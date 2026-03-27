<?php
define('CONFIG_RUNNER','PERMISSION_DATABASE');
require_once 'config.php';
if (empty($_GET['key'])) {
    http_response_code(404);
    exit;
}

$key = trim($_GET['key']);

if (!defined('database_server') || !defined('database_login') || !defined('database_password') || !defined('database_name')) {
    http_response_code(500);
    exit;
}

$mysqli = @new mysqli(database_server, database_login, database_password, database_name);
if ($mysqli->connect_errno) {
    http_response_code(500);
    exit;
}
$mysqli->set_charset('utf8');

$tableUsers = defined('table_users') ? table_users : 'Service_users';
$keyEscaped = $mysqli->real_escape_string($key);
$res = $mysqli->query("SELECT * FROM `{$tableUsers}` WHERE `access_key`='{$keyEscaped}' LIMIT 1");
if (!$res || $res->num_rows === 0) {
    http_response_code(404);
    $mysqli->close();
    exit;
}

$userData = $res->fetch_assoc();
$res->free();

if (isset($userData['status']) && $userData['status'] === 'banned') {
    // Вывод как простой текст для забаненных
    header('Content-Type: text/plain; charset=utf-8');
    echo "#EXTINF:-1, INFO\n"."https://kirya-coder.yzz.me/zg/ban.png";

    if (defined('error_client_banned') && !empty(error_client_banned)) {
        echo error_client_banned . "\n";
    }

    $mysqli->close();
    exit;
}

// Вывод как простой текст
header('Content-Type: text/plain; charset=utf-8');

$table = defined('table_stream_source') ? table_stream_source : 'AnyStream';
$res = $mysqli->query("SELECT * FROM `{$mysqli->real_escape_string($table)}`");
if (!$res) {
    http_response_code(500);
    $mysqli->close();
    exit;
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

echo "#EXTM3U\n";

if (isset($epg_Master) && !empty($epg_Master)) {
    if (is_array($epg_Master)) {
        foreach ($epg_Master as $epgUrl) {
            echo "#EXTM3U url-tvg=\"{$epgUrl}\"\n";
        }
    } else {
        echo "#EXTM3U url-tvg=\"{$epg_Master}\"\n";
    }
}

while ($row = $res->fetch_assoc()) {
    $url = detect_stream_url($row);
    if (!$url) continue;

    $title = isset($row['channel_name']) ? $row['channel_name'] : 'Channel';
    $tvg_id = isset($row['channel_id']) ? $row['channel_id'] : '';
    $tvg_name = isset($row['channel_class']) ? $row['channel_class'] : '';
    $tvg_logo = isset($row['icon_url']) ? $row['icon_url'] : '';
    $group_title = isset($row['channel_group']) ? $row['channel_group'] : '';

    echo "#EXTINF:-1 tvg-id=\"{$tvg_id}\" tvg-name=\"{$tvg_name}\" tvg-logo=\"{$tvg_logo}\" group-title=\"{$group_title}\",{$title}\n";
    echo "{$url}\n";
}

$res->free();
$mysqli->close();
exit;
