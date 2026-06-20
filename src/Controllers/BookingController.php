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
use App\Services\PushService;

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

        // Only one open booking at a time. The user can't queue a new request
        // until the consultant ends or cancels the previous one.
        $open = DB::one(
            "SELECT id, consultant_id FROM sessions
             WHERE user_id = :uid AND status IN ('pending','confirmed','in_progress')
             ORDER BY id DESC LIMIT 1",
            [':uid' => $uid]
        );
        if ($open) {
            Response::error('open_session_exists', 409, [
                'session_id'    => (int)$open['id'],
                'consultant_id' => (int)$open['consultant_id'],
                'message_ar'    => 'لديك طلب جلسة سابق لم يكتمل بعد، لا يمكنك إرسال طلب جديد حتى ينهيه المستشار.',
            ]);
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
            $isPaid = Subscription::isPaidPlan($sub);
            if (!$isPaid) {
                // Free / trial user: check the admin-configured monthly free
                // session cap (Settings → plan_free_sessions_per_month). Each
                // booking is counted with paid_with='free' so the cap is
                // enforced naturally on the next attempt.
                $remaining = Subscription::freeMonthlyRemaining($uid);
                if ($remaining <= 0) {
                    Response::error('subscription_required', 402, [
                        'free_monthly_used_up' => true,
                    ]);
                }
                $paidWith = 'free';
            } elseif ((int)$sub['sessions_remaining'] < 1) {
                Response::error('no_session_credits', 402, ['needs_extra' => true]);
            }
        }

        // Find the extra_session transaction for this user so we can link it
        // to the session row — enables accurate refund and purchase auditing.
        $extraTx = null;
        if ($paidWith !== 'free') {
            $extraTx = DB::one(
                "SELECT t.id FROM transactions t
                  WHERE t.user_id = :uid AND t.kind = 'extra_session'
                    AND NOT EXISTS (
                        SELECT 1 FROM sessions s
                         WHERE s.transaction_id = t.id AND s.status != 'canceled'
                    )
                  ORDER BY t.id DESC LIMIT 1",
                [':uid' => $uid]
            );
        }

        $sessionId = DB::transaction(function () use ($uid, $cons, $data, $paidWith, $sub, $allowFree, $extraTx) {
            $sid = DB::insert('sessions', [
                'user_id'        => $uid,
                'consultant_id'  => (int)$cons['id'],
                'type'           => $data['type'],
                'mode'           => 'scheduled',
                'status'         => 'pending',
                'scheduled_at'   => null,
                'pre_mood'       => $data['pre_mood'] ?? null,
                'pre_issue'      => $data['pre_issue'] ?? null,
                'pre_ai_summary' => $data['pre_ai_summary'] ?? null,
                'paid_with'      => $paidWith,
                'transaction_id' => $extraTx ? (int)$extraTx['id'] : null,
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
            $clientName = DB::one(
                'SELECT name FROM users WHERE id = :id',
                [':id' => $uid]
            )['name'] ?? 'عميل';
            $notifId = DB::insert('notifications', [
                'user_id'      => (int)$consultant['user_id'],
                'kind'         => 'session_reminder',
                'title'        => 'طلب جلسة جديد',
                'body'         => $clientName . ' — حدّد موعد الجلسة',
                'payload_json' => json_encode([
                    'session_id' => $sessionId,
                    'kind'       => 'consultant_new_booking',
                ], JSON_UNESCAPED_UNICODE),
                'status'       => 'queued',
            ]);
            // Dispatch inline so the consultant's phone vibrates within
            // seconds — the cron tick is once a minute and that's too
            // long to wait for the booking-arrived signal.
            try { (new PushService())->send($notifId); } catch (\Throwable $e) { /* best-effort */ }
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
                        u.id AS client_id, u.name AS client_name, u.email AS client_email,
                        (SELECT m.body FROM messages m
                            WHERE m.session_id = s.id ORDER BY m.id DESC LIMIT 1) AS last_message,
                        (SELECT m.sender_role FROM messages m
                            WHERE m.session_id = s.id ORDER BY m.id DESC LIMIT 1) AS last_message_role,
                        (SELECT m.created_at FROM messages m
                            WHERE m.session_id = s.id ORDER BY m.id DESC LIMIT 1) AS last_message_at,
                        (SELECT COUNT(*) FROM messages m
                            WHERE m.session_id = s.id AND m.sender_role = \'user\'
                              AND m.read_at IS NULL) AS unread_count
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
        // Enrich with the partner names so the chat header can show
        // "your consultant" or "your client" without a second roundtrip.
        $extra = DB::one(
            'SELECT c.name AS consultant_name, c.photo_url AS consultant_photo,
                    c.specialty, u.name AS client_name
             FROM consultants c
             LEFT JOIN users u ON u.id = :uid
             WHERE c.id = :cid',
            [':cid' => (int)$sess['consultant_id'], ':uid' => (int)$sess['user_id']]
        );
        Response::json(['session' => array_merge($sess, $extra ?? [])]);
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
     * Consultant-only: change the active conversation mode (chat/voice/video)
     * mid-session. The polling on the client picks the new type up via
     * /api/sessions/{id}/messages and switches its UI to the matching view.
     */
    public function setType(Request $req): void
    {
        if ($req->userRole() !== 'consultant' && $req->userRole() !== 'admin') {
            Response::error('forbidden', 403);
        }
        $data = Validator::check($req->body, [
            'type' => 'required|in:chat,voice,video',
        ]);
        $sess = $this->loadOwned($req);
        if (!in_array($sess['status'], ['confirmed', 'in_progress'], true)) {
            Response::error('invalid_state', 409);
        }
        DB::update('sessions', ['type' => $data['type']], 'id = :id', [':id' => $sess['id']]);

        // setType is now purely a *mode* switch (chat ↔ voice ↔ video).
        // It never raises a ring and never auto-promotes the session: that
        // was the source of the "client phone rings just from opening the
        // app" bug. Ringing is driven exclusively by /call/invite, and chat
        // sessions go live on the consultant's first message (ChatController).
        Response::json(['ok' => true, 'type' => $data['type']]);
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

    /**
     * Consultant / admin: re-open a previously closed or canceled session
     * so they can keep talking to the same client without forcing them to
     * book again. Flips status back to in_progress, clears the ended_at
     * stamp, and notifies the client so their app pulls the session out
     * of "completed" state on the next poll.
     */
    public function reopen(Request $req): void
    {
        if (!in_array($req->userRole(), ['consultant', 'admin'], true)) {
            Response::error('forbidden', 403);
        }
        $sess = $this->loadOwned($req);
        if (!in_array($sess['status'], ['completed', 'canceled', 'no_show'], true)) {
            Response::error('invalid_state', 409, [
                'message_ar' => 'الجلسة ليست منتهية أو ملغاة، لا حاجة لإعادة فتحها.',
            ]);
        }
        DB::run(
            "UPDATE sessions
                SET status = 'in_progress',
                    started_at = COALESCE(started_at, NOW()),
                    ended_at = NULL
              WHERE id = :id",
            [':id' => $sess['id']]
        );
        $notifId = DB::insert('notifications', [
            'user_id'      => (int)$sess['user_id'],
            'kind'         => 'session_reminder',
            'title'        => 'أعاد المستشار فتح جلستك 🕯️',
            'body'         => 'يمكنك مواصلة المحادثة الآن من حيث توقفت.',
            'payload_json' => json_encode([
                'session_id' => (int)$sess['id'],
                'kind'       => 'session_reopened',
            ], JSON_UNESCAPED_UNICODE),
            'status'       => 'queued',
        ]);
        // Inline dispatch so the client's phone wakes up immediately
        // rather than waiting for the next cron tick.
        try { (new \App\Services\PushService())->send($notifId); } catch (\Throwable $e) { /* best-effort */ }

        Response::json(['ok' => true, 'status' => 'in_progress']);
    }

    public function cancel(Request $req): void
    {
        $sess = $this->loadOwned($req);
        if (!in_array($sess['status'], ['pending', 'confirmed', 'in_progress'], true)) {
            Response::error('invalid_state', 409);
        }
        $byConsultant = $req->userRole() === 'consultant' || $req->userRole() === 'admin';
        DB::transaction(function () use ($sess) {
            DB::run("UPDATE sessions SET status = 'canceled' WHERE id = :id", [':id' => $sess['id']]);
            // Refund only if the session never actually started (pending /
            // confirmed). Once in_progress the credit was consumed — no refund.
            $sessionStarted = $sess['status'] === 'in_progress';
            if ($sess['paid_with'] !== 'free' && !$sessionStarted) {
                $sub = Subscription::current((int)$sess['user_id']);
                if ($sub) {
                    DB::run(
                        'UPDATE subscriptions SET sessions_remaining = sessions_remaining + 1 WHERE id = :id',
                        [':id' => $sub['id']]
                    );
                }
            }
        });
        // Notify the other party so they don't keep waiting
        if ($byConsultant) {
            DB::insert('notifications', [
                'user_id'      => (int)$sess['user_id'],
                'kind'         => 'session_reminder',
                'title'        => 'تم إلغاء جلستك',
                'body'         => 'قام المستشار بإلغاء الجلسة، يمكنك إرسال طلب جديد.',
                'payload_json' => json_encode([
                    'session_id' => (int)$sess['id'],
                    'kind'       => 'session_canceled_by_consultant',
                ], JSON_UNESCAPED_UNICODE),
                'status'       => 'queued',
            ]);
        } else {
            $consultant = DB::one(
                'SELECT user_id FROM consultants WHERE id = :id',
                [':id' => (int)$sess['consultant_id']]
            );
            if ($consultant && !empty($consultant['user_id'])) {
                DB::insert('notifications', [
                    'user_id'      => (int)$consultant['user_id'],
                    'kind'         => 'session_reminder',
                    'title'        => 'ألغى العميل الطلب',
                    'body'         => 'تمّ إلغاء طلب الجلسة من قِبَل العميل.',
                    'payload_json' => json_encode([
                        'session_id' => (int)$sess['id'],
                        'kind'       => 'session_canceled_by_client',
                    ], JSON_UNESCAPED_UNICODE),
                    'status'       => 'queued',
                ]);
            }
        }
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
