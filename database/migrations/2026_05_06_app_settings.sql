-- =============================================================================
-- 2026-05-06 — Generic key/value app settings
-- =============================================================================
-- A single tiny table for tunable runtime values that admins should be able
-- to edit without redeploying. First user: the شمعة AI system prompt.
-- =============================================================================

CREATE TABLE IF NOT EXISTS app_settings (
    setting_key   VARCHAR(100) NOT NULL,
    setting_value MEDIUMTEXT NOT NULL,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
