<?php
declare(strict_types=1);
/**
 * CCNDA 戰情室 ops.ccnda.net —— 首頁：摘要卡（任務、網站監控、設備監控）＋測試站。
 * 熊哥 2026-10-11 TG：「先做好戰情室，都透過 zero trust 聯結看內網的測試站」；
 *   20:54「我要類似這樣 任務要讀 asana」（大蘇 it.ccra.tw/ops 的摘要卡版面）；「不用全部展開，有問題在展開就好」。
 *
 * 只在 cora-lab 本機（nginx 127.0.0.1）跑，對外經 Cloudflare Tunnel，登入由 Cloudflare Access
 * （Google Workspace，限 @ccnda.org）把關。Asana 1219371344401545、1219390021775751。
 * - 任務：tools/asana_summary.py 每小時寫 state/asana_summary.json，這裡只讀檔（不碰權杖）
 * - 網站監控：lib/uptime.php（UptimeRobot 唯讀金鑰在 state/，快取 60 秒）；詳情在 uptime.php
 * - 數位同工：lib/agents.php（小芯電腦每 2 分鐘傳 state/agents.json；90 分／3 小時燈號）
 * - 頁面每 60 秒自動重新整理（媽媽 2026-10-11 信：頁面每分鐘自動刷新）
 */

require __DIR__ . '/../lib/page.php';
require __DIR__ . '/../lib/uptime.php';
require __DIR__ . '/../lib/agents.php';

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

// ── 任務（Asana）──
const TASK_STATS = [
    'today_due'  => '今日到期',
    'today_done' => '今日完成',
    'month_due'  => '當月到期',
    'month_done' => '當月完成',
    'overdue'    => '逾期',
];
$tasks = json_decode((string) @file_get_contents(__DIR__ . '/../state/asana_summary.json'), true);
$list = (string) ($_GET['list'] ?? '');
if (!isset(TASK_STATS[$list])) {
    $list = '';
}
$taskStale = is_array($tasks) && time() - (int) $tasks['at'] > 3 * 3600; // 超過 3 小時沒更新＝排程可能停了

// ── 網站監控摘要 ──
[$up, $upMsg] = uptime_data();
$mons = $up ? array_map('uptime_view', $up['monitors']) : [];
$cnt = ['ok' => 0, 'down' => 0, 'paused' => 0];
foreach ($mons as $m) {
    $cnt[$m['cls'] === 'ok' ? 'ok' : ($m['cls'] === 'paused' ? 'paused' : 'down')]++;
}
$upCls = !$up ? 'wait' : ($cnt['down'] > 0 ? 'bad' : 'ok');

// ── 數位同工 ──
[$ag, $agMsg] = agents_data();
$agents = $ag ? array_map(fn($r) => agent_view($r, time()), $ag['rows']) : [];
$agCnt = ['ok' => 0, 'warn' => 0, 'bad' => 0];
foreach ($agents as $a) { if (isset($agCnt[$a['cls']])) { $agCnt[$a['cls']]++; } }
$agCls = !$ag ? 'wait' : ($agCnt['bad'] ? 'bad' : ($agCnt['warn'] || array_filter($agents, fn($a) => $a['daily'] !== null) ? 'warn' : 'ok'));

page_head('CCNDA 戰情室', '內部使用 ・ ' . date('Y-m-d H:i') . ' 從 cora-lab 檢查 ・ 每分鐘自動更新', 60);
?>
  <section class="panel<?= is_array($tasks) && $tasks['counts']['overdue'] > 0 ? '' : '' ?>" id="tasks">
    <div class="panel-hd">
      <h2>📋 任務（Asana）</h2>
      <?php if (is_array($tasks)): ?>
        <span class="sub">更新於 <?= h(date('H:i', (int) $tasks['at'])) ?> ・ 每小時更新 ・ <a href="https://app.asana.com/" target="_blank" rel="noopener">開啟 Asana</a></span>
      <?php endif; ?>
    </div>
