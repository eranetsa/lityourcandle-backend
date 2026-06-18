-- =============================================================================
-- 2026-06-18 — Extend notifications.kind for cancelled-call pushes
-- =============================================================================
-- CallController::cancel() now enqueues a kind = 'incoming_call_cancelled'
-- notification so a killed device can dismiss the native CallKit / Telecom
-- ring via a VoIP / data push. The ENUM previously rejected this value.
--
-- IMPORTANT: this migration is a SUPERSET of every prior ENUM value, including
-- 'chat_message' (added in 2026_05_07_chat_message_notification_kind.sql).
-- Dropping any previous value here would silently truncate existing rows.
--
-- The push_tokens table already has a voip_token VARCHAR(255) column
-- (added by 2026_05_05_call_invites.sql), so no schema change there.
-- =============================================================================

ALTER TABLE notifications
    MODIFY COLUMN kind ENUM(
        'mood_checkin',
        'session_reminder',
        'reengagement',
        'trial_ending',
        'upgrade',
        'generic',
        'incoming_call',
        'incoming_call_cancelled',
        'missed_call',
        'chat_message'
    ) NOT NULL;
