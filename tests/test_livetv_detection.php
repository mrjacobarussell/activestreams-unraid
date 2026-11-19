<?php
// tests/test_livetv_detection.php
require __DIR__ . '/../src/activestreams_livetv.php';

function assertTrue($cond, $msg) { if (!$cond) { fwrite(STDERR, "FAIL: $msg\n"); exit(1); } echo "PASS: $msg\n"; }

// Plex live TV: a session with "live":"1" among Metadata
assertTrue(detectPlexLiveTv(['MediaContainer' => ['Metadata' => [['live' => '1']]]]) === true, 'plex live flag "1" detected');
assertTrue(detectPlexLiveTv(['MediaContainer' => ['Metadata' => [['live' => 1]]]]) === true, 'plex live flag integer 1 detected');
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
