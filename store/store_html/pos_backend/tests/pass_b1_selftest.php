<?php
/**
 * B1 自测脚本（并发核销 & 每日限次验证）
 * 方式：对 POS API 发起并发 HTTP 请求（curl_multi），不改后端代码。
 * 依赖：PHP >=7.4，启用 curl 扩展。已登录会话的 Cookie 文件。
 *
 * 用法示例：
 * php pass_b1_selftest.php \
 *   --base_url="https://your.store.domain/store_html/html/pos/api/pos_api_gateway.php" \
 *   --cookie_file="/path/to/cookie.txt" \
 *   --payload="/path/to/redeem_payload.json"
 *
 * redeem_payload.json 示例（根据你的环境修改 product_id 等）：
 * {
 *   "member_id": 123,
 *   "member_pass_id": 456,
 *   "redeemed_uses_in_order": 1,
 *   "device_id": "TEST-DEVICE-01",
 *   "madrid_date": "2025-11-09",
 *   "cart": [
 *     { "product_id": 1001, "title_zh":"经典奶茶", "qty": 1, "final_price": 3.50, "addons": [] }
 *   ],
 *   "payment": [{ "method": "cash", "amount": 0 }]
 * }
 */

declare(strict_types=1);

$options = getopt('', ['base_url:', 'cookie_file:', 'payload:']);
$baseUrl     = $options['base_url']     ?? null;
$cookieFile  = $options['cookie_file']  ?? null;
$payloadPath = $options['payload']      ?? null;

if (!$baseUrl || !$cookieFile || !$payloadPath) {
    fwrite(STDERR, "参数缺失：--base_url / --cookie_file / --payload\n");
    exit(2);
}
if (!file_exists($cookieFile)) { fwrite(STDERR, "找不到 cookie_file\n"); exit(2); }
if (!file_exists($payloadPath)) { fwrite(STDERR, "找不到 payload\n"); exit(2); }

$payloadJson = file_get_contents($payloadPath);
$payload = json_decode($payloadJson, true);
if (!is_array($payload)) { fwrite(STDERR, "payload 不是有效 JSON\n"); exit(2); }

// 强制幂等键（同单并发双提）
$idempotency = bin2hex(random_bytes(8));
$payload['idempotency_key'] = $idempotency;

// 目标 URL（核销）
$url = rtrim($baseUrl, '?&') . '?res=pass&act=redeem';

// 构造两个并发请求
$mh = curl_multi_init();
$chs = [];

for ($i = 0; $i < 2; $i++) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_COOKIEJAR  => $cookieFile,
        CURLOPT_TIMEOUT => 30,
    ]);
    curl_multi_add_handle($mh, $ch);
    $chs[] = $ch;
}

// 并发执行
$running = null;
do {
    $mrc = curl_multi_exec($mh, $running);
    if ($mrc == CURLM_CALL_MULTI_PERFORM) continue;
    curl_multi_select($mh, 0.25);
} while ($running > 0 && $mrc == CURLM_OK);

// 输出结果
foreach ($chs as $idx => $ch) {
    $resp = curl_multi_getcontent($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    echo "---- Response #" . ($idx+1) . " HTTP: $http ----\n";
    echo $resp . "\n";
    curl_multi_remove_handle($mh, $ch);
    curl_close($ch);
}
curl_multi_close($mh);
echo "幂等键: $idempotency\n";
