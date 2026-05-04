<?php
declare(strict_types=1);

/**
 * Idempotent migration runner.
 *
 *   php bin/migrate.php         — apply any unapplied SQL files in
 *                                  database/migrations/, in name order.
 *   php bin/migrate.php --dry   — list pending without applying.
 *
 * Each migration filename (basename) is recorded in `schema_migrations`
 * after a successful run, so re-running is a no-op. Failures abort the
 * batch and leave subsequent migrations pending.
 *
 * Wired into the auto-deploy: see deploy/lityc-deploy.sh.
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\App;
use App\Core\DB;

App::boot(dirname(__DIR__));

$dryRun = in_array('--dry', $argv ?? [], true);

DB::run("CREATE TABLE IF NOT EXISTS schema_migrations (
    name        VARCHAR(190) NOT NULL,
    applied_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$dir = dirname(__DIR__) . '/database/migrations';
$files = glob($dir . '/*.sql') ?: [];
sort($files);

$applied = array_column(DB::all('SELECT name FROM schema_migrations'), 'name');
$applied = array_flip($applied);

// First-run baseline: if schema_migrations is empty but the database is
// clearly already provisioned (the `users` table exists from schema.sql),
// the older migrations were applied manually before this runner existed.
// Re-running them would fail (e.g. duplicate ADD COLUMN). Mark every
// migration *older than today's call_invites change* as already applied
// so we only execute new files going forward.
if (empty($applied)) {
    $usersExists = DB::one("SELECT 1 AS x FROM information_schema.tables
                             WHERE table_schema = DATABASE() AND table_name = 'users'");
    $callInvitesExists = DB::one("SELECT 1 AS x FROM information_schema.tables
                                   WHERE table_schema = DATABASE() AND table_name = 'call_invites'");
    if ($usersExists && !$callInvitesExists) {
        $baseline = array_filter(
            $files,
            fn(string $f) => !str_contains(basename($f), 'call_invites')
        );
        foreach ($baseline as $f) {
            $name = basename($f);
            DB::run('INSERT IGNORE INTO schema_migrations (name) VALUES (:n)', [':n' => $name]);
            $applied[$name] = true;
            echo "✓ baseline (assumed applied): $name" . PHP_EOL;
        }
    }
}

$pending = [];
foreach ($files as $f) {
    $name = basename($f);
    if (!isset($applied[$name])) {
        $pending[] = $f;
    }
}

if (empty($pending)) {
    echo "Nothing to apply. (" . count($files) . " migrations in repo, all applied.)" . PHP_EOL;
    exit(0);
}

echo "Pending: " . count($pending) . PHP_EOL;
foreach ($pending as $f) {
    echo "  • " . basename($f) . PHP_EOL;
}
if ($dryRun) {
    exit(0);
}

$pdo = DB::pdo();
foreach ($pending as $f) {
    $name = basename($f);
    echo "→ applying $name" . PHP_EOL;
    $sql = file_get_contents($f);
    if ($sql === false) {
        fwrite(STDERR, "  read failed\n");
        exit(1);
    }
    try {
        // Split on semicolons that end a statement. This is good enough
        // for our hand-written migrations (no PROCEDUREs, triggers, etc).
        // MySQL accepts `-- ...` line comments inline so we don't need to
        // strip them; we just need each statement to actually contain DDL.
        $stmts = array_filter(array_map('trim', preg_split('/;\s*\n/', $sql)));
        foreach ($stmts as $stmt) {
            if ($stmt === '') continue;
            // Skip chunks that are *only* line comments / blank lines.
            $lines = preg_split('/\r?\n/', $stmt) ?: [];
            $hasCode = false;
            foreach ($lines as $ln) {
                $t = trim($ln);
                if ($t === '' || str_starts_with($t, '--')) continue;
                $hasCode = true; break;
            }
            if (!$hasCode) continue;
            $pdo->exec($stmt);
        }
        DB::run('INSERT INTO schema_migrations (name) VALUES (:n)', [':n' => $name]);
        echo "  ok" . PHP_EOL;
    } catch (\Throwable $e) {
        fwrite(STDERR, "  FAILED: " . $e->getMessage() . PHP_EOL);
        exit(1);
    }
}

echo "Done." . PHP_EOL;
