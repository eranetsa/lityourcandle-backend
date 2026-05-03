<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

final class AuthController
{
    public function register(Request $req): void
    {
        $data = Validator::check($req->body, [
            'name'      => 'required|string|min:2|max:120',
            'email'     => 'required|email|max:190',
            'password'  => 'required|string|min:8|max:128',
            'phone'     => 'string|max:32',
            'language'  => 'in:ar,en',
            'device_id' => 'string|max:64',
        ]);

        $email     = strtolower(trim($data['email']));
        $deviceId  = !empty($data['device_id']) ? trim($data['device_id']) : null;

        // Email taken by someone other than this device's guest? → 409
        $emailOwner = DB::one('SELECT id, role, device_id FROM users WHERE email = :e', [':e' => $email]);
        if ($emailOwner && $emailOwner['role'] !== 'guest' &&
            ($deviceId === null || $emailOwner['device_id'] !== $deviceId)) {
            Response::error('email_already_used', 409);
        }

        // Upgrade path: if this device already has a guest user, convert it to a real user.
        $existingGuest = $deviceId
            ? DB::one("SELECT id FROM users WHERE device_id = :d AND role = 'guest'", [':d' => $deviceId])
            : null;

        if ($existingGuest) {
            DB::update('users', [
                'name'          => trim($data['name']),
                'email'         => $email,
                'phone'         => $data['phone']    ?? null,
                'password_hash' => Auth::hashPassword($data['password']),
                'language'      => $data['language'] ?? 'ar',
                'role'          => 'user',
            ], 'id = :id', [':id' => $existingGuest['id']]);
            $userId = (int)$existingGuest['id'];
        } else {
            $userId = DB::insert('users', [
                'name'          => trim($data['name']),
                'email'         => $email,
                'phone'         => $data['phone']    ?? null,
                'password_hash' => Auth::hashPassword($data['password']),
                'language'      => $data['language'] ?? 'ar',
                'role'          => 'user',
                'device_id'     => $deviceId,
            ]);
            DB::insert('subscriptions', [
                'user_id' => $userId,
                'plan'    => 'free',
                'status'  => 'active',
                'store'   => 'none',
            ]);
        }

        Response::json([
            'token' => Auth::issue($userId, 'user'),
            'user'  => $this->loadUser($userId),
        ], 201);
    }

    /**
     * Anonymous device-bound login. The app calls this on first launch with
     * a per-install device_id. On subsequent launches, the same device_id
     * returns the same guest user (with their mood/AI history intact).
     */
    public function guest(Request $req): void
    {
        $data = Validator::check($req->body, [
            'device_id' => 'required|string|min:8|max:64|regex:/^[A-Za-z0-9_\-]+$/',
            'platform'  => 'in:ios,android,web',
            'language'  => 'in:ar,en',
        ]);

        $deviceId = trim($data['device_id']);
        $platform = $data['platform'] ?? null;
        $language = $data['language'] ?? 'ar';

        $existing = DB::one(
            'SELECT id, role, is_active FROM users WHERE device_id = :d',
            [':d' => $deviceId]
        );

        if ($existing) {
            if (!$existing['is_active']) {
                Response::error('user_inactive', 403);
            }
            DB::run(
                'UPDATE users SET last_login_at = NOW(), platform = COALESCE(:p, platform) WHERE id = :id',
                [':p' => $platform, ':id' => $existing['id']]
            );
            $userId = (int)$existing['id'];
            $role   = (string)$existing['role'];
        } else {
            // Brand new guest. Email is synthetic but kept unique to satisfy the schema.
            $email = "guest+{$deviceId}@guest.lityourcandle.local";
            $userId = DB::insert('users', [
                'name'          => 'ضيف',
                'email'         => $email,
                'password_hash' => Auth::hashPassword(bin2hex(random_bytes(16))),
                'language'      => $language,
                'role'          => 'guest',
                'device_id'     => $deviceId,
                'platform'      => $platform,
            ]);
            DB::insert('subscriptions', [
                'user_id' => $userId,
                'plan'    => 'free',
                'status'  => 'active',
                'store'   => 'none',
            ]);
            $role = 'guest';
        }

        Response::json([
            'token' => Auth::issue($userId, $role),
            'user'  => $this->loadUser($userId),
        ]);
    }

    public function login(Request $req): void
    {
        $data = Validator::check($req->body, [
            'email'    => 'required|email',
            'password' => 'required|string|min:8',
        ]);
        $email = strtolower(trim($data['email']));
        $u = DB::one(
            'SELECT id, password_hash, role, is_active FROM users WHERE email = :e',
            [':e' => $email]
        );
        if (!$u || !Auth::verifyPassword($data['password'], $u['password_hash']) || !$u['is_active']) {
            Response::error('invalid_credentials', 401);
        }

        DB::run('UPDATE users SET last_login_at = NOW() WHERE id = :id', [':id' => $u['id']]);

        Response::json([
            'token' => Auth::issue((int)$u['id'], $u['role']),
            'user'  => $this->loadUser((int)$u['id']),
        ]);
    }

    private function loadUser(int $id): array
    {
        $u = DB::one(
            'SELECT id, name, email, phone, language, role, avatar_url, trial_started_at, trial_ends_at, created_at
             FROM users WHERE id = :id',
            [':id' => $id]
        );
        return $u ?? [];
    }
}
