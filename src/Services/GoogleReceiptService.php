<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Logger;
use GuzzleHttp\Client;

/**
 * Validates a Google Play subscription purchase via the Android Publisher API.
 * Uses a service account JSON to obtain an OAuth2 access token (JWT bearer flow).
 */
final class GoogleReceiptService
{
    public function validate(string $productId, string $purchaseToken): array
    {
        $pkg = App::config('google.package');
        $serviceJson = App::config('google.service_json');
        if (!$pkg || !$serviceJson || !file_exists($serviceJson)) {
            Logger::warn('google_config_missing', ['pkg' => $pkg, 'json' => $serviceJson]);
            return ['valid' => false, 'reason' => 'config_missing'];
        }

        $token = $this->oauthToken($serviceJson);
        if (!$token) return ['valid' => false, 'reason' => 'oauth_failed'];

        $url = sprintf(
            'https://androidpublisher.googleapis.com/androidpublisher/v3/applications/%s/purchases/subscriptions/%s/tokens/%s',
            rawurlencode($pkg), rawurlencode($productId), rawurlencode($purchaseToken)
        );

        try {
            $client = new Client(['timeout' => 8]);
            $r = $client->get($url, ['headers' => ['Authorization' => 'Bearer ' . $token]]);
            $data = json_decode((string)$r->getBody(), true) ?: [];
            $expMs = (int)($data['expiryTimeMillis'] ?? 0);
            return [
                'valid'      => $expMs > 0,
                'expires_at' => $expMs ? date('Y-m-d H:i:s', (int)($expMs / 1000)) : null,
                'is_trial'   => (int)($data['paymentState'] ?? 1) === 2,  // 2 = trial
                'product_id' => $productId,
                'original_tx'=> $data['orderId'] ?? $purchaseToken,
                'auto_renew' => (bool)($data['autoRenewing'] ?? false),
                'raw'        => $data,
            ];
        } catch (\Throwable $e) {
            Logger::error('google_verify_error', ['msg' => $e->getMessage()]);
            return ['valid' => false, 'reason' => 'request_failed'];
        }
    }

    private function oauthToken(string $jsonPath): ?string
    {
        $svc = json_decode((string)file_get_contents($jsonPath), true);
        if (!$svc || empty($svc['private_key']) || empty($svc['client_email'])) return null;

        $now = time();
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claim  = [
            'iss'   => $svc['client_email'],
            'scope' => 'https://www.googleapis.com/auth/androidpublisher',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'exp'   => $now + 3600,
            'iat'   => $now,
        ];
        $b64 = static fn(string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $signingInput = $b64(json_encode($header)) . '.' . $b64(json_encode($claim));

        $sig = '';
        if (!openssl_sign($signingInput, $sig, $svc['private_key'], OPENSSL_ALGO_SHA256)) {
            return null;
        }
        $assertion = $signingInput . '.' . $b64($sig);

        try {
            $client = new Client(['timeout' => 8]);
            $r = $client->post('https://oauth2.googleapis.com/token', [
                'form_params' => [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion'  => $assertion,
                ],
            ]);
            $data = json_decode((string)$r->getBody(), true) ?: [];
            return $data['access_token'] ?? null;
        } catch (\Throwable $e) {
            Logger::error('google_oauth_error', ['msg' => $e->getMessage()]);
            return null;
        }
    }
}
