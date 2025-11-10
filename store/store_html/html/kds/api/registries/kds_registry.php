<?php
/**
 * Toptea Store - KDS 统一 API 注册表
 * 迁移所有 store/html/kds/api/ 的逻辑
 * Version: 1.0.0 (Pre-Phase 3 Migration)
 * Date: 2025-11-08
 *
 * [A2 UTC SYNC]: Modified handle_kds_expiry_record to use utc_now().
 */

// 1. 加载所有 KDS 业务逻辑函数 (来自 kds_repo.php)
require_once realpath(__DIR__ . '/../../../../kds_backend/helpers/kds_helper.php');

// 2. 定义门店端角色常量 (必须与 kds_api_core.php 一致)
if (!defined('ROLE_STORE_MANAGER')) {
    define('ROLE_STORE_MANAGER', 'manager');
}
if (!defined('ROLE_STORE_USER')) {
    define('ROLE_STORE_USER', 'staff');
}

/* -------------------------------------------------------------------------- */
/* Handlers: 迁移自 /kds/api/sop_handler.php*/
/* -------------------------------------------------------------------------- */
function handle_kds_sop_get(PDO $pdo, array $config, array $input_data): void {
    $store_id = (int)($_SESSION['kds_store_id'] ?? 0);
    // store_id 检查已在 kds_api_core.php 中完成
    
    $raw = $_GET['code'] ?? '';
    
    // 依赖: KdsSopParser (来自 kds_repo.php)
    $parser = new KdsSopParser($pdo, $store_id);
    $seg = $parser->parse($raw);

    if (!$seg || $seg['p'] === '') json_error('编码不合法或未匹配任何解析规则', 400);

    // 依赖: get_product (来自 kds_repo.php)
    $prod = get_product($pdo, $seg['p']);
    if (!$prod || $prod['deleted_at'] !== null || (int)$prod['is_active'] !== 1) {
        json_error('找不到该产品或未上架 (P-Code: ' . htmlspecialchars($seg['p']) . ')', 404);
    }
    $pid = (int)$prod['id'];

    // 依赖: get_product_info_bilingual (来自 kds_repo.php)
    $prod_info = array_merge(
        ['product_id' => $pid, 'product_code' => $prod['product_code']],
        get_product_info_bilingual($pdo, $pid, (int)$prod['status_id'])
    );
    
    if ($seg['ord'] !== null) {
        $prod_info['order_uuid'] = $seg['ord'];
    }

    // (P-Code ONLY) 仅查询基础信息
    if ($seg['a'] === null && $seg['m'] === null && $seg['t'] === null) {
        json_ok([
            'type' => 'base_info',
            'product' => $prod_info,
            'recipe' => get_base_recipe_bilingual($pdo, $pid), // 依赖 (来自 kds_repo.php)
            'options' => get_available_options($pdo, $pid) // 依赖 (来自 kds_repo.php)
        ]);
    }

    // (P-A-M-T) 动态计算配方

    // 依赖: id_by_code (来自 kds_repo.php)
    $cup_id = id_by_code($pdo, 'kds_cups', 'cup_code', $seg['a']);
    if ($seg['a'] !== null && $cup_id === null) json_error('杯型编码 (A-code) 无效: ' . htmlspecialchars($seg['a']), 404);
    
    $ice_id = id_by_code($pdo, 'kds_ice_options', 'ice_code', $seg['m']);
    if ($seg['m'] !== null && $ice_id === null) json_error('冰量编码 (M-code) 无效: ' . htmlspecialchars($seg['m']), 404);

    $sweet_id = id_by_code($pdo, 'kds_sweetness_options', 'sweetness_code', $seg['t']);
    if ($seg['t'] !== null && $sweet_id === null) json_error('甜度编码 (T-code) 无效: ' . htmlspecialchars($seg['t']), 404);

    try {
        // 依赖: check_gating (来自 kds_repo.php)
        check_gating($pdo, $pid, $cup_id, $ice_id, $sweet_id);
    } catch (Exception $e) {
        json_error($e->getMessage(), 403, ['code' => $e->getCode()]);
    }

    // 依赖: get_base_recipe (来自 kds_repo.php)
    $recipe_map = get_base_recipe($pdo, $pid);
    if (empty($recipe_map)) json_error('产品 (P-Code: ' . htmlspecialchars($seg['p']) . ') 缺少基础配方 (L1)，无法计算。', 404);

    // 依赖: apply_global_rules (来自 kds_repo.php)
    $recipe_map = apply_global_rules($pdo, $recipe_map, $cup_id, $ice_id, $sweet_id);
    // 依赖: apply_overrides (来自 kds_repo.php)
    $recipe_map = apply_overrides($pdo, $pid, $recipe_map, $cup_id, $ice_id, $sweet_id);

    $final_recipe = [];
    foreach ($recipe_map as $item) {
        if ($item['quantity'] <= 0) continue; 
        // 依赖: m_details, u_name, norm_cat (来自 kds_repo.php)
        $m_details = m_details($pdo, (int)$item['material_id']);
        $u_names = u_name($pdo, (int)$item['unit_id']);
        $final_recipe[] = [
            'material_zh'   => $m_details['zh'],
            'material_es'   => $m_details['es'],
            'image_url'     => $m_details['image_url'],
            'unit_zh'       => $u_names['zh'],
            'unit_es'       => $u_names['es'],
            'quantity'      => (float)$item['quantity'],
            'step_category' => norm_cat((string)$item['step_category'])
        ];
    }
    
    // 依赖: get_cup_names_bilingual, etc. (来自 kds_repo.php)
    $names = array_merge(
        get_cup_names_bilingual($pdo, $cup_id),
        get_ice_names_bilingual($pdo, $ice_id),
        get_sweet_names_bilingual($pdo, $sweet_id)
    );
    $prod_info = array_merge($prod_info, $names);

    json_ok([
        'type' => 'adjusted_recipe',
        'product' => $prod_info,
        'recipe' => $final_recipe
    ]);
}

