-- =============================================================================
-- 2026-05-05 — Calling protocol
-- =============================================================================
-- Adds the dedicated invite/accept/decline/cancel/missed pipeline so the
-- ringing event is decoupled from session state. Ringing is now driven only
-- by `call_invites.status = 'ringing'` and never piggy-backed on session.start
-- or session.type changes.
--
-- Tables:
--   call_invites — one row per ring attempt (consultant → client)
--   push_tokens  — multi-device push targets (one row per user×device)
--   ws_outbox    — async fan-out queue read by the websocket daemon so HTTP
--                  request handlers can push real-time frames without holding
--                  a socket open themselves.
--
-- Also extends `notifications.kind` so the notifications table can record
-- incoming-call and missed-call events.
-- =============================================================================

CREATE TABLE IF NOT EXISTS call_invites (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_id      BIGINT UNSIGNED NOT NULL,
    from_user_id    BIGINT UNSIGNED NOT NULL,
    to_user_id      BIGINT UNSIGNED NOT NULL,
    media           ENUM('voice','video') NOT NULL,
    status          ENUM('ringing','accepted','declined','missed','cancelled') NOT NULL DEFAULT 'ringing',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    responded_at    DATETIME DEFAULT NULL,
    expires_at      DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_invite_to_status   (to_user_id, status),
    KEY idx_invite_session     (session_id),
    KEY idx_invite_expires     (status, expires_at),
    CONSTRAINT fk_invite_session FOREIGN KEY (session_id)   REFERENCES sessions(id) ON DELETE CASCADE,
    CONSTRAINT fk_invite_from    FOREIGN KEY (from_user_id) REFERENCES users(id)    ON DELETE CASCADE,
    CONSTRAINT fk_invite_to      FOREIGN KEY (to_user_id)   REFERENCES users(id)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- push_tokens — replaces the single push_token column on `users` so each user
-- can have multiple devices ringing in parallel. We keep the old column on
-- `users` populated for backward compatibility until clients migrate.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS push_tokens (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         BIGINT UNSIGNED NOT NULL,
    token           VARCHAR(255) NOT NULL,
    platform        ENUM('ios','android','web') NOT NULL,
    voip_token      VARCHAR(255) DEFAULT NULL,
    device_id       VARCHAR(64)  DEFAULT NULL,
    last_seen_at    DATETIME     DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_push_token (token),
    KEY idx_push_user (user_id),
    KEY idx_push_lastseen (last_seen_at),
    CONSTRAINT fk_push_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- ws_outbox — async fan-out queue. The HTTP API inserts a row; the websocket
-- daemon polls every ~500ms, dispatches to matching connections, marks
-- processed_at, and rows older than 1h are pruned by the same poller.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ws_outbox (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    target_type     ENUM('user','session') NOT NULL,
    target_id       BIGINT UNSIGNED NOT NULL,
    frame_json      JSON NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at    DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_ws_outbox_pending (processed_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- notifications.kind: add incoming_call & missed_call. Existing values are
-- preserved verbatim — MySQL accepts this ALTER as a metadata-only change.
-- -----------------------------------------------------------------------------
ALTER TABLE notifications
    MODIFY kind ENUM(
        'mood_checkin',
        'session_reminder',
        'reengagement',
        'trial_ending',
        'upgrade',
        'generic',
        'incoming_call',
        'missed_call'
    ) NOT NULL;
