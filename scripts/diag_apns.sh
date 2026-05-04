#!/usr/bin/env bash
# =============================================================================
# APNs readiness diagnostics for lityourcandle backend.
# Run on the server:
#   sudo bash /var/www/lityourcandle/scripts/diag_apns.sh
# Reports each step ✅ / ❌ with masked secrets.
# =============================================================================
set -uo pipefail

APP_DIR="${APP_DIR:-/var/www/lityourcandle}"
ENV_FILE="$APP_DIR/.env"
LOG_FILE="${LOG_FILE:-$APP_DIR/storage/logs/app-$(date +%F).log}"

green=$'\033[0;32m'; red=$'\033[0;31m'; yellow=$'\033[0;33m'; reset=$'\033[0m'
ok()   { echo "${green}✅ $*${reset}"; }
fail() { echo "${red}❌ $*${reset}"; }
warn() { echo "${yellow}⚠ $*${reset}"; }
hr()   { echo; echo "──── $* ────"; }

mask() { # last-4 only
    local v="$1"; local n=${#v}
    if (( n <= 4 )); then echo "(set, len=$n)"; else echo "***${v: -4} (len=$n)"; fi
}

# ── 1. .env vars ─────────────────────────────────────────────────────────────
hr "1) .env"
if [[ ! -f "$ENV_FILE" ]]; then
    fail ".env not found at $ENV_FILE"; exit 1
fi
get() { grep -E "^${1}=" "$ENV_FILE" | head -1 | cut -d= -f2- | tr -d '"' | tr -d "'"; }

KID=$(get APNS_KEY_ID)
TID=$(get APNS_TEAM_ID)
BID=$(get APNS_BUNDLE_ID)
KPATH=$(get APNS_KEY_PATH)
USE_SANDBOX=$(get APNS_USE_SANDBOX)

[[ -n "$KID"  ]] && ok "APNS_KEY_ID = $(mask "$KID")"     || fail "APNS_KEY_ID missing"
[[ -n "$TID"  ]] && ok "APNS_TEAM_ID = $(mask "$TID")"    || fail "APNS_TEAM_ID missing"
[[ -n "$BID"  ]] && ok "APNS_BUNDLE_ID = $BID"            || fail "APNS_BUNDLE_ID missing"
[[ -n "$KPATH" ]] && ok "APNS_KEY_PATH = $KPATH"          || fail "APNS_KEY_PATH missing"
[[ -n "$USE_SANDBOX" ]] && ok "APNS_USE_SANDBOX = $USE_SANDBOX" || warn "APNS_USE_SANDBOX not set (defaults: try prod, fall back to sandbox)"

[[ ${#KID} -eq 10 ]] && ok "APNS_KEY_ID length is 10"     || fail "APNS_KEY_ID length is ${#KID}, expected 10"
[[ ${#TID} -eq 10 ]] && ok "APNS_TEAM_ID length is 10"    || fail "APNS_TEAM_ID length is ${#TID}, expected 10"
if [[ "$BID" != "com.era.lityourcandle1" ]]; then
    warn "APNS_BUNDLE_ID = '$BID' — make sure it matches expo/app.json bundleIdentifier exactly (currently com.era.lityourcandle1)"
else
    ok "APNS_BUNDLE_ID matches expected com.era.lityourcandle1"
fi

# ── 2. .p8 key ───────────────────────────────────────────────────────────────
hr "2) APNs .p8 file"
if [[ ! -f "$KPATH" ]]; then
    fail ".p8 file not found at $KPATH"
else
    ok ".p8 file present"
    PERM=$(stat -c '%a' "$KPATH" 2>/dev/null || stat -f '%Lp' "$KPATH")
    OWN=$(stat -c '%U:%G' "$KPATH" 2>/dev/null || stat -f '%Su:%Sg' "$KPATH")
    echo "    perms=$PERM owner=$OWN"
    [[ "$PERM" == "600" || "$PERM" == "640" || "$PERM" == "400" ]] && ok "perms locked down" || warn "perms $PERM are loose; consider chmod 640"
    if grep -q -- "-----BEGIN PRIVATE KEY-----" "$KPATH" \
        && grep -q -- "-----END PRIVATE KEY-----" "$KPATH"; then
        ok ".p8 has correct PEM header/footer"
    else
        fail ".p8 file does NOT look like a PEM private key"
    fi
fi

# ── 3. service status ────────────────────────────────────────────────────────
hr "3) service status"
if command -v systemctl >/dev/null 2>&1; then
    if systemctl is-active --quiet apache2; then ok "apache2 active"; else fail "apache2 not active"; fi
    if systemctl is-active --quiet lityc-ws; then ok "lityc-ws (websocket) active"; else fail "lityc-ws (websocket) not active"; fi
fi

# ── 4. recent logs ───────────────────────────────────────────────────────────
hr "4) recent push log lines"
if [[ -f "$LOG_FILE" ]]; then
    echo "(grepping for apns/fcm/push in $LOG_FILE — last 40 hits)"
    grep -Ei "apns|fcm|push|incoming_call" "$LOG_FILE" | tail -40
else
    warn "$LOG_FILE not found — skipping"
fi

# ── 5. live APNs round-trip ─────────────────────────────────────────────────
hr "5) live APNs round-trip"
echo "Latest push token in DB:"
TOKEN_ROW=$(php "$APP_DIR/bin/diag_token.php" 2>/dev/null || true)
echo "$TOKEN_ROW"

# ── 6. DNS / TLS reachability ────────────────────────────────────────────────
hr "6) DNS / TLS reachability"
for h in api.push.apple.com api.sandbox.push.apple.com; do
    if command -v dig >/dev/null 2>&1; then
        dig +short "$h" | head -1 | grep -q . && ok "$h resolves" || fail "$h DNS failed"
    fi
    code=$(curl -s -o /dev/null -w '%{http_code}' --http2 -X POST -H 'apns-topic: probe' \
        --data '{}' --max-time 5 "https://$h/3/device/0" 2>/dev/null)
    echo "    $h → HTTP $code  (400/403 are expected for a fake token; 0 means unreachable)"
done

hr "Done"
echo "Now run a real push test:"
echo "  sudo php $APP_DIR/bin/diag_send_push.php <user_id>"