/* -------------------------------------------------------------------------- */
/* Handlers: 迁移自 /kds/api/record_expiry_item.php*/
/* -------------------------------------------------------------------------- */
function handle_kds_expiry_record(PDO $pdo, array $config, array $input_data): void {
    $store_id = (int)$_SESSION['kds_store_id'];
    $user_id = (int)$_SESSION['kds_user_id'];
    $operator_name = $_SESSION['kds_display_name'] ?? 'KDS User';
    
    $material_id = (int)($input_data['material_id'] ?? 0);
    if ($material_id <= 0) json_error('无效的物料ID。', 400);

    // 依赖: getMaterialById (来自 kds_repo.php)
    $material = getMaterialById($pdo, $material_id);
    if (!$material || empty($material['expiry_rule_type'])) {
        json_error('找不到该物料或该物料未设置效期规则。', 404);
    }

    // [A2 UTC SYNC] 依赖 datetime_helper.php
    $tz = new DateTimeZone(APP_DEFAULT_TIMEZONE);
    $opened_at_utc = utc_now();
    $opened_at_local_dt = (clone $opened_at_utc)->setTimezone($tz); // 转换为本地时间以便计算
    $expires_at_local_dt = clone $opened_at_local_dt;
    // [A2 UTC SYNC] END
    
    $time_left_text = 'N/A';

    switch ($material['expiry_rule_type']) {
        case 'HOURS':
            $duration = (int)$material['expiry_duration'];
            $expires_at_local_dt->add(new DateInterval('PT' . $duration . 'H'));
            $time_left_text = $duration . '小时';
            break;
        case 'DAYS':
            $duration = (int)$material['expiry_duration'];
            $expires_at_local_dt->add(new DateInterval('P' . $duration . 'D'));
            $time_left_text = $duration . '天';
            break;
        case 'END_OF_DAY':
            $expires_at_local_dt->setTime(23, 59, 59);
            $time_left_text = '至当日结束';
            break;
    }

    // [A2 UTC SYNC] 将计算后的本地过期时间转回 UTC 存入数据库
    $expires_at_utc = (clone $expires_at_local_dt)->setTimezone(new DateTimeZone('UTC'));

    $pdo->beginTransaction();
    $stmt_expiry = $pdo->prepare(
        "INSERT INTO kds_material_expiries (material_id, store_id, opened_at, expires_at, status) VALUES (?, ?, ?, ?, 'ACTIVE')"
    );
    $stmt_expiry->execute([
        $material_id, $store_id,
        $opened_at_utc->format('Y-m-d H:i:s'), // 存 UTC
        $expires_at_utc->format('Y-m-d H:i:s')  // 存 UTC
    ]);
    $pdo->commit();

    // [A2 UTC SYNC] 打印数据使用本地时间
    $print_data = [
        'material_name' => $material['name_zh'] ?? 'N/A',
        'material_name_es' => $material['name_es'] ?? ($material['name_zh'] ?? 'N/A'),
        'opened_at_time' => $opened_at_local_dt->format('Y-m-d H:i'),
        'expires_at_time' => $expires_at_local_dt->format('Y-m-d H:i'),
        'time_left' => $time_left_text,
        'operator_name' => $operator_name
    ];

    json_ok('效期记录已生成。', ['print_data' => $print_data]);
}

