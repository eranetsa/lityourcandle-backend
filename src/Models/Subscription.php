<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\App;
use App\Core\DB;
use App\Core\Logger;
use App\Core\Settings;

final class Subscription
{
    public static function current(int $userId): ?array
    {
        // Priority ordering — a user can legitimately have multiple
        // subscription rows (a default 'free' row from guest-auth plus
        // a real paid row reassigned by an RC TRANSFER, or a stale
        // 'expired' row plus a fresh 'active' one). Return whichever
        // matters most for gating:
        //   1) paid plans before free
        //   2) active status before any other status
        //   3) within those, the row with the latest expires_at wins
        //   4) latest id as final tiebreaker
        return DB::one(
            "SELECT * FROM subscriptions
              WHERE user_id = :uid
              ORDER BY
                  CASE WHEN plan IN ('weekly','monthly','yearly','lifetime') THEN 0 ELSE 1 END ASC,
                  CASE WHEN status = 'active' THEN 0
                       WHEN status IN ('trial','grace') THEN 1
                       ELSE 2 END ASC,
                  COALESCE(expires_at, '9999-12-31') DESC,
                  id DESC
              LIMIT 1",
            [':uid' => $userId]
        );
    }

    /** Active = paid+not expired, or in trial. */
    public static function isActive(?array $sub): bool
    {
        if (!$sub) return false;
        if (in_array($sub['status'], ['expired', 'canceled'], true)) return false;
        if (!empty($sub['expires_at']) && strtotime($sub['expires_at']) < time()) return false;
        return true;
    }

    public static function isPaidPlan(?array $sub): bool
    {
        return $sub && in_array($sub['plan'], ['weekly','monthly','yearly'], true) && self::isActive($sub);
    }

    public static function applyStoreResult(int $userId, string $store, array $r, string $plan): int
    {
        $existing = self::current($userId);
        $sessions = self::sessionsForPlan($plan);
        $data = [
            'user_id'              => $userId,
            'plan'                 => $plan,
            'status'               => $r['is_trial'] ? 'trial' : 'active',
            'store'                => $store,
            'store_product_id'     => $r['product_id'] ?? null,
            'store_original_tx'    => $r['original_tx'] ?? null,
            'store_latest_receipt' => isset($r['raw']) ? json_encode($r['raw']) : null,
            'expires_at'           => $r['expires_at'] ?? null,
            'trial_ends_at'        => $r['is_trial'] ? ($r['expires_at'] ?? null) : null,
            'auto_renew'           => $r['auto_renew'] ? 1 : 0,
            'sessions_total'       => $sessions,
            'sessions_remaining'   => $sessions,
        ];
        if ($existing && $existing['store'] === $store
            && ($existing['store_original_tx'] ?? null) === ($r['original_tx'] ?? null)) {
            DB::update('subscriptions', $data, 'id = :id', [':id' => $existing['id']]);
            return (int)$existing['id'];
        }
        return DB::insert('subscriptions', $data);
    }

    public static function sessionsForPlan(string $plan): int
    {
        // Admin can override the per-plan session count from
        // /admin → الباقات. Falls back to config/config.php defaults.
        $cfg = App::config('plans');
        switch ($plan) {
            case 'weekly': {
                $admin = Settings::get('plan_weekly_sessions');
                if ($admin !== null && $admin !== '') return max(0, (int)$admin);
                return (int)($cfg['weekly']['sessions'] ?? 0);
            }
            case 'monthly': {
                $admin = Settings::get('plan_monthly_sessions');
                if ($admin !== null && $admin !== '') return max(0, (int)$admin);
                return (int)($cfg['monthly']['sessions'] ?? 1);
            }
            case 'yearly': {
                $admin = Settings::get('plan_yearly_sessions_per_month');
                $perMonth = ($admin !== null && $admin !== '')
                    ? max(0, (int)$admin)
                    : (int)($cfg['yearly']['sessions_per_month'] ?? 2);
                return $perMonth * 12;
            }
            default:
                return 0;
        }
    }

    /**
     * Free users don't have a subscription row to draw credits from, so we
     * count the sessions they've already booked this calendar month with
     * paid_with='free' and compare to the admin-configured cap.
     */
    public static function freeMonthlyRemaining(int $userId): int
    {
        $cap = (int)(Settings::get('plan_free_sessions_per_month', '0') ?? 0);
        if ($cap <= 0) return 0;
        $row = DB::one(
            "SELECT COUNT(*) AS n
               FROM sessions
              WHERE user_id = :uid
                AND paid_with = 'free'
                AND status NOT IN ('canceled','no_show')
                AND YEAR(created_at)  = YEAR(CURDATE())
                AND MONTH(created_at) = MONTH(CURDATE())",
            [':uid' => $userId]
        );
        $used = (int)($row['n'] ?? 0);
        return max(0, $cap - $used);
    }

