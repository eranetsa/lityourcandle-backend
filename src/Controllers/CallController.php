<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\AgoraTokenService;
use App\Services\PushService;
use App\Services\WsBridge;

/**
 * Call signalling: invite / accept / decline / cancel / current.
 *
 * Lives on top of `call_invites` (one row per ring attempt). The receiver's
 * device decides to ring based on a `call.incoming` WS frame **or** a push
 * notification — never on session state. That keeps a freshly-opened app
 * from ringing just because the user has an active session.
 */
final class CallController
{
    private const INVITE_TTL_SECONDS = 45;

    /**
     * Consultant initiates a call to the booking's client.
     * POST /api/sessions/{id}/call/invite   { media: voice|video }
     */
    public function invite(Request $req): void
    {
        $sess = $this->loadSessionForConsultant($req);
        $data = Validator::check($req->body, [
            'media' => 'required|in:voice,video',
        ]);

        if (!in_array($sess['status'], ['confirmed', 'in_progress'], true)) {
            Response::error('invalid_state', 409);
        }
        if ($sess['type'] !== $data['media']) {
            // The session must already be of the matching type — flip it via
            // /sessions/{id}/type before inviting if needed.
            Response::error('session_type_mismatch', 422);
        }

        // If a ringing invite already exists for this session, reuse it
        // rather than spamming new ones. (Multiple devices, double-tap, etc.)
        $existing = DB::one(
            "SELECT * FROM call_invites
              WHERE session_id = :sid AND status = 'ringing'
              ORDER BY id DESC LIMIT 1",
            [':sid' => (int)$sess['id']]
        );
        if ($existing && strtotime((string)$existing['expires_at']) > time()) {
            Response::json([
                'invite_id'  => (int)$existing['id'],
                'expires_at' => date('c', strtotime((string)$existing['expires_at'])),
                'reused'     => true,
            ]);
        }

        $expiresAt = date('Y-m-d H:i:s', time() + self::INVITE_TTL_SECONDS);
        $inviteId = DB::insert('call_invites', [
            'session_id'   => (int)$sess['id'],
            'from_user_id' => $req->userId(),
            'to_user_id'   => (int)$sess['user_id'],
            'media'        => $data['media'],
            'status'       => 'ringing',
            'expires_at'   => $expiresAt,
        ]);

        $from = DB::one(
            'SELECT u.id, u.name, u.avatar_url
               FROM users u WHERE u.id = :id',
            [':id' => $req->userId()]
        ) ?? ['id' => $req->userId(), 'name' => 'المستشار', 'avatar_url' => null];

        $frame = [
            'type'       => 'call.incoming',
            'invite_id'  => $inviteId,
            'session_id' => (int)$sess['id'],
            'media'      => $data['media'],
            'from'       => [
                'id'         => (int)$from['id'],
                'name'       => $from['name'],
                'avatar_url' => $from['avatar_url'],
            ],
            'expires_at' => date('c', strtotime($expiresAt)),
        ];

        WsBridge::pushToUser((int)$sess['user_id'], $frame);

        // Push notification: queued *and* dispatched inline because the
        // notifications cron runs once per minute, but the invite expires
        // after 45s — we can't wait for the cron tick.
        // PushService recognises kind=incoming_call and applies the ringtone /
        // time-sensitive / high-priority flags for each platform.
        $notifId = DB::insert('notifications', [
            'user_id'      => (int)$sess['user_id'],
            'kind'         => 'incoming_call',
            'title'        => 'مكالمة واردة من ' . ($from['name'] ?: 'المستشار'),
            'body'         => $data['media'] === 'video' ? 'مكالمة فيديو واردة' : 'مكالمة صوتية واردة',
            'payload_json' => json_encode([
                'type'       => 'incoming_call',
                'invite_id'  => $inviteId,
                'session_id' => (int)$sess['id'],
                'media'      => $data['media'],
                'expires_at' => date('c', strtotime($expiresAt)),
                'from'       => [
                    'id'   => (int)$from['id'],
                    'name' => $from['name'],
                ],
            ], JSON_UNESCAPED_UNICODE),
            'status'       => 'queued',
        ]);
        try { (new PushService())->send($notifId); } catch (\Throwable $e) { /* push best-effort */ }

        Response::json([
            'invite_id'  => $inviteId,
            'expires_at' => date('c', strtotime($expiresAt)),
        ]);
    }

    /**
     * Client accepts the ring. POST /api/calls/{invite_id}/accept
     * On success returns the RTC token so the client can join immediately.
     */
    public function accept(Request $req): void
    {
        $invite = $this->loadInviteForReceiver($req);
        $this->markResponded($invite, 'accepted');

        // Accepting a call is the de-facto "session start" for voice/video,
        // so flip the session to in_progress if it isn't already. Without
        // this the client lands on WaitingRoom even after answering.
        DB::run(
            "UPDATE sessions
                SET status = 'in_progress',
                    started_at = COALESCE(started_at, NOW())
              WHERE id = :id AND status IN ('confirmed','pending','in_progress')",
            [':id' => (int)$invite['session_id']]
        );

        WsBridge::pushToUser((int)$invite['from_user_id'], [
            'type'       => 'call.accepted',
            'invite_id'  => (int)$invite['id'],
            'session_id' => (int)$invite['session_id'],
        ]);

        $channel = 'sess_' . (int)$invite['session_id'];
        $rtc     = (new AgoraTokenService())->buildRtcToken($channel, $req->userId(), 3600, 'publisher');

        Response::json(['ok' => true, 'rtc' => $rtc]);
    }

