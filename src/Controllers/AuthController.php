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

    /**
     * Sign in with Apple.
     *
     * Receives the identity_token (JWT) issued by Apple, verifies it against
     * Apple's public keys, then upserts a user row keyed on the stable `sub`
     * claim. Works for both new and returning users; converts an existing
     * guest row if the device_id matches.
     */
    public function apple(Request $req): void
    {
        $data = Validator::check($req->body, [
            'identity_token' => 'required|string',
            'full_name'      => 'string|max:120',
            'device_id'      => 'string|max:64',
        ]);

        $claims = $this->verifyAppleToken($data['identity_token']);
        if (!$claims) {
            Response::error('invalid_apple_token', 401);
        }

        $appleSub = (string)$claims['sub'];
        $email    = isset($claims['email']) ? strtolower(trim((string)$claims['email'])) : null;
        $name     = !empty($data['full_name']) ? trim($data['full_name']) : 'مستخدم';
        $deviceId = !empty($data['device_id']) ? trim($data['device_id']) : null;

        // 1. Find by apple_sub (returning Apple user)
        $user = DB::one('SELECT id, role FROM users WHERE apple_sub = :s', [':s' => $appleSub]);

        // 2. Find by email (user registered with same email before)
        if (!$user && $email) {
            $user = DB::one('SELECT id, role FROM users WHERE email = :e', [':e' => $email]);
        }

        // 3. Find existing guest on this device to upgrade
        if (!$user && $deviceId) {
            $user = DB::one("SELECT id, role FROM users WHERE device_id = :d AND role = 'guest'", [':d' => $deviceId]);
        }

        if ($user) {
            // Update apple_sub and name if this is the first Apple login
            $updates = ['apple_sub' => $appleSub, 'last_login_at' => date('Y-m-d H:i:s')];
            if ($user['role'] === 'guest') {
                $updates['role'] = 'user';
                $updates['name'] = $name;
                if ($email) $updates['email'] = $email;
            }
            DB::update('users', $updates, 'id = :id', [':id' => $user['id']]);
            $userId = (int)$user['id'];
        } else {
            // New user — generate a placeholder email if Apple hides it
            $storeEmail = $email ?? "apple+{$appleSub}@appleid.lityourcandle.local";
            $userId = DB::insert('users', [
                'name'          => $name,
                'email'         => $storeEmail,
                'password_hash' => Auth::hashPassword(bin2hex(random_bytes(16))),
                'language'      => 'ar',
                'role'          => 'user',
                'apple_sub'     => $appleSub,
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
        ]);
    }

    /**
     * Verify an Apple identity token (JWT) against Apple's public keys.
     * Returns the decoded claims array on success, null on failure.
     */
    private function verifyAppleToken(string $token): ?array
    {
        try {
            $keysJson = @file_get_contents('https://appleid.apple.com/auth/keys');
            if (!$keysJson) return null;

            $keysData = json_decode($keysJson, true);
            if (empty($keysData['keys'])) return null;

            $keySet  = \Firebase\JWT\JWK::parseKeySet($keysData);
            $decoded = \Firebase\JWT\JWT::decode($token, $keySet);
            $claims  = (array)$decoded;

            if (empty($claims['sub'])) return null;
            if (($claims['iss'] ?? '') !== 'https://appleid.apple.com') return null;

            return $claims;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Sign in with Google (Android / web).
     * Receives a Google ID token, verifies it via Google's token-info endpoint,
     * then upserts a user row keyed on the Google `sub`.
     */
    public function google(Request $req): void
    {
        $data = Validator::check($req->body, [
            'id_token'  => 'required|string',
            'device_id' => 'string|max:64',
        ]);

        $claims = $this->verifyGoogleToken($data['id_token']);
        if (!$claims) {
            Response::error('invalid_google_token', 401);
        }

        $googleSub = (string)$claims['sub'];
        $email     = isset($claims['email']) ? strtolower(trim((string)$claims['email'])) : null;
        $name      = (string)($claims['name'] ?? ($claims['given_name'] ?? 'مستخدم'));
        $deviceId  = !empty($data['device_id']) ? trim($data['device_id']) : null;

        // 1. Find by google_sub (returning Google user)
        $user = DB::one('SELECT id, role FROM users WHERE google_sub = :s', [':s' => $googleSub]);

        // 2. Find by email
        if (!$user && $email) {
            $user = DB::one('SELECT id, role FROM users WHERE email = :e', [':e' => $email]);
        }

        // 3. Upgrade existing guest on this device
        if (!$user && $deviceId) {
            $user = DB::one("SELECT id, role FROM users WHERE device_id = :d AND role = 'guest'", [':d' => $deviceId]);
        }

        if ($user) {
            $updates = ['google_sub' => $googleSub, 'last_login_at' => date('Y-m-d H:i:s')];
            if ($user['role'] === 'guest') {
                $updates['role'] = 'user';
                $updates['name'] = $name;
                if ($email) $updates['email'] = $email;
            }
            DB::update('users', $updates, 'id = :id', [':id' => $user['id']]);
            $userId = (int)$user['id'];
        } else {
            $storeEmail = $email ?? "google+{$googleSub}@google.lityourcandle.local";
            $userId = DB::insert('users', [
                'name'          => $name,
                'email'         => $storeEmail,
                'password_hash' => Auth::hashPassword(bin2hex(random_bytes(16))),
                'language'      => 'ar',
                'role'          => 'user',
                'google_sub'    => $googleSub,
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
        ]);
    }

    /** Verify Google ID token via Google's tokeninfo endpoint. */
    private function verifyGoogleToken(string $idToken): ?array
    {
        try {
            $url  = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken);
            $json = @file_get_contents($url);
            if (!$json) return null;

            $data = json_decode($json, true);
            if (empty($data['sub']) || !empty($data['error'])) return null;
            // Optionally validate aud matches your Google client IDs here

            return $data;
        } catch (\Throwable $e) {
            return null;
        }
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
