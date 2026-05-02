<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\DB;
use App\Core\Logger;
use GuzzleHttp\Client;

/**
 * Sends push notifications via FCM (Android/Web) and APNs HTTP/2 (iOS).
 * Records the result back into the `notifications` table.
 */
final class PushService
{
    public function send(int $notificationId): bool
    {
        $n = DB::one(
            'SELECT n.*, u.push_token, u.push_platform
             FROM notifications n JOIN users u ON u.id = n.user_id
             WHERE n.id = :id',
            [':id' => $notificationId]
        );
        if (!$n || empty($n['push_token'])) {
            DB::run("UPDATE notifications SET status = 'failed' WHERE id = :id", [':id' => $notificationId]);
            return false;
        }

        $ok = match ($n['push_platform']) {
            'ios'     => $this->sendApns($n),
            'android' => $this->sendFcm($n),
            'web'     => $this->sendFcm($n),
            default   => false,
        };

        DB::run(
            'UPDATE notifications SET status = :s, sent_at = NOW() WHERE id = :id',
            [':s' => $ok ? 'sent' : 'failed', ':id' => $notificationId]
        );
        return $ok;
    }

    private function sendFcm(array $n): bool
    {
        $key = (string)App::config('push.fcm_server_key');
        if ($key === '') return false;
        try {
            $c = new Client(['timeout' => 10]);
            $c->post('https://fcm.googleapis.com/fcm/send', [
                'headers' => [
                    'Authorization' => 'key=' . $key,
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'to' => $n['push_token'],
                    'notification' => [
                        'title' => $n['title'],
                        'body'  => $n['body'],
                    ],
                    'data' => json_decode($n['payload_json'] ?? 'null', true) ?: [],
                ],
            ]);
            return true;
        } catch (\Throwable $e) {
            Logger::error('fcm_error', ['msg' => $e->getMessage()]);
            return false;
        }
    }

    private function sendApns(array $n): bool
    {
        $cfg = App::config('push');
        $keyPath = (string)$cfg['apns_key_path'];
        if (!$keyPath || !file_exists($keyPath) || !$cfg['apns_key_id'] || !$cfg['apns_team_id']) {
            return false;
        }
        try {
            $jwt = $this->apnsJwt($cfg['apns_key_id'], $cfg['apns_team_id'], $keyPath);
            if (!$jwt) return false;
            $bundle = $cfg['apns_bundle_id'];
            $url = 'https://api.push.apple.com/3/device/' . rawurlencode($n['push_token']);
            $payload = json_encode([
                'aps' => [
                    'alert' => ['title' => $n['title'], 'body' => $n['body']],
                    'sound' => 'default',
                ],
                'data' => json_decode($n['payload_json'] ?? 'null', true) ?: [],
            ], JSON_UNESCAPED_UNICODE);

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0,
                CURLOPT_HTTPHEADER => [
                    'authorization: bearer ' . $jwt,
                    'apns-topic: ' . $bundle,
                    'apns-push-type: alert',
                    'content-type: application/json',
                ],
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
            ]);
            curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return $code >= 200 && $code < 300;
        } catch (\Throwable $e) {
            Logger::error('apns_error', ['msg' => $e->getMessage()]);
            return false;
        }
    }

    private function apnsJwt(string $kid, string $teamId, string $keyPath): ?string
    {
        $header = ['alg' => 'ES256', 'kid' => $kid];
        $claim  = ['iss' => $teamId, 'iat' => time()];
        $b64 = static fn(string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $signingInput = $b64(json_encode($header)) . '.' . $b64(json_encode($claim));

        $key = openssl_pkey_get_private('file://' . $keyPath);
        if (!$key) return null;
        $sig = '';
        if (!openssl_sign($signingInput, $sig, $key, OPENSSL_ALGO_SHA256)) return null;
        // ECDSA in PHP returns DER; APNs expects raw r||s
        $sig = self::derToJose($sig);
        return $signingInput . '.' . $b64($sig);
    }

    private static function derToJose(string $der): string
    {
        // Minimal DER ECDSA -> raw 64-byte conversion
        $offset = 4;
        if (ord($der[1]) > 0x80) $offset += ord($der[1]) - 0x80;
        $rLen = ord($der[$offset + 1]);
        $r = substr($der, $offset + 2, $rLen);
        $sLen = ord($der[$offset + 2 + $rLen + 1]);
        $s = substr($der, $offset + 2 + $rLen + 2, $sLen);
        $r = ltrim($r, "\x00");
        $s = ltrim($s, "\x00");
        return str_pad($r, 32, "\x00", STR_PAD_LEFT) . str_pad($s, 32, "\x00", STR_PAD_LEFT);
    }
}
