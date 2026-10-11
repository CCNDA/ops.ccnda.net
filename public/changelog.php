<?php
declare(strict_types=1);
/**
 * 戰情室：版本說明（讀 repo 根目錄的 CHANGELOG.md）。
 * 熊哥 2026-10-11 TG：「戰情網也要有協會logo 版本序號 版本說明」。
 * 只認「## [x.y.z] - 日期 —— 標題」和底下的「- 」條列，其他一律當純文字 escape。
 */

require __DIR__ . '/../lib/page.php';

$releases = [];
$cur = null;
// ★ 不能用 preg_split('/\R/')（沒有 /u）：\R 也比對 0x85，會把中文字的 UTF-8 從中間切斷（2026-10-11 實測少了兩個版本）
foreach (explode("
", str_replace("", '', (string) @file_get_contents(__DIR__ . '/../CHANGELOG.md'))) as $line) {
    if (preg_match('/^## \[(\d+\.\d+\.\d+)\] - (\d{4}-\d{2}-\d{2}) —— (.+)$/u', $line, $m)) {
        $releases[] = ['ver' => $m[1], 'date' => $m[2], 'title' => trim($m[3]), 'items' => []];
        $cur = count($releases) - 1;
    } elseif ($cur !== null && preg_match('/^- (.+)$/u', $line, $m)) {
        $releases[$cur]['items'][] = $m[1];
    } elseif (preg_match('/^## /', $line)) {
        $cur = null;
    }
}

page_head('版本說明｜CCNDA 戰情室', '目前版本 v' . app_version());
?>
  <p><a href="/">← 回戰情室</a></p>
  <h2>版本說明</h2>
<?php if (!$releases): ?><p class="todo">暫時讀不到版本紀錄。</p><?php endif; ?>
<?php foreach ($releases as $i => $r): ?>
  <section class="panel">
    <div class="panel-hd">
      <h2 style="font-size:1rem">v<?= h($r['ver']) ?>　<?= h($r['title']) ?></h2>
      <span class="sub"><?= h($r['date']) ?><?= $i === 0 ? ' ・ 目前版本' : '' ?></span>
    </div>
    <ul style="margin:0;padding-left:1.3em">
      <?php foreach ($r['items'] as $it): ?><li><?= h($it) ?></li><?php endforeach; ?>
    </ul>
  </section>
<?php endforeach; ?>
<?php page_foot();
