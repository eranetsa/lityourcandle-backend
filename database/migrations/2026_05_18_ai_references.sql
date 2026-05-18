-- Reference documents that the شمعة AI uses as background knowledge.
-- Uploaded via the admin panel; their `extracted_text` is concatenated
-- into the system prompt at chat time so the model can ground its
-- answers in our own material instead of (or in addition to) its
-- baseline training.
CREATE TABLE IF NOT EXISTS ai_references (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  original_name   VARCHAR(255)    NOT NULL,
  storage_path    VARCHAR(500)    NOT NULL,
  mime            VARCHAR(120)    NOT NULL,
  size_bytes      INT UNSIGNED    NOT NULL,
  extracted_text  LONGTEXT        NOT NULL,
  is_active       TINYINT(1)      NOT NULL DEFAULT 1,
  sort_order      INT             NOT NULL DEFAULT 0,
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_active_sort (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
