<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\DB;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;

final class AuthMiddleware implements Middleware
{
    public function handle(Request $req): void
    {
        $token = $req->bearer();
        if (!$token) {
            Response::error('unauthenticated', 401);
        }
        $claims = Auth::verify($token);
        if (!$claims || empty($claims['sub'])) {
            Response::error('invalid_token', 401);
        }
        $user = DB::one('SELECT id, role, is_active FROM users WHERE id = :id', [':id' => $claims['sub']]);
        if (!$user || !$user['is_active']) {
            Response::error('user_inactive', 401);
        }
        $req->auth = [
            'sub'  => (int)$user['id'],
            'role' => $user['role'],
        ];
    }
}
