<?php
// proxy-playlist.php — без urlencode

$playlistUrl = $_GET['l'] ?? '';

if (!$playlistUrl || !filter_var($playlistUrl, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    exit("Bad URL");
}

$playlist = file_get_contents($playlistUrl);
if ($playlist === false) {
    http_response_code(502);
    exit("Fetch error");
}

// Base URL для относительных путей
$parsed = parse_url($playlistUrl);
$base = $parsed['scheme'] . '://' . $parsed['host'] . dirname($parsed['path']);

// Ваш прокси
$self = "http://lkc-usercontent.x10.mx/wp-admin/Nira.php";

// Переписываем плейлист
$playlist = preg_replace_callback('/^(?!#)(.+)$/m', function ($m) use ($base, $self) {
    $line = trim($m[1]);
    if ($line === '') return $line;

    // Собираем полный URL сегмента
    if (preg_match('/^https?:\/\//i', $line)) {
        $full = $line;
    } elseif (str_starts_with($line, '/')) {
        $parsedBase = parse_url($base);
        $full = $parsedBase['scheme'] . '://' . $parsedBase['host'] . $line;
    } else {
        $full = rtrim($base, '/') . '/' . $line;
    }

    // ⚠️ БЕЗ rawurlencode — как вы просили
    return $self . '?url=' . $full;
}, $playlist);

header("Content-Type: application/vnd.apple.mpegurl");
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

echo $playlist;
exit;
