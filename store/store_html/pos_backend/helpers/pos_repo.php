<?php
/**
 * Toptea Store - POS 核心帮助库 (Repo)
 * 包含所有 POS API 处理器共用的业务逻辑函数。
 * (迁移自 /pos/api/*)
 * Version: 1.1.0 (Phase 3a: Invoice Numbering Refactor)
 * Date: 2025-11-08
 *
 * [B1.2 PASS]: Added pass-related helpers (VR invoice, validation, allocation, tags, plan details)
 */

/* -------------------------------------------------------------------------- */
/* 任务 2.2: 门店配置 & 购物车编码                         */
/* -------------------------------------------------------------------------- */

/**
 * * 获取完整的门店配置, 包括所有新的打印机角色字段
 */
if (!function_exists('get_store_config_full')) {
    function get_store_config_full(PDO $pdo, int $store_id): array {
        $stmt_store = $pdo->prepare("SELECT * FROM kds_stores WHERE id = :store_id LIMIT 1");
        $stmt_store->execute([':store_id' => $store_id]);
        $store_config = $stmt_store->fetch(PDO::FETCH_ASSOC);
        if (!$store_config) {
            throw new Exception("Store configuration for store_id #{$store_id} not found.");
        }
        return $store_config;
    }
}

/**
 * * 从购物车 item 中提取或查询 KDS/SOP 所需的机器码
 */
if (!function_exists('get_cart_item_codes')) {
    function get_cart_item_codes(PDO $pdo, array $item): array {
        $product_id = (int)($item['product_id'] ?? 0); // This is pos_menu_items.id
        $variant_id = (int)($item['variant_id'] ?? 0);
        
        $p_code = null;
        $cup_id = null;
        if ($variant_id > 0) {
            $stmt_pv = $pdo->prepare("
                SELECT mi.product_code, pv.cup_id
                FROM pos_item_variants pv
                JOIN pos_menu_items mi ON pv.menu_item_id = mi.id
                WHERE pv.id = ?
            ");
            $stmt_pv->execute([$variant_id]);
            $row = $stmt_pv->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $p_code = $row['product_code'];
                $cup_id = (int)$row['cup_id'];
            }
        }
        
        $cup_code = null;
        if ($cup_id > 0) {
            $stmt_cup = $pdo->prepare("SELECT cup_code FROM kds_cups WHERE id = ? AND deleted_at IS NULL");
            $stmt_cup->execute([$cup_id]);
            $cup_code = $stmt_cup->fetchColumn();
        }
        
        $ice_code = $item['ice'] ?? null;
        $sweet_code = $item['sugar'] ?? null;

        return [
            'product_code' => $p_code,
            'cup_code'     => $cup_code ? (string)$cup_code : null,
            'ice_code'     => $ice_code ? (string)$ice_code : null,
            'sweet_code'   => $sweet_code ? (string)$sweet_code : null,
        ];
    }
}

/* -------------------------------------------------------------------------- */
/* [B1.2] 次卡 (PASS) 核心业务逻辑                                      */
/* -------------------------------------------------------------------------- */

/**
 * [B1.2] 获取购物车中所有 menu_item_id 关联的 tags
 * @param PDO $pdo
 * @param array $cart_menu_item_ids (来自 $item['product_id'])
 * @return array [ menu_item_id => [tag_code1, tag_code2] ]
 */
if (!function_exists('get_cart_item_tags')) {
    function get_cart_item_tags(PDO $pdo, array $cart_menu_item_ids): array {
        if (empty($cart_menu_item_ids)) {
            return [];
        }
        $unique_ids = array_unique(array_filter(array_map('intval', $cart_menu_item_ids)));
        if (empty($unique_ids)) {
            return [];
        }
        
        $placeholders = implode(',', array_fill(0, count($unique_ids), '?'));
        
        $sql = "
            SELECT map.product_id, t.tag_code
            FROM pos_product_tag_map map
            JOIN pos_tags t ON map.tag_id = t.tag_id
            WHERE map.product_id IN ($placeholders)
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($unique_ids);
        
        $tags_by_item = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $tags_by_item[(int)$row['product_id']][] = $row['tag_code'];
        }
        
        return $tags_by_item;
    }
}

