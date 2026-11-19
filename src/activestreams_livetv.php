<?php
/**
 * Active Streams - Live TV & Recording Detection
 * Pure functions for detecting active live TV and recording status across media servers
 */

/**
 * Detect if any Plex session is live TV
 *
 * @param array $sessionsData Decoded Plex /status/sessions response
 * @return bool True if any session has live="1" in Metadata
 */
function detectPlexLiveTv($sessionsData) {
    if (!isset($sessionsData['MediaContainer']['Metadata'])) {
        return false;
    }

    $metadata = $sessionsData['MediaContainer']['Metadata'];
    if (!is_array($metadata)) {
        return false;
    }

    foreach ($metadata as $item) {
        if (isset($item['live']) && (string)$item['live'] === '1') {
            return true;
        }
    }

    return false;
}

/**
 * Detect if any Plex activity is a recording in progress
 *
 * @param array $activitiesData Decoded Plex /status/sessions response with Activity
 * @return bool True if any Activity has type="livetv.recording"
 */
function detectPlexRecording($activitiesData) {
    if (!isset($activitiesData['MediaContainer']['Activity'])) {
        return false;
    }

    $activities = $activitiesData['MediaContainer']['Activity'];
    if (!is_array($activities)) {
        return false;
    }

    foreach ($activities as $activity) {
        if (isset($activity['type']) && $activity['type'] === 'livetv.recording') {
            return true;
        }
    }

    return false;
}

/**
 * Detect if any Emby/Jellyfin session is live TV
 *
 * @param array $sessionsData Array of Emby/Jellyfin session objects
 * @return bool True if any session's NowPlayingItem.Type is "TvChannel"
 */
function detectEmbyJellyfinLiveTv($sessionsData) {
    if (!is_array($sessionsData)) {
        return false;
    }

    foreach ($sessionsData as $session) {
        if (isset($session['NowPlayingItem']['Type']) && $session['NowPlayingItem']['Type'] === 'TvChannel') {
            return true;
        }
    }

    return false;
}

/**
 * Detect if any Emby/Jellyfin timer is recording in progress
 *
 * Handles both Emby (Items-wrapped) and Jellyfin (bare-array) response formats
 *
 * @param array $timersData Either {'Items': [...]} or [...]
 * @return bool True if any timer has Status="InProgress"
 */
function detectEmbyJellyfinRecording($timersData) {
    if (!is_array($timersData)) {
        return false;
    }

    // Emby format: {'Items': [...]}
    $timersList = isset($timersData['Items']) ? $timersData['Items'] : $timersData;

    if (!is_array($timersList)) {
        return false;
    }

    foreach ($timersList as $timer) {
        if (isset($timer['Status']) && $timer['Status'] === 'InProgress') {
            return true;
        }
    }

    return false;
}
