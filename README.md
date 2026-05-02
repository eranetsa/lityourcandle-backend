# Lit Your Candle — أشعل شمعتك (Backend)

LAMP backend for the Arabic mental-wellness app: AI companion (شمعة), subscriptions
(Apple / Google), consultant booking, mood tracking, programs, real-time chat, and
admin dashboard.

## Stack

- Ubuntu 24.04
- Apache 2.4 (HTTPS, mod_rewrite, mod_proxy_wstunnel)
- PHP 8.1+ (PDO, OpenSSL, cURL)
- MySQL 8 / MariaDB 10.6
- Composer
- Ratchet WebSocket server (long-running PHP process)

## Layout

```
public/                  ← Apache DocumentRoot
  index.php              ← REST front controller
  admin/                 ← Server-rendered admin panel (PHP)
src/
  Core/                  ← App, DB, Router, Request, Response, Auth, Crypto, Logger, Validator
  Controllers/           ← One per module
  Models/                ← Subscription helper
  Middleware/            ← Auth, Admin, Consultant, RateLimit
  Services/              ← Apple/Google receipts, Agora token, Candle AI, Push (FCM/APNs)
config/                  ← config.php, routes.php
database/                ← schema.sql, seeds.sql
cron/                    ← update_subscriptions, send_notifications, daily_checkins
websocket/server.php     ← Ratchet chat server
storage/logs/            ← JSON-line app logs
```

## REST endpoints

All JSON. Auth via `Authorization: Bearer <jwt>` unless marked public.

### Public
- `GET  /api/health`
- `POST /api/auth/register`
- `POST /api/auth/login`
- `GET  /api/subscriptions/plans`
- `GET  /api/consultants`
- `GET  /api/consultants/{id}`
- `GET  /api/consultants/{id}/availability`
- `GET  /api/programs`
- `GET  /api/programs/{slug}`
- `GET  /api/daily-message`
- `GET  /api/exit-popup`

### Authenticated
- `GET/PUT /api/me`
- `POST /api/me/push-token`
- `POST /api/me/change-password`
- `GET  /api/subscriptions/me`
- `POST /api/subscriptions/start-trial`
- `POST /api/subscriptions/apple/validate`
- `POST /api/subscriptions/google/validate`
- `POST /api/subscriptions/extra-session`
- `POST /api/bookings`
- `GET  /api/sessions`
- `GET  /api/sessions/{id}`
- `POST /api/sessions/{id}/start | end | cancel | feedback`
- `GET/POST /api/sessions/{id}/messages`
- `GET  /api/sessions/{id}/rtc-token`
- `POST /api/ai/candle`
- `POST /api/mood`
- `GET  /api/mood/history`
- `GET  /api/mood/insights`
- `POST /api/programs/{slug}/days/{day}/complete`
- `GET  /api/notifications`
- `POST /api/notifications/{id}/read`
- `POST /api/paywall/check`

### WebSocket
`wss://backend.lityourcandle.com/ws?token=<jwt>&session_id=<id>` — JSON messages of type
`message`, `typing`, `read`. The server persists messages to the `messages` table
and broadcasts to other connections in the same session.

## Setup

```bash
# 1. Clone + install
sudo apt install apache2 php8.3 php8.3-mysql php8.3-curl php8.3-mbstring \
                 php8.3-xml php8.3-bcmath libapache2-mod-php8.3 mysql-server \
                 composer
sudo a2enmod rewrite headers proxy proxy_http proxy_wstunnel ssl

cd /var/www/lityourcandle
composer install --no-dev --optimize-autoloader

# 2. Environment
cp .env.example .env
# generate secrets
php -r "echo bin2hex(random_bytes(32)).PHP_EOL;"   # JWT_SECRET (64 hex)
php -r "echo bin2hex(random_bytes(32)).PHP_EOL;"   # NOTES_ENCRYPTION_KEY (64 hex)
nano .env

# 3. Database
mysql -u root -p < database/schema.sql
mysql -u root -p lityourcandle < database/seeds.sql

# 4. Apache vhost (sample)
#   DocumentRoot /var/www/lityourcandle/public
#   <Directory /var/www/lityourcandle/public> AllowOverride All Require all granted </Directory>
#   ProxyPass        /ws  ws://127.0.0.1:8081/
#   ProxyPassReverse /ws  ws://127.0.0.1:8081/

# 5. Cron (as www-data)
crontab -e
*/1 * * * *  /usr/bin/php /var/www/lityourcandle/cron/send_notifications.php
0   3 * * *  /usr/bin/php /var/www/lityourcandle/cron/update_subscriptions.php
0  19 * * *  /usr/bin/php /var/www/lityourcandle/cron/daily_checkins.php

# 6. WebSocket as a systemd unit
sudo tee /etc/systemd/system/lityc-ws.service > /dev/null <<'EOF'
[Unit]
Description=Lit Your Candle WebSocket
After=network.target

[Service]
User=www-data
WorkingDirectory=/var/www/lityourcandle
ExecStart=/usr/bin/php websocket/server.php
Restart=always

[Install]
WantedBy=multi-user.target
EOF
sudo systemctl enable --now lityc-ws

# 7. Permissions
sudo chown -R www-data:www-data /var/www/lityourcandle
sudo chmod -R 750 /var/www/lityourcandle
sudo chmod 770    /var/www/lityourcandle/storage/logs
```

Create the first admin user:

```bash
php cron/create_admin.php admin admin@lityourcandle.com 'StrongP@ss1234' 'مدير النظام'
```

Then sign in at `https://backend.lityourcandle.com/admin/`.

## Plans

| Plan    | Price (SAR) | Sessions     | Features                                  |
|---------|-------------|--------------|-------------------------------------------|
| Free    | 0           | 0            | daily candle, mood, limited content       |
| Weekly  | 200         | 0            | unlimited AI, programs                    |
| Monthly | 500         | 1 / month    | + sessions                                |
| Yearly  | 1200        | 2 / month    | + priority booking                        |
| Trial   | 0           | 0            | 3 days, weekly-tier features, no sessions |

Extra session: 300 SAR for active subscribers, 500 SAR for non-subscribers.

## Security

- HTTPS forced via `.htaccess` (HSTS header set)
- Bcrypt cost-12 password hashing
- JWT (HS256) with rotating `jti`, 30-day default TTL
- AES-256-GCM at-rest encryption for `sessions.post_notes_enc`
- PDO prepared statements throughout (no string concatenation)
- Per-IP+route rate limiting via DB-backed bucket
- CSRF token + SameSite=Strict cookies in admin panel
- Role enforcement in middleware (`user`, `consultant`, `admin`)
- Audit log of admin actions in `audit_log`

## Third-party integrations

Implementations are present and will activate when env vars are filled:

- **Apple App Store** — `AppleReceiptService` validates receipts (production +
  sandbox fallback).
- **Google Play** — `GoogleReceiptService` does its own JWT-bearer OAuth using a
  service-account JSON, then calls Android Publisher API.
- **Agora RTC** — token built locally; only the App ID and certificate are needed.
- **Anthropic (شمعة AI)** — calls `claude-haiku-4-5-20251001` by default with a
  strict Arabic system prompt requesting structured JSON. Falls back to a
  templated response when the API key is missing.
- **FCM / APNs** — implemented; needs server key (FCM) or .p8 key (APNs).

## Notes

- The schema is normalized, with foreign keys enforced.
- The `users.consultant_id` FK is added at the end of `schema.sql` because of the
  forward reference.
- Trials are recorded as a `subscriptions` row with `status='trial'` and a
  `trial_ends_at` timestamp; a daily cron expires them.
