<?php
declare(strict_types=1);

/**
 * 戰情室推播：每分鐘看一次網站監控與 Zabbix，有新的異常（或解除）就推給已開啟通知的裝置。
 * 參考 it.ccra.tw deploy/push_watch.php；範圍見 lib/webpush.php 開頭。
 * 由 www-data 的 crontab 執行（和網頁同一個身分，state/ 的訂閱檔才寫得進去）：
 *   * * * * * php /srv/dev/ops.ccnda.net/tools/push_watch.php
 *
 * - 第一次跑（或新增來源）只記下目前狀態，不推舊資料，免得一開就跳一堆。
 * - 網站：UptimeRobot 狀態 9（中斷）且已連續 5 分鐘才推；恢復正常時推「已恢復」。
 * - Zabbix：嚴重（4）以上；問題消失時推「已解除」。API 權杖放 state/zabbix-push.token（沒有就跳過這段）。
 * - 資安事件、新申請單由其他程式呼叫 tools/push_send.php。
 */

if (PHP_SAPI !== 'cli') {
    exit;
}
require __DIR__ . '/../lib/webpush.php';
require __DIR__ . '/../lib/uptime.php';
date_default_timezone_set('Asia/Taipei');

const WATCH_FILE = PUSH_STATE_DIR . '/push_watch.json';
const UPTIME_DOWN_MIN_SEC = 300;
const ZBX_URL = 'http://127.0.0.1:8090/api_jsonrpc.php';

$st = is_file(WATCH_FILE) ? (json_decode((string) file_get_contents(WATCH_FILE), true) ?: []) : [];

// ---- 對外站台（UptimeRobot）----
[$up] = uptime_data();
if (is_array($up)) {
    $first = !isset($st['uptime']);
    $prev = $st['uptime'] ?? [];
    $now = [];
    foreach ($up['monitors'] as $m) {
        $id = (string) $m['id'];
        $name = (string) ($m['friendly_name'] ?? $id);
        $since = null;
        foreach ((array) ($m['logs'] ?? []) as $log) {
            if ((int) ($log['type'] ?? 0) === 1) {
                $since = (int) $log['datetime'];
                break;
            }
        }
        $down = (int) ($m['status'] ?? 0) === 9 && ($since === null || time() - $since >= UPTIME_DOWN_MIN_SEC);
        $now[$id] = $down ? 'down' : ((int) ($m['status'] ?? 0) === 2 ? 'up' : ($prev[$id] ?? 'up'));
        if ($first) {
            continue;
        }
        if ($now[$id] === 'down' && ($prev[$id] ?? 'up') !== 'down') {
            push_send(['title' => '連不上：' . $name, 'body' => '已連續 5 分鐘以上連不上' . ($since ? '（' . date('H:i', $since) . ' 起）' : ''),
                'url' => '/uptime.php', 'tag' => 'up-' . $id], 'uptime');
        } elseif ($now[$id] === 'up' && ($prev[$id] ?? 'up') === 'down') {
            push_send(['title' => '已恢復：' . $name, 'body' => '網站恢復正常', 'url' => '/uptime.php', 'tag' => 'up-' . $id], 'uptime');
        }
    }
    $st['uptime'] = $now;
}

// ---- Zabbix 嚴重（4）以上 ----
function zbx_call(string $method, array $params, string $token): array
{
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'timeout' => 10, 'ignore_errors' => true,
        'header' => "Content-Type: application/json-rpc\r\nAuthorization: Bearer $token\r\n",
        'content' => json_encode(['jsonrpc' => '2.0', 'method' => $method, 'params' => $params, 'id' => 1])]]);
    $j = json_decode((string) @file_get_contents(ZBX_URL, false, $ctx), true);
    if (!is_array($j) || !array_key_exists('result', $j)) {
        throw new RuntimeException('zabbix ' . $method . ': ' . (string) ($j['error']['data'] ?? '無回應'));
    }
    return $j['result'];
}

$token = trim((string) @file_get_contents(PUSH_STATE_DIR . '/zabbix-push.token'));
if ($token !== '') {
    try {
        $probs = zbx_call('problem.get', ['output' => ['eventid', 'name', 'severity', 'objectid'], 'severities' => [4, 5],
            'recent' => false, 'suppressed' => false], $token);
        $hostOf = [];
        if ($probs) {
            foreach (zbx_call('trigger.get', ['triggerids' => array_column($probs, 'objectid'), 'output' => ['triggerid'],
                'selectHosts' => ['name'], 'monitored' => true], $token) as $t) {
                $hostOf[$t['triggerid']] = $t['hosts'][0]['name'] ?? '';
            }
        }
        $first = !isset($st['zabbix']);
        $prev = $st['zabbix'] ?? [];
        $now = [];
        foreach ($probs as $p) {
            if (!isset($hostOf[$p['objectid']])) {
                continue; // 觸發器已停用（例如誤報）就不推
            }
            $now[$p['eventid']] = ['host' => $hostOf[$p['objectid']], 'name' => $p['name']];
            if (!$first && !isset($prev[$p['eventid']])) {
                push_send(['title' => ((int) $p['severity'] === 5 ? '災難' : '嚴重') . '：' . $hostOf[$p['objectid']], 'body' => $p['name'],
                    'url' => '/#devices', 'tag' => 'zbx-' . $p['eventid']], 'zabbix');
            }
        }
        foreach ($prev as $eid => $z) {
            if (!isset($now[$eid])) {
                push_send(['title' => '已解除：' . $z['host'], 'body' => $z['name'], 'url' => '/#devices', 'tag' => 'zbx-' . $eid], 'zabbix');
            }
        }
        $st['zabbix'] = $now;
    } catch (Throwable $e) {
        error_log('[ops] push_watch zabbix: ' . $e->getMessage()); // 讀不到就保留上次狀態，下一分鐘再試
    }
}

$tmp = WATCH_FILE . '.tmp';
file_put_contents($tmp, json_encode($st, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
rename($tmp, WATCH_FILE);
