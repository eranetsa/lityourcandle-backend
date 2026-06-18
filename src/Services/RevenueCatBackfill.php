<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Logger;
use App\Models\Subscription;

/**
 * Orchestrates a one-direction sync from RevenueCat → local DB.
 *
 * Webhooks are still the source of truth for live updates; this class is
 * for two scenarios:
 *   1) Back-fill of subscribers who paid BEFORE the webhook was wired up.
 *   2) Admin "Re-sync this user from RC" button when a payment looks stale.
 *
 * Identity strategy (matches WebhookController::resolveSingleId):
 *   - Try "u_{users.id}"  first  — the authenticated alias.
 *   - Fall back to the raw  users.device_id (already prefixed "dev_…").
 *   - If both 404, the user has no RC footprint — mark and move on.
 *
 * Unreachable identifiers:
 *   - "$RCAnonymousID:..." (the mobile SDK's pre-login id) is intentionally
 *     not probed: it isn't stored anywhere on our side. Anonymous-only
 *     purchases are unreachable until the user signs up / links.
 *   - Custom Purchases.logIn() ids that are neither "u_<n>" nor "dev_..."
 *     are also unreachable. Same constraint as the webhook resolver.
 *
 * Write strategy: we synthesize an RC-event-shaped array per active
 * subscription and feed it through Subscription::upsertFromRcEvent() with
 * the `is_backfill` flag, which:
 *   - Writes the snapshot to rc_backfill_receipt (NOT store_latest_receipt,
 *     which holds the real Apple/Play receipt the webhook delivered).
 *   - Stamps subscriptions.last_synced_at so operators can spot stale rows.
 *   - Reuses all existing chain-matching / clamp / downgrade logic.
 *
 * Idempotency:
 *   - upsertFromRcEvent is idempotent UNLESS reset_sessions=true.
 *     We pass reset_sessions=true ONLY when the existing row's
 *     sessions_total=0 AND the new plan is paid (the "missed-INITIAL_PURCHASE
 *     bootstrap" case). Otherwise reset_sessions=false to avoid refunding
 *     already-consumed credits.
 *   - Non-subscription purchases are credited via creditExtraSession, which
 *     dedupes on BOTH the webhook event.id (if any prior delivery wrote one)
 *     AND the underlying store_transaction_id (cross-path dedupe).
 *
 * Important: this back-fill only GRANTS access. It does NOT revoke. A user
 * whose webhook failed and who locally shows active-but-RC-expired is
 * flagged in the result (`local_active_but_rc_inactive`) but their row is
 * not modified — revocation must come from EXPIRATION webhooks or the
 * daily cron. Same for refunds: refunded chains do not trigger clawback.
 */
final class RevenueCatBackfill
{
    private RevenueCatRestService $rc;

    /** Sleep between users (microseconds). Each user may issue up to TWO RC
     *  REST calls (u_<id> 404 → dev_<id> fallback), so the effective rate is
     *  ≤ 1 / (sleep × 0.5). 75ms → 13 req/s in the worst case, well under
     *  RC's 20 req/s cap. */
    private const PER_USER_SLEEP_US = 75_000;

    public function __construct(?RevenueCatRestService $rc = null)
    {
        $this->rc = $rc ?? new RevenueCatRestService();
    }

