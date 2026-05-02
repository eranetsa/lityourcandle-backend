<?php
declare(strict_types=1);

namespace App\Core;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

final class Auth
{
    public static function issue(int $userId, string $role, array $extra = []): string
    {
        $cfg = App::config('jwt');
        $now = time();
        $payload = array_merge([
            'iss'  => $cfg['iss'],
            'sub'  => $userId,
            'role' => $role,
            'iat'  => $now,
            'exp'  => $now + (int)$cfg['ttl'],
            'jti'  => bin2hex(random_bytes(8)),
        ], $extra);
        return JWT::encode($payload, $cfg['secret'], $cfg['alg']);
    }

    public static function verify(string $token): ?array
    {
        $cfg = App::config('jwt');
        try {
            $decoded = JWT::decode($token, new Key($cfg['secret'], $cfg['alg']));
            $arr = (array)$decoded;
            $arr['sub']  = (int)($arr['sub'] ?? 0);
            $arr['role'] = (string)($arr['role'] ?? 'user');
            return $arr;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function hashPassword(string $plain): string
    {
        return password_hash($plain, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    public static function verifyPassword(string $plain, string $hash): bool
    {
        return password_verify($plain, $hash);
    }
}
