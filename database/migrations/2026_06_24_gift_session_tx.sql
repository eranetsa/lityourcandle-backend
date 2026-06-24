-- Add 'gift_session' to transactions.kind and 'none' to transactions.store
-- Required for the admin gift-session feature.

ALTER TABLE transactions
    MODIFY COLUMN kind  ENUM('subscription','extra_session','refund','gift_session') NOT NULL,
    MODIFY COLUMN store ENUM('apple','google','manual','none') NOT NULL;