    /**
     * Reconcile one user against RC.
     *
     * @return array{
     *   user_id:int,
     *   matched_id:?string,
     *   tried_ids:array<string>,
     *   found:bool,
     *   active_subs_seen:int,
     *   sub_rows_written:int,
     *   non_sub_credits:int,
     *   skipped_sandbox:int,
     *   skipped_family_shared:int,
     *   skipped_refunded:int,
     *   skipped_lifetime:int,
     *   notes:array<string>,
     *   error:?string,
     * }
     */
    public function syncUser(int $userId): array
    {
        $result = [
            'user_id'               => $userId,
            'matched_id'            => null,
            'tried_ids'             => [],
            'found'                 => false,
            'active_subs_seen'      => 0,
            'sub_rows_written'      => 0,
            'non_sub_credits'       => 0,
            'skipped_sandbox'       => 0,
            'skipped_family_shared' => 0,
            'skipped_refunded'      => 0,
            'skipped_lifetime'      => 0,
            'notes'                 => [],
            'error'                 => null,
        ];

        $user = DB::one(
            'SELECT id, device_id FROM users WHERE id = :id',
            [':id' => $userId]
        );
        if (!$user) {
            $result['error'] = 'user_not_found';
            return $result;
        }

        // Try u_<id> first, then the raw device_id (which is already
        // "dev_…" per the app's ID generator). Skip the second probe if
        // device_id is null/empty.
        $candidates = ['u_' . $userId];
        if (!empty($user['device_id'])) {
            $candidates[] = (string)$user['device_id'];
        }

        $snapshot = null;
        foreach ($candidates as $appUserId) {
            $result['tried_ids'][] = $appUserId;
            try {
                $snap = $this->rc->getSubscriber($appUserId);
            } catch (\Throwable $e) {
                $msg = $e->getMessage();
                $isFatal = str_contains($msg, 'fatal HTTP');
                Logger::warn('rc_backfill_rest_error', [
                    'user_id'     => $userId,
                    'app_user_id' => $appUserId,
                    'msg'         => $msg,
                    'fatal'       => $isFatal,
                ]);
                if ($isFatal) {
                    // 400/401/403 — won't get better against the next candidate.
                    $result['error'] = $msg;
                    return $result;
                }
                // Transient (timeout / retries exhausted) — try the next
                // candidate id rather than abandoning this user entirely.
                continue;
            }
            if ($snap !== null) {
                $snapshot             = $snap;
                $result['matched_id'] = $appUserId;
                $result['found']      = true;
                break;
            }
        }

        if (!$snapshot) {
            $result['notes'][] = 'no_subscriber_record';
            return $result;
        }

        // RC may have collapsed aliases — the canonical original_app_user_id
        // is in the response. Note it so the operator can spot duplicates.
        $orig = (string)($snapshot['original_app_user_id'] ?? '');
        if ($orig !== '' && $orig !== $result['matched_id']) {
            // Hash before audit-logging (caller writes notes into audit_log).
            $result['notes'][] = 'rc_origin_hash=' . substr(hash('sha256', $orig), 0, 12);
        }

        // Prefer the RC envelope's request_date_ms over local time, and
        // over the ISO request_date (which has TZ pitfalls).
        $requestDate = !empty($snapshot['_request_date_ms'])
            ? (int)((int)$snapshot['_request_date_ms'] / 1000)
            : (!empty($snapshot['_request_date']) ? self::isoToEpoch((string)$snapshot['_request_date']) : time());

        // ---- Subscriptions (auto-renewing) ----------------------------
        // RC returns the FULL history keyed by product_id, including
        // long-cancelled rows. We want the one row in our DB to reflect
        // the user's CURRENT entitlement, so pick the best active sub
        // (latest expiry) and feed only that one to upsertFromRcEvent.
        $subs = is_array($snapshot['subscriptions'] ?? null) ? $snapshot['subscriptions'] : [];
        // $best: ['product_id'=>…, 'sub'=>…, 'expires_ts'=>int]
        $best = null;
        foreach ($subs as $productId => $sub) {
            if (!is_array($sub)) continue;
            $isSandbox     = !empty($sub['is_sandbox']);
            $ownership     = strtoupper((string)($sub['ownership_type'] ?? 'PURCHASED'));
            $refundedAt    = $sub['refunded_at'] ?? null;
            // Prefer the *_ms field, fall back to a UTC-anchored ISO parse.
            $expTs = self::epochFromRc($sub, 'expires_date');
            $graceTs = self::epochFromRc($sub, 'grace_period_expires_date');
            $effectiveTs = ($graceTs !== null && ($expTs === null || $graceTs > $expTs))
                ? $graceTs : $expTs;

            if ($isSandbox)                       { $result['skipped_sandbox']++;       continue; }
            if ($ownership === 'FAMILY_SHARED')   { $result['skipped_family_shared']++; continue; }
            if (!empty($refundedAt))              { $result['skipped_refunded']++;      continue; }

            // Lifetime / null-expiry products aren't modelled locally
            // (Subscription::planFromRcProduct returns null for "lifetime").
            // Skip rather than write a row with NULL expires_at that would
            // grant access forever.
            if ($effectiveTs === null) {
                $result['skipped_lifetime']++;
                continue;
            }

            $isActive = $effectiveTs > $requestDate;
            if (!$isActive) continue;

            $result['active_subs_seen']++;
            if ($best === null || $effectiveTs > (int)$best['expires_ts']) {
                $best = [
                    'product_id' => (string)$productId,
                    'sub'        => $sub,
                    'expires_ts' => $effectiveTs,
                ];
            }
        }

        $existingRow = DB::one(
            'SELECT id, plan, status, expires_at, store, store_original_tx,
                    sessions_total, sessions_remaining
               FROM subscriptions
              WHERE user_id = :uid
              ORDER BY id DESC LIMIT 1',
            [':uid' => $userId]
        );

        if ($best !== null) {
            $event = $this->buildSyntheticEvent(
                $best['product_id'],
                $best['sub'],
                // Thread the existing row's chain key back in so
                // upsertFromRcEvent's match-priority-#1 hits and we update
                // the SAME row instead of inventing a new one.
                $existingRow['store_original_tx'] ?? null
            );

            $isTrial = strtoupper((string)($best['sub']['period_type'] ?? 'NORMAL')) === 'TRIAL'
                       || !empty($best['sub']['is_trial_period']);
            // RC's REST snapshot does NOT include auto_renew_status — derive
            // from the canonical "user has cancelled auto-renew" signal.
            $autoRenew = empty($best['sub']['unsubscribe_detected_at']) ? 1 : 0;

            // Status:
            //   - billing_issues_detected_at present and grace window active
            //     → 'grace' (and use_grace_expiration so we write the grace
            //       expiry, not the failed billing date).
            //   - period_type=TRIAL → 'trial'
            //   - otherwise         → 'active'
            $status = 'active';
            $useGrace = false;
            if (!empty($best['sub']['billing_issues_detected_at'])) {
                $status   = 'grace';
                $useGrace = true;
            } elseif ($isTrial) {
                $status = 'trial';
            }

            // Bootstrap detection: existing row has sessions_total=0 but RC
            // now shows the user on a paid plan. This is the
            // missed-INITIAL_PURCHASE case — the user paid before the
            // webhook was wired, so we never allocated their credits.
            // Pass reset_sessions=true so they get the plan's full quota.
            $plan = Subscription::planFromRcProduct($best['product_id']);
            $needsBootstrap = $existingRow
                && (int)($existingRow['sessions_total'] ?? 0) === 0
                && $plan !== null
                && in_array($plan, ['weekly', 'monthly', 'yearly'], true);

            $opts = [
                'event_type'           => 'BACKFILL_FROM_SNAPSHOT',
                'status'               => $status,
                'auto_renew'           => $autoRenew,
                'reset_sessions'       => $needsBootstrap,
                'use_grace_expiration' => $useGrace,
                'is_backfill'          => true,
            ];

            DB::transaction(function () use ($userId, $event, $opts) {
                Subscription::upsertFromRcEvent($userId, $event, $opts);
            });
            $result['sub_rows_written']++;
            if ($needsBootstrap) {
                $result['notes'][] = 'bootstrapped_missed_initial_purchase';
            }
        } else {
            // No active sub in RC.
            if ($existingRow
                && in_array($existingRow['status'], ['active', 'trial', 'grace'], true)
                && $existingRow['plan'] !== 'free') {
                // Local DB still thinks they're active but RC disagrees.
                // Back-fill DOES NOT revoke (see class docblock); flag for
                // the operator. The daily expiration cron handles this.
                $result['notes'][] = 'local_active_but_rc_inactive';
            }
        }

        // ---- Non-subscriptions (one-shots, e.g. extra-session SKUs) ---
        $nonSubs = is_array($snapshot['non_subscriptions'] ?? null) ? $snapshot['non_subscriptions'] : [];
        foreach ($nonSubs as $productId => $purchases) {
            if (!is_array($purchases)) continue;
            foreach ($purchases as $p) {
                if (!is_array($p)) continue;
                if (!empty($p['is_sandbox'])) { $result['skipped_sandbox']++; continue; }
                $rcId = (string)($p['id'] ?? '');
                if ($rcId === '') continue;
                // creditExtraSession dedupes on BOTH store_tx_id and the
                // alt store_transaction_id, so cross-path duplicates with
                // the webhook (which keyed on event.id) are caught.
                $synthetic = [
                    'product_id'                    => (string)$productId,
                    'transaction_id'                => (string)($p['store_transaction_id'] ?? $rcId),
                    'store_transaction_identifier'  => (string)($p['store_transaction_id'] ?? ''),
                    'store_transaction_id'          => (string)($p['store_transaction_id'] ?? ''),
                    'store'                         => (string)($p['store'] ?? ''),
                    'purchase_date'                 => (string)($p['purchase_date'] ?? ''),
                ];
                DB::transaction(function () use ($userId, $synthetic, $rcId) {
                    Subscription::creditExtraSession($userId, $synthetic, $rcId);
                });
                $result['non_sub_credits']++;
            }
        }

        Logger::info('rc_backfill_user_synced', [
            'user_id'           => $userId,
            'found'             => $result['found'],
            'sub_rows_written'  => $result['sub_rows_written'],
            'non_sub_credits'   => $result['non_sub_credits'],
            'matched_id_hash'   => $result['matched_id'] !== null
                ? substr(hash('sha256', $result['matched_id']), 0, 12) : null,
        ]);
        return $result;
    }

