<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Logger;
use GuzzleHttp\Client;

/**
 * Validates an App Store unified receipt (auto-renewable subscriptions).
 * Falls back to sandbox when status 21007 is returned.
 */
final class AppleReceiptService
{
    public function validate(string $receiptB64): array
    {
        $cfg = App::config('apple');
        $payload = [
            'receipt-data' => $receiptB64,
            'password'     => $cfg['shared_secret'],
            'exclude-old-transactions' => true,
        ];

        $resp = $this->callEndpoint($cfg['verify_url'], $payload);
        if (($resp['status'] ?? -1) === 21007) {
            $resp = $this->callEndpoint($cfg['sandbox_url'], $payload);
        }
        if (($resp['status'] ?? -1) !== 0) {
            Logger::warn('apple_receipt_invalid', ['status' => $resp['status'] ?? null]);
            return ['valid' => false, 'status' => $resp['status'] ?? null];
        }

        $latest = $resp['latest_receipt_info'] ?? [];
        $info   = is_array($latest) && $latest ? end($latest) : [];
        $ms     = (int)($info['expires_date_ms'] ?? 0);
        $isTrial= ($info['is_trial_period'] ?? 'false') === 'true';
        $productId = $info['product_id'] ?? null;
        $originalTx= $info['original_transaction_id'] ?? null;

        return [
            'valid'        => true,
            'expires_at'   => $ms ? date('Y-m-d H:i:s', (int)($ms / 1000)) : null,
            'is_trial'     => $isTrial,
            'product_id'   => $productId,
            'original_tx'  => $originalTx,
            'auto_renew'   => ($resp['pending_renewal_info'][0]['auto_renew_status'] ?? '0') === '1',
            'raw'          => $resp,
        ];
    }

    private function callEndpoint(string $url, array $payload): array
    {
        try {
            $client = new Client(['timeout' => 8]);
            $r = $client->post($url, ['json' => $payload]);
            return json_decode((string)$r->getBody(), true) ?: [];
        } catch (\Throwable $e) {
            Logger::error('apple_verify_error', ['msg' => $e->getMessage()]);
            return ['status' => -1];
        }
    }
}
