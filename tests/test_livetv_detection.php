<?php
// tests/test_livetv_detection.php
require __DIR__ . '/../src/activestreams_livetv.php';

function assertTrue($cond, $msg) { if (!$cond) { fwrite(STDERR, "FAIL: $msg\n"); exit(1); } echo "PASS: $msg\n"; }

// Plex live TV: a session with "live":"1" among Metadata
assertTrue(detectPlexLiveTv(['MediaContainer' => ['Metadata' => [['live' => '1']]]]) === true, 'plex live flag "1" detected');
assertTrue(detectPlexLiveTv(['MediaContainer' => ['Metadata' => [['live' => 1]]]]) === true, 'plex live flag integer 1 detected');
assertTrue(detectPlexLiveTv(['MediaContainer' => ['Metadata' => [['title' => 'Movie']]]]) === false, 'plex no live flag = false');
assertTrue(detectPlexLiveTv(['MediaContainer' => []]) === false, 'plex empty sessions = false');

// Plex per-session recording match: confirmed on 2026-11-19 against two real
// simultaneous DVR recordings plus a real live-only (not recording) viewer
// at the same time. A live session is "recording" only if
// its own guid appears in the recording set, not just because something
// else on the server is recording (the real bug this replaced).
assertTrue(
    getPlexRecordingGuids(['MediaContainer' => ['MediaGrabOperation' => [
        ['status' => 'inprogress', 'Metadata' => ['guid' => 'plex://episode/aaa']],
        ['status' => 'scheduled', 'Metadata' => ['guid' => 'plex://episode/bbb']],
    ]]]) === ['plex://episode/aaa'],
    'getPlexRecordingGuids returns only inprogress guids'
);
assertTrue(getPlexRecordingGuids(['MediaContainer' => []]) === [], 'getPlexRecordingGuids empty when no operations');

// Emby/Jellyfin live TV: NowPlayingItem.Type == TvChannel
assertTrue(detectEmbyJellyfinLiveTv([['NowPlayingItem' => ['Type' => 'TvChannel']]]) === true, 'emby/jellyfin live tv channel detected');
assertTrue(detectEmbyJellyfinLiveTv([['NowPlayingItem' => ['Type' => 'Episode']]]) === false, 'emby/jellyfin regular playback = false');
assertTrue(detectEmbyJellyfinLiveTv([]) === false, 'emby/jellyfin no sessions = false');

// Emby/Jellyfin recording: Timers with Status == InProgress, both response envelopes
assertTrue(detectEmbyJellyfinRecording(['Items' => [['Status' => 'InProgress']]]) === true, 'emby Items-wrapped InProgress timer detected');
assertTrue(detectEmbyJellyfinRecording([['Status' => 'InProgress']]) === true, 'jellyfin bare-array InProgress timer detected');
assertTrue(detectEmbyJellyfinRecording(['Items' => [['Status' => 'Cancelled']]]) === false, 'non-InProgress timer = false');
assertTrue(detectEmbyJellyfinRecording(['Items' => []]) === false, 'no timers = false');

// Emby/Jellyfin active recordings list: used to synthesize a stream-list row
// for a background recording with no viewer (Emby/Jellyfin create no
// session at all for that case, unlike Plex - confirmed on a real
// in-progress recording, "NBC5 News Now at 3PM", with zero matching session).
assertTrue(
    getEmbyJellyfinActiveRecordings(['Items' => [['Id' => '123', 'Status' => 'InProgress', 'Name' => 'NBC5 News Now at 3PM', 'SeriesName' => 'NBC5 News Now at 3PM']]])
        === [['id' => '123', 'name' => 'NBC5 News Now at 3PM']],
    'getEmbyJellyfinActiveRecordings: series name used when Name == SeriesName'
);
assertTrue(
    getEmbyJellyfinActiveRecordings(['Items' => [['Id' => '456', 'Status' => 'InProgress', 'Name' => 'Pilot', 'SeriesName' => 'The Institute']]])
        === [['id' => '456', 'name' => 'The Institute - Pilot']],
    'getEmbyJellyfinActiveRecordings: series + episode name combined'
);
assertTrue(getEmbyJellyfinActiveRecordings(['Items' => [['Id' => '789', 'Status' => 'Cancelled']]]) === [], 'getEmbyJellyfinActiveRecordings: non-InProgress excluded');
assertTrue(getEmbyJellyfinActiveRecordings(['Items' => []]) === [], 'getEmbyJellyfinActiveRecordings: empty when no recordings');

echo "ALL PASS\n";
