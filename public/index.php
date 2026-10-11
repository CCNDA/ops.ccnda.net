<?php
declare(strict_types=1);
/**
 * CCNDA 戰情室 ops.ccnda.net —— 第一版：從這裡點進內網的測試站。
 * 熊哥 2026-10-11 TG：「先做好戰情室，都透過 zero trust 聯結看內網的測試站」「由戰情室可以點選觀看測試站」。
 *
 * 只在 cora-lab 本機（nginx 127.0.0.1）跑，對外經 Cloudflare Tunnel，登入由 Cloudflare Access
 * （Google Workspace，限 @ccnda.org）把關。設備監控之後加（Asana 1219371344401545）。
 * 網站監控：UptimeRobot（lib/uptime.php，唯讀金鑰在 state/，快取 60 秒）。
 */

require __DIR__ . '/../lib/uptime.php';
date_default_timezone_set('Asia/Taipei'); // cora-lab 主機是 UTC，畫面一律台北時間

// 測試站清單：新增一站就加一筆。check 是 cora-lab 本機的健康檢查網址（不經網際網路）。
$TEST_SITES = [
    [
        'name'  => '屬靈共同書目（測試站）',
        'url'   => 'https://books.ps194.net/',
        'prod'  => 'https://books.zh.church/',
        'check' => 'http://127.0.0.1:8081/api/version',
        'note'  => 'cora-lab：/srv/dev/books.zh.church，資料庫 books_dev（正式庫的複本）',
    ],
];

function site_status(string $url): array
{
    $ctx = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]);
    $t = microtime(true);
    $body = @file_get_contents($url, false, $ctx);
    $ms = (int) round((microtime(true) - $t) * 1000);
    $code = 0;
    foreach ($http_response_header ?? [] as $hdr) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $hdr, $m)) {
            $code = (int) $m[1];
        }
    }
    $ver = null;
    if ($body !== false && ($j = json_decode($body, true)) && isset($j['data']['version'])) {
        $ver = (string) $j['data']['version'];
    }
    return ['ok' => $code >= 200 && $code < 400, 'code' => $code, 'ms' => $ms, 'version' => $ver];
}

