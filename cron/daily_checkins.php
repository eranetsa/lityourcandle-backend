<?php
declare(strict_types=1);

/**
 * Run daily at the local check-in hour (e.g. 19:00 Riyadh):
 *   0 19 * * *  /usr/bin/php /var/www/lityourcandle/cron/daily_checkins.php
 *
 * Queues mood check-in, session reminders (24h before), and re-engagement (7d idle).
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\App;
use App\Core\DB;

App::boot(dirname(__DIR__));

// 1. Daily mood check-in for users who didn't log today
DB::run(
    "INSERT INTO notifications (user_id, kind, title, body, status)
     SELECT u.id, 'mood_checkin', 'كيف تشعر اليوم؟ 🕯️',
            'سجّل مزاجك اليوم في دقيقة، خطوة صغيرة لرحلة كبيرة.', 'queued'
     FROM users u
     LEFT JOIN mood_logs m ON m.user_id = u.id AND m.logged_on = CURDATE()
     WHERE u.is_active = 1
       AND m.id IS NULL
       AND u.push_token IS NOT NULL
       AND NOT EXISTS (
         SELECT 1 FROM notifications n
         WHERE n.user_id = u.id AND n.kind = 'mood_checkin'
           AND DATE(n.created_at) = CURDATE()
       )"
);

// 2. Session reminders 24h before scheduled time
DB::run(
    "INSERT INTO notifications (user_id, kind, title, body, payload_json, status)
     SELECT s.user_id, 'session_reminder', 'تذكير بجلستك',
            CONCAT('جلستك المحجوزة غدًا الساعة ', DATE_FORMAT(s.scheduled_at, '%H:%i')),
            JSON_OBJECT('session_id', s.id), 'queued'
     FROM sessions s
     WHERE s.status IN ('pending','confirmed')
       AND s.scheduled_at BETWEEN DATE_ADD(NOW(), INTERVAL 23 HOUR) AND DATE_ADD(NOW(), INTERVAL 25 HOUR)
       AND NOT EXISTS (
         SELECT 1 FROM notifications n
         WHERE n.user_id = s.user_id AND n.kind = 'session_reminder'
           AND JSON_EXTRACT(n.payload_json, '$.session_id') = s.id
       )"
);

// 3. Re-engagement after 7+ days of inactivity
DB::run(
    "INSERT INTO notifications (user_id, kind, title, body, status)
     SELECT u.id, 'reengagement', 'اشتقنا لك 🕯️',
            'لا تطفئ شمعتك… عد ولو لدقيقة واحدة اليوم.', 'queued'
     FROM users u
     WHERE u.is_active = 1 AND u.push_token IS NOT NULL
       AND (u.last_login_at IS NULL OR u.last_login_at < DATE_SUB(NOW(), INTERVAL 7 DAY))
       AND NOT EXISTS (
         SELECT 1 FROM notifications n
         WHERE n.user_id = u.id AND n.kind = 'reengagement'
           AND n.created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
       )"
);

echo "Daily check-ins queued" . PHP_EOL;
