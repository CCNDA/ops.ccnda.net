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
 * - 數位同工:超過 90 分鐘沒有心跳(AGENTS_GREEN_MIN)推「停擺」,恢復時推「恢復」(2026-10-14 小布停 5 小時沒人收到通知)。
 * - 同工狀態檔由 cora-lab 的 agents_push.py 每 2 分鐘產生（10/15 從小芯電腦搬來）；超過 10 分鐘(AGENTS_STALE_SEC)沒更新,由這台自己推「蒐集中斷」,
 *   中斷期間不判斷各同工(檔案裡的時間會一起變舊,全部誤報停擺)。熊哥 10/15:「不能靠你自己監控…不然你掛了戰情室沒直接呈現」
 * - Linode 主機：state/linode.json（cora-lab 的 linode_stats.py 每 5 分鐘產生）某台轉紅燈推「Linode 異常」，回綠／黃推「恢復」；
 *   黃燈不推。檔案超過 20 分鐘(LINODE_STALE_SEC)沒更新推「蒐集中斷：Linode」，中斷期間不判斷各台（熊哥 10/16 裁示用網頁推播，Asana 1219561988862419）。
 * - 資安事件由其他程式呼叫 tools/push_send.php(CCNDA 沒有申請單,熊哥 10/14)。
 */

if (PHP_SAPI !== 'cli') {
    exit;
}
require __DIR__ . '/../lib/webpush.php';
require __DIR__ . '/../lib/uptime.php';
require __DIR__ . '/../lib/agents.php';
require __DIR__ . '/../lib/linode.php';
date_default_timezone_set('Asia/Taipei');

const WATCH_FILE = PUSH_STATE_DIR . '/push_watch.json';
const UPTIME_DOWN_MIN_SEC = 300;
const ZBX_URL = 'http://127.0.0.1:8090/api_jsonrpc.php';
const LINODE_STALE_SEC = 1200;

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

// ---- 數位同工心跳 ----
[$ag] = agents_data();
$feedAge = is_file(AGENTS_FILE) ? time() - (int) filemtime(AGENTS_FILE) : null;
$feed = $feedAge !== null && $feedAge > AGENTS_STALE_SEC ? 'stale' : 'ok';
if ($feedAge !== null && isset($st['agents_feed']) && $feed !== $st['agents_feed']) {
    push_send($feed === 'stale'
        ? ['title' => '蒐集中斷：小芯', 'body' => '同工狀態 ' . (int) round($feedAge / 60) . ' 分鐘沒有更新（最後 '
            . date('H:i', (int) filemtime(AGENTS_FILE)) . '），cora-lab 的蒐集排程可能停了；其他同工暫時判斷不了',
            'url' => '/#agents', 'tag' => 'agents-feed']
        : ['title' => '恢復：同工狀態蒐集', 'body' => '蒐集排程又開始更新了', 'url' => '/#agents', 'tag' => 'agents-feed'], 'agent');
}
if ($feedAge !== null) {
    $st['agents_feed'] = $feed;
}
if (is_array($ag) && $feed === 'ok') {
    $first = !isset($st['agents']);
    $prev = $st['agents'] ?? [];
    $now = [];
    foreach ($ag['rows'] as $r) {
        $v = agent_view($r, time());
        if ($v['agent'] === '' || $v['cls'] === 'paused') {
            continue;   // 尚未部署的不算
        }
        $id = $v['agent'];
        $now[$id] = $v['cls'] === 'bad' ? 'down' : 'up';
        if ($first) {
            continue;
        }
        if ($now[$id] === 'down' && ($prev[$id] ?? 'up') !== 'down') {
            push_send(['title' => '停擺：' . $v['name'], 'body' => '超過 ' . AGENTS_GREEN_MIN . ' 分鐘沒有心跳'
                . (!empty($r['ts']) ? '（最後 ' . date('H:i', (int) strtotime((string) $r['ts'])) . '）' : ''),
                'url' => '/#agents', 'tag' => 'agent-' . $id], 'agent');
        } elseif ($now[$id] === 'up' && ($prev[$id] ?? 'up') === 'down') {
            push_send(['title' => '恢復：' . $v['name'], 'body' => '又有心跳了', 'url' => '/#agents', 'tag' => 'agent-' . $id], 'agent');
        }
    }
    $st['agents'] = $now;
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

// ---- Linode 主機 ----
[$lin] = linode_data();
$linAge = is_array($lin) ? time() - (int) ($lin['at'] ?? 0) : null;
$linFeed = $linAge !== null && $linAge > LINODE_STALE_SEC ? 'stale' : 'ok';
if ($linAge !== null && isset($st['linode_feed']) && $linFeed !== $st['linode_feed']) {
    push_send($linFeed === 'stale'
        ? ['title' => '蒐集中斷：Linode', 'body' => 'Linode 主機數據 ' . (int) round($linAge / 60) . ' 分鐘沒有更新（最後 '
            . date('H:i', (int) $lin['at']) . '），cora-lab 的 linode_stats 排程可能停了', 'url' => '/#linode', 'tag' => 'linode-feed']
        : ['title' => '恢復：Linode 數據', 'body' => 'linode_stats 又開始更新了', 'url' => '/#linode', 'tag' => 'linode-feed'], 'linode');
}
if ($linAge !== null) {
    $st['linode_feed'] = $linFeed;
}
if (is_array($lin) && $linFeed === 'ok') {
    $first = !isset($st['linode']);
    $prev = $st['linode'] ?? [];
    $now = [];
    foreach ($lin['hosts'] as $h) {
        $id = (string) $h['label'];
        $now[$id] = ($h['cls'] ?? '') === 'bad' ? 'down' : 'up';   // 只有紅燈算異常，黃燈不推
        if ($first) {
            continue;
        }
        if ($now[$id] === 'down' && ($prev[$id] ?? 'up') !== 'down') {
            push_send(['title' => 'Linode 異常：' . $id, 'body' => implode('、', $h['why'] ?? []) ?: '紅燈',
                'url' => '/#linode', 'tag' => 'linode-' . $id], 'linode');
        } elseif ($now[$id] === 'up' && ($prev[$id] ?? 'up') === 'down') {
            push_send(['title' => '恢復：' . $id, 'body' => 'Linode 主機恢復正常', 'url' => '/#linode', 'tag' => 'linode-' . $id], 'linode');
        }
    }
    $st['linode'] = $now;
}

$tmp = WATCH_FILE . '.tmp';
file_put_contents($tmp, json_encode($st, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
rename($tmp, WATCH_FILE);
