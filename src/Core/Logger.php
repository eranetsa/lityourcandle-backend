<?php
declare(strict_types=1);

namespace App\Core;

final class Logger
{
    public static function write(string $level, string $event, array $ctx = []): void
    {
        $line = json_encode([
            't'     => date('c'),
            'lvl'   => $level,
            'evt'   => $event,
            'ctx'   => $ctx,
        ], JSON_UNESCAPED_UNICODE);
        $path = App::rootDir() . '/storage/logs/app-' . date('Y-m-d') . '.log';
        @file_put_contents($path, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    public static function info(string $event, array $ctx = []): void  { self::write('info', $event, $ctx); }
    public static function warn(string $event, array $ctx = []): void  { self::write('warn', $event, $ctx); }
    public static function error(string $event, array $ctx = []): void { self::write('error', $event, $ctx); }
}
