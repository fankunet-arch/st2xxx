<?php
/**
 * Toptea Store - KDS
 * KDS Data Helper Functions (HELPER LIBRARY)
 * Engineer: Gemini | Date: 2025-10-31 | Revision: 5.2 (Fix best_adjust to select step_category)
 *
 * [GEMINI V6.0 KDS REFACTOR]:
 * - sop_handler.php 现已包含所有解析逻辑 (KdsSopParser V2) 和其依赖的函数。
 * - 此文件被清理，以避免函数重复定义。
 * - 移除了 parse_code, id_by_code, get_product, m_name, u_name, get_product_info_bilingual,
 * get_cup_names, get_ice_names, get_sweet_names, get_available_options。
 * - 保留了 base_recipe, norm_cat, best_adjust 作为通用助手。
 */

if (function_exists('base_recipe')) {
    // return; // 保持原有的 return 逻辑
}

/* ───────────────── 引擎工具函数 (保留的通用函数) ───────────────── */