/**
 * [B1.2] 获取次卡方案详情
 * @param PDO $pdo
 * @param int $plan_id
 * @return array|null
 */
if (!function_exists('get_pass_plan_details')) {
    function get_pass_plan_details(PDO $pdo, int $plan_id): ?array {
        $stmt = $pdo->prepare("SELECT * FROM pass_plans WHERE pass_plan_id = ? AND is_active = 1");
        $stmt->execute([$plan_id]);
        $plan = $stmt->fetch(PDO::FETCH_ASSOC);
        return $plan ?: null;
    }
}

/**
 * [B1.2] 分配 VR 非税凭证号 (原子计数器)
 *
 * @param PDO $pdo
 * @param string $store_prefix 门店前缀 (e.g., "S1")
 * @return array [string $full_series, int $next_number]
 * @throws Exception
 */
if (!function_exists('allocate_vr_invoice_number')) {
    function allocate_vr_invoice_number(PDO $pdo, string $store_prefix): array {
        if (empty($store_prefix)) {
            throw new Exception('VR Invoice prefix cannot be empty.');
        }

        // 1. 确定系列 (Series)
        // 格式: {Prefix}-VRY{YY} (e.g., S1-VRY25 for 2025)
        $year_short = date('y'); // "25"
        $vr_prefix = $store_prefix . '-VR';
        $series = $vr_prefix . 'Y' . $year_short;

        // 2. 尝试原子更新 (INSERT ... ON DUPLICATE KEY UPDATE)
        try {
            // 确保该系列存在，如果不存在，则从 0 开始创建
            $sql_init = "
                INSERT INTO pos_vr_counters 
                    (vr_prefix, series, current_number)
                VALUES 
                    (:prefix, :series, 0)
                ON DUPLICATE KEY UPDATE 
                    current_number = current_number;
            ";
            $stmt_init = $pdo->prepare($sql_init);
            $stmt_init->execute([
                ':prefix' => $vr_prefix,
                ':series' => $series
            ]);

            // 原子更新并获取新ID
            $sql_bump = "
                UPDATE pos_vr_counters
                SET current_number = LAST_INSERT_ID(current_number + 1)
                WHERE series = :series;
            ";
            $stmt_bump = $pdo->prepare($sql_bump);
            $stmt_bump->execute([':series' => $series]);
            
            // 获取 LAST_INSERT_ID()
            $next_number = (int)$pdo->lastInsertId();

            if ($next_number > 0) {
                return [$series, $next_number];
            } else {
                // Fallback: 如果 LAST_INSERT_ID() 返回 0
                $stmt_get = $pdo->prepare("SELECT current_number FROM pos_vr_counters WHERE series = :series");
                $stmt_get->execute([':series' => $series]);
                $next_number = (int)$stmt_get->fetchColumn();
                if ($next_number > 0) {
                     return [$series, $next_number];
                }
                throw new Exception("Failed to bump VR counter, LAST_INSERT_ID and subsequent SELECT were 0.");
            }

        } catch (Throwable $e) {
            // 3. 回退 (Fallback) - 如果 pos_vr_counters 表不存在或失败
            error_log("CRITICAL: VR counter failed, falling back to MAX(number). Error: " . $e->getMessage());

            $stmt_max = $pdo->prepare(
                "SELECT MAX(`voucher_number`) FROM topup_orders WHERE series = :series"
            );
            $stmt_max->execute([':series' => $series]);
            $max = (int)$stmt_max->fetchColumn();
            
            $next_number = $max + 1;
            
            return [$series, $next_number];
        }
    }
}

/**
 * [B1.2] 计算次卡分摊金额 (用于激活)
 * @param float $purchase_amount
 * @param int $total_uses
 * @return array [float $unit_allocated_base, float $last_adjustment_amount]
 */
