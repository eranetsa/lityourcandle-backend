<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\DB;
use App\Core\Logger;
use GuzzleHttp\Client;

/**
 * Sends push notifications via FCM (Android/Web) and APNs HTTP/2 (iOS).
 *
 * For incoming-call notifications (`kind = 'incoming_call'`) we ramp the
 * payload up so it actually wakes the device:
 *   • iOS:     interruption-level=time-sensitive, custom sound `ringtone.caf`,
 *              priority 10. (PushKit / VoIP would be ideal but requires
 *              CallKit integration on the client; this is the best we can
 *              do with a regular alert push.)
 *   • Android: priority=high data-only message routed to the "calls"
 *              notification channel with vibration + bypass-DND.
 *
 * Tokens are fanned out across every row in `push_tokens` for the user
 * (multi-device). For backwards compatibility we still fall through to
 * the legacy `users.push_token` column when no rows exist.
 */
final class PushService
{
    public function send(int $notificationId): bool
    {
        $n = DB::one(
            'SELECT * FROM notifications WHERE id = :id',
            [':id' => $notificationId]
        );
        if (!$n) {
            return false;
        }

        $tokens = $this->resolveTokens((int)$n['user_id']);
        if (empty($tokens)) {
            DB::run("UPDATE notifications SET status = 'failed' WHERE id = :id", [':id' => $notificationId]);
            return false;
        }

        $anyOk = false;
        foreach ($tokens as $tok) {
            $ok = match ($tok['platform']) {
                'ios'     => $this->sendApns($n, $tok),
                'android' => $this->sendFcm($n, $tok),
                'web'     => $this->sendFcm($n, $tok),
                default   => false,
            };
            $anyOk = $anyOk || $ok;
        }

        DB::run(
            'UPDATE notifications SET status = :s, sent_at = NOW() WHERE id = :id',
            [':s' => $anyOk ? 'sent' : 'failed', ':id' => $notificationId]
        );
        return $anyOk;
    }

    /**
     * @return list<array{token:string, platform:string, voip_token:?string}>
     */
    private function resolveTokens(int $userId): array
    {
        $rows = DB::all(
            'SELECT token, platform, voip_token FROM push_tokens WHERE user_id = :uid',
            [':uid' => $userId]
        );
        if ($rows) {
            return $rows;
        }
        // Backwards compat: legacy single-token column on `users`.
        $u = DB::one('SELECT push_token, push_platform FROM users WHERE id = :id', [':id' => $userId]);
        if ($u && !empty($u['push_token']) && !empty($u['push_platform'])) {
            return [[
                'token'      => $u['push_token'],
                'platform'   => $u['push_platform'],
                'voip_token' => null,
            ]];
        }
        return [];
    }

    private function sendFcm(array $n, array $tok): bool
    {
        $key = (string)App::config('push.fcm_server_key');
        if ($key === '') return false;
        $isCall = ($n['kind'] ?? '') === 'incoming_call';
        $data   = json_decode($n['payload_json'] ?? 'null', true) ?: [];

        try {
            $body = [
                'to'       => $tok['token'],
                'priority' => 'high',
                'data'     => $data + [
                    'title' => $n['title'],
                    'body'  => $n['body'],
                    'kind'  => $n['kind'],
                ],
                'notification' => [
                    'title' => $n['title'],
                    'body'  => $n['body'],
                    // Routing call notifications through the high-importance
                    // "incoming-call" channel is what makes the device ring
                    // even when the app is killed (data-only messages do
                    // nothing on a killed app for most OEMs).
                    'sound'              => $isCall ? 'incoming_call' : 'default',
                    'android_channel_id' => $isCall ? 'incoming-call' : 'default',
                ],
            ];
            if ($isCall) {
                $body['android'] = [
                    'priority' => 'high',
                    'ttl'      => '45s',
                ];
            }

            $c = new Client(['timeout' => 10]);
            $c->post('https://fcm.googleapis.com/fcm/send', [
                'headers' => [
                    'Authorization' => 'key=' . $key,
                    'Content-Type'  => 'application/json',
                ],
                'json' => $body,
            ]);
            return true;
        } catch (\Throwable $e) {
            Logger::error('fcm_error', ['msg' => $e->getMessage()]);
            return false;
        }
    }

    private function sendApns(array $n, array $tok): bool
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

            $isCall = ($n['kind'] ?? '') === 'incoming_call';
            $extra  = json_decode($n['payload_json'] ?? 'null', true) ?: [];

            $aps = [
                'alert' => ['title' => $n['title'], 'body' => $n['body']],
                'sound' => $isCall ? 'ringtone.caf' : 'default',
            ];
            if ($isCall) {
                $aps['interruption-level'] = 'time-sensitive';
                // Push the alert through even if Focus is on.
                $aps['relevance-score']    = 1.0;
            }

            $payload = json_encode([
                'aps'  => $aps,
                'data' => $extra + ['kind' => $n['kind']],
            ], JSON_UNESCAPED_UNICODE);

            $url = 'https://api.push.apple.com/3/device/' . rawurlencode($tok['token']);
            $ch  = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0,
                CURLOPT_HTTPHEADER => [
                    'authorization: bearer ' . $jwt,
                    'apns-topic: ' . $bundle,
                    'apns-push-type: alert',
                    'apns-priority: ' . ($isCall ? '10' : '5'),
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
