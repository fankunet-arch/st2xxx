<?php
/**
 * Toptea Store - POS 统一 API 注册表
 * 迁移所有 store/html/pos/api/ 的逻辑
 * Version: 1.2.1 (Phase 4: Load StoreConfig & CupCode)
 * Date: 2025-11-08
 *
 * [A2 UTC SYNC]: Modified handle_order_submit to use utc_now() for timestamps.
 * [B1.2 PASS]: Added 'pass' resource (purchase/redeem).
 * [B1.2.2 REFACTOR]: Merged pos_pass_handler.php functions (handle_pass_purchase, handle_pass_redeem) into this file
 * and removed the require_once for the handler file.
 * [B1.3.1 REFACTOR]: Removed handle_pass_redeem implementation. It is now loaded via pos_registry_ext_pass.php
 */

// 1. 加载所有 POS 业务逻辑函数 (来自 pos_repo.php)
require_once realpath(__DIR__ . '/../../../../pos_backend/helpers/pos_helper.php');

// [B1.2 PASS] 2. 加载次卡处理器
// [B1.2.2 REFACTOR] Removed: require_once realpath(__DIR__ . '/../handlers/pos_pass_handler.php');


// 2. 定义门店端角色常量 (必须与 pos_api_core.php 一致)
if (!defined('ROLE_STORE_MANAGER')) {
    define('ROLE_STORE_MANAGER', 'manager');
}
if (!defined('ROLE_STORE_USER')) {
    define('ROLE_STORE_USER', 'staff');
}

