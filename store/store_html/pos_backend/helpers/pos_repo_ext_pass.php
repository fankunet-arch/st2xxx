<?php
/**
 * Toptea POS - Repo 扩展 (次卡/票号/门店配置等通用查询)
 * Scope: POS-only，禁止跨 HQ/KDS。
 * Version: 1.0.0
 * Date: 2025-11-09
 */

declare(strict_types=1);

require_once realpath(__DIR__ . '/pos_datetime_helper.php');
require_once realpath(__DIR__ . '/pos_json_helper.php');

if (!function_exists('safe_uuid')) {
    /** 生成 RFC4122 v4 UUID */
    function safe_uuid(): string {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

/** 读取门店配置（最小必需字段） */
if (!function_exists('get_store_config_full')) {
    function get_store_config_full(PDO $pdo, int $store_id): array {
        $stmt = $pdo->prepare("
            SELECT id, store_name, invoice_prefix, tax_id, default_vat_rate, billing_system, eod_cutoff_hour
              FROM kds_stores
             WHERE id = ? AND deleted_at IS NULL
        ");
        $stmt->execute([$store_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) json_error('门店配置不存在。', 404);
        return $row;
    }
}

/** 加锁读取会员次卡 */
if (!function_exists('get_member_pass_for_update')) {
    function get_member_pass_for_update(PDO $pdo, int $member_pass_id): array {
        $sql = "
            SELECT mp.*, pp.total_uses AS plan_total_uses, pp.validity_days,
                   pp.max_uses_per_order, pp.max_uses_per_day, pp.allocation_strategy
              FROM member_passes mp
              JOIN pass_plans pp ON mp.pass_plan_id = pp.pass_plan_id
             WHERE mp.member_pass_id = ?
             FOR UPDATE
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$member_pass_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) json_error('会员次卡不存在。', 404);
        return $row;
    }
}

/** 可选：单独读取方案 */
if (!function_exists('get_pass_plan_details')) {
    function get_pass_plan_details(PDO $pdo, int $pass_plan_id): array {
        $stmt = $pdo->prepare("SELECT * FROM pass_plans WHERE pass_plan_id = ?");
        $stmt->execute([$pass_plan_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) json_error('次卡方案不存在。', 404);
        return $row;
    }
}

/** 购物车条目补充显示字段 */
if (!function_exists('get_cart_item_tags')) {
    function get_cart_item_tags(PDO $pdo, array $cart): array {
        foreach ($cart as &$it) {
            $it['display_name'] = $it['title_zh'] ?? ($it['item_name'] ?? '项');
        }
        return $cart;
    }
}

/** 汇总支付方式（给票据 payment_summary 字段） */
if (!function_exists('extract_payment_totals')) {
    function extract_payment_totals(array $payment_list): array {
        $sum = 0.0; $by = [];
        foreach ((array)$payment_list as $p) {
            $m = strtolower((string)($p['method'] ?? ''));
            $a = (float)($p['amount'] ?? 0);
            if ($a <= 0) continue;
            $sum += $a; $by[$m] = ($by[$m] ?? 0) + $a;
        }
        return ['total' => $sum, 'by' => $by];
    }
}

/** 分配合规票号（基于 pos_invoice_counters 原子计数器） */
if (!function_exists('allocate_invoice_number')) {
    function allocate_invoice_number(PDO $pdo, array $store_cfg): array {
        $prefix = trim((string)($store_cfg['invoice_prefix'] ?? 'S1'));
        $series = $prefix . 'Y' . date('y'); // 例: S1Y25
        $system = (string)($store_cfg['billing_system'] ?? 'VERIFACTU');

        $pdo->beginTransaction();
        try {
            $sel = $pdo->prepare("SELECT id, current_number FROM pos_invoice_counters WHERE invoice_prefix=? AND series=? FOR UPDATE");
            $sel->execute([$prefix, $series]);
            $row = $sel->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                $ins = $pdo->prepare("INSERT INTO pos_invoice_counters (invoice_prefix, series, compliance_system, current_number) VALUES (?,?,?,0)");
                $ins->execute([$prefix, $series, $system]);

                $sel->execute([$prefix, $series]);
                $row = $sel->fetch(PDO::FETCH_ASSOC);
            }

            $next = (int)$row['current_number'] + 1;
            $upd = $pdo->prepare("UPDATE pos_invoice_counters SET current_number = ? WHERE id = ?");
            $upd->execute([$next, (int)$row['id']]);

            $pdo->commit();
            return [$series, $next];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}