    /**
     * Reconcile a page of users.
     *
     * @param int|null $sinceUserId  exclusive lower bound on users.id; null = start
     * @param int      $limit        max users per call (default 50)
     * @param bool     $onlyRegular  if true (default), filter to
     *                               is_active=1 AND role IN ('user','guest').
     *                               Set false to sweep every row.
     * @return array{
     *   users_checked:int,
     *   users_with_active_sub:int,
     *   users_updated:int,
     *   non_sub_credits:int,
     *   errors:int,
     *   last_user_id:?int,
     *   done:bool,
     *   per_user:array<int, array>,
     * }
     */
    public function syncAll(?int $sinceUserId = null, int $limit = 50, bool $onlyRegular = true): array
    {
        $limit = max(1, min(500, $limit));
        $where = 'id > :since';
        if ($onlyRegular) {
            $where .= " AND is_active = 1 AND role IN ('user', 'guest')";
        }
        $rows  = DB::all(
            "SELECT id FROM users
              WHERE {$where}
              ORDER BY id ASC
              LIMIT " . $limit,
            [':since' => $sinceUserId ?? 0]
        );

        $agg = [
            'users_checked'         => 0,
            'users_with_active_sub' => 0,
            'users_updated'         => 0,
            'non_sub_credits'       => 0,
            'errors'                => 0,
            'last_user_id'          => $sinceUserId,
            // done = true iff this page returned zero users (sweep finished).
            'done'                  => empty($rows),
            'per_user'              => [],
        ];

        foreach ($rows as $row) {
            $uid = (int)$row['id'];
            $agg['last_user_id'] = $uid;
            $agg['users_checked']++;

            try {
                $r = $this->syncUser($uid);
            } catch (\Throwable $e) {
                $agg['errors']++;
                Logger::error('rc_backfill_user_failed', [
                    'user_id' => $uid,
                    'msg'     => $e->getMessage(),
                ]);
                usleep(self::PER_USER_SLEEP_US);
                continue;
            }

            if ($r['error'])                $agg['errors']++;
            if ($r['active_subs_seen'] > 0) $agg['users_with_active_sub']++;
            if ($r['sub_rows_written'] > 0) $agg['users_updated']++;
            $agg['non_sub_credits']        += $r['non_sub_credits'];
            $agg['per_user'][$uid] = $r;

            usleep(self::PER_USER_SLEEP_US);
        }

        return $agg;
    }

