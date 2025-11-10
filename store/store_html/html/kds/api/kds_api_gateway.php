<?php
/**
 * Toptea Store - KDS 统一 API 网关
 * (Based on HQ CPSYS Gateway)
 * Version: 1.0.0
 * Date: 2025-11-08
 */

// 1. 加载核心配置 (提供 $pdo)
// [MODIFIED 2c] 路径指向 kds/core
require_once realpath(__DIR__ . '/../../../kds/core/config.php');

// 2. 加载核心助手 (提供 json_ok, json_error 等)
// [MODIFIED 2c] 路径指向 kds_backend (新)
require_once realpath(__DIR__ . '/../../../kds_backend/helpers/kds_json_helper.php');

// 3. 加载核心引擎 (提供 run_api)
// [MODIFIED 2c] 路径指向 kds_backend (新)
require_once realpath(__DIR__ . '/../../../kds_backend/core/kds_api_core.php');

// 4. 加载所有资源注册表
// [MODIFIED 2c] 加载 KDS 专属注册表
$full_registry = require_once __DIR__ . '/registries/kds_registry.php';

// 5. 运行引擎
run_api($full_registry, $pdo);