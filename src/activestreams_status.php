<?php
/**
 * Active Streams - Server Status Endpoint
 * Detects Live TV playback and in-progress recordings across status-enabled
 * media servers and renders dot indicators for the dashboard widget.
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require __DIR__.'/activestreams_livetv.php';

$servers_file = "/boot/config/plugins/activestreams/servers.json";

if (!file_exists($servers_file)) {
    exit;
}

$servers = json_decode(file_get_contents($servers_file), true);

if (json_last_error() !== JSON_ERROR_NONE || !is_array($servers)) {
    error_log("Active Streams: JSON decode error in servers.json (status endpoint) - " . json_last_error_msg());
    exit;
}

// Independent of `enabled` - this is a separate poll loop from activestreams_api.php.
// Missing show_status key defaults to "on" to match ActiveStreamsSettings.page and
// activestreams_servers.php, so pre-upgrade servers.json records aren't excluded.
$servers = array_values(array_filter($servers, function ($server) {
    return ($server['show_status'] ?? '1') === '1';
}));

// Only plex/emby/jellyfin have Sessions/Timers-style endpoints to poll for Live TV
// and recording status. Dispatcharr (and any other unsupported type) falls through
// to a permanently-black dot in buildStatusCurlHandles(), which looks identical to a
// verified-idle server - exclude it here instead of rendering a misleading dot.
$servers = array_values(array_filter($servers, function ($server) {
    return in_array($server['type'] ?? '', ['plex', 'emby', 'jellyfin'], true);
}));

if (empty($servers)) {
    exit;
}

/**
 * Build the Sessions-leg and Recording-leg curl handles for one server.
 * Mirrors activestreams_api.php's buildCurlHandle URL/header construction,
 * extended with a second leg for the Activities/Timers endpoint.
 *
 * @return array{sessions: ?CurlHandle, recording: ?CurlHandle}
 */
function buildStatusCurlHandles($server) {
    $protocol = ($server['ssl'] === '1' || $server['ssl'] === true) ? 'https' : 'http';

    switch ($server['type']) {
        case 'plex':
            $sessionsUrl = "$protocol://{$server['host']}:{$server['port']}/status/sessions";
            $recordingUrl = "$protocol://{$server['host']}:{$server['port']}/media/subscriptions/scheduled";
            $headers = [
                'Accept: application/json',
                'X-Plex-Token: ' . $server['token']
            ];
            break;

        case 'emby':
            $sessionsUrl = "$protocol://{$server['host']}:{$server['port']}/emby/Sessions";
            $recordingUrl = "$protocol://{$server['host']}:{$server['port']}/emby/LiveTv/Recordings?IsInProgress=true";
            $headers = ['X-Emby-Token: ' . $server['token']];
            break;

        case 'jellyfin':
            $sessionsUrl = "$protocol://{$server['host']}:{$server['port']}/Sessions";
            $recordingUrl = "$protocol://{$server['host']}:{$server['port']}/LiveTv/Recordings?IsInProgress=true";
            $headers = ['Authorization: MediaBrowser Client="ActiveStreams", Device="Unraid", DeviceId="activestreams-plugin", Version="1.0.0", Token="' . $server['token'] . '"'];
            break;

        default:
            return ['sessions' => null, 'recording' => null];
    }

    return [
        'sessions' => makeStatusCurl($sessionsUrl, $headers, $server),
        'recording' => makeStatusCurl($recordingUrl, $headers, $server)
    ];
}

function makeStatusCurl($url, $headers, $server) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $sslVerify = isset($server['ssl_verify']) && ($server['ssl_verify'] === '1' || $server['ssl_verify'] === true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $sslVerify);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $sslVerify ? 2 : 0);

    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    return $ch;
}

/**
 * Fail-safe decode: curl error, non-200, or malformed JSON all become null
 * so callers can treat the corresponding flag as false.
 */
function decodeStatusLeg($ch) {
    $response = curl_multi_getcontent($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);

    if ($curl_error || $http_code !== 200 || !$response) {
        return null;
    }

    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return null;
    }

    return $data;
}

$mh = curl_multi_init();
$legHandles = [];   // index => ['sessions' => ch|null, 'recording' => ch|null]
$serverMap = [];

foreach ($servers as $index => $server) {
    $handles = buildStatusCurlHandles($server);
    $legHandles[$index] = $handles;
    $serverMap[$index] = $server;

    if ($handles['sessions'] !== null) {
        curl_multi_add_handle($mh, $handles['sessions']);
    }
    if ($handles['recording'] !== null) {
        curl_multi_add_handle($mh, $handles['recording']);
    }
}

$running = null;
do {
    curl_multi_exec($mh, $running);
    curl_multi_select($mh);
} while ($running > 0);

$liveTvFlags = [];
$recordingFlags = [];

foreach ($legHandles as $index => $handles) {
    $server = $serverMap[$index];

    $liveTv = false;
    $recording = false;

    if ($handles['sessions'] !== null) {
        $sessionsData = decodeStatusLeg($handles['sessions']);
        if ($sessionsData !== null) {
            $liveTv = ($server['type'] === 'plex')
                ? detectPlexLiveTv($sessionsData)
                : detectEmbyJellyfinLiveTv($sessionsData);
        }
        curl_multi_remove_handle($mh, $handles['sessions']);
        curl_close($handles['sessions']);
    }

    if ($handles['recording'] !== null) {
        $recordingData = decodeStatusLeg($handles['recording']);
        if ($recordingData !== null) {
            $recording = ($server['type'] === 'plex')
                ? detectPlexRecording($recordingData)
                : detectEmbyJellyfinRecording($recordingData);
        }
        curl_multi_remove_handle($mh, $handles['recording']);
        curl_close($handles['recording']);
    }

    $liveTvFlags[$index] = $liveTv;
    $recordingFlags[$index] = $recording;
}

curl_multi_close($mh);

function statusDotClass($flag, $onClass) {
    return $flag ? $onClass : 'ss-off-black';
}

$liveRow = "<div id=\"ss-row-live\">";
$recordingRow = "<div id=\"ss-row-recording\">";

foreach ($servers as $index => $server) {
    $name = htmlspecialchars($server['name']);

    $liveClass = statusDotClass($liveTvFlags[$index], 'ss-on-green');
    $recordingClass = statusDotClass($recordingFlags[$index], 'ss-on-red');

    $liveRow .= "<span class=\"ss-dot $liveClass\" title=\"$name\"></span>";
    $recordingRow .= "<span class=\"ss-dot $recordingClass\" title=\"$name\"></span>";
}

$liveRow .= "</div>";
$recordingRow .= "</div>";

echo $liveRow;
echo $recordingRow;
