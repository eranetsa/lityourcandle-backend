<?php
declare(strict_types=1);

/**
 * Rebuild the RAG chunk index for every AI reference.
 *
 *   php bin/reindex_ai_refs.php
 *
 * Idempotent: each reference's chunks are deleted and regenerated from
 * its stored extracted_text. Run after the ai_reference_chunks migration
 * (backfill) or after bulk-editing reference rows directly in the DB.
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\App;
use App\Services\AiReferenceIndex;

App::boot(dirname(__DIR__));

$counts = AiReferenceIndex::reindexAll();
foreach ($counts as $refId => $n) {
    echo "reference #$refId: $n chunks\n";
}
echo 'total: ' . array_sum($counts) . " chunks across " . count($counts) . " references\n";
