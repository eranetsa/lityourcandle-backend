-- =============================================================================
-- 2026-06-18 — RevenueCat webhook event log
-- =============================================================================
-- Receives a row per inbound RC webhook event. event_id is RC's id and is
-- UNIQUE, which gives us cheap idempotency: duplicate POSTs collide on insert
-- and the controller short-circuits to 200 OK if the prior delivery already
-- finished. If the prior delivery never marked processed_at, the controller
-- re-runs the handler.
--
-- resolved_user_id stays NULL when we can't yet map app_user_id → users.id
-- (e.g. an INITIAL_PURCHASE arrived under "$RCAnonymousID:..." before
-- Purchases.logIn() ran). A later SUBSCRIBER_ALIAS / TRANSFER event triggers
-- replayPendingFor(), which backfills these rows.
--
-- payload_json is the full event for audit/forensics. MEDIUMTEXT (~16 MB) is
-- more than enough for any RC payload, and matches subscriptions.store_latest_receipt.
--
-- event_id is VARCHAR(64) rather than CHAR(36) so future RC id-format changes
-- (or non-UUID test ids) don't silently truncate and collide on UNIQUE.
-- =============================================================================

CREATE TABLE IF NOT EXISTS rc_webhook_events (
    id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id              VARCHAR(64)     NOT NULL,
    event_type            VARCHAR(40)     NOT NULL,
    app_user_id           VARCHAR(255)    DEFAULT NULL,
    original_app_user_id  VARCHAR(255)    DEFAULT NULL,
    product_id            VARCHAR(190)    DEFAULT NULL,
    environment           VARCHAR(16)     NOT NULL DEFAULT 'PRODUCTION',
    payload_json          MEDIUMTEXT      NOT NULL,
    resolved_user_id      BIGINT UNSIGNED DEFAULT NULL,
    processed_at          DATETIME        DEFAULT NULL,
    created_at            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_rc_event_id (event_id),
    KEY idx_rc_app_user (app_user_id),
    KEY idx_rc_original_app_user (original_app_user_id),
    KEY idx_rc_pending (processed_at, resolved_user_id, created_at),
    KEY idx_rc_type_created (event_type, created_at),
    CONSTRAINT fk_rc_resolved_user
        FOREIGN KEY (resolved_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
