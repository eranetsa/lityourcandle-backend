#!/bin/bash
# =============================================================================
# Lit Your Candle — أشعل شمعتك
# Cloud-init for Hetzner LAMP image (Ubuntu 24.04)
#
# Paste the entire contents of this file into the "Cloud-init configuration"
# textarea before creating the server. Edit DOMAIN / LE_EMAIL / REPO_URL below.
# =============================================================================
set -euo pipefail
# NOTE: do not enable `-x` — secrets (DB pass, JWT secret) would leak into the log.
touch /var/log/lityc-bootstrap.log
chmod 600 /var/log/lityc-bootstrap.log
exec > >(tee -a /var/log/lityc-bootstrap.log) 2>&1

# ----- USER CONFIG -----------------------------------------------------------
DOMAIN="backend.lityourcandle.com"
LE_EMAIL="agent@era.net.sa"
REPO_URL="https://github.com/eranetsa/lityourcandle-backend.git"
APP_DIR="/var/www/lityourcandle"
DB_NAME="lityourcandle"
DB_USER="lityc"
TIMEZONE="Asia/Riyadh"

# Public keys to authorize for root SSH login (one per line, ssh-* format)
ROOT_AUTHORIZED_KEYS="ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOS2I9Ahn4NJgjqr2cIUAf9xbAMsGywFE+vJMllZGOge claude-deploy@lityourcandle"
# -----------------------------------------------------------------------------

echo ">>> installing root authorized_keys"
install -d -m 700 /root/.ssh
# Append (don't clobber) any keys already injected by Hetzner from your account
while IFS= read -r key; do
  [ -n "$key" ] || continue
  grep -qxF "$key" /root/.ssh/authorized_keys 2>/dev/null \
    || echo "$key" >> /root/.ssh/authorized_keys
done <<< "$ROOT_AUTHORIZED_KEYS"
chmod 600 /root/.ssh/authorized_keys

echo ">>> waiting for LAMP / cloud-init services to come up"
for i in $(seq 1 60); do
  if (systemctl is-active --quiet mysql || systemctl is-active --quiet mariadb) \
     && systemctl is-active --quiet apache2; then break; fi
  sleep 5
done

# Wait for any apt locks held by cloud-init / unattended-upgrades
while fuser /var/lib/dpkg/lock-frontend >/dev/null 2>&1 \
   || fuser /var/lib/apt/lists/lock      >/dev/null 2>&1; do sleep 5; done

export DEBIAN_FRONTEND=noninteractive
export COMPOSER_ALLOW_SUPERUSER=1
timedatectl set-timezone "$TIMEZONE"

echo ">>> installing extra packages"
apt-get update -y
apt-get install -y --no-install-recommends \
    git unzip composer \
    php-cli php-curl php-mbstring php-xml php-bcmath php-mysql php-gd php-zip \
    ufw fail2ban

echo ">>> enabling Apache modules"
a2enmod rewrite headers ssl proxy proxy_http proxy_wstunnel
a2dissite 000-default 2>/dev/null || true

echo ">>> generating secrets"
DB_PASS=$(openssl rand -hex 16)
JWT_SECRET=$(openssl rand -hex 32)
NOTES_KEY=$(openssl rand -hex 32)
ADMIN_PASS=$(openssl rand -base64 18 | tr -d '/+=' | cut -c1-20)

echo ">>> creating database + user"
MYSQL_CMD="mysql"
systemctl is-active --quiet mariadb && MYSQL_CMD="mariadb"
# Hetzner LAMP image stores the MySQL root password in /root/.hcloud_password
# as `mysql_root_pass="..."`. If absent, fall back to passwordless (auth_socket).
MYSQL_ROOT_OPTS=""
if [ -f /root/.hcloud_password ]; then
    . /root/.hcloud_password
    [ -n "${mysql_root_pass:-}" ] && MYSQL_ROOT_OPTS="--password=${mysql_root_pass}"
