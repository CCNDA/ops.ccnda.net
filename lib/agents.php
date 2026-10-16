<?php
declare(strict_types=1);

/**
 * 數位同工即時狀態（戰情室卡片；Asana 1219390262350870）。
 *
 * 媽媽 2026-10-11 信（熊哥 TG 按鈕「照 90分／3小時」）：
 *   即時燈號看最後一次心跳距今：≤ 90 分＝正常（綠）、> 90 分＝停擺（紅），門檻比照小甯的存活檢查
 *   （2026-10-14：小布 16:45 起停 5 小時，原本要 3 小時才轉紅、也不推播；熊哥：「要趕緊讓小芯協助監測所有設備」）
 *   （10/12 改 60、10/13 依一晚心跳紀錄改回 90）
 *   第二道沿用早報：heartbeat_check.py 判讀不是 alive（今天 07:00 後沒有排程心跳等）＝標「今日未報到」
 *
 * 資料：state/agents.json —— cora-lab 每 2 分鐘跑共用技能 heartbeat_check.py --json 產生（判讀不另寫一套；10/15 從小芯電腦搬來）。
 *       這裡只在它上面加即時那層。檔案超過 10 分鐘沒更新＝蒐集端可能停了。
 */

const AGENTS_FILE = __DIR__ . '/../state/agents.json';
const AGENTS_GREEN_MIN = 90;  // 熊哥 2026-10-13：「B 都維持90吧」（10/12 曾改 60；每小時一班的同工間隔常在 60–77 分，60 會常亮黃燈）
const AGENTS_STALE_SEC = 600;

/** @return array{0: ?array, 1: string} [資料, 提示] */
// 共用同一個帳號額度的同工（熊哥 2026-10-12：「小布小芯 電腦版放在上下，手機板放在左右」「小薇小霏也是 因為他們現在是同一個帳號額度」）
const AGENTS_SHARED_QUOTA = [['vera', 'faye'], ['bruno', 'cora']];

/** 把同工兩兩分組：共用額度的先排、成對；其他照原順序兩兩一組。電腦上一組上下疊、手機上一組左右並排。 */
function agent_groups(array $agents): array
{
    $byKey = [];
    foreach ($agents as $a) { $byKey[$a['agent']] = $a; }
    $groups = []; $used = [];
    foreach (AGENTS_SHARED_QUOTA as $pair) {
        $items = array_values(array_filter(array_map(fn($k) => $byKey[$k] ?? null, $pair)));
        if (count($items) === 2) {
            $groups[] = ['shared' => true, 'items' => $items];
            foreach ($pair as $k) { $used[$k] = true; }
        }
    }
    $rest = array_values(array_filter($agents, fn($a) => !isset($used[$a['agent']])));
    foreach (array_chunk($rest, 2) as $items) { $groups[] = ['shared' => false, 'items' => $items]; }
    return $groups;
}

function agents_data(): array
{
    if (!is_file(AGENTS_FILE)) {
        return [null, '還沒有同工狀態資料（等 cora-lab 的蒐集排程第一次跑）。'];
    }
    $d = json_decode((string) file_get_contents(AGENTS_FILE), true);
    if (!is_array($d) || !isset($d['rows'])) {
        return [null, '同工狀態資料格式不對。'];
    }
    $age = time() - (int) filemtime(AGENTS_FILE);
    $msg = $age > AGENTS_STALE_SEC
        ? '同工狀態 ' . (int) round($age / 60) . ' 分鐘沒有更新，cora-lab 的蒐集排程可能停了；下面是 ' . date('H:i', (int) filemtime(AGENTS_FILE)) . ' 的資料。'
        : '';
    if (!empty($d['unlisted'])) {
        $msg .= ($msg ? ' ' : '') . '共用硬碟有不在名單裡的心跳：' . implode('、', $d['unlisted']) . '（heartbeat_check.py 的名單要補）。';
    }
    return [$d, $msg];
}

/** 一台同工 → 畫面欄位 */
function agent_view(array $r, int $now): array
{
    $ts = isset($r['ts']) ? strtotime((string) $r['ts']) : false;
    $min = $ts ? (int) floor(($now - $ts) / 60) : null;
    if ($min === null) {
        [$cls, $live] = ['bad', '沒有心跳'];
    } elseif ($min <= AGENTS_GREEN_MIN) {
        [$cls, $live] = ['ok', '正常'];
    } else {
        [$cls, $live] = ['bad', '停擺'];
    }
    if (($r['status'] ?? '') === 'pending') {
        [$cls, $live] = ['paused', '尚未部署'];
    }
    $runtime = (string) ($r['runtime'] ?? 'claude-code');
    $ver = $runtime === 'codex' ? ($r['codex_version'] ?? null) : ($r['claude_version'] ?? null);
    $q = $r['quota_7d'] ?? null;
    $quota = is_array($q) && isset($q['used_pct']) && is_numeric($q['used_pct']) ? (float) $q['used_pct'] : null;
    // 重置時間（熊哥 2026-10-11：「七日額度 沒有重整日 這樣不知道狀態」）：Claude 台是 UTC（…Z）、codex 台帶 +08:00，strtotime 都吃
    $reset = is_array($q) && !empty($q['resets_at']) ? strtotime((string) $q['resets_at']) : false;
    $ago = $min === null ? '' : ($min < 60 ? $min . ' 分鐘前' : intdiv($min, 60) . ' 小時 ' . ($min % 60) . ' 分前');
    return [
        // 顯示用短名：拿掉「CCNDA」「IT」前綴（熊哥 2026-10-11：「大蘇不用加it 這樣比較一致」）。
        // 名單在共用技能 heartbeat_check.py，不改那邊，只在戰情室顯示時處理；完整名稱放 full（title 提示）。
        'agent'   => (string) ($r['agent'] ?? ''),
        'name'    => preg_replace('/^(CCNDA|IT)/u', '', (string) ($r['name'] ?? $r['agent'] ?? '?')),
        'full'    => (string) ($r['name'] ?? $r['agent'] ?? '?'),
        'cls'     => $cls,
        'live'    => $live,
        'when'    => $ts ? date('H:i', $ts) : '—',
        'ago'     => $ago,
        'runtime' => $runtime,
        'ver'     => $ver !== null ? (string) $ver : null,
        'behind'  => ($r['version_state'] ?? '') === 'behind',
        'quota'   => $quota,
        'reset'   => $reset ?: null,
        'note'    => (string) ($r['note'] ?? ''),
        // 只看即時心跳燈號（熊哥 2026-10-12：「戰情室應該不用算今日報到 因為都需要90分鐘內報到」）
        'problem' => $cls !== 'ok',
    ];
}
