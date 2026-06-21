<?php
require_once __DIR__ . '/config.php';
function get_stub_url($configKey, $defaultUrl) {
    if (defined($configKey)) {
        $url = constant($configKey);
        if (filter_var($url, FILTER_VALIDATE_URL)) {
            return $url;
        }
    }
    return $defaultUrl;
}

/**
 * Check if User-Agent belongs to a TV or media player
 */
function is_TV_or_Player() {
    $allow = [
        'VLC', 'MX Player', 'MXPlayer', 'Kodi', 'Plex', 'SS IPTV', 'KPlayer',
        'MoliPlayer', 'RushPlayer', 'PlayerXtreme', 'CloudStream', 'HD Player', 'AllPlayer',
        'IPlayer', 'IPTV Player', 'IPTVPlayer', 'Smarters', 'TiviMate', 'IMPlayer',
        'XCIPTV', 'Perfect Player', 'GSE SMART IPTV', 'GSE', 'Flex IPTV', 'TVIRL',
        'ProgTV', 'IPTV Extreme', 'Lazy IPTV', 'OttPlayer', 'ExoPlayer', 'Exoplayer',
        'Android MediaPlayer', 'Infuse', 'nPlayer', 'OPlayer', 'AVPlayer', 'CorePlayer',
        'M3U8 Player', 'M3U8Player', 'HLS Player', 'HLSPlayer', 'SMART-TV', 'Chromecast',
        'iptvnator', 'IPTVnator', 'Tizen', 'webOS', 'vlclib', 'IPTV', 'iptv',
        'Android TV', 'TV', 'WebOS', 'Chrome', 'Player', '  Web0S'
    ];
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

    if (empty($ua)) {
        return false;
    }

    foreach ($allow as $x) {
        if ($x && stripos($ua, $x) !== false) {
            return true;
        }
    }
    return false;
}

/**
 * Check if subscription is expired
 */
function is_subscription_expired($expires) {
    if (empty($expires) || $expires === null) {
        return false; // unlimited
    }

    $expireDate = strtotime($expires);
    if ($expireDate === false) {
        return true; // invalid date = expired
    }

    return $expireDate < time();
}

/**
 * Check group access
 */
function has_group_access($channelGroup, $allowedGroups) {
    if (empty($allowedGroups)) {
        return true;
    }
    if (empty($channelGroup)) {
        return true; // no group -> allow (depends on logic)
    }
    $groups = array_map('trim', explode(',', $allowedGroups));
    return in_array($channelGroup, $groups);
}

/**
 * Stub responses
 */
function return_404_stub() {
    header('Content-Type: text/plain; charset=utf-8');
    $url = get_stub_url('config_error_404', 'http://65fc.com/404.m3u8');
    echo "#EXTM3U\n#EXTINF:-1, 404 - Channel Not Found\n{$url}\n";
    exit;
}

function return_211_stub() {
    header('Content-Type: text/plain; charset=utf-8');
    $url = get_stub_url('config_error_211', 'http://65fc.com/211.m3u8');
    echo "#EXTM3U\n#EXTINF:-1, 211 - Access Denied\n{$url}\n";
    exit;
}

function return_503_stub() {
    header('Content-Type: text/plain; charset=utf-8');
    $url = get_stub_url('config_error_503', 'http://65fc.com/503.m3u8');
    echo "#EXTM3U\n#EXTINF:-1, 503 - Service Unavailable\n{$url}\n";
    exit;
}

function return_expired_stub() {
    header('Content-Type: text/plain; charset=utf-8');
    $url = get_stub_url('config_error_expired', 'http://65fc.com/expired.m3u8');
    echo "#EXTM3U\n#EXTINF:-1, Subscription Expired - Please Renew\n{$url}\n";
    exit;
}

// ---------------------------------------------------------------------
// MAIN
// ---------------------------------------------------------------------

// No key provided
if (empty($_GET['key'])) {
    if (is_TV_or_Player()) {
        return_404_stub();
    }
    http_response_code(404);
    exit;
}

