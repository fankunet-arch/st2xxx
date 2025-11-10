<?php
/**
 * Toptea POS - 次卡业务助手
 * Scope: POS-only
 * Version: 1.0.1
 * Date: 2025-11-09
 *
 * [GEMINI FIX 2025-11-10] 修复 create_redeem_records 中
 * "SQLSTATE[HY093]: Invalid parameter number" 错误。
 * INSERT 语句的 VALUES 占位符数量与 execute() 数组不匹配。
 */

declare(strict_types=1);

require_once realpath(__DIR__ . '/pos_datetime_helper.php');
require_once realpath(__DIR__ . '/pos_repo_ext_pass.php');
require_once realpath(__DIR__ . '/../core/shift_guard.php');

/** 基础校验：状态/余量/单笔/单日限制 */
if (!function_exists('validate_pass_redeem_order')) {
    function validate_pass_redeem_order(PDO $pdo, array $pass_row, string $madrid_date, int $redeemed_uses): void {
        if (($pass_row['status'] ?? '') !== 'active') json_error('次卡状态不可用。', 409);
        if ($redeemed_uses <= 0) json_error('核销次数无效。', 422);
        if ((int)$pass_row['remaining_uses'] < $redeemed_uses) json_error('剩余次数不足。', 409);

        $max_per_order = (int)($pass_row['max_uses_per_order'] ?? 1);
        if ($max_per_order > 0 && $redeemed_uses > $max_per_order) json_error('超过单笔核销上限。', 409);

        $max_per_day = (int)($pass_row['max_uses_per_day'] ?? 0);
        if ($max_per_day > 0) {
            $stmt = $pdo->prepare("SELECT uses_count FROM pass_daily_usage WHERE member_pass_id=? AND usage_date=?");
            $stmt->execute([(int)$pass_row['member_pass_id'], $madrid_date]);
            $used = (int)($stmt->fetchColumn() ?: 0);
            if ($used + $redeemed_uses > $max_per_day) json_error('超过当日核销上限。', 409);
        }
    }
}

/**
 * 分摊：覆盖前 N 杯（N=redeemed_uses），每杯可覆盖 unit_allocated_base，超出自费
 * 返回: ['covered_total','extra_total','per_item'=>[ {index,covered,extra}... ]]
 */
if (!function_exists('calculate_redeem_allocation')) {
    function calculate_redeem_allocation(array $cart, float $unit_allocated_base, int $uses): array {
        $per_item = [];
        $covered_total = 0.0; $extra_total = 0.0;

        $units = [];
        foreach ($cart as $i => $item) {
            $qty = max(1, (int)($item['qty'] ?? 1));
            $price = (float)($item['final_price'] ?? 0);
            for ($q=0; $q<$qty; $q++) $units[] = ['index'=>$i, 'unit_price'=>$price];
        }

        $n = min($uses, count($units));
        foreach ($units as $u_idx => $u) {
            $covered = 0.0; $extra = 0.0;
            if ($u_idx < $n) {
                $covered = min($u['unit_price'], $unit_allocated_base);
                $extra   = max(0.0, $u['unit_price'] - $covered);
            } else {
                $extra = $u['unit_price'];
            }
            $covered_total += $covered;
            $extra_total   += $extra;
            $per_item[] = ['index' => $u['index'], 'covered' => $covered, 'extra' => $extra];
        }

        return [
            'covered_total' => round($covered_total, 2),
            'extra_total'   => round($extra_total, 2),
            'per_item'      => $per_item
        ];
    }
}

/**
 * 写入：票据头/行、核销批次/明细、每日用量、扣减剩余
 */
