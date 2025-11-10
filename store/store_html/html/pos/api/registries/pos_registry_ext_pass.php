<?php
/**
 * Toptea POS - 扩展注册表（次卡核销）
 * Version: 1.0.1
 * Date: 2025-11-09
 * 注意：本文件取代旧文件 "pos registry ext_pass.php"（带空格），请删除旧文件。
 *
 * [GEMINI SUPER-ENGINEER FIX (Error 2/3)]
 * 1. 移除了 'declare(strict_types=1);'。
 * 2. 此文件被 pos_api_gateway.php 包含 (include)，不应有自己的 strict_types 声明，
 * 以避免在特定 PHP 版本或配置下引发 500 错误。
 */

// [GEMINI FIX] 移除: declare(strict_types=1);

require_once realpath(__DIR__ . '/../../../../pos_backend/helpers/pos_helper.php');
require_once realpath(__DIR__ . '/../../../../pos_backend/helpers/pos_pass_helper.php');
require_once realpath(__DIR__ . '/../../../../pos_backend/helpers/pos_repo_ext_pass.php');

if (!defined('ROLE_STORE_USER')) define('ROLE_STORE_USER', 'staff');

/**
 * 次卡核销（redeem）
 * 入参（JSON）示例：
 * {
 * "member_id": 123,
 * "member_pass_id": 456,
 * "redeemed_uses_in_order": 1,
 * "device_id": "POS-01",
 * "madrid_date": "2025-11-09",
 * "cart": [{ "product_id":1001, "title_zh":"经典奶茶", "qty":1, "final_price":3.50, "addons":[] }],
 * "payment": [{ "method":"cash", "amount":0 }],
 * "idempotency_key": "abcd1234..." // 可选
 * }
 */
function handle_pass_redeem(PDO $pdo, array $config, array $input): void {
    @session_start();
    ensure_active_shift_or_fail($pdo);

    $store_id  = (int)($_SESSION['pos_store_id'] ?? 0);
    $user_id   = (int)($_SESSION['pos_user_id']  ?? 0);
    if ($store_id <= 0 || $user_id <= 0) json_error('会话缺少门店或用户信息。', 401);

    $member_id        = (int)($input['member_id'] ?? 0);
    $member_pass_id   = (int)($input['member_pass_id'] ?? 0);
    $uses_in_order    = (int)($input['redeemed_uses_in_order'] ?? 0);
    $device_id        = (string)($input['device_id'] ?? 'POS');
    $madrid_date      = (string)($input['madrid_date'] ?? date('Y-m-d'));
    $cart             = (array)($input['cart'] ?? []);
    $payment_list     = (array)($input['payment'] ?? []);
    $idempotency_key  = isset($input['idempotency_key']) ? (string)$input['idempotency_key'] : null;

    if ($member_id <= 0 || $member_pass_id <= 0 || $uses_in_order <= 0 || empty($cart)) {
        json_error('参数不完整。', 422);
    }

    // 读取 & 校验
    $store_cfg   = get_store_config_full($pdo, $store_id);
    $member_pass = get_member_pass_for_update($pdo, $member_pass_id);
    if ((int)$member_pass['member_id'] !== $member_id) json_error('会员与次卡不匹配。', 409);

    validate_pass_redeem_order($pdo, $member_pass, $madrid_date, $uses_in_order);

    // 分摊
    $cart_tags = get_cart_item_tags($pdo, $cart);
    $unit_alloc_base = (float)$member_pass['unit_allocated_base'];
    $alloc = calculate_redeem_allocation($cart_tags, $unit_alloc_base, $uses_in_order);

    $plan = ['_uses_in_this_order' => $uses_in_order];

    // 写入
    try {
        $result = create_redeem_records(
            $pdo, $store_cfg, $member_pass, $plan, $cart_tags, $alloc, $device_id, $madrid_date, $idempotency_key
        );
        json_ok($result, 'redeemed');
    } catch (Throwable $e) {
        $http_code = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500;
        $safe_message = preg_replace('/ on line \d+/', '', $e->getMessage());
        json_error('核销失败: ' . $safe_message, $http_code);
    }
}

// 导出注册表
return [
    'pass' => [
        'table' => null,
        'pk' => null,
        'soft_delete_col' => null,
        'auth_role' => ROLE_STORE_USER,
        'custom_actions' => [
            'redeem' => 'handle_pass_redeem'
        ],
    ],
];