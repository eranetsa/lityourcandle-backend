-- Lit Your Candle - أشعل شمعتك
-- MySQL/MariaDB schema

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------------------------
-- users
-- ----------------------------------------------------------------------------
CREATE TABLE users (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name            VARCHAR(120) NOT NULL,
    email           VARCHAR(190) NOT NULL,
    phone           VARCHAR(32) DEFAULT NULL,
    password_hash   VARCHAR(255) NOT NULL,
    language        VARCHAR(8) NOT NULL DEFAULT 'ar',
    role            ENUM('user','consultant','admin','guest') NOT NULL DEFAULT 'user',
    avatar_url      VARCHAR(255) DEFAULT NULL,
    push_token      VARCHAR(255) DEFAULT NULL,
    push_platform   ENUM('ios','android','web') DEFAULT NULL,
    device_id       VARCHAR(64) DEFAULT NULL,
    platform        VARCHAR(20) DEFAULT NULL,
    trial_started_at DATETIME DEFAULT NULL,
    trial_ends_at   DATETIME DEFAULT NULL,
    consultant_id   BIGINT UNSIGNED DEFAULT NULL,
    last_login_at   DATETIME DEFAULT NULL,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_users_email (email),
    UNIQUE KEY uniq_users_device_id (device_id),
    KEY idx_users_role (role),
    KEY idx_users_consultant (consultant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- admin_users  (separate credentials for admin panel)
-- ----------------------------------------------------------------------------
CREATE TABLE admin_users (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username        VARCHAR(64) NOT NULL,
    email           VARCHAR(190) NOT NULL,
    password_hash   VARCHAR(255) NOT NULL,
    full_name       VARCHAR(120) NOT NULL,
    role            ENUM('admin','support','content') NOT NULL DEFAULT 'admin',
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at   DATETIME DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_admin_username (username),
    UNIQUE KEY uniq_admin_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- consultants
-- ----------------------------------------------------------------------------
CREATE TABLE consultants (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         BIGINT UNSIGNED DEFAULT NULL,
    name            VARCHAR(120) NOT NULL,
    photo_url       VARCHAR(255) DEFAULT NULL,
    specialty       VARCHAR(120) NOT NULL,
    bio             TEXT,
    rating          DECIMAL(3,2) NOT NULL DEFAULT 0.00,
    rating_count    INT UNSIGNED NOT NULL DEFAULT 0,
    price_per_session DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    currency        VARCHAR(8) NOT NULL DEFAULT 'SAR',
    session_types   SET('chat','voice','video') NOT NULL DEFAULT 'chat,voice,video',
    is_available    TINYINT(1) NOT NULL DEFAULT 1,
    languages       VARCHAR(64) NOT NULL DEFAULT 'ar',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_consultants_specialty (specialty),
    KEY idx_consultants_rating (rating),
    KEY idx_consultants_available (is_available),
    CONSTRAINT fk_consultants_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE consultant_availability (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    consultant_id   BIGINT UNSIGNED NOT NULL,
    weekday         TINYINT UNSIGNED NOT NULL, -- 0=Sun .. 6=Sat
    start_time      TIME NOT NULL,
    end_time        TIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_avail_consultant (consultant_id, weekday),
    CONSTRAINT fk_avail_consultant FOREIGN KEY (consultant_id) REFERENCES consultants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- subscriptions
-- ----------------------------------------------------------------------------
CREATE TABLE subscriptions (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         BIGINT UNSIGNED NOT NULL,
    plan            ENUM('free','weekly','monthly','yearly') NOT NULL DEFAULT 'free',
    status          ENUM('active','trial','expired','canceled','grace') NOT NULL DEFAULT 'active',
    store           ENUM('apple','google','manual','none') NOT NULL DEFAULT 'none',
    store_product_id VARCHAR(120) DEFAULT NULL,
    store_original_tx VARCHAR(255) DEFAULT NULL,
    store_latest_receipt MEDIUMTEXT,
    started_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at      DATETIME DEFAULT NULL,
    trial_ends_at   DATETIME DEFAULT NULL,
    auto_renew      TINYINT(1) NOT NULL DEFAULT 1,
    sessions_total      INT UNSIGNED NOT NULL DEFAULT 0,
    sessions_remaining  INT UNSIGNED NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_synced_at      DATETIME DEFAULT NULL,
    rc_backfill_receipt MEDIUMTEXT DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_sub_user (user_id, status),
    KEY idx_sub_expires (expires_at),
    KEY idx_sub_original_tx (store_original_tx),
    CONSTRAINT fk_sub_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- transactions  (one-off purchases like extra session)
-- ----------------------------------------------------------------------------
CREATE TABLE transactions (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         BIGINT UNSIGNED NOT NULL,
    kind            ENUM('subscription','extra_session','refund') NOT NULL,
    amount          DECIMAL(10,2) NOT NULL,
    currency        VARCHAR(8) NOT NULL DEFAULT 'SAR',
    store           ENUM('apple','google','manual') NOT NULL,
    store_tx_id     VARCHAR(255) DEFAULT NULL,
    store_alt_tx_id VARCHAR(255) DEFAULT NULL,
    status          ENUM('pending','succeeded','failed','refunded') NOT NULL DEFAULT 'pending',
    metadata_json   JSON DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tx_user (user_id),
    KEY idx_tx_store_tx (store_tx_id),
    KEY idx_tx_store_alt_tx (store_alt_tx_id),
    CONSTRAINT fk_tx_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- sessions  (booked consultation between user and consultant)
-- ----------------------------------------------------------------------------
CREATE TABLE sessions (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         BIGINT UNSIGNED NOT NULL,
    consultant_id   BIGINT UNSIGNED NOT NULL,
    type            ENUM('chat','voice','video') NOT NULL,
    mode            ENUM('instant','scheduled') NOT NULL DEFAULT 'scheduled',
    status          ENUM('pending','confirmed','in_progress','completed','canceled','no_show') NOT NULL DEFAULT 'pending',
    scheduled_at    DATETIME DEFAULT NULL,
    started_at      DATETIME DEFAULT NULL,
    ended_at        DATETIME DEFAULT NULL,
    duration_min    INT UNSIGNED DEFAULT NULL,
    pre_mood        ENUM('happy','calm','neutral','anxious','sad') DEFAULT NULL,
    pre_issue       TEXT,
    pre_ai_summary  TEXT,
    post_rating     TINYINT UNSIGNED DEFAULT NULL,
    post_notes_enc  MEDIUMBLOB DEFAULT NULL,  -- AES-256-GCM encrypted
    follow_up       TEXT,
    paid_with       ENUM('credit','extra','free') NOT NULL DEFAULT 'credit',
    transaction_id  BIGINT UNSIGNED DEFAULT NULL,
    room_token      VARCHAR(120) DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_sess_user (user_id, status),
    KEY idx_sess_consultant (consultant_id, status),
    KEY idx_sess_scheduled (scheduled_at),
    CONSTRAINT fk_sess_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_sess_consultant FOREIGN KEY (consultant_id) REFERENCES consultants(id) ON DELETE RESTRICT,
    CONSTRAINT fk_sess_tx FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- messages  (chat inside a session)
-- ----------------------------------------------------------------------------
CREATE TABLE messages (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_id      BIGINT UNSIGNED NOT NULL,
    sender_id       BIGINT UNSIGNED NOT NULL,
    sender_role     ENUM('user','consultant','system') NOT NULL,
    body            TEXT NOT NULL,
    attachment_url  VARCHAR(255) DEFAULT NULL,
    read_at         DATETIME DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_msg_session (session_id, created_at),
    CONSTRAINT fk_msg_session FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE,
    CONSTRAINT fk_msg_sender FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- mood_logs
-- ----------------------------------------------------------------------------
CREATE TABLE mood_logs (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         BIGINT UNSIGNED NOT NULL,
    mood            ENUM('happy','calm','neutral','anxious','sad') NOT NULL,
    note            VARCHAR(500) DEFAULT NULL,
    logged_on       DATE NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_mood_user_day (user_id, logged_on),
    KEY idx_mood_user (user_id, logged_on),
    CONSTRAINT fk_mood_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- ai_logs
-- ----------------------------------------------------------------------------
CREATE TABLE ai_logs (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         BIGINT UNSIGNED NOT NULL,
    mood            ENUM('happy','calm','neutral','anxious','sad') DEFAULT NULL,
    user_message    TEXT NOT NULL,
    response_json   JSON NOT NULL,
    escalated       TINYINT(1) NOT NULL DEFAULT 0,
    tokens_in       INT UNSIGNED DEFAULT NULL,
    tokens_out      INT UNSIGNED DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ai_user (user_id, created_at),
    CONSTRAINT fk_ai_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- daily_messages  (the daily candle inspirational message)
-- ----------------------------------------------------------------------------
CREATE TABLE daily_messages (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    text_ar         TEXT NOT NULL,
    text_en         TEXT,
    show_on         DATE DEFAULT NULL,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_daily_show_on (show_on, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- programs and program_days
-- ----------------------------------------------------------------------------
CREATE TABLE programs (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug            VARCHAR(80) NOT NULL,
    category        ENUM('breathing','self_awareness','lifestyle','anxiety','relationships','self_development','candle') NOT NULL,
    title_ar        VARCHAR(190) NOT NULL,
    title_en        VARCHAR(190),
    description_ar  TEXT,
    description_en  TEXT,
    cover_url       VARCHAR(255) DEFAULT NULL,
    icon            VARCHAR(20) DEFAULT NULL,
    palette_start   CHAR(7) DEFAULT NULL,
    palette_end     CHAR(7) DEFAULT NULL,
    is_premium      TINYINT(1) NOT NULL DEFAULT 0,
    sort_order      INT NOT NULL DEFAULT 0,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_program_slug (slug),
    KEY idx_program_category (category, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE program_days (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    program_id      BIGINT UNSIGNED NOT NULL,
    day_number      INT UNSIGNED NOT NULL,
    title_ar        VARCHAR(190) NOT NULL,
    title_en        VARCHAR(190),
    description_ar  TEXT,
    description_en  TEXT,
    body_ar         MEDIUMTEXT,
    body_en         MEDIUMTEXT,
    media_url       VARCHAR(255) DEFAULT NULL,
    duration_min    TINYINT UNSIGNED NOT NULL DEFAULT 3,
    is_locked       TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_program_day (program_id, day_number),
    CONSTRAINT fk_pday_program FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE program_progress (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         BIGINT UNSIGNED NOT NULL,
    program_id      BIGINT UNSIGNED NOT NULL,
    day_number      INT UNSIGNED NOT NULL,
    completed_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_progress (user_id, program_id, day_number),
    CONSTRAINT fk_progress_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_progress_program FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- notifications
-- ----------------------------------------------------------------------------
CREATE TABLE notifications (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         BIGINT UNSIGNED NOT NULL,
    kind            ENUM('mood_checkin','session_reminder','reengagement','trial_ending','upgrade','generic') NOT NULL,
    title           VARCHAR(190) NOT NULL,
    body            TEXT NOT NULL,
    payload_json    JSON DEFAULT NULL,
    scheduled_at    DATETIME DEFAULT NULL,
    sent_at         DATETIME DEFAULT NULL,
    read_at         DATETIME DEFAULT NULL,
    status          ENUM('queued','sent','failed','read') NOT NULL DEFAULT 'queued',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_notif_user (user_id, status),
    KEY idx_notif_scheduled (scheduled_at, status),
    CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- rate_limits  (token bucket per ip+route)
-- ----------------------------------------------------------------------------
CREATE TABLE rate_limits (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    bucket_key      VARCHAR(190) NOT NULL,
    counter         INT UNSIGNED NOT NULL DEFAULT 0,
    window_started  DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_bucket (bucket_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- audit_log  (admin actions)
-- ----------------------------------------------------------------------------
CREATE TABLE audit_log (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    admin_user_id   BIGINT UNSIGNED DEFAULT NULL,
    action          VARCHAR(80) NOT NULL,
    entity          VARCHAR(80) NOT NULL,
    entity_id       BIGINT UNSIGNED DEFAULT NULL,
    detail_json     JSON DEFAULT NULL,
    ip_address      VARCHAR(64) DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_admin (admin_user_id, created_at),
    CONSTRAINT fk_audit_admin FOREIGN KEY (admin_user_id) REFERENCES admin_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Foreign key from users.consultant_id to consultants(id) (deferred so consultants exists)
ALTER TABLE users
    ADD CONSTRAINT fk_users_consultant FOREIGN KEY (consultant_id) REFERENCES consultants(id) ON DELETE SET NULL;

SET FOREIGN_KEY_CHECKS = 1;