if (!function_exists('calculate_pass_allocation')) {
    function calculate_pass_allocation(float $purchase_amount, int $total_uses): array {
        if ($total_uses <= 0) {
            throw new InvalidArgumentException("Total uses must be greater than 0.");
        }
        // 1. 计算标准单位基准价
        $unit_base = round($purchase_amount / $total_uses, 2);
        
        // 2. 计算 (N-1) 次的总和
        $sum_n_minus_1 = $unit_base * ($total_uses - 1);
        
        // 3. 计算最后一次的补差金额
        $last_adjustment = $purchase_amount - $sum_n_minus_1;

        return [
            'unit_allocated_base' => $unit_base,
            'last_adjustment_amount' => $last_adjustment // 仅用于审计，实际核销时再计算
        ];
    }
}

/**
 * [B1.2] 服务端校验售卡订单 (B1 兜底)
 * @param PDO $pdo
 * @param array $cart 购物车
 * @param array $cart_tags 从 get_cart_item_tags() 获取的标签
 * @param mixed $promo_result 促销引擎的计算结果
 * @throws Exception
 */
if (!function_exists('validate_pass_purchase_order')) {
    function validate_pass_purchase_order(PDO $pdo, array $cart, array $cart_tags, $promo_result): void {
        
        // 规则 1: 购物车不能为空
        if (empty($cart)) {
            throw new Exception('售卡订单购物车不能为空。', 400);
        }

        // 规则 2: 购物车仅能包含 'pass_product' 标签的商品
        foreach ($cart as $item) {
            $menu_item_id = $item['product_id'] ?? null; // product_id 在 POS 端是 menu_item_id
            if (!$menu_item_id || !isset($cart_tags[$menu_item_id]) || !in_array('pass_product', $cart_tags[$menu_item_id], true)) {
                throw new Exception('售卡订单中包含非次卡商品 (ID: '.$menu_item_id.')，已拒绝。', 400);
            }
        }

        // 规则 3: 售卡订单禁止任何优惠
        if ($promo_result) {
            $discount = (float)($promo_result['discount_amount'] ?? 0);
            if ($discount > 0) {
                throw new Exception('售卡订单禁止使用任何优惠券或自动折扣。', 403);
            }
        }
        
        // 规则 4: (在 B2 实现) 禁止赠饮、员工餐等
    }
}

/**
 * [B1.2] 售卡 - 写入数据库
 * @param PDO $pdo
 * @param array $context [store_id, user_id, device_id, member_id]
 * @param array $vr_info [series, number]
 * @param array $cart_item (注意：B1 阶段售卡订单只允许1个次卡)
 * @param array $plan_details (来自 pass_plans 的快照)
 * @return int $member_pass_id
 * @throws Exception
 */
