<?php
declare(strict_types=1);

/**
 * Linode 主機監控（戰情室；Asana 1219409196970109）。
 * 資料：cora-lab 的 tools/linode_stats.py 每 5 分鐘用唯讀權杖讀 Linode API，寫 state/linode.json。
 * 頁面只讀這個檔，不碰權杖。燈號由 linode_stats.py 判（門檻寫在那支的說明）。
 */

const LINODE_FILE = __DIR__ . '/../state/linode.json';

/** @return array{0: ?array, 1: string} */
function linode_data(): array
{
    if (!is_file(LINODE_FILE)) {
        return [null, '還沒有 Linode 資料（等 cora-lab 排程第一次跑）。'];
    }
    $d = json_decode((string) file_get_contents(LINODE_FILE), true);
    if (!is_array($d) || !isset($d['hosts']) || !is_array($d['hosts'])) {
        return [null, 'Linode 資料格式不對。'];
    }
    $age = time() - (int) ($d['at'] ?? 0);
    return [$d, $age > 20 * 60 ? 'Linode 資料超過 20 分鐘沒有更新（cora-lab 的排程可能停了），下面是舊數字。' : ''];
}

/** 數字顯示：null 顯示「—」 */
function linode_num(mixed $v, string $unit = ''): string
{
    return $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 1, '.', ''), '0'), '.') . $unit;
}
