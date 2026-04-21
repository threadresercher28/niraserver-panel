<?php

$playlistUrl = $_GET['l'] ?? '';

if (!$playlistUrl || !filter_var($playlistUrl, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    exit("Bad URL");
}

// получаем плейлист
$playlist = file_get_contents($playlistUrl);
if (!$playlist) {
    http_response_code(502);
    exit("Fetch error");
}

// авто base (из URL)
$parsed = parse_url($playlistUrl);
$base = $parsed['scheme'] . '://' . $parsed['host'] . dirname($parsed['path']);

// твой прокси
$self = "http://lkc-usercontent.x10.mx/wp-admin/Nira.php";

// переписываем
$playlist = preg_replace_callback('/^(?!#)(.+)$/m', function ($m) use ($base, $self) {
    $line = trim($m[1]);

    if ($line === '') return $line;

    // абсолютный URL
    if (preg_match('/^https?:\/\//i', $line)) {
        $full = $line;
    }
    // /file.ts
    elseif (str_starts_with($line, '/')) {
        $parsedBase = parse_url($base);
        $full = $parsedBase['scheme'] . '://' . $parsedBase['host'] . $line;
    }
    // file.ts
    else {
        $full = $base . '/' . $line;
    }

    return $self . '?url=' . urlencode($full);
}, $playlist);

header("Content-Type: application/vnd.apple.mpegurl");
echo $playlist;
