<?php
declare(strict_types=1);

/**
 * Import a .txt/.md file as an AI reference from the CLI (same effect as
 * the admin-panel upload, including RAG chunk indexing).
 *
 *   php bin/import_ai_reference.php /path/to/file.md
 *
 * Skips (exit 2) if a reference with the same original_name already
 * exists, so bulk imports are safe to re-run.
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\App;
use App\Core\DB;
use App\Services\AiReferenceIndex;

App::boot(dirname(__DIR__));

$path = $argv[1] ?? '';
if ($path === '' || !is_file($path)) {
    fwrite(STDERR, "usage: php bin/import_ai_reference.php <file.txt|file.md>\n");
    exit(1);
}
$name = basename($path);
$ext  = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
if (!in_array($ext, ['txt', 'md'], true)) {
    fwrite(STDERR, "only .txt / .md are supported\n");
    exit(1);
}
$text = (string)file_get_contents($path);
if ($text === '') {
    fwrite(STDERR, "file is empty or unreadable\n");
    exit(1);
}
if (DB::one('SELECT id FROM ai_references WHERE original_name = :n', [':n' => $name])) {
    echo "skip (already imported): $name\n";
    exit(2);
}

$dir = dirname(__DIR__) . '/storage/ai-references';
if (!is_dir($dir)) mkdir($dir, 0750, true);
$stored = bin2hex(random_bytes(8)) . '.' . $ext;
file_put_contents($dir . '/' . $stored, $text);
@chmod($dir . '/' . $stored, 0640);

$refId = DB::insert('ai_references', [
    'original_name'  => $name,
    'storage_path'   => 'storage/ai-references/' . $stored,
    'mime'           => $ext === 'md' ? 'text/markdown' : 'text/plain',
    'size_bytes'     => strlen($text),
    'extracted_text' => $text,
    'is_active'      => 1,
    'sort_order'     => 0,
]);
$chunks = AiReferenceIndex::indexReference((int)$refId);
echo "imported #$refId: $name (" . mb_strlen($text) . " chars, $chunks chunks)\n";
