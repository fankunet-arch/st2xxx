<?php
/**
 * Toptea POS - 统一 JSON 响应助手
 * 职责: 统一 JSON 响应格式 (json_ok, json_error) 和输入解析 (get_request_data)。
 * Version: 1.0.1
 * Date: 2025-11-09
 */

if (!function_exists('send_json_headers_once')) {
    function send_json_headers_once(): void {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
    }
}

if (!function_exists('json_ok')) {
    function json_ok($data = null, string $message = 'ok', int $http_code = 200): void {
        send_json_headers_once();
        http_response_code($http_code);
        echo json_encode([
            'status'  => 'success',
            'message' => $message,
            'data'    => $data
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!function_exists('json_error')) {
    function json_error(string $message = 'error', int $http_code = 400, $data = null): void {
        send_json_headers_once();
        http_response_code($http_code);
        echo json_encode([
            'status'  => 'error',
            'message' => $message,
            'data'    => $data
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!function_exists('get_request_data')) {
    /**
     * 解析输入数据（JSON 优先，其次表单）
     * @return array
     */
    function get_request_data(): array {
        $raw = file_get_contents('php://input');
        if (is_string($raw) && $raw !== '') {
            $j = json_decode($raw, true);
            if (is_array($j)) return $j;
        }
        // 退回表单/查询字符串
        return array_merge($_GET ?? [], $_POST ?? []);
    }
}
