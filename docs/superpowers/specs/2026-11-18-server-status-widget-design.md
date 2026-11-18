# Active Streams — Server Status Widget (Live TV / Recording indicators)

Date: 2026-11-18
Target: fork of https://github.com/gthrift/activestreams-unraid, intended as a PR back upstream.

## Intent / Why

The Active Streams plugin (installed on Cosmo) already shows live playback
sessions from Plex/Emby/Jellyfin. It has no concept of DVR/Live-TV state.
The user wants an at-a-glance "is it safe to restart the server" signal:
per registered media server, is anyone watching Live TV right now, and is
anything being recorded right now. This matters because restarting Unraid
(or the server container) mid-recording or mid-live-broadcast breaks the
recording/stream, and the existing widget (a scrollable list of individual
streams) isn't a fast enough glance for that specific question.

## Goals

- A new, separate dashboard widget ("Server Status") showing one dot per
  registered server for Live TV viewing, and one dot per registered server
  for active recording.
- Dots fill right-to-left, same server order as configured in settings.
- Black = idle, Green (Live TV row) = someone watching live TV, Red
  (Recording row) = active recording in progress.
- Per-server opt-in/out of this feature, independent of the existing
  "enabled" toggle (which governs the stream-list polling).
- Feasible for Plex, Emby, and Jellyfin.

## Non-goals

- Not touching the existing Active Streams widget's layout, polling loop,
  or stream-list rendering.
- Not surfacing recording *details* (title, channel, time remaining) — dot
  state only, consistent with "restart safety glance" intent. Tooltip on
  hover can show server name only (matches existing pattern).
- Not adding scheduling, timer management, or recording controls — read
  status only.

## Anti-goals

- Don't make this so detailed it becomes a second stream list — the whole
  point is a glance, not another thing to read.
- Don't couple this to the existing per-server "enabled" flag — a server
  can have stream-list monitoring off but status dots on, or vice versa.

## Constraints

- Must reuse the server's existing stored credentials (host/port/token) —
  no new auth flow.
- Must not add a second slow poll: can ride the existing `curl_multi`
  batch in `activestreams_api.php`, one extra request per server per type
  (Plex: `/activities`; Emby/Jellyfin: `/LiveTv/Timers`). Live-TV-viewing
  detection needs no extra request — it reads a flag already present in
  the session payload the plugin already fetches.
- Must degrade cleanly for servers without a tuner/DVR configured: these
  calls return empty lists, not errors — a server with no tuner just
  never lights up. No need to detect "no tuner" explicitly, but the
  Settings toggle still lets a user suppress the row for a server they
  don't care about (shared Plex library server with no tuner, etc).

## Approaches considered

1. **Add rows to the existing Active Streams widget, pinned above the
   stream list.** Simplest file-count-wise, but couples two different
   concerns into one widget and complicates the existing CSS/JS.
2. **New, separate dashboard widget/page, own poll loop, sits above the
   Active Streams widget.** More files (new `.page`, small additions to
   the shared API/servers PHP), but keeps the diff reviewable in
   isolation and doesn't risk the existing widget's established behavior.
   **Picked — user confirmed.**

## Decided design

