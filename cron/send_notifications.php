<?php
declare(strict_types=1);

/**
 * Run every minute: dispatch queued notifications.
 *   * * * * *  /usr/bin/php /var/www/lityourcandle/cron/send_notifications.php
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\App;
use App\Core\DB;
use App\Core\Logger;
use App\Services\PushService;

App::boot(dirname(__DIR__));

$rows = DB::all(
    "SELECT id FROM notifications
     WHERE status = 'queued'
       AND (scheduled_at IS NULL OR scheduled_at <= NOW())
     ORDER BY id ASC LIMIT 200"
);

$svc = new PushService();
$ok = 0; $fail = 0;
foreach ($rows as $r) {
    if ($svc->send((int)$r['id'])) $ok++; else $fail++;
}
Logger::info('cron_notifications_done', ['ok' => $ok, 'fail' => $fail]);
echo "Sent: $ok, Failed: $fail" . PHP_EOL;
