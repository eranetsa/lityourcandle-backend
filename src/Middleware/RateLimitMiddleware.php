<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\App;
use App\Core\DB;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;

final class RateLimitMiddleware implements Middleware
{
    public function handle(Request $req): void
    {
        $limit = (int)App::config('rate_limit.per_minute', 120);
        $key   = sha1($req->ip() . '|' . $req->path);
        $now   = date('Y-m-d H:i:00');

        DB::run(
            'INSERT INTO rate_limits (bucket_key, counter, window_started)
             VALUES (:k, 1, :w)
             ON DUPLICATE KEY UPDATE
                counter = IF(window_started = VALUES(window_started), counter + 1, 1),
                window_started = IF(window_started = VALUES(window_started), window_started, VALUES(window_started))',
            [':k' => $key, ':w' => $now]
        );
        $row = DB::one('SELECT counter FROM rate_limits WHERE bucket_key = :k', [':k' => $key]);
        if ($row && (int)$row['counter'] > $limit) {
            Response::error('rate_limited', 429, ['retry_after' => 60]);
        }
    }
}
