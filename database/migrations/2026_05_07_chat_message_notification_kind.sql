-- Add 'chat_message' to notifications.kind so messages sent over the
-- WebSocket / HTTP chat path can queue a push without the row being
-- silently truncated (MySQL emits a warning, not an error, on enum
-- truncation; rows go in with an empty kind and never dispatch).
ALTER TABLE notifications
  MODIFY COLUMN kind ENUM(
    'mood_checkin',
    'session_reminder',
    'reengagement',
    'trial_ending',
    'upgrade',
    'generic',
    'incoming_call',
    'missed_call',
    'chat_message'
  ) NOT NULL;
