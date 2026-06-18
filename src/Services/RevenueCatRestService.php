<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Logger;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;

/**
 * Thin client around RevenueCat's v1 REST API.
 *
 * Only the read path we need for back-fill is implemented:
 *   GET https://api.revenuecat.com/v1/subscribers/{app_user_id}
 *
 * RC's webhook secret (config: revenuecat.webhook_secret) is a SEPARATE
 * credential from the v1 "Secret API key" (config: revenuecat.secret_api_key).
 * This service uses the latter — see config/config.php for where to find it
 * in the RC dashboard.
 *
 * The class is intentionally narrow: no caching, no parallelism. Rate-limit
 * pacing is the caller's job (see RevenueCatBackfill). All we own is:
 *   - Bearer auth header
 *   - rawurlencode of the app_user_id (so "$RCAnonymousID:abc" works)
 *   - Retry on 429 / 5xx with exponential backoff + jitter
 *   - 404 → null (and surface it; do NOT auto-create)
 *   - 401/403/400 → throw fatal (operator error, do not silently retry)
 *   - HTTPS + revenuecat.com host validation (defence against config-leak
 *     SSRF in the unlikely event $baseUrl is ever tainted)
 */
final class RevenueCatRestService
{
    private string $secret;
    private string $baseUrl;
    private Client $http;

    /** Max retry attempts on 429 / 5xx (in addition to the initial try). */
    private const MAX_RETRIES = 3;

    /** Hard ceiling on a single sleep, in seconds. */
    private const MAX_SLEEP_SECONDS = 5;

    public function __construct(?string $secret = null, ?string $baseUrl = null, ?Client $http = null)
    {
        $configured = (string)App::config('revenuecat.secret_api_key', '');
        $envSecret  = (string)($_ENV['REVENUECAT_SECRET_KEY'] ?? getenv('REVENUECAT_SECRET_KEY') ?: '');
        $this->secret = $secret ?? ($configured !== '' ? $configured : $envSecret);
        if ($this->secret === '') {
            throw new \RuntimeException(
                'RevenueCat secret API key not configured (config: revenuecat.secret_api_key / env REVENUECAT_SECRET_KEY).'
            );
        }
        $url = rtrim(
            $baseUrl ?? (string)App::config('revenuecat.rest_base_url', 'https://api.revenuecat.com/v1'),
            '/'
        );
        // Defensive validation: scheme must be https, host must end in
        // .revenuecat.com. If a future code path ever lets request data
        // influence $baseUrl, this stops the bearer-bearing request from
        // landing on attacker-controlled infra.
        $parts = parse_url($url);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host   = strtolower((string)($parts['host']   ?? ''));
        if ($scheme !== 'https'
            || ($host !== 'api.revenuecat.com' && !str_ends_with($host, '.revenuecat.com'))) {
            throw new \RuntimeException('RevenueCat REST base URL must be https on *.revenuecat.com');
        }
        $this->baseUrl = $url;

        $this->http = $http ?? new Client([
            'timeout'         => 15,
            'connect_timeout' => 5,
            'http_errors'     => false, // we inspect status ourselves
            // verify=true is Guzzle's default — TLS verification stays on.
        ]);
    }

    /**
     * Fetch the canonical subscriber object for an App User ID.
     *
     * @return array|null  Parsed `subscriber` object (NOT the outer envelope)
     *                     enriched with two synthetic keys:
     *                       _request_date     ISO8601 string from the envelope
     *                       _request_date_ms  int (epoch ms) from the envelope
     *                     Returns null on HTTP 404 (rare — see §2.2 of the RC ref).
     *
     * @throws \RuntimeException on auth failure (401/403/400 — message starts
     *                           with "fatal HTTP"), persistent 5xx, or
     *                           exhausted retries. Callers can distinguish
     *                           "definitely-fatal" from "transient" by the
     *                           "fatal HTTP" message prefix.
     */
    public function getSubscriber(string $appUserId): ?array
    {
        if ($appUserId === '') {
            throw new \InvalidArgumentException('appUserId must not be empty');
        }
        $encoded = rawurlencode($appUserId);
        $url     = $this->baseUrl . '/subscribers/' . $encoded;

        for ($attempt = 0; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                $resp = $this->http->get($url, [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->secret,
                        'Accept'        => 'application/json',
                    ],
                ]);
            } catch (RequestException $e) {
                // Transport-level error (DNS / TLS / timeout). Treat as
                // retryable up to the limit. NB: we strip the inner
                // exception out before wrapping so a future stack-trace
                // dump can't surface the bearer token (which lives in
                // the args of the call frame we just unwound).
                if ($attempt >= self::MAX_RETRIES) {
                    Logger::error('rc_rest_transport_giveup', [
                        'app_user_id_hash' => self::idHash($appUserId),
                        'msg'              => self::sanitizeMessage($e->getMessage()),
                    ]);
                    throw new \RuntimeException(
                        'RC REST transport error: ' . self::sanitizeMessage($e->getMessage())
                    );
                }
                $this->sleep($attempt, null);
                continue;
            }

