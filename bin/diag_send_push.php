<?php
declare(strict_types=1);

/**
 * Send a real test push through the live PushService stack and report the
 * outcome (HTTP / APNs reason code). Usage:
 *   sudo php bin/diag_send_push.php <user_id>      # uses latest token for that user
 *   sudo php bin/diag_send_push.php token <hex>    # raw token override
 *
 * The notification is inserted with kind=incoming_call so PushService applies
 * the ringtone / time-sensitive headers we use for real call rings.
 */
require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\App;
use App\Core\DB;
use App\Services\PushService;

App::boot(dirname(__DIR__));

$argv ??= $_SERVER['argv'] ?? [];

if (!isset($argv[1])) {
    fwrite(STDERR, "usage: php bin/diag_send_push.php <user_id>\n");
    exit(1);
}

$userId = null;
if ($argv[1] === 'token' && isset($argv[2])) {
    // Bind the supplied token to a fake notification owner so PushService
    // can pick it up. We hijack the most recent user as the recipient — the
    // user record is not modified, only `notifications.user_id` references it.
    $userId = (int)(DB::one('SELECT id FROM users ORDER BY id DESC LIMIT 1')['id'] ?? 0);
    DB::run(
        'INSERT INTO push_tokens (user_id, token, platform, last_seen_at)
         VALUES (:uid, :tok, :plat, NOW())
         ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), platform = VALUES(platform), last_seen_at = NOW()',
        [':uid' => $userId, ':tok' => $argv[2], ':plat' => 'ios']
    );
    fwrite(STDERR, "→ bound override token to user_id=$userId (push_tokens upserted)\n");
} else {
    $userId = (int)$argv[1];
}

if ($userId <= 0) {
    fwrite(STDERR, "bad user_id\n"); exit(1);
}

$notifId = DB::insert('notifications', [
    'user_id'      => $userId,
    'kind'         => 'incoming_call',
    'title'        => '🔔 APNs diagnostic',
    'body'         => 'If you can read this, the APNs path is healthy.',
    'payload_json' => json_encode([
        'type'       => 'incoming_call',
        'invite_id'  => 0,
        'session_id' => 0,
        'media'      => 'voice',
        'diagnostic' => true,
    ], JSON_UNESCAPED_UNICODE),
    'status'       => 'queued',
]);

fwrite(STDERR, "→ inserted notification id=$notifId for user_id=$userId\n");
fwrite(STDERR, "→ calling PushService::send() — watch for apns_send_failed in /var/log\n");
$ok = (new PushService())->send($notifId);
fwrite(STDERR, $ok ? "✅ PushService reported success\n" : "❌ PushService reported failure (check log)\n");

$row = DB::one('SELECT status, sent_at FROM notifications WHERE id = :id', [':id' => $notifId]);
fwrite(STDERR, sprintf("→ notification status=%s sent_at=%s\n", $row['status'] ?? '?', $row['sent_at'] ?? '?'));
exit($ok ? 0 : 2);
