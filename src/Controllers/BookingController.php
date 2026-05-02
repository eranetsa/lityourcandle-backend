<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Crypto;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Models\Subscription;

final class BookingController
{
    public function create(Request $req): void
    {
        $data = Validator::check($req->body, [
            'consultant_id' => 'required|int',
            'type'          => 'required|in:chat,voice,video',
            'mode'          => 'in:instant,scheduled',
            'scheduled_at'  => 'string',
            'pre_mood'      => 'in:happy,calm,neutral,anxious,sad',
            'pre_issue'     => 'string|max:2000',
            'pre_ai_summary'=> 'string|max:2000',
            'use_extra'     => 'in:0,1',
        ]);

        $uid  = $req->userId();
        $mode = $data['mode'] ?? 'scheduled';

        $cons = DB::one('SELECT id, is_available, session_types FROM consultants WHERE id = :id',
            [':id' => $data['consultant_id']]);
        if (!$cons) Response::error('consultant_not_found', 404);
        if (!$cons['is_available']) Response::error('consultant_unavailable', 409);
        if (!in_array($data['type'], explode(',', (string)$cons['session_types']), true)) {
            Response::error('session_type_unsupported', 422);
        }

        $sub = Subscription::current($uid);
        $useExtra = !empty($data['use_extra']);
        $paidWith = 'credit';

        if ($useExtra) {
            $paidWith = 'extra';   // user must have already purchased extra via /subscriptions/extra-session
            if (!$sub || (int)$sub['sessions_remaining'] < 1) {
                Response::error('no_session_credits', 402, ['needs_purchase' => true]);
            }
        } else {
            if (!Subscription::isPaidPlan($sub)) {
                Response::error('subscription_required', 402);
            }
            if ((int)$sub['sessions_remaining'] < 1) {
                Response::error('no_session_credits', 402, ['needs_extra' => true]);
            }
        }

        $sessionId = DB::transaction(function () use ($uid, $cons, $data, $mode, $paidWith, $sub) {
            $sid = DB::insert('sessions', [
                'user_id'        => $uid,
                'consultant_id'  => (int)$cons['id'],
                'type'           => $data['type'],
                'mode'           => $mode,
                'status'         => $mode === 'instant' ? 'confirmed' : 'pending',
                'scheduled_at'   => $data['scheduled_at'] ?? null,
                'pre_mood'       => $data['pre_mood'] ?? null,
                'pre_issue'      => $data['pre_issue'] ?? null,
                'pre_ai_summary' => $data['pre_ai_summary'] ?? null,
                'paid_with'      => $paidWith,
                'room_token'     => bin2hex(random_bytes(12)),
            ]);
            DB::run(
                'UPDATE subscriptions SET sessions_remaining = GREATEST(sessions_remaining - 1, 0) WHERE id = :id',
                [':id' => $sub['id']]
            );
            return $sid;
        });

        Response::json(['session_id' => $sessionId], 201);
    }

    public function mine(Request $req): void
    {
        $uid = $req->userId();
        $rows = DB::all(
            'SELECT s.id, s.type, s.mode, s.status, s.scheduled_at, s.started_at, s.ended_at,
                    s.duration_min, s.post_rating, s.created_at,
                    c.id AS consultant_id, c.name AS consultant_name, c.photo_url, c.specialty
             FROM sessions s
             JOIN consultants c ON c.id = s.consultant_id
             WHERE s.user_id = :uid
             ORDER BY s.created_at DESC
             LIMIT 100',
            [':uid' => $uid]
        );
        Response::json(['sessions' => $rows]);
    }

    public function show(Request $req): void
    {
        $sess = $this->loadOwned($req);
        Response::json(['session' => $sess]);
    }

    public function start(Request $req): void
    {
        $sess = $this->loadOwned($req);
        if (!in_array($sess['status'], ['pending', 'confirmed'], true)) {
            Response::error('invalid_state', 409);
        }
        DB::run(
            "UPDATE sessions SET status = 'in_progress', started_at = NOW() WHERE id = :id",
            [':id' => $sess['id']]
        );
        Response::json(['ok' => true, 'started_at' => date('c'), 'room_token' => $sess['room_token']]);
    }

    public function end(Request $req): void
    {
        $sess = $this->loadOwned($req);
        if ($sess['status'] !== 'in_progress') Response::error('invalid_state', 409);
        $started = strtotime((string)$sess['started_at']) ?: time();
        $duration = max(1, (int)round((time() - $started) / 60));
        DB::run(
            "UPDATE sessions SET status = 'completed', ended_at = NOW(), duration_min = :d WHERE id = :id",
            [':d' => $duration, ':id' => $sess['id']]
        );
        Response::json(['ok' => true, 'duration_min' => $duration]);
    }

    public function cancel(Request $req): void
    {
        $sess = $this->loadOwned($req);
        if (!in_array($sess['status'], ['pending', 'confirmed'], true)) {
            Response::error('invalid_state', 409);
        }
        DB::transaction(function () use ($sess) {
            DB::run("UPDATE sessions SET status = 'canceled' WHERE id = :id", [':id' => $sess['id']]);
            DB::run(
                'UPDATE subscriptions SET sessions_remaining = sessions_remaining + 1
                 WHERE user_id = :uid ORDER BY id DESC LIMIT 1',
                [':uid' => $sess['user_id']]
            );
        });
        Response::json(['ok' => true]);
    }

    public function feedback(Request $req): void
    {
        $sess = $this->loadOwned($req);
        $data = Validator::check($req->body, [
            'rating'    => 'required|int|min:1|max:5',
            'notes'     => 'string|max:5000',
            'follow_up' => 'string|max:2000',
        ]);
        $update = ['post_rating' => (int)$data['rating']];
        if (!empty($data['notes'])) {
            $update['post_notes_enc'] = Crypto::encrypt($data['notes']);
        }
        if (!empty($data['follow_up'])) {
            $update['follow_up'] = $data['follow_up'];
        }
        DB::update('sessions', $update, 'id = :id', [':id' => $sess['id']]);

        // Update consultant aggregate rating
        DB::run(
            'UPDATE consultants c
             JOIN (
                SELECT consultant_id, AVG(post_rating) AS r, COUNT(post_rating) AS n
                FROM sessions WHERE consultant_id = :cid AND post_rating IS NOT NULL
             ) agg ON agg.consultant_id = c.id
             SET c.rating = ROUND(agg.r, 2), c.rating_count = agg.n
             WHERE c.id = :cid',
            [':cid' => $sess['consultant_id']]
        );

        Response::json(['ok' => true]);
    }

    private function loadOwned(Request $req): array
    {
        $id = (int)$req->params['id'];
        $row = DB::one('SELECT * FROM sessions WHERE id = :id', [':id' => $id]);
        if (!$row) Response::error('not_found', 404);
        $isOwner = (int)$row['user_id'] === $req->userId();
        $isCons  = $req->userRole() === 'consultant'
            && DB::one('SELECT 1 FROM consultants WHERE id = :cid AND user_id = :uid',
                [':cid' => $row['consultant_id'], ':uid' => $req->userId()]);
        if (!$isOwner && !$isCons && $req->userRole() !== 'admin') {
            Response::error('forbidden', 403);
        }
        return $row;
    }
}