$key = trim($_GET['key']);

// Limit key length to prevent abuse
if (strlen($key) > 64) {
    if (is_TV_or_Player()) {
        return_404_stub();
    }
    http_response_code(400);
    exit;
}

// Database configuration presence
if (!defined('database_server') || !defined('database_login') || !defined('database_password') || !defined('database_name')) {
    if (is_TV_or_Player()) {
        return_503_stub();
    }
    http_response_code(500);
    exit('Database configuration error');
}

// Connect to database
$mysqli = new mysqli(database_server, database_login, database_password, database_name);
if ($mysqli->connect_errno) {
    error_log("DB connection error: " . $mysqli->connect_error);
    if (is_TV_or_Player()) {
        return_503_stub();
    }
    http_response_code(500);
    exit('Service temporarily unavailable');
}
$mysqli->set_charset('utf8');

// Whitelist allowed user table names
$allowedUserTables = ['Service_users', 'users', 'accounts'];
$tableUsers = defined('table_users') ? table_users : 'Service_users';
if (!in_array($tableUsers, $allowedUserTables, true)) {
    error_log("Invalid table_users configuration: " . $tableUsers);
    if (is_TV_or_Player()) {
        return_503_stub();
    }
    http_response_code(500);
    exit('Database configuration error');
}

// Prepare statement to fetch user
$stmt = $mysqli->prepare("SELECT * FROM `{$tableUsers}` WHERE `access_key` = ? LIMIT 1");
if (!$stmt) {
    error_log("Prepare failed: " . $mysqli->error);
    if (is_TV_or_Player()) {
        return_503_stub();
    }
    http_response_code(500);
    $mysqli->close();
    exit('Database error');
}

$stmt->bind_param("s", $key);
$stmt->execute();
$res = $stmt->get_result();

// Key not found
if (!$res || $res->num_rows === 0) {
    if (is_TV_or_Player()) {
        return_404_stub();
    }
    http_response_code(404);
    $stmt->close();
    $mysqli->close();
    exit;
}

$userData = $res->fetch_assoc();
if (!$userData) {
    if (is_TV_or_Player()) {
        return_404_stub();
    }
    http_response_code(404);
    $stmt->close();
    $mysqli->close();
    exit;
}

$res->free();
$stmt->close();

// Check ban status
if (isset($userData['status']) && $userData['status'] === 'banned') {
    return_211_stub();
}

// Check expiry
$expires = $userData['expires'] ?? null;
if (is_subscription_expired($expires)) {
    return_expired_stub();
}

$allowedGroups = $userData['groups_access'] ?? '';

// If not a TV/player, forbid access completely
if (!is_TV_or_Player()) {
    http_response_code(403);
    exit('Access denied: Only TV and media players are allowed');
}

// ---- M3U generation ----
header('Content-Type: text/plain; charset=utf-8');

// Choose stream table with whitelist
$allowedTables = ['AnyStream', 'StreamSource', 'Channels'];
$table = defined('table_stream_source') ? table_stream_source : 'AnyStream';
if (!in_array($table, $allowedTables, true)) {
    if (is_TV_or_Player()) {
        return_503_stub();
    }
    http_response_code(500);
    $mysqli->close();
    exit('Invalid table name');
}

$res = $mysqli->query("SELECT * FROM `{$table}`");
if (!$res) {
    error_log("Query error: " . $mysqli->error);
    if (is_TV_or_Player()) {
        return_503_stub();
    }
    http_response_code(500);
    $mysqli->close();
    exit('Database query error');
}

/**
 * Detect stream URL from row columns
 */
function detect_stream_url(array $row)
{
    $candidates = ['stream_url', 'url', 'src', 'link', 'play', 'stream', 'm3u8', 'rtmp'];
    foreach ($candidates as $c) {
        if (isset($row[$c]) && trim($row[$c]) !== '') return trim($row[$c]);
    }
    foreach ($row as $v) {
        if (!is_string($v)) continue;
        $s = trim($v);
        if ($s === '') continue;
        if (
            preg_match('#^https?://#i', $s) ||
            preg_match('#^[\w\-\./]+/(?:.*\.(?:m3u8|ts|mp4|flv))#i', $s)
        ) return $s;
    }
    return null;
}

