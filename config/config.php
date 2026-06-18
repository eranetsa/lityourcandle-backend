<?php
declare(strict_types=1);

return [
    'app' => [
        'env'      => $_ENV['APP_ENV']      ?? 'production',
        'debug'    => filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'url'      => $_ENV['APP_URL']      ?? 'https://backend.lityourcandle.com',
        'timezone' => $_ENV['APP_TIMEZONE'] ?? 'Asia/Riyadh',
    ],
    'db' => [
        'host'    => $_ENV['DB_HOST']    ?? '127.0.0.1',
        'port'    => (int)($_ENV['DB_PORT'] ?? 3306),
        'name'    => $_ENV['DB_NAME']    ?? 'lityourcandle',
        'user'    => $_ENV['DB_USER']    ?? 'lityc',
        'pass'    => $_ENV['DB_PASS']    ?? '',
        'charset' => $_ENV['DB_CHARSET'] ?? 'utf8mb4',
    ],
    'jwt' => [
        'secret' => $_ENV['JWT_SECRET'] ?? '',
        'ttl'    => (int)($_ENV['JWT_TTL'] ?? 2592000),
        'iss'    => $_ENV['APP_URL'] ?? 'https://backend.lityourcandle.com',
        'alg'    => 'HS256',
    ],
    'crypto' => [
        'notes_key_hex' => $_ENV['NOTES_ENCRYPTION_KEY'] ?? '',
    ],
    'apple' => [
        'shared_secret' => $_ENV['APPLE_SHARED_SECRET'] ?? '',
        'verify_url'    => $_ENV['APPLE_VERIFY_URL'] ?? 'https://buy.itunes.apple.com/verifyReceipt',
        'sandbox_url'   => $_ENV['APPLE_VERIFY_SANDBOX_URL'] ?? 'https://sandbox.itunes.apple.com/verifyReceipt',
    ],
    'google' => [
        'package'      => $_ENV['GOOGLE_PLAY_PACKAGE'] ?? '',
        'service_json' => $_ENV['GOOGLE_SERVICE_ACCOUNT_JSON'] ?? '',
    ],
    'revenuecat' => [
        // The verbatim "Authorization" header value we expect on every
        // webhook POST. Set this in .env to the same string you typed
        // into the RC dashboard. Either raw "<secret>" or "Bearer <secret>"
        // is accepted — the controller strips the Bearer prefix from
        // BOTH sides before the constant-time compare.
        'webhook_secret' => $_ENV['REVENUECAT_WEBHOOK_SECRET'] ?? '',
        // RC v1 REST API "Secret API key" — used by the back-fill /
        // reconciliation job (src/Services/RevenueCatRestService.php) to
        // call GET https://api.revenuecat.com/v1/subscribers/{app_user_id}.
        //
        // IMPORTANT: this is NOT the same as `webhook_secret` above, and
        // NOT the public "App-specific" key the mobile SDK uses. Find it
        // at: RevenueCat dashboard → Project settings → API keys →
        // "Secret API keys" (sometimes shown as "v1 secret API key").
        // Pass it raw — the service prepends `Bearer ` itself. Never log
        // the full value (the service logs only an 8-char sha256 prefix).
        'secret_api_key' => $_ENV['REVENUECAT_SECRET_KEY'] ?? '',
        // Base URL — overridable for tests / staging proxy. Default is
        // the production REST endpoint. RevenueCatRestService validates
        // the scheme is https and host ends in .revenuecat.com.
        'rest_base_url'  => $_ENV['REVENUECAT_REST_BASE_URL']
                            ?? 'https://api.revenuecat.com/v1',
        // Optional environment filter. Empty (default) → accept both
        // SANDBOX and PRODUCTION events. Set to "PRODUCTION" in live
        // .env to drop sandbox traffic (e.g. internal TestFlight buys).
        'allowed_environment' => $_ENV['REVENUECAT_ENV'] ?? '',
        // Comma-separated SKU prefixes the webhook treats as an extra-
        // session top-up under NON_RENEWING_PURCHASE. Lets ops add new
        // SKUs (e.g. "extra_session_sar30_") without a redeploy.
        'extra_session_sku_prefixes' => array_values(array_filter(array_map(
            'trim',
            explode(',', $_ENV['REVENUECAT_EXTRA_SESSION_SKU_PREFIXES']
                ?? 'session_single_,session_extra_')
        ))),
    ],
    'agora' => [
        'app_id'          => $_ENV['AGORA_APP_ID'] ?? '',
        'app_certificate' => $_ENV['AGORA_APP_CERTIFICATE'] ?? '',
    ],
    'ai' => [
        // Default provider + keys baked from .env. The admin panel can
        // override the provider + active key per-installation via the
        // `ai_provider` / `ai_*_key` settings rows, so production can
        // flip between Anthropic direct and OpenRouter without a
        // redeploy.
        'provider'         => $_ENV['AI_PROVIDER'] ?? 'anthropic',
        'anthropic_key'    => $_ENV['ANTHROPIC_API_KEY'] ?? '',
        'anthropic_model'  => $_ENV['ANTHROPIC_MODEL'] ?? 'claude-haiku-4-5-20251001',
        'openrouter_key'   => $_ENV['OPENROUTER_API_KEY'] ?? '',
        'openrouter_model' => $_ENV['OPENROUTER_MODEL'] ?? 'openai/gpt-4o-mini',
    ],
    'push' => [
        // Legacy FCM server key — Google disabled the `fcm/send` endpoint
        // in 2024. Kept only so old deployments don't error out; new
        // installs should use HTTP v1 via the service account JSON below.
        'fcm_server_key'           => $_ENV['FCM_SERVER_KEY'] ?? '',
        'fcm_project_id'           => $_ENV['FCM_PROJECT_ID'] ?? '',
        'fcm_service_account_path' => $_ENV['FCM_SERVICE_ACCOUNT_PATH'] ?? '',
        'apns_key_id'    => $_ENV['APNS_KEY_ID'] ?? '',
        'apns_team_id'   => $_ENV['APNS_TEAM_ID'] ?? '',
        'apns_bundle_id' => $_ENV['APNS_BUNDLE_ID'] ?? '',
        'apns_key_path'  => $_ENV['APNS_KEY_PATH'] ?? '',
        // When true the APNs request goes straight to the sandbox gateway
        // (TestFlight + Xcode dev builds). Default false: try production
        // first and fall back to sandbox automatically on BadDeviceToken.
        'apns_sandbox'   => $_ENV['APNS_USE_SANDBOX'] ?? false,
    ],
    'rate_limit' => [
        'per_minute' => (int)($_ENV['RATE_LIMIT_PER_MIN'] ?? 120),
    ],
    'bookings' => [
        // Temporary flag for testing: lets anyone book a session without
        // a paid plan or session credit. Sessions are stored with
        // paid_with='free' so they're easy to filter out later.
        'allow_free' => filter_var($_ENV['ALLOW_FREE_BOOKINGS'] ?? false, FILTER_VALIDATE_BOOLEAN),
    ],
    'plans' => [
        'free' => [
            'price'    => 0.00,
            'sessions' => 0,
            'features' => ['daily_candle', 'mood', 'limited_content'],
        ],
        'weekly' => [
            'price'    => 200.00,
            'sessions' => 0,
            'features' => ['ai_unlimited', 'programs'],
            'duration_days' => 7,
        ],
        'monthly' => [
            'price'    => 500.00,
            'sessions' => 1,
            'features' => ['ai_unlimited', 'programs', 'sessions'],
            'duration_days' => 30,
        ],
        'yearly' => [
            'price'    => 1200.00,
            'sessions_per_month' => 2,
            'features' => ['ai_unlimited', 'programs', 'sessions', 'priority_booking'],
            'duration_days' => 365,
        ],
        'extra_session' => [
            'subscriber'     => 300.00,
            'non_subscriber' => 500.00,
        ],
        // Free trials retired: subscriptions bill immediately. Existing
        // trial rows still expire on their original schedule via
        // cron/update_subscriptions.php.
        'trial_days' => 0,
    ],
];
