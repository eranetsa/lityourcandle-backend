<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\App;
use App\Core\DB;

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
        $cfg = App::config('plans');
        return match ($plan) {
            'monthly' => (int)($cfg['monthly']['sessions'] ?? 1),
            'yearly'  => (int)(($cfg['yearly']['sessions_per_month'] ?? 2) * 12),
            default   => 0,
        };
    }
}
