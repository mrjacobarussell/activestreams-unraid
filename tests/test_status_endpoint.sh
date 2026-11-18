#!/bin/bash
# tests/test_status_endpoint.sh
set -e
CFG_DIR=/boot/config/plugins/activestreams
BACKUP_SERVERS="$CFG_DIR/servers.json.plan-test-bak"
cp "$CFG_DIR/servers.json" "$BACKUP_SERVERS" 2>/dev/null || echo '[]' > "$BACKUP_SERVERS"
restore() { cp "$BACKUP_SERVERS" "$CFG_DIR/servers.json"; rm -f "$BACKUP_SERVERS"; }
trap restore EXIT

# Unreachable fake server with show_status on - must degrade to black, not error
cat > "$CFG_DIR/servers.json" <<'JSON'
[{"type":"plex","name":"Unreachable","host":"127.0.0.1","port":"1","token":"x","enabled":"0","show_status":"1"}]
JSON

php -S 127.0.0.1:8099 -t src >/tmp/php-test-status.log 2>&1 &
SERVER_PID=$!
sleep 1

OUT=$(curl -s http://127.0.0.1:8099/activestreams_status.php)
kill $SERVER_PID

echo "$OUT" | grep -q "ss-off-black" || { echo "FAIL: unreachable server did not render black. Output: $OUT"; exit 1; }
echo "$OUT" | grep -q "ss-on-green\|ss-on-red" && { echo "FAIL: unreachable server rendered a false green/red"; exit 1; }
echo "PASS: unreachable server fails safe to black on both rows"

# Zero show_status servers - endpoint must echo nothing
echo '[{"type":"plex","name":"NoStatus","host":"127.0.0.1","port":"1","token":"x","enabled":"1","show_status":"0"}]' > "$CFG_DIR/servers.json"
php -S 127.0.0.1:8099 -t src >/tmp/php-test-status2.log 2>&1 &
SERVER_PID=$!
sleep 1
OUT=$(curl -s http://127.0.0.1:8099/activestreams_status.php)
kill $SERVER_PID
[ -z "$OUT" ] || { echo "FAIL: expected empty output with zero show_status servers, got: $OUT"; exit 1; }
echo "PASS: empty output when no servers have show_status enabled"

echo "ALL PASS"
