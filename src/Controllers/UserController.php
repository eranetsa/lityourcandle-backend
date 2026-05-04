<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

final class UserController
{
    public function me(Request $req): void
    {
        $uid = $req->userId();
        $user = DB::one(
            'SELECT id, name, email, phone, language, role, avatar_url, trial_started_at, trial_ends_at, created_at
             FROM users WHERE id = :id', [':id' => $uid]
        );
        $sub = DB::one(
            'SELECT plan, status, expires_at, trial_ends_at, sessions_remaining, auto_renew
             FROM subscriptions WHERE user_id = :uid ORDER BY id DESC LIMIT 1',
            [':uid' => $uid]
        );
        Response::json(['user' => $user, 'subscription' => $sub]);
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

    public function savePushToken(Request $req): void
    {
        $data = Validator::check($req->body, [
            'token'      => 'required|string|max:255',
            'platform'   => 'required|in:ios,android,web',
            'voip_token' => 'string|max:255',
            'device_id'  => 'string|max:64',
        ]);

        // Upsert into push_tokens (multi-device). The same token from a
        // different user just rebinds — UNIQUE(token) takes care of that.
        DB::run(
            'INSERT INTO push_tokens (user_id, token, platform, voip_token, device_id, last_seen_at)
             VALUES (:uid, :tok, :plat, :voip, :did, NOW())
             ON DUPLICATE KEY UPDATE
                 user_id = VALUES(user_id),
                 platform = VALUES(platform),
                 voip_token = VALUES(voip_token),
                 device_id = VALUES(device_id),
                 last_seen_at = NOW()',
            [
                ':uid'  => $req->userId(),
                ':tok'  => $data['token'],
                ':plat' => $data['platform'],
                ':voip' => $data['voip_token'] ?? null,
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
}