    // =========================================================
    //  RevenueCat webhook integration
    // =========================================================

    /**
     * Apply an inbound RevenueCat event to the user's subscription row.
     *
     * $opts:
     *   event_type           string  — RC event.type (informational, logged)
     *   status               ?string — target status or null to preserve
     *   auto_renew           ?int    — 0/1 or null to preserve
     *   reset_sessions       bool    — clobber sessions_remaining = sessions_total
     *   zero_remaining       bool    — force sessions_remaining = 0 (refund/expire)
     *   force_expires_now    bool    — set expires_at = NOW() (refund/expire)
     *   use_grace_expiration bool    — prefer grace_period_expiration_at_ms
     *   is_backfill          bool    — REST back-fill path. Writes the
     *                                  receipt to rc_backfill_receipt
     *                                  instead of store_latest_receipt
     *                                  (the latter is sacred — it holds
     *                                  the real Apple/Play receipt the
     *                                  webhook delivered) and stamps
     *                                  last_synced_at = NOW().
     */
    public static function upsertFromRcEvent(int $userId, array $event, array $opts = []): int
    {
        $productId  = (string)($event['product_id'] ?? $event['new_product_id'] ?? '');
        $plan       = self::planFromRcProduct($productId);
        $store      = self::storeFromRc((string)($event['store'] ?? ''));
        $originalTx = (string)($event['original_transaction_id'] ?? $event['transaction_id'] ?? '');

        // Parenthesised — without these, ?: binds tighter than ?? and the
        // whole expression evaluates to the boolean `true` for grace flows.
        $useGrace = (bool)($opts['use_grace_expiration'] ?? false);
        $expMs    = $useGrace
            ? ($event['grace_period_expiration_at_ms'] ?? $event['expiration_at_ms'] ?? null)
            : ($event['expiration_at_ms'] ?? null);
        $expiresAt = !empty($opts['force_expires_now'])
            ? date('Y-m-d H:i:s')
            : self::msToDatetime(is_numeric($expMs) ? (int)$expMs : null);

        $sessions = $plan ? self::sessionsForPlan($plan) : 0;

        // Match priority:
        //   1) same store + same original_tx  (renewal of the same chain)
        //   2) newest row for the user        (product change, recovery)
        $existing = null;
        if ($originalTx !== '' && $store !== 'none') {
            $existing = DB::one(
                'SELECT * FROM subscriptions
                  WHERE user_id = :uid AND store = :s AND store_original_tx = :tx
                  ORDER BY id DESC LIMIT 1 FOR UPDATE',
                [':uid' => $userId, ':s' => $store, ':tx' => $originalTx]
            );
        }
        if (!$existing) {
            $existing = DB::one(
                'SELECT * FROM subscriptions
                  WHERE user_id = :uid ORDER BY id DESC LIMIT 1 FOR UPDATE',
                [':uid' => $userId]
            );
        }

        $isBackfill = !empty($opts['is_backfill']);
        $data = [
            'user_id'              => $userId,
            'plan'                 => $plan ?? ($existing['plan'] ?? 'free'),
            'store'                => $store !== 'none' ? $store : ($existing['store'] ?? 'none'),
            'store_product_id'     => $productId !== '' ? $productId : ($existing['store_product_id'] ?? null),
            'store_original_tx'    => $originalTx !== '' ? $originalTx : ($existing['store_original_tx'] ?? null),
            'expires_at'           => $expiresAt ?? ($existing['expires_at'] ?? null),
        ];
        if ($isBackfill) {
            // Back-fill must NEVER overwrite the real Apple/Play receipt.
            // Stash the snapshot in a side column and stamp the sync time.
            $data['rc_backfill_receipt'] = self::safeJsonEncode($event);
            $data['last_synced_at']      = date('Y-m-d H:i:s');
        } else {
            $data['store_latest_receipt'] = self::safeJsonEncode($event);
        }

        if (array_key_exists('status', $opts) && $opts['status'] !== null) {
            $data['status'] = $opts['status'];
        } elseif (!$existing) {
            $data['status'] = 'active';
        }

        if (array_key_exists('auto_renew', $opts) && $opts['auto_renew'] !== null) {
            $data['auto_renew'] = (int)$opts['auto_renew'] ? 1 : 0;
        }

        $periodType = strtoupper((string)($event['period_type'] ?? ''));
        if ($periodType === 'TRIAL') {
            $data['trial_ends_at'] = $expiresAt;
        }

        // Session credits.
        //   reset_sessions=true       → fresh allocation at plan total
        //   new row, no plan match    → 0
        //   existing row, same plan   → preserve remaining as-is
        //                                (extra-session purchases stay
        //                                 on top of the plan quota)
        //   existing row, plan change → clamp remaining to new total
        //                                (handles downgrades correctly)
        if ($plan && !empty($opts['reset_sessions'])) {
            $data['sessions_total']     = $sessions;
            $data['sessions_remaining'] = $sessions;
        } elseif ($plan && !$existing) {
            $data['sessions_total']     = $sessions;
            $data['sessions_remaining'] = $sessions;
        } elseif ($plan && $existing) {
            $data['sessions_total'] = $sessions;
            $current      = (int)($existing['sessions_remaining'] ?? 0);
            $existingPlan = (string)($existing['plan'] ?? '');
            if ($existingPlan === $plan) {
                // Same plan — preserve remaining untouched so the
                // user's accumulated extra-session credits survive
                // every BACKFILL_FROM_SNAPSHOT and RENEWAL refresh.
                $data['sessions_remaining'] = $current;
            } else {
                // Plan changed — clamp for downgrades.
                $data['sessions_remaining'] = max(0, min($current, $sessions));
            }
        }

        if (!empty($opts['zero_remaining'])) {
            $data['sessions_remaining'] = 0;
        }

        if ($existing) {
            DB::update('subscriptions', $data, 'id = :id', [':id' => $existing['id']]);
            Logger::info('rc_subscription_updated', [
                'user_id'    => $userId,
                'sub_id'     => (int)$existing['id'],
                'plan'       => $data['plan'] ?? null,
                'status'     => $data['status'] ?? $existing['status'],
                'expires_at' => $data['expires_at'] ?? null,
                'event_type' => $opts['event_type'] ?? null,
            ]);
            return (int)$existing['id'];
        }

        // No row yet — bootstrap.
        $data['status']             = $data['status']     ?? 'active';
        $data['auto_renew']         = $data['auto_renew'] ?? 1;
        $data['sessions_total']     = $data['sessions_total']     ?? $sessions;
        $data['sessions_remaining'] = $data['sessions_remaining'] ?? $sessions;
        $newId = DB::insert('subscriptions', $data);
        Logger::info('rc_subscription_inserted', [
            'user_id'    => $userId,
            'sub_id'     => $newId,
            'plan'       => $data['plan'],
            'status'     => $data['status'],
            'expires_at' => $data['expires_at'] ?? null,
            'event_type' => $opts['event_type'] ?? null,
        ]);
        return $newId;
    }

