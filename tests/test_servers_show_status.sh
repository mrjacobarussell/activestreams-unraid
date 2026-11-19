#!/bin/bash
# tests/test_servers_show_status.sh
#
# WARNING: this test temporarily overwrites your live servers.json (real API
# tokens included) while it runs, then restores it. Opt in explicitly.
set -e

[ "$AS_ALLOW_PROD_CONFIG_TEST" = "1" ] || { echo "Set AS_ALLOW_PROD_CONFIG_TEST=1 to run this test - it temporarily overwrites your live servers.json" >&2; exit 1; }

CFG_DIR=/boot/config/plugins/activestreams
BACKUP="$CFG_DIR/servers.json.plan-test-bak"

if [ -f "$CFG_DIR/servers.json" ]; then
    cp "$CFG_DIR/servers.json" "$BACKUP" || { echo "FATAL: cannot back up live config, refusing to run" >&2; exit 1; }
    RESTORE_MODE="file"
else
    RESTORE_MODE="none"
fi

restore() {
    if [ "$RESTORE_MODE" = "file" ]; then
        cp "$BACKUP" "$CFG_DIR/servers.json"
        rm -f "$BACKUP"
    else
        rm -f "$CFG_DIR/servers.json"
    fi
}
trap restore EXIT

echo '[]' > "$CFG_DIR/servers.json"
php -S 127.0.0.1:8099 -t src >/tmp/php-test-server.log 2>&1 &
SERVER_PID=$!
sleep 1

# Add a server, expect show_status defaulted to "1"
curl -s -X POST -H "X-Requested-With: XMLHttpRequest" \
  -d "action=add&type=plex&name=TestSrv&host=127.0.0.1&port=32400&token=abc" \
  http://127.0.0.1:8099/activestreams_servers.php
RESULT=$(php -r '$s=json_decode(file_get_contents("'"$CFG_DIR"'/servers.json"),true); echo $s[0]["show_status"] ?? "MISSING";')
[ "$RESULT" == "1" ] || { echo "FAIL: expected show_status=1 default, got $RESULT"; kill $SERVER_PID; exit 1; }
echo "PASS: new server defaults show_status=1"

# Toggle it off, expect show_status flips independent of enabled
curl -s -X POST -H "X-Requested-With: XMLHttpRequest" -d "action=toggle_status&index=0" \
  http://127.0.0.1:8099/activestreams_servers.php
RESULT=$(php -r '$s=json_decode(file_get_contents("'"$CFG_DIR"'/servers.json"),true); echo $s[0]["show_status"]." ".$s[0]["enabled"];')
[ "$RESULT" == "0 1" ] || { echo "FAIL: expected '0 1' (status off, enabled still on), got $RESULT"; kill $SERVER_PID; exit 1; }
echo "PASS: toggle_status flips show_status without touching enabled"

kill $SERVER_PID
echo "ALL PASS"
