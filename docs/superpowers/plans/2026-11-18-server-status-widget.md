# Server Status Widget Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a second, independent Unraid dashboard widget to the Active Streams plugin showing, per registered media server, whether Live TV is being watched (green dot) and/or something is recording (red dot), black otherwise.

**Architecture:** A new PHP endpoint (`activestreams_status.php`) runs its own `curl_multi` batch — fully separate from the existing stream-list endpoint — over whichever servers have the new `show_status` flag on. It calls out to pure, independently-testable detection functions (`src/activestreams_livetv.php`) that take already-decoded JSON and return booleans. A new dashboard page (`ServerStatusDashboard.page`) polls that endpoint and renders two dot-rows. A new per-server toggle in the existing settings page controls the `show_status` flag, independent of the existing `enabled` flag.

**Tech Stack:** Plain PHP (no frameworks, matches existing codebase), jQuery (already loaded by Unraid webGUI, matches existing `.page` files), no new dependencies.

**Spec:** `docs/superpowers/specs/2026-11-18-server-status-widget-design.md`

**Repo state:** Fork already created (`mrjacobarussell/activestreams-unraid`), cloned to `/mnt/array_cache/github/activestreams-unraid` on Cosmo, branch `feature/server-status-widget` already checked out. `upstream` remote points at `gthrift/activestreams-unraid`.

## Global Constraints

- Reuse each server's already-stored host/port/token — no new auth flow, no new settings fields beyond `show_status`.
- Do not modify `activestreams_api.php`'s existing stream-list `curl_multi` loop or its output — the new endpoint is fully separate.
- Dot colors: black = idle, `#8cc43c` (existing "playing" green) = Live TV, `#d9534f` (existing "error" red) = recording.
- Any detection error, timeout, or unreachable server renders black — never a false green/red.
- `show_status` defaults to `"1"` for both newly-added and pre-existing servers (same default pattern already used for `enabled`).
- The whole widget tile is hidden (not rendered) when zero servers have `show_status === '1'`.
- Verify Plex and Emby field names (`live` flag, `livetv.recording` activity type, Timers `Status`) against real responses from the user's actual servers before calling this done. Jellyfin is implemented from API docs only — ship as best-effort/untested and say so plainly in the PR description.
- No new runtime dependencies.

## Review Focus

