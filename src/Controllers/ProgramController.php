<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Models\Subscription;

final class ProgramController
{
    public function list(Request $req): void
    {
        $where = ['is_active = 1'];
        $params = [];
        if (!empty($req->query['category'])) {
            $where[] = 'category = :c';
            $params[':c'] = (string)$req->query['category'];
        }
        $rows = DB::all(
            'SELECT id, slug, category, title_ar, title_en, description_ar, description_en,
                    cover_url, is_premium, sort_order
             FROM programs
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY sort_order ASC, id ASC',
            $params
        );
        Response::json(['programs' => $rows]);
    }

    public function show(Request $req): void
    {
        $slug = (string)$req->params['slug'];
        $program = DB::one('SELECT * FROM programs WHERE slug = :s AND is_active = 1', [':s' => $slug]);
        if (!$program) Response::error('not_found', 404);

        $days = DB::all(
            'SELECT day_number, title_ar, title_en, description_ar, description_en,
                    body_ar, body_en, media_url, duration_min, is_locked
             FROM program_days
             WHERE program_id = :pid
             ORDER BY day_number ASC',
            [':pid' => $program['id']]
        );

        $unlocked = $this->isUnlocked($req, $program);
        if (!$unlocked) {
            // Hide premium body for non-subscribers; show only first day as preview
            foreach ($days as &$d) {
                if ($d['day_number'] > 1) {
                    $d['body_ar'] = null;
                    $d['body_en'] = null;
                    $d['is_locked'] = 1;
                }
            }
        }

        $progress = [];
        if ($req->userId()) {
            $progress = array_column(DB::all(
                'SELECT day_number FROM program_progress WHERE user_id = :u AND program_id = :p',
                [':u' => $req->userId(), ':p' => $program['id']]
            ), 'day_number');
        }

        Response::json([
            'program'          => $program,
            'days'             => $days,
            'unlocked'         => $unlocked,
            'completed_days'   => $progress,
        ]);
    }

    public function complete(Request $req): void
    {
        $slug = (string)$req->params['slug'];
        $day  = (int)$req->params['day'];
        $program = DB::one('SELECT id, is_premium FROM programs WHERE slug = :s', [':s' => $slug]);
        if (!$program) Response::error('not_found', 404);
        if (!$this->isUnlocked($req, $program)) Response::error('subscription_required', 402);

        DB::run(
            'INSERT IGNORE INTO program_progress (user_id, program_id, day_number) VALUES (:u, :p, :d)',
            [':u' => $req->userId(), ':p' => $program['id'], ':d' => $day]
        );
        Response::json(['ok' => true]);
    }

    private function isUnlocked(Request $req, array $program): bool
    {
        if (empty($program['is_premium'])) return true;
        if (!$req->userId()) return false;
        $sub = Subscription::current($req->userId());
        return Subscription::isPaidPlan($sub) || ($sub && $sub['status'] === 'trial');
    }
}
