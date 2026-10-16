<?php
declare(strict_types=1);

/**
 * 網站流量（GA4＋Search Console；戰情室，Asana 1219379138644448）。
 * 資料：cora-lab 的 tools/ga_stats.py 每 15 分鐘讀 GA4／Search Console，寫 state/ga.json。頁面只讀這個檔。
 * 熊哥 2026-10-11：「GA 之後戰情室開發會用到」；10/16 准 cora-lab 加 GA／Search Console 兩項唯讀。
 */

const GA_FILE = __DIR__ . '/../state/ga.json';

/** @return array{0: ?array, 1: string} */
function ga_data(): array
{
    if (!is_file(GA_FILE)) {
        return [null, '還沒有網站流量資料（等 cora-lab 排程第一次跑）。'];
    }
    $d = json_decode((string) file_get_contents(GA_FILE), true);
    if (!is_array($d) || !isset($d['sites']) || !is_array($d['sites'])) {
        return [null, '網站流量資料格式不對。'];
    }
    $age = time() - (int) ($d['at'] ?? 0);
    return [$d, $age > 45 * 60 ? '網站流量資料超過 45 分鐘沒有更新（cora-lab 的排程可能停了），下面是舊數字。' : ''];
}

/** 有流量的站（過去 7 天有使用者，或現在有人在線上）放表格；其他收在「沒有流量」一行 */
function ga_split(array $sites): array
{
    $live = $quiet = [];
    foreach ($sites as $s) {
        $busy = ($s['week']['users'] ?? 0) > 0 || ($s['realtime'] ?? 0) > 0 || isset($s['err']);
        $busy ? $live[] = $s : $quiet[] = $s;
    }
    return [$live, $quiet];
}

function ga_num(mixed $v): string
{
    return $v === null ? '—' : number_format((int) $v);
}
