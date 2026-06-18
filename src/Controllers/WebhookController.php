<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| RevenueCat Webhook Receiver
|--------------------------------------------------------------------------
|
| Mount: POST /api/webhooks/revenuecat   (no AuthMiddleware — RC has no JWT)
|
| ADMIN SETUP
| -----------
|   1) Server: add to /var/www/lityourcandle/.env
|        REVENUECAT_WEBHOOK_SECRET="<openssl rand -hex 32>"
|        REVENUECAT_ENV=""              # "" → accept SANDBOX+PRODUCTION
|                                        # "PRODUCTION" → drop sandbox
|   2) RevenueCat dashboard → Integrations → "+ New" → "Webhook":
|        URL  : https://backend.lityourcandle.com/api/webhooks/revenuecat
|        Auth : paste the EXACT same secret value from REVENUECAT_WEBHOOK_SECRET.
|               You may also paste it prefixed with "Bearer " — both forms
|               are accepted server-side (we strip the prefix before
|               constant-time compare).
|   3) Toggle "Send webhook events for sandbox purchases" ON during
|      TestFlight / Play internal-track testing. Once live, set
|      REVENUECAT_ENV=PRODUCTION in .env to drop sandbox traffic.
|   4) Click "Send test event" — should return 200 and create one row in
|      `rc_webhook_events` with event_type='TEST'.
|
| Apache: if the Authorization header arrives empty even though RC sent
| it, add to .htaccess:
|     SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1
| Nginx + PHP-FPM: ensure `fastcgi_pass_request_headers on;` OR set
|     fastcgi_param HTTP_AUTHORIZATION $http_authorization;
|
| FAILURE SEMANTICS
| -----------------
|   200 OK   — success, no-op, unknown event type, duplicate, or
|              unresolvable app_user_id (stored as pending for later replay).
|   401      — missing / wrong Authorization header (constant-time check).
|   400      — body is not parseable JSON, missing event.id / event.type,
|              malformed event.id format, or payload too large.
|   413      — body exceeds 256KB.
|   500      — internal failure; RC retries with exponential backoff up
|              to ~72h. Use sparingly so we don't get retry storms.
*/

namespace App\Controllers;

use App\Core\App;
use App\Core\DB;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Models\Subscription;
use PDOException;
use Throwable;

final class WebhookController
{
    /** Hard cap on webhook payload size, matched against php://input. */
    private const MAX_PAYLOAD_BYTES = 262144; // 256 KB

    public function revenuecat(Request $req): void
    {
        // ---- 0) Payload-size guard (pre-auth DoS protection) -----------
        $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength > self::MAX_PAYLOAD_BYTES) {
            Logger::warn('rc_webhook_too_large', ['bytes' => $contentLength]);
            Response::error('payload_too_large', 413);
        }

        // ---- 1) Auth (constant-time) -----------------------------------
        // Accept both "<secret>" and "Bearer <secret>" forms regardless of
        // which side wears the Bearer prefix. We strip it from BOTH sides
        // before hash_equals so dashboard/typing mismatches don't 401.
        $expectedRaw = trim((string)App::config('revenuecat.webhook_secret', ''));
        $receivedRaw = trim((string)($req->headers['authorization'] ?? ''));
        $expected = self::stripBearer($expectedRaw);
        $received = self::stripBearer($receivedRaw);
        if ($expected === '' || !hash_equals($expected, $received)) {
            Logger::warn('rc_webhook_unauthorized', [
                'ip'      => $req->ip(),
                'has_hdr' => $receivedRaw !== '',
            ]);
            Response::error('unauthorized', 401);
        }

        // ---- 2) Parse + minimal shape check ----------------------------
        $payload = $req->body;
        $event   = is_array($payload['event'] ?? null) ? $payload['event'] : null;
        $eventId = is_string($event['id'] ?? null) ? $event['id'] : null;
        $type    = is_string($event['type'] ?? null) ? strtoupper($event['type']) : null;
        if (!$event || !$eventId || !$type) {
            Logger::warn('rc_webhook_bad_payload', [
                'snippet' => substr((string)file_get_contents('php://input'), 0, 512),
            ]);
            Response::error('bad_payload', 400);
        }
        // event_id format: defensively allow up to 64 chars of UUID-ish chars.
        if (!preg_match('/^[A-Za-z0-9_\-]{8,64}$/', $eventId)) {
            Logger::warn('rc_webhook_bad_event_id', ['event_id' => substr($eventId, 0, 80)]);
            Response::error('bad_payload', 400);
        }

