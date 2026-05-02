<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

final class MoodController
{
    public function log(Request $req): void
    {
        $data = Validator::check($req->body, [
            'mood' => 'required|in:happy,neutral,sad',
            'note' => 'string|max:500',
            'date' => 'date',
        ]);
        $uid = $req->userId();
        $day = !empty($data['date']) ? date('Y-m-d', strtotime($data['date'])) : date('Y-m-d');

        DB::run(
            'INSERT INTO mood_logs (user_id, mood, note, logged_on)
             VALUES (:u, :m, :n, :d)
             ON DUPLICATE KEY UPDATE mood = VALUES(mood), note = VALUES(note)',
            [':u' => $uid, ':m' => $data['mood'], ':n' => $data['note'] ?? null, ':d' => $day]
        );
        Response::json(['ok' => true, 'date' => $day]);
    }

    public function history(Request $req): void
    {
        $days = max(1, min(90, (int)($req->query['days'] ?? 30)));
        $rows = DB::all(
            'SELECT logged_on AS date, mood, note
             FROM mood_logs
             WHERE user_id = :uid AND logged_on >= DATE_SUB(CURDATE(), INTERVAL :d DAY)
             ORDER BY logged_on ASC',
            [':uid' => $req->userId(), ':d' => $days]
        );
        Response::json(['days' => $days, 'history' => $rows]);
    }

    public function insights(Request $req): void
    {
        $uid = $req->userId();
        $rows = DB::all(
            "SELECT mood, COUNT(*) AS n
             FROM mood_logs
             WHERE user_id = :uid AND logged_on >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
             GROUP BY mood",
            [':uid' => $uid]
        );
        $totals = ['happy' => 0, 'neutral' => 0, 'sad' => 0];
        foreach ($rows as $r) $totals[$r['mood']] = (int)$r['n'];
        $total30 = array_sum($totals);

        $last7 = DB::all(
            "SELECT mood FROM mood_logs
             WHERE user_id = :uid AND logged_on >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
             ORDER BY logged_on DESC",
            [':uid' => $uid]
        );
        $sad7 = count(array_filter($last7, fn($r) => $r['mood'] === 'sad'));
        $recommend = $sad7 >= 4;

        Response::json([
            'last_30_days'  => $totals,
            'total_logged'  => $total30,
            'sad_last_7'    => $sad7,
            'recommend_consultant' => $recommend,
            'recommended_specialty' => $recommend ? 'القلق والتوتر' : null,
            'message_ar'    => $recommend
                ? 'لاحظنا أن أيامك الأخيرة كانت ثقيلة، ربما جلسة قصيرة مع مستشار تساعدك 🕯️'
                : null,
        ]);
    }
}
