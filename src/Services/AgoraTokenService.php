<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;

/**
 * Agora RTC token (AccessToken2) builder.
 * Implements the `006` legacy token format which is sufficient for SDK auth in
 * most setups; for production consider switching to AccessToken2.
 */
final class AgoraTokenService
{
    public function buildRtcToken(string $channel, int $uid, int $ttlSeconds = 3600, string $role = 'publisher'): array
    {
        $appId  = (string)App::config('agora.app_id');
        $appCert= (string)App::config('agora.app_certificate');
        if (!$appId || !$appCert) {
            return ['token' => null, 'app_id' => $appId, 'note' => 'agora_not_configured'];
        }

        $expireTs = time() + $ttlSeconds;
        $salt     = random_int(1, PHP_INT_MAX);
        $roleByte = $role === 'publisher' ? 1 : 2;

        // Privileges (from Agora's spec): join_channel = 1, publish_audio = 2, publish_video = 3, publish_data = 4
        $privileges = [1 => $expireTs, 2 => $expireTs, 3 => $expireTs, 4 => $expireTs];

        // Build the message
        $msg  = pack('V', $salt) . pack('V', $expireTs);
        $msg .= pack('v', count($privileges));
        foreach ($privileges as $k => $v) {
            $msg .= pack('v', $k) . pack('V', $v);
        }

        $toSign = $msg . $appId . $channel . (string)$uid;
        $sig = hash_hmac('sha256', $toSign, $appCert, true);

        $content = pack('n', strlen($sig)) . $sig . pack('V', crc32($channel)) . pack('V', crc32((string)$uid)) . $msg;
        $token = '006' . $appId . base64_encode($content);

        return [
            'token'      => $token,
            'app_id'     => $appId,
            'channel'    => $channel,
            'uid'        => $uid,
            'role'       => $role,
            'expires_at' => date('c', $expireTs),
        ];
    }
}
