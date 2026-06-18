<?php
declare(strict_types=1);

/**
 * Back-fill every users row from RevenueCat's REST snapshot.
 *
 * Usage:
 *   php bin/sync_rc_subscribers.php                   # full sweep, page size 50
 *   php bin/sync_rc_subscribers.php --since=12345     # resume after users.id=12345
 *   php bin/sync_rc_subscribers.php --limit=100       # custom page size (max 500)
 *   php bin/sync_rc_subscribers.php --max-pages=3     # stop after N pages (smoke test)
 *   php bin/sync_rc_subscribers.php --max-runtime=30m # stop after N seconds/minutes
 *   php bin/sync_rc_subscribers.php --include-all     # don't filter is_active/role
 *
 * Reads REVENUECAT_SECRET_KEY from .env. Safe to re-run — every write
 * path is idempotent (see RevenueCatBackfill class docblock).
 *
 * A flock guard on /tmp/lityc_rc_sync.lock prevents two simultaneous
 * sweeps from blowing past RC's 20 req/s rate limit.
 *
 * Progress goes to STDERR (so STDOUT stays free for a future
 * machine-parseable summary if needed). Exits 0 on completion, 1 on
 * fatal config error, 2 if any users surfaced per-row errors, 3 if
 * another sweep is already running.
 */

// SECURITY: with zend.exception_ignore_args=0 (PHP default), a stack
// trace dump includes ARGUMENT frames — and one of our argument frames is
// the Guzzle options array containing the bearer token. Force args off so
// the trace is safe to STDERR even if cron pipes both streams into a
// shared logfile.
ini_set('zend.exception_ignore_args', '1');

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\App;
use App\Services\RevenueCatBackfill;

App::boot(dirname(__DIR__));

$argv ??= $_SERVER['argv'] ?? [];

$since      = null;
$limit      = 50;
$maxPages   = null;
$maxRuntime = null; // seconds
$onlyRegular = true;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--since=(\d+)$/', $arg, $m))      { $since    = (int)$m[1]; continue; }
    if (preg_match('/^--limit=(\d+)$/', $arg, $m))      { $limit    = max(1, min(500, (int)$m[1])); continue; }
    if (preg_match('/^--max-pages=(\d+)$/', $arg, $m))  { $maxPages = (int)$m[1]; continue; }
    if (preg_match('/^--max-runtime=(\d+)([smh]?)$/', $arg, $m)) {
        $mult = ['' => 1, 's' => 1, 'm' => 60, 'h' => 3600][$m[2]];
        $maxRuntime = (int)$m[1] * $mult;
        continue;
    }
    if ($arg === '--include-all') { $onlyRegular = false; continue; }
    fwrite(STDERR, "unknown arg: {$arg}\n");
    fwrite(STDERR, "usage: php bin/sync_rc_subscribers.php [--since=N] [--limit=N] "
                 . "[--max-pages=N] [--max-runtime=N[smh]] [--include-all]\n");
    exit(1);
}

// ---- Mutex: prevent concurrent sweeps from bursting RC's rate cap. ----
$lockPath = sys_get_temp_dir() . '/lityc_rc_sync.lock';
$lockFp   = fopen($lockPath, 'c');
if ($lockFp === false || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "another rc-sync is already running (lock: {$lockPath})\n");
    exit(3);
}
register_shutdown_function(static function () use ($lockFp): void {
    @flock($lockFp, LOCK_UN);
    @fclose($lockFp);
});

try {
    $backfill = new RevenueCatBackfill();
} catch (\Throwable $e) {
    fwrite(STDERR, "FATAL: " . $e->getMessage() . "\n");
    exit(1);
}

$totals = [
    'users_checked'         => 0,
    'users_with_active_sub' => 0,
    'users_updated'         => 0,
    'non_sub_credits'       => 0,
    'errors'                => 0,
];

fwrite(STDERR, sprintf(
    "-> starting RC back-fill (since=%s, limit=%d, max_pages=%s, max_runtime=%ss, regular_only=%s)\n",
    $since === null ? '<start>' : (string)$since,
    $limit,
    $maxPages === null ? 'inf' : (string)$maxPages,
    $maxRuntime === null ? 'inf' : (string)$maxRuntime,
    $onlyRegular ? 'yes' : 'no'
));

$page  = 0;
$start = microtime(true);
try {
    while (true) {
        $page++;
        // Print the cursor BEFORE running the page, so a crashed run leaves
        // a recoverable footprint in the logs.
        fwrite(STDERR, sprintf(
            "  page %d: requesting users with id > %s ...\n",
            $page,
            $since === null ? '0' : (string)$since
        ));
        $res = $backfill->syncAll($since, $limit, $onlyRegular);

        foreach (['users_checked', 'users_with_active_sub', 'users_updated', 'non_sub_credits', 'errors'] as $k) {
            $totals[$k] += $res[$k];
        }

        fwrite(STDERR, sprintf(
            "  page %d: checked=%d active=%d updated=%d credits=%d errors=%d (last_id=%s, elapsed=%.1fs)\n",
            $page,
            $res['users_checked'],
            $res['users_with_active_sub'],
            $res['users_updated'],
            $res['non_sub_credits'],
            $res['errors'],
            $res['last_user_id'] === null ? 'none' : (string)$res['last_user_id'],
            microtime(true) - $start
        ));

        if ($res['done'] || $res['users_checked'] === 0) {
            break;
        }
        if ($maxPages !== null && $page >= $maxPages) {
            fwrite(STDERR, "-> stopped after --max-pages={$maxPages}\n");
            break;
        }
        $elapsed = microtime(true) - $start;
        if ($maxRuntime !== null && $elapsed >= $maxRuntime) {
            fwrite(STDERR, sprintf(
                "-> stopped after --max-runtime=%ds (resume with --since=%s)\n",
                $maxRuntime,
                $res['last_user_id'] === null ? '0' : (string)$res['last_user_id']
            ));
            break;
        }
        $since = $res['last_user_id'];
    }
} catch (\Throwable $e) {
    // Do NOT print getTraceAsString — even with ignore_args=1 the file
    // paths leak structural info, and the message+location is plenty.
    fwrite(STDERR, "FATAL: " . $e->getMessage()
        . " at " . $e->getFile() . ":" . $e->getLine() . "\n");
    exit(1);
}

fwrite(STDERR, "-> done.\n");
fwrite(STDERR, sprintf(
    "   totals: checked=%d active=%d updated=%d credits=%d errors=%d (elapsed=%.1fs)\n",
    $totals['users_checked'],
    $totals['users_with_active_sub'],
    $totals['users_updated'],
    $totals['non_sub_credits'],
    $totals['errors'],
    microtime(true) - $start
));

exit($totals['errors'] > 0 ? 2 : 0);
