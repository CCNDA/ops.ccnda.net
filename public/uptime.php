<?php
declare(strict_types=1);
/**
 * 戰情室：網站監控詳情（UptimeRobot）。首頁摘要卡「點這裡看詳情 →」進來。
 * 熊哥 2026-10-11：「不用全部展開，有問題在展開就好」→ 有問題的展開成卡片，正常的收合成小方塊。
 */

require __DIR__ . '/../lib/page.php';
require __DIR__ . '/../lib/uptime.php';

[$up, $upMsg] = uptime_data();
page_head('網站監控｜CCNDA 戰情室', 'UptimeRobot（從外部檢查）・ ' . date('Y-m-d H:i'));
?>
  <p><a href="/">← 回戰情室</a></p>
  <h2>網站監控</h2>
<?php if ($upMsg !== ''): ?>  <div class="notice"><?= h($upMsg) ?></div>
<?php endif; ?>
<?php if ($up):
    $mons = array_map('uptime_view', $up['monitors']);
    $rank = ['bad' => 0, 'warn' => 1, 'wait' => 2, 'paused' => 3, 'ok' => 4];
    usort($mons, fn($a, $b) => [$rank[$a['cls']] ?? 9, $a['name']] <=> [$rank[$b['cls']] ?? 9, $b['name']]); // 中斷的排最前面
    // 有問題＝中斷／疑似中斷／尚未檢查，或 24 小時可用率 < 99%：直接展開成完整卡片；其他收合成小方塊，點了才展開
    $isProblem = fn($m) => in_array($m['cls'], ['bad', 'warn', 'wait'], true) || ($m['cls'] === 'ok' && $m['d1'] !== null && $m['d1'] < 99);
    $detail = function (array $m): string {
        $out = '';
        foreach (['d1' => '24 小時', 'd7' => '7 天', 'd30' => '30 天'] as $k => $lbl) {
            if ($m[$k] !== null && $m['cls'] !== 'paused') {
                $out .= '<span>' . $lbl . ' <b class="' . ($m[$k] < 99 ? 'low' : '') . '">' . number_format($m[$k], 2) . '%</b></span>';
            }
        }
        if ($m['ms'] !== null) { $out .= '<span>平均回應 <b>' . $m['ms'] . '</b> ms</span>'; }
        if ($m['down']) {
            $fmt = date('Y', $m['down']['at']) === date('Y') ? 'm-d H:i' : 'Y-m-d H:i';
            $out .= '<span>上次中斷 ' . h(date($fmt, $m['down']['at'])) . '（' . max(1, (int) round($m['down']['sec'] / 60)) . ' 分鐘）</span>';
        }
        if (preg_match('#^https?://#', $m['url'])) {
            $out .= '<span><a href="' . h($m['url']) . '" target="_blank" rel="noopener">開啟網站 ↗</a></span>';
        }
        return $out;
    };
    $problems = array_values(array_filter($mons, $isProblem));
    $fine = array_values(array_filter($mons, fn($m) => !$isProblem($m)));
?>
  <div class="sub" style="margin:0 0 8px">共 <?= count($mons) ?> 個監控（正常的收起來，點名稱可展開），正常 <?= count(array_filter($mons, fn($m) => $m['cls'] === 'ok')) ?> 個 ・ 資料時間 <?= h(date('H:i:s', (int) $up['at'])) ?></div>
<?php foreach ($problems as $m): ?>
  <div class="card">
    <span class="dot <?= h($m['cls']) ?>" title="<?= h($m['label']) ?>"></span>
    <span class="name"><?= h($m['name']) ?>　<span class="sub"><?= h($m['label']) ?></span></span>
    <div class="nums meta"><?= $detail($m) ?></div>
  </div>
<?php endforeach; ?>
<?php if ($fine): ?>
  <div class="chips">
<?php foreach ($fine as $m): ?>
    <details class="chip">
      <summary title="<?= h($m['name']) ?>"><span class="dot <?= h($m['cls']) ?>"></span><span class="nm"><?= h($m['name']) ?></span></summary>
      <div class="nums meta"><?= $m['cls'] === 'paused' ? '<span>暫停監控</span>' : '' ?><?= $detail($m) ?></div>
    </details>
<?php endforeach; ?>
  </div>
<?php endif; ?>
<?php endif; ?>
<?php page_foot();
