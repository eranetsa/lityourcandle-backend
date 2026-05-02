<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Core\Request;
use App\Core\Response;

final class NotificationController
{
    public function list(Request $req): void
    {
        $rows = DB::all(
            'SELECT id, kind, title, body, payload_json, scheduled_at, sent_at, read_at, status, created_at
             FROM notifications
             WHERE user_id = :uid
             ORDER BY id DESC LIMIT 100',
            [':uid' => $req->userId()]
        );
        Response::json(['notifications' => $rows]);
    }

    public function markRead(Request $req): void
    {
        $id = (int)$req->params['id'];
        DB::run(
            "UPDATE notifications SET read_at = NOW(), status = 'read'
             WHERE id = :id AND user_id = :uid",
            [':id' => $id, ':uid' => $req->userId()]
        );
        Response::json(['ok' => true]);
    }
}