    /**
     * Build a synthetic RC webhook-event array from a REST `subscriptions[pid]`
     * record.
     *
     * Only the fields Subscription::upsertFromRcEvent reads are populated;
     * everything else would be noise. The trickiest field is
     * `original_transaction_id`: REST `/v1/subscribers/{id}` does not
     * include a chain key in the subscription record (those fields live
     * on webhook payloads only). So we thread the EXISTING row's
     * store_original_tx back in — that lets match-priority-#1 in
     * upsertFromRcEvent hit the same row.
     */
    private function buildSyntheticEvent(string $productId, array $sub, ?string $existingChainTx): array
    {
        $expMs = self::msFromRc($sub, 'expires_date');
        $graceMs = self::msFromRc($sub, 'grace_period_expires_date');

        return [
            'type'                          => 'BACKFILL',
            'product_id'                    => $productId,
            // No date-string fallback — would corrupt store_original_tx.
            // Empty → upsertFromRcEvent preserves the existing value.
            'original_transaction_id'       => (string)($existingChainTx ?? ''),
            'store'                         => (string)($sub['store'] ?? ''),
            'expiration_at_ms'              => $expMs,
            'grace_period_expiration_at_ms' => $graceMs,
            'period_type'                   => strtoupper(
                (string)($sub['period_type']
                    ?? (!empty($sub['is_trial_period']) ? 'TRIAL' : 'NORMAL'))
            ),
        ];
    }

    /**
     * Pull a millisecond epoch from an RC REST subscription/non-subscription
     * record. Prefers the `${field}_ms` key (always unambiguous epoch ms)
     * over the ISO `${field}` string, and parses the ISO form as strict UTC
     * to avoid the local-TZ drift `strtotime` introduces.
     */
    private static function msFromRc(array $rec, string $field): ?int
    {
        $msKey = $field . '_ms';
        if (isset($rec[$msKey]) && is_numeric($rec[$msKey])) {
            $ms = (int)$rec[$msKey];
            return $ms > 0 ? $ms : null;
        }
        $iso = (string)($rec[$field] ?? '');
        if ($iso === '') return null;
        $ts = self::isoToEpoch($iso);
        return $ts !== null ? $ts * 1000 : null;
    }

    /** Same field but returns epoch seconds (null if unparseable). */
    private static function epochFromRc(array $rec, string $field): ?int
    {
        $ms = self::msFromRc($rec, $field);
        return $ms !== null ? (int)($ms / 1000) : null;
    }

    /**
     * Strict UTC parse of an RC ISO timestamp. RC sometimes returns the
     * "Z"-suffixed form ("2026-06-18T12:00:00Z"), sometimes a space-
     * separated naive form ("2026-06-18 12:00:00"). strtotime treats the
     * naive form as LOCAL time on a TZ-set server — for Asia/Riyadh that's
     * a 3-hour drift vs the webhook's *_ms epoch. We force UTC.
     */
    private static function isoToEpoch(string $iso): ?int
    {
        try {
            // DateTimeImmutable accepts both 'Z' and space-separated forms.
            // Forcing the constructor TZ to UTC means a naive string is
            // interpreted as UTC, not local — matching the *_ms field.
            $dt = new \DateTimeImmutable($iso, new \DateTimeZone('UTC'));
            return $dt->getTimestamp();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
