<?php
declare(strict_types=1);

/**
 * Run daily: marks expired subscriptions, queues "trial ending" notifications,
 * and downgrades expired users to free.
 *
 *   * 0 3 * * *  /usr/bin/php /var/www/lityourcandle/cron/update_subscriptions.php
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\App;
use App\Core\DB;
use App\Core\Logger;

App::boot(dirname(__DIR__));

// 1. Expire any active/trial subscriptions whose expires_at has passed
$expired = DB::run(
    "UPDATE subscriptions
     SET status = 'expired'
     WHERE status IN ('active','trial','grace')
       AND expires_at IS NOT NULL
       AND expires_at < NOW()"
)->rowCount();

// 2. Insert a "free" record for users whose latest sub just expired
DB::run(
    "INSERT INTO subscriptions (user_id, plan, status, store)
     SELECT u.id, 'free', 'active', 'none'
     FROM users u
     LEFT JOIN subscriptions s ON s.user_id = u.id
        AND s.id = (SELECT MAX(id) FROM subscriptions WHERE user_id = u.id)
     WHERE (s.id IS NULL OR s.status = 'expired')
       AND NOT EXISTS (
         SELECT 1 FROM subscriptions s2
         WHERE s2.user_id = u.id AND s2.plan = 'free' AND s2.status = 'active'
         ORDER BY s2.id DESC LIMIT 1
       )"
);

// 3. Queue "trial ending" notifications for trials expiring in <24h
$trialEnding = DB::all(
    "SELECT user_id FROM subscriptions
     WHERE status = 'trial'
       AND trial_ends_at IS NOT NULL
       AND trial_ends_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 24 HOUR)"
);
foreach ($trialEnding as $row) {
    DB::run(
        "INSERT INTO notifications (user_id, kind, title, body, status)
         SELECT :uid, 'trial_ending', 'تنتهي تجربتك قريبًا', 'لا تطفئ شمعتك… اشترك للاستمرار 🕯️', 'queued'
         WHERE NOT EXISTS (
           SELECT 1 FROM notifications
           WHERE user_id = :uid AND kind = 'trial_ending'
             AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
         )",
        [':uid' => $row['user_id']]
    );
}

Logger::info('cron_subscriptions_done', ['expired' => $expired, 'trial_ending' => count($trialEnding)]);
echo "Expired: $expired, trial-ending: " . count($trialEnding) . PHP_EOL;
