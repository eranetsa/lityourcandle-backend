#!/bin/bash
# =============================================================================
# Lit Your Candle — auto-deploy script
# Runs as root via the GitHub webhook (public/_deploy.php) or manually.
# Pulls origin/main, reinstalls vendor deps, fixes ownership/perms, reloads
# services. Holds an exclusive flock so concurrent webhooks don't race.
# =============================================================================
set -euo pipefail

APP_DIR="/var/www/lityourcandle"
LOG="/var/log/lityc-deploy.log"
LOCK="/var/run/lityc-deploy.lock"

mkdir -p "$(dirname "$LOG")"
exec >>"$LOG" 2>&1
echo "==== $(date -Iseconds) deploy start ===="

# Acquire lock; bail quickly if another deploy is already running
exec 9>"$LOCK"
flock -n 9 || { echo "another deploy in progress, exiting"; exit 0; }

cd "$APP_DIR"

# Trust the repo for root (run-once safe)
git config --system --add safe.directory "$APP_DIR" 2>/dev/null || true

OLD=$(sudo -u www-data git rev-parse HEAD)
echo "before: $OLD"

sudo -u www-data git fetch --quiet origin
sudo -u www-data git reset --hard origin/main

NEW=$(sudo -u www-data git rev-parse HEAD)
echo "after:  $NEW"

if [ "$OLD" = "$NEW" ]; then
    echo "nothing to deploy"
fi

# Composer (only if composer.lock changed since last deploy)
if [ "$OLD" = "$NEW" ] && [ -d "$APP_DIR/vendor" ]; then
    echo "skipping composer (no change)"
else
    sudo -u www-data -H COMPOSER_ALLOW_SUPERUSER=1 \
        composer install --no-dev --optimize-autoloader --no-interaction --working-dir="$APP_DIR"
fi

# Database migrations: idempotent, no-op when already applied.
# Runs after composer so new namespaces in src/ are autoloadable, and
# before the service reload so freshly-shipped code never touches a
# yet-unmigrated schema.
sudo -u www-data -H php "$APP_DIR/bin/migrate.php" || {
    echo "migrate failed — aborting deploy"
    exit 1
}

# Permissions
chown -R www-data:www-data "$APP_DIR"
find "$APP_DIR" -path "$APP_DIR/vendor" -prune -o -type d -print0 | xargs -0 -r chmod 750
find "$APP_DIR" -path "$APP_DIR/vendor" -prune -o -type f -print0 | xargs -0 -r chmod 640
chmod -R a+rX "$APP_DIR/vendor"
chmod -R 770 "$APP_DIR/storage" "$APP_DIR/public/uploads"
chmod 755 "$APP_DIR/public" "$APP_DIR/public/admin" "$APP_DIR/public/admin/views" \
          "$APP_DIR/public/uploads" "$APP_DIR/public/uploads/consultants" 2>/dev/null || true

# If the deploy script itself changed in the repo, install the new copy so the
# next webhook fires the latest version.
if ! cmp -s "$APP_DIR/deploy/lityc-deploy.sh" /usr/local/sbin/lityc-deploy.sh; then
    install -m 0755 -o root -g root "$APP_DIR/deploy/lityc-deploy.sh" /usr/local/sbin/lityc-deploy.sh
    echo "self-updated /usr/local/sbin/lityc-deploy.sh"
fi

# Reload services
systemctl reload apache2
systemctl restart lityc-ws
echo "==== $(date -Iseconds) deploy ok ===="
