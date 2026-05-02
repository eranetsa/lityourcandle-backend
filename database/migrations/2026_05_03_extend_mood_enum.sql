-- Extend mood ENUM from 3 → 5 values across all mood-bearing tables.
-- The app surfaces happy/calm/neutral/anxious/sad in its UI; backend now
-- preserves that fidelity end-to-end.

ALTER TABLE mood_logs
  MODIFY COLUMN mood ENUM('happy','calm','neutral','anxious','sad') NOT NULL;

ALTER TABLE sessions
  MODIFY COLUMN pre_mood ENUM('happy','calm','neutral','anxious','sad') DEFAULT NULL;

ALTER TABLE ai_logs
  MODIFY COLUMN mood ENUM('happy','calm','neutral','anxious','sad') DEFAULT NULL;
