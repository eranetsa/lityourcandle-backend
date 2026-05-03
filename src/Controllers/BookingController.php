<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
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
        $allowFree = (bool)App::config('bookings.allow_free', false);
        $paidWith = 'credit';

        if ($allowFree) {
            // Testing mode: anyone can book, no credit deducted, marked free
            $paidWith = 'free';
        } elseif ($useExtra) {
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

        $sessionId = DB::transaction(function () use ($uid, $cons, $data, $mode, $paidWith, $sub, $allowFree) {
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
            // Only decrement session credits in real paid mode
            if (!$allowFree && $sub) {
                DB::run(
                    'UPDATE subscriptions SET sessions_remaining = GREATEST(sessions_remaining - 1, 0) WHERE id = :id',
                    [':id' => $sub['id']]
                );
            }
            return $sid;
        });

        // Notify the consultant (if they have a linked user account) so they
        // can open the portal and pick a time.
        $consultant = DB::one(
            'SELECT user_id, name FROM consultants WHERE id = :id',
            [':id' => (int)$cons['id']]
        );
        if ($consultant && !empty($consultant['user_id'])) {
            DB::insert('notifications', [
                'user_id'      => (int)$consultant['user_id'],
                'kind'         => 'session_reminder',
                'title'        => 'طلب جلسة جديد',
                'body'         => $mode === 'instant'
                    ? 'لديك جلسة فورية تنتظر بدئها'
                    : 'طلب حجز جديد — حدّد موعد الجلسة',
                'payload_json' => json_encode([
                    'session_id' => $sessionId,
                    'mode'       => $mode,
                    'kind'       => 'consultant_new_booking',
                ], JSON_UNESCAPED_UNICODE),
                'status'       => 'queued',
            ]);
        }

        Response::json(['session_id' => $sessionId], 201);
    }

    public function mine(Request $req): void
    {
        $uid = $req->userId();

        // Consultants see sessions where they're the provider (newest first)
        if ($req->userRole() === 'consultant') {
            $cons = DB::one('SELECT id FROM consultants WHERE user_id = :uid', [':uid' => $uid]);
            if (!$cons) {
                Response::json(['sessions' => []]);
            }
            $rows = DB::all(
                'SELECT s.id, s.type, s.mode, s.status, s.scheduled_at, s.started_at, s.ended_at,
                        s.duration_min, s.post_rating, s.created_at, s.pre_mood, s.pre_issue,
                        u.id AS client_id, u.name AS client_name, u.email AS client_email
                 FROM sessions s
                 JOIN users u ON u.id = s.user_id
                 WHERE s.consultant_id = :cid
                 ORDER BY
                    CASE s.status
                        WHEN \'pending\'     THEN 0
                        WHEN \'confirmed\'   THEN 1
                        WHEN \'in_progress\' THEN 2
                        ELSE 3
                    END,
                    COALESCE(s.scheduled_at, s.created_at) ASC
                 LIMIT 200',
                [':cid' => $cons['id']]
            );
            Response::json(['sessions' => $rows, 'consultant_id' => (int)$cons['id']]);
        }

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

    /**
     * Consultant-only: pick a moment for a pending/confirmed session and
     * notify the client. Body: { scheduled_at: "YYYY-MM-DD HH:MM" }
     */
    public function schedule(Request $req): void
    {
        if ($req->userRole() !== 'consultant') Response::error('forbidden', 403);

        $data = Validator::check($req->body, [
            'scheduled_at' => 'required|string|date',
        ]);
        $scheduledAt = date('Y-m-d H:i:s', strtotime((string)$data['scheduled_at']));

        $sess = $this->loadOwned($req);
        if (!in_array($sess['status'], ['pending', 'confirmed'], true)) {
            Response::error('invalid_state', 409);
        }

        DB::update('sessions', [
            'scheduled_at' => $scheduledAt,
            'status'       => 'confirmed',
        ], 'id = :id', [':id' => $sess['id']]);

        // Notify the client
        DB::insert('notifications', [
            'user_id'      => (int)$sess['user_id'],
            'kind'         => 'session_reminder',
            'title'        => 'تم تأكيد موعد جلستك 🕯️',
            'body'         => 'الموعد: ' . date('Y-m-d · H:i', strtotime($scheduledAt)),
            'payload_json' => json_encode([
                'session_id'   => (int)$sess['id'],
                'scheduled_at' => $scheduledAt,
                'kind'         => 'session_scheduled',
            ], JSON_UNESCAPED_UNICODE),
            'status'       => 'queued',
        ]);

        Response::json(['ok' => true, 'scheduled_at' => $scheduledAt, 'status' => 'confirmed']);
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
