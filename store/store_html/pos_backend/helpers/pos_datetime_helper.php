<?php
/**
 * Toptea POS - 统一时间助手 (A2 UTC SYNC)
 * 职责: 提供 UTC 时间与 Madrid 本地日窗转换。
 * Version: 1.0.1
 * Date: 2025-11-09
 */

if (!defined('APP_DEFAULT_TIMEZONE')) {
    define('APP_DEFAULT_TIMEZONE', 'Europe/Madrid');
}

if (!function_exists('utc_now')) {
    function utc_now(): DateTime {
        return new DateTime('now', new DateTimeZone('UTC'));
    }
}

/**
 * 将本地日期（YYYY-MM-DD）转换为 UTC 窗口 [start, end)
 * @param string $local_date e.g. '2025-11-09'
 * @param string $tz         e.g. 'Europe/Madrid'
 * @return array [DateTime $utc_start, DateTime $utc_end]
 */
if (!function_exists('to_utc_window')) {
    function to_utc_window(string $local_date, string $tz = APP_DEFAULT_TIMEZONE): array {
        $tzObj = new DateTimeZone($tz);
        $localStart = new DateTime($local_date . ' 00:00:00', $tzObj);
        $localEnd   = clone $localStart; $localEnd->modify('+1 day');

        $utcStart = clone $localStart; $utcStart->setTimezone(new DateTimeZone('UTC'));
        $utcEnd   = clone $localEnd;   $utcEnd->setTimezone(new DateTimeZone('UTC'));
        return [$utcStart, $utcEnd];
    }
}

/**
 * 将 UTC 时间格式化为本地字符串
 */
if (!function_exists('fmt_local')) {
    function fmt_local($utc_datetime, string $format = 'Y-m-d H:i:s', string $tz = APP_DEFAULT_TIMEZONE): ?string {
        if ($utc_datetime === null) return null;
        $dt = ($utc_datetime instanceof DateTime)
            ? clone $utc_datetime
            : new DateTime((string)$utc_datetime, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone($tz));
        return $dt->format($format);
    }
}
