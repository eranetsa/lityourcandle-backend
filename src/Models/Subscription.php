<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\App;
use App\Core\DB;
use App\Core\Settings;

final class Subscription
{
    public static function current(int $userId): ?array
    {
        return DB::one(
            'SELECT * FROM subscriptions WHERE user_id = :uid ORDER BY id DESC LIMIT 1',
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
}
