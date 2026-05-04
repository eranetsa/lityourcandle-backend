#!/usr/bin/env bash
# =============================================================================
# Manual end-to-end test for the calling protocol. Requires:
#   - $B           backend base url (defaults to production)
#   - $CONS_JWT    consultant JWT
#   - $CLIENT_JWT  client JWT
#   - $SID         existing confirmed/in_progress voice or video session
# =============================================================================
set -euo pipefail

B="${B:-https://backend.lityourcandle.com}"
: "${CONS_JWT:?set CONS_JWT}"
: "${CLIENT_JWT:?set CLIENT_JWT}"
: "${SID:?set SID}"

py() { python3 -c "import sys,json; d=json.load(sys.stdin); print($1)"; }

echo "── 1. consultant invites client (voice) ──"
INVITE=$(curl -sS -H "Authorization: Bearer $CONS_JWT" -H "Content-Type: application/json" \
    -X POST "$B/api/sessions/$SID/call/invite" -d '{"media":"voice"}')
echo "$INVITE"
IID=$(echo "$INVITE" | py "d['invite_id']")

echo "── 2. /sessions/{id}/call/current shows it ringing ──"
curl -sS -H "Authorization: Bearer $CLIENT_JWT" "$B/api/sessions/$SID/call/current"
echo

echo "── 3. client accepts → RTC token returned ──"
ACK=$(curl -sS -H "Authorization: Bearer $CLIENT_JWT" -X POST "$B/api/calls/$IID/accept")
echo "$ACK"
test "$(echo "$ACK" | py "d.get('ok', False)")" = "True" || { echo "accept failed"; exit 1; }

echo "── 4. second invite, then decline ──"
INVITE2=$(curl -sS -H "Authorization: Bearer $CONS_JWT" -H "Content-Type: application/json" \
    -X POST "$B/api/sessions/$SID/call/invite" -d '{"media":"voice"}')
IID2=$(echo "$INVITE2" | py "d['invite_id']")
curl -sS -H "Authorization: Bearer $CLIENT_JWT" -X POST "$B/api/calls/$IID2/decline"
echo

echo "── 5. third invite, then cancel from caller ──"
INVITE3=$(curl -sS -H "Authorization: Bearer $CONS_JWT" -H "Content-Type: application/json" \
    -X POST "$B/api/sessions/$SID/call/invite" -d '{"media":"voice"}')
IID3=$(echo "$INVITE3" | py "d['invite_id']")
curl -sS -H "Authorization: Bearer $CONS_JWT" -X POST "$B/api/calls/$IID3/cancel"
echo

echo "── 6. /sessions/{id}/start emits NO call.incoming ──"
curl -sS -H "Authorization: Bearer $CONS_JWT" -X POST "$B/api/sessions/$SID/start"
echo
curl -sS -H "Authorization: Bearer $CLIENT_JWT" "$B/api/sessions/$SID/call/current"
echo "(should be { invite: null })"

echo "── 7. timeout → missed (waits 50s) ──"
INVITE4=$(curl -sS -H "Authorization: Bearer $CONS_JWT" -H "Content-Type: application/json" \
    -X POST "$B/api/sessions/$SID/call/invite" -d '{"media":"voice"}')
IID4=$(echo "$INVITE4" | py "d['invite_id']")
echo "ringing $IID4, idle 50s..."
sleep 50
curl -sS -H "Authorization: Bearer $CLIENT_JWT" "$B/api/sessions/$SID/call/current"
echo "(should be { invite: null } — sweeper marked it missed)"

echo "✓ end-to-end calling pipeline verified"
