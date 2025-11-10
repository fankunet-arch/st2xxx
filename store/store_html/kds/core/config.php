<?php
/**
 * Toptea KDS - Core Configuration File
 * Engineer: Gemini | Date: 2025-10-24
 * Revision: 3.0 (Sync with HQ Error Logging)
 */

// --- [SECURITY FIX V2.0] ---
ini_set('display_errors', '0'); // Turn off displaying errors in production
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1'); // Enable logging errors
ini_set('error_log', __DIR__ . '/php_errors_kds.log'); // Log errors to this file
// --- [END FIX] ---

error_reporting(E_ALL);
mb_internal_encoding('UTF-8');

// --- Database Configuration (Copied from HQ) ---
$db_host = 'mhdlmskvtmwsnt5z.mysql.db';
$db_name = 'mhdlmskvtmwsnt5z';
$db_user = 'mhdlmskvtmwsnt5z';
$db_pass = 'p8PQF7M8ZKLVxtjvatMkrthFQQUB9';
$db_char = 'utf8mb4';

// --- Application Settings ---
define('KDS_BASE_URL', '/kds/'); // Relative base URL for the KDS app

// --- Directory Paths ---
define('KDS_ROOT_PATH', dirname(__DIR__));
define('KDS_APP_PATH', KDS_ROOT_PATH . '/app');
define('KDS_CORE_PATH', KDS_ROOT_PATH . '/core');
define('KDS_PUBLIC_PATH', KDS_ROOT_PATH . '/html');

// --- Database Connection (PDO) ---
$dsn = "mysql:host=$db_host;dbname=$db_name;charset=$db_char";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $db_user, $db_pass, $options);
    
    // [A1 UTC SYNC] Set connection timezone to UTC
    $pdo->exec("SET time_zone='+00:00'");

} catch (\PDOException $e) {
    error_log("KDS Database connection failed: " . $e->getMessage());
    // For KDS, we must die cleanly in a way the frontend can parse
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(503); // Service Unavailable
    echo json_encode([
        'status' => 'error',
        'message' => 'DB Connection Error (KDS)',
        'data' => null
    ]);
    exit;
}