fi
$MYSQL_CMD --user=root $MYSQL_ROOT_OPTS <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
ALTER  USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL

echo ">>> cloning repo"
git clone "$REPO_URL" "$APP_DIR"
cd "$APP_DIR"

echo ">>> composer install"
composer install --no-dev --optimize-autoloader --no-interaction

echo ">>> writing .env"
cat > "$APP_DIR/.env" <<ENV
APP_ENV=production
APP_DEBUG=false
APP_URL=https://${DOMAIN}
APP_TIMEZONE=${TIMEZONE}

DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=${DB_NAME}
DB_USER=${DB_USER}
DB_PASS=${DB_PASS}
DB_CHARSET=utf8mb4

JWT_SECRET=${JWT_SECRET}
JWT_TTL=2592000
NOTES_ENCRYPTION_KEY=${NOTES_KEY}

# Fill in after DNS + provider accounts are ready -----------------------------
APPLE_SHARED_SECRET=
APPLE_VERIFY_URL=https://buy.itunes.apple.com/verifyReceipt
APPLE_VERIFY_SANDBOX_URL=https://sandbox.itunes.apple.com/verifyReceipt

GOOGLE_PLAY_PACKAGE=app.lityourcandle
GOOGLE_SERVICE_ACCOUNT_JSON=/etc/lityourcandle/google-service-account.json

AGORA_APP_ID=
AGORA_APP_CERTIFICATE=

AI_PROVIDER=anthropic
ANTHROPIC_API_KEY=
ANTHROPIC_MODEL=claude-haiku-4-5-20251001

FCM_SERVER_KEY=
APNS_KEY_ID=
APNS_TEAM_ID=
APNS_BUNDLE_ID=app.lityourcandle
APNS_KEY_PATH=/etc/lityourcandle/AuthKey.p8

RATE_LIMIT_PER_MIN=120
WS_PORT=8081
ENV
chmod 640 "$APP_DIR/.env"

echo ">>> loading schema + seeds"
$MYSQL_CMD --user="$DB_USER" --password="$DB_PASS" "$DB_NAME" < "$APP_DIR/database/schema.sql"
$MYSQL_CMD --user="$DB_USER" --password="$DB_PASS" "$DB_NAME" < "$APP_DIR/database/seeds.sql"

echo ">>> Apache vhost"
cat > /etc/apache2/sites-available/lityourcandle.conf <<APACHE
<VirtualHost *:80>
    ServerName ${DOMAIN}
    DocumentRoot ${APP_DIR}/public

    <Directory ${APP_DIR}/public>
        AllowOverride All
        Require all granted
    </Directory>

    ProxyPass        /ws  ws://127.0.0.1:8081/
    ProxyPassReverse /ws  ws://127.0.0.1:8081/

    # Block direct access to dotfiles and config dirs
    <FilesMatch "^\.">
        Require all denied
    </FilesMatch>

    ErrorLog  \${APACHE_LOG_DIR}/lityc-error.log
    CustomLog \${APACHE_LOG_DIR}/lityc-access.log combined
</VirtualHost>
APACHE
a2ensite lityourcandle
systemctl reload apache2

echo ">>> permissions"
chown -R www-data:www-data "$APP_DIR"
find "$APP_DIR" -type d -exec chmod 750 {} \;
find "$APP_DIR" -type f -exec chmod 640 {} \;
chmod -R 770 "$APP_DIR/storage"

echo ">>> systemd unit for WebSocket"
cat > /etc/systemd/system/lityc-ws.service <<UNIT
[Unit]
Description=Lit Your Candle WebSocket
After=network.target mysql.service mariadb.service

[Service]
User=www-data
WorkingDirectory=${APP_DIR}
ExecStart=/usr/bin/php websocket/server.php
Restart=always
RestartSec=5
StandardOutput=append:/var/log/lityc-ws.log
StandardError=append:/var/log/lityc-ws.log

