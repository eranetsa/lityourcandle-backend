-- Guest user support: lets the mobile app run without sign-in by issuing a
-- JWT bound to a per-install device id. All existing endpoints (mood, AI,
-- programs progress, daily message, paywall) continue to work as-is.
--
-- - users.role gains 'guest'
-- - users.device_id stores the per-install id from the app (unique)
-- - users.platform stores ios / android / web (matches push_platform values
--   but separate so push registration can lag the auth handshake)

ALTER TABLE users
  MODIFY COLUMN role ENUM('user','consultant','admin','guest') NOT NULL DEFAULT 'user';

ALTER TABLE users
  ADD COLUMN device_id VARCHAR(64) DEFAULT NULL AFTER push_platform,
  ADD COLUMN platform  VARCHAR(20) DEFAULT NULL AFTER device_id,
  ADD UNIQUE KEY uniq_users_device_id (device_id);
