<?php
/**
 * Toptea POS - 存储库 (Repo) - 次卡扩展 (B1)
 *
 * 包含所有与次卡 (Member Pass) 相关的数据库查询函数。
 * 依赖: pos_repo.php (用于 get_cart_item_tags)
 *
 * [GEMINI FIX 2025-11-10] 修正 get_active_pass_plans 中的 SQL JOIN，
 * 将 'p.pass_plan_id = m.pass_plan_id'
 * 修正为 'p.id = m.plan_id' 以匹配数据库 schema。
 *
 * [GEMINI FIX 2025-11-10-v2] 移除了重复的 get_pass_plan_details() 函数，
 * 因为它在 pos_repo.php 中已被正确定义，导致 "Cannot redeclare" 致命错误。
 */

declare(strict_types=1);

/**
 * [B1.1] 获取商店所有可售的次卡
 */
function get_active_pass_plans(PDO $pdo, int $store_id): array {
    // [GEMINI FIX] 修正了 SQL JOIN 以匹配数据库
    // pass_plans.pass_plan_id (PK)
    // pass_plan_store_map.plan_id (FK)
    $sql = "
        SELECT p.*
        FROM pass_plans p
        JOIN pass_plan_store_map m ON p.pass_plan_id = m.plan_id
        WHERE p.is_active = 1
          AND m.store_id = ?
          AND p.deleted_at IS NULL
        ORDER BY p.display_order ASC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$store_id]);
    
    $plans = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 转换 JSON 字段
    foreach ($plans as &$plan) {
        if (isset($plan['name_translation']) && !empty($plan['name_translation'])) {
            $plan['name_translation'] = json_decode($plan['name_translation'], true);
        }
    }
    return $plans;
}

/**
 * [GEMINI FIX 2025-11-10-v2]
 * 删除了这个函数的错误重复定义。
 * 正确的版本位于 pos_repo.php
 */
/*
function get_pass_plan_details(PDO $pdo, int $plan_id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM pass_plans WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$plan_id]);
    $plan = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($plan && isset($plan['name_translation']) && !empty($plan['name_translation'])) {
        $plan['name_translation'] = json_decode($plan['name_translation'], true);
    }
    return $plan ?: null;
}
*/


/**
 * [B1.2] 获取会员所有有效的次卡
 */
function get_member_active_passes(PDO $pdo, int $member_id): array {
    $now = date('Y-m-d H:i:s');
    
    // [GEMINI FIX] 修正了 SQL JOIN
    $sql = "
        SELECT 
            mp.pass_id, 
            mp.pass_plan_id, 
            mp.remaining_uses,
            mp.expires_at,
            p.name, 
            p.name_translation,
            p.pass_type
        FROM member_passes mp
        JOIN pass_plans p ON mp.pass_plan_id = p.pass_plan_id
        WHERE mp.member_id = ?
          AND mp.remaining_uses > 0
          AND (mp.expires_at IS NULL OR mp.expires_at > ?)
          AND p.deleted_at IS NULL
        ORDER BY mp.expires_at ASC, mp.created_at ASC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$member_id, $now]);
    $passes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($passes as &$pass) {
        if (isset($pass['name_translation']) && !empty($pass['name_translation'])) {
            $pass['name_translation'] = json_decode($pass['name_translation'], true);
        }
    }
    return $passes;
}


/**
 * [B1.2] 查找可用于核销特定饮品的最佳次卡
 *
 * @param int $member_id 会员ID
 * @param int $menu_item_id 购物车中的饮品ID
 * @return array|null 找到的最佳次卡 (member_passes 记录)
 */
function find_redeemable_pass(PDO $pdo, int $member_id, int $menu_item_id): ?array {
    $now = date('Y-m-d H:i:s');

    // 1. 检查该商品是否符合任何次卡核销规则
    // (注意: 依赖 pos_repo.php 中的 get_cart_item_tags)
    $tags = get_cart_item_tags($pdo, [$menu_item_id]);
    if (empty($tags[$menu_item_id]) || !in_array('pass_eligible_beverage', $tags[$menu_item_id], true)) {
        return null; // 该商品不能被核销
    }

    // 2. 查找该会员持有的、可核销该商品的、且未过期的次卡
    // 策略：优先使用即将过期的次卡
    // [GEMINI FIX] 修正了 SQL JOIN
    $sql = "
        SELECT 
            mp.pass_id, 
            mp.pass_plan_id, 
            mp.remaining_uses,
            mp.expires_at,
            p.name,
            p.pass_type
        FROM member_passes mp
        JOIN pass_plans p ON mp.pass_plan_id = p.pass_plan_id
        JOIN pass_plan_item_map pim ON p.pass_plan_id = pim.pass_plan_id
        WHERE mp.member_id = ?
          AND mp.remaining_uses > 0
          AND (mp.expires_at IS NULL OR mp.expires_at > ?)
          AND p.deleted_at IS NULL
          AND pim.menu_item_id = ?
        ORDER BY mp.expires_at ASC, mp.created_at ASC
        LIMIT 1
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$member_id, $now, $menu_item_id]);
    $pass = $stmt->fetch(PDO::FETCH_ASSOC);

    return $pass ?: null;
}