$h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$checkedAt = date('Y-m-d H:i:s');
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>CCNDA 戰情室</title>
<style>
:root{--bg:#f4f7fa;--card:#fff;--ink:#14303c;--soft:#5c7482;--line:#e2e8ee;--ok:#1a7f4b;--bad:#b42318;--link:#0e6d94}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font-family:"Noto Sans TC","Microsoft JhengHei",sans-serif;line-height:1.7}
header{background:var(--card);border-bottom:1px solid var(--line)}
.wrap{max-width:960px;margin:0 auto;padding:16px 20px}
h1{margin:0;font-size:1.3rem;letter-spacing:.08em}
.sub{color:var(--soft);font-size:.85rem}
h2{font-size:1.05rem;margin:28px 0 10px}
.card{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:14px 18px;margin:0 0 10px;
  display:flex;gap:14px;align-items:center;flex-wrap:wrap}
.dot{width:12px;height:12px;border-radius:50%;flex:0 0 auto}
.dot.ok{background:var(--ok)}.dot.bad{background:var(--bad)}
.name{font-weight:700;flex:1 1 14em}
.meta{color:var(--soft);font-size:.82rem;flex:1 1 100%}
a{color:var(--link);text-decoration:none}
.btn{border:1px solid var(--line);border-radius:999px;padding:4px 14px;font-size:.9rem}
.todo{color:var(--soft);background:var(--card);border:1px dashed var(--line);border-radius:10px;padding:14px 18px}
.dot.warn{background:#d97706}.dot.paused,.dot.wait{background:#9aa9b3}
.nums{display:flex;gap:16px;flex-wrap:wrap;font-variant-numeric:tabular-nums;font-size:.9rem}
.nums b{font-weight:600}.low{color:var(--bad)}
.notice{color:#8a5a00;background:#fff7e6;border:1px solid #f5d9a8;border-radius:10px;padding:10px 16px;margin:0 0 10px}
</style>
</head>
<body>
<header><div class="wrap">
  <h1>CCNDA 戰情室</h1>
  <div class="sub">內部使用 ・ 狀態在 <?= $h($checkedAt) ?> 從 cora-lab 檢查</div>
</div></header>
<main class="wrap">
  <h2>測試站</h2>
<?php foreach ($TEST_SITES as $s): $st = site_status($s['check']); ?>
  <div class="card">
    <span class="dot <?= $st['ok'] ? 'ok' : 'bad' ?>" title="<?= $st['ok'] ? '正常' : '連不到' ?>"></span>
    <span class="name"><?= $h($s['name']) ?><?= $st['version'] ? '　<span class="sub">v' . $h($st['version']) . '</span>' : '' ?></span>
    <a class="btn" href="<?= $h($s['url']) ?>" target="_blank" rel="noopener">開啟測試站</a>
    <a class="btn" href="<?= $h($s['prod']) ?>" target="_blank" rel="noopener">對照正式站</a>
    <div class="meta"><?= $st['ok'] ? '正常' : '⚠️ 連不到' ?>（HTTP <?= $st['code'] ?: '—' ?>，<?= $st['ms'] ?> ms）・ <?= $h($s['note']) ?></div>
  </div>
<?php endforeach; ?>

  <h2>網站監控 <span class="sub">UptimeRobot（從外部檢查）</span></h2>
<?php [$up, $upMsg] = uptime_data(); ?>
<?php if ($upMsg !== ''): ?>  <div class="notice"><?= $h($upMsg) ?></div>
<?php endif; ?>
<?php if ($up): $mons = array_map('uptime_view', $up['monitors']);
      $rank = ['bad' => 0, 'warn' => 1, 'wait' => 2, 'paused' => 3, 'ok' => 4];
      usort($mons, fn($a, $b) => [$rank[$a['cls']] ?? 9, $a['name']] <=> [$rank[$b['cls']] ?? 9, $b['name']]); // 中斷的排最前面 ?>
  <div class="sub" style="margin:0 0 8px">共 <?= count($mons) ?> 個監控，正常 <?= count(array_filter($mons, fn($m) => $m['cls'] === 'ok')) ?> 個 ・ 資料時間 <?= $h(date('H:i:s', (int) $up['at'])) ?></div>
<?php foreach ($mons as $m): ?>
  <div class="card">
    <span class="dot <?= $h($m['cls']) ?>" title="<?= $h($m['label']) ?>"></span>
    <span class="name"><?= $h($m['name']) ?>　<span class="sub"><?= $h($m['label']) ?></span></span>
    <?php if (preg_match('#^https?://#', $m['url'])): ?><a class="btn" href="<?= $h($m['url']) ?>" target="_blank" rel="noopener">開啟</a><?php endif; ?>
    <div class="nums meta">
      <?php foreach (['d1' => '24 小時', 'd7' => '7 天', 'd30' => '30 天'] as $k => $lbl): if ($m[$k] !== null && $m['cls'] !== 'paused'): ?>
        <span><?= $lbl ?> <b class="<?= $m[$k] < 99 ? 'low' : '' ?>"><?= number_format($m[$k], 2) ?>%</b></span>
      <?php endif; endforeach; ?>
      <?php if ($m['ms'] !== null): ?><span>平均回應 <b><?= $m['ms'] ?></b> ms</span><?php endif; ?>
      <?php if ($m['down']): ?><span>上次中斷 <?= $h(date('m-d H:i', $m['down']['at'])) ?>（<?= max(1, (int) round($m['down']['sec'] / 60)) ?> 分鐘）</span><?php endif; ?>
    </div>
  </div>
<?php endforeach; endif; ?>

  <h2>設備監控</h2>
  <div class="todo">建置中：Zabbix，設備清單等熊叔叔確認。</div>
</main>
</body>
</html>
