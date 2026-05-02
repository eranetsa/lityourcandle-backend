<?php
declare(strict_types=1);

namespace App\Core;

final class Request
{
    public string $method;
    public string $path;
    public array  $query;
    public array  $body;
    public array  $headers;
    public array  $params = [];      // route params (e.g. /consultants/{id})
    public ?array $auth   = null;    // populated by AuthMiddleware

    public function __construct()
    {
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri          = $_SERVER['REQUEST_URI'] ?? '/';
        $this->path   = rtrim(parse_url($uri, PHP_URL_PATH) ?? '/', '/') ?: '/';
        $this->query  = $_GET ?? [];
        $this->headers = self::collectHeaders();

        $raw = file_get_contents('php://input') ?: '';
        $ct  = $this->headers['content-type'] ?? '';
        if (stripos($ct, 'application/json') !== false && $raw !== '') {
            $decoded = json_decode($raw, true);
            $this->body = is_array($decoded) ? $decoded : [];
        } else {
            $this->body = $_POST ?? [];
        }
    }

    private static function collectHeaders(): array
    {
        $h = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($k, 5)));
                $h[$name] = $v;
            }
        }
        if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
            $h['authorization'] = $_SERVER['HTTP_AUTHORIZATION'];
        }
        if (!empty($_SERVER['CONTENT_TYPE'])) {
            $h['content-type'] = $_SERVER['CONTENT_TYPE'];
        }
        return $h;
    }

    public function input(string $key, $default = null)
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function bearer(): ?string
    {
        $auth = $this->headers['authorization'] ?? '';
        if (preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    public function ip(): string
    {
        return $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['REMOTE_ADDR']
            ?? '0.0.0.0';
    }

    public function userId(): ?int
    {
        return $this->auth['sub'] ?? null;
    }

    public function userRole(): ?string
    {
        return $this->auth['role'] ?? null;
    }
}