<?php if (!is_array($tasks)): ?>
    <p class="todo">還沒有任務資料（等 tools/asana_summary.py 第一次執行）。</p>
<?php else: ?>
    <?php if ($taskStale): ?><div class="notice">任務資料超過 3 小時沒有更新，排程可能停了。</div><?php endif; ?>
    <div class="stats">
      <?php foreach (TASK_STATS as $k => $label): ?>
        <a class="stat<?= $k === 'overdue' && $tasks['counts'][$k] > 0 ? ' red' : '' ?><?= $list === $k ? ' on' : '' ?>"
           href="<?= $list === $k ? '/' : '?list=' . $k ?>#tasks"><b><?= (int) $tasks['counts'][$k] ?></b><span><?= h($label) ?></span></a>
      <?php endforeach; ?>
    </div>
    <p class="note">點上面的數字看清單。未完成共 <?= (int) $tasks['open_total'] ?> 件，其中 <?= (int) $tasks['open_no_due'] ?> 件沒有設到期日。</p>
    <?php if ($list !== ''): $items = $tasks['lists'][$list] ?? []; ?>
      <ul class="list">
        <?php foreach ($items as $t): ?>
          <li>
            <span class="t"><?= preg_match('#^https://app\.asana\.com/#', (string) $t['url']) ? '<a href="' . h($t['url']) . '" target="_blank" rel="noopener">' . h($t['name']) . '</a>' : h($t['name']) ?></span>
            <span class="m"><?= h($t['who'] ?? '未指派') ?></span>
            <?php if (in_array($list, ['today_done', 'month_done'], true)): ?>
              <span class="m">完成 <?= $t['done_at'] ? h(date('m-d H:i', strtotime($t['done_at']))) : '' ?></span>
            <?php else: ?>
              <span class="m<?= $t['due'] && $t['due'] < $tasks['today'] ? ' late' : '' ?>">到期 <?= h($t['due'] ?? '—') ?></span>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
        <?php if (!$items): ?><li><span class="t sub">沒有</span></li><?php endif; ?>
      </ul>
    <?php endif; ?>
