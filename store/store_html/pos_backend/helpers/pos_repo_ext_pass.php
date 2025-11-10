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