[Install]
WantedBy=multi-user.target
UNIT
touch /var/log/lityc-ws.log
chown www-data:www-data /var/log/lityc-ws.log
systemctl daemon-reload
systemctl enable --now lityc-ws

echo ">>> cron jobs"
cat > /etc/cron.d/lityourcandle <<CRON
# Lit Your Candle background workers
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
* * * * *   www-data /usr/bin/php ${APP_DIR}/cron/send_notifications.php   >/dev/null 2>&1
0 3 * * *   www-data /usr/bin/php ${APP_DIR}/cron/update_subscriptions.php >/dev/null 2>&1
0 19 * * *  www-data /usr/bin/php ${APP_DIR}/cron/daily_checkins.php       >/dev/null 2>&1
CRON
chmod 644 /etc/cron.d/lityourcandle

echo ">>> firewall"
ufw default deny incoming
ufw default allow outgoing
ufw allow OpenSSH
ufw allow "Apache Full"
ufw --force enable

echo ">>> creating first admin user"
cd "$APP_DIR"
sudo -u www-data php cron/create_admin.php admin "admin@${DOMAIN}" "$ADMIN_PASS" "Admin"

echo ">>> auto-deploy webhook (GitHub → /_deploy.php → /usr/local/sbin/lityc-deploy.sh)"
install -m 0755 -o root -g root "$APP_DIR/deploy/lityc-deploy.sh" /usr/local/sbin/lityc-deploy.sh
mkdir -p /etc/lityourcandle
if [ ! -s /etc/lityourcandle/deploy.secret ]; then
    openssl rand -hex 32 > /etc/lityourcandle/deploy.secret
fi
chown root:www-data /etc/lityourcandle/deploy.secret
chmod 0640 /etc/lityourcandle/deploy.secret
cat > /etc/sudoers.d/lityc-deploy <<'SUDO'
www-data ALL=(root) NOPASSWD: /usr/local/sbin/lityc-deploy.sh
SUDO
chmod 0440 /etc/sudoers.d/lityc-deploy
visudo -c -f /etc/sudoers.d/lityc-deploy
git config --system --add safe.directory "$APP_DIR" 2>/dev/null || true

echo ">>> writing certbot helper (run after DNS is pointed)"
cat > /root/run-certbot.sh <<CERT
#!/bin/bash
set -e
certbot --apache --non-interactive --agree-tos --redirect \\
        -m "${LE_EMAIL}" -d "${DOMAIN}"
echo "Certbot done. Reloading Apache."
systemctl reload apache2
CERT
chmod 700 /root/run-certbot.sh

# Enable Certbot auto-renewal (already provided by certbot package, but ensure)
systemctl enable --now certbot.timer 2>/dev/null || true

echo ">>> deployment summary"
INFO=/root/lityc-deploy-info.txt
cat > "$INFO" <<EOF
==========================================
  Lit Your Candle backend deployed
==========================================
Domain:        ${DOMAIN}
App dir:       ${APP_DIR}
DB name:       ${DB_NAME}
DB user:       ${DB_USER}
DB pass:       ${DB_PASS}

Admin panel:   http://${DOMAIN}/admin/
Admin user:    admin
Admin pass:    ${ADMIN_PASS}

Health check:  curl http://\$(curl -s ifconfig.me)/api/health -H "Host: ${DOMAIN}"

Next steps:
  1. Point ${DOMAIN} A-record to this server's IPv4
  2. Issue TLS cert:    /root/run-certbot.sh
  3. Edit secrets:      nano ${APP_DIR}/.env
                        (Anthropic, Apple, Google, Agora, FCM, APNs)
  4. Restart services:  systemctl restart apache2 lityc-ws

Logs:
  /var/log/lityc-bootstrap.log     (this script)
  /var/log/lityc-ws.log            (websocket)
  /var/log/apache2/lityc-error.log
  ${APP_DIR}/storage/logs/         (app)
==========================================
EOF
chmod 600 "$INFO"
cat "$INFO"

echo ">>> done"