        $appUserId  = (string)($event['app_user_id'] ?? '');
        $origUserId = (string)($event['original_app_user_id'] ?? '');
        $productId  = (string)($event['product_id'] ?? $event['new_product_id'] ?? '');
        $env        = strtoupper((string)($event['environment'] ?? 'PRODUCTION'));
        $apiVersion = (string)($payload['api_version'] ?? '');
        $entitlements = is_array($event['entitlement_ids'] ?? null) ? $event['entitlement_ids'] : [];

        Logger::info('rc_webhook_received', [
            'event_id'        => $eventId,
            'type'            => $type,
            'app_user_id'     => $appUserId,
            'product_id'      => $productId,
            'environment'     => $env,
            'api_version'     => $apiVersion,
            'entitlement_ids' => $entitlements,
        ]);

        // ---- 3) Environment filter -------------------------------------
        $allowedEnv = strtoupper((string)App::config('revenuecat.allowed_environment', ''));
        if ($allowedEnv !== '' && $allowedEnv !== $env) {
            Logger::info('rc_webhook_env_dropped', [
                'event_id' => $eventId,
                'env'      => $env,
                'allowed'  => $allowedEnv,
            ]);
            $this->logEvent($eventId, $type, $appUserId, $origUserId, $productId, $env, $payload, null, true);
            Response::json(['ok' => true, 'ignored' => 'environment']);
        }

        // ---- 4) Dispatch in a single transaction -----------------------
        // Insert + resolve + apply + mark-processed all live in ONE
        // transaction. If anything throws we roll back EVERYTHING — the
        // audit row included — so RC's retry hits a clean slate. The
        // UNIQUE index on event_id still guards against true duplicates
        // since the insert commits transactionally on success.
        try {
            [$status, $extras] = DB::transaction(function () use (
                $eventId, $type, $appUserId, $origUserId, $productId, $env, $payload, $event
            ) {
                // 4a) Audit insert (idempotent via UNIQUE event_id)
                try {
                    DB::insert('rc_webhook_events', [
                        'event_id'             => $eventId,
                        'event_type'           => $type,
                        'app_user_id'          => $appUserId !== '' ? $appUserId : null,
                        'original_app_user_id' => $origUserId !== '' ? $origUserId : null,
                        'product_id'           => $productId !== '' ? $productId : null,
                        'environment'          => $env,
                        'payload_json'         => self::safeJsonEncode($payload),
                    ]);
                } catch (PDOException $e) {
                    if (($e->errorInfo[1] ?? null) === 1062) {
                        // True duplicate. Check whether the prior delivery
                        // actually succeeded — if it did, return 200; if it
                        // never finished (processed_at IS NULL), re-dispatch
                        // so we recover from a previously-failed attempt.
                        $prior = DB::one(
                            'SELECT id, processed_at, resolved_user_id
                               FROM rc_webhook_events WHERE event_id = :e',
                            [':e' => $eventId]
                        );
                        if ($prior && $prior['processed_at'] !== null) {
                            return ['duplicate', ['duplicate' => true]];
                        }
                        // Fall through; we'll re-attempt resolution + dispatch
                        // and update the existing row at step 4d.
                    } else {
                        throw $e;
                    }
                }

                // 4b) Resolve user inside the transaction (FOR UPDATE works).
                $userId = $this->resolveUser($appUserId, $event['aliases'] ?? [], $origUserId);

                // 4c) Dispatch
                $handled = false;
                switch ($type) {
                    case 'INITIAL_PURCHASE':
                    case 'RENEWAL':
                    case 'UNCANCELLATION':
                    case 'PRODUCT_CHANGE':
                        $handled = $this->handleActivation($event, $userId, $type);
                        break;
                    case 'CANCELLATION':
                        $handled = $this->handleCancellation($event, $userId);
                        break;
                    case 'EXPIRATION':
                        $handled = $this->handleExpiration($event, $userId);
                        break;
                    case 'BILLING_ISSUE':
                        $handled = $this->handleBillingIssue($event, $userId);
                        break;
                    case 'SUBSCRIPTION_PAUSED':
                        $handled = $this->handlePaused($event, $userId);
                        break;
                    case 'SUBSCRIPTION_EXTENDED':
                        $handled = $this->handleExtended($event, $userId);
                        break;
                    case 'REFUND_REVERSED':
                        // Refund was reversed → re-grant access.
                        $handled = $this->handleActivation($event, $userId, 'REFUND_REVERSED');
                        break;
                    case 'NON_RENEWING_PURCHASE':
                        $handled = $this->handleNonRenewing($event, $userId, $eventId);
                        break;
                    case 'TRANSFER':
                        $resolvedTransferUserId = $this->handleTransfer($event);
                        if ($resolvedTransferUserId !== null) {
                            $userId = $resolvedTransferUserId;
                        }
                        $handled = $resolvedTransferUserId !== null;
                        break;
                    case 'SUBSCRIBER_ALIAS':
                        // Legacy; mostly inactive on modern RC but kept as a
                        // safety net for older projects.
                        $aliasUserId = $this->handleAlias($event);
                        if ($aliasUserId !== null) {
                            $userId = $aliasUserId;
                        }
                        $handled = $aliasUserId !== null;
                        break;
                    case 'TEST':
                        Logger::info('rc_webhook_test_received', ['app_user_id' => $appUserId]);
                        $handled = true;
                        break;
                    case 'TEMPORARY_ENTITLEMENT_GRANT':
                    case 'INVOICE_ISSUANCE':
                    case 'PURCHASE_REDEEMED':
                    case 'VIRTUAL_CURRENCY_TRANSACTION':
                    case 'EXPERIMENT_ENROLLMENT':
                        // Acknowledged-but-not-actioned events. Mark processed
                        // so they don't pollute the pending index.
                        Logger::info('rc_webhook_ack_only', ['type' => $type]);
                        $handled = true;
                        break;
                    default:
                        Logger::warn('rc_webhook_unhandled_type', ['type' => $type]);
                        $handled = false;
                }

                // 4d) Update the audit row.
                $update = [];
                if ($handled) {
                    $update['processed_at'] = date('Y-m-d H:i:s');
                }
                if ($userId !== null) {
                    $update['resolved_user_id'] = $userId;
                }
                if ($update) {
                    DB::update('rc_webhook_events', $update, 'event_id = :eid', [':eid' => $eventId]);
                }

                if (!$handled) {
                    return ['pending', ['pending' => true]];
                }
                if ($userId === null) {
                    return ['ok', ['resolved' => false]];
                }
                return ['ok', []];
            });
        } catch (Throwable $e) {
            Logger::error('rc_webhook_handler_failed', [
                'event_id' => $eventId,
                'type'     => $type,
                'msg'      => $e->getMessage(),
                'file'     => $e->getFile(),
                'line'     => $e->getLine(),
            ]);
            // 500 → RC will retry. Acceptable for transient DB errors.
            Response::error('server_error', 500);
        }

