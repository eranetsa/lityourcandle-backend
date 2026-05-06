<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\AgoraTokenService;
use App\Services\PushService;

final class ChatController
{
    public function list(Request $req): void
    {
        $sess = $this->ensureMember($req);
        $afterId = (int)($req->query['after_id'] ?? 0);

        // Gate: the client can't read messages until the consultant has
        // officially started the session (started_at set, or the session is
        // already in_progress / completed). Consultants and admins always see
        // everything. The app polls this endpoint every few seconds, so as
        // soon as the consultant sends the first word the client gets it.
        $isConsultant = $req->userRole() === 'consultant' || $req->userRole() === 'admin';
        if (!$isConsultant && empty($sess['started_at'])
            && !in_array($sess['status'], ['in_progress', 'completed'], true)) {
            Response::json([
                'messages' => [],
                'started'  => false,
                'type'     => $sess['type'],
                'status'   => $sess['status'],
            ]);
        }

        $rows = DB::all(
            'SELECT id, sender_id, sender_role, body, attachment_url, read_at, created_at
             FROM messages
             WHERE session_id = :sid AND id > :aid
             ORDER BY id ASC
             LIMIT 500',
            [':sid' => $sess['id'], ':aid' => $afterId]
        );

        // When the consultant pulls messages, mark all the client's unread
        // messages as read so the consultant portal's "unread" highlight
        // clears automatically.
        if ($isConsultant) {
            DB::run(
                "UPDATE messages SET read_at = NOW()
                 WHERE session_id = :sid AND sender_role = 'user' AND read_at IS NULL",
                [':sid' => $sess['id']]
            );
        }

        Response::json([
            'messages' => $rows,
            'started'  => true,
            // Surface the current type/status so the polling client can flip
            // its UI between chat / voice / video when the consultant
            // changes mode mid-session.
            'type'     => $sess['type'],
            'status'   => $sess['status'],
        ]);
    }

    public function send(Request $req): void
    {
        $sess = $this->ensureMember($req);
        if (!in_array($sess['status'], ['pending', 'confirmed', 'in_progress'], true)) {
            Response::error('session_not_active', 409);
        }
        $data = Validator::check($req->body, [
            'body'           => 'required|string|max:4000',
            'attachment_url' => 'string|max:255',
        ]);
        $role = $req->userRole() === 'consultant' ? 'consultant' : 'user';

        // Block the client from messaging until the consultant has started.
        // Once the consultant types anything, the session becomes live for
        // both sides.
        if ($role !== 'consultant' && empty($sess['started_at'])) {
            Response::error('session_not_started', 409, [
                'message_ar' => 'الجلسة لم تبدأ بعد، انتظر حتى يفتحها المستشار.',
            ]);
        }

        $msgId = DB::insert('messages', [
            'session_id'     => $sess['id'],
            'sender_id'      => $req->userId(),
            'sender_role'    => $role,
            'body'           => $data['body'],
            'attachment_url' => $data['attachment_url'] ?? null,
        ]);

        // Auto-start on the consultant's first message.
        if ($role === 'consultant' && empty($sess['started_at'])) {
            DB::run(
                "UPDATE sessions SET started_at = NOW(), status = 'in_progress'
                 WHERE id = :id AND started_at IS NULL",
                [':id' => $sess['id']]
            );
            // Notify the client so their app can swap the waiting screen
            // for the live chat (the polling will also pick this up).
            DB::insert('notifications', [
                'user_id'      => (int)$sess['user_id'],
                'kind'         => 'session_reminder',
                'title'        => 'فُتحت جلستك 🕯️',
                'body'         => 'بدأ المستشار الجلسة، يمكنك الآن المحادثة.',
                'payload_json' => json_encode([
                    'session_id' => (int)$sess['id'],
                    'kind'       => 'session_started',
                ], JSON_UNESCAPED_UNICODE),
                'status'       => 'queued',
            ]);
        }

        // Push the message to whichever party isn't the sender so they
        // get a banner + sound when the chat screen isn't open. We
        // dispatch inline (not via cron) because chat is real-time —
        // a 60-second cron tick would feel broken.
        if ($role === 'consultant') {
            $recipientUserId = (int)$sess['user_id'];
            $senderName = DB::one(
                'SELECT name FROM consultants WHERE id = :cid',
                [':cid' => (int)$sess['consultant_id']]
            )['name'] ?? 'المستشار';
        } else {
            $cons = DB::one(
                'SELECT user_id FROM consultants WHERE id = :cid',
                [':cid' => (int)$sess['consultant_id']]
            );
            $recipientUserId = (int)($cons['user_id'] ?? 0);
            $senderName = DB::one(
                'SELECT name FROM users WHERE id = :uid',
                [':uid' => $req->userId()]
            )['name'] ?? 'العميل';
        }

        if ($recipientUserId > 0) {
            $preview = mb_substr($data['body'], 0, 100);
            if (mb_strlen($data['body']) > 100) {
                $preview .= '…';
            }
            $notifId = DB::insert('notifications', [
                'user_id'      => $recipientUserId,
                'kind'         => 'chat_message',
                'title'        => $senderName,
                'body'         => $preview,
                'payload_json' => json_encode([
                    'type'        => 'chat_message',
                    'session_id'  => (int)$sess['id'],
                    'message_id'  => $msgId,
                    'sender_role' => $role,
                ], JSON_UNESCAPED_UNICODE),
                'status'       => 'queued',
            ]);
            try { (new PushService())->send($notifId); } catch (\Throwable $e) { /* push best-effort */ }
        }

        Response::json(['message_id' => $msgId, 'created_at' => date('c')], 201);
    }

    public function rtcToken(Request $req): void
    {
        $sess = $this->ensureMember($req);
        if (!in_array($sess['type'], ['voice','video'], true)) {
            Response::error('session_type_not_rtc', 422);
        }
        $channel = 'sess_' . $sess['id'];
        $token = (new AgoraTokenService())->buildRtcToken($channel, $req->userId(), 3600, 'publisher');
        Response::json($token);
    }

    private function ensureMember(Request $req): array
    {
        $id = (int)$req->params['id'];
        $row = DB::one('SELECT * FROM sessions WHERE id = :id', [':id' => $id]);
        if (!$row) Response::error('not_found', 404);
        $isOwner = (int)$row['user_id'] === $req->userId();
        $isCons  = DB::one('SELECT 1 FROM consultants WHERE id = :cid AND user_id = :uid',
            [':cid' => $row['consultant_id'], ':uid' => $req->userId()]);
        if (!$isOwner && !$isCons && $req->userRole() !== 'admin') {
            Response::error('forbidden', 403);
        }
        return $row;
    }
}
