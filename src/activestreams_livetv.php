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
 * Detect if any Plex recording is in progress, server-wide.
 *
 * IMPORTANT: this does NOT use the /activities endpoint's "grabber.grab"
 * activity type, despite that looking plausible from the docs. Confirmed
 * against two real simultaneous recordings plus a real live-only (not
 * recording) viewer: Plex creates an identical grabber.grab/inprogress
 * activity for ANY tuned live channel, recording or not — so it cannot
 * distinguish "being recorded to the library" from "just being watched
 * live". The reliable signal is /media/subscriptions/scheduled, whose
 * MediaGrabOperation entries only exist for actual grab/record jobs.
 *
 * @param array $scheduledData Decoded Plex /media/subscriptions/scheduled response
 * @return bool True if any MediaGrabOperation has status == "inprogress"
 */
function detectPlexRecording($scheduledData) {
    if (!isset($scheduledData['MediaContainer']['MediaGrabOperation'])) {
        return false;
    }

    $operations = $scheduledData['MediaContainer']['MediaGrabOperation'];
    if (!is_array($operations)) {
        return false;
    }

    foreach ($operations as $op) {
        if (($op['status'] ?? '') === 'inprogress') {
            return true;
        }
    }

    return false;
}

/**
 * Return the set of Plex episode guids (e.g. "plex://episode/{id}") whose
 * recording is currently in progress, for matching against a specific live
 * session's own "guid" field — lets a specific live session be identified
 * as "recording" vs "just being watched live" rather than assuming any
 * server-wide recording applies to every live session.
 *
 * @param array $scheduledData Decoded Plex /media/subscriptions/scheduled response
 * @return array<string> episode guids currently being recorded
 */
function getPlexRecordingGuids($scheduledData) {
    if (!isset($scheduledData['MediaContainer']['MediaGrabOperation'])) {
        return [];
    }

    $operations = $scheduledData['MediaContainer']['MediaGrabOperation'];
    if (!is_array($operations)) {
        return [];
    }

    $guids = [];
    foreach ($operations as $op) {
        $guid = $op['Metadata']['guid'] ?? '';
        if (($op['status'] ?? '') === 'inprogress' && $guid !== '') {
            $guids[] = $guid;
        }
    }

    return $guids;
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
