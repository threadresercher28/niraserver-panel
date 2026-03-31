<?php
ob_start();

// Проверяем, что указан URL
if (!isset($_GET['url'])) {
    http_response_code(400);
    echo "Error: No URL specified";
    ob_end_flush();
    exit;
}

$url = $_GET['url'];

// Валидируем URL
if (!filter_var($url, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    echo "Error: Invalid URL";
    ob_end_flush();
    exit;
}

// Инициализация cURL
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HEADER => true,
    CURLOPT_BUFFERSIZE => 8192,
    CURLOPT_ENCODING => ''
]);

// Передаем заголовки клиента
$headers = [];
foreach (getallheaders() as $name => $value) {
    if (strtolower($name) != 'host') {
        $headers[] = "$name: $value";
    }
}
if ($headers) {
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
}

// Выполняем запрос
$response = curl_exec($ch);

if ($response === false) {
    http_response_code(500);
    echo "Error: " . curl_error($ch);
    curl_close($ch);
    ob_end_flush();
    exit;
}

// Разделяем заголовки и тело
$header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$header_text = substr($response, 0, $header_size);
$body = substr($response, $header_size);
ob_clean();

// Отправляем заголовки
$headers_array = explode("\r\n", $header_text);
foreach ($headers_array as $header) {
    if (
        stripos($header, 'Transfer-Encoding') === false &&
        stripos($header, 'Content-Length') === false &&
        !empty($header)
    ) {
        header($header);
    }
}

// Отправляем тело
echo $body;

curl_close($ch);
ob_end_flush();
?>
 