    /** Client declines the ring. POST /api/calls/{invite_id}/decline */
    public function decline(Request $req): void
    {
        $invite = $this->loadInviteForReceiver($req);
        $this->markResponded($invite, 'declined');

        WsBridge::pushToUser((int)$invite['from_user_id'], [
            'type'       => 'call.declined',
            'invite_id'  => (int)$invite['id'],
            'session_id' => (int)$invite['session_id'],
        ]);

        $notifId = DB::insert('notifications', [
            'user_id'      => (int)$invite['from_user_id'],
            'kind'         => 'session_reminder',
            'title'        => 'رفض العميل المكالمة',
            'body'         => 'يمكنك التواصل عبر الرسائل أو إعادة المحاولة لاحقًا.',
            'payload_json' => json_encode([
                'type'       => 'call_declined',
                'invite_id'  => (int)$invite['id'],
                'session_id' => (int)$invite['session_id'],
            ], JSON_UNESCAPED_UNICODE),
            'status'       => 'queued',
        ]);
        try { (new PushService())->send($notifId); } catch (\Throwable $e) { /* best-effort */ }

        Response::json(['ok' => true]);
    }

    /** Caller (consultant) cancels before client responds. POST /api/calls/{invite_id}/cancel */
    public function cancel(Request $req): void
    {
        $invite = $this->loadInviteForCaller($req);
        $this->markResponded($invite, 'cancelled');

        WsBridge::pushToUser((int)$invite['to_user_id'], [
            'type'       => 'call.cancelled',
            'invite_id'  => (int)$invite['id'],
            'session_id' => (int)$invite['session_id'],
        ]);

        Response::json(['ok' => true]);
    }

    /**
     * GET /api/sessions/{id}/call/current — used by the app on launch / app
     * wake to determine if a call is *actually* ringing (so the ringtone is
     * tied to invite state, not session state).
     */
    public function current(Request $req): void
    {
        $sess = $this->loadSessionForMember($req);
        $row  = DB::one(
            "SELECT * FROM call_invites
              WHERE session_id = :sid AND status = 'ringing' AND expires_at > NOW()
              ORDER BY id DESC LIMIT 1",
            [':sid' => (int)$sess['id']]
        );
        if (!$row) {
            Response::json(['invite' => null]);
        }
        Response::json(['invite' => $this->serializeInvite($row)]);
    }

    /* ---------------------------------------------------------------- */

    private function loadSessionForConsultant(Request $req): array
    {
        if ($req->userRole() !== 'consultant' && $req->userRole() !== 'admin') {
            Response::error('forbidden', 403);
        }
        $id  = (int)$req->params['id'];
        $row = DB::one('SELECT * FROM sessions WHERE id = :id', [':id' => $id]);
        if (!$row) Response::error('not_found', 404);
        $isCons = DB::one(
            'SELECT 1 FROM consultants WHERE id = :cid AND user_id = :uid',
            [':cid' => (int)$row['consultant_id'], ':uid' => $req->userId()]
        );
        if (!$isCons && $req->userRole() !== 'admin') {
            Response::error('forbidden', 403);
        }
        return $row;
    }

    private function loadSessionForMember(Request $req): array
    {
        $id  = (int)$req->params['id'];
        $row = DB::one('SELECT * FROM sessions WHERE id = :id', [':id' => $id]);
        if (!$row) Response::error('not_found', 404);
        $isOwner = (int)$row['user_id'] === $req->userId();
        $isCons  = DB::one(
            'SELECT 1 FROM consultants WHERE id = :cid AND user_id = :uid',
            [':cid' => (int)$row['consultant_id'], ':uid' => $req->userId()]
        );
        if (!$isOwner && !$isCons && $req->userRole() !== 'admin') {
            Response::error('forbidden', 403);
        }
        return $row;
    }

    private function loadInviteForReceiver(Request $req): array
    {
        $invite = $this->loadInvite($req);
        if ((int)$invite['to_user_id'] !== $req->userId() && $req->userRole() !== 'admin') {
            Response::error('forbidden', 403);
        }
        return $invite;
    }

    private function loadInviteForCaller(Request $req): array
    {
        $invite = $this->loadInvite($req);
        if ((int)$invite['from_user_id'] !== $req->userId() && $req->userRole() !== 'admin') {
            Response::error('forbidden', 403);
        }
        return $invite;
    }

    private function loadInvite(Request $req): array
    {
        $id  = (int)$req->params['invite_id'];
        $row = DB::one('SELECT * FROM call_invites WHERE id = :id', [':id' => $id]);
        if (!$row) Response::error('not_found', 404);
        return $row;
    }

    private function markResponded(array $invite, string $newStatus): void
    {
        if ($invite['status'] !== 'ringing') {
            Response::error('invite_already_settled', 409, ['status' => $invite['status']]);
        }
        if (strtotime((string)$invite['expires_at']) < time()) {
            Response::error('invite_expired', 410);
        }
        DB::run(
            'UPDATE call_invites
                SET status = :s, responded_at = NOW()
              WHERE id = :id AND status = \'ringing\'',
            [':s' => $newStatus, ':id' => (int)$invite['id']]
        );
    }

    private function serializeInvite(array $row): array
    {
        return [
            'id'         => (int)$row['id'],
            'session_id' => (int)$row['session_id'],
            'from_user_id' => (int)$row['from_user_id'],
            'to_user_id'   => (int)$row['to_user_id'],
            'media'      => $row['media'],
            'status'     => $row['status'],
            'created_at' => date('c', strtotime((string)$row['created_at'])),
            'expires_at' => date('c', strtotime((string)$row['expires_at'])),
        ];
    }
}
