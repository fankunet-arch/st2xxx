<?php
/**
 * Toptea KDS - Get Image
 * Fetches an image from the protected 'store_images' directory.
 *
 * [SECURITY FIX V2.0]:
 * - Added basename() to $_GET['name'] to prevent Path Traversal.
 */

// 1. Get and Sanitize File Name
$name_raw = $_GET['name'] ?? 'noimg.png';

// [SECURITY FIX] Use basename() to prevent Path Traversal (e.g., ../../)
// This ensures we only get a filename.
$name = basename($name_raw);

// Failsafe: If basename returns an empty string or dot, default to noimg.
if (empty($name) || $name === '.' || $name === '..') {
    $name = 'noimg.png';
}

// 2. Define Base Path
// __DIR__ is /store/store_html/html/kds/api
$base_path = realpath(__DIR__ . '/../../../../store_images/kds');
$file_path = $base_path . '/' . $name;
$fallback_path = $base_path . '/noimg.png';

// 3. Check if file exists and is valid
if (!$base_path || strpos(realpath($file_path), $base_path) !== 0 || !is_file($file_path)) {
    // If file doesn't exist, or attempts directory traversal, use fallback
    $file_path = $fallback_path;
}

// 4. Determine MIME Type
$mime_type = 'image/png'; // Default
$extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
if ($extension === 'jpg' || $extension === 'jpeg') {
    $mime_type = 'image/jpeg';
} elseif ($extension === 'gif') {
    $mime_type = 'image/gif';
}

// 5. Output the image
if (is_file($file_path)) {
    header('Content-Type: ' . $mime_type);
    header('Content-Length: ' . filesize($file_path));
    readfile($file_path);
    exit;
} else {
    // Should not happen if noimg.png exists, but as a final failsafe:
    http_response_code(404);
    echo "Image not found.";
    exit;
}