- A server configured for status but unreachable/timing out must render black on both rows, not throw a PHP error or blank the whole widget — covered in Task 4's error-path test.
- A server with `show_status=1` but `enabled=0` (or vice versa) must appear in exactly the endpoint(s) matching its own flags, independent of the other flag — covered in Task 3 (settings persistence) and Task 4 (status endpoint's own server filter, decoupled from `activestreams_api.php`).
- Zero servers with `show_status=1` must hide the tile entirely, not render an empty/blank widget — covered in Task 5.
- Emby and Jellyfin's `LiveTv/Timers` response envelope differs (`{"Items":[...]}` vs a bare array) — the recording-detection function must handle both without crashing — covered in Task 1.
- Pre-existing `servers.json` entries (written before this feature existed) have no `show_status` key at all — must default to enabled, not crash on the missing key and not silently default to hidden — covered in Task 3.

---

### Task 1: Live TV / recording detection functions

**Files:**
- Create: `src/activestreams_livetv.php`
- Create: `tests/test_livetv_detection.php`

**Interfaces:**
- Produces: `detectPlexLiveTv(array $sessionsData): bool`, `detectPlexRecording(array $activitiesData): bool`, `detectEmbyJellyfinLiveTv(array $sessionsData): bool`, `detectEmbyJellyfinRecording(array $timersData): bool` — all pure, no I/O, consumed by Task 4.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/test_livetv_detection.php
require __DIR__ . '/../src/activestreams_livetv.php';

function assertTrue($cond, $msg) { if (!$cond) { fwrite(STDERR, "FAIL: $msg\n"); exit(1); } echo "PASS: $msg\n"; }

// Plex live TV: a session with "live":"1" among Metadata
assertTrue(detectPlexLiveTv(['MediaContainer' => ['Metadata' => [['live' => '1']]]]) === true, 'plex live flag "1" detected');
assertTrue(detectPlexLiveTv(['MediaContainer' => ['Metadata' => [['title' => 'Movie']]]]) === false, 'plex no live flag = false');
assertTrue(detectPlexLiveTv(['MediaContainer' => []]) === false, 'plex empty sessions = false');

// Plex recording: an Activity with type livetv.recording
assertTrue(detectPlexRecording(['MediaContainer' => ['Activity' => [['type' => 'livetv.recording']]]]) === true, 'plex recording activity detected');
assertTrue(detectPlexRecording(['MediaContainer' => ['Activity' => [['type' => 'media.generate']]]]) === false, 'plex unrelated activity = false');
assertTrue(detectPlexRecording(['MediaContainer' => []]) === false, 'plex no activities = false');

// Emby/Jellyfin live TV: NowPlayingItem.Type == TvChannel
assertTrue(detectEmbyJellyfinLiveTv([['NowPlayingItem' => ['Type' => 'TvChannel']]]) === true, 'emby/jellyfin live tv channel detected');
assertTrue(detectEmbyJellyfinLiveTv([['NowPlayingItem' => ['Type' => 'Episode']]]) === false, 'emby/jellyfin regular playback = false');
assertTrue(detectEmbyJellyfinLiveTv([]) === false, 'emby/jellyfin no sessions = false');

// Emby/Jellyfin recording: Timers with Status == InProgress, both response envelopes
assertTrue(detectEmbyJellyfinRecording(['Items' => [['Status' => 'InProgress']]]) === true, 'emby Items-wrapped InProgress timer detected');
assertTrue(detectEmbyJellyfinRecording([['Status' => 'InProgress']]) === true, 'jellyfin bare-array InProgress timer detected');
assertTrue(detectEmbyJellyfinRecording(['Items' => [['Status' => 'Cancelled']]]) === false, 'non-InProgress timer = false');
assertTrue(detectEmbyJellyfinRecording(['Items' => []]) === false, 'no timers = false');

echo "ALL PASS\n";
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/test_livetv_detection.php`
Expected: fatal error — `src/activestreams_livetv.php` does not exist yet.

- [ ] **Step 3: Implement the four functions in `src/activestreams_livetv.php`**

Plain `<?php` file, no top-level execution, just the four function definitions from the Interfaces block. `detectEmbyJellyfinRecording` must check `$timersData['Items']` first and fall back to treating `$timersData` itself as the list (covers both Emby's and Jellyfin's response shapes per Review Focus).

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/test_livetv_detection.php`
Expected: `ALL PASS` printed, exit code 0.

- [ ] **Step 5: Commit**

```bash
cd /mnt/array_cache/github/activestreams-unraid
git add src/activestreams_livetv.php tests/test_livetv_detection.php
git commit -m "feat: add Live TV / recording detection functions"
```

---

### Task 2: Verify field names against real Plex + Emby servers

**Files:** none (read-only verification against the live servers already configured in production `/boot/config/plugins/activestreams/servers.json` on this host; no code changes unless a mismatch is found, in which case this task amends Task 1's functions/tests).

**Interfaces:**
- Consumes: the real `host`/`port`/`token` already stored for the Plex and Emby entries in production `servers.json` (read-only `GET`, never written).

- [ ] **Step 1: Read the current host/port/token for the Plex and Emby entries**

Run: `php -r '$s=json_decode(file_get_contents("/boot/config/plugins/activestreams/servers.json"),true); foreach($s as $x){ if(in_array($x["type"],["plex","emby"])) echo $x["type"]." ".$x["host"].":".$x["port"]."\n"; }'`
Expected: one line each for the configured Plex and Emby server (host:port only — do not print the token to the terminal).

- [ ] **Step 2: Confirm the Plex sessions/activities envelope shape**

Run (substitute real host/port/token): `curl -s "http://HOST:PORT/status/sessions" -H "X-Plex-Token: TOKEN" | php -r 'echo json_encode(json_decode(file_get_contents("php://stdin"),true)["MediaContainer"] ?? "MISSING", JSON_PRETTY_PRINT);'` and the same against `/activities`.
Expected: both return a `MediaContainer` key; if a Live TV session or recording happens to be active at test time, confirm the session item carries a `live` key and the activity carries `type: livetv.recording` exactly as assumed in Task 1. If nothing is live/recording right now, confirm at minimum that the envelope shape (`MediaContainer.Metadata`, `MediaContainer.Activity`) matches what Task 1's functions expect — true verification of the field values themselves has to wait for an actual live/recording session, flag this to the user as a follow-up manual check, not a blocker for merging the branch.

- [ ] **Step 3: Confirm the Emby sessions/timers envelope shape**

Run (substitute real host/port/token): `curl -s "http://HOST:PORT/emby/Sessions" -H "X-Emby-Token: TOKEN" | php -r 'var_dump(array_column(json_decode(file_get_contents("php://stdin"),true), "NowPlayingItem"));'` and `curl -s "http://HOST:PORT/emby/LiveTv/Timers" -H "X-Emby-Token: TOKEN"` directly.
Expected: confirm the Timers call returns HTTP 200 with the stored token (resolves the builder-check's token-scope-risk question) and note whether the envelope is `{"Items":[...]}` or a bare array — if it's neither, amend `detectEmbyJellyfinRecording` in Task 1 accordingly and re-run `tests/test_livetv_detection.php`.

- [ ] **Step 4: Record findings**

Add a short "Verified against real servers" note at the bottom of the spec file (`docs/superpowers/specs/2026-11-18-server-status-widget-design.md`) stating what was confirmed and what still needs a live recording/Live-TV session to fully verify.

- [ ] **Step 5: Commit**

```bash
cd /mnt/array_cache/github/activestreams-unraid
git add docs/superpowers/specs/2026-11-18-server-status-widget-design.md
git commit -m "docs: record field-verification findings against real Plex/Emby servers"
```

---

### Task 3: Per-server `show_status` toggle (settings persistence + UI)

**Files:**
- Modify: `src/activestreams_servers.php` (add `show_status` to the `add` and `edit` field sets, add a new `toggle_status` action)
- Modify: `src/ActiveStreamsSettings.page` (second toggle switch per server row, `toggleStatus(index)` JS function)

**Interfaces:**
- Produces: `show_status` key (`"1"`/`"0"`, default `"1"`) in each server's record in `servers.json`, consumed by Task 4's server filter.

- [ ] **Step 1: Write the failing functional test**

```bash
#!/bin/bash
# tests/test_servers_show_status.sh
set -e
CFG_DIR=/boot/config/plugins/activestreams
BACKUP="$CFG_DIR/servers.json.plan-test-bak"

cp "$CFG_DIR/servers.json" "$BACKUP" 2>/dev/null || echo '[]' > "$BACKUP"
restore() { cp "$BACKUP" "$CFG_DIR/servers.json"; rm -f "$BACKUP"; }
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `chmod +x tests/test_servers_show_status.sh && ./tests/test_servers_show_status.sh`
Expected: first assertion fails (`MISSING` or no `toggle_status` action) since the field/action don't exist yet.

- [ ] **Step 3: Implement in `src/activestreams_servers.php`**

Add `'show_status' => $_POST['show_status'] ?? ($servers[$index]['show_status'] ?? '1')` to the `$serverData` array built in both the `add` and `edit` cases (default `'1'` for `add`; preserve-or-default for `edit`, mirroring how `enabled` is already preserved in `edit`). Add a new `case 'toggle_status':` mirroring the existing `case 'toggle':` block exactly, but flipping `$servers[$index]['show_status']` instead of `['enabled']`.

- [ ] **Step 4: Implement the second toggle in `src/ActiveStreamsSettings.page`**

In the server list loop (around the existing `.as-toggle` block), add a second `<label class="as-toggle as-toggle-status">` checkbox bound to `$server['show_status'] ?? '1'`, calling a new `toggleStatus(index)` JS function that POSTs `action=toggle_status` — copy the existing `toggleServer()` function verbatim and rename/retarget it.

- [ ] **Step 5: Run test to verify it passes**

Run: `./tests/test_servers_show_status.sh`
Expected: `ALL PASS`, and confirm via `diff` that `/boot/config/plugins/activestreams/servers.json` was restored to its pre-test contents after the script exits (the `trap restore EXIT` handles this, but verify manually once: `md5sum` before running vs. after).

- [ ] **Step 6: Commit**

```bash
cd /mnt/array_cache/github/activestreams-unraid
git add src/activestreams_servers.php src/ActiveStreamsSettings.page tests/test_servers_show_status.sh
git commit -m "feat: add per-server show_status toggle, independent of enabled"
```

---

### Task 4: Status endpoint (`activestreams_status.php`)

**Files:**
- Create: `src/activestreams_status.php`

**Interfaces:**
- Consumes: `detectPlexLiveTv`, `detectPlexRecording`, `detectEmbyJellyfinLiveTv`, `detectEmbyJellyfinRecording` from Task 1 (`src/activestreams_livetv.php`); reads `servers.json` filtering on `show_status === '1'` (independent of `enabled`, per Task 3).
- Produces: HTML output consumed by Task 5's dashboard page — two `<div>` blocks (`id="ss-row-live"`, `id="ss-row-recording"`), each containing one `<span class="ss-dot" title="SERVERNAME">` per status-enabled server, with a class of `ss-on-green`, `ss-on-red`, or `ss-off-black`. Echoes nothing (empty string) when zero servers have `show_status=1`.

- [ ] **Step 1: Write the failing functional test**

```bash
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `chmod +x tests/test_status_endpoint.sh && ./tests/test_status_endpoint.sh`
Expected: fails — `activestreams_status.php` doesn't exist (404 / empty curl response, grep fails).

- [ ] **Step 3: Implement `src/activestreams_status.php`**

Structure mirrors `activestreams_api.php`: load `activestreams.cfg`/`servers.json`, `require __DIR__.'/activestreams_livetv.php'`, filter `$servers` to `show_status === '1'` entries, build a `curl_multi` batch of two requests per server (Sessions + Activities for `plex`; Sessions + `/emby/LiveTv/Timers` or `/Sessions` + `/LiveTv/Timers` for `emby`/`jellyfin` — reuse the existing `buildCurlHandle`-style URL/header construction from `activestreams_api.php` for the Sessions leg, add the second leg for Activities/Timers). On any `curl_error` or non-200 for either leg, treat that server's corresponding flag as `false` (fail-safe). Echo the two `<div>` rows from the Interfaces block; echo nothing if `$servers` is empty after filtering.

- [ ] **Step 4: Run test to verify it passes**

Run: `./tests/test_status_endpoint.sh`
Expected: `ALL PASS`.

- [ ] **Step 5: Commit**

```bash
cd /mnt/array_cache/github/activestreams-unraid
git add src/activestreams_status.php tests/test_status_endpoint.sh
git commit -m "feat: add activestreams_status.php endpoint for Live TV/recording detection"
```

---

### Task 5: Server Status dashboard widget

**Files:**
- Create: `src/ServerStatusDashboard.page`

**Interfaces:**
- Consumes: `activestreams_status.php` (Task 4) via polling `$.get`, same `POLL_INTERVAL` config value already read by `ActiveStreamsDashboard.page`.

- [ ] **Step 1: Write the failing test**

```bash
#!/bin/bash
# tests/test_dashboard_page_lint.sh
set -e
php -l <(sed -n '/^<?php/,/^?>/p' src/ServerStatusDashboard.page) || { echo "FAIL: PHP syntax error in ServerStatusDashboard.page"; exit 1; }
grep -q 'ss-row-live' src/ServerStatusDashboard.page || { echo "FAIL: missing live row container"; exit 1; }
grep -q 'ss-row-recording' src/ServerStatusDashboard.page || { echo "FAIL: missing recording row container"; exit 1; }
grep -q 'activestreams_status.php' src/ServerStatusDashboard.page || { echo "FAIL: not polling the status endpoint"; exit 1; }
echo "ALL PASS"
```

- [ ] **Step 2: Run test to verify it fails**

Run: `chmod +x tests/test_dashboard_page_lint.sh && ./tests/test_dashboard_page_lint.sh`
Expected: fails — file doesn't exist.

- [ ] **Step 3: Implement `src/ServerStatusDashboard.page`**

Modeled directly on `ActiveStreamsDashboard.page`'s structure (`Menu="Dashboard"`, tile-header with title "Server Status" and a settings-gear link back to the same `/Settings/ActiveStreamsSettings` page, `$mytiles['activestreams_status']['column1']` heredoc). Two label+row pairs ("Live TV" / "Recording"), each row a flex container with `flex-direction: row-reverse` (gives the right-to-left fill from the spec while keeping DOM order = settings order, so tooltips/indexing stay simple). JS: on the existing `POLL_INTERVAL` from `activestreams.cfg`, `$.get('/plugins/activestreams/activestreams_status.php', ...)` replacing the two row containers' inner HTML; if the response is empty, hide the whole tile (`$('#activestreams-status-dashboard-card').closest('tbody').hide()` or equivalent), show it again on a non-empty response (handles servers being toggled on/off without a page reload).

- [ ] **Step 4: Run test to verify it passes**

Run: `./tests/test_dashboard_page_lint.sh`
Expected: `ALL PASS`.

- [ ] **Step 5: Commit**

```bash
cd /mnt/array_cache/github/activestreams-unraid
git add src/ServerStatusDashboard.page tests/test_dashboard_page_lint.sh
git commit -m "feat: add Server Status dashboard widget"
```

---

### Task 6: Manual deploy + visual check on Cosmo (optional, not required to merge)

**Files:** none — this deploys the branch's build output to the live Unraid webGUI plugin directory for a real visual check. Flag to the user before running: this touches the actively-installed plugin on Cosmo's dashboard, reversible by restoring from the backup taken in Step 1.

- [ ] **Step 1: Back up the currently-installed plugin directory**

Run: `cp -r /usr/local/emhttp/plugins/activestreams /usr/local/emhttp/plugins/activestreams.bak-$(date +%Y%m%d)`

- [ ] **Step 2: Copy the branch's `src/` contents over the installed plugin**

Run: `cp -r /mnt/array_cache/github/activestreams-unraid/src/* /usr/local/emhttp/plugins/activestreams/`

- [ ] **Step 3: Visually confirm in the real Unraid dashboard**

Toggle `show_status` on for the Plex and Emby entries in Settings, confirm the new "Server Status" tile appears above "Active Streams" with two rows, dots black at idle, and confirm toggling `show_status` off for all servers makes the tile disappear on the next poll.

- [ ] **Step 4: Restore if anything looks wrong, otherwise leave deployed**

Run (only if needed): `rm -rf /usr/local/emhttp/plugins/activestreams && mv /usr/local/emhttp/plugins/activestreams.bak-$(date +%Y%m%d) /usr/local/emhttp/plugins/activestreams`

- [ ] **Step 5: No commit** — this task only deploys already-committed code to the live install; nothing new to commit.

---

### Task 7: Push branch and open PR

**Files:** none.

- [ ] **Step 1: Push the branch**

Run: `cd /mnt/array_cache/github/activestreams-unraid && git push -u origin feature/server-status-widget`

- [ ] **Step 2: Open the PR against upstream**

Run: `gh pr create --repo gthrift/activestreams-unraid --base main --head mrjacobarussell:feature/server-status-widget --title "Add Server Status widget (Live TV / recording indicators)" --body "..."` — body must state plainly that Jellyfin's recording/live-TV detection is implemented from API docs only and untested against a real Jellyfin Live TV setup, per the spec's constraint. (If `gh` isn't installed/authenticated in this environment, open the PR manually at `https://github.com/gthrift/activestreams-unraid/compare/main...mrjacobarussell:activestreams-unraid:feature/server-status-widget` instead.)

- [ ] **Step 3: No further commit** — PR creation is the deliverable.
