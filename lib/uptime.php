<?php
declare(strict_types=1);

/**
 * UptimeRobot 讀取（戰情室「網站監控」區塊；Asana 1219371344401545）。
 * 參考大蘇 it.ccra.tw/ops 的 uptime.php（MIT）改寫。
 *
 * - 唯讀 API 金鑰放 state/uptimerobot-readonly.key（網站根目錄外、cora:www-data 640、不進 git），
 *   只在主機端使用，不送到瀏覽器。
 * - 結果快取 60 秒（state/uptime_cache.json）：免費方案有呼叫頻率限制，戰情室重新整理不會每次都打 API。
 * - API 失敗時用上次的快取並標註；完全沒有資料才顯示錯誤。
 *
 * 回傳 [資料或 null, 提示訊息]；資料 = ['at' => 取得時間, 'monitors' => [...]]。
 */

const UPTIME_STATE_DIR = __DIR__ . '/../state';
const UPTIME_CACHE_TTL = 60;

/** UptimeRobot 監控狀態碼 → [燈號, 中文] */
const UPTIME_STATUS = [
    0 => ['paused', '暫停監控'],
    1 => ['wait', '尚未檢查'],
    2 => ['ok', '正常'],
    8 => ['warn', '疑似中斷'],
    9 => ['bad', '中斷'],
];

function uptime_data(): array
{
    $cache = UPTIME_STATE_DIR . '/uptime_cache.json';
    $cached = is_file($cache) ? json_decode((string) file_get_contents($cache), true) : null;
    if (is_array($cached) && time() - (int) ($cached['at'] ?? 0) < UPTIME_CACHE_TTL) {
        return [$cached, ''];
    }

    $key = trim((string) @file_get_contents(UPTIME_STATE_DIR . '/uptimerobot-readonly.key'));
    if ($key === '') {
        return [is_array($cached) ? $cached : null, '還沒有設定 UptimeRobot 唯讀金鑰（state/uptimerobot-readonly.key）。'];
    }

    $body = http_build_query([
        'api_key' => $key, 'format' => 'json',
        'custom_uptime_ratios' => '1-7-30',
        'response_times' => 1, 'response_times_average' => 30, 'response_times_limit' => 1,
        'logs' => 1, 'logs_limit' => 3,
    ]);
    $ctx = stream_context_create(['http' => [
        'method' => 'POST', 'timeout' => 10, 'content' => $body, 'ignore_errors' => true,
        'header' => "Content-Type: application/x-www-form-urlencoded\r\nCache-Control: no-cache\r\nUser-Agent: ops.ccnda.net\r\n",
    ]]);
    $raw = @file_get_contents('https://api.uptimerobot.com/v2/getMonitors', false, $ctx);
    $j = is_string($raw) ? json_decode($raw, true) : null;

    if (is_array($j) && ($j['stat'] ?? '') === 'ok') {
        $data = ['at' => time(), 'monitors' => $j['monitors'] ?? []];
        @file_put_contents($cache, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
        return [$data, ''];
    }
    // 錯誤訊息不含金鑰：UptimeRobot 的錯誤 JSON 只有 type／message
    $why = is_array($j) ? (string) ($j['error']['message'] ?? $j['error']['type'] ?? 'API 回應錯誤') : '連不到 UptimeRobot';
    error_log('[ops] UptimeRobot 讀取失敗：' . $why);
    if (is_array($cached)) {
        return [$cached, '暫時讀不到 UptimeRobot（' . $why . '），以下是 ' . date('m-d H:i', (int) $cached['at']) . ' 的資料。'];
    }
    return [null, '讀不到 UptimeRobot（' . $why . '），請稍後再試。'];
}

/** 一個監控 → 畫面要用的欄位 */
function uptime_view(array $m): array
{
    [$cls, $label] = UPTIME_STATUS[(int) ($m['status'] ?? 1)] ?? ['wait', '未知'];
    $ratios = array_map('floatval', explode('-', (string) ($m['custom_uptime_ratio'] ?? '')));
    $lastDown = null;
    foreach ((array) ($m['logs'] ?? []) as $log) {
        if ((int) ($log['type'] ?? 0) === 1) { // 1 = down
            $lastDown = ['at' => (int) $log['datetime'], 'sec' => (int) ($log['duration'] ?? 0)];
            break;
        }
    }
    return [
        'name'   => (string) ($m['friendly_name'] ?? ''),
        'url'    => (string) ($m['url'] ?? ''),
        'cls'    => $cls,
        'label'  => $label,
        'd1'     => $ratios[0] ?? null,
        'd7'     => $ratios[1] ?? null,
        'd30'    => $ratios[2] ?? null,
        'ms'     => isset($m['average_response_time']) ? (int) round((float) $m['average_response_time']) : null,
        'down'   => $lastDown,
    ];
}