            $status = $resp->getStatusCode();

            if ($status === 200) {
                $body = (string)$resp->getBody();
                $json = json_decode($body, true);
                if (!is_array($json) || !isset($json['subscriber']) || !is_array($json['subscriber'])) {
                    throw new \RuntimeException('RC REST: malformed 200 body');
                }
                $sub = $json['subscriber'];
                // Stash the envelope's request_date(s) inside the returned
                // object so callers can compare entitlement expiries against
                // RC's clock instead of the local one. Prefer the *_ms field
                // (no TZ ambiguity) over the ISO string.
                $sub['_request_date']    = (string)($json['request_date']    ?? '');
                $sub['_request_date_ms'] = (int)   ($json['request_date_ms'] ?? 0);
                return $sub;
            }

            if ($status === 404) {
                return null;
            }

            if (in_array($status, [400, 401, 403], true)) {
                // Operator errors — never auto-retry, will not get better.
                // Prefix "fatal HTTP" lets callers branch on "is this worth
                // trying the next candidate id" vs "stop the whole job".
                Logger::error('rc_rest_fatal', [
                    'app_user_id_hash' => self::idHash($appUserId),
                    'status'           => $status,
                    'key_hash'         => self::secretHash($this->secret),
                ]);
                throw new \RuntimeException("RevenueCat REST fatal HTTP {$status}");
            }

            if ($status === 429 || $status >= 500) {
                if ($attempt >= self::MAX_RETRIES) {
                    Logger::warn('rc_rest_retry_giveup', [
                        'app_user_id_hash' => self::idHash($appUserId),
                        'status'           => $status,
                        'attempts'         => $attempt + 1,
                    ]);
                    throw new \RuntimeException("RevenueCat REST retries exhausted at HTTP {$status}");
                }
                $this->sleep($attempt, $resp);
                continue;
            }

            throw new \RuntimeException("RevenueCat REST unexpected HTTP {$status}");
        }

        // Unreachable, but keeps static analysers happy.
        return null;
    }

    /**
     * Backoff:
     *   - Honour Retry-After header verbatim if present (seconds).
     *   - Otherwise 2^attempt * 250ms + 0..500ms jitter.
     *   - Hard ceiling MAX_SLEEP_SECONDS (5s).
     */
    private function sleep(int $attempt, ?ResponseInterface $resp): void
    {
        $sleep = null;
        if ($resp !== null && $resp->hasHeader('Retry-After')) {
            $ra = (int)$resp->getHeaderLine('Retry-After');
            if ($ra > 0) $sleep = (float)$ra;
        }
        if ($sleep === null) {
            $base   = (2 ** $attempt) * 0.25;          // 0.25, 0.5, 1.0, 2.0s
            $jitter = mt_rand(0, 500) / 1000;          // 0..0.5s
            $sleep  = $base + $jitter;
        }
        $sleep = min($sleep, (float)self::MAX_SLEEP_SECONDS);
        usleep((int)($sleep * 1_000_000));
    }

    /** Strip URL paths from Guzzle messages so we can't accidentally leak the
     *  query string or the configured base URL into a logfile. The bearer
     *  token itself never lands in Guzzle's message — but Guzzle does include
     *  the request URL, which is enough on its own to deanonymise traffic. */
    private static function sanitizeMessage(string $msg): string
    {
        return preg_replace('#https?://\S+#', '<url>', $msg) ?? $msg;
    }

    /** Stable 12-char hash of the App User ID — safe to log. */
    private static function idHash(string $appUserId): string
    {
        return substr(hash('sha256', $appUserId), 0, 12);
    }

    /** First 8 chars of sha256 over the secret — enough to disambiguate
     *  which key the operator configured, useless for impersonation. */
    private static function secretHash(string $secret): string
    {
        return substr(hash('sha256', $secret), 0, 8);
    }
}