/* -------------------------------------------------------------------------- */
/* Handlers: 迁移自 /pos/api/submit_order.php                     */
/* -------------------------------------------------------------------------- */
function handle_order_submit(PDO $pdo, array $config, array $input_data): void {
    // 依赖: ensure_active_shift_or_fail (来自 pos_helper.php)
    ensure_active_shift_or_fail($pdo);

    $json_data = $input_data; // 网关已解析
    
    if (empty($json_data['cart']) || !is_array($json_data['cart'])) {
        json_error('Cart data is missing or empty.', 400);
    }

    $shift_id = (int)($_SESSION['pos_shift_id'] ?? 0);
    $store_id = (int)($_SESSION['pos_store_id'] ?? 0);
    $user_id  = (int)($_SESSION['pos_user_id']  ?? 0);
    
    $member_id  = isset($json_data['member_id']) ? (int)$json_data['member_id'] : null;
    $points_redeemed_from_payload = (int)($json_data['points_redeemed'] ?? 0);

    $couponCode = null;
    foreach (['coupon_code','coupon','code','promo_code','discount_code'] as $k) {
        if (!empty($json_data[$k])) { $couponCode = trim((string)$json_data[$k]); break; }
    }

    $payment_payload_raw = $json_data['payment'] ?? $json_data['payments'] ?? [];
    // 依赖: extract_payment_totals (来自 pos_repo.php)
    [, , , $sumPaid, $payment_summary] = extract_payment_totals($payment_payload_raw);

    // 依赖: get_store_config_full (来自 pos_repo.php)
    $store_config = get_store_config_full($pdo, $store_id);
    $vat_rate = (float)($store_config['default_vat_rate'] ?? 21.0);

    // 依赖: PromotionEngine (来自 pos_helper.php)
    $engine = new PromotionEngine($pdo);
    $promoResult = $engine->applyPromotions($json_data['cart'], $couponCode);
    $cart = $promoResult['cart'];
    $discount_from_promo = (float)($promoResult['discount_amount'] ?? 0.0);
    $final_total_after_promo = (float)($promoResult['final_total'] ?? 0.0);

    $pdo->beginTransaction();

    // 积分抵扣
    $points_discount_final = 0.0;
    $points_to_deduct = 0;
    if ($member_id && $points_redeemed_from_payload > 0) {
        $stmt_member = $pdo->prepare("SELECT points_balance FROM pos_members WHERE id = ? AND is_active = 1 FOR UPDATE");
        $stmt_member->execute([$member_id]);
        if ($m = $stmt_member->fetch(PDO::FETCH_ASSOC)) {
            $current_points = (int)$m['points_balance'];
            $max_possible_discount = $final_total_after_promo;
            $max_points_for_discount = (int)floor($max_possible_discount * 100);
            $points_to_deduct = min($points_redeemed_from_payload, $current_points, $max_points_for_discount);
            if ($points_to_deduct > 0) $points_discount_final = $points_to_deduct / 100.0;
            else $points_to_deduct = 0;
        }
    }

    $final_total = round($final_total_after_promo - $points_discount_final, 2);
    $discount_amount = round($discount_from_promo + $points_discount_final, 2);

    if ($sumPaid < $final_total - 0.01) {
        $pdo->rollBack();
        json_error('Payment breakdown does not match final total.', 422, [
          'final_total'=>$final_total,
          'sum_paid'=>$sumPaid,
        ]);
    }
    
    // 积分扣减与累计
    if ($member_id && $points_to_deduct > 0 && $points_discount_final > 0) {
        $pdo->prepare("UPDATE pos_members SET points_balance = points_balance - ? WHERE id = ?")
            ->execute([$points_to_deduct, $member_id]);
        $pdo->prepare("INSERT INTO pos_member_points_log (member_id, invoice_id, points_change, reason_code, notes, user_id)
                       VALUES (?,?,?,?,?,?)")
            ->execute([$member_id, null, -$points_to_deduct, 'REDEEM_DISCOUNT', "兑换抵扣 {$points_discount_final} EUR", $user_id]);
    }
    if ($member_id && $final_total > 0) {
        $stmt_settings = $pdo->query("SELECT setting_key, setting_value FROM pos_settings WHERE setting_key = 'points_euros_per_point'");
        $settings = $stmt_settings->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
        $euros_per_point = isset($settings['points_euros_per_point']) ? (float)$settings['points_euros_per_point'] : 1.0;
        if ($euros_per_point <= 0) $euros_per_point = 1.0;
        
        $points_to_add = (int)floor($final_total / $euros_per_point);
        if ($points_to_add > 0) {
            $pdo->prepare("UPDATE pos_members SET points_balance = points_balance + ? WHERE id = ?")
                ->execute([$points_to_add, $member_id]);
            $pdo->prepare("INSERT INTO pos_member_points_log (member_id, invoice_id, points_change, reason_code, user_id)
                         VALUES (?,?,?,?,?)")
                ->execute([$member_id, null, $points_to_add, 'PURCHASE', $user_id]);
        }
    }

    // 检查是否需要开票
    // 依赖: is_invoicing_enabled (来自 pos_helper.php)
    if (!is_invoicing_enabled($store_config)) {
        $pdo->commit();
        json_ok(['invoice_id' => null, 'invoice_number' => 'NO_INVOICE', 'qr_content' => null], 'Order processed without invoice.');
    }

    // --- 开票流程 ---
    $compliance_system = $store_config['billing_system'];
    
    // --- [PHASE 3a MODIFIED] ---
    // 
    // 依赖: allocate_invoice_number (来自 pos_repo.php)
    // 传递 $store_config['invoice_prefix'] 而不是 $store_config
    if (empty($store_config['invoice_prefix'])) {
         $pdo->rollBack();
         json_error('开票失败：门店缺少票号前缀 (invoice_prefix) 配置。', 412);
    }
    [$series, $invoice_number] = allocate_invoice_number(
        $pdo, 
        $store_config['invoice_prefix'], 
        $compliance_system
    );
    // --- [PHASE 3a END MOD] ---

    // [A2 UTC SYNC] 获取当前 UTC 时间
    // 依赖: datetime_helper.php (已通过 pos_helper.php 加载)
    $now_utc = utc_now();
    $issued_at_micro_utc_str = $now_utc->format('Y-m-d H:i:s.u');
    $issued_at_utc_str = $now_utc->format('Y-m-d H:i:s');
    // [A2 UTC SYNC] END

    $compliance_data = null;
    $qr_payload = null;
    if ($compliance_system) {
        $handler_path = realpath(__DIR__ . "/../../../pos_backend/compliance/{$compliance_system}Handler.php");
        if ($handler_path && file_exists($handler_path)) {
            require_once $handler_path;
            $class = $compliance_system . 'Handler';
            if (class_exists($class)) {
                $issuer_nif = (string)$store_config['tax_id'];
                // [PHASE 3a] TICKETBAI/VERIFACTU 依赖于前一个 hash
                $stmt_prev = $pdo->prepare(
                    "SELECT compliance_data FROM pos_invoices 
                     WHERE compliance_system=:system AND series=:series AND issuer_nif=:nif 
                     ORDER BY `number` DESC LIMIT 1"
                );
                $stmt_prev->execute([':system'=>$compliance_system, ':series'=>$series, ':nif'=>$issuer_nif]);
                $prev = $stmt_prev->fetch(PDO::FETCH_ASSOC);
                $previous_hash = $prev ? (json_decode($prev['compliance_data'] ?? '[]', true)['hash'] ?? null) : null;
                
                // [A2 UTC SYNC] 使用 $issued_at_micro_utc_str
                $invoiceData = ['series'=>$series,'number'=>$invoice_number,'issued_at'=>$issued_at_micro_utc_str,'final_total'=>$final_total];
                $handler = new $class();
                $compliance_data = $handler->generateComplianceData($pdo, $invoiceData, $previous_hash);
                if (is_array($compliance_data)) $qr_payload = $compliance_data['qr_content'] ?? null;
            }
        }
    }

    $taxable_base = round($final_total / (1 + ($vat_rate / 100)), 2);
    $vat_amount   = round($final_total - $taxable_base, 2);

    $stmt_invoice = $pdo->prepare("
        INSERT INTO pos_invoices (invoice_uuid, store_id, user_id, shift_id, issuer_nif, series, `number`, issued_at, invoice_type, taxable_base, vat_amount, discount_amount, final_total, status, compliance_system, compliance_data, payment_summary) 
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");
    $stmt_invoice->execute([
        bin2hex(random_bytes(16)), $store_id, $user_id, $shift_id, (string)$store_config['tax_id'],
        $series, $invoice_number, $issued_at_micro_utc_str, // [A2 UTC SYNC] 使用带毫秒的 UTC 时间
        'F2', $taxable_base, $vat_amount, $discount_amount, $final_total,
        'ISSUED', $compliance_system, json_encode($compliance_data, JSON_UNESCAPED_UNICODE),
        json_encode($payment_summary, JSON_UNESCAPED_UNICODE)
    ]);
    $invoice_id = (int)$pdo->lastInsertId();

    if ($member_id) {
        $pdo->prepare("UPDATE pos_member_points_log SET invoice_id = ? WHERE user_id = ? AND invoice_id IS NULL ORDER BY id DESC LIMIT 2")
            ->execute([$invoice_id, $user_id]);
    }

    $sql_item = "INSERT INTO pos_invoice_items (
                   invoice_id, menu_item_id, variant_id, 
                   item_name, variant_name, 
                   item_name_zh, item_name_es, variant_name_zh, variant_name_es,
                   quantity, 
                   unit_price, unit_taxable_base, vat_rate, 
                   vat_amount, customizations
               ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
    $stmt_item = $pdo->prepare($sql_item);

    // --- [PHASE 3b MOD] START: 打印任务 ---
    $print_jobs = [];
    $kitchen_items = []; // KDS厨房单的条目
    
    // [PHASE 3b] $series 已经是 {Prefix}Y{YY} (e.g., S1Y25)
    // $invoice_number 是序列号 (e.g., 1001)
    $full_invoice_number = $series . '-' . $invoice_number; // e.g., S1Y25-1001
    // [PHASE 3b] 使用纯数字 $invoice_number 作为取餐号
    $pickup_number_human = (string)$invoice_number; 

    $cup_index = 1; // [PHASE 3b] 杯序号计数器
    
    foreach ($cart as $i => $item) {
        $qty = max(1, (int)($item['qty'] ?? 1));
        $unit_price = (float)($item['final_price'] ?? $item['unit_price_eur'] ?? 0);
        $item_total = round($unit_price * $qty, 2);
        $item_tax_base_total = round($item_total / (1 + ($vat_rate / 100)), 2);
        $item_vat_amount = round($item_total - $item_tax_base_total, 2);
        $unit_tax_base = ($qty > 0) ? round($item_tax_base_total / $qty, 4) : 0;
        $custom = ['ice' => $item['ice'] ?? null, 'sugar' => $item['sugar'] ?? null, 'addons' => $item['addons'] ?? [], 'remark' => $item['remark'] ?? ''];
        
        $item_name_to_db = (string)($item['title'] ?? ($item['name'] ?? ''));
        $variant_name_to_db = (string)($item['variant_name'] ?? '');
        $item_name_zh_to_db = (string)($item['title_zh'] ?? '');
        $item_name_es_to_db = (string)($item['title_es'] ?? '');
        $variant_name_zh_to_db = (string)($item['variant_name_zh'] ?? '');
        $variant_name_es_to_db = (string)($item['variant_name_es'] ?? '');
        $menu_item_id_to_db = isset($item['product_id']) ? (int)$item['product_id'] : null;
        $variant_id_to_db = isset($item['variant_id']) ? (int)$item['variant_id'] : null;
        
        $stmt_item->execute([
            $invoice_id, $menu_item_id_to_db, $variant_id_to_db,
            $item_name_to_db, $variant_name_to_db,
            $item_name_zh_to_db, $item_name_es_to_db, $variant_name_zh_to_db, $variant_name_es_to_db,
            $qty, $unit_price, $unit_tax_base, $vat_rate, $item_vat_amount, 
            json_encode($custom, JSON_UNESCAPED_UNICODE)
        ]);

        // [PHASE 3b] 准备 KDS 厨房单 和 杯贴 的变量
        
        $customizations_parts = [];
        // TODO: 此处应按需从 $custom['ice'] 和 $custom['sugar'] 的 *code* 去查询翻译表
        if (!empty($custom['ice'])) $customizations_parts[] = 'Ice:' . $custom['ice']; 
        if (!empty($custom['sugar'])) $customizations_parts[] = 'Sugar:' . $custom['sugar'];
        if (!empty($custom['addons'])) $customizations_parts[] = '+' . implode(',+', $custom['addons']);
        $customization_detail_str = implode(' / ', $customizations_parts);
        
        // 厨房单条目
        $kitchen_items[] = [
            'item_name' => $item_name_zh_to_db,
            'variant_name' => $variant_name_zh_to_db,
            'customizations' => $customization_detail_str,
            'qty' => $qty,
            'remark' => (string)($custom['remark'] ?? ''),
        ];
        
        // [PHASE 3b] 依赖 pos_repo::get_cart_item_codes
        // $kds_codes = get_cart_item_codes($pdo, $item); // 依赖 cart.js (Phase 4.1)
        
        // [PHASE 4.1 修复] cart.js 尚未修改，我们先从 item 中读取已有的 kds_code
        $p_code = $item['product_code'] ?? 'NA';
        $cup_code = $item['cup_code'] ?? 'NA';
        $ice_code = $item['ice'] ?? 'NA';
        $sweet_code = $item['sugar'] ?? 'NA';

        // [PHASE 3b] 为每一杯生成一个杯贴任务
        for ($i_qty = 0; $i_qty < $qty; $i_qty++) {
            $kds_internal_id = $store_config['invoice_prefix'] . '-' . $invoice_number . '-' . $cup_index;
            
            $item_print_data = [
                // 计划书 3.1.B 定义的变量
                'pickup_number'    => $pickup_number_human, // e.g., "1001"
                'kds_id'           => $kds_internal_id, // e.g., "S1-1001-1"
                'store_prefix'     => $store_config['invoice_prefix'], // e.g., "S1"
                'invoice_sequence' => $invoice_number, // e.g., 1001
                'cup_index'        => $cup_index, // e.g., 1
                'product_code'     => $p_code,
                'cup_code'         => $cup_code,
                'ice_code'         => $ice_code,
                'sweet_code'       => $sweet_code,
                'cup_order_number' => $kds_internal_id, // 兼容旧模板
                // 附加变量
                'item_name'         => $item_name_to_db,
                'variant_name'      => $variant_name_to_db,
                'item_name_zh'      => $item_name_zh_to_db,
                'item_name_es'      => $item_name_es_to_db,
                'variant_name_zh'   => $variant_name_zh_to_db,
                'variant_name_es'   => $variant_name_es_to_db,
                'customization_detail' => $customization_detail_str,
                'remark'            => (string)($custom['remark'] ?? ''),
                'store_name'        => $store_config['store_name'] ?? ''
            ];

            $print_jobs[] = [
                'type'         => 'CUP_STICKER',
                'data'         => $item_print_data,
                'printer_role' => 'POS_STICKER' // <--- 关键：标记角色
            ];
            
            $cup_index++; // 递增杯序号
        }
    }

    // [PHASE 3b] 准备厨房单
    $kitchen_data = [
        'invoice_number' => $full_invoice_number,
        'issued_at'      => $issued_at_utc_str, // [A2 UTC SYNC] 使用 UTC 字符串
        'items'          => $kitchen_items,
        // [PHASE 3b] 添加新变量
        'pickup_number' => $pickup_number_human,
        'invoice_full'  => $full_invoice_number,
    ];
    $print_jobs[] = [
        'type'         => 'KITCHEN_ORDER',
        'data'         => $kitchen_data,
        'printer_role' => 'KDS_PRINTER' // <--- 关键：标记角色
    ];

    // [PHASE 3b] 准备顾客小票
    $receipt_data = [
        'store_name'      => $store_config['store_name'] ?? '',
        'store_address'   => $store_config['store_address'] ?? '',
        'store_tax_id'    => $store_config['tax_id'] ?? '',
        'invoice_number'  => $full_invoice_number, // 旧变量 (兼容)
        'issued_at'       => $issued_at_utc_str, // [A2 UTC SYNC] 使用 UTC 字符串
        'cashier_name'    => $_SESSION['pos_display_name'] ?? 'N/A',
        'qr_code'         => $qr_payload,
        'subtotal'        => number_format((float)($promoResult['subtotal'] ?? 0.0), 2),
        'discount_amount' => number_format($discount_amount, 2),
        'final_total'     => number_format($final_total, 2),
        'taxable_base'    => number_format($taxable_base, 2),
        'vat_amount'      => number_format($vat_amount, 2),
        'payment_methods' => '...', // TODO: 格式化 $payment_summary
        'change'          => number_format((float)($payment_summary['change'] ?? 0.0), 2),
        'items'           => $cart, // 模板引擎需要自己循环 cart
        // [PHASE 3b] 添加新变量
        'pickup_number'    => $pickup_number_human,
        'invoice_full'     => $full_invoice_number,
        'invoice_series'   => $series,
        'invoice_sequence' => $invoice_number,
    ];
    $print_jobs[] = [
        'type'         => 'RECEIPT',
        'data'         => $receipt_data,
        'printer_role' => 'POS_RECEIPT' // <--- 关键：标记角色
    ];
    // --- [PHASE 3b MOD] END: 打印任务 ---

    
    $pdo->commit();

    json_ok('Order created.',[
        'invoice_id'=>$invoice_id,
        'invoice_number'=>$full_invoice_number, // e.g., S1Y25-1001
        'qr_content'=>$qr_payload,
        'print_jobs' => $print_jobs
    ]);
}

/* -------------------------------------------------------------------------- */
/* Handlers: 迁移自 /pos/api/calculate_promotions.php              */
/* -------------------------------------------------------------------------- */
function handle_cart_calculate(PDO $pdo, array $config, array $input_data): void {
    $json_data = $input_data;
    if (!isset($json_data['cart'])) json_error('Cart data is missing.', 400);

    $cart = $json_data['cart'];
    $couponCode = $json_data['coupon_code'] ?? null;
    $member_id = isset($json_data['member_id']) ? (int)$json_data['member_id'] : null;
    $points_to_redeem = isset($json_data['points_to_redeem']) ? (int)$json_data['points_to_redeem'] : 0;
    
    // 依赖: PromotionEngine (来自 pos_helper.php)
    $engine = new PromotionEngine($pdo);
    $promoResult = $engine->applyPromotions($cart, $couponCode);
    
    $final_total = (float)$promoResult['final_total'];
    $points_discount = 0.0;
    $points_redeemed = 0;

    if ($member_id && $points_to_redeem > 0 && $final_total > 0) {
        $stmt_member = $pdo->prepare("SELECT points_balance FROM pos_members WHERE id = ? AND is_active = 1");
        $stmt_member->execute([$member_id]);
        $member = $stmt_member->fetch(PDO::FETCH_ASSOC);

        if ($member) {
            $current_points = (float)$member['points_balance'];
            $max_possible_discount = $final_total;
            $max_points_for_discount = floor($max_possible_discount * 100);
            $points_can_be_used = min($points_to_redeem, $current_points, $max_points_for_discount);

            if ($points_can_be_used > 0) {
                $points_redeemed = $points_can_be_used;
                $points_discount = floor($points_can_be_used) / 100.0;
                $final_total -= $points_discount;
            }
        }
    }
    
    $total_discount_amount = (float)$promoResult['discount_amount'] + $points_discount;

    $result = [
        'cart' => $promoResult['cart'],
        'subtotal' => $promoResult['subtotal'],
        'discount_amount' => number_format($total_discount_amount, 2, '.', ''),
        'final_total' => number_format($final_total, 2, '.', ''),
        'points_redemption' => [
            'points_redeemed' => $points_redeemed,
            'discount_amount' => number_format($points_discount, 2, '.', '')
        ]
    ];

    json_ok($result, 'Promotions and points calculated successfully.');
}

/* -------------------------------------------------------------------------- */
/* Handlers: 迁移自 /pos/api/pos_shift_handler.php                 */
/* -------------------------------------------------------------------------- */
function handle_shift_status(PDO $pdo, array $config, array $input_data): void {
    $store_id = (int)$_SESSION['pos_store_id'];
    $user_id = (int)$_SESSION['pos_user_id'];
    
    $stmt_store = $pdo->prepare("SELECT eod_cutoff_hour FROM kds_stores WHERE id = ?");
    $stmt_store->execute([$store_id]);
    $eod_cutoff_hour = (int)($stmt_store->fetchColumn() ?: 3);

    // [A2 UTC SYNC] 依赖 datetime_helper.php
    $tzMadrid = new DateTimeZone(APP_DEFAULT_TIMEZONE);
    $now_madrid = new DateTime('now', $tzMadrid);
    
    $today_cutoff_dt_madrid = (clone $now_madrid)->setTime($eod_cutoff_hour, 0, 0);
    $cutoff_dt_utc_str = (clone $today_cutoff_dt_madrid)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    // [A2 UTC SYNC] END

    $stmt_any = $pdo->prepare(
        "SELECT s.id, s.user_id, s.start_time, u.display_name
         FROM pos_shifts s
         LEFT JOIN kds_users u ON s.user_id = u.id AND s.store_id = u.store_id
         WHERE s.store_id=? AND s.status='ACTIVE'
         ORDER BY s.id ASC LIMIT 1"
    );
    $stmt_any->execute([$store_id]);
    $active_shift = $stmt_any->fetch(PDO::FETCH_ASSOC);

    if (!$active_shift) {
        unset($_SESSION['pos_shift_id']);
        json_ok(['has_active_shift'=>false, 'ghost_shift_detected'=>false], 'No active shift.');
    }

    $is_ghost = ($active_shift['start_time'] < $cutoff_dt_utc_str);

    if ($is_ghost) {
        if ((int)$active_shift['user_id'] === $user_id) {
            unset($_SESSION['pos_shift_id']);
        }
        // [A2 UTC SYNC] 格式化鬼班次时间
        $ghost_start_local = fmt_local($active_shift['start_time'], 'Y-m-d H:i', APP_DEFAULT_TIMEZONE);
        json_ok([
            'has_active_shift' => false,
            'ghost_shift_detected' => true,
            'ghost_shift_user_name' => $active_shift['display_name'] ?? '未知员工',
            'ghost_shift_start_time' => $ghost_start_local // e.g., "2025-11-08 15:30"
        ], 'Ghost shift detected.');
    } else {
        if ((int)$active_shift['user_id'] === $user_id) {
            $_SESSION['pos_shift_id'] = (int)$active_shift['id'];
            json_ok(['has_active_shift'=>true, 'shift'=>$active_shift], 'Active shift found for current user.');
        } else {
            unset($_SESSION['pos_shift_id']);
            json_ok(['has_active_shift'=>false, 'ghost_shift_detected'=>false], 'Another user shift is active.');
        }
    }
}
function handle_shift_start(PDO $pdo, array $config, array $input_data): void {
    $store_id = (int)$_SESSION['pos_store_id'];
    $user_id = (int)$_SESSION['pos_user_id'];
    $starting_float = (float)($input_data['starting_float'] ?? -1);
    if ($starting_float < 0) json_error('Invalid starting_float.', 422);

    // [A2 UTC SYNC] 依赖 datetime_helper.php
    $now_utc_str = utc_now()->format('Y-m-d H:i:s');

    $tx_started = false;
    if (!$pdo->inTransaction()) { $pdo->beginTransaction(); $tx_started = true; }

    $chk = $pdo->prepare("SELECT id FROM pos_shifts WHERE user_id=? AND store_id=? AND status='ACTIVE' ORDER BY id DESC LIMIT 1 FOR UPDATE");
    $chk->execute([$user_id, $store_id]);
    if ($existing_id = $chk->fetchColumn()) {
        $_SESSION['pos_shift_id'] = (int)$existing_id;
        if ($tx_started && $pdo->inTransaction()) $pdo->commit();
        json_ok(['shift_id' => $existing_id], 'Shift already active (reused).');
    }

    $chk_ghost = $pdo->prepare("SELECT id FROM pos_shifts WHERE store_id=? AND status='ACTIVE' LIMIT 1 FOR UPDATE");
    $chk_ghost->execute([$store_id]);
    if ($chk_ghost->fetchColumn()) {
         if ($tx_started && $pdo->inTransaction()) $pdo->rollBack();
         json_error('Cannot start shift, another shift is still active.', 409);
    }

    $uuid = bin2hex(random_bytes(16));
    $ins = $pdo->prepare("INSERT INTO pos_shifts (shift_uuid, store_id, user_id, start_time, status, starting_float) VALUES (?, ?, ?, ?, 'ACTIVE', ?)");
    $ins->execute([$uuid, $store_id, $user_id, $now_utc_str, $starting_float]);
    $shift_id = (int)$pdo->lastInsertId();

    if ($tx_started && $pdo->inTransaction()) $pdo->commit();
    $_SESSION['pos_shift_id'] = $shift_id;

    // [B1.2] 查询上一班的估清快照
    $stmt_snapshot = $pdo->prepare("SELECT sold_out_state_snapshot FROM pos_daily_tracking WHERE store_id = ?");
    $stmt_snapshot->execute([$store_id]);
    $snapshot_json = $stmt_snapshot->fetchColumn();
    $snapshot = $snapshot_json ? json_decode($snapshot_json, true) : [];
    
    $prompt_decision = false;
    $snapshot_count = 0;
    
    if (!empty($snapshot)) {
        $prompt_decision = true;
        $snapshot_count = count($snapshot);
        // [B1.2] 将快照应用到当前的估清表
        $sql_apply = "INSERT INTO pos_product_availability (store_id, menu_item_id, is_sold_out, updated_at) VALUES (:store_id, :menu_item_id, 1, :now) ON DUPLICATE KEY UPDATE is_sold_out = 1, updated_at = :now";
        $stmt_apply = $pdo->prepare($sql_apply);
        foreach ($snapshot as $menu_item_id) {
            $stmt_apply->execute([
                ':store_id' => $store_id,
                ':menu_item_id' => (int)$menu_item_id,
                ':now' => $now_utc_str
            ]);
        }
    }

    json_ok('Shift started.',[
        'shift'=>[ 'id'=>$shift_id, 'start_time'=>$now_utc_str, 'starting_float'=>(float)$starting_float ],
        // [B1.2] 返回估清决策
        'prompt_sold_out_decision' => $prompt_decision,
        'snapshot_item_count' => $snapshot_count
    ]);
}
function handle_shift_end(PDO $pdo, array $config, array $input_data): void {
    $store_id = (int)$_SESSION['pos_store_id'];
    $user_id = (int)$_SESSION['pos_user_id'];
    $shift_id = (int)($_SESSION['pos_shift_id'] ?? 0);
    $counted_cash = (float)($input_data['counted_cash'] ?? -1);
    
    if ($shift_id <= 0) json_error('No active shift in session.', 400);
    if ($counted_cash < 0) json_error('Invalid counted_cash.', 422);

    // [A2 UTC SYNC] 依赖 datetime_helper.php
    $now_utc_str = utc_now()->format('Y-m-d H:i:s');

    $tx_started = false;
    if (!$pdo->inTransaction()) { $pdo->beginTransaction(); $tx_started = true; }

    $lock = $pdo->prepare("SELECT id, start_time, starting_float FROM pos_shifts WHERE id=? AND user_id=? AND store_id=? AND status='ACTIVE' FOR UPDATE");
    $lock->execute([$shift_id, $user_id, $store_id]);
    $shift = $lock->fetch(PDO::FETCH_ASSOC);
    if (!$shift) {
        if ($tx_started && $pdo->inTransaction()) $pdo->rollBack();
        json_error('Active shift not found or already ended.', 404);
    }

    // 依赖: compute_expected_cash (来自 pos_repo.php)
    // [A2 UTC SYNC] $shift['start_time'] 已经是 UTC 字符串
    $totals = compute_expected_cash($pdo, $store_id, $shift['start_time'], $now_utc_str, (float)$shift['starting_float']);
    $expected_cash = (float)$totals['expected_cash'];
    $cash_diff     = round((float)$counted_cash - $expected_cash, 2);

    $upd = $pdo->prepare("UPDATE pos_shifts SET end_time=?, status='ENDED', counted_cash=? WHERE id=?");
    $upd->execute([$now_utc_str, $counted_cash, $shift_id]);

    if (table_exists($pdo, 'pos_eod_records')) {
        $ins = $pdo->prepare("INSERT INTO pos_eod_records
          (shift_id, store_id, user_id, started_at, ended_at, starting_float,
           cash_sales, cash_in, cash_out, cash_refunds, expected_cash, counted_cash, cash_diff)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $ins->execute([
            $shift_id, $store_id, $user_id, $shift['start_time'], $now_utc_str, (float)$totals['starting_float'],
            (float)$totals['cash_sales'], (float)$totals['cash_in'], (float)$totals['cash_out'],
            (float)$totals['cash_refunds'], $expected_cash, (float)$counted_cash, (float)$cash_diff
        ]);
        $eod_id = (int)$pdo->lastInsertId();
    } else {
        $eod_id = null;
    }

    // [B1.2] 写入估清快照
    $stmt_sold_out = $pdo->prepare("SELECT menu_item_id FROM pos_product_availability WHERE store_id = ? AND is_sold_out = 1");
    $stmt_sold_out->execute([$store_id]);
    $sold_out_ids = $stmt_sold_out->fetchAll(PDO::FETCH_COLUMN, 0);
    $snapshot_json = empty($sold_out_ids) ? NULL : json_encode($sold_out_ids);
    
    $sql_update_tracking = "
        INSERT INTO pos_daily_tracking (store_id, sold_out_state_snapshot, snapshot_taken_at)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE
            sold_out_state_snapshot = VALUES(sold_out_state_snapshot),
            snapshot_taken_at = VALUES(snapshot_taken_at)
    ";
    $pdo->prepare($sql_update_tracking)->execute([$store_id, $snapshot_json, $now_utc_str]);
    // [B1.2] 估清快照结束

    if ($tx_started && $pdo->inTransaction()) $pdo->commit();
    unset($_SESSION['pos_shift_id']);

    json_ok('Shift ended.',[
        'eod_id' => $eod_id,
        'eod' => [ 'shift_id' => $shift_id, 'started_at' => $shift['start_time'], 'ended_at' => $now_utc_str,
                   'starting_float' => $totals['starting_float'], 'cash_sales' => $totals['cash_sales'],
                   'cash_in' => $totals['cash_in'], 'cash_out' => $totals['cash_out'],
                   'cash_refunds' => $totals['cash_refunds'], 'expected_cash' => $totals['expected_cash'],
                   'counted_cash' => (float)$counted_cash, 'cash_diff' => $cash_diff ]
    ]);
}
function handle_shift_force_start(PDO $pdo, array $config, array $input_data): void {
    $store_id = (int)$_SESSION['pos_store_id'];
    $user_id = (int)$_SESSION['pos_user_id'];
    $starting_float = (float)($input_data['starting_float'] ?? -1);
    if ($starting_float < 0) json_error('Invalid starting_float for new shift.', 422);

    // [A2 UTC SYNC] 依赖 datetime_helper.php
    $now_utc_str = utc_now()->format('Y-m-d H:i:s');
    
    $tx_started = false;
    if (!$pdo->inTransaction()) { $pdo->beginTransaction(); $tx_started = true; }

    $stmt_ghosts = $pdo->prepare("SELECT id, start_time, starting_float, user_id FROM pos_shifts WHERE store_id=? AND status='ACTIVE' FOR UPDATE");
    $stmt_ghosts->execute([$store_id]);
    $ghosts = $stmt_ghosts->fetchAll(PDO::FETCH_ASSOC);

    if (empty($ghosts)) {
         if ($tx_started && $pdo->inTransaction()) $pdo->rollBack();
         json_error('No ghost shifts found. Please try starting a normal shift.', 404, ['redirect_action' => 'start']);
    }

    $closer_name = $_SESSION['pos_display_name'] ?? ('User #' . $user_id);

    foreach ($ghosts as $ghost) {
        $ghost_id = (int)$ghost['id'];
        // 依赖: compute_expected_cash (来自 pos_repo.php)
        // [A2 UTC SYNC] $ghost['start_time'] 已经是 UTC
        $totals = compute_expected_cash($pdo, $store_id, $ghost['start_time'], $now_utc_str, (float)$ghost['starting_float']);
        
        $upd = $pdo->prepare(
            "UPDATE pos_shifts SET 
                end_time = ?, status = 'FORCE_CLOSED', counted_cash = NULL, expected_cash = ?, 
                cash_variance = NULL, payment_summary = ?, admin_reviewed = 0 
             WHERE id = ?"
        );
        $upd->execute([
            $now_utc_str,
            (float)$totals['expected_cash'],
            json_encode(['note' => 'Forcibly closed by ' . $closer_name]),
            $ghost_id
        ]);
        
        if (table_exists($pdo, 'pos_eod_records')) {
            $ins = $pdo->prepare("INSERT INTO pos_eod_records
              (shift_id, store_id, user_id, started_at, ended_at, starting_float,
               cash_sales, cash_in, cash_out, cash_refunds, expected_cash, counted_cash, cash_diff)
              VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $ins->execute([
                $ghost_id, $store_id, $ghost['user_id'], $ghost['start_time'], $now_utc_str, (float)$totals['starting_float'],
                (float)$totals['cash_sales'], (float)$totals['cash_in'], (float)$totals['cash_out'],
                (float)$totals['cash_refunds'], (float)$totals['expected_cash'], 0.00, 0.00
            ]);
        }
    }
    
    $uuid = bin2hex(random_bytes(16));
    $ins_new = $pdo->prepare("INSERT INTO pos_shifts (shift_uuid, store_id, user_id, start_time, status, starting_float) VALUES (?, ?, ?, ?, 'ACTIVE', ?)");
    $ins_new->execute([$uuid, $store_id, $user_id, $now_utc_str, $starting_float]);
    $new_shift_id = (int)$pdo->lastInsertId();
    
    // [B1.2] 查询上一班的估清快照
    $stmt_snapshot = $pdo->prepare("SELECT sold_out_state_snapshot FROM pos_daily_tracking WHERE store_id = ?");
    $stmt_snapshot->execute([$store_id]);
    $snapshot_json = $stmt_snapshot->fetchColumn();
    $snapshot = $snapshot_json ? json_decode($snapshot_json, true) : [];
    
    $prompt_decision = false;
    $snapshot_count = 0;
    
    if (!empty($snapshot)) {
        $prompt_decision = true;
        $snapshot_count = count($snapshot);
        // [B1.2] 将快照应用到当前的估清表
        $sql_apply = "INSERT INTO pos_product_availability (store_id, menu_item_id, is_sold_out, updated_at) VALUES (:store_id, :menu_item_id, 1, :now) ON DUPLICATE KEY UPDATE is_sold_out = 1, updated_at = :now";
        $stmt_apply = $pdo->prepare($sql_apply);
        foreach ($snapshot as $menu_item_id) {
            $stmt_apply->execute([
                ':store_id' => $store_id,
                ':menu_item_id' => (int)$menu_item_id,
                ':now' => $now_utc_str
            ]);
        }
    }
    // [B1.2] 估清快照结束


    if ($tx_started && $pdo->inTransaction()) $pdo->commit();
    
    $_SESSION['pos_shift_id'] = $new_shift_id;
    
    json_ok('Ghost shifts closed and new shift started.', [
        'shift' => [ 'id' => $new_shift_id, 'start_time' => $now_utc_str, 'starting_float' => $starting_float ],
        // [B1.2] 返回估清决策
        'prompt_sold_out_decision' => $prompt_decision,
        'snapshot_item_count' => $snapshot_count
    ]);
}

/* -------------------------------------------------------------------------- */
/* Handlers: 迁移自 /pos/api/pos_data_loader.php                   */
/* -------------------------------------------------------------------------- */
function handle_data_load(PDO $pdo, array $config, array $input_data): void {
    $store_id = (int)($_SESSION['pos_store_id'] ?? 0);

    // [PHASE 4.1.A] 获取门店配置 (用于打印机)
    $store_config = get_store_config_full($pdo, $store_id);

    $categories_sql = "SELECT category_code AS `key`, name_zh AS label_zh, name_es AS label_es FROM pos_categories WHERE deleted_at IS NULL ORDER BY sort_order ASC";
    $categories = $pdo->query($categories_sql)->fetchAll(PDO::FETCH_ASSOC);

    $gating_data = [ 'ice' => [], 'sweetness' => [] ];
    $ice_rules = $pdo->query("SELECT product_id, ice_option_id FROM kds_product_ice_options WHERE ice_option_id > 0")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($ice_rules as $rule) { $gating_data['ice'][(int)$rule['product_id']][] = (int)$rule['ice_option_id']; }
    $sweet_rules = $pdo->query("SELECT product_id, sweetness_option_id FROM kds_product_sweetness_options WHERE sweetness_option_id > 0")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($sweet_rules as $rule) { $gating_data['sweetness'][(int)$rule['product_id']][] = (int)$rule['sweetness_option_id']; }
    $managed_ice_products = $pdo->query("SELECT DISTINCT product_id FROM kds_product_ice_options")->fetchAll(PDO::FETCH_COLUMN, 0);
    $managed_sweet_products = $pdo->query("SELECT DISTINCT product_id FROM kds_product_sweetness_options")->fetchAll(PDO::FETCH_COLUMN, 0);
    $managed_ice_set = array_flip($managed_ice_products);
    $managed_sweet_set = array_flip($managed_sweet_products);

    // [PHASE 4.1.A] 修改 SQL, 增加 kc.cup_code
    $menu_sql = "
        SELECT 
            mi.id, mi.name_zh, mi.name_es, mi.image_url, pc.category_code,
            pv.id as variant_id, pv.variant_name_zh, pv.variant_name_es, pv.price_eur, pv.is_default,
            kp.product_code AS product_sku, kp.id AS kds_product_id,
            kc.cup_code AS cup_code,
            COALESCE(pa.is_sold_out, 0) AS is_sold_out
        FROM pos_menu_items mi
        JOIN pos_item_variants pv ON mi.id = pv.menu_item_id
        JOIN pos_categories pc ON mi.pos_category_id = pc.id
        LEFT JOIN kds_products kp ON mi.product_code = kp.product_code AND kp.deleted_at IS NULL
        LEFT JOIN kds_cups kc ON pv.cup_id = kc.id AND kc.deleted_at IS NULL
        LEFT JOIN pos_product_availability pa ON mi.id = pa.menu_item_id AND pa.store_id = :store_id
        WHERE mi.deleted_at IS NULL 
          AND mi.is_active = 1
          AND pv.deleted_at IS NULL
          AND pc.deleted_at IS NULL
        ORDER BY pc.sort_order, mi.sort_order, mi.id, pv.sort_order
    ";
    
    $stmt_menu = $pdo->prepare($menu_sql);
    $stmt_menu->execute([':store_id' => $store_id]);
    $results = $stmt_menu->fetchAll(PDO::FETCH_ASSOC);

    $products = [];
    foreach ($results as $row) {
        $itemId = (int)$row['id'];
        if (!isset($products[$itemId])) {
            $kds_pid = $row['kds_product_id'] ? (int)$row['kds_product_id'] : null;
            $allowed_ice_ids = null;
            $allowed_sweetness_ids = null;
            if ($kds_pid) {
                if (isset($managed_ice_set[$kds_pid])) { $allowed_ice_ids = $gating_data['ice'][$kds_pid] ?? []; }
                if (isset($managed_sweet_set[$kds_pid])) { $allowed_sweetness_ids = $gating_data['sweetness'][$kds_pid] ?? []; }
            }
            $products[$itemId] = [
                'id' => $itemId, 'title_zh' => $row['name_zh'], 'title_es' => $row['name_es'],
                'image_url' => $row['image_url'], 'category_key' => $row['category_code'],
                'allowed_ice_ids' => $allowed_ice_ids, 'allowed_sweetness_ids' => $allowed_sweetness_ids,
                'is_sold_out' => (int)$row['is_sold_out'],
                'variants' => []
            ];
        }
        $products[$itemId]['variants'][] = [
            'id' => (int)$row['variant_id'], 
            'recipe_sku' => $row['product_sku'], // This is product_code
            'cup_code' => $row['cup_code'],   // [PHASE 4.1.A] 新增
            'name_zh' => $row['variant_name_zh'], 
            'name_es' => $row['variant_name_es'],
            'price_eur' => (float)$row['price_eur'], 
            'is_default' => (bool)$row['is_default']
        ];
    }
    
    try {
        $addons_sql = "
            SELECT addon_code AS `key`, name_zh AS label_zh, name_es AS label_es, price_eur 
            FROM pos_addons 
            WHERE is_active = 1 AND deleted_at IS NULL 
            ORDER BY sort_order ASC
        ";
        $addons = $pdo->query($addons_sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { $addons = []; }
    
    $ice_options_sql = "
        SELECT i.id, i.ice_code, it_zh.ice_option_name AS name_zh, it_es.ice_option_name AS name_es
        FROM kds_ice_options i
        LEFT JOIN kds_ice_option_translations it_zh ON i.id = it_zh.ice_option_id AND it_zh.language_code = 'zh-CN'
        LEFT JOIN kds_ice_option_translations it_es ON i.id = it_es.ice_option_id AND it_es.language_code = 'es-ES'
        WHERE i.deleted_at IS NULL ORDER BY i.ice_code ASC
    ";
    $ice_options = $pdo->query($ice_options_sql)->fetchAll(PDO::FETCH_ASSOC);

    $sweetness_options_sql = "
        SELECT s.id, s.sweetness_code, st_zh.sweetness_option_name AS name_zh, st_es.sweetness_option_name AS name_es
        FROM kds_sweetness_options s
        LEFT JOIN kds_sweetness_option_translations st_zh ON s.id = st_zh.sweetness_option_id AND st_zh.language_code = 'zh-CN'
        LEFT JOIN kds_sweetness_option_translations st_es ON s.id = st_es.sweetness_option_id AND st_es.language_code = 'es-ES'
        WHERE s.deleted_at IS NULL ORDER BY s.sweetness_code ASC
    ";
    $sweetness_options = $pdo->query($sweetness_options_sql)->fetchAll(PDO::FETCH_ASSOC);

    $redemption_rules = [];
    try {
        $rules_sql = "
            SELECT id, rule_name_zh, rule_name_es, points_required, reward_type, reward_value_decimal, reward_promo_id
            FROM pos_point_redemption_rules
            WHERE is_active = 1 AND deleted_at IS NULL
            ORDER BY points_required ASC
        ";
        $redemption_rules = $pdo->query($rules_sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { $redemption_rules = []; }

    $sif_declaration = '';
    try {
        $stmt_sif = $pdo->prepare("SELECT setting_value FROM pos_settings WHERE setting_key = 'sif_declaracion_responsable'");
        $stmt_sif->execute();
        $sif_declaration = $stmt_sif->fetchColumn();
        if ($sif_declaration === false) $sif_declaration = ''; 
    } catch (PDOException $e) { $sif_declaration = 'Error: No se pudo cargar la declaración.'; }

    $data_payload = [
        'store_config' => $store_config, // [PHASE 4.1.A] 新增
        'products' => array_values($products),
        'addons' => $addons,
        'categories' => $categories,
        'redemption_rules' => $redemption_rules,
        'ice_options' => $ice_options,
        'sweetness_options' => $sweetness_options,
        'sif_declaration' => $sif_declaration
    ];

    json_ok($data_payload);
}

/* -------------------------------------------------------------------------- */
/* Handlers: 迁移自 /pos/api/pos_member_handler.php                */
/* -------------------------------------------------------------------------- */
function handle_member_find(PDO $pdo, array $config, array $input_data): void {
    $phone = trim($input_data['phone'] ?? $_GET['phone'] ?? '');
    if (empty($phone)) json_error('Phone number is required.', 400);

    $stmt = $pdo->prepare("
        SELECT m.*, ml.level_name_zh, ml.level_name_es
        FROM pos_members m
        LEFT JOIN pos_member_levels ml ON m.member_level_id = ml.id
        WHERE m.phone_number = ? AND m.deleted_at IS NULL
    ");
    $stmt->execute([$phone]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($member) {
        json_ok($member, 'Member found.');
    } else {
        json_error('Member not found.', 404);
    }
}
function handle_member_create(PDO $pdo, array $config, array $input_data): void {
    $data = $input_data['data'] ?? $input_data;
    $phone = trim($data['phone_number'] ?? '');

    if (empty($phone)) json_error('手机号为必填项。 (Phone number is required.)', 400);

    $stmt_check = $pdo->prepare("SELECT id FROM pos_members WHERE phone_number = ? AND deleted_at IS NULL");
    $stmt_check->execute([$phone]);
    if ($stmt_check->fetch()) json_error('此手机号已被注册。 (This phone number is already registered.)', 409);

    $first_name = !empty($data['first_name']) ? trim($data['first_name']) : null;
    $last_name = !empty($data['last_name']) ? trim($data['last_name']) : null;
    $email = !empty($data['email']) ? trim($data['email']) : null;
    $birthdate = !empty($data['birthdate']) ? trim($data['birthdate']) : null;
    
    if ($birthdate && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthdate)) {
         json_error('生日格式无效，请使用 YYYY-MM-DD 格式。', 400);
    }

    $stmt_insert = $pdo->prepare("
        INSERT INTO pos_members (member_uuid, phone_number, first_name, last_name, email, birthdate)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    
    $uuid = bin2hex(random_bytes(16));
    $stmt_insert->execute([$uuid, $phone, $first_name, $last_name, $email, $birthdate]);
    
    $new_member_id = $pdo->lastInsertId();
    
    $stmt_get = $pdo->prepare("SELECT * FROM pos_members WHERE id = ?");
    $stmt_get->execute([$new_member_id]);
    $new_member_data = $stmt_get->fetch(PDO::FETCH_ASSOC);

    json_ok($new_member_data, '新会员已成功创建！ (Member created successfully!)', 201);
}

/* -------------------------------------------------------------------------- */
/* Handlers: 迁移自 /pos/api/pos_hold_handler.php                  */
/* -------------------------------------------------------------------------- */
function handle_hold_list(PDO $pdo, array $config, array $input_data): void {
    $store_id = (int)$_SESSION['pos_store_id'];
    $sort_by = $_GET['sort'] ?? 'time_desc';
    $order_clause = 'created_at DESC';
    if ($sort_by === 'amount_desc') {
        $order_clause = 'total_amount DESC';
    }
    $stmt = $pdo->prepare("SELECT id, note, created_at, total_amount FROM pos_held_orders WHERE store_id = ? ORDER BY $order_clause");
    $stmt->execute([$store_id]);
    $held_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    json_ok($held_orders, 'Held orders retrieved.');
}
function handle_hold_save(PDO $pdo, array $config, array $input_data): void {
    $store_id = (int)$_SESSION['pos_store_id'];
    $user_id = (int)$_SESSION['pos_user_id'];
    $note = trim($input_data['note'] ?? '');
    $cart_data = $input_data['cart'] ?? [];

    if (empty($note)) json_error('备注/桌号不能为空 (Note cannot be empty).', 400);
    if (empty($cart_data)) json_error('不能挂起一个空的购物车 (Cannot hold an empty cart).', 400);
    
    $total_amount = 0;
    foreach ($cart_data as $item) {
        $total_amount += ($item['unit_price_eur'] ?? 0) * ($item['qty'] ?? 1);
    }

    $stmt = $pdo->prepare("INSERT INTO pos_held_orders (store_id, user_id, note, cart_data, total_amount) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$store_id, $user_id, $note, json_encode($cart_data), $total_amount]);
    $new_id = $pdo->lastInsertId();
    json_ok(['id' => $new_id], 'Order held successfully.');
}
function handle_hold_restore(PDO $pdo, array $config, array $input_data): void {
    $store_id = (int)$_SESSION['pos_store_id'];
    $id = (int)($_GET['id'] ?? $input_data['id'] ?? 0);
    if (!$id) json_error('Invalid hold ID.', 400);

    $pdo->beginTransaction();
    $stmt_get = $pdo->prepare("SELECT cart_data FROM pos_held_orders WHERE id = ? AND store_id = ? FOR UPDATE");
    $stmt_get->execute([$id, $store_id]);
    $cart_json = $stmt_get->fetchColumn();
    
    if ($cart_json === false || empty($cart_json)) {
        $pdo->rollBack();
        json_error('Held order not found or is empty.', 404);
    }
    
    $cart_data = json_decode($cart_json, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $pdo->rollBack();
        json_error('Failed to parse held cart data.', 500);
    }

    $stmt_delete = $pdo->prepare("DELETE FROM pos_held_orders WHERE id = ?");
    $stmt_delete->execute([$id]);
    $pdo->commit();
    
    json_ok($cart_data, 'Order restored.');
}

/* -------------------------------------------------------------------------- */
/* Handlers: 迁移自 /pos/api/pos_transaction_handler.php           */
/* -------------------------------------------------------------------------- */
function handle_txn_list(PDO $pdo, array $config, array $input_data): void {
    $store_id = (int)$_SESSION['pos_store_id'];
    $start_date = $_GET['start_date'] ?? null;
    $end_date = $_GET['end_date'] ?? null;
    
    // [A2 UTC SYNC] 依赖 datetime_helper.php
    $tz = APP_DEFAULT_TIMEZONE;
    
    $sql = "SELECT id, series, number, issued_at, final_total, status FROM pos_invoices WHERE store_id = :store_id";
    $params = [':store_id' => $store_id];

    if ($start_date && $end_date) {
        // [A2 UTC SYNC] 使用 to_utc_window 转换查询范围
        [$utc_start, $utc_end] = to_utc_window($start_date, $end_date, $tz);

        $sql .= " AND issued_at BETWEEN :start_date AND :end_date";
        $params[':start_date'] = $utc_start->format('Y-m-d H:i:s');
        $params[':end_date'] = $utc_end->format('Y-m-d H:i:s');
    }
    $sql .= " ORDER BY issued_at DESC LIMIT 200";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // [A2 UTC SYNC] 将 issued_at 转换为本地时间字符串
    foreach ($transactions as &$txn) {
        $txn['issued_at'] = fmt_local($txn['issued_at'], 'Y-m-d H:i:s', $tz);
    }

    json_ok($transactions, 'Transactions retrieved.');
}
function handle_txn_get_details(PDO $pdo, array $config, array $input_data): void {
    $store_id = (int)$_SESSION['pos_store_id'];
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) json_error('Invalid Invoice ID.', 400);

    $stmt_invoice = $pdo->prepare("
        SELECT pi.*, ku.display_name AS cashier_name
        FROM pos_invoices pi
        LEFT JOIN kds_users ku ON pi.user_id = ku.id
        WHERE pi.id = ? AND pi.store_id = ?
    ");
    $stmt_invoice->execute([$id, $store_id]);
    $invoice = $stmt_invoice->fetch(PDO::FETCH_ASSOC);

    if (!$invoice) json_error('Invoice not found.', 404);

    // [A2 UTC SYNC] 转换时间
    $invoice['issued_at'] = fmt_local($invoice['issued_at'], 'Y-m-d H:i:s.u', APP_DEFAULT_TIMEZONE);

    $stmt_items = $pdo->prepare("SELECT * FROM pos_invoice_items WHERE invoice_id = ?");
    $stmt_items->execute([$id]);
    $invoice['items'] = $stmt_items->fetchAll(PDO::FETCH_ASSOC);

    $invoice['payment_summary_decoded'] = json_decode($invoice['payment_summary'] ?? '[]', true);
    $invoice['compliance_data_decoded'] = json_decode($invoice['compliance_data'] ?? '[]', true);

    json_ok($invoice, 'Invoice details retrieved.');
}

/* -------------------------------------------------------------------------- */
/* Handlers: 迁移自 /pos/api/pos_print_handler.php                */
/* -------------------------------------------------------------------------- */
function handle_print_get_templates(PDO $pdo, array $config, array $input_data): void {
    // [MODIFIED 2c] 只使用 POS store_id
    $store_id = (int)($_SESSION['pos_store_id'] ?? 0);
    if ($store_id === 0) json_error('无法确定门店ID。', 401);

    $stmt = $pdo->prepare(
        "SELECT template_type, template_content, physical_size
         FROM pos_print_templates 
         WHERE (store_id = :store_id OR store_id IS NULL) AND is_active = 1
         ORDER BY store_id DESC"
    );
    $stmt->execute([':store_id' => $store_id]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $templates = [];
    foreach ($results as $row) {
        if (!isset($templates[$row['template_type']])) {
            $templates[$row['template_type']] = [
                'content' => json_decode($row['template_content'], true),
                'size' => $row['physical_size']
            ];
        }
    }
    json_ok($templates, 'Templates loaded.');
}
function handle_print_get_eod_data(PDO $pdo, array $config, array $input_data): void {
    // [MODIFIED 2c] 只使用 POS store_id
    $store_id = (int)($_SESSION['pos_store_id'] ?? 0);
    if ($store_id === 0) json_error('无法确定门店ID。', 401);
    
    $report_id = (int)($_GET['report_id'] ?? 0);
    if (!$report_id) json_error('无效的报告ID。', 400);

    $stmt = $pdo->prepare(
        "SELECT r.*, s.store_name, u.display_name as user_name
         FROM pos_eod_reports r
         LEFT JOIN kds_stores s ON r.store_id = s.id
         LEFT JOIN cpsys_users u ON r.user_id = u.id
         WHERE r.id = ? AND r.store_id = ?"
    );
    $stmt->execute([$report_id, $store_id]);
    $report_data = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$report_data) json_error('未找到指定的日结报告。', 404);

    // [A2 UTC SYNC] 依赖 datetime_helper.php
    $tz = APP_DEFAULT_TIMEZONE;
    // report_date 已经是 Y-m-d 格式，不需要转
    // executed_at 是 UTC，需要转
    $report_data['executed_at'] = fmt_local($report_data['executed_at'], 'Y-m-d H:i:s', $tz);
    // [A2 UTC SYNC] print_time 在本地生成，使用本地时区
    $report_data['print_time'] = (new DateTime('now', new DateTimeZone($tz)))->format('Y-m-d H:i:s');
    
    foreach(['system_gross_sales', 'system_discounts', 'system_net_sales', 'system_tax', 'system_cash', 'system_card', 'system_platform', 'counted_cash', 'cash_discrepancy'] as $key) {
        if (isset($report_data[$key])) {
            $report_data[$key] = number_format((float)$report_data[$key], 2, '.', '');
        }
    }
    json_ok($report_data, 'EOD report data for printing retrieved.');
}

/* -------------------------------------------------------------------------- */
/* Handlers: 迁移自 /pos/api/pos_availability_handler.php          */
/* -------------------------------------------------------------------------- */
function handle_avail_get_all(PDO $pdo, array $config, array $input_data): void {
    $store_id = (int)$_SESSION['pos_store_id'];
    $sql = "
        SELECT 
            mi.id AS menu_item_id, mi.name_zh, mi.name_es, mi.product_code,
            COALESCE(pa.is_sold_out, 0) AS is_sold_out
        FROM pos_menu_items mi
        LEFT JOIN pos_product_availability pa ON mi.id = pa.menu_item_id AND pa.store_id = :store_id
        WHERE mi.deleted_at IS NULL AND mi.is_active = 1
        ORDER BY mi.pos_category_id, mi.sort_order
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':store_id' => $store_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    json_ok($items, 'Status loaded.');
}
function handle_avail_toggle(PDO $pdo, array $config, array $input_data): void {
    $store_id = (int)$_SESSION['pos_store_id'];
    $menu_item_id = (int)($input_data['menu_item_id'] ?? 0);
    $is_sold_out = isset($input_data['is_sold_out']) ? (int)$input_data['is_sold_out'] : 0;
    if ($menu_item_id <= 0) json_error('Invalid menu_item_id.', 400);

    // [A2 UTC SYNC] 使用 UTC 时间
    $now_utc_str = utc_now()->format('Y-m-d H:i:s');

    $sql = "
        INSERT INTO pos_product_availability (store_id, menu_item_id, is_sold_out, updated_at)
        VALUES (:store_id, :menu_item_id, :is_sold_out, :now)
        ON DUPLICATE KEY UPDATE
            is_sold_out = VALUES(is_sold_out),
            updated_at = VALUES(updated_at)
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':store_id' => $store_id,
        ':menu_item_id' => $menu_item_id,
        ':is_sold_out' => $is_sold_out,
        ':now' => $now_utc_str // [A2 UTC SYNC]
    ]);
    json_ok(null, 'Status updated.');
}
function handle_avail_reset_all(PDO $pdo, array $config, array $input_data): void {
    $store_id = (int)$_SESSION['pos_store_id'];
    $stmt = $pdo->prepare("DELETE FROM pos_product_availability WHERE store_id = :store_id");
    $stmt->execute([':store_id' => $store_id]);
    json_ok(null, 'All items restocked.');
}

/* -------------------------------------------------------------------------- */
/* Handlers: 迁移自 /pos/api/eod_summary_handler.php               */
/* -------------------------------------------------------------------------- */
function handle_eod_get_preview(PDO $pdo, array $config, array $input_data): void {
    // [A2 UTC SYNC] 依赖 datetime_helper.php
    $tzMadrid = new DateTimeZone(APP_DEFAULT_TIMEZONE);
    $store_id = (int)($_SESSION['pos_store_id'] ?? 1);

    $target_business_date = null;
    $date_input = $_GET['target_business_date'] ?? $input_data['target_business_date'] ?? null;
    
    // 1. 确定目标营业日 (马德里本地日期)
    if ($date_input) {
        $d = DateTime::createFromFormat('Y-m-d', $date_input, $tzMadrid);
        if ($d !== false) $target_business_date = $d->format('Y-m-d');
    }
    if ($target_business_date === null) {
        // [A2 UTC SYNC] POS 端始终查询“今天”
        $target_business_date = (new DateTime('today', $tzMadrid))->format('Y-m-d');
    }
    
    // 2. [A2 UTC SYNC] 将本地营业日转换为 UTC 时间窗口
    [$bd_start_utc_dt, $bd_end_utc_dt] = to_utc_window($target_business_date, null, APP_DEFAULT_TIMEZONE);
    $bd_start_utc_str = $bd_start_utc_dt->format('Y-m-d H:i:s');
    $bd_end_utc_str   = $bd_end_utc_dt->format('Y-m-d H:i:s');
    // [A2 UTC SYNC] END

    $eod_table = 'pos_eod_reports';
    $sql_check = "SELECT * FROM `{$eod_table}` WHERE store_id=:sid AND report_date = :bd LIMIT 1";
    $stmt_check = $pdo->prepare($sql_check);
    $stmt_check->execute([':sid' => $store_id, ':bd' => $target_business_date]);
    $existing_report = $stmt_check->fetch(PDO::FETCH_ASSOC);

    if ($existing_report) {
        // [A2 UTC SYNC] 转换已存储的 UTC 时间
        $existing_report['executed_at'] = fmt_local($existing_report['executed_at']);
        json_ok(['is_submitted' => true, 'existing_report' => $existing_report]);
    }

    // 依赖: getInvoiceSummaryForPeriod (来自 pos_repo.php)
    // [A2 UTC SYNC] 传入 UTC 窗口
    $full_summary = getInvoiceSummaryForPeriod($pdo, $store_id, $bd_start_utc_str, $bd_end_utc_str);
    
    $preview_data = [
        'transactions_count'   => $full_summary['summary']['transactions_count'],
        'system_gross_sales'   => $full_summary['summary']['system_gross_sales'],
        'system_discounts'     => $full_summary['summary']['system_discounts'],
        'system_net_sales'     => $full_summary['summary']['system_net_sales'],
        'system_tax'           => $full_summary['summary']['system_tax'],
        'payments'             => $full_summary['payments'],
        'report_date'          => $target_business_date,
        'is_submitted'         => false
    ];
    json_ok($preview_data);
}
function handle_eod_submit_report(PDO $pdo, array $config, array $input_data): void {
    $json_data = $input_data;
    // [A2 UTC SYNC] 依赖 datetime_helper.php
    $tzMadrid = new DateTimeZone(APP_DEFAULT_TIMEZONE);
    $store_id = (int)($_SESSION['pos_store_id'] ?? 1);

    // 1. 确定目标营业日 (马德里本地日期)
    $target_business_date = null;
    $date_input = $_GET['target_business_date'] ?? $json_data['target_business_date'] ?? null;
    if ($date_input) {
        $d = DateTime::createFromFormat('Y-m-d', $date_input, $tzMadrid);
        if ($d !== false) $target_business_date = $d->format('Y-m-d');
    }
    if ($target_business_date === null) {
        $target_business_date = (new DateTime('today', $tzMadrid))->format('Y-m-d');
    }
    
    // 2. [A2 UTC SYNC] 将本地营业日转换为 UTC 时间窗口
    [$bd_start_utc_dt, $bd_end_utc_dt] = to_utc_window($target_business_date, null, APP_DEFAULT_TIMEZONE);
    $bd_start_utc_str = $bd_start_utc_dt->format('Y-m-d H:i:s');
    $bd_end_utc_str   = $bd_end_utc_dt->format('Y-m-d H:i:s');
    // [A2 UTC SYNC] END

    $eod_table = 'pos_eod_reports';

    $sql_check = "SELECT * FROM `{$eod_table}` WHERE store_id=:sid AND report_date = :bd LIMIT 1";
    $stmt_check = $pdo->prepare($sql_check);
    $stmt_check->execute([':sid' => $store_id, ':bd' => $target_business_date]);
    if ($stmt_check->fetch(PDO::FETCH_ASSOC)) {
        json_error('该业务日已完成日结，不可重复提交。', 409);
    }

    $counted_cash = isset($json_data['counted_cash']) ? (float)$json_data['counted_cash'] : 0.0;
    $notes = isset($json_data['notes']) ? trim($json_data['notes']) : '';

    // 依赖: getInvoiceSummaryForPeriod (来自 pos_repo.php)
    // [A2 UTC SYNC] 传入 UTC 窗口
    $full_summary = getInvoiceSummaryForPeriod($pdo, $store_id, $bd_start_utc_str, $bd_end_utc_str);
    $summary = $full_summary['summary'];
    $payments_breakdown = $full_summary['payments'];
    
    $cash_discrepancy = $counted_cash - $payments_breakdown['Cash'];

    // [A2 UTC SYNC] 写入 UTC 时间
    $now_utc_str = utc_now()->format('Y-m-d H:i:s');

    $pdo->beginTransaction();
    $sql_insert = "INSERT INTO `{$eod_table}` (
                       report_date, store_id, user_id, executed_at,
                       transactions_count, system_gross_sales, system_discounts, system_net_sales, system_tax,
                       system_cash, system_card, system_platform,
                       counted_cash, cash_discrepancy, notes
                   ) VALUES (
                       :report_date, :store_id, :user_id, :now_utc,
                       :transactions_count, :system_gross_sales, :system_discounts, :system_net_sales, :system_tax,
                       :system_cash, :system_card, :system_platform,
                       :counted_cash, :cash_discrepancy, :notes
                   )";
    $stmt_insert = $pdo->prepare($sql_insert);
    $stmt_insert->execute([
        ':report_date' => $target_business_date,
        ':store_id' => $store_id,
        ':user_id' => (int)($_SESSION['pos_user_id'] ?? $json_data['user_id'] ?? 1),
        ':now_utc' => $now_utc_str, // [A2 UTC SYNC]
        ':transactions_count' => $summary['transactions_count'],
        ':system_gross_sales' => $summary['system_gross_sales'],
        ':system_discounts' => $summary['system_discounts'],
        ':system_net_sales' => $summary['system_net_sales'],
        ':system_tax' => $summary['system_tax'],
        ':system_cash' => $payments_breakdown['Cash'],
        ':system_card' => $payments_breakdown['Card'],
        ':system_platform' => $payments_breakdown['Platform'],
        ':counted_cash' => $counted_cash,
        ':cash_discrepancy' => $cash_discrepancy,
        ':notes' => $notes
    ]);

    $pdo->commit();
    json_ok(null, '日结报告已成功提交。');
}

/* -------------------------------------------------------------------------- */
/* Handlers: 迁移自 /pos/api/eod_list.php                          */
/* -------------------------------------------------------------------------- */
function handle_eod_list(PDO $pdo, array $config, array $input_data): void {
    $store_id = (int)$_SESSION['pos_store_id'];
    $limit = isset($_GET['limit']) ? max(1,min(200,(int)$_GET['limit'])) : 50;

    $sql = "SELECT id, shift_id, store_id, user_id,
                 started_at, ended_at,
                 starting_float, cash_sales, cash_in, cash_out, cash_refunds,
                 expected_cash, counted_cash, cash_diff, created_at
          FROM pos_eod_records
          WHERE store_id = ?
          ORDER BY id DESC
          LIMIT ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$store_id, $limit]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // [A2 UTC SYNC] 转换时间
    $tz = APP_DEFAULT_TIMEZONE;
    foreach ($rows as &$row) {
        $row['started_at'] = fmt_local($row['started_at'], 'Y-m-d H:i', $tz);
        $row['ended_at'] = fmt_local($row['ended_at'], 'Y-m-d H:i', $tz);
        $row['created_at'] = fmt_local($row['created_at'], 'Y-m-d H:i', $tz);
    }

    json_ok(['items'=>$rows, 'count'=>count($rows)], 'ok');
}

/* -------------------------------------------------------------------------- */
/* Handlers: 迁移自 /pos/api/eod_get.php                           */
/* -------------------------------------------------------------------------- */
function handle_eod_get(PDO $pdo, array $config, array $input_data): void {
    $store_id = (int)$_SESSION['pos_store_id'];
    $eod_id   = isset($_GET['eod_id']) ? (int)$_GET['eod_id'] : 0;
    if ($eod_id <= 0) json_error('Missing eod_id', 400);

    $stmt = $pdo->prepare("SELECT * FROM pos_eod_records WHERE id=? AND store_id=? LIMIT 1");
    $stmt->execute([$eod_id, $store_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) json_error('Record not found', 404);

    // [A2 UTC SYNC] 转换时间
    $tz = APP_DEFAULT_TIMEZONE;
    $row['started_at'] = fmt_local($row['started_at'], 'Y-m-d H:i', $tz);
    $row['ended_at'] = fmt_local($row['ended_at'], 'Y-m-d H:i', $tz);
    $row['created_at'] = fmt_local($row['created_at'], 'Y-m-d H:i', $tz);

    json_ok(['item'=>$row], 'OK');
}

/* -------------------------------------------------------------------------- */
/* Handlers: 迁移自 /pos/api/check_eod_status.php                  */
/* -------------------------------------------------------------------------- */
function handle_check_eod_status(PDO $pdo, array $config, array $input_data): void {
    // [A2 UTC SYNC] 依赖 datetime_helper.php
    $tzMadrid = new DateTimeZone(APP_DEFAULT_TIMEZONE);
    $store_id = (int)($_GET['store_id'] ?? $_SESSION['pos_store_id'] ?? 1);

    // 1. 确定“昨天”的营业日 (马德里本地日期)
    $yesterday_date_str = (new DateTime('yesterday', $tzMadrid))->format('Y-m-d');

    $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM pos_eod_reports WHERE store_id = :store_id AND report_date = :report_date");
    $stmt_check->execute([':store_id' => $store_id, ':report_date' => $yesterday_date_str]);
    $report_exists = (int)$stmt_check->fetchColumn() > 0;

    if ($report_exists) {
        json_ok(['previous_day_unclosed' => false, 'unclosed_date' => null]);
    }

    // 2. [A2 UTC SYNC] 将昨天的本地营业日转换为 UTC 窗口
    [$yesterday_start_utc_dt, $yesterday_end_utc_dt] = to_utc_window($yesterday_date_str, null, APP_DEFAULT_TIMEZONE);
    $yesterday_start_utc_str = $yesterday_start_utc_dt->format('Y-m-d H:i:s');
    $yesterday_end_utc_str   = $yesterday_end_utc_dt->format('Y-m-d H:i:s');
    // [A2 UTC SYNC] END

    $stmt_invoice = $pdo->prepare(
        "SELECT 1 FROM pos_invoices WHERE store_id = :store_id AND issued_at BETWEEN :start_utc AND :end_utc LIMIT 1"
    );
    $stmt_invoice->execute([
        ':store_id' => $store_id,
        ':start_utc' => $yesterday_start_utc_str,
        ':end_utc' => $yesterday_end_utc_str
    ]);
    $invoice_exists = $stmt_invoice->fetchColumn() !== false;
    
    if ($invoice_exists) {
        json_ok(['previous_day_unclosed' => true, 'unclosed_date' => $yesterday_date_str]);
    } else {
        json_ok(['previous_day_unclosed' => false, 'unclosed_date' => null]);
    }
}

/* -------------------------------------------------------------------------- */
/* [B1.2.2 REFACTOR] Handlers: 迁移自 /pos/api/handlers/pos_pass_handler.php    */
/* -------------------------------------------------------------------------- */
if (!function_exists('handle_pass_purchase')) {
    /**
     * [B1.2] 处理售卡请求 (POST /api/pos_api_gateway.php?res=pass&act=purchase)
     */
    function handle_pass_purchase(PDO $pdo, array $config, array $input_data): void {
        
        // 1. 验证班次 (依赖: shift_guard.php)
        ensure_active_shift_or_fail($pdo);

        // 2. 获取上下文
        $store_id = (int)($_SESSION['pos_store_id'] ?? 0);
        $user_id  = (int)($_SESSION['pos_user_id']  ?? 0);
        $device_id = (string)($input_data['device_id'] ?? null); // 假设前端未来会传递

        // 3. 解析载荷
        $cart        = $input_data['cart'] ?? [];
        $payment_raw = $input_data['payment'] ?? [];
        $member_id   = (int)($input_data['member_id'] ?? 0);
        $promo_result = $input_data['promo_result'] ?? null; // 用于校验
        
        if (empty($cart)) json_error('购物车不能为空。', 400);
        if ($member_id <= 0) json_error('必须绑定会员才能购买次卡。', 400);
        
        // [B1.2] B1 阶段的实现：售卡订单只允许包含一个次卡商品，且数量为1
        if (count($cart) > 1 || (int)($cart[0]['qty'] ?? 0) !== 1) {
            json_error('售卡订单目前只支持购买一张次卡。', 400);
        }
        $cart_item = $cart[0];
        $menu_item_id = (int)($cart_item['product_id'] ?? 0); // product_id 是 menu_item_id

        [cite_start]// 4. 服务端校验 (依赖: pos_repo.php, pos_pass_helper.php) [cite: 113-115]
        $tags = get_cart_item_tags($pdo, [$menu_item_id]);
        validate_pass_purchase_order($pdo, $cart, $tags, $promo_result);

        // 5. 从数据库获取次卡方案详情
        // (假设 B1 阶段: pass_plan_id 存储在 pos_menu_items.product_code 字段中)
        $stmt_plan_id = $pdo->prepare("SELECT product_code FROM pos_menu_items WHERE id = ?");
        $stmt_plan_id->execute([$menu_item_id]);
        $plan_id_str = $stmt_plan_id->fetchColumn();
        
        if (!$plan_id_str || !ctype_digit($plan_id_str)) {
             json_error('次卡商品未正确关联到次卡方案 (product_code 应为 plan_id)。', 500);
        }
        
        $plan_details = get_pass_plan_details($pdo, (int)$plan_id_str);
        if (!$plan_details) {
            json_error('找不到次卡方案 (ID: '.$plan_id_str.') 或该方案已下架。', 404);
        }

        $pdo->beginTransaction();
        try {
            
            // 6. 获取门店配置 (用于 VR 票号)
            $store_config = get_store_config_full($pdo, $store_id);
            if (empty($store_config['invoice_prefix'])) {
                 throw new Exception('售卡失败：门店缺少票号前缀 (invoice_prefix) 配置。', 412);
            }
            
            // 7. 分配 VR 非税凭证号 (依赖: pos_pass_helper.php)
            [$vr_series, $vr_number] = allocate_vr_invoice_number($pdo, $store_config['invoice_prefix']);
            $vr_info = ['series' => $vr_series, 'number' => $vr_number];

            // 8. 写入数据库 (topup_orders, member_passes) (依赖: pos_pass_helper.php)
            $context = [
                'store_id' => $store_id,
                'user_id' => $user_id,
                'device_id' => $device_id,
                'member_id' => $member_id
            ];
            $member_pass_id = create_pass_records($pdo, $context, $vr_info, $cart_item, $plan_details);
            
            // 9. (B1 阶段) 支付信息暂不处理，假设前端已收款
            // TODO (B3): 记录支付详情到 topup_orders
            
            // 10. 提交事务
            $pdo->commit();
            
            // 11. 准备打印数据 (B1 阶段可选, B2 必须)
            $print_jobs = [
                // [TODO B2] 在此构建 VR 售卡小票
            ];

            json_ok('次卡购买成功', [
                'topup_order_id' => $member_pass_id, // 临时返回 member_pass_id
                'member_pass_id' => $member_pass_id,
                'voucher_number' => $vr_series . '-' . $vr_number, // VR 非税凭证号
                'print_jobs' => $print_jobs
            ]);

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            json_error('售卡失败: ' . $e->getMessage(), 500, ['debug' => $e->getTraceAsString()]);
        }
    }
}

// [B1.3.1 REFACTOR] 移除 handle_pass_redeem 的占位实现。
// 它现在由 pos_registry_ext_pass.php 提供。


/* -------------------------------------------------------------------------- */
/* 注册表                                                   */
/* -------------------------------------------------------------------------- */
return [
    
    // [B1.2 PASS] 新增次卡资源
    'pass' => [
        'auth_role' => ROLE_STORE_USER,
        'custom_actions' => [
            'purchase' => 'handle_pass_purchase', // 售卡
            'redeem'   => 'handle_pass_redeem',   // [B1.3.1] 核销 (实现在 ext 文件)
        ],
    ],

    // POS: Order
    'order' => [
        'auth_role' => ROLE_STORE_USER,
        'custom_actions' => [
            'submit' => 'handle_order_submit',
        ],
    ],
    
    // POS: Cart
    'cart' => [
        'auth_role' => ROLE_STORE_USER,
        'custom_actions' => [
            'calculate' => 'handle_cart_calculate',
        ],
    ],

    // POS: Shift
    'shift' => [
        'auth_role' => ROLE_STORE_USER,
        'custom_actions' => [
            'status' => 'handle_shift_status',
            'start' => 'handle_shift_start',
            'end' => 'handle_shift_end',
            'force_start' => 'handle_shift_force_start',
        ],
    ],
    
    // POS: Data Loader
    'data' => [
        'auth_role' => ROLE_STORE_USER,
        'custom_actions' => [
            'load' => 'handle_data_load',
        ],
    ],
    
    // POS: Member
    'member' => [
        'auth_role' => ROLE_STORE_USER,
        'custom_actions' => [
            'find' => 'handle_member_find',
            'create' => 'handle_member_create',
        ],
    ],
    
    // POS: Hold
    'hold' => [
        'auth_role' => ROLE_STORE_USER,
        'custom_actions' => [
            'list' => 'handle_hold_list',
            'save' => 'handle_hold_save',
            'restore' => 'handle_hold_restore',
        ],
    ],
    
    // POS: Transaction
    'transaction' => [
        'auth_role' => ROLE_STORE_USER,
        'custom_actions' => [
            'list' => 'handle_txn_list',
            'get_details' => 'handle_txn_get_details',
        ],
    ],
    
    // POS: Print
    'print' => [
        'auth_role' => ROLE_STORE_USER,
        'custom_actions' => [
            'get_templates' => 'handle_print_get_templates',
            'get_eod_data' => 'handle_print_get_eod_data',
        ],
    ],
    
    // POS: Availability (估清)
    'availability' => [
        'auth_role' => ROLE_STORE_USER,
        'custom_actions' => [
            'get_all' => 'handle_avail_get_all',
            'toggle' => 'handle_avail_toggle',
            'reset_all' => 'handle_avail_reset_all',
        ],
    ],
    
    // POS: EOD (日结)
    'eod' => [
        'auth_role' => ROLE_STORE_USER,
        'custom_actions' => [
            'get_preview' => 'handle_eod_get_preview',
            'submit_report' => 'handle_eod_submit_report',
            'list' => 'handle_eod_list',
            'get' => 'handle_eod_get',
            'check_status' => 'handle_check_eod_status',
        ],
    ],
];