    /**
     * NON_RENEWING_PURCHASE: one-shot session credit.
     *
     * Idempotent across BOTH delivery paths (webhook + REST back-fill) via
     * TWO marker columns on `transactions`:
     *
     *   store_tx_id      — primary key. The webhook uses event.id (per-delivery
     *                      UUID); the REST back-fill uses non_subscriptions.id
     *                      (RC's stable purchase row id). These are different
     *                      identifiers for the SAME purchase, so a row may
     *                      exist under either.
     *   store_alt_tx_id  — the underlying Apple/Google store_transaction_id
     *                      (purchase-stable). The webhook reads it from
     *                      event.transaction_id / store_transaction_identifier
     *                      and the REST snapshot reads it from
     *                      non_subscriptions[pid][].store_transaction_id.
     *                      Both paths write the same value here, so we can
     *                      detect cross-path duplicates.
     *
     * The dedupe SELECT checks both columns; the INSERT writes both. A
     * webhook-then-backfill (or backfill-then-webhook) ordering both
     * land on the same store_alt_tx_id and the second call no-ops.
     */
    public static function creditExtraSession(int $userId, array $event, string $eventId): void
    {
        // Pull the underlying purchase id for cross-path dedupe. Webhook
        // and REST snapshots both expose it but under slightly different
        // keys — try them all.
        $altTx = (string)(
            $event['store_transaction_identifier']
            ?? $event['transaction_id']
            ?? $event['store_transaction_id']
            ?? ''
        );

        // Idempotency: have we credited this purchase under EITHER key?
        $sql    = "SELECT id, store_tx_id, store_alt_tx_id FROM transactions
                    WHERE kind = 'extra_session'
                      AND (store_tx_id = :pri";
        $params = [':pri' => $eventId];
        if ($altTx !== '') {
            $sql .= " OR store_tx_id = :alt OR store_alt_tx_id = :alt2";
            $params[':alt']  = $altTx;
            $params[':alt2'] = $altTx;
        }
        $sql .= ') LIMIT 1';
        $marker = DB::one($sql, $params);
        if ($marker) {
            Logger::info('rc_extra_session_already_credited', [
                'user_id'  => $userId,
                'event_id' => $eventId,
                'alt_tx'   => $altTx !== '' ? substr(hash('sha256', $altTx), 0, 12) : null,
                'hit_via'  => $marker['store_tx_id'] === $eventId ? 'primary' : 'alt',
            ]);
            return;
        }

        $store = self::storeFromRc((string)($event['store'] ?? ''));
        // `transactions.store` enum has no 'none' — fall back to 'manual'.
        $txStore = in_array($store, ['apple', 'google', 'manual'], true) ? $store : 'manual';

        $sub = DB::one(
            'SELECT * FROM subscriptions WHERE user_id = :uid ORDER BY id DESC LIMIT 1 FOR UPDATE',
            [':uid' => $userId]
        );
        if ($sub) {
            DB::run(
                'UPDATE subscriptions
                    SET sessions_remaining = sessions_remaining + 1,
                        store_latest_receipt = :raw
                  WHERE id = :id',
                [':id' => $sub['id'], ':raw' => self::safeJsonEncode($event)]
            );
        } else {
            // No row yet — create a minimal 'free' row so the credit is
            // spendable by BookingController.
            DB::insert('subscriptions', [
                'user_id'              => $userId,
                'plan'                 => 'free',
                'status'               => 'active',
                'store'                => $store,
                'store_product_id'     => (string)($event['product_id'] ?? ''),
                'store_original_tx'    => (string)($event['transaction_id'] ?? ''),
                'store_latest_receipt' => self::safeJsonEncode($event),
                'auto_renew'           => 0,
                'sessions_total'       => 1,
                'sessions_remaining'   => 1,
            ]);
        }

        // Idempotency marker (the transactions row also doubles as audit).
        DB::insert('transactions', [
            'user_id'         => $userId,
            'kind'            => 'extra_session',
            'amount'          => 0.00, // price unknown at webhook time
            'currency'        => 'SAR',
            'store'           => $txStore,
            'store_tx_id'     => $eventId,
            'store_alt_tx_id' => $altTx !== '' ? $altTx : null,
            'status'          => 'succeeded',
            'metadata_json'   => self::safeJsonEncode($event),
        ]);

        Logger::info('rc_extra_session_credited', [
            'user_id'    => $userId,
            'event_id'   => $eventId,
            'product_id' => (string)($event['product_id'] ?? ''),
        ]);
    }

    /**
     * Anchored substring match — boundaries on either side avoid false
     * hits on SKUs like "sessions_monthly_addon" (which is NOT the monthly
     * plan) and "yearly_with_monthly_billing" (which IS yearly).
     *
     * The product id is split on '_', '.', ':', '-' and we look for an
     * exact token match against weekly/monthly/yearly/annual/lifetime.
     */
    public static function planFromRcProduct(string $productId): ?string
    {
        $id = strtolower($productId);
        if ($id === '') return null;
        $tokens = preg_split('/[_.:\-\s]+/', $id) ?: [];

        // Lifetime is not modelled — leave as null.
        if (in_array('lifetime', $tokens, true)) return null;
        // Yearly takes priority over monthly so "yearly_with_monthly_billing"
        // resolves correctly.
        if (in_array('yearly', $tokens, true) || in_array('annual', $tokens, true)) return 'yearly';
        if (in_array('monthly', $tokens, true)) return 'monthly';
        if (in_array('weekly', $tokens, true))  return 'weekly';
        return null;
    }

    private static function storeFromRc(string $rcStore): string
    {
        // RC -> our `subscriptions.store` ENUM ('apple','google','manual','none').
        return match (strtoupper($rcStore)) {
            'APP_STORE', 'MAC_APP_STORE' => 'apple',
            'PLAY_STORE'                 => 'google',
            'PROMOTIONAL', 'STRIPE',
            'RC_BILLING', 'PADDLE',
            'AMAZON'                     => 'manual',
            default                      => 'none',
        };
    }

    private static function msToDatetime(?int $ms): ?string
    {
        if (!$ms || $ms <= 0) return null;
        return date('Y-m-d H:i:s', (int)($ms / 1000));
    }

    /** json_encode that survives invalid UTF-8 in subscriber attributes. */
    private static function safeJsonEncode(array $value): string
    {
        $out = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        return $out === false ? '{}' : $out;
    }
}
