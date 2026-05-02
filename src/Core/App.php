<?php
declare(strict_types=1);

namespace App\Core;

use Dotenv\Dotenv;

final class App
{
    private static array $config = [];
    private static bool $booted = false;

    public static function boot(string $rootDir): void
    {
        if (self::$booted) {
            return;
        }
        require_once $rootDir . '/vendor/autoload.php';
        if (file_exists($rootDir . '/.env')) {
            Dotenv::createImmutable($rootDir)->load();
        }
        self::$config = require $rootDir . '/config/config.php';
        date_default_timezone_set(self::$config['app']['timezone']);

        $debug = self::$config['app']['debug'];
        ini_set('display_errors', $debug ? '1' : '0');
        ini_set('log_errors', '1');
        error_reporting($debug ? E_ALL : (E_ALL & ~E_NOTICE & ~E_DEPRECATED));

        set_exception_handler(static function (\Throwable $e): void {
            Logger::error('uncaught', [
                'msg' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            $debug = self::config('app.debug');
            $payload = ['error' => 'server_error'];
            if ($debug) {
                $payload['message'] = $e->getMessage();
                $payload['trace']   = explode("\n", $e->getTraceAsString());
            }
            Response::json($payload, 500);
        });

        self::$booted = true;
    }

    public static function config(string $dotPath, $default = null)
    {
        $parts = explode('.', $dotPath);
        $node  = self::$config;
        foreach ($parts as $p) {
            if (!is_array($node) || !array_key_exists($p, $node)) {
                return $default;
            }
            $node = $node[$p];
        }
        return $node;
    }

    public static function rootDir(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Expand a relative upload path (e.g. "/uploads/consultants/x.jpg") into
     * an absolute URL using APP_URL. Pass-through for absolute URLs, null,
     * and empty strings.
     */
    public static function absoluteUrl(?string $path): ?string
    {
        if ($path === null || $path === '') return $path;
        if (preg_match('#^https?://#i', $path)) return $path;
        $base = rtrim((string)self::config('app.url', ''), '/');
        if ($base === '') return $path;
        return $base . '/' . ltrim($path, '/');
    }
}
