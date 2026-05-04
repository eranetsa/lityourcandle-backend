<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

/**
 * Bridge from synchronous HTTP request handlers into the long-running
 * WebSocket daemon ({@see websocket/server.php}).
 *
 * The HTTP backend can't reach the daemon's in-memory connection map
 * directly, so we drop frames into the `ws_outbox` table. The daemon polls
 * the table every ~500 ms, fans matching frames out to live connections,
 * and marks rows processed.
 *
 * This decouples the call signalling protocol (which needs sub-second
 * delivery, but is fine with ~500 ms) from PHP-FPM workers, and survives
 * a daemon restart with at-least-once semantics.
 */
final class WsBridge
{
    /** Send a frame to every live connection of a single user. */
    public static function pushToUser(int $userId, array $frame): void
    {
        self::enqueue('user', $userId, $frame);
    }

    /** Send a frame to every live connection currently bound to a session room. */
    public static function pushToSession(int $sessionId, array $frame): void
    {
        self::enqueue('session', $sessionId, $frame);
    }

    private static function enqueue(string $targetType, int $targetId, array $frame): void
    {
        DB::insert('ws_outbox', [
            'target_type' => $targetType,
            'target_id'   => $targetId,
            'frame_json'  => json_encode($frame, JSON_UNESCAPED_UNICODE),
        ]);
    }
}