/**
 * Escape attribute value for M3U
 */
function escapeM3uAttribute($value) {
    if ($value === null || $value === '') return '';
    $value = str_replace('"', '&quot;', $value);
    $value = str_replace(["\n", "\r"], '', $value);
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

// Output playlist header
echo "#EXTM3U\n";

// EPG sources (if configured)
if (defined('epg_Master') && !empty(epg_Master)) {
    $epgValue = epg_Master;

    $decoded = json_decode($epgValue, true);
    if (is_array($decoded)) {
        foreach ($decoded as $epgUrl) {
            $safeEpgUrl = filter_var($epgUrl, FILTER_SANITIZE_URL);
            if ($safeEpgUrl) {
                echo "url-tvg=\"{$safeEpgUrl}\"\n";
            }
        }
    } else {
        $safeEpgUrl = filter_var($epgValue, FILTER_SANITIZE_URL);
        if ($safeEpgUrl) {
            echo "url-tvg=\"{$safeEpgUrl}\"\n";
        }
    }
}

// Subscription info comment
echo "#EXTM3U INFO: Subscription valid until: " . ($expires ?? 'Unlimited') . "\n";

$channelCount = 0;
$filteredCount = 0;

while ($row = $res->fetch_assoc()) {
    // 1. Определяем URL канала
    $url = detect_stream_url($row);
    if (!$url) continue;

    $validUrl = filter_var($url, FILTER_VALIDATE_URL);
    if (!$validUrl) continue;

    // 2. Проверка доступа к группе
    $channelGroup = $row['channel_group'] ?? '';
    if (!has_group_access($channelGroup, $allowedGroups)) {
        $filteredCount++;
        continue;
    }

    // 3. Формирование названия канала (берём из БД, никакой генерации!)
    $channelName = isset($row['channel_name']) ? trim($row['channel_name']) : '';
    if ($channelName === '') {
        $channelName = 'Channel'; // если поле пустое
    }
    $title = escapeM3uAttribute($channelName);

    // Остальные атрибуты
    $tvg_id   = isset($row['channel_id'])   ? escapeM3uAttribute($row['channel_id']) : '';
    $tvg_name = isset($row['channel_class'])? escapeM3uAttribute($row['channel_class']) : '';
    $tvg_logo = isset($row['icon_url'])     ? filter_var($row['icon_url'], FILTER_SANITIZE_URL) : '';
    $group_title = $channelGroup ? escapeM3uAttribute($channelGroup) : '';

    echo "#EXTINF:-1 tvg-id=\"{$tvg_id}\" tvg-name=\"{$tvg_name}\" tvg-logo=\"{$tvg_logo}\" group-title=\"{$group_title}\",{$title}\n";
    echo "{$validUrl}\n";
    $channelCount++;
}

// Если каналов нет – выдаём заглушку
if ($channelCount === 0) {
    if ($filteredCount > 0) {
        $errUrl = get_stub_url('config_error_no_group', 'http://65fc.com/no-group-access.m3u8');
        echo "#EXTINF:-1, No channels available for your subscription group\n{$errUrl}\n";
    } else {
        $errUrl = get_stub_url('config_error_404', 'http://65fc.com/404.m3u8');
        echo "#EXTINF:-1, 404 - No Channels Available\n{$errUrl}\n";
    }
}

// Логирование (опционально)
if (defined('enable_access_log') && enable_access_log) {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    $logEntry = date('Y-m-d H:i:s') . " | Key: {$key} | IP: {$_SERVER['REMOTE_ADDR']} | Channels: {$channelCount} | Filtered: {$filteredCount} | UA: {$ua}\n";
    @file_put_contents(__DIR__ . '/access.log', $logEntry, FILE_APPEND);
}

$res->free();
$mysqli->close();
exit;
