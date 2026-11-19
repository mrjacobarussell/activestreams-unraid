#!/bin/bash
# tests/test_status_endpoint.sh
#
# WARNING: this test temporarily overwrites your live servers.json (real API
# tokens included) while it runs, then restores it. Opt in explicitly.
set -e

[ "$AS_ALLOW_PROD_CONFIG_TEST" = "1" ] || { echo "Set AS_ALLOW_PROD_CONFIG_TEST=1 to run this test - it temporarily overwrites your live servers.json" >&2; exit 1; }

CFG_DIR=/boot/config/plugins/activestreams
BACKUP_SERVERS="$CFG_DIR/servers.json.plan-test-bak"

if [ -f "$CFG_DIR/servers.json" ]; then
    cp "$CFG_DIR/servers.json" "$BACKUP_SERVERS" || { echo "FATAL: cannot back up live config, refusing to run" >&2; exit 1; }
    RESTORE_MODE="file"
else
    RESTORE_MODE="none"
fi

restore() {
    if [ "$RESTORE_MODE" = "file" ]; then
        cp "$BACKUP_SERVERS" "$CFG_DIR/servers.json"
        rm -f "$BACKUP_SERVERS"
    else
        rm -f "$CFG_DIR/servers.json"
    fi
}
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

# Pre-upgrade record with no show_status key at all - must still render (default-on),
# not be silently excluded
cat > "$CFG_DIR/servers.json" <<'JSON'
[{"type":"plex","name":"PreUpgrade","host":"127.0.0.1","port":"1","token":"x","enabled":"1"}]
JSON
php -S 127.0.0.1:8099 -t src >/tmp/php-test-status3.log 2>&1 &
SERVER_PID=$!
sleep 1
OUT=$(curl -s http://127.0.0.1:8099/activestreams_status.php)
kill $SERVER_PID
[ -n "$OUT" ] || { echo "FAIL: server with no show_status key produced empty output (should default to on)"; exit 1; }
echo "$OUT" | grep -q "ss-dot" || { echo "FAIL: server with no show_status key did not render a dot. Output: $OUT"; exit 1; }
echo "PASS: server with no show_status key still renders dots (defaults to on)"

# Dispatcharr (unsupported status type) must be excluded entirely, not rendered black
echo '[{"type":"dispatcharr","name":"IPTV","host":"127.0.0.1","port":"1","token":"x","enabled":"1","show_status":"1"}]' > "$CFG_DIR/servers.json"
php -S 127.0.0.1:8099 -t src >/tmp/php-test-status4.log 2>&1 &
SERVER_PID=$!
sleep 1
OUT=$(curl -s http://127.0.0.1:8099/activestreams_status.php)
kill $SERVER_PID
[ -z "$OUT" ] || { echo "FAIL: expected empty output for Dispatcharr-only config (unsupported type excluded), got: $OUT"; exit 1; }
echo "PASS: Dispatcharr (unsupported status type) is excluded, not rendered as a black dot"

# Malformed JSON must be logged server-side, not silently swallowed.
# Point error_log at a dedicated file via -d so this doesn't depend on the
# host's php.ini error_log destination.
echo '{not valid json' > "$CFG_DIR/servers.json"
PHP_ERR_LOG=$(mktemp)
php -d error_log="$PHP_ERR_LOG" -S 127.0.0.1:8099 -t src >/tmp/php-test-status5.log 2>&1 &
SERVER_PID=$!
sleep 1
OUT=$(curl -s http://127.0.0.1:8099/activestreams_status.php)
kill $SERVER_PID
sleep 1
[ -z "$OUT" ] || { echo "FAIL: expected empty output for malformed servers.json, got: $OUT"; rm -f "$PHP_ERR_LOG"; exit 1; }
grep -q "JSON decode error in servers.json" "$PHP_ERR_LOG" || { echo "FAIL: malformed servers.json did not error_log"; rm -f "$PHP_ERR_LOG"; exit 1; }
rm -f "$PHP_ERR_LOG"
echo "PASS: malformed servers.json logs an error server-side"

echo "ALL PASS"
