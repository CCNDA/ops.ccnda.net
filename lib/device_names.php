<?php
declare(strict_types=1);

/**
 * 設備監控的顯示名稱與分組（熊哥 10/16「資訊呈現太亂」）。只影響戰情室怎麼顯示，不改 Zabbix 的主機名。
 * 依 IP 對照；沒有 IP 的（agent 主動回報）依 Zabbix 主機名對照。對不到的放「其他」、用 Zabbix 原名。
 * 熊哥統一命名後（Asana 1219566590937717）這張表跟著改。
 */

const DEVICE_GROUPS = ['宿主機', '同工的電腦', '伺服器', '網路與儲存', '其他'];

const DEVICE_NAMES = [
    // IP => [分組, 顯示名稱, 補充說明]
    '192.168.194.18'  => ['宿主機', 'LOVE', '4 樓伺服器；小布、小鹿的 VM 在上面'],
    '192.168.193.15'  => ['宿主機', 'FAITH', '5 樓伺服器；小薇、FaithBettle 的 VM 在上面'],
    '192.168.193.18'  => ['宿主機', 'HOPE', '5 樓伺服器；小芯的 VM 在上面'],
    '192.168.194.19'  => ['其他', 'WHOST02', '熊哥 10/16：宿主機只有 FAITH、HOPE、LOVE 三台；WHOST02 是什麼待確認'],
    '192.168.193.40'  => ['同工的電腦', '小秘＋小甯', 'BLACK-DOVE，實體機'],
    '192.168.193.17'  => ['同工的電腦', '小薇', 'FAITH-VERA，在 FAITH 上'],
    '192.168.194.20'  => ['同工的電腦', '小布', 'Black02，在 LOVE 上'],
    '192.168.194.21'  => ['同工的電腦', '小鹿', 'Ubuntu VM，在 LOVE 上'],
    'cora-pc'         => ['同工的電腦', '小芯', 'Hope-Cora，192.168.193.19，在 HOPE 上'],
    'cora-lab'        => ['伺服器', 'cora-lab', '測試機＋Zabbix 伺服器＋戰情室'],
    '192.168.193.16'  => ['伺服器', 'FaithBettle', 'FAITH 上的 VM'],
    '192.168.193.1'   => ['網路與儲存', '防火牆／閘道', '5 樓'],
    '192.168.193.230' => ['網路與儲存', 'NAS', '共用檔案（DD-code 等）'],
    '192.168.193.232' => ['網路與儲存', 'NAS 716Plus', 'CCNDA716Plus'],
    '192.168.193.240' => ['網路與儲存', 'NAS OC', 'OC-CCNDA-NAS'],
    '192.168.193.54'  => ['網路與儲存', '印表機', 'EPSON45D841'],
];

/** 依分組排好：['宿主機' => [主機…], …]；每台加上 label、note、group */
function device_grouped(array $hosts): array
{
    $out = array_fill_keys(DEVICE_GROUPS, []);
    foreach ($hosts as $h) {
        $key = $h['ip'] !== '' ? $h['ip'] : null;
        if ($key === null) {
            foreach (array_keys(DEVICE_NAMES) as $k) {
                if (!str_contains($k, '.') && str_contains($h['name'], $k)) { $key = $k; break; }
            }
        }
        [$g, $label, $note] = DEVICE_NAMES[$key ?? ''] ?? ['其他', $h['name'], ''];
        $out[$g][] = $h + ['label' => $label, 'note' => $note];
    }
    foreach ($out as &$list) {   // 有狀況的排前面，其餘照名稱
        usort($list, fn($a, $b) => [$a['cls'] === 'ok', $a['label']] <=> [$b['cls'] === 'ok', $b['label']]);
    }
    return array_filter($out);
}

/** 問題講白話：Windows: "X" (Name) is not running … → 「Name」服務沒在跑 */
function device_problem_text(string $name): string
{
    if (preg_match('/^Windows: "[^"]+" \((.+)\) is not running/u', $name, $m)) {
        return '「' . trim((string) preg_replace('/\s*\([^()]*\)$/u', '', $m[1])) . '」服務沒在跑';   // 拿掉名稱後面括號裡的內部代號
    }
    if (preg_match('/Unavailable by ICMP ping|is not available/i', $name)) {
        return '連不上';
    }
    return $name;
}