<?php endif; ?>
  </section>

  <section class="panel<?= $agCls === 'bad' ? ' alert' : '' ?>" id="agents">
    <div class="panel-hd">
      <span class="dot big <?= h($agCls) ?>"></span>
      <h2>數位同工</h2>
      <span class="sub"><?= $ag ? '更新於 ' . h(date('H:i', (int) filemtime(AGENTS_FILE))) . ' ・ 90 分鐘內有心跳＝正常、3 小時內＝延遲' : '' ?></span>
    </div>
    <?php if ($agMsg !== ''): ?><div class="notice"><?= h($agMsg) ?></div><?php endif; ?>
    <?php if ($ag): ?>
    <div class="inline">
      <span><b><?= $agCnt['ok'] ?></b> 正常</span>
      <span><b class="<?= $agCnt['warn'] ? 'red' : '' ?>" style="<?= $agCnt['warn'] ? 'color:#b45309' : '' ?>"><?= $agCnt['warn'] ?></b> 延遲</span>
      <span><b class="<?= $agCnt['bad'] ? 'red' : '' ?>"><?= $agCnt['bad'] ?></b> 離線</span>
    </div>
    <?php
      $agDetail = function (array $a): string {
          $v = $a['ver'] !== null ? h($a['runtime'] . ' ' . $a['ver']) . ($a['behind'] ? ' <span class="low">（版本落後）</span>' : '') : h($a['runtime']);
          $out = '<span>最後心跳 <b>' . h($a['when']) . '</b>' . ($a['ago'] ? '（' . h($a['ago']) . '）' : '') . '</span>'
               . '<span>' . $v . '</span>'
               . '<span>7 日額度 <b>' . ($a['quota'] !== null ? h(rtrim(rtrim(number_format($a['quota'], 1), '0'), '.')) . '%' : '–') . '</b></span>';
          if ($a['note'] !== '') { $out .= '<span style="flex:1 1 100%">這班：' . h(mb_strimwidth($a['note'], 0, 160, '…', 'UTF-8')) . '</span>'; }
          return $out;
      };
      $agProblems = array_values(array_filter($agents, fn($a) => $a['problem']));
      $agFine = array_values(array_filter($agents, fn($a) => !$a['problem']));
    ?>
    <div style="margin-top:12px">
    <?php foreach ($agProblems as $a): ?>
      <div class="card">
        <span class="dot <?= h($a['cls']) ?>" title="<?= h($a['live']) ?>"></span>
        <span class="name"><?= h($a['name']) ?>　<span class="sub"><?= h($a['live']) ?></span><?= $a['daily'] !== null ? '<span class="tag bad">' . h($a['daily']) . '</span>' : '' ?></span>
        <div class="nums meta"><?= $agDetail($a) ?></div>
      </div>
    <?php endforeach; ?>
    <?php if ($agFine): ?>
      <div class="chips">
      <?php foreach ($agFine as $a): ?>
        <details class="chip">
          <summary><span class="dot <?= h($a['cls']) ?>" title="<?= h($a['live']) ?>"></span><?= h($a['name']) ?> <span class="sub"><?= h($a['when']) ?></span></summary>
          <div class="nums meta"><?= $agDetail($a) ?></div>
        </details>
      <?php endforeach; ?>
      </div>
    <?php endif; ?>
    </div>
    <?php endif; ?>
  </section>

  <section class="panel<?= $upCls === 'bad' ? ' alert' : '' ?>">
    <div class="panel-hd">
      <span class="dot big <?= h($upCls) ?>"></span>
      <h2>網站監控</h2>
      <span class="sub"><?= $up ? '更新於 ' . h(date('H:i', (int) $up['at'])) . ' ・ ' : '' ?><a href="/uptime.php">點這裡看詳情 →</a></span>
    </div>
    <?php if ($upMsg !== ''): ?><div class="notice"><?= h($upMsg) ?></div><?php endif; ?>
    <?php if ($up): ?>
    <div class="inline">
      <span><b><?= $cnt['ok'] ?></b> 正常</span>
      <span><b class="<?= $cnt['down'] ? 'red' : '' ?>"><?= $cnt['down'] ?></b> 中斷／疑似中斷</span>
      <span><b><?= $cnt['paused'] ?></b> 暫停監測</span>
    </div>
    <?php if ($cnt['down']): ?>
      <p class="note">有狀況：<?= h(implode('、', array_map(fn($m) => $m['name'], array_filter($mons, fn($m) => !in_array($m['cls'], ['ok', 'paused'], true))))) ?></p>
    <?php endif; ?>
    <?php endif; ?>
  </section>

  <section class="panel">
    <div class="panel-hd">
      <span class="dot big wait"></span>
      <h2>設備監控</h2>
      <span class="sub">Zabbix</span>
    </div>
    <p class="todo">建置中：設備清單等熊叔叔確認。</p>
  </section>

  <h2>測試站</h2>
<?php foreach ($TEST_SITES as $s): $st = site_status($s['check']); ?>
  <div class="card">
    <span class="dot <?= $st['ok'] ? 'ok' : 'bad' ?>" title="<?= $st['ok'] ? '正常' : '連不到' ?>"></span>
    <span class="name"><?= h($s['name']) ?><?= $st['version'] ? '　<span class="sub">v' . h($st['version']) . '</span>' : '' ?></span>
    <a class="btn" href="<?= h($s['url']) ?>" target="_blank" rel="noopener">開啟測試站</a>
    <a class="btn" href="<?= h($s['prod']) ?>" target="_blank" rel="noopener">對照正式站</a>
    <div class="meta"><?= $st['ok'] ? '正常' : '⚠️ 連不到' ?>（HTTP <?= $st['code'] ?: '—' ?>，<?= $st['ms'] ?> ms）・ <?= h($s['note']) ?></div>
  </div>
<?php endforeach; ?>
<?php page_foot();
