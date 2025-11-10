<?php
/**
 * Toptea Store - KDS 帮助库 (Bootstrapper)
 * 加载所有 KDS API 需要的业务逻辑函数库。
 * Version: 1.0.0
 * Date: 2025-11-08
 *
 * [A2 UTC SYNC]: Added kds_datetime_helper.php
 */
 
// [A2 UTC SYNC] 引入新的时间助手
require_once realpath(__DIR__ . '/kds_datetime_helper.php');

// 核心业务逻辑 (SOP 引擎, 效期计算等)
require_once realpath(__DIR__ . '/kds_repo.php');

// (注意: 此文件不加载 pos_repo.php 或 PromotionEngine)
}