if (!function_exists('create_pass_records')) {
    function create_pass_records(PDO $pdo, array $context, array $vr_info, array $cart_item, array $plan_details): int {
        
        $now_utc = utc_now();
        $sale_time_utc = $now_utc->format('Y-m-d H:i:s.u');
        
        // 1. 计算分摊
        $purchase_amount = (float)($cart_item['unit_price_eur'] * $cart_item['qty']);
        $total_uses = (int)$plan_details['total_uses'];
        $alloc = calculate_pass_allocation($purchase_amount, $total_uses);
        $unit_allocated_base = $alloc['unit_allocated_base'];

        // 2. 创建售卡订单 (topup_orders)
        $sql_topup = "
            INSERT INTO topup_orders
                (pass_plan_id, member_id, quantity, amount_total, 
                 store_id, device_id, sale_user_id, sale_time, 
                 voucher_series, voucher_number, review_status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')";
        
        $pdo->prepare($sql_topup)->execute([
            $plan_details['pass_plan_id'],
            $context['member_id'],
            $cart_item['qty'], // 假设 B1 阶段 qty 总是 1
            $purchase_amount,
            $context['store_id'],
            $context['device_id'],
            $context['user_id'],
            $sale_time_utc,
            $vr_info['series'],
            $vr_info['number']
        ]);
        
        $topup_order_id = (int)$pdo->lastInsertId();

        // 3. 计算有效期
        $validity_days = (int)$plan_details['validity_days'];
        $expires_at_utc_str = null;
        if ($validity_days > 0) {
            $expires_dt = (clone $now_utc)->add(new DateInterval("P{$validity_days}D"));
            $expires_at_utc_str = $expires_dt->format('Y-m-d H:i:s');
        }

        // 4. 创建会员持卡 (member_passes) - 激活即用
        $sql_pass = "
            INSERT INTO member_passes
                (member_id, pass_plan_id, topup_order_id, 
                 total_uses, remaining_uses, purchase_amount, unit_allocated_base,
                 status, store_id, device_id, activated_at, expires_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, ?, ?)";
        
        $pdo->prepare($sql_pass)->execute([
            $context['member_id'],
            $plan_details['pass_plan_id'],
            $topup_order_id,
            $total_uses,
            $total_uses, // 激活时 剩余=总数
            $purchase_amount,
            $unit_allocated_base,
            $context['store_id'],
            $context['device_id'],
            $sale_time_utc, // 激活时间 = 销售时间
            $expires_at_utc_str
        ]);
        
        $member_pass_id = (int)$pdo->lastInsertId();

        // 5. 写入审计 (B1 阶段可选，B3 必须)
        
        return $member_pass_id;
    }
}


/* -------------------------------------------------------------------------- */
/* 迁移自: pos_shift_handler.php                                              */
/* -------------------------------------------------------------------------- */

if (!function_exists('table_exists')) {
    function table_exists(PDO $pdo, string $table): bool {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    }
}
if (!function_exists('col_exists')) {
    function col_exists(PDO $pdo, string $table, string $col): bool {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
        $stmt->execute([$table, $col]);
        return (bool)$stmt->fetchColumn();
    }
}

if (!function_exists('compute_expected_cash')) {
    function compute_expected_cash(PDO $pdo, int $store_id, string $start_iso, string $end_iso, float $starting_float): array {
        $cash_sales   = 0.0;
        $cash_in      = 0.0;
        $cash_out     = 0.0;
        $cash_refunds = 0.0;

        // 1) 优先用 pos_payments(method='CASH')
        if (table_exists($pdo, 'pos_payments')) {
            $ts_col  = col_exists($pdo,'pos_payments','paid_at')    ? 'paid_at'
                    : (col_exists($pdo,'pos_payments','created_at') ? 'created_at' : null);
            $amt_col = col_exists($pdo,'pos_payments','amount_eur') ? 'amount_eur'
                    : (col_exists($pdo,'pos_payments','amount')     ? 'amount'     : null);
            if ($ts_col && $amt_col && col_exists($pdo,'pos_payments','method')) {
                // 现金销售（正额，排除退款）
                $sql = "SELECT COALESCE(SUM($amt_col),0) FROM pos_payments
                        WHERE store_id=? AND method='CASH' AND $ts_col BETWEEN ? AND ?
                          AND (NOT (COALESCE(is_refund,0)=1))";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$store_id, $start_iso, $end_iso]);
                $cash_sales = (float)$stmt->fetchColumn();

                // 现金退款
                if (col_exists($pdo, 'pos_payments', 'is_refund')) {
                    $sql = "SELECT COALESCE(SUM($amt_col),0) FROM pos_payments
                            WHERE store_id=? AND method='CASH' AND COALESCE(is_refund,0)=1
                              AND $ts_col BETWEEN ? AND ?";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$store_id, $start_iso, $end_iso]);
                    $cash_refunds = (float)$stmt->fetchColumn();
                } else {
                    // 无 is_refund 字段时：负金额视作退款
                    $sql = "SELECT COALESCE(SUM(CASE WHEN $amt_col<0 THEN -$amt_col ELSE 0 END),0)
                            FROM pos_payments
                            WHERE store_id=? AND method='CASH' AND $ts_col BETWEEN ? AND ?";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$store_id, $start_iso, $end_iso]);
                    $cash_refunds = (float)$stmt->fetchColumn();
                }
            }
        }
        // 2) 回退：pos_orders（payment_method='CASH'）
        elseif (table_exists($pdo, 'pos_orders')) {
            $ts_col  = col_exists($pdo,'pos_orders','paid_at')     ? 'paid_at'
                    : (col_exists($pdo,'pos_orders','created_at')  ? 'created_at' : null);
            $amt_col = col_exists($pdo,'pos_orders','grand_total_eur')   ? 'grand_total_eur'
                    : (col_exists($pdo,'pos_orders','total_amount_eur') ? 'total_amount_eur'
                    : (col_exists($pdo,'pos_orders','total')           ? 'total' : null));
            if ($ts_col && $amt_col && col_exists($pdo,'pos_orders','payment_method')) {
                $where_paid = col_exists($pdo,'pos_orders','status') ? "AND status IN ('PAID','COMPLETED','CLOSED')" : '';
                $sql = "SELECT COALESCE(SUM($amt_col),0) FROM pos_orders
                        WHERE store_id=? AND payment_method='CASH' $where_paid
                          AND $ts_col BETWEEN ? AND ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$store_id, $start_iso, $end_iso]);
                $cash_sales = (float)$stmt->fetchColumn();
            }
        }

        // 3) 现金出入：pos_cash_movements
        if (table_exists($pdo, 'pos_cash_movements')) {
            $ts_col  = col_exists($pdo,'pos_cash_movements','created_at') ? 'created_at'
                    : (col_exists($pdo,'pos_cash_movements','occurred_at') ? 'occurred_at' : null);
            $amt_col = col_exists($pdo,'pos_cash_movements','amount_eur') ? 'amount_eur'
                    : (col_exists($pdo,'pos_cash_movements','amount')     ? 'amount'     : null);
            $type_col= col_exists($pdo,'pos_cash_movements','movement_type') ? 'movement_type'
                    : (col_exists($pdo,'pos_cash_movements','type')          ? 'type'          : null);
            if ($ts_col && $amt_col && $type_col) {
                // IN
                $sql = "SELECT COALESCE(SUM($amt_col),0) FROM pos_cash_movements
                        WHERE store_id=? AND $type_col IN ('CASH_IN','SAFE_IN','ADJUST_IN')
                          AND $ts_col BETWEEN ? AND ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$store_id, $start_iso, $end_iso]);
                $cash_in = (float)$stmt->fetchColumn();

                // OUT
                $sql = "SELECT COALESCE(SUM($amt_col),0) FROM pos_cash_movements
                        WHERE store_id=? AND $type_col IN ('CASH_OUT','PAYOUT','SAFE_OUT','ADJUST_OUT')
                          AND $ts_col BETWEEN ? AND ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$store_id, $start_iso, $end_iso]);
                $cash_out = (float)$stmt->fetchColumn();
            }
        }

        $expected_cash = (float)$starting_float + (float)$cash_sales + (float)$cash_in - (float)$cash_out - (float)$cash_refunds;

        return [
            'starting_float' => round((float)$starting_float, 2),
            'cash_sales'     => round((float)$cash_sales, 2),
            'cash_in'        => round((float)$cash_in, 2),
            'cash_out'       => round((float)$cash_out, 2),
            'cash_refunds'   => round((float)$cash_refunds, 2),
            'expected_cash'  => round((float)$expected_cash, 2),
        ];
    }
}