if (!function_exists('create_redeem_records')) {
    function create_redeem_records(
        PDO $pdo,
        array $store_cfg,
        array $member_pass,
        array $plan,
        array $cart,
        array $alloc,
        string $device_id,
        string $madrid_date,
        ?string $idempotency_key = null
    ): array {
        $store_id = (int)$store_cfg['id'];
        $vat_rate = (float)$store_cfg['default_vat_rate'];
        $user_id  = (int)($_SESSION['pos_user_id']  ?? 0);
        $shift_id = (int)($_SESSION['pos_shift_id'] ?? 0);

        // 票号 (依赖 pos_repo.php)
        [$series, $number] = allocate_invoice_number($pdo, (string)$store_cfg['invoice_prefix'], (string)$store_cfg['billing_system']);

        // 金额汇总：extra_total 为实付；covered_total 记为 discount
        $final_total  = $alloc['extra_total'];
        $taxable_base = round($final_total / (1 + $vat_rate / 100), 2);
        $vat_amount   = round($final_total - $taxable_base, 2);
        $discount     = $alloc['covered_total'];

        $issued_at = utc_now()->format('Y-m-d H:i:s.u');
        $invoice_uuid = $idempotency_key && strlen($idempotency_key) >= 32 ? substr($idempotency_key, 0, 36) : safe_uuid();

        $pdo->beginTransaction();
        try {
            // 1) 票据头
            
            // [GEMINI FIX 2025-11-10] 
            // 修复 HY093 错误：
            // 1. INSERT 列表 (19个字段)
            // 2. VALUES 列表 (19个值 = 17个 '?' + 1个 'ISSUED' + 1个 'NULL')
            // 3. execute() 数组 (17个参数)
            $sql_invoice = "
                INSERT INTO pos_invoices
                (
                    invoice_uuid, store_id, user_id, shift_id, issuer_nif, 
                    series, number, issued_at, invoice_type, 
                    taxable_base, vat_amount, discount_amount, final_total, 
                    status, 
                    compliance_system, compliance_data, payment_summary, 
                    references_invoice_id, correction_type
                )
                VALUES 
                (
                    ?, ?, ?, ?, ?, 
                    ?, ?, ?, ?, 
                    ?, ?, ?, ?, 
                    'ISSUED', 
                    ?, ?, ?,
                    NULL, NULL
                )
            ";
            
            $stmt = $pdo->prepare($sql_invoice);
            
            $payment_summary = json_encode(['pass_covered'=>$discount], JSON_UNESCAPED_UNICODE);
            
            // [GEMINI FIX 2025-11-10] 
            // 确保 execute() 数组有 17 个参数，匹配 17 个 '?'
            $stmt->execute([
                $invoice_uuid, $store_id, $user_id, $shift_id, (string)($store_cfg['tax_id'] ?? ''), // 5
                $series, $number, $issued_at, 'F2', // 4
                $taxable_base, $vat_amount, $discount, $final_total, // 4
                (string)($store_cfg['billing_system'] ?? 'NONE'), // 1
                '{}', // 1 (compliance_data)
                $payment_summary, // 1 (payment_summary)
                null // 1 (references_invoice_id)
            ]);
            $invoice_id = (int)$pdo->lastInsertId();

            // 2) 票据行
            $items_stmt = $pdo->prepare("
                INSERT INTO pos_invoice_items
                (invoice_id, menu_item_id, variant_id, item_name, variant_name,
                 item_name_zh, item_name_es, variant_name_zh, variant_name_es,
                 quantity, unit_price, unit_taxable_base, vat_rate, vat_amount, customizations)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            foreach ($cart as $i => $item) {
                $qty    = max(1, (int)($item['qty'] ?? 1));
                $price  = (float)($item['final_price'] ?? 0);
                $base_u = round($price / (1 + $vat_rate / 100), 2);
                $vat_u  = round($price - $base_u, 2);

                $items_stmt->execute([
                    $invoice_id,
                    (int)($item['product_id'] ?? null),
                    (int)($item['variant_id'] ?? null),
                    (string)($item['title_zh'] ?? $item['item_name'] ?? 'Item'),
                    (string)($item['variant_name'] ?? ''),
                    (string)($item['title_zh'] ?? null),
                    (string)($item['title_es'] ?? null),
                    (string)($item['variant_name_zh'] ?? null),
                    (string)($item['variant_name_es'] ?? null),
                    $qty, $price, $base_u, $vat_rate, $vat_u, json_encode($item['addons'] ?? [])
                ]);
            }

            // 3) 核销批次 + 明细
            $batch_stmt = $pdo->prepare("
                INSERT INTO pass_redemption_batches
                (member_pass_id, order_id, redeemed_uses, store_id, cashier_user_id, created_at)
                VALUES (?,?,?,?,?,?)
            ");
            $batch_stmt->execute([
                (int)$member_pass['member_pass_id'], $invoice_id,
                (int)$plan['_uses_in_this_order'], $store_id, $user_id,
                utc_now()->format('Y-m-d H:i:s.u')
            ]);
            $batch_id = (int)$pdo->lastInsertId();

            $red_stmt = $pdo->prepare("
                INSERT INTO pass_redemptions
                (batch_id, member_pass_id, order_id, order_item_id, sku_id, invoice_series, invoice_number,
                 covered_amount, extra_charge, redeemed_at, store_id, device_id, cashier_user_id)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");

            // 将分摊结果映射至对应的发票行 id
            $line_ids = [];
            $get_line_ids = $pdo->prepare("SELECT id FROM pos_invoice_items WHERE invoice_id=? ORDER BY id ASC");
            $get_line_ids->execute([$invoice_id]);
            foreach ($get_line_ids->fetchAll(PDO::FETCH_COLUMN) as $lid) $line_ids[] = (int)$lid;

            foreach ($alloc['per_item'] as $u) {
                $order_item_id = $line_ids[min($u['index'], max(0, count($line_ids)-1))] ?? $line_ids[0];
                $red_stmt->execute([
                    $batch_id,
                    (int)$member_pass['member_pass_id'],
                    $invoice_id,
                    (int)$order_item_id,
                    null,
                    $series, (int)$number,
                    (float)$u['covered'],
                    (float)$u['extra'],
                    utc_now()->format('Y-m-d H:i:s.u'),
                    $store_id, $device_id, $user_id
                ]);
            }

            // 4) 每日用量
            $sel = $pdo->prepare("SELECT uses_count FROM pass_daily_usage WHERE member_pass_id=? AND usage_date=? FOR UPDATE");
            $sel->execute([(int)$member_pass['member_pass_id'], $madrid_date]);
            $row = $sel->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $upd = $pdo->prepare("UPDATE pass_daily_usage SET uses_count = uses_count + ? WHERE member_pass_id=? AND usage_date=?");
                $upd->execute([(int)$plan['_uses_in_this_order'], (int)$member_pass['member_pass_id'], $madrid_date]);
            } else {
                $ins = $pdo->prepare("INSERT INTO pass_daily_usage (member_pass_id, usage_date, uses_count) VALUES (?,?,?)");
                $ins->execute([(int)$member_pass['member_pass_id'], $madrid_date, (int)$plan['_uses_in_this_order']]);
            }

            // 5) 扣减剩余
            $dec = $pdo->prepare("UPDATE member_passes SET remaining_uses = remaining_uses - ? WHERE member_pass_id=?");
            $dec->execute([(int)$plan['_uses_in_this_order'], (int)$member_pass['member_pass_id']]);

            $pdo->commit();
            return [
                'invoice_id' => $invoice_id,
                'series'     => $series,
                'number'     => $number,
                'final_total'=> $final_total,
                'covered'    => $discount,
                'issued_at'  => $issued_at
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}