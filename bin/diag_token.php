<?php
declare(strict_types=1);

/**
 * Print the most recent push token row, masked. Used by diag_apns.sh.
 *   php bin/diag_token.php
 */
require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\App;
use App\Core\DB;

App::boot(dirname(__DIR__));

$rows = DB::all(
    'SELECT pt.id, pt.user_id, pt.platform, pt.token, pt.last_seen_at, u.name
       FROM push_tokens pt
       JOIN users u ON u.id = pt.user_id
      ORDER BY pt.id DESC LIMIT 5'
);

if (empty($rows)) {
    // Fallback: legacy users.push_token column
    $rows = DB::all(
        "SELECT id AS id, id AS user_id, push_platform AS platform, push_token AS token,
                NULL AS last_seen_at, name
           FROM users
          WHERE push_token IS NOT NULL AND push_platform IS NOT NULL
          ORDER BY id DESC LIMIT 5"
    );
    if ($rows) echo "(falling back to legacy users.push_token column)\n";
}

if (empty($rows)) {
    echo "❌  No push tokens registered yet — open the app on a real device and grant notifications first.\n";
    exit(0);
}

printf("%-4s %-7s %-9s %-19s %s\n", 'id', 'uid', 'platform', 'last_seen', 'token');
foreach ($rows as $r) {
    $tok = (string)$r['token'];
    $masked = strlen($tok) > 12
        ? substr($tok, 0, 4) . '…' . substr($tok, -8)
        : $tok;
    printf(
        "%-4s %-7s %-9s %-19s %s   (len=%d)\n",
        $r['id'],
        $r['user_id'],
        $r['platform'],
        $r['last_seen_at'] ?? '-',
        $masked,
        strlen($tok)
    );
}