/* -------------------------------------------------------------------------- */
/* 迁移自: submit_order.php                                                   */
/* -------------------------------------------------------------------------- */

if (!function_exists('to_float')) {
    function to_float($v): float {
      if (is_int($v) || is_float($v)) return (float)$v;
      if (!is_string($v)) return 0.0;
      $s = trim($v);
      $s = preg_replace('/[^\d\.,\-]/u', '', $s);
      if (strpos($s, ',') !== false && strpos($s, '.') === false) {
        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);
      } else {
        $s = str_replace(',', '', $s);
      }
      if ($s === '' || $s === '-' || $s === '.') return 0.0;
      return is_numeric($s) ? (float)$s : 0.0;
    }
}

if (!function_exists('extract_payment_totals')) {
    function extract_payment_totals($payment): array {
      $cash = 0.0; $card = 0.0; $platform = 0.0;
    
      if (!is_array($payment)) {
        $tmp = to_float($payment);
        if ($tmp > 0) $cash += $tmp;
        $payment = [];
      }
    
      // 1) summary = 对象 {cash, card, platform...}
      if (isset($payment['summary']) && is_array($payment['summary']) && array_values($payment['summary']) !== $payment['summary']) {
        $s = $payment['summary'];
        $cash     += to_float($s['cash'] ?? 0);
        $card     += to_float($s['card'] ?? 0);
        $platform += to_float($s['platform'] ?? 0)
                   + to_float($s['bizum'] ?? 0) + to_float($s['qr'] ?? 0)
                   + to_float($s['wechat'] ?? 0) + to_float($s['alipay'] ?? 0)
                   + to_float($s['online'] ?? 0) + to_float($s['stripe'] ?? 0)
                   + to_float($s['paypal'] ?? 0);
      }
    
      // 2) summary = 数组 [{method:'Cash', amount:4.5}, ...]
      if (isset($payment['summary']) && is_array($payment['summary']) && array_values($payment['summary']) === $payment['summary']) {
        foreach ($payment['summary'] as $line) {
          if (!is_array($line)) continue;
          $amount = to_float($line['amount'] ?? $line['value'] ?? 0);
          $m = strtolower((string)($line['method'] ?? $line['type'] ?? $line['channel'] ?? $line['name'] ?? ''));
          if (in_array($m, ['cash','efectivo'])) $cash += $amount;
          elseif (in_array($m, ['card','tarjeta'])) $card += $amount;
          else $platform += $amount;
        }
      }
    
      // 3) 顶层字段
      $cash     += to_float($payment['cash'] ?? 0);
      $card     += to_float($payment['card'] ?? 0);
      $platform += to_float($payment['platform'] ?? 0)
                 + to_float($payment['bizum'] ?? 0) + to_float($payment['qr'] ?? 0)
                 + to_float($payment['wechat'] ?? 0) + to_float($payment['alipay'] ?? 0)
                 + to_float($payment['online'] ?? 0) + to_float($payment['stripe'] ?? 0)
                 + to_float($payment['paypal'] ?? 0);
    
      // 4) methods/tenders/lines
      foreach (['methods','tenders','lines'] as $k) {
        if (!empty($payment[$k]) && is_array($payment[$k])) {
          foreach ($payment[$k] as $m) {
            if (!is_array($m)) continue;
            $amount = to_float($m['amount'] ?? $m['value'] ?? 0);
            $t = strtolower((string)($m['type'] ?? $m['method'] ?? $m['channel'] ?? $m['name'] ?? ''));
            if (in_array($t, ['cash','efectivo'])) $cash += $amount;
            elseif (in_array($t, ['card','tarjeta'])) $card += $amount;
            else $platform += $amount;
          }
        }
      }
    
      // 5) paid / breakdown
      foreach (['paid','breakdown'] as $obj) {
        if (!empty($payment[$obj]) && is_array($payment[$obj])) {
          $o = $payment[$obj];
          $cash     += to_float($o['cash'] ?? 0);
          $card     += to_float($o['card'] ?? 0);
          $platform += to_float($o['platform'] ?? 0)
                     + to_float($o['bizum'] ?? 0) + to_float($o['qr'] ?? 0)
                     + to_float($o['wechat'] ?? 0) + to_float($o['alipay'] ?? 0)
                     + to_float($o['online'] ?? 0) + to_float($o['stripe'] ?? 0)
                     + to_float($o['paypal'] ?? 0);
        }
      }
    
      // 6) 兜底
      if ($cash == 0.0 && $card == 0.0 && $platform == 0.0) {
        $total  = to_float($payment['total']  ?? 0);
        $paid   = to_float($payment['paid']   ?? 0);
        $change = to_float($payment['change'] ?? 0);
        $candidate = 0.0;
        if ($paid > 0 || $change > 0) $candidate = max(0.0, $paid - $change);
        elseif ($total > 0) $candidate = $total;
        if ($candidate > 0) $cash = round($candidate, 2);
      }
    
      $cash = round($cash, 2); $card = round($card, 2); $platform = round($platform, 2);
      $sumPaid = round($cash + $card + $platform, 2);
    
      if (!isset($payment['summary']) || !is_array($payment['summary']) || array_values($payment['summary']) === $payment['summary']) {
        $payment['summary'] = ['cash'=>$cash,'card'=>$card,'platform'=>$platform,'total'=>$sumPaid];
      } else {
        $payment['summary']['cash'] = $cash;
        $payment['summary']['card'] = $card;
        $payment['summary']['platform'] = $platform;
        $payment['summary']['total'] = $sumPaid;
      }
      return [$cash, $card, $platform, $sumPaid, $payment];
    }
}

