<?php
declare(strict_types=1);

/**
 * Zabbix 即時狀態（戰情室設備監控區塊；Asana 1219442962700669）。
 * 用推播同一把唯讀 API 權杖 state/zabbix-push.token（使用者 ops-push，只有讀取權）；
 * 結果快取 60 秒（state/zabbix_cache.json），API 讀不到就用上次的快取並標註。
 *
 * 回傳 [資料或 null, 提示訊息]；資料 = ['at' => 取得時間, 'hosts' => [[name, cls, problems[]]...]]
 * 燈號：有「嚴重」以上問題或 ping 不通＝紅；有一般／警告問題＝黃；其餘綠。資訊級（1）不算。
 */

const ZBX_STATE_DIR = __DIR__ . '/../state';
const ZBX_API = 'http://127.0.0.1:8090/api_jsonrpc.php';
const ZBX_CACHE_TTL = 60;

function zbx_api(string $method, array $params, string $token): array
{
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'timeout' => 8, 'ignore_errors' => true,
        'header' => "Content-Type: application/json-rpc\r\nAuthorization: Bearer $token\r\n",
        'content' => json_encode(['jsonrpc' => '2.0', 'method' => $method, 'params' => $params, 'id' => 1])]]);
    $j = json_decode((string) @file_get_contents(ZBX_API, false, $ctx), true);
    if (!is_array($j) || !array_key_exists('result', $j)) {
        throw new RuntimeException((string) ($j['error']['data'] ?? '連不到 Zabbix'));
    }
    return $j['result'];
}

function zabbix_data(): array
{
    $cache = ZBX_STATE_DIR . '/zabbix_cache.json';
    $cached = is_file($cache) ? json_decode((string) file_get_contents($cache), true) : null;
    if (is_array($cached) && time() - (int) ($cached['at'] ?? 0) < ZBX_CACHE_TTL) {
        return [$cached, ''];
    }
    $token = trim((string) @file_get_contents(ZBX_STATE_DIR . '/zabbix-push.token'));
    if ($token === '') {
        return [null, '還沒有設定 Zabbix 唯讀權杖。'];
    }
    try {
        $hosts = zbx_api('host.get', ['output' => ['hostid', 'name', 'status'], 'filter' => ['status' => 0],
            'selectInterfaces' => ['ip']], $token);
        $probs = zbx_api('problem.get', ['output' => ['eventid', 'name', 'severity', 'objectid'], 'recent' => false,
            'suppressed' => false, 'severities' => [2, 3, 4, 5]], $token);
        $hostOf = [];
        if ($probs) {
            foreach (zbx_api('trigger.get', ['triggerids' => array_column($probs, 'objectid'), 'output' => ['triggerid'],
                'selectHosts' => ['hostid'], 'monitored' => true], $token) as $t) {
                $hostOf[$t['triggerid']] = $t['hosts'][0]['hostid'] ?? '';
            }
        }
        $byHost = [];
        foreach ($probs as $p) {
            if (isset($hostOf[$p['objectid']])) {
                $byHost[$hostOf[$p['objectid']]][] = ['name' => $p['name'], 'sev' => (int) $p['severity']];
            }
        }
        $out = [];
        foreach ($hosts as $hst) {
            $ps = $byHost[$hst['hostid']] ?? [];
            $max = $ps ? max(array_column($ps, 'sev')) : 0;
            $out[] = ['name' => $hst['name'], 'ip' => (string) ($hst['interfaces'][0]['ip'] ?? ''),
                'cls' => $max >= 4 ? 'bad' : ($max >= 2 ? 'warn' : 'ok'), 'problems' => $ps];
        }
        usort($out, fn($a, $b) => [$a['cls'] === 'ok', $a['name']] <=> [$b['cls'] === 'ok', $b['name']]);
        $data = ['at' => time(), 'hosts' => $out];
        @file_put_contents($cache, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
        return [$data, ''];
    } catch (Throwable $e) {
        error_log('[ops] Zabbix 讀取失敗：' . $e->getMessage());
        return [is_array($cached) ? $cached : null,
            '暫時讀不到 Zabbix' . (is_array($cached) ? '，以下是 ' . date('H:i', (int) $cached['at']) . ' 的資料。' : '。')];
    }
}