/* -------------------------------------------------------------------------- */
/* Handlers: 迁移自 /kds/api/get_preppable_materials.php*/
/* -------------------------------------------------------------------------- */
function handle_kds_get_preppable(PDO $pdo, array $config, array $input_data): void {
    $store_id = (int)$_SESSION['kds_store_id'];
    $sql = "
        SELECT 
            m.id, m.material_type,
            mt.material_name AS name_zh,
            mt_es.material_name AS name_es
        FROM kds_materials m
        LEFT JOIN kds_material_translations mt ON m.id = mt.material_id AND mt.language_code = 'zh-CN'
        LEFT JOIN kds_material_translations mt_es ON m.id = mt_es.material_id AND mt_es.language_code = 'es-ES'
        WHERE m.deleted_at IS NULL
          AND m.expiry_rule_type IS NOT NULL
        ORDER BY m.material_code ASC
    ";
    
    $stmt = $pdo->query($sql);
    if ($stmt === false) json_error("SQL query failed to execute.", 500);
    
    $all_materials = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $response_data = ['packaged_goods' => [], 'in_store_preps' => []];

    foreach ($all_materials as $material) {
        if ($material['material_type'] === 'PRODUCT' || $material['material_type'] === 'RAW') {
            $response_data['packaged_goods'][] = $material;
        } 
        elseif ($material['material_type'] === 'SEMI_FINISHED') {
            $response_data['in_store_preps'][] = $material;
        }
    }
    json_ok($response_data);
}

/* -------------------------------------------------------------------------- */
/* Handlers: 迁移自 /kds/api/get_kds_expiry_items.php*/
/* -------------------------------------------------------------------------- */
function handle_kds_get_expiry_items(PDO $pdo, array $config, array $input_data): void {
    $store_id = (int)$_SESSION['kds_store_id'];
    // [A2 UTC SYNC] opened_at 和 expires_at 已经是 UTC，无需转换
    $sql = "
        SELECT 
            e.id, e.batch_code, e.opened_at, e.expires_at,
            mt_zh.material_name AS name_zh,
            mt_es.material_name AS name_es
        FROM kds_material_expiries e
        JOIN kds_material_translations mt_zh ON e.material_id = mt_zh.material_id AND mt_zh.language_code = 'zh-CN'
        JOIN kds_material_translations mt_es ON e.material_id = mt_es.material_id AND mt_es.language_code = 'es-ES'
        WHERE e.store_id = ? AND e.status = 'ACTIVE'
        ORDER BY e.expires_at ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$store_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    json_ok($items);
}

/* -------------------------------------------------------------------------- */
/* Handlers: 迁移自 /kds/api/update_expiry_status.php*/
/* -------------------------------------------------------------------------- */
function handle_kds_update_expiry_status(PDO $pdo, array $config, array $input_data): void {
    $store_id = (int)$_SESSION['kds_store_id'];
    $handler_id = (int)$_SESSION['kds_user_id'];
    
    $item_id = (int)($input_data['item_id'] ?? 0);
    $new_status = (string)($input_data['status'] ?? '');

    if ($item_id <= 0 || !in_array($new_status, ['USED', 'DISCARDED'])) {
        json_error('无效的项目ID或状态。', 400);
    }

    // [A2 UTC SYNC] 使用 handled_at = utc_now()
    $now_utc_str = utc_now()->format('Y-m-d H:i:s');

    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
        "UPDATE kds_material_expiries SET status = ?, handler_id = ?, handled_at = ? WHERE id = ? AND store_id = ? AND status = 'ACTIVE'"
    );
    $stmt->execute([$new_status, $handler_id, $now_utc_str, $item_id, $store_id]); // [A2 UTC SYNC]

    if ($stmt->rowCount() > 0) {
        $pdo->commit();
        json_ok(null, '状态更新成功。');
    } else {
        $pdo->rollBack();
        json_error('未找到项目、项目已被更新或不属于本店。', 404);
    }
}


/* -------------------------------------------------------------------------- */
/* 注册表*/
/* -------------------------------------------------------------------------- */
return [
    
    // KDS: SOP
    'sop' => [
        'auth_role' => ROLE_STORE_USER, // KDS 角色
        'custom_actions' => [
            'get' => 'handle_kds_sop_get', // 迁移自 kds/api/sop_handler.php
        ],
    ],
    
    // KDS: Expiry
    'expiry' => [
        'auth_role' => ROLE_STORE_USER, // KDS 角色
        'custom_actions' => [
            'record' => 'handle_kds_expiry_record',       // 迁移自 kds/api/record_expiry_item.php
            'get_items' => 'handle_kds_get_expiry_items', // 迁移自 kds/api/get_kds_expiry_items.php
            'update_status' => 'handle_kds_update_expiry_status',// 迁移自 kds/api/update_expiry_status.php
        ],
    ],
    
    // KDS: Prep
    'prep' => [
        'auth_role' => ROLE_STORE_USER, // KDS 角色
        'custom_actions' => [
            'get_materials' => 'handle_kds_get_preppable', // 迁移自 kds/api/get_preppable_materials.php
        ],
    ],
];