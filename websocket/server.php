<?php
declare(strict_types=1);

/**
 * Ratchet WebSocket server for real-time chat AND call signalling.
 *
 * Connect:
 *   wss://backend.lityourcandle.com/ws?token=<JWT>&session_id=<id>     ← in-room
 *   wss://backend.lityourcandle.com/ws?token=<JWT>                     ← presence
 *
 * Behind Apache use mod_proxy_wstunnel:
 *   ProxyPass        /ws  ws://127.0.0.1:8081/
 *   ProxyPassReverse /ws  ws://127.0.0.1:8081/
 *
 * Client → server JSON:
 *   {"type":"message","body":"..."}
 *   {"type":"typing"}
 *   {"type":"read","message_id":n}
 *
 * Server → client JSON:
 *   {"type":"message", "id":..., "sender_role":"...", "body":"...", "created_at":"..."}
 *   {"type":"typing", ...}, {"type":"read", ...}
 *   Call frames (delivered via ws_outbox poller):
 *     {"type":"call.incoming",   "invite_id":..., "session_id":..., "media":..., "from":{...}, "expires_at":...}
 *     {"type":"call.accepted",   "invite_id":..., "session_id":...}
 *     {"type":"call.declined",   "invite_id":..., "session_id":...}
 *     {"type":"call.cancelled",  "invite_id":..., "session_id":...}
 *     {"type":"call.missed",     "invite_id":..., "session_id":...}
 *
 * Cross-process bridging:
 *   HTTP request handlers can't reach this daemon's in-memory connection
 *   map directly. They write rows to `ws_outbox`; we poll every ~500ms
 *   and dispatch. Periodic timer also sweeps expired ringing invites and
 *   marks them missed (broadcasting `call.missed` to both parties).
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\App;
use App\Core\Auth;
use App\Core\DB;
use App\Services\PushService;
use Ratchet\ConnectionInterface;
use Ratchet\Http\HttpServer;
use Ratchet\MessageComponentInterface;
use Ratchet\Server\IoServer;
use Ratchet\WebSocket\WsServer;
use React\EventLoop\Loop;
use React\Socket\SocketServer;

App::boot(dirname(__DIR__));

final class CandleChatServer implements MessageComponentInterface
{
    /** @var \SplObjectStorage<ConnectionInterface, array{user_id:int,role:string,session_id:int}> */
    private \SplObjectStorage $clients;

    public function __construct()
    {
        $this->clients = new \SplObjectStorage();
    }

    public function onOpen(ConnectionInterface $conn): void
    {
        $query = [];
        parse_str($conn->httpRequest->getUri()->getQuery() ?? '', $query);
        $token     = (string)($query['token'] ?? '');
        // session_id is optional now: a connection without it is "presence
        // only" — it can receive user-targeted frames (incoming calls etc.)
        // but isn't part of any chat room.
        $sessionId = (int)($query['session_id'] ?? 0);

        $claims = Auth::verify($token);
        if (!$claims) {
            $conn->send(json_encode(['type' => 'error', 'error' => 'unauthenticated']));
            $conn->close();
            return;
        }

        $role = (string)($claims['role'] ?? 'user');
        if ($sessionId > 0) {
            $sess = DB::one('SELECT user_id, consultant_id FROM sessions WHERE id = :id', [':id' => $sessionId]);
            if (!$sess) {
                $conn->send(json_encode(['type' => 'error', 'error' => 'not_found']));
                $conn->close();
                return;
            }
            $isOwner = (int)$sess['user_id'] === (int)$claims['sub'];
            $isCons  = DB::one(
                'SELECT 1 FROM consultants WHERE id = :cid AND user_id = :uid',
                [':cid' => $sess['consultant_id'], ':uid' => $claims['sub']]
            );
            if (!$isOwner && !$isCons && $role !== 'admin') {
                $conn->send(json_encode(['type' => 'error', 'error' => 'forbidden']));
                $conn->close();
                return;
            }
            $role = $isCons ? 'consultant' : $role;
        }

        $this->clients[$conn] = [
            'user_id'    => (int)$claims['sub'],
            'role'       => $role,
            'session_id' => $sessionId,
        ];
        $conn->send(json_encode(['type' => 'ready']));
    }

    public function onMessage(ConnectionInterface $from, $msg): void
    {
        $info = $this->clients[$from] ?? null;
        if (!$info) { $from->close(); return; }

        $data = json_decode((string)$msg, true) ?: [];
        $type = $data['type'] ?? '';

        // Heartbeat — clients send this so we can keep last_seen_at fresh.
        if ($type === 'ping') {
            $from->send(json_encode(['type' => 'pong', 'ts' => time()]));
            return;
        }

        // The chat protocol below requires a session-bound connection.
        $sid = $info['session_id'];
        if ($sid <= 0) return;

        if ($type === 'message') {
            $body = trim((string)($data['body'] ?? ''));
            if ($body === '' || mb_strlen($body) > 4000) return;
            $senderRole = $info['role'] === 'consultant' ? 'consultant' : 'user';
            $msgId = DB::insert('messages', [
                'session_id'  => $sid,
                'sender_id'   => $info['user_id'],
                'sender_role' => $senderRole,
                'body'        => $body,
            ]);
            $payload = [
                'type'        => 'message',
                'id'          => $msgId,
                'sender_id'   => $info['user_id'],
                'sender_role' => $senderRole,
                'body'        => $body,
                'created_at'  => date('c'),
            ];
            $this->broadcastToSession($sid, $payload);

            // Push the message to the recipient if they aren't already
            // connected to this chat room. When both sides are in-room
            // the WS broadcast above already delivers it, so a push
            // would be redundant noise (and would also play the chat
            // banner on top of the open chat screen).
            $this->maybePushChatMessage($sid, $info['user_id'], $senderRole, $msgId, $body);
            return;
        }

        if ($type === 'typing') {
            $this->broadcastToSession($sid, [
                'type' => 'typing', 'sender_id' => $info['user_id'],
            ], $from);
            return;
        }

        if ($type === 'read' && !empty($data['message_id'])) {
            DB::run(
                'UPDATE messages SET read_at = NOW()
                 WHERE session_id = :sid AND id <= :mid AND sender_id != :uid',
                [':sid' => $sid, ':mid' => (int)$data['message_id'], ':uid' => $info['user_id']]
            );
            $this->broadcastToSession($sid, [
                'type' => 'read', 'message_id' => (int)$data['message_id'],
            ]);
        }
    }

    public function onClose(ConnectionInterface $conn): void
    {
        $this->clients->detach($conn);
    }

    public function onError(ConnectionInterface $conn, \Exception $e): void
    {
        $conn->close();
    }

    /**
     * Queue + send an APNs/FCM push for a chat message, but only if the
     * recipient has no live WebSocket connection on this session. If they
     * are connected they already got the message via `broadcastToSession`.
     */
    private function maybePushChatMessage(int $sessionId, int $senderUserId, string $senderRole, int $msgId, string $body): void
    {
        try {
            $sess = DB::one(
                'SELECT s.user_id AS client_user_id, c.user_id AS consultant_user_id, c.name AS consultant_name
                   FROM sessions s
                   LEFT JOIN consultants c ON c.id = s.consultant_id
                  WHERE s.id = :sid',
                [':sid' => $sessionId]
            );
            if (!$sess) return;

            if ($senderRole === 'consultant') {
                $recipientUserId = (int)$sess['client_user_id'];
                $senderName = (string)($sess['consultant_name'] ?? 'المستشار');
            } else {
                $recipientUserId = (int)($sess['consultant_user_id'] ?? 0);
                $u = DB::one('SELECT name FROM users WHERE id = :id', [':id' => $senderUserId]);
                $senderName = (string)($u['name'] ?? 'العميل');
            }
            if ($recipientUserId <= 0 || $recipientUserId === $senderUserId) return;

            // Skip the push if the recipient already has a live socket
            // attached to this session — they're seeing the chat in real
            // time and don't need a banner on top.
            foreach ($this->clients as $conn) {
                $info = $this->clients[$conn];
                if ($info['user_id'] === $recipientUserId && $info['session_id'] === $sessionId) {
                    return;
                }
            }

            $preview = mb_substr($body, 0, 100);
            if (mb_strlen($body) > 100) $preview .= '…';

            $notifId = DB::insert('notifications', [
                'user_id'      => $recipientUserId,
                'kind'         => 'chat_message',
                'title'        => $senderName,
                'body'         => $preview,
                'payload_json' => json_encode([
                    'type'        => 'chat_message',
                    'session_id'  => $sessionId,
                    'message_id'  => $msgId,
                    'sender_role' => $senderRole,
                ], JSON_UNESCAPED_UNICODE),
                'status'       => 'queued',
            ]);
            (new PushService())->send($notifId);
        } catch (\Throwable $e) {
            // Best-effort: never let a push failure break the chat path.
        }
    }

    public function broadcastToSession(int $sessionId, array $payload, ?ConnectionInterface $except = null): void
    {
        $msg = json_encode($payload, JSON_UNESCAPED_UNICODE);
        foreach ($this->clients as $client) {
            $info = $this->clients[$client];
            if ($info['session_id'] !== $sessionId) continue;
            if ($except && $client === $except) continue;
            $client->send($msg);
        }
    }

    public function broadcastToUser(int $userId, array $payload): void
    {
        $msg = json_encode($payload, JSON_UNESCAPED_UNICODE);
        foreach ($this->clients as $client) {
            $info = $this->clients[$client];
            if ($info['user_id'] !== $userId) continue;
            $client->send($msg);
        }
    }

    /**
     * Drains pending frames from the ws_outbox table. Call this on a
     * periodic timer (~500 ms) — the HTTP API uses the table to push
     * frames cross-process.
     */
    public function drainOutbox(): void
    {
        try {
            $rows = DB::all(
                'SELECT id, target_type, target_id, frame_json
                   FROM ws_outbox
                  WHERE processed_at IS NULL
                  ORDER BY id ASC
                  LIMIT 200'
            );
        } catch (\Throwable $e) {
            error_log('[ws-outbox] query error: ' . $e->getMessage());
            return;
        }

        foreach ($rows as $row) {
            $frame = json_decode((string)$row['frame_json'], true) ?: [];
            if (!$frame) {
                DB::run('UPDATE ws_outbox SET processed_at = NOW() WHERE id = :id', [':id' => (int)$row['id']]);
                continue;
            }
            if ($row['target_type'] === 'user') {
                $this->broadcastToUser((int)$row['target_id'], $frame);
            } elseif ($row['target_type'] === 'session') {
                $this->broadcastToSession((int)$row['target_id'], $frame);
            }
            DB::run('UPDATE ws_outbox SET processed_at = NOW() WHERE id = :id', [':id' => (int)$row['id']]);
        }

        // Prune anything fully processed > 1h ago to keep the table small.
        DB::run("DELETE FROM ws_outbox WHERE processed_at IS NOT NULL AND processed_at < (NOW() - INTERVAL 1 HOUR)");
    }

    /**
     * Sweeps `call_invites` for ringing rows whose expiry has passed,
     * marking them `missed` and broadcasting `call.missed` to both
     * parties. Runs on a periodic timer; the spec asks for ~10s cadence.
     */
    public function sweepMissedInvites(): void
    {
        try {
            $rows = DB::all(
                "SELECT id, session_id, from_user_id, to_user_id, media
                   FROM call_invites
                  WHERE status = 'ringing' AND expires_at < NOW()
                  ORDER BY id ASC LIMIT 100"
            );
        } catch (\Throwable $e) {
            error_log('[invite-sweep] query error: ' . $e->getMessage());
            return;
        }

        foreach ($rows as $r) {
            $upd = DB::run(
                "UPDATE call_invites
                    SET status = 'missed', responded_at = NOW()
                  WHERE id = :id AND status = 'ringing'",
                [':id' => (int)$r['id']]
            );
            if (!$upd) continue;

            $frame = [
                'type'       => 'call.missed',
                'invite_id'  => (int)$r['id'],
                'session_id' => (int)$r['session_id'],
            ];
            $this->broadcastToUser((int)$r['from_user_id'], $frame);
            $this->broadcastToUser((int)$r['to_user_id'],   $frame);

            // Surface the missed call in the receiver's notification feed
            // so it shows up next time they open the app.
            DB::insert('notifications', [
                'user_id'      => (int)$r['to_user_id'],
                'kind'         => 'missed_call',
                'title'        => 'مكالمة فائتة',
                'body'         => 'فاتتك مكالمة من المستشار. اضغط للتواصل.',
                'payload_json' => json_encode([
                    'type'       => 'missed_call',
                    'invite_id'  => (int)$r['id'],
                    'session_id' => (int)$r['session_id'],
                    'media'      => $r['media'],
                ], JSON_UNESCAPED_UNICODE),
                'status'       => 'queued',
            ]);
        }
    }
}

$port = (int)($_ENV['WS_PORT'] ?? 8081);

$loop   = Loop::get();
$socket = new SocketServer("0.0.0.0:$port", [], $loop);

$app = new CandleChatServer();
$server = new IoServer(new HttpServer(new WsServer($app)), $socket, $loop);

// Cross-process push: HTTP handlers write to ws_outbox; we drain it.
// 0.5s feels real-time enough for ringing without hammering MySQL.
$loop->addPeriodicTimer(0.5, fn() => $app->drainOutbox());

// Missed-call sweep: ringing invites past their expires_at → missed.
$loop->addPeriodicTimer(10.0, fn() => $app->sweepMissedInvites());

echo "Candle WebSocket listening on :$port" . PHP_EOL;
$loop->run();
