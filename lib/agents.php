<?php
declare(strict_types=1);

/**
 * 數位同工即時狀態（戰情室卡片；Asana 1219390262350870）。
 *
 * 媽媽 2026-10-11 信（熊哥 TG 按鈕「照 90分／3小時」）：
 *   即時燈號看最後一次心跳距今：≤ 90 分＝正常（綠）、90 分–3 小時＝延遲（黃）、> 3 小時＝離線（紅）
 *   第二道沿用早報：heartbeat_check.py 判讀不是 alive（今天 07:00 後沒有排程心跳等）＝標「今日未報到」
 *
 * 資料：state/agents.json —— 小芯電腦每 2 分鐘跑共用技能 heartbeat_check.py --json 傳上來（判讀不另寫一套）。
 *       這裡只在它上面加即時那層。檔案超過 10 分鐘沒更新＝蒐集端可能停了。
 */

const AGENTS_FILE = __DIR__ . '/../state/agents.json';
const AGENTS_GREEN_MIN = 90;
const AGENTS_YELLOW_MIN = 180;
const AGENTS_STALE_SEC = 600;

/** @return array{0: ?array, 1: string} [資料, 提示] */
function agents_data(): array
{
    if (!is_file(AGENTS_FILE)) {
        return [null, '還沒有同工狀態資料（等小芯電腦的蒐集程式第一次傳上來）。'];
    }
    $d = json_decode((string) file_get_contents(AGENTS_FILE), true);
    if (!is_array($d) || !isset($d['rows'])) {
        return [null, '同工狀態資料格式不對。'];
    }
    $age = time() - (int) filemtime(AGENTS_FILE);
    $msg = $age > AGENTS_STALE_SEC
        ? '同工狀態 ' . (int) round($age / 60) . ' 分鐘沒有更新，小芯電腦的蒐集程式可能停了；下面是 ' . date('H:i', (int) filemtime(AGENTS_FILE)) . ' 的資料。'
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
    } elseif ($min <= AGENTS_YELLOW_MIN) {
        [$cls, $live] = ['warn', '延遲'];
    } else {
        [$cls, $live] = ['bad', '離線'];
    }
    if (($r['status'] ?? '') === 'pending') {
        [$cls, $live] = ['paused', '尚未部署'];
    }
    $runtime = (string) ($r['runtime'] ?? 'claude-code');
    $ver = $runtime === 'codex' ? ($r['codex_version'] ?? null) : ($r['claude_version'] ?? null);
    $q = $r['quota_7d'] ?? null;
    $quota = is_array($q) && isset($q['used_pct']) && is_numeric($q['used_pct']) ? (float) $q['used_pct'] : null;
    $ago = $min === null ? '' : ($min < 60 ? $min . ' 分鐘前' : intdiv($min, 60) . ' 小時 ' . ($min % 60) . ' 分前');
    return [
        'name'    => (string) ($r['name'] ?? $r['agent'] ?? '?'),
        'cls'     => $cls,
        'live'    => $live,
        'daily'   => ($r['status'] ?? '') === 'alive' ? null : (string) ($r['label'] ?? '今日未報到'),
        'when'    => $ts ? date('H:i', $ts) : '—',
        'ago'     => $ago,
        'runtime' => $runtime,
        'ver'     => $ver !== null ? (string) $ver : null,
        'behind'  => ($r['version_state'] ?? '') === 'behind',
        'quota'   => $quota,
        'note'    => (string) ($r['note'] ?? ''),
        'problem' => $cls !== 'ok' || ($r['status'] ?? '') !== 'alive',
    ];
}
