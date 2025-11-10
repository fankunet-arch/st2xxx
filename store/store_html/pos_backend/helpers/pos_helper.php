<?php
/**
 * Toptea Store - POS 帮助库 (Bootstrapper)
 * Version: 1.0.2
 * Date: 2025-11-09
 */

require_once realpath(__DIR__ . '/pos_datetime_helper.php');
require_once realpath(__DIR__ . '/pos_json_helper.php');
require_once realpath(__DIR__ . '/pos_repo.php');           // 你现有的核心 Repo（保持不动）
require_once realpath(__DIR__ . '/pos_repo_ext_pass.php');  // 本次新增的 Repo 扩展
require_once realpath(__DIR__ . '/../services/PromotionEngine.php');

require_once realpath(__DIR__ . '/../core/invoicing_guard.php');
require_once realpath(__DIR__ . '/../core/shift_guard.php');

// 本文件无业务函数，仅作为加载器使用（避免跨平台依赖）
