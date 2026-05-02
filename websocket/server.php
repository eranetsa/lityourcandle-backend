<?php
declare(strict_types=1);

/**
 * Ratchet WebSocket server for real-time chat in active sessions.
 *
 * Connect:   wss://backend.lityourcandle.com/ws?token=<JWT>&session_id=<id>
 * Behind Apache use mod_proxy_wstunnel:
 *   ProxyPass        /ws  ws://127.0.0.1:8081/
 *   ProxyPassReverse /ws  ws://127.0.0.1:8081/
 *
 * Client → server JSON:  {"type":"message","body":"..."}     | {"type":"typing"} | {"type":"read","message_id":n}
 * Server → client JSON:  {"type":"message", "id":..., "sender_role":"...", "body":"...", "created_at":"..."}
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\App;
use App\Core\Auth;
use App\Core\DB;
use Ratchet\ConnectionInterface;
use Ratchet\Http\HttpServer;
use Ratchet\MessageComponentInterface;
use Ratchet\Server\IoServer;
use Ratchet\WebSocket\WsServer;

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
        $token = $query['token'] ?? '';
        $sessionId = (int)($query['session_id'] ?? 0);

        $claims = Auth::verify((string)$token);
        if (!$claims || !$sessionId) {
            $conn->send(json_encode(['type' => 'error', 'error' => 'unauthenticated']));
            $conn->close();
            return;
        }

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
        if (!$isOwner && !$isCons && ($claims['role'] ?? '') !== 'admin') {
            $conn->send(json_encode(['type' => 'error', 'error' => 'forbidden']));
            $conn->close();
            return;
        }

        $this->clients[$conn] = [
            'user_id'    => (int)$claims['sub'],
            'role'       => $isCons ? 'consultant' : (string)$claims['role'],
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

        if ($type === 'message') {
            $body = trim((string)($data['body'] ?? ''));
            if ($body === '' || mb_strlen($body) > 4000) return;
            $msgId = DB::insert('messages', [
                'session_id'  => $info['session_id'],
                'sender_id'   => $info['user_id'],
                'sender_role' => $info['role'] === 'consultant' ? 'consultant' : 'user',
                'body'        => $body,
            ]);
            $payload = [
                'type'        => 'message',
                'id'          => $msgId,
                'sender_id'   => $info['user_id'],
                'sender_role' => $info['role'] === 'consultant' ? 'consultant' : 'user',
                'body'        => $body,
                'created_at'  => date('c'),
            ];
            $this->broadcast($info['session_id'], $payload);
            return;
        }

        if ($type === 'typing') {
            $this->broadcast($info['session_id'], [
                'type' => 'typing', 'sender_id' => $info['user_id'],
            ], $from);
            return;
        }

        if ($type === 'read' && !empty($data['message_id'])) {
            DB::run(
                'UPDATE messages SET read_at = NOW()
                 WHERE session_id = :sid AND id <= :mid AND sender_id != :uid',
                [':sid' => $info['session_id'], ':mid' => (int)$data['message_id'], ':uid' => $info['user_id']]
            );
            $this->broadcast($info['session_id'], [
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

    private function broadcast(int $sessionId, array $payload, ?ConnectionInterface $except = null): void
    {
        $msg = json_encode($payload, JSON_UNESCAPED_UNICODE);
        foreach ($this->clients as $client) {
            $info = $this->clients[$client];
            if ($info['session_id'] !== $sessionId) continue;
            if ($except && $client === $except) continue;
            $client->send($msg);
        }
    }
}

$port = (int)($_ENV['WS_PORT'] ?? 8081);
$server = IoServer::factory(new HttpServer(new WsServer(new CandleChatServer())), $port, '0.0.0.0');
echo "Candle WebSocket listening on :$port" . PHP_EOL;
$server->run();