if (!function_exists('allocate_invoice_number')) {
    /**
     * [PHASE 3a MODIFIED] 
     * 新的发票号逻辑 (原子计数器)
     *
     * @param PDO $pdo
     * @param string $invoice_prefix 门店前缀 (e.g., "S1")
     * @param string $compliance_system (e.g., "TICKETBAI")
     * @return array [string $full_prefix, int $next_number]
     * @throws Exception
     */
    function allocate_invoice_number(PDO $pdo, string $invoice_prefix, ?string $compliance_system): array {
        if (empty($invoice_prefix)) {
            throw new Exception('Invoice prefix cannot be empty.');
        }

        // 1. 确定系列 (Series)
        // 格式: {Prefix}Y{YY} (e.g., S1Y25 for 2025)
        $year_short = date('y'); // "25"
        $series = $invoice_prefix . 'Y' . $year_short;
        $compliance_system_key = $compliance_system ?: 'NONE';

        // 2. 尝试原子更新 (INSERT ... ON DUPLICATE KEY UPDATE)
        try {
            // 确保该系列存在，如果不存在，则从 0 开始创建
            $sql_init = "
                INSERT INTO pos_invoice_counters 
                    (invoice_prefix, series, compliance_system, current_number)
                VALUES 
                    (:prefix, :series, :system, 0)
                ON DUPLICATE KEY UPDATE 
                    current_number = current_number;
            ";
            $stmt_init = $pdo->prepare($sql_init);
            $stmt_init->execute([
                ':prefix' => $invoice_prefix,
                ':series' => $series,
                ':system' => $compliance_system_key
            ]);

            // 原子更新并获取新ID
            $sql_bump = "
                UPDATE pos_invoice_counters
                SET current_number = LAST_INSERT_ID(current_number + 1)
                WHERE series = :series AND compliance_system = :system;
            ";
            $stmt_bump = $pdo->prepare($sql_bump);
            $stmt_bump->execute([
                ':series' => $series,
                ':system' => $compliance_system_key
            ]);
            
            // 获取 LAST_INSERT_ID()
            $next_number = (int)$pdo->lastInsertId();

            if ($next_number > 0) {
                // 成功！返回前缀和新号码
                return [$series, $next_number];
            } else {
                // 如果 LAST_INSERT_ID() 返回 0 (例如，在某些复制或特定MySQL版本下)
                // 我们必须再次查询以获取当前值
                $stmt_get = $pdo->prepare("SELECT current_number FROM pos_invoice_counters WHERE series = :series AND compliance_system = :system");
                $stmt_get->execute([':series' => $series, ':system' => $compliance_system_key]);
                $next_number = (int)$stmt_get->fetchColumn();
                if ($next_number > 0) {
                     return [$series, $next_number];
                }
                
                throw new Exception("Failed to bump invoice counter, LAST_INSERT_ID and subsequent SELECT were 0.");
            }

        } catch (Throwable $e) {
            // 3. 回退 (Fallback) - 如果 pos_invoice_counters 表不存在或失败
            error_log("CRITICAL: Invoice counter failed, falling back to MAX(number). Error: " . $e->getMessage());

            $stmt_max = $pdo->prepare(
                "SELECT MAX(`number`) FROM pos_invoices WHERE series = :series"
            );
            $stmt_max->execute([':series' => $series]);
            $max = (int)$stmt_max->fetchColumn();
            
            $next_number = $max + 1;
            
            return [$series, $next_number];
        }
    }
}


