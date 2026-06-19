<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\DB;
use App\Core\Logger;
use GuzzleHttp\Client;

/**
 * Sends push notifications via FCM v1 (Android/Web) and APNs HTTP/2 (iOS).
 *
 * iOS dispatch splits between TWO topics on the same APNs endpoint using the
 * same .p8 token-auth JWT (token-auth is environment- and topic-agnostic —
 * only the apns-topic header differs):
 *
 *   • sendApnsAlert  — apns-topic: <bundle>            apns-push-type: alert
 *     Used for chat messages, session reminders, declined-call notices,
 *     missed-call follow-ups.
 *
 *   • sendApnsVoip   — apns-topic: <bundle>.voip       apns-push-type: voip
 *     Used for kind in {'incoming_call', 'incoming_call_cancelled'} when the
 *     device has registered a PushKit token. Payload is a bare JSON object
 *     (no `aps` envelope) — the device-side AppDelegate calls
 *     CXProvider.reportNewIncomingCall directly to satisfy the 5-second
 *     PushKit contract.
 *
 *     For incoming_call_cancelled the device reports + immediately ends the
 *     call (CallKit-approved missed-call pattern), so the killed-iPhone ring
 *     gets dismissed when the consultant cancels.
 *
 * Android dispatch (sendFcm) is DATA-ONLY for kind in
 *   {'incoming_call', 'incoming_call_cancelled'}.
 * The previous "notification + data" payload prevented
 * react-native-callkeep's Headless JS task from running on a killed app
 * (Android delivers `notification` blocks directly to the system tray
 * without invoking onMessageReceived). Non-call kinds keep the notification
 * block so the system tray still shows a banner.
 *
 * Tokens are fanned out across every row in `push_tokens` for the user
 * (multi-device). Legacy `users.push_token` is consulted only when no
 * push_tokens rows exist.
 */
final class PushService
{
    /** Notification kinds that route via the APNs VoIP topic. */
    private const VOIP_KINDS = ['incoming_call', 'incoming_call_cancelled'];

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

        $kind = (string)($n['kind'] ?? '');
        $isVoipKind = in_array($kind, self::VOIP_KINDS, true);

