<?php
require_once __DIR__ . '/config.php';

if (empty($_GET['key'])) {
    http_response_code(404);
    exit;
}

$key = trim($_GET['key']);

if (!defined('database_server') || !defined('database_login') || !defined('database_password') || !defined('database_name')) {
    http_response_code(500);
    exit('Database configuration error');
}

$mysqli = @new mysqli(database_server, database_login, database_password, database_name);
if ($mysqli->connect_errno) {
    http_response_code(500);
    exit('Database connection error: ' . $mysqli->connect_error);
}
$mysqli->set_charset('utf8');

$tableUsers = defined('table_users') ? table_users : 'Service_users';

$stmt = $mysqli->prepare("SELECT * FROM `{$tableUsers}` WHERE `access_key` = ? LIMIT 1");
if (!$stmt) {
    http_response_code(500);
    $mysqli->close();
    exit('Prepare statement error');
}

$stmt->bind_param("s", $key);
$stmt->execute();
$res = $stmt->get_result();

if (!$res || $res->num_rows === 0) {
    http_response_code(404);
    $stmt->close();
    $mysqli->close();
    exit;
}

$userData = $res->fetch_assoc();
$res->free();
$stmt->close();

if (isset($userData['status']) && $userData['status'] === 'banned') {
    header('Content-Type: text/plain; charset=utf-8');
    echo "#EXTINF:-1, INFO\n";
    echo "https://kirya-coder.yzz.me/zg/ban.png\n";

    if (defined('error_client_banned') && !empty(error_client_banned)) {
        echo htmlspecialchars(error_client_banned, ENT_QUOTES, 'UTF-8') . "\n";
    }

    $mysqli->close();
    exit;
}

header('Content-Type: text/plain; charset=utf-8');

$table = defined('table_stream_source') ? table_stream_source : 'AnyStream';
$allowedTables = ['AnyStream', 'StreamSource', 'Channels'];
if (!in_array($table, $allowedTables)) {
    http_response_code(500);
    $mysqli->close();
    exit('Invalid table name');
}

$res = $mysqli->query("SELECT * FROM `{$table}`");
if (!$res) {
    http_response_code(500);
    $mysqli->close();
    exit('Query error: ' . $mysqli->error);
}

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

function escapeM3uAttribute($value) {
    if ($value === null || $value === '') return '';
    $value = str_replace('"', '&quot;', $value);
    $value = str_replace("\n", '', $value);
    $value = str_replace("\r", '', $value);
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

echo "#EXTM3U\n";

if (isset($epg_Master) && !empty($epg_Master)) {
    if (is_array($epg_Master)) {
        foreach ($epg_Master as $epgUrl) {
            $safeEpgUrl = filter_var($epgUrl, FILTER_SANITIZE_URL);
            if ($safeEpgUrl) {
                echo "#EXTM3U url-tvg=\"{$safeEpgUrl}\"\n";
            }
        }
    } else {
        $safeEpgUrl = filter_var($epg_Master, FILTER_SANITIZE_URL);
        if ($safeEpgUrl) {
            echo "#EXTM3U url-tvg=\"{$safeEpgUrl}\"\n";
        }
    }
}

$channelCount = 0;
while ($row = $res->fetch_assoc()) {
    $url = detect_stream_url($row);
    if (!$url) continue;
    
    $validUrl = filter_var($url, FILTER_VALIDATE_URL);
    if (!$validUrl) continue;

    $title = isset($row['channel_name']) ? escapeM3uAttribute($row['channel_name']) : 'Channel';
    $tvg_id = isset($row['channel_id']) ? escapeM3uAttribute($row['channel_id']) : '';
    $tvg_name = isset($row['channel_class']) ? escapeM3uAttribute($row['channel_class']) : '';
    $tvg_logo = isset($row['icon_url']) ? filter_var($row['icon_url'], FILTER_SANITIZE_URL) : '';
    $group_title = isset($row['channel_group']) ? escapeM3uAttribute($row['channel_group']) : '';

    echo "#EXTINF:-1 tvg-id=\"{$tvg_id}\" tvg-name=\"{$tvg_name}\" tvg-logo=\"{$tvg_logo}\" group-title=\"{$group_title}\",{$title}\n";
    echo "{$validUrl}\n";
    $channelCount++;
}

$res->free();
$mysqli->close();

exit;
