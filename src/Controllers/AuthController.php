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
            'name'     => 'required|string|min:2|max:120',
            'email'    => 'required|email|max:190',
            'password' => 'required|string|min:8|max:128',
            'phone'    => 'string|max:32',
            'language' => 'in:ar,en',
        ]);

        $email = strtolower(trim($data['email']));
        $exists = DB::one('SELECT id FROM users WHERE email = :e', [':e' => $email]);
        if ($exists) {
            Response::error('email_already_used', 409);
        }

        $userId = DB::insert('users', [
            'name'          => trim($data['name']),
            'email'         => $email,
            'phone'         => $data['phone']    ?? null,
            'password_hash' => Auth::hashPassword($data['password']),
            'language'      => $data['language'] ?? 'ar',
            'role'          => 'user',
        ]);

        DB::insert('subscriptions', [
            'user_id' => $userId,
            'plan'    => 'free',
            'status'  => 'active',
            'store'   => 'none',
        ]);

        Response::json([
            'token' => Auth::issue($userId, 'user'),
            'user'  => $this->loadUser($userId),
        ], 201);
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
