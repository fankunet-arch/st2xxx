<?php
/**
 * TopTea Store - KDS API Gateway (compat, PHP5/7/8)
 * - 不使用 declare(strict_types=1)
 * - 不使用 dirname 的第二参数
 * - 不使用 Throwable
 * - 不使用 realpath()，直接用字符串路径，避免 realpath 返回 false 造成 require_once('')
 */
error_reporting(E_ALL); 
ini_set('display_errors', '1'); 
ini_set('display_startup_errors', '1');

header('Content-Type: application/json; charset=utf-8');

// 计算 /store_html
// __DIR__ = /store/store_html/html/kds/api
$STORE_HTML = dirname(dirname(dirname(__DIR__))); // 回到 /store_html
$API_DIR    = __DIR__;

// 依赖路径（字符串直指，不用 realpath）
$path_config   = $STORE_HTML . '/kds/core/config.php';
$path_jsonhelp = $STORE_HTML . '/kds_backend/helpers/kds_json_helper.php';
$path_core     = $STORE_HTML . '/kds_backend/core/kds_api_core.php';
$path_registry = $API_DIR    . '/registries/kds_registry.php';

// 依赖校验（防止 require_once 传空或目录）
$need = array(
    'config.php'      => $path_config,
    'kds_json_helper' => $path_jsonhelp,
    'kds_api_core'    => $path_core,
    'kds_registry'    => $path_registry,
);
foreach ($need as $name => $f) {
    if (!is_string($f) || $f === '' || is_dir($f) || !is_file($f)) {
        http_response_code(500);
        echo json_encode(array(
            'status'  => 'error',
            'message' => "KDS gateway missing dependency: {$name}",
            'path'    => $f,
            'base'    => array('STORE_HTML' => $STORE_HTML, 'API_DIR' => $API_DIR),
        ), JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// 加载依赖
require_once $path_config;     // 提供 db() 或 $pdo
require_once $path_jsonhelp;   // json_ok/json_error 等
require_once $path_core;       // run_api()

// 注册表
$registry = require $path_registry;
if (!is_array($registry)) {
    http_response_code(500);
    echo json_encode(array(
        'status'  => 'error',
        'message' => 'KDS registry not array.',
    ), JSON_UNESCAPED_UNICODE);
    exit;
}

// 获取 PDO (已修复：直接使用 config.php 提供的全局 $pdo)
if (!isset($pdo) || !($pdo instanceof PDO)) {
    // 兼容 $GLOBALS['pdo']
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
         $pdo = $GLOBALS['pdo'];
    } else {
        // 如果 config.php 真的失败了（例如数据库密码错误）
        // 我们在这里捕获它，而不是依赖 config.php 内部的 catch
        http_response_code(500);
        echo json_encode(array(
            'status'  => 'error',
            'message' => 'PDO variable not found after loading config. Check DB credentials in kds/core/config.php',
        ), JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// 运行（仅捕获 Exception，兼容 PHP5）
try {
    run_api($registry, $pdo); // <--- 现在 $pdo 是有效的
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(array(
        'status'  => 'error',
        'message' => 'Gateway runtime exception',
        'error'   => $e->getMessage(),
    ), JSON_UNESCAPED_UNICODE);
    exit;
}