**New widget**: `ServerStatusDashboard.page` (new file), rendered above
the existing Active Streams tile on the Unraid dashboard. Polls a new
endpoint `activestreams_status.php` (or an added branch in the existing
API file — builder's call) on the same interval pattern as the existing
widget (reuses `POLL_INTERVAL` from `activestreams.cfg`).

**Layout**: two rows, each row = one dot per server-with-status-enabled,
filled right-to-left in server-configured order (first-configured server
ends up rightmost dot):

```
Server Status
Live TV:    ●  ●  ●
Recording:  ●  ●  ●
```

- Dot color: black (`#555`-ish, matches widget's muted palette) when
  idle; green (`#8cc43c`, matches existing "playing" status color) on the
  Live TV row when that server has a live-TV session; red (`#d9534f`,
  matches existing error/danger color) on the Recording row when that
  server has an active recording.
- Hover tooltip on each dot = server display name (same `title=` pattern
  used on the colored server-type dot in the existing stream rows).
- If **zero** servers have the status feature enabled, the whole widget
  is hidden/not rendered (mirrors "no servers configured" behavior in the
  existing widget).
- A server with the feature enabled but offline/erroring shows black on
  both rows (fail-safe: never show a false green/red).

**Per-server detection**:
- **Plex — Live TV**: a session in the already-fetched `/status/sessions`
  payload has `"live": 1` (or `"live": "1"`) on the Metadata item → green.
- **Plex — Recording**: new call to `/activities` (same token header);
  any activity with `type == "livetv.recording"` → red.
- **Emby/Jellyfin — Live TV**: a session's `NowPlayingItem.Type ==
  "TvChannel"` → green.
- **Emby/Jellyfin — Recording**: new call to `/emby/LiveTv/Timers` (Emby)
  or `/LiveTv/Timers` (Jellyfin), filtered where `Status == "InProgress"`
  → red. (Both APIs are documented and match the existing Sessions-call
  auth header already used in `activestreams_api.php`.)

**Settings UI** (`ActiveStreamsSettings.page`): a second toggle switch per
server row in the existing server list (next to the current
enabled/disabled toggle), labeled "Status" or similar, persisted as a new
`show_status` field (default `"1"` for newly added servers, matching the
existing `enabled` default pattern) in `servers.json`. New `toggle_status`
action added to `activestreams_servers.php` alongside the existing
`toggle` action, following the same request-validation pattern.

## Builder-check resolutions

A fresh-eyes review of this doc surfaced two real gaps and confirmed the
field-name risk below. Resolved:

- **Batch-inclusion conflict, corrected**: initial resolution proposed
  editing `activestreams_api.php`'s existing `curl_multi` loop to admit
  `show_status` servers. On reflection that's the wrong fix — the status
  widget already has its own endpoint and own poll loop per "Decided
  design," which means its own independent `curl_multi` batch, not a
  shared one. **Final resolution**: `activestreams_status.php` runs a
  completely separate `curl_multi` batch over servers where
  `show_status === '1'`, regardless of `enabled`. This costs one
  duplicate Sessions HTTP call for servers that have both flags on
  (negligible — small JSON, 5s-interval local LAN call), but means
  `activestreams_api.php`'s existing stream-list loop is **not modified
  at all**, fully satisfying "don't touch the existing widget's code
  path" and trivially satisfying the independent-toggle goal with no
  shared-state coupling.
- **`show_status` field survival**: `activestreams_servers.php`'s `add`
  and `edit` handlers rebuild the server record from an explicit field
  whitelist. **Resolved**: add `show_status` to that whitelist in both
  handlers (not just a new `toggle_status` action), defaulting to `"1"`
  for both newly added and pre-existing servers (read as `?? '1'`, same
  pattern already used for `enabled`) — upgraders see the new widget
  immediately without having to opt in per server.

**Verification scope — confirmed with user**: only Plex and Emby are
actually running Live TV/DVR right now (no Jellyfin tuner in use).
Build and test against those two for real; the field names below are
confirmed against actual recording/live sessions on Plex and Emby before
this is called done. Jellyfin support is written from API docs only and
shipped as best-effort/untested — call this out explicitly in the PR
description so gthrift (and Jellyfin users) know it needs real-world
verification. Token-scope risk (Timers endpoint requiring admin rights)
gets checked against the actual Emby token on file as part of that same
verification pass, not assumed.

## What's left to the builder

- New-file layout settled during builder check: `ServerStatusDashboard.page`
  (widget) + `activestreams_status.php` (its own endpoint, own
  `curl_multi` batch — see Builder-check resolutions above).
- Exact CSS for the dot row (sizing/spacing) — match the visual weight of
  the existing widget's dots, builder's call.
- Whether the new widget shares `WIDGET_ICON`/title config or gets its
  own minimal settings (a title like "Server Status" is enough; no need
  for the icon/title dropdown the main widget has) — builder's call,
  default to a fixed title, no new config sprawl.
- Caching/dedup of the extra API calls if `curl_multi` ends up firing
  more requests than wanted — builder's call based on what's simplest to
  wire into the existing batch.
- Fork mechanics: fork `gthrift/activestreams-unraid` under the user's
  GitHub account, implement on a branch, open as a PR once working and
  tested against real Plex/Emby/Jellyfin servers with DVR configured.

## Verified against real servers (2026-11-18)

Read-only checks against the user's actual Plex (192.168.0.8:32400) and
Emby (192.168.0.8:8096) instances, using the tokens already stored in the
live installed plugin's `servers.json` (no writes, no plugin files on the
real install touched):

- **Emby `/emby/LiveTv/Timers`**: HTTP 200 with the stored token — the
  token-scope-risk question is resolved, no admin-only 401/403. Response
  shape is exactly `{"Items":[{"Status":"Cancelled", ...}]}` as coded —
  confirms the `Items`-wrapped branch of `detectEmbyJellyfinRecording`.
  A real `Status` value (`"Cancelled"`) was observed; `"InProgress"` is
  still the documented value for an active recording and wasn't directly
  observed since nothing was recording at check time.
- **Emby `/emby/Sessions`**: HTTP 200, returns a plain array of session
  objects as coded (not `Items`-wrapped) — confirms
  `detectEmbyJellyfinLiveTv`'s input shape. No session had
  `NowPlayingItem.Type == "TvChannel"` at check time (no one was watching
  live TV), so that exact field/value is still unconfirmed in practice.
- **Plex `/status/sessions`** and **`/activities`**: both HTTP 200, both
  wrapped in `MediaContainer` as coded. Zero active sessions and zero
  `livetv.recording` activities at check time (14 other activity types
  seen: `provider.subscriptions.process`, `grabber.grab`,
  `library.update.section`, etc.) — confirms the envelope shape but not
  the exact `live` session flag or the `livetv.recording` activity type
  string, since nothing was live or recording during the check.

**Still open**: the `live` flag on a Plex session, the
`livetv.recording` Plex activity type, and Emby's `TvChannel`
NowPlayingItem type all need confirming against an actual live-TV or
in-progress-recording event — flag this in the PR description as
untested-until-a-real-event, same as Jellyfin is flagged as
untested-entirely. Not a blocker for merging the branch; is a blocker for
calling the feature fully verified.
