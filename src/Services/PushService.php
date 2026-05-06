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
        $projectId   = (string)App::config('push.fcm_project_id');
        $accountPath = (string)App::config('push.fcm_service_account_path');
        if ($projectId === '' || $accountPath === '' || !is_readable($accountPath)) {
            Logger::error('fcm_misconfigured', [
                'has_project'         => $projectId !== '',
                'service_account_set' => $accountPath !== '',
                'readable'            => $accountPath !== '' && is_readable($accountPath),
            ]);
            return false;
        }

        $token = $this->fcmAccessToken($accountPath);
        if (!$token) return false;

        $isCall = ($n['kind'] ?? '') === 'incoming_call';
        $data   = json_decode($n['payload_json'] ?? 'null', true) ?: [];

        // FCM v1 requires data values to be strings. Cast everything so
        // numeric ids and booleans pass through cleanly.
        $stringData = [];
        foreach ($data + ['title' => $n['title'], 'body' => $n['body'], 'kind' => $n['kind']] as $k => $v) {
            $stringData[$k] = is_scalar($v) ? (string)$v : json_encode($v, JSON_UNESCAPED_UNICODE);
        }

        $message = [
            'token'        => $tok['token'],
            'notification' => [
                'title' => $n['title'],
                'body'  => $n['body'],
            ],
            'data'    => $stringData,
            'android' => [
                'priority'     => 'high',
                'notification' => [
                    // Routing call notifications through the high-importance
                    // "incoming-call" channel is what makes the device ring
                    // even when the app is killed (data-only messages do
                    // nothing on a killed app for most OEMs).
                    'channel_id' => $isCall ? 'incoming-call' : 'default',
                    'sound'      => $isCall ? 'incoming_call' : 'default',
                ],
            ],
        ];
        if ($isCall) {
            $message['android']['ttl'] = '45s';
        }

        try {
            $c = new Client(['timeout' => 10, 'http_errors' => false]);
            $resp = $c->post(
                'https://fcm.googleapis.com/v1/projects/' . rawurlencode($projectId) . '/messages:send',
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $token,
                        'Content-Type'  => 'application/json',
                    ],
                    'json' => ['message' => $message],
                ]
            );
            $status = $resp->getStatusCode();
            if ($status >= 200 && $status < 300) {
                return true;
            }
            Logger::error('fcm_send_failed', [
                'http' => $status,
                'body' => substr((string)$resp->getBody(), 0, 500),
                'kind' => $n['kind'],
            ]);
            return false;
        } catch (\Throwable $e) {
            Logger::error('fcm_error', ['msg' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Exchange the service-account JSON for a short-lived OAuth2 access
     * token scoped to FCM. Cached on disk for 50 minutes (token TTL is 60).
     */
    private function fcmAccessToken(string $accountPath): ?string
    {
        $cacheFile = sys_get_temp_dir() . '/fcm_token_' . md5($accountPath) . '.json';
        if (is_readable($cacheFile)) {
            $cached = json_decode((string)@file_get_contents($cacheFile), true);
            if (is_array($cached) && ($cached['exp'] ?? 0) > time() + 60) {
                return (string)$cached['token'];
            }
        }

        $sa = json_decode((string)@file_get_contents($accountPath), true);
        if (!is_array($sa) || empty($sa['client_email']) || empty($sa['private_key'])) {
            Logger::error('fcm_service_account_invalid', ['path' => $accountPath]);
            return null;
        }

        $now = time();
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claim = [
            'iss'   => $sa['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'iat'   => $now,
            'exp'   => $now + 3600,
        ];
        $b64 = static fn(string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $signingInput = $b64(json_encode($header)) . '.' . $b64(json_encode($claim));

        $key = openssl_pkey_get_private((string)$sa['private_key']);
        if (!$key) {
            Logger::error('fcm_private_key_load_failed', []);
            return null;
        }
        $sig = '';
        if (!openssl_sign($signingInput, $sig, $key, OPENSSL_ALGO_SHA256)) {
            Logger::error('fcm_sign_failed', []);
            return null;
        }
        $assertion = $signingInput . '.' . $b64($sig);

        try {
            $c = new Client(['timeout' => 10, 'http_errors' => false]);
            $resp = $c->post('https://oauth2.googleapis.com/token', [
                'form_params' => [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion'  => $assertion,
                ],
            ]);
            $body = json_decode((string)$resp->getBody(), true) ?: [];
            if ($resp->getStatusCode() >= 300 || empty($body['access_token'])) {
                Logger::error('fcm_token_exchange_failed', [
                    'http' => $resp->getStatusCode(),
                    'body' => substr((string)$resp->getBody(), 0, 300),
                ]);
                return null;
            }
            $token = (string)$body['access_token'];
            @file_put_contents($cacheFile, json_encode([
                'token' => $token,
                'exp'   => $now + (int)($body['expires_in'] ?? 3600),
            ]));
            @chmod($cacheFile, 0600);
            return $token;
        } catch (\Throwable $e) {
            Logger::error('fcm_token_exception', ['msg' => $e->getMessage()]);
            return null;
        }
    }

    private function sendApns(array $n, array $tok): bool
    {
        $cfg = App::config('push');
        $keyPath = (string)$cfg['apns_key_path'];
        if (!$keyPath || !file_exists($keyPath) || !$cfg['apns_key_id'] || !$cfg['apns_team_id']) {
            Logger::error('apns_misconfigured', [
                'has_key' => $keyPath && file_exists($keyPath),
                'has_kid' => !empty($cfg['apns_key_id']),
                'has_tid' => !empty($cfg['apns_team_id']),
            ]);
            return false;
        }
        try {
            $jwt = $this->apnsJwt($cfg['apns_key_id'], $cfg['apns_team_id'], $keyPath);
            if (!$jwt) return false;
            $bundle = (string)$cfg['apns_bundle_id'];

            $isCall = ($n['kind'] ?? '') === 'incoming_call';
            $extra  = json_decode($n['payload_json'] ?? 'null', true) ?: [];

            $aps = [
                'alert' => ['title' => $n['title'], 'body' => $n['body']],
                'sound' => $isCall ? 'ringtone.caf' : 'default',
            ];
            if ($isCall) {
                $aps['interruption-level'] = 'time-sensitive';
                $aps['relevance-score']    = 1.0;
            }

            $payload = json_encode([
                'aps'  => $aps,
                'data' => $extra + ['kind' => $n['kind']],
            ], JSON_UNESCAPED_UNICODE);

            // TestFlight + dev-client builds register against APNs sandbox;
            // production builds use the production gateway. We don't know
            // which environment a device registered in, so try production
            // first and fall back to sandbox on BadDeviceToken (400).
            $forceSandbox = filter_var($cfg['apns_sandbox'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $hosts = $forceSandbox
                ? ['https://api.sandbox.push.apple.com']
                : ['https://api.push.apple.com', 'https://api.sandbox.push.apple.com'];

            foreach ($hosts as $i => $host) {
                $url = $host . '/3/device/' . rawurlencode($tok['token']);
                $ch  = curl_init($url);
                $respHeaders = [];
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
                    CURLOPT_HEADERFUNCTION => function ($_c, $h) use (&$respHeaders) {
                        $respHeaders[] = $h; return strlen($h);
                    },
                ]);
                $body = (string)curl_exec($ch);
                $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $err  = curl_error($ch);
                curl_close($ch);

                if ($code >= 200 && $code < 300) {
                    return true;
                }

                $reason = '';
                if ($body !== '') {
                    $j = json_decode($body, true);
                    $reason = is_array($j) ? (string)($j['reason'] ?? '') : '';
                }
                Logger::error('apns_send_failed', [
                    'host'    => $host,
                    'http'    => $code,
                    'reason'  => $reason,
                    'curl'    => $err,
                    'bundle'  => $bundle,
                    'payload_kind' => $n['kind'] ?? null,
                ]);

                // Keep going only if the failure looks like a wrong-env token.
                $envMismatch = ($code === 400 && $reason === 'BadDeviceToken')
                    || ($code === 410 && $reason === 'Unregistered');
                if (!$envMismatch || $i === count($hosts) - 1) {
                    return false;
                }
            }
            return false;
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
        // ECDSA DER → raw r||s for ES256.
        //
        // DER layout: SEQUENCE { INTEGER r, INTEGER s } i.e.
        //   30 [total-len] 02 [r-len] [r…] 02 [s-len] [s…]
        // Start at the INTEGER tag for r (offset 2 for single-byte
        // total-length; adjust upward when multi-byte). The previous
        // version used offset 4 which over-shot into the r value and
        // produced 96-byte garbage signatures that Apple rejected with
        // InvalidProviderToken.
        $offset = 2;
        if (ord($der[1]) > 0x80) $offset += ord($der[1]) - 0x80;
        $rLen = ord($der[$offset + 1]);
        $r = substr($der, $offset + 2, $rLen);
        $sLen = ord($der[$offset + 2 + $rLen + 1]);
        $s = substr($der, $offset + 2 + $rLen + 2, $sLen);
        // DER encodes positive integers with a leading 0x00 byte when the
        // high bit is set; JOSE expects fixed-width 32-byte halves, so
        // strip any 0x00 padding and left-pad back to 32.
        $r = ltrim($r, "\x00");
        $s = ltrim($s, "\x00");
        return str_pad($r, 32, "\x00", STR_PAD_LEFT) . str_pad($s, 32, "\x00", STR_PAD_LEFT);
    }
}
