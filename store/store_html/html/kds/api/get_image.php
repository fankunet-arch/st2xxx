<?php
/**
 * TopTea KDS - Secure Image Proxy
 * Return images from /store_html/store_images/kds (private) to the browser.
 * Location of this script: /store_html/html/kds/api/get_image.php
 * So base path to images is: __DIR__ . '/../../../store_images/kds'
 */
declare(strict_types=1);

// 1) Input & sanitization
$name = isset($_GET['file']) ? trim((string)$_GET['file']) : '';
$name = basename($name); // drop any path
if ($name === '' || !preg_match('/^[a-zA-Z0-9._-]+$/', $name)) {
    http_response_code(400);
    echo 'Bad request.';
    exit;
}

// 2) Resolve paths
$base_dir = realpath(__DIR__ . '/../../../store_images/kds') ?: '';
$fallback = realpath(__DIR__ . '/../../../store_images/noimg.png') ?: '';
if ($base_dir === '' || !is_dir($base_dir)) {
    http_response_code(500);
    echo 'Image base path error.';
    exit;
}
$target = $base_dir . DIRECTORY_SEPARATOR . $name;
if (!is_file($target)) {
    $target = $fallback;
}

// 3) MIME
$ext = strtolower(pathinfo($target, PATHINFO_EXTENSION));
$mime = 'application/octet-stream';
switch ($ext) {
    case 'png': $mime = 'image/png'; break;
    case 'jpg':
    case 'jpeg': $mime = 'image/jpeg'; break;
    case 'gif': $mime = 'image/gif'; break;
    case 'webp': $mime = 'image/webp'; break;
}

// 4) Output
if (is_file($target)) {
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string)filesize($target));
    header('X-Content-Type-Options: nosniff');
    readfile($target);
    exit;
}
http_response_code(404);
echo 'Not found.';
