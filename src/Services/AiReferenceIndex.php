<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

/**
 * Chunking + retrieval for the AI reference library (RAG).
 *
 * The admin-uploaded references grew to ~1M chars — far beyond what can be
 * injected into every chat call. Instead, each reference's extracted_text
 * is split into ~1.4k-char chunks stored in ai_reference_chunks with an
 * Arabic-normalized copy under a FULLTEXT index. At chat time only the
 * chunks matching the user's message are injected (see CandleAiService).
 */
final class AiReferenceIndex
{
    private const CHUNK_TARGET = 1400;  // soft max chars per chunk
    private const CHUNK_MIN    = 500;   // don't emit fragments smaller than this unless forced

    /**
     * Normalize Arabic text for search: strip diacritics/tatweel, unify
     * alef/yaa/taa-marbuta variants, collapse punctuation to spaces.
     */
    public static function normalize(string $t): string
    {
        $t = mb_strtolower($t, 'UTF-8');
        $t = preg_replace('/[\x{064B}-\x{0652}\x{0670}\x{0640}]/u', '', $t) ?? $t; // tashkeel + tatweel
        $t = preg_replace('/[أإآٱ]/u', 'ا', $t) ?? $t;
        $t = preg_replace('/[ىئ]/u', 'ي', $t) ?? $t;
        $t = preg_replace('/ؤ/u', 'و', $t) ?? $t;
        $t = preg_replace('/ة/u', 'ه', $t) ?? $t;
        $t = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $t) ?? $t;
        $t = preg_replace('/\s+/u', ' ', $t) ?? $t;
        return trim($t);
    }

    /** Split text into chunks along paragraph/sentence boundaries. */
    public static function chunkText(string $text): array
    {
        $paras = preg_split('/\n{2,}/u', $text) ?: [$text];
        $chunks = [];
        $buf = '';
        foreach ($paras as $p) {
            $p = trim($p);
            if ($p === '') continue;

            // Hard-split paragraphs that alone exceed the target.
            while (mb_strlen($p) > self::CHUNK_TARGET) {
                $cut = self::CHUNK_TARGET;
                // prefer a sentence boundary in the back half of the window
                foreach (['۔', '.', '؟', '!', "\n"] as $sep) {
                    $idx = mb_strrpos(mb_substr($p, 0, self::CHUNK_TARGET), $sep);
                    if ($idx !== false && $idx > self::CHUNK_TARGET / 2) { $cut = $idx + 1; break; }
                }
                $head = trim(mb_substr($p, 0, $cut));
                if ($buf !== '') { $chunks[] = $buf; $buf = ''; }
                if ($head !== '') $chunks[] = $head;
                $p = trim(mb_substr($p, $cut));
            }

            if ($buf !== '' && mb_strlen($buf) + mb_strlen($p) + 2 > self::CHUNK_TARGET) {
                $chunks[] = $buf;
                $buf = $p;
            } else {
                $buf = $buf === '' ? $p : $buf . "\n\n" . $p;
            }
        }
        if (trim($buf) !== '') {
            // Merge a tiny tail into the previous chunk instead of emitting a fragment.
            if ($chunks && mb_strlen($buf) < self::CHUNK_MIN) {
                $chunks[count($chunks) - 1] .= "\n\n" . $buf;
            } else {
                $chunks[] = $buf;
            }
        }
        return $chunks;
    }

    /** (Re)build the chunk index for one reference. */
    public static function indexReference(int $refId): int
    {
        $row = DB::one('SELECT extracted_text FROM ai_references WHERE id = :id', [':id' => $refId]);
        DB::run('DELETE FROM ai_reference_chunks WHERE reference_id = :id', [':id' => $refId]);
        if (!$row) return 0;
        $chunks = self::chunkText((string)$row['extracted_text']);
        $i = 0;
        foreach ($chunks as $c) {
            DB::insert('ai_reference_chunks', [
                'reference_id' => $refId,
                'chunk_index'  => $i++,
                'content'      => $c,
                'content_norm' => self::normalize($c),
                'char_len'     => mb_strlen($c),
            ]);
        }
        return $i;
    }

    /** Rebuild every reference's chunks (backfill / bulk maintenance). */
    public static function reindexAll(): array
    {
        $out = [];
        foreach (DB::all('SELECT id FROM ai_references') as $r) {
            $out[(int)$r['id']] = self::indexReference((int)$r['id']);
        }
        return $out;
    }

    public static function hasChunks(): bool
    {
        try {
            $row = DB::one('SELECT id FROM ai_reference_chunks LIMIT 1');
            return (bool)$row;
        } catch (\Throwable $e) {
            return false; // table not migrated yet — caller falls back to flat injection
        }
    }

    /**
     * Top chunks for a user message, joined with their source title.
     * Returns [['title' => ..., 'content' => ...], ...] within $budget chars.
     */
    public static function retrieve(string $query, int $limit = 12, int $budget = 18000): array
    {
        $q = self::normalize($query);
        if ($q === '') return [];
        try {
            $rows = DB::all(
                'SELECT c.content, r.original_name,
                        MATCH(c.content_norm) AGAINST (:q1 IN NATURAL LANGUAGE MODE) AS score
                   FROM ai_reference_chunks c
                   JOIN ai_references r ON r.id = c.reference_id AND r.is_active = 1
                  WHERE MATCH(c.content_norm) AGAINST (:q2 IN NATURAL LANGUAGE MODE)
                  ORDER BY score DESC
                  LIMIT ' . (int)$limit,
                [':q1' => $q, ':q2' => $q]
            );
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        $used = 0;
        foreach ($rows as $r) {
            $len = mb_strlen((string)$r['content']);
            if ($used + $len > $budget && $out) break;
            $out[] = ['title' => (string)$r['original_name'], 'content' => (string)$r['content']];
            $used += $len;
        }
        return $out;
    }
}