        $anyOk = false;
        foreach ($tokens as $tok) {
            $platform = (string)($tok['platform'] ?? '');
            switch ($platform) {
                case 'ios':
                    $hasVoip = !empty($tok['voip_token']);
                    if ($isVoipKind && $hasVoip) {
                        // VoIP path is authoritative for call kinds. We do
                        // NOT fall back to alert push on failure: an alert
                        // would arrive as a banner the user may not see
                        // (worst case for a ringing call), and double-sends
                        // when the VoIP push DID succeed cause double-ring.
                        $ok = $this->sendApnsVoip($n, $tok);
                    } elseif ($isVoipKind && !$hasVoip) {
                        // Legacy iOS client without PushKit registration —
                        // fall back to a high-priority alert push so at
                        // least the banner+ringtone fires. Only valid for
                        // the initial ring; cancel pushes have no useful
                        // alert representation.
                        if ($kind === 'incoming_call' && !empty($tok['token'])) {
                            $ok = $this->sendApnsAlert($n, $tok);
                        } else {
                            $ok = false;
                        }
                    } else {
                        $ok = !empty($tok['token']) ? $this->sendApnsAlert($n, $tok) : false;
                    }
                    break;
                case 'android':
                case 'web':
                    $ok = $this->sendFcm($n, $tok);
                    break;
                default:
                    $ok = false;
            }
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

        $kind         = (string)($n['kind'] ?? '');
        $isCall       = ($kind === 'incoming_call');
        $isCallCancel = ($kind === 'incoming_call_cancelled');
        $data         = json_decode($n['payload_json'] ?? 'null', true) ?: [];

        // FCM v1 requires data values to be strings.
        $merged = $data + ['title' => $n['title'], 'body' => $n['body'], 'kind' => $kind];
        // Surface invite_id as `uuid` for the device-side CallKit/Telecom
        // key (react-native-callkeep uses a single string id across both
        // platforms).
        if (($isCall || $isCallCancel) && !isset($merged['uuid']) && isset($merged['invite_id'])) {
            $merged['uuid'] = (string)$merged['invite_id'];
        }
        $stringData = [];
        foreach ($merged as $k => $v) {
            $stringData[$k] = is_scalar($v) ? (string)$v : json_encode($v, JSON_UNESCAPED_UNICODE);
        }

        $message = [
            'token'   => $tok['token'],
            'data'    => $stringData,
            'android' => [
                'priority' => 'high',
            ],
        ];

        if ($isCall || $isCallCancel) {
            // *** DATA-ONLY ***
            // Killed Android apps DO run the FCM background message handler
            // for data-only HIGH-priority messages but DO NOT run it when a
            // `notification` block is present (Android delivers those
            // straight to the system tray). react-native-callkeep needs
            // the JS handler to fire so it can call displayIncomingCall /
            // endCall — hence: no notification block here.
            $message['android']['ttl'] = '45s';
        } else {
            $message['notification'] = [
                'title' => $n['title'],
                'body'  => $n['body'],
            ];
            $message['android']['notification'] = [
                'channel_id' => 'default',
                'sound'      => 'default',
            ];
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
                'kind' => $kind,
            ]);
            return false;
        } catch (\Throwable $e) {
            Logger::error('fcm_error', ['msg' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Exchange the service-account JSON for a short-lived OAuth2 access
     * token scoped to FCM. Cached on disk for ~55 minutes.
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

    /**
     * Regular APNs alert push. Used for everything except incoming_call /
     * incoming_call_cancelled when a VoIP token is available.
     */
    private function sendApnsAlert(array $n, array $tok): bool
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

            $kind   = (string)($n['kind'] ?? '');
            $isCall = ($kind === 'incoming_call');
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
                'data' => $extra + ['kind' => $kind],
            ], JSON_UNESCAPED_UNICODE);

            // Collapse incoming + cancel on the same invite so the cancel
            // banner replaces the incoming one on the lockscreen.
            $collapse = null;
            if (isset($extra['invite_id'])) {
                $collapse = 'invite-' . (int)$extra['invite_id'];
            }

            return $this->postToApns(
                tok:        $tok['token'],
                jwt:        $jwt,
                topic:      $bundle,
                pushType:   'alert',
                priority:   $isCall ? '10' : '5',
                payload:    $payload,
                cfg:        $cfg,
                kindForLog: $kind,
                collapseId: $collapse,
            );
        } catch (\Throwable $e) {
            Logger::error('apns_alert_error', ['msg' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * VoIP push via PushKit. Sent to the .voip topic with a bare JSON
     * payload (no aps envelope). The AppDelegate handler is contractually
     * required to call CXProvider.reportNewIncomingCall within ~5 seconds.
     *
     * For incoming_call: apns-expiration = INVITE_TTL (45s).
     * For incoming_call_cancelled: apns-expiration = 0 (drop if offline —
     * a cancel that lands after the original ringer already self-expired
     * would orphan a CallKit entry).
     */
    private function sendApnsVoip(array $n, array $tok): bool
    {
        if (empty($tok['voip_token'])) {
            return false;
        }
        $cfg = App::config('push');
        $keyPath = (string)$cfg['apns_key_path'];
        if (!$keyPath || !file_exists($keyPath) || !$cfg['apns_key_id'] || !$cfg['apns_team_id']) {
            Logger::error('apns_misconfigured', [
                'has_key' => $keyPath && file_exists($keyPath),
                'has_kid' => !empty($cfg['apns_key_id']),
                'has_tid' => !empty($cfg['apns_team_id']),
                'path'    => 'voip',
            ]);
            return false;
        }
        try {
            $jwt = $this->apnsJwt($cfg['apns_key_id'], $cfg['apns_team_id'], $keyPath);
            if (!$jwt) return false;
            $bundle = (string)$cfg['apns_bundle_id'];

            $kind  = (string)($n['kind'] ?? '');
            $extra = json_decode($n['payload_json'] ?? 'null', true) ?: [];
            if (!isset($extra['uuid']) && isset($extra['invite_id'])) {
                $extra['uuid'] = (string)$extra['invite_id'];
            }
            // Inline display strings so the AppDelegate can call
            // reportNewIncomingCall without any network round-trip.
            $extra['title'] = $n['title'];
            $extra['body']  = $n['body'];
            $extra['kind']  = $kind;

            $payload = json_encode($extra, JSON_UNESCAPED_UNICODE);

            $expiration = ($kind === 'incoming_call_cancelled') ? '0' : '45';

            return $this->postToApns(
                tok:        $tok['voip_token'],
                jwt:        $jwt,
                topic:      $bundle . '.voip',
                pushType:   'voip',
                priority:   '10',
                payload:    $payload,
                cfg:        $cfg,
                kindForLog: $kind . '.voip',
                expiration: $expiration,
            );
        } catch (\Throwable $e) {
            Logger::error('apns_voip_error', ['msg' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Shared HTTP/2 POST to APNs with prod→sandbox fallback on env-mismatch.
     * VoIP and alert pushes use the same gateway and the same .p8 JWT.
     */
    private function postToApns(
        string $tok,
        string $jwt,
        string $topic,
        string $pushType,
        string $priority,
        string $payload,
        array  $cfg,
        string $kindForLog,
        ?string $expiration = null,
        ?string $collapseId = null,
    ): bool {
        $forceSandbox = filter_var($cfg['apns_sandbox'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $hosts = $forceSandbox
            ? ['https://api.sandbox.push.apple.com']
            : ['https://api.push.apple.com', 'https://api.sandbox.push.apple.com'];

        foreach ($hosts as $i => $host) {
            $url = $host . '/3/device/' . rawurlencode($tok);
            $headers = [
                'authorization: bearer ' . $jwt,
                'apns-topic: ' . $topic,
                'apns-push-type: ' . $pushType,
                'apns-priority: ' . $priority,
                'content-type: application/json',
            ];
            if ($expiration !== null) {
                $headers[] = 'apns-expiration: ' . $expiration;
            }
            if ($collapseId !== null && $collapseId !== '') {
                $headers[] = 'apns-collapse-id: ' . $collapseId;
            }

            $ch  = curl_init($url);
            $respHeaders = [];
            curl_setopt_array($ch, [
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_2_0,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
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
                'host'   => $host,
                'http'   => $code,
                'reason' => $reason,
                'curl'   => $err,
                'topic'  => $topic,
                'kind'   => $kindForLog,
            ]);

            // Token is permanently invalid — remove from DB so it is never
            // retried. DeviceTokenNotForTopic happens when a VoIP push token
            // was stored in the regular token column (old CallKit builds).
            $staleToken = in_array($reason, ['Unregistered', 'DeviceTokenNotForTopic'], true)
                || ($code === 410);
            if ($staleToken) {
                DB::run('DELETE FROM push_tokens WHERE token = :t', [':t' => $tok]);
            }

            $envMismatch = ($code === 400 && $reason === 'BadDeviceToken')
                || ($code === 410 && $reason === 'Unregistered');
            if (!$envMismatch || $i === count($hosts) - 1) {
                return false;
            }
        }
        return false;
    }

    /**
     * APNs ES256 JWT with on-disk cache. Apple throttles JWT regeneration
     * (429 TooManyProviderTokenUpdates); reuse for ~55 minutes.
     */
    private function apnsJwt(string $kid, string $teamId, string $keyPath): ?string
    {
        $cacheFile = sys_get_temp_dir() . '/apns_jwt_' . md5($kid . '|' . $teamId . '|' . $keyPath) . '.json';
        if (is_readable($cacheFile)) {
            $cached = json_decode((string)@file_get_contents($cacheFile), true);
            if (is_array($cached) && ($cached['exp'] ?? 0) > time() + 60) {
                return (string)$cached['jwt'];
            }
        }

        $now    = time();
        $header = ['alg' => 'ES256', 'kid' => $kid];
        $claim  = ['iss' => $teamId, 'iat' => $now];
        $b64 = static fn(string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $signingInput = $b64(json_encode($header)) . '.' . $b64(json_encode($claim));

        $key = openssl_pkey_get_private('file://' . $keyPath);
        if (!$key) return null;
        $sig = '';
        if (!openssl_sign($signingInput, $sig, $key, OPENSSL_ALGO_SHA256)) return null;
        // ECDSA in PHP returns DER; APNs expects raw r||s
        $sig = self::derToJose($sig);
        $jwt = $signingInput . '.' . $b64($sig);

        @file_put_contents($cacheFile, json_encode([
            'jwt' => $jwt,
            'exp' => $now + 55 * 60,
        ]));
        @chmod($cacheFile, 0600);
        return $jwt;
    }

    private static function derToJose(string $der): string
    {
        // ECDSA DER → raw r||s for ES256.
        //
        // DER layout: SEQUENCE { INTEGER r, INTEGER s } i.e.
        //   30 [total-len] 02 [r-len] [r…] 02 [s-len] [s…]
        $offset = 2;
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