/* -------------------------------------------------------------------------- */
/* 迁移自: eod_summary_handler.php                                            */
/* -------------------------------------------------------------------------- */

if (!function_exists('getInvoiceSummaryForPeriod')) {
    function getInvoiceSummaryForPeriod(PDO $pdo, int $store_id, string $start_utc, string $end_utc): array {
        $invoices_table = 'pos_invoices';

        // 1. 计算交易总览
        $sqlInv = "SELECT 
                       COUNT(*) AS transactions_count,
                       COALESCE(SUM(taxable_base + vat_amount), 0) AS system_gross_sales,
                       COALESCE(SUM(discount_amount), 0) AS system_discounts,
                       COALESCE(SUM(final_total), 0) AS system_net_sales,
                       COALESCE(SUM(vat_amount), 0) AS system_tax
                   FROM `{$invoices_table}`
                   WHERE store_id=:sid AND issued_at BETWEEN :s AND :e AND status = 'ISSUED'";
        
        $st = $pdo->prepare($sqlInv);
        $st->execute([':sid' => $store_id, ':s' => $start_utc, ':e' => $end_utc]);
        $summary = $st->fetch(PDO::FETCH_ASSOC);

        // 2. 计算支付方式分类汇总
        $sqlPay = "SELECT payment_summary FROM `{$invoices_table}` WHERE store_id=:sid AND issued_at BETWEEN :s AND :e AND status = 'ISSUED'";
        $stmtPay = $pdo->prepare($sqlPay);
        $stmtPay->execute([':sid' => $store_id, ':s' => $start_utc, ':e' => $end_utc]);
        
        $breakdown = ['Cash' => 0.0, 'Card' => 0.0, 'Platform' => 0.0];
        
        while ($row = $stmtPay->fetch(PDO::FETCH_ASSOC)) {
            if (empty($row['payment_summary'])) continue;
            $payment_data = json_decode($row['payment_summary'], true);
            if (!is_array($payment_data)) continue;
            
            if (isset($payment_data['summary']) && is_array($payment_data['summary'])) {
                $summary_part = $payment_data['summary'];
                if (isset($summary_part[0]) || empty($summary_part)) {
                    foreach ($summary_part as $part) {
                        if (isset($part['method'], $part['amount']) && isset($breakdown[$part['method']])) {
                            $breakdown[$part['method']] += (float)$part['amount'];
                        }
                    }
                } 
                else {
                     if (isset($summary_part['cash'])) $breakdown['Cash'] += (float)$summary_part['cash'];
                     if (isset($summary_part['card'])) $breakdown['Card'] += (float)$summary_part['card'];
                     if (isset($summary_part['platform'])) $breakdown['Platform'] += (float)$summary_part['platform'];
                }
            } 
            else {
                if (isset($payment_data['cash'])) $breakdown['Cash'] += (float)$payment_data['cash'];
                if (isset($payment_data['card'])) $breakdown['Card'] += (float)$payment_data['card'];
                if (isset($payment_data['platform'])) $breakdown['Platform'] += (float)$payment_data['platform'];
            }
            
            if (isset($payment_data['change']) && (float)$payment_data['change'] > 0) {
                $breakdown['Cash'] -= (float)$payment_data['change'];
            }
        }
        
        foreach($breakdown as &$value) {
            $value = max(0, round($value, 2));
        }

        // 3. 组合最终结果
        return [
            'summary' => $summary,
            'payments' => $breakdown
        ];
    }
}