        Response::json(['ok' => true] + $extras);
    }

    // =========================================================
    //  USER MAPPING
    // =========================================================

    /**
     * Map an RC app_user_id (and its aliases) to a local users.id.
     *
     *   "u_<n>"   → users.id = <n>   (n: 1..18 digits)
     *   "dev_..." → users.device_id = "<full string>"  (length 8..64,
     *               alnum + _ -)
     *   anything else (e.g. "$RCAnonymousID:...") → defer.
     */
    private function resolveUser(string $appUserId, array $aliases, string $originalAppUserId): ?int
    {
        $tried = [];
        $candidates = array_filter(array_unique(array_merge(
            [$appUserId],
            array_map('strval', is_array($aliases) ? $aliases : []),
            [$originalAppUserId]
        )));

        foreach ($candidates as $id) {
            $uid = $this->resolveSingleId((string)$id);
            $tried[] = $id;
            if ($uid !== null) return $uid;
        }
        Logger::info('rc_webhook_resolve_miss', ['tried' => $tried]);
        return null;
    }

    private function resolveSingleId(string $rcId): ?int
    {
        if ($rcId === '') return null;

        // Strict: u_<digits> where digits is 1..18 chars (max int range).
        if (preg_match('/^u_(\d{1,18})$/', $rcId, $m)) {
            $uid = (int)$m[1];
            if ($uid <= 0) return null;
            $row = DB::one(
                'SELECT id FROM users WHERE id = :id FOR UPDATE',
                [':id' => $uid]
            );
            return $row ? (int)$row['id'] : null;
        }

        // Strict: dev_<allowed device id charset>, total 8..64 chars
        // including the prefix. AuthController::guest validates device_id
        // as 8..64 chars [A-Za-z0-9_-], so dev_<id> is up to 68 — accept.
        if (preg_match('/^dev_[A-Za-z0-9_\-]{4,64}$/', $rcId)) {
            $row = DB::one(
                'SELECT id FROM users WHERE device_id = :d FOR UPDATE',
                [':d' => $rcId]
            );
            return $row ? (int)$row['id'] : null;
        }

        // Unknown form (most commonly "$RCAnonymousID:..."). Defer.
        return null;
    }

    // =========================================================
    //  PER-EVENT HANDLERS
    // =========================================================

    /** Grant or extend access. */
    private function handleActivation(array $event, ?int $userId, string $type): bool
    {
        if ($userId === null) return false;

        // Trial → paid conversion can arrive as RENEWAL with period_type=NORMAL
        // for an existing trial row. Treat that as reset.
        $periodType = strtoupper((string)($event['period_type'] ?? ''));
        $existing   = DB::one(
            'SELECT status FROM subscriptions WHERE user_id = :u ORDER BY id DESC LIMIT 1',
            [':u' => $userId]
        );
        $isTrialConversion = $periodType === 'NORMAL'
            && $existing && ($existing['status'] ?? null) === 'trial';

        Subscription::upsertFromRcEvent($userId, $event, [
            'event_type'     => $type,
            'status'         => $this->statusFromEvent($event, 'active'),
            'auto_renew'     => 1,
            // PRODUCT_CHANGE & RENEWAL: keep any unused credits the user
            // already paid for; INITIAL_PURCHASE / trial-conversion /
            // REFUND_REVERSED: fresh allocation.
            'reset_sessions' => in_array($type, ['INITIAL_PURCHASE', 'REFUND_REVERSED'], true)
                              || $isTrialConversion,
        ]);
        return true;
    }

    /**
     * Auto-renew turned off OR refund issued. cancel_reason disambiguates.
     * For non-refund cancels we DO NOT touch status — the user keeps
     * access until expires_at and the daily cron flips them to 'expired'.
     */
    private function handleCancellation(array $event, ?int $userId): bool
    {
        if ($userId === null) return false;
        $reason = strtoupper((string)($event['cancel_reason'] ?? ''));

        // Immediate revocation: customer support refund OR developer revoke.
        if (in_array($reason, ['CUSTOMER_SUPPORT', 'DEVELOPER_INITIATED'], true)) {
            Subscription::upsertFromRcEvent($userId, $event, [
                'event_type'        => 'CANCELLATION_REVOKE',
                'status'            => 'canceled',
                'auto_renew'        => 0,
                'reset_sessions'    => false,
                'zero_remaining'    => true,
                'force_expires_now' => true,
            ]);
            return true;
        }

        // User-initiated unsubscribe / billing_error / price_increase: keep
        // access until expires_at. Just flip auto_renew off.
        Subscription::upsertFromRcEvent($userId, $event, [
            'event_type'     => 'CANCELLATION',
            'status'         => null,        // preserve current status
            'auto_renew'     => 0,
            'reset_sessions' => false,
        ]);
        return true;
    }

    private function handleExpiration(array $event, ?int $userId): bool
    {
        if ($userId === null) return false;
        Subscription::upsertFromRcEvent($userId, $event, [
            'event_type'        => 'EXPIRATION',
            'status'            => 'expired',
            'auto_renew'        => 0,
            'reset_sessions'    => false,
            'zero_remaining'    => true,
            // Force expires_at = NOW() regardless of payload — EXPIRATION
            // means access ends now.
            'force_expires_now' => true,
        ]);
        return true;
    }

    /** Card declined; grace window. Honour grace_period_expiration_at_ms. */
    private function handleBillingIssue(array $event, ?int $userId): bool
    {
        if ($userId === null) return false;
        Subscription::upsertFromRcEvent($userId, $event, [
            'event_type'           => 'BILLING_ISSUE',
            'status'               => 'grace',
            'auto_renew'           => 1,
            'reset_sessions'       => false,
            'use_grace_expiration' => true,
        ]);
        return true;
    }

    /** Play Store user paused their auto-renew. Access stays until expires_at. */
    private function handlePaused(array $event, ?int $userId): bool
    {
        if ($userId === null) return false;
        Subscription::upsertFromRcEvent($userId, $event, [
            'event_type'     => 'SUBSCRIPTION_PAUSED',
            'status'         => 'grace',
            'auto_renew'     => 0,
            'reset_sessions' => false,
        ]);
        return true;
    }

    /** Developer-granted extension. Just bump expires_at to event's value. */
    private function handleExtended(array $event, ?int $userId): bool
    {
        if ($userId === null) return false;
        Subscription::upsertFromRcEvent($userId, $event, [
            'event_type'     => 'SUBSCRIPTION_EXTENDED',
            'status'         => 'active',
            'auto_renew'     => 1,
            'reset_sessions' => false,
        ]);
        return true;
    }

    /**
     * One-shot purchase. If it's an extra-session SKU, credit +1 to the
     * user's current subscription row. Idempotent against replays via a
     * marker row in `transactions` keyed on store_tx_id.
     */
    private function handleNonRenewing(array $event, ?int $userId, string $eventId): bool
    {
        if ($userId === null) return false;
        $pid = strtolower((string)($event['product_id'] ?? ''));

        // SKU prefixes are config-driven so ops can change them without a
        // redeploy. Defaults to the conservative ['session_single_',
        // 'session_extra_'] used during development.
        $prefixes = App::config('revenuecat.extra_session_sku_prefixes', [
            'session_single_',
            'session_extra_',
        ]);
        $isSessionTopup = false;
        foreach ((array)$prefixes as $prefix) {
            $prefix = (string)$prefix;
            if ($prefix !== '' && str_starts_with($pid, strtolower($prefix))) {
                $isSessionTopup = true;
                break;
            }
        }
        if (!$isSessionTopup) {
            Logger::info('rc_webhook_non_renewing_ignored', ['product_id' => $pid]);
            return true;
        }

        Subscription::creditExtraSession($userId, $event, $eventId);
        return true;
    }

    /**
     * Reassign entitlements from one app_user_id to another. We MERGE the
     * source's active subscription state into the destination's latest
     * row, then mark the source's stale rows canceled. Restricting to
     * active states avoids reshuffling historical rows.
     *
     * Returns the resolved destination user id (so the outer dispatch
     * can stamp resolved_user_id on the audit row) or null on miss.
     */
    private function handleTransfer(array $event): ?int
    {
        $from = array_filter(array_map('strval', $event['transferred_from'] ?? []));
        $to   = array_filter(array_map('strval', $event['transferred_to']   ?? []));
        if (!$to) return null;

        $toUserId = null;
        foreach ($to as $rcId) {
            $toUserId = $this->resolveSingleId($rcId);
            if ($toUserId !== null) break;
        }
        if ($toUserId === null) {
            Logger::warn('rc_webhook_transfer_to_unresolved', ['to' => $to]);
            return null;
        }

        foreach ($from as $rcId) {
            $fromUserId = $this->resolveSingleId($rcId);
            if ($fromUserId === null || $fromUserId === $toUserId) continue;
            // Only move active/trial/grace rows. Historical canceled/expired
            // rows stay with the original user for accurate history.
            DB::run(
                "UPDATE subscriptions
                    SET user_id = :new
                  WHERE user_id = :old
                    AND status IN ('active','trial','grace')",
                [':new' => $toUserId, ':old' => $fromUserId]
            );
            Logger::info('rc_webhook_transfer_applied', [
                'from_user' => $fromUserId,
                'to_user'   => $toUserId,
            ]);
        }

        // Backfill any pending events that named the new id (or the from ids).
        $this->replayPendingFor($toUserId, array_merge($to, $from));
        return $toUserId;
    }

    /**
     * Legacy alias event. RC tells us two app_user_ids are the same
     * subscriber. Record it and replay any pending events.
     *
     * Returns the resolved user id or null.
     */
    private function handleAlias(array $event): ?int
    {
        $appUserId  = (string)($event['app_user_id'] ?? '');
        $aliases    = array_map('strval', $event['aliases'] ?? []);
        $userId     = $this->resolveSingleId($appUserId);
        if ($userId === null) {
            foreach ($aliases as $a) {
                $userId = $this->resolveSingleId($a);
                if ($userId !== null) break;
            }
        }
        if ($userId === null) return null;
        $this->replayPendingFor($userId, array_merge([$appUserId], $aliases));
        return $userId;
    }

    /** Re-resolve any pending events whose app_user_id we now know. */
    private function replayPendingFor(int $userId, array $rcIds): void
    {
        $rcIds = array_values(array_filter(array_unique(array_map('strval', $rcIds))));
        if (!$rcIds) return;
        $place = implode(',', array_fill(0, count($rcIds), '?'));
        $rows = DB::all(
            "SELECT id, event_id, event_type, payload_json
               FROM rc_webhook_events
              WHERE resolved_user_id IS NULL
                AND processed_at IS NULL
                AND (app_user_id IN ($place) OR original_app_user_id IN ($place))",
            array_merge($rcIds, $rcIds)
        );
        foreach ($rows as $row) {
            $payload = json_decode($row['payload_json'], true);
            if (!is_array($payload) || !is_array($payload['event'] ?? null)) continue;
            $event = $payload['event'];
            Logger::info('rc_webhook_replay', [
                'event_id' => $row['event_id'],
                'type'     => $row['event_type'],
                'user_id'  => $userId,
            ]);
            try {
                $applied = false;
                switch ($row['event_type']) {
                    case 'INITIAL_PURCHASE':
                    case 'RENEWAL':
                    case 'UNCANCELLATION':
                    case 'PRODUCT_CHANGE':
                    case 'REFUND_REVERSED':
                        $applied = $this->handleActivation($event, $userId, $row['event_type']);
                        break;
                    case 'CANCELLATION':
                        $applied = $this->handleCancellation($event, $userId);
                        break;
                    case 'EXPIRATION':
                        $applied = $this->handleExpiration($event, $userId);
                        break;
                    case 'BILLING_ISSUE':
                        $applied = $this->handleBillingIssue($event, $userId);
                        break;
                    case 'SUBSCRIPTION_PAUSED':
                        $applied = $this->handlePaused($event, $userId);
                        break;
                    case 'SUBSCRIPTION_EXTENDED':
                        $applied = $this->handleExtended($event, $userId);
                        break;
                    case 'NON_RENEWING_PURCHASE':
                        $applied = $this->handleNonRenewing($event, $userId, $row['event_id']);
                        break;
                }
                if ($applied) {
                    DB::update(
                        'rc_webhook_events',
                        ['processed_at' => date('Y-m-d H:i:s'), 'resolved_user_id' => $userId],
                        'id = :id',
                        [':id' => $row['id']]
                    );
                }
            } catch (Throwable $e) {
                Logger::error('rc_webhook_replay_failed', [
                    'event_id' => $row['event_id'],
                    'msg'      => $e->getMessage(),
                ]);
            }
        }
    }

    private function statusFromEvent(array $event, string $default): string
    {
        $period = strtoupper((string)($event['period_type'] ?? ''));
        if ($period === 'TRIAL') return 'trial';
        return $default;
    }

    /**
     * Write the raw event row in the env-dropped path (no idempotent
     * insert collision possible because dropped events still get a row).
     */
    private function logEvent(
        string $eventId,
        string $type,
        string $appUserId,
        string $origUserId,
        string $productId,
        string $env,
        array $payload,
        ?int $resolvedUserId,
        bool $markProcessed
    ): void {
        try {
            DB::insert('rc_webhook_events', [
                'event_id'             => $eventId,
                'event_type'           => $type,
                'app_user_id'          => $appUserId !== '' ? $appUserId : null,
                'original_app_user_id' => $origUserId !== '' ? $origUserId : null,
                'product_id'           => $productId !== '' ? $productId : null,
                'environment'          => $env,
                'payload_json'         => self::safeJsonEncode($payload),
                'resolved_user_id'     => $resolvedUserId,
                'processed_at'         => $markProcessed ? date('Y-m-d H:i:s') : null,
            ]);
        } catch (PDOException $e) {
            // Duplicate is fine here — we already have the row.
            if (($e->errorInfo[1] ?? null) !== 1062) {
                Logger::error('rc_webhook_log_event_failed', ['msg' => $e->getMessage()]);
            }
        }
    }

    /** Strip an optional case-insensitive "Bearer " prefix and trim. */
    private static function stripBearer(string $s): string
    {
        if (preg_match('/^Bearer\s+(.+)$/i', $s, $m)) {
            return trim($m[1]);
        }
        return $s;
    }

    /** json_encode that survives invalid UTF-8 in subscriber attributes. */
    private static function safeJsonEncode(array $value): string
    {
        $out = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        return $out === false ? '{}' : $out;
    }
}
