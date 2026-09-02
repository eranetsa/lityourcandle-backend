-- RAG index for AI references: extracted_text is split into ~1.4k-char
-- chunks, normalized for Arabic full-text search. The chat prompt then
-- injects only the chunks relevant to the user's message instead of the
-- whole corpus (which outgrew the flat injection cap).

CREATE TABLE IF NOT EXISTS ai_reference_chunks (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    reference_id BIGINT UNSIGNED NOT NULL,
    chunk_index  INT UNSIGNED    NOT NULL,
    content      TEXT            NOT NULL,
    content_norm TEXT            NOT NULL,
    char_len     INT UNSIGNED    NOT NULL,
    created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ref (reference_id),
    FULLTEXT KEY ft_norm (content_norm)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
