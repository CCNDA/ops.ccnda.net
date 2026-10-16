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

/** 大數字縮寫：12,345 → 1.2 萬；1,234,567 → 123.5 萬；1 萬以下照原樣加逗號 */
function ga_compact(?int $n): string
{
    if ($n === null) {
        return '—';
    }
    return $n >= 10000 ? rtrim(rtrim(number_format($n / 10000, 1, '.', ''), '0'), '.') . ' 萬' : number_format($n);
}

/** 比前 7 天的增減：['dir' => up|down|flat, 'text' => '▲ 3.2%'] 或 null（沒有可比的數字） */
function ga_delta(?int $now, ?int $prev): ?array
{
    if ($now === null || !$prev) {
        return null;
    }
    $p = ($now - $prev) / $prev * 100;
    if (abs($p) < 0.5) {
        return ['dir' => 'flat', 'text' => '持平', 'word' => '持平'];
    }
    $t = number_format(abs($p), abs($p) >= 10 ? 0 : 1) . '%';
    return $p > 0 ? ['dir' => 'up', 'text' => '▲ ' . $t, 'word' => '增加 ' . $t] : ['dir' => 'down', 'text' => '▼ ' . $t, 'word' => '減少 ' . $t];
}

/**
 * 30 天趨勢線（單一系列，不用圖例）：前 23 天淡色 #4799cd、最近 7 天強調色 #0e6d94（dataviz 驗證 5/5 通過，白底對比都 ≥ 3:1）。
 * y 軸從 0 開始，不放大小幅波動。滑過或用鍵盤左右鍵可看每天的數字（public/assets/spark.js）。
 */
function ga_spark(array $daily, string $name): string
{
    if (count($daily) < 2) {
        return '';
    }
    $w = 240; $hgt = 44; $pad = 3;
    $vals = array_map(fn($d) => (int) $d[1], $daily);
    $max = max(1, max($vals));
    $n = count($vals);
    $pt = fn($i) => round($pad + $i * ($w - 2 * $pad) / ($n - 1), 1) . ',' . round($hgt - $pad - $vals[$i] / $max * ($hgt - 2 * $pad), 1);
    $split = max(0, $n - 7);
    $old = implode(' ', array_map($pt, range(0, $split)));
    $new = implode(' ', array_map($pt, range($split, $n - 1)));
    $hi = array_search(max($vals), $vals, true); $lo = array_search(min($vals), $vals, true);
    $md = fn($i) => (int) substr($daily[$i][0], 4, 2) . '/' . (int) substr($daily[$i][0], 6, 2);
    $label = $name . '過去 30 天每日使用者：最高 ' . number_format($vals[$hi]) . '（' . $md($hi) . '），最低 '
        . number_format($vals[$lo]) . '（' . $md($lo) . '），昨天 ' . number_format($vals[$n - 1]) . '。用左右鍵看每一天';
    $data = htmlspecialchars(json_encode(array_map(fn($d) => [$d[0], (int) $d[1]], $daily)), ENT_QUOTES);
    return '<div class="spark-wrap"><svg class="spark" viewBox="0 0 ' . $w . ' ' . $hgt . '" preserveAspectRatio="none" role="img" tabindex="0"'
        . ' aria-label="' . h($label) . '" data-days="' . $data . '" data-w="' . $w . '" data-h="' . $hgt . '" data-pad="' . $pad . '" data-max="' . $max . '">'
        . '<polyline class="spark-old" points="' . $old . '"/><polyline class="spark-new" points="' . $new . '"/>'
        . '<line class="spark-x" x1="0" y1="0" x2="0" y2="' . $hgt . '" visibility="hidden"/>'
        . '<circle class="spark-dot" r="3" cx="0" cy="0" visibility="hidden"/></svg>'
        . '<div class="spark-tip" role="status" aria-live="polite"></div></div>';
}
