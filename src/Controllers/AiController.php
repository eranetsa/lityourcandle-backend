<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Core\Validator;
use App\Models\Subscription;
use App\Services\CandleAiService;

final class AiController
{
    private const FREE_DAILY_AI_LIMIT_DEFAULT = 3;

    public function candle(Request $req): void
    {
        $data = Validator::check($req->body, [
            'mood'    => 'in:happy,calm,neutral,anxious,sad',
            'message' => 'required|string|min:1|max:2000',
        ]);

        $uid = $req->userId();
        $sub = Subscription::current($uid);

        if (!Subscription::isPaidPlan($sub) && ($sub['status'] ?? '') !== 'trial') {
            $limit = (int)(Settings::get('ai_free_daily_limit', (string)self::FREE_DAILY_AI_LIMIT_DEFAULT) ?? self::FREE_DAILY_AI_LIMIT_DEFAULT);
            if ($limit < 0) $limit = 0;
            $today = DB::one(
                'SELECT COUNT(*) AS n FROM ai_logs WHERE user_id = :uid AND DATE(created_at) = CURDATE()',
                [':uid' => $uid]
            );
            if ((int)$today['n'] >= $limit) {
                Response::error('ai_daily_limit_reached', 402, [
                    'show_upgrade'   => true,
                    'limit_per_day'  => $limit,
                    'message_ar'     => 'لقد استخدمت محادثاتك المجانية اليوم. اشترك للاستمرار مع شمعة بلا حدود.',
                ]);
            }
        }

        $recent = DB::all(
            'SELECT mood FROM mood_logs WHERE user_id = :uid ORDER BY logged_on DESC LIMIT 7',
            [':uid' => $uid]
        );
        $recentMoods = array_column($recent, 'mood');

        $result = (new CandleAiService())->generate($data['message'], $data['mood'] ?? null, $recentMoods);
        $payload = $result['data'];

        $logId = DB::insert('ai_logs', [
            'user_id'       => $uid,
            'mood'          => $data['mood'] ?? null,
            'user_message'  => $data['message'],
            'response_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'escalated'     => !empty($payload['escalate']) ? 1 : 0,
            'tokens_in'     => $result['tokens_in'],
            'tokens_out'    => $result['tokens_out'],
        ]);

        $resp = ['ai_log_id' => $logId, 'response' => $payload];
        if (!empty($payload['escalate'])) {
            $resp['recommended_consultants'] = DB::all(
                'SELECT id, name, photo_url, specialty, rating, price_per_session, currency
                 FROM consultants WHERE is_available = 1
                 ORDER BY rating DESC LIMIT 3'
            );
        }
        Response::json($resp);
    }
}
