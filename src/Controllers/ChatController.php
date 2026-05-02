<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\AgoraTokenService;

final class ChatController
{
    public function list(Request $req): void
    {
        $sess = $this->ensureMember($req);
        $afterId = (int)($req->query['after_id'] ?? 0);

        $rows = DB::all(
            'SELECT id, sender_id, sender_role, body, attachment_url, read_at, created_at
             FROM messages
             WHERE session_id = :sid AND id > :aid
             ORDER BY id ASC
             LIMIT 500',
            [':sid' => $sess['id'], ':aid' => $afterId]
        );
        Response::json(['messages' => $rows]);
    }

    public function send(Request $req): void
    {
        $sess = $this->ensureMember($req);
        if (!in_array($sess['status'], ['confirmed', 'in_progress'], true)) {
            Response::error('session_not_active', 409);
        }
        $data = Validator::check($req->body, [
            'body'           => 'required|string|max:4000',
            'attachment_url' => 'string|max:255',
        ]);
        $role = $req->userRole() === 'consultant' ? 'consultant' : 'user';
        $msgId = DB::insert('messages', [
            'session_id'     => $sess['id'],
            'sender_id'      => $req->userId(),
            'sender_role'    => $role,
            'body'           => $data['body'],
            'attachment_url' => $data['attachment_url'] ?? null,
        ]);
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
