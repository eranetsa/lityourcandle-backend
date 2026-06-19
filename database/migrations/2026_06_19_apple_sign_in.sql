-- Social sign-in: Apple (iOS) and Google (Android).
ALTER TABLE users
    ADD COLUMN apple_sub  VARCHAR(255) DEFAULT NULL AFTER device_id,
    ADD COLUMN google_sub VARCHAR(255) DEFAULT NULL AFTER apple_sub,
    ADD UNIQUE KEY uniq_users_apple_sub  (apple_sub),
    ADD UNIQUE KEY uniq_users_google_sub (google_sub);
