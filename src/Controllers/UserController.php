<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Core\Validator;
use App\Models\Subscription;
use App\Services\RevenueCatBackfill;

final class UserController
{
    public function me(Request $req): void
    {
        $uid = $req->userId();
        $user = DB::one(
            'SELECT id, name, email, phone, language, role, avatar_url, trial_started_at, trial_ends_at, created_at
             FROM users WHERE id = :id', [':id' => $uid]
        );
        // Route through Subscription::current() so we get the
        // priority-ordered pick (paid+active beats latest free row
        // inserted by guest-auth). Then slim down to the client shape.
        $row = Subscription::current($uid);
        $sub = $row ? [
            'plan'               => $row['plan'],
            'status'             => $row['status'],
            'expires_at'         => $row['expires_at'] ?? null,
            'trial_ends_at'      => $row['trial_ends_at'] ?? null,
            'sessions_remaining' => (int)($row['sessions_remaining'] ?? 0),
            'sessions_total'     => (int)($row['sessions_total'] ?? 0),
            'auto_renew'         => (int)($row['auto_renew'] ?? 0),
        ] : null;

        // Surface admin-tunable limits to the client so UI strings (e.g.
        // "remaining today: 4/N") and gating logic match the backend
        // exactly. Without this the app uses a hard-coded N=5 even after
        // an admin changes /admin → AI free daily limit.
        $limits = [
            'ai_free_daily_limit'             => (int)(Settings::get('ai_free_daily_limit', '3') ?? 3),
            'plan_free_sessions_per_month'    => (int)(Settings::get('plan_free_sessions_per_month', '0') ?? 0),
            'free_sessions_remaining_month'   => Subscription::freeMonthlyRemaining($uid),
        ];

        Response::json([
            'user'         => $user,
            'subscription' => $sub,
            'limits'       => $limits,
        ]);
    }

    public function update(Request $req): void
    {
        $data = Validator::check($req->body, [
            'name'     => 'string|min:2|max:120',
            'phone'    => 'string|max:32',
            'language' => 'in:ar,en',
            'avatar_url' => 'string|max:255',
        ]);
        if (!$data) Response::error('nothing_to_update', 422);
        DB::update('users', $data, 'id = :id', [':id' => $req->userId()]);
        Response::json(['ok' => true]);
    }

    /**
     * Persist a push token for the caller.
     *
     * Accepts either a plain push token (APNs/FCM/web), a VoIP/PushKit
     * token alongside it, or both. iOS clients call this endpoint twice
     * on cold start:
     *
     *   1) After getDevicePushTokenAsync — { token, platform: "ios" }
     *   2) After PushKit register event  — { token, platform: "ios",
     *                                        voip_token: "<hex>" }
     *
     * Both calls land on the same push_tokens row keyed by UNIQUE(token);
     * the VoIP call's `token` is the same APNs token from step 1.
     *
     * The upsert uses COALESCE(VALUES(voip_token), voip_token) so a later
     * APNs-only call cannot wipe out a previously-saved PushKit token.
     * Empty strings are normalized to NULL so a missing field never
     * clobbers existing data.
     */
    public function savePushToken(Request $req): void
    {
        $data = Validator::check($req->body, [
            'token'      => 'required|string|max:255',
            'platform'   => 'required|in:ios,android,web',
            'voip_token' => 'string|max:255',
            'device_id'  => 'string|max:64',
        ]);

        $voip = isset($data['voip_token']) ? trim((string)$data['voip_token']) : '';
        $voipParam = $voip !== '' ? $voip : null;

        // Upsert into push_tokens (multi-device). UNIQUE(token) keys the
        // row; the same token rebinding to a different user just updates
        // user_id. COALESCE preserves voip_token when the call doesn't
        // include one (APNs-only update from step 1 above).
        DB::run(
            'INSERT INTO push_tokens (user_id, token, platform, voip_token, device_id, last_seen_at)
             VALUES (:uid, :tok, :plat, :voip, :did, NOW())
             ON DUPLICATE KEY UPDATE
                 user_id      = VALUES(user_id),
                 platform     = VALUES(platform),
                 voip_token   = COALESCE(VALUES(voip_token), voip_token),
                 device_id    = COALESCE(VALUES(device_id), device_id),
                 last_seen_at = NOW()',
            [
                ':uid'  => $req->userId(),
                ':tok'  => $data['token'],
                ':plat' => $data['platform'],
                ':voip' => $voipParam,
                ':did'  => $data['device_id'] ?? null,
            ]
        );

        // Backwards-compat: keep the legacy single-token columns on `users`
        // populated so older PushService callers (and the admin dashboard)
        // keep working until they migrate to push_tokens.
        DB::update('users',
            ['push_token' => $data['token'], 'push_platform' => $data['platform']],
            'id = :id', [':id' => $req->userId()]
        );
        Response::json(['ok' => true]);
    }

    public function changePassword(Request $req): void
    {
        $data = Validator::check($req->body, [
            'current_password' => 'required|string|min:8',
            'new_password'     => 'required|string|min:8|max:128',
        ]);
        $u = DB::one('SELECT password_hash FROM users WHERE id = :id', [':id' => $req->userId()]);
        if (!$u || !Auth::verifyPassword($data['current_password'], $u['password_hash'])) {
            Response::error('invalid_current_password', 401);
        }
        DB::update('users',
            ['password_hash' => Auth::hashPassword($data['new_password'])],
            'id = :id', [':id' => $req->userId()]
        );
        Response::json(['ok' => true]);
    }

    /**
     * Reconcile the caller's subscription state from RevenueCat's REST
     * snapshot. The app calls this on cold launch and right after every
     * Purchases.restorePurchases() so the backend never drifts behind RC
     * — paying customers stop seeing the paywall after restoring on a
     * new install / new device sharing the same Apple ID.
     *
     * Always returns 200 with a small status payload (even on RC errors)
     * so the client can fold this into a normal startup flow without
     * special error handling. The actual sync result is logged
     * server-side.
     */
    public function syncRc(Request $req): void
    {
        $uid = $req->userId();
        try {
            $result = (new RevenueCatBackfill())->syncUser($uid);
            Response::json([
                'ok'                  => true,
                'matched_id'          => $result['matched_id']         ?? null,
                'active_subs_seen'    => (int)($result['active_subs_seen']  ?? 0),
                'sub_rows_written'    => (int)($result['sub_rows_written']  ?? 0),
                'non_sub_credits'     => (int)($result['non_sub_credits']   ?? 0),
                'error'               => $result['error']              ?? null,
            ]);
        } catch (\Throwable $e) {
            // Never let an RC outage crash the cold-launch flow.
            Response::json(['ok' => false, 'error' => 'rc_unavailable'], 200);
        }
    }
}
