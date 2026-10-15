<?php
declare(strict_types=1);

/**
 * 設備管理表（戰情室設備監控區塊；Asana 1219442962700669）。
 * 熊哥 2026-10-13：「CCNDA 設備管理表」整份（含 Linode 主機）做進戰情室。
 * 資料：cora-lab 的排程 devices_push.py（~/.config/gws）每小時用服務帳號讀共用硬碟的試算表，
 *       傳成 state/devices_table.json（只有表格內容，沒有憑證）。
 */

const DEVICES_FILE = __DIR__ . '/../state/devices_table.json';

/** @return array{0: ?array, 1: string} */
function devices_data(): array
{
    if (!is_file(DEVICES_FILE)) {
        return [null, '還沒有設備資料（等 cora-lab 的排程第一次跑）。'];
    }
    $d = json_decode((string) file_get_contents(DEVICES_FILE), true);
    if (!is_array($d) || !isset($d['rows']) || !is_array($d['rows'])) {
        return [null, '設備資料格式不對。'];
    }
    $age = time() - (int) filemtime(DEVICES_FILE);
    return [$d, $age > 3 * 3600 ? '設備資料超過 3 小時沒有更新（cora-lab 的排程可能停了）。' : ''];
}

/** 依「類別」分組，保留表格原本的順序 */
function devices_grouped(array $rows): array
{
    $g = [];
    foreach ($rows as $r) {
        $g[(string) ($r['類別'] ?? '其他')][] = $r;
    }
    return $g;
}

/** 「狀態與備註」第一段（｜之前）當狀態；🔴／🟡／待補 給顏色 */
function device_status(array $r): array
{
    $raw = (string) ($r['狀態與備註'] ?? '');
    $first = trim(explode('｜', $raw)[0]);
    $cls = 'ok';
    if (str_contains($raw, '🔴') || str_contains($first, '待補')) {
        $cls = 'bad';
    } elseif (str_contains($raw, '🟡') || str_contains($first, '待命')) {
        $cls = 'warn';
    }
    return [$first !== '' ? $first : '—', $cls, $raw];
}
