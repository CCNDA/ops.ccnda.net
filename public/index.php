<?php
declare(strict_types=1);
/**
 * CCNDA 戰情室 ops.ccnda.net —— 首頁：摘要卡（任務、數位同工、開發中專案〔含測試站〕、網站監控、設備監控）。
 * 熊哥 2026-10-11 TG：「先做好戰情室，都透過 zero trust 聯結看內網的測試站」；
 *   20:54「我要類似這樣 任務要讀 asana」（大蘇 it.ccra.tw/ops 的摘要卡版面）；「不用全部展開，有問題在展開就好」。
 *
 * 只在 cora-lab 本機（nginx 127.0.0.1）跑，對外經 Cloudflare Tunnel，登入由 Cloudflare Access
 * （Google Workspace，限 @ccnda.org）把關。Asana 1219371344401545、1219390021775751。
 * - 任務：tools/asana_summary.py 每小時寫 state/asana_summary.json，這裡只讀檔（不碰權杖）
 * - 網站監控：lib/uptime.php（UptimeRobot 唯讀金鑰在 state/，快取 60 秒）；詳情在 uptime.php
 * - 數位同工：lib/agents.php（cora-lab 每 2 分鐘產生 state/agents.json；超過 90 分鐘沒心跳＝紅燈＋推播）
 * - 頁面每 60 秒自動重新整理（媽媽 2026-10-11 信：頁面每分鐘自動刷新）
 */

require __DIR__ . '/../lib/page.php';
require __DIR__ . '/../lib/uptime.php';
require __DIR__ . '/../lib/agents.php';
require __DIR__ . '/../lib/projects.php';
require __DIR__ . '/../lib/devices.php';
require __DIR__ . '/../lib/zabbix.php';
require __DIR__ . '/../lib/linode.php';
require __DIR__ . '/../lib/ga.php';

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
$agCls = !$ag ? 'wait' : ($agCnt['bad'] ? 'bad' : ($agCnt['warn'] ? 'warn' : 'ok'));

page_head('CCNDA 戰情室', '內部使用 ・ ' . date('Y-m-d H:i') . ' 從 cora-lab 檢查 ・ 每分鐘自動更新', 60);
?>
  <!-- 網頁推播（Asana 1219443887250576）：沒有 JS 或瀏覽器不支援時整塊保持隱藏 -->
  <div id="push-box" class="pushbox" hidden>
    <button type="button" id="push-btn" class="rf" aria-pressed="false">開啟通知</button>
    <button type="button" id="push-test" class="rf" hidden>送測試通知</button>
    <span id="push-msg" class="sub" role="status" aria-live="polite"></span>
  </div>
  <script src="/assets/push.js" defer></script>
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
      <span class="sub"><?= $ag ? '更新於 ' . h(date('H:i', (int) filemtime(AGENTS_FILE))) . ' ・ ' . AGENTS_GREEN_MIN . ' 分鐘內有心跳＝正常、超過就是停擺（紅燈，開了通知的裝置會收到推播） ・ 電池＝7 日額度剩餘，下方灰條＝7 日時間剩餘（綠色比灰條短＝可能提早用完） ・ 虛線框＝同一個帳號額度' : '' ?></span>
    </div>
    <?php if ($agMsg !== ''): ?><div class="notice"><?= h($agMsg) ?></div><?php endif; ?>
    <?php if ($ag): ?>
    <div class="inline">
      <span><b><?= $agCnt['ok'] ?></b> 正常</span>
      <span><b class="<?= $agCnt['warn'] ? 'red' : '' ?>" style="<?= $agCnt['warn'] ? 'color:#b45309' : '' ?>"><?= $agCnt['warn'] ?></b> 延遲</span>
      <span><b class="<?= $agCnt['bad'] ? 'red' : '' ?>"><?= $agCnt['bad'] ?></b> 離線</span>
    </div>
    <?php
      // 7 日額度電池：像手機電量一樣顯示「剩下」多少（剩 30% 以下橘、10% 以下紅）；沒有額度資料就不畫
      $agMeter = function (array $a): string {
          if ($a['quota'] === null) { return ''; }
          $p = 100.0 - max(0.0, min(100.0, $a['quota']));
          $cls = $p <= 10 ? ' bad' : ($p <= 30 ? ' warn' : '');
          $txt = rtrim(rtrim(number_format($p, 1), '0'), '.') . '%';
          $tip = '7 日額度剩 ' . $txt . ($a['reset'] ? '，' . date('n/j H:i', $a['reset']) . ' 重置' : '');
          // 時間條：這 7 天還剩多少時間（重置前 7 天起算），畫在電池下方。綠色電量比灰條短＝用得比時間快，可能提早用完
          $line = '';
          if ($a['reset']) {
              $t = max(0.0, min(100.0, ($a['reset'] - time()) / (7 * 86400) * 100));
              $line = '<span class="time"><span style="width:' . round($t) . '%"></span></span>';
              $tip .= '；時間剩 ' . round($t) . '%' . ($p < $t ? '（用得比時間快，可能提早用完）' : '');
          }
          return '<span class="meter' . $cls . '" title="' . h($tip) . '" aria-label="' . h($tip) . '">'
               . '<span class="bw"><span class="bar"><span class="fill" style="width:' . round($p) . '%"></span></span>' . $line . '</span>' . h($txt) . '</span>';
      };
      // 重置倒數：一天以上寫「還有 N 天 H 小時」，不到一天寫「還有 H 小時 M 分」
      $agLeft = function (int $reset): string {
          $s = $reset - time();
          if ($s <= 0) { return '，已到重置時間'; }
          $d = intdiv($s, 86400); $hh = intdiv($s % 86400, 3600); $mm = intdiv($s % 3600, 60);
          return $d > 0 ? '，還有 ' . $d . ' 天 ' . $hh . ' 小時' : '，還有 ' . $hh . ' 小時 ' . $mm . ' 分';
      };
      $agDetail = function (array $a) use ($agLeft): string {
          $v = $a['ver'] !== null ? h($a['runtime'] . ' ' . $a['ver']) . ($a['behind'] ? ' <span class="low">（版本落後）</span>' : '') : h($a['runtime']);
          $out = '<span>最後心跳 <b>' . h($a['when']) . '</b>' . ($a['ago'] ? '（' . h($a['ago']) . '）' : '') . '</span>'
               . '<span>' . $v . '</span>'
               . '<span>7 日額度 <b>' . ($a['quota'] !== null ? '已用 ' . h(rtrim(rtrim(number_format($a['quota'], 1), '0'), '.')) . '%，剩 ' . h(rtrim(rtrim(number_format(100 - $a['quota'], 1), '0'), '.')) . '%' : '–') . '</b>'
               . ($a['reset'] ? '（' . h(date('n/j H:i', $a['reset'])) . ' 重置' . h($agLeft($a['reset'])) . '）' : '') . '</span>';
          if ($a['note'] !== '') { $out .= '<span class="clamp" style="flex:1 1 100%">這班：' . h(mb_strimwidth($a['note'], 0, 160, '…', 'UTF-8')) . '</span>'; }
          return $out;
      };
      $agProblems = array_values(array_filter($agents, fn($a) => $a['problem']));
      $agFine = array_values(array_filter($agents, fn($a) => !$a['problem']));
    ?>
    <div style="margin-top:12px">
    <?php foreach ($agProblems as $a): ?>
      <div class="card">
        <span class="dot <?= h($a['cls']) ?>" title="<?= h($a['live']) ?>"></span>
        <span class="name" title="<?= h($a['full']) ?>"><?= h($a['name']) ?>　<span class="sub"><?= h($a['live']) ?></span><?= $agMeter($a) ?></span>
        <div class="nums meta"><?= $agDetail($a) ?></div>
      </div>
    <?php endforeach; ?>
    <?php if ($agFine): ?>
      <div class="chips">
      <?php foreach (agent_groups($agFine) as $g): ?>
        <div class="pair<?= $g['shared'] ? ' shared' : '' ?>"<?= $g['shared'] ? ' role="group" aria-label="同一個帳號額度：' . h(implode('、', array_column($g['items'], 'name'))) . '" title="同一個帳號額度"' : '' ?>>
        <?php foreach ($g['items'] as $a): ?>
          <details class="chip">
            <summary title="<?= h($a['full']) ?>"><span class="dot <?= h($a['cls']) ?>"></span><span class="nm"><?= h($a['name']) ?></span><?= $agMeter($a) ?><span class="sub"><?= h($a['when']) ?></span></summary>
            <div class="nums meta"><?= $agDetail($a) ?></div>
          </details>
        <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
      </div>
    <?php endif; ?>
    </div>
    <?php endif; ?>
  </section>

  <?php [$proj, $projMsg] = projects_data(); ?>
  <section class="panel" id="projects">
    <div class="panel-hd">
      <span class="dot big <?= $proj ? 'ok' : 'wait' ?>"></span>
      <h2>開發中專案</h2>
      <span class="sub"><?= $proj ? '更新於 ' . h(substr((string) $proj['checked_at'], 11, 5)) . ' ・ 每 10 分鐘更新' : '' ?></span>
    </div>
    <?php if ($projMsg !== ''): ?><div class="notice"><?= h($projMsg) ?></div><?php endif; ?>
    <?php if ($proj): ?>
    <div class="projs">
    <?php foreach ($proj['projects'] as $p): ?>
      <div class="card proj">
        <?php $st = !empty($p['test_check']) ? site_status((string) $p['test_check']) : null; ?>
        <div class="proj-hd">
          <?php if ($st): ?><span class="dot <?= $st['ok'] ? 'ok' : 'bad' ?>" title="測試站<?= $st['ok'] ? '正常' : '連不到' ?>"></span><?php endif; ?>
          <span class="name"><?= project_link($p['url'] ?? null, (string) $p['name']) ?: h((string) $p['name']) ?></span>
          <span class="meta">
            <?php if (!empty($p['prod_version'])): ?><span>正式版 <b>v<?= h((string) $p['prod_version']) ?></b></span><?php endif; ?>
            <?php if (!empty($p['test']['branch'])): ?><span>測試站：<?= project_link($p['test_url'] ?? null, (string) $p['test']['branch']) ?: h((string) $p['test']['branch']) ?>（<?= h((string) $p['test']['commit']) ?>）</span><?php endif; ?>
            <?= !empty($p['github']) ? '<span>' . project_link($p['github'], 'GitHub') . '</span>' : '' ?>
          </span>
          <?php if (!empty($p['test_url']) && preg_match('#^https?://#i', (string) $p['test_url'])): ?><a class="btn" href="<?= h((string) $p['test_url']) ?>" target="_blank" rel="noopener">開啟測試站</a><?php endif; ?>
          <?php if (!empty($p['url']) && !empty($p['test_url']) && preg_match('#^https?://#i', (string) $p['url'])): ?><a class="btn" href="<?= h((string) $p['url']) ?>" target="_blank" rel="noopener">對照正式站</a><?php endif; ?>
        </div>
        <?php if ($st || !empty($p['test_note'])): ?>
        <div class="meta"><?= $st ? ($st['ok'] ? '測試站正常' : '⚠️ 測試站連不到') . '（HTTP ' . ($st['code'] ?: '—') . '，' . $st['ms'] . ' ms）' : '' ?><?= !empty($p['test_note']) ? ($st ? ' ・ ' : '') . h((string) $p['test_note']) : '' ?></div>
        <?php endif; ?>
        <ul class="proj-items">
        <?php foreach (($p['items'] ?? []) as $it):
            [$stLabel, $stCls] = PROJECT_STAGES[$it['stage'] ?? ''] ?? [(string) ($it['stage'] ?? '—'), 'st-plan']; ?>
          <li>
            <span class="stage <?= h($stCls) ?>"><?= h($stLabel) ?></span>
            <span class="it-title"><?= h((string) $it['title']) ?></span>
            <?php if (!empty($it['waiting'])): ?><span class="it-wait">卡在：<?= h((string) $it['waiting']) ?></span><?php endif; ?>
            <span class="it-links"><?= project_link($it['asana'] ?? null, 'Asana') ?><?= !empty($it['github']) ? ' ・ ' . project_link($it['github'], 'GitHub') : '' ?></span>
          </li>
        <?php endforeach; ?>
        </ul>
      </div>
    <?php endforeach; ?>
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

  <?php [$dev, $devMsg] = devices_data(); [$zbx, $zbxMsg] = zabbix_data();
    if ($zbx) { $zbx['hosts'] = zabbix_merge_by_ip($zbx['hosts']); }   // 同一台的 agent＋ping 合成一張
    $zbxCnt = ['ok' => 0, 'warn' => 0, 'bad' => 0];
    foreach ($zbx['hosts'] ?? [] as $zh) { $zbxCnt[$zh['cls']]++; }
    $devCls = $zbxCnt['bad'] ? 'bad' : ($zbxCnt['warn'] ? 'warn' : ($dev || $zbx ? 'ok' : 'wait')); ?>
  <section class="panel<?= $devCls === 'bad' ? ' alert' : '' ?>" id="devices">
    <div class="panel-hd">
      <span class="dot big <?= h($devCls) ?>"></span>
      <h2>設備監控</h2>
      <span class="sub"><?= $dev ? 'Zabbix 即時狀態，每分鐘更新 ・ 下方可展開設備管理表（資產資料）' : 'Zabbix' ?></span>
    </div>
    <?php if ($devMsg !== ''): ?><div class="notice"><?= h($devMsg) ?></div><?php endif; ?>
    <h3 class="dev-cat">Zabbix 即時狀態<?= $zbx ? '（' . count($zbx['hosts']) . ' 台・' . h(date('H:i', (int) $zbx['at'])) . '）' : '' ?></h3>
    <?php if ($zbxMsg !== ''): ?><div class="notice"><?= h($zbxMsg) ?></div><?php endif; ?>
    <?php if ($zbx): ?>
      <p class="note">紅＝嚴重以上或連不上・黃＝一般／警告・綠＝正常（<?= $zbxCnt['ok'] ?> 正常、<?= $zbxCnt['warn'] ?> 注意、<?= $zbxCnt['bad'] ?> 異常）；有狀況時已開通知的裝置會收到推播</p>
      <div class="devs">
      <?php foreach ($zbx['hosts'] as $zh): ?>
        <details class="chip dev">
          <summary title="<?= h($zh['name']) ?>"><span class="dot <?= h($zh['cls']) ?>"></span><span class="nm"><?= h($zh['name']) ?></span></summary>
          <div class="nums meta">
            <span>IP <b><?= h($zh['ip'] !== '' ? $zh['ip'] : '（agent 主動回報）') ?></b></span>
            <?php if ($zh['also']): ?><span>Zabbix 也叫 <b><?= h(implode('、', $zh['also'])) ?></b></span><?php endif; ?>
            <?php if (!$zh['problems']): ?><span>沒有問題</span><?php endif; ?>
            <?php foreach ($zh['problems'] as $zp): ?><span class="clamp" style="flex:1 1 100%"><?= h(['', '', '一般', '警告', '嚴重', '災難'][$zp['sev']] ?? '') ?>：<?= h($zp['name']) ?></span><?php endforeach; ?>
          </div>
        </details>
      <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <?php if ($dev): $devRows = array_values(array_filter($dev['rows'], fn($r) => ($r['類別'] ?? '') !== '雲端主機')); ?>
    <details class="sheet">
      <summary>設備管理表（資產資料：歸屬、機型、規格、負責人，<?= count($devRows) ?> 台）・<?= h(substr((string) $dev['read_at'], 11, 5)) ?> 讀取</summary>
      <p class="note"><?= project_link($dev['sheet_url'] ?? null, '開啟設備管理表') ?> ・ 這裡的燈號是表上資料齊不齊（紅＝有待補或待確認、黃＝待命），不是連線狀態；連線狀態看上面的 Zabbix。雲端主機（Linode）看下面的 Linode 主機卡。</p>
    <?php foreach (devices_grouped($devRows) as $cat => $rows): ?>
      <h3 class="dev-cat"><?= h($cat) ?>（<?= count($rows) ?>）</h3>
      <div class="devs">
      <?php foreach ($rows as $r): [$st, $stCls, $raw] = device_status($r); ?>
        <details class="chip dev">
          <summary title="<?= h((string) ($r['名稱／識別'] ?? '')) ?>"><span class="dot <?= h($stCls) ?>"></span><span class="nm"><?= h((string) ($r['名稱／識別'] ?? '—')) ?></span><?php if (($r['歸屬'] ?? '') !== 'CCNDA'): ?><span class="tag"><?= h((string) ($r['歸屬'] ?? '')) ?></span><?php endif; ?></summary>
          <div class="nums meta">
            <span>類型 <b><?= h((string) ($r['類型'] ?? '—')) ?></b></span>
            <span>機型 <b><?= h((string) ($r['機型／晶片'] ?? '—')) ?></b></span>
            <span>規格 <b><?= h((string) ($r['CPU／記憶體／OS'] ?? '—')) ?></b></span>
            <span>負責人 <b><?= h((string) ($r['負責人'] ?? '—')) ?></b></span>
            <span class="clamp" style="flex:1 1 100%"><?= h($raw) ?></span>
          </div>
        </details>
      <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
    </details>
    <?php endif; ?>
  </section>

  <?php [$lin, $linMsg] = linode_data();
    $linCnt = ['ok' => 0, 'warn' => 0, 'bad' => 0];
    foreach ($lin['hosts'] ?? [] as $lh) { if (isset($linCnt[$lh['cls']])) { $linCnt[$lh['cls']]++; } }
    $linCls = !$lin ? 'wait' : ($linCnt['bad'] ? 'bad' : ($linCnt['warn'] ? 'warn' : 'ok'));
    $linWord = ['ok' => '正常', 'warn' => '注意', 'bad' => '異常', 'wait' => '等待資料']; ?>
  <section class="panel<?= $linCls === 'bad' ? ' alert' : '' ?>" id="linode">
    <div class="panel-hd">
      <span class="dot big <?= h($linCls) ?>" role="img" aria-label="<?= h($linWord[$linCls]) ?>"></span>
      <h2>Linode 主機</h2>
      <span class="sub"><?= $lin ? '更新於 ' . h(date('H:i', (int) $lin['at'])) . ' ・ 每 5 分鐘・數字是最近 30 分鐘平均' : 'Linode API' ?></span>
    </div>
    <?php if ($linMsg !== ''): ?><div class="notice"><?= h($linMsg) ?></div><?php endif; ?>
    <?php if ($lin): ?>
      <p class="note">紅＝主機沒在跑、CPU ≥ 90% 或 swap ≥ 20・黃＝CPU ≥ 70% 或 swap ≥ 5・綠＝正常（<?= $linCnt['ok'] ?> 正常、<?= $linCnt['warn'] ?> 注意、<?= $linCnt['bad'] ?> 異常）。Linode 拿不到記憶體用量，用 swap 寫入量代替：平常是 0，持續有 swap 就是記憶體不夠</p>
      <div class="devs">
      <?php foreach ($lin['hosts'] as $lh): ?>
        <details class="chip dev"<?= $lh['cls'] !== 'ok' ? ' open' : '' ?>>
          <summary title="<?= h($lh['label']) ?>"><span class="dot <?= h($lh['cls']) ?>" role="img" aria-label="<?= h($linWord[$lh['cls']] ?? '') ?>"></span><span class="nm"><?= h($lh['label']) ?></span><span class="sub">CPU <?= h(linode_num($lh['cpu_avg'], '%')) ?></span></summary>
          <div class="nums meta">
            <?php if ($lh['why']): ?><span class="clamp" style="flex:1 1 100%"><b><?= h(implode('、', $lh['why'])) ?></b></span><?php endif; ?>
            <span>狀態 <b><?= h($lh['status']) ?></b></span>
            <span>規格 <b><?= (int) $lh['vcpus'] ?> 核・<?= h(linode_num($lh['memory_mb'] / 1024, ' GB')) ?></b></span>
            <span>CPU（每核）<b><?= h(linode_num($lh['cpu_avg'], '%')) ?></b>，現在 <?= h(linode_num($lh['cpu_now'], '%')) ?></span>
            <span>swap <b><?= h(linode_num($lh['swap_avg'])) ?></b></span>
            <span>磁碟 IO <b><?= h(linode_num($lh['io_avg'])) ?></b></span>
            <span>網路 進 <b><?= h(linode_num($lh['net_in_kbps'], ' kbps')) ?></b>／出 <b><?= h(linode_num($lh['net_out_kbps'], ' kbps')) ?></b></span>
            <span>IP <b><?= h($lh['ip']) ?></b></span>
          </div>
        </details>
      <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <?php [$ga, $gaMsg] = ga_data(); [$gaLive, $gaQuiet] = $ga ? ga_split($ga['sites']) : [[], []]; ?>
  <section class="panel" id="traffic">
    <div class="panel-hd">
      <h2>網站流量</h2>
      <span class="sub"><?= $ga ? '更新於 ' . h(date('H:i', (int) $ga['at'])) . ' ・ 每 15 分鐘・Google Analytics 與 Search Console' : 'Google Analytics' ?></span>
    </div>
    <?php if ($gaMsg !== ''): ?><div class="notice"><?= h($gaMsg) ?></div><?php endif; ?>
    <?php if ($ga): ?>
      <p class="note">「即時」是最近 30 分鐘在線上的人；7 天、30 天都到昨天為止。「今天」GA 要處理幾個小時才有（大流量的站可能要到隔天），還沒處理到的顯示「—」，不是 0 人。</p>
      <div class="tblwrap" role="region" aria-label="各網站使用者人數" tabindex="0">
        <table class="tbl">
          <caption class="sr">各網站使用者人數</caption>
          <thead>
            <tr><th scope="col" rowspan="2">網站</th><th scope="colgroup" colspan="5">使用者（Google Analytics）</th><th scope="colgroup" colspan="3">Google 搜尋（7 天）</th></tr>
            <tr><th scope="col">即時</th><th scope="col">今天</th><th scope="col">昨天</th><th scope="col">7 天</th><th scope="col">30 天</th><th scope="col">點擊</th><th scope="col">曝光</th><th scope="col">平均排名</th></tr>
          </thead>
          <tbody>
          <?php foreach ($gaLive as $s): ?>
            <tr>
              <th scope="row"><?php if (!empty($s['uri'])): ?><a href="<?= h($s['uri']) ?>" rel="noopener"><?= h($s['name']) ?></a><?php else: ?><?= h($s['name']) ?><?php endif; ?></th>
              <?php if (isset($s['err'])): ?>
                <td colspan="5">讀取失敗（<?= h($s['err']) ?>）</td>
              <?php else: ?>
                <td><?= ga_num($s['realtime'] ?? null) ?></td>
                <td><?= ga_num($s['today']['users'] ?? null) ?></td>
                <td><?= ga_num($s['yesterday']['users'] ?? null) ?></td>
                <td><?= ga_num($s['week']['users'] ?? null) ?></td>
                <td><?= ga_num($s['month']['users'] ?? null) ?></td>
              <?php endif; ?>
              <?php $sc = $s['search'] ?? ['err' => '未授權'];
                // 剛建立的 Search Console 資源要過幾天才有數字；點擊、曝光都是 0 不要顯示成 0
                if (!isset($sc['err']) && (int) $sc['clicks'] === 0 && (int) $sc['impressions'] === 0) { $sc = ['err' => '尚無資料']; } ?>
              <?php if (isset($sc['err'])): ?>
                <td colspan="3" class="soft"><?= h($sc['err']) ?></td>
              <?php else: ?>
                <td><?= ga_num($sc['clicks']) ?></td>
                <td><?= ga_num($sc['impressions']) ?></td>
                <td><?= h((string) $sc['position']) ?></td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if ($gaQuiet): ?>
        <p class="note">過去 7 天沒有流量：<?= h(implode('、', array_map(fn($s) => $s['name'], $gaQuiet))) ?></p>
      <?php endif; ?>
      <?php $scAny = array_values(array_filter(array_map(fn($s) => $s['search'] ?? null, $gaLive), fn($x) => $x && !isset($x['err']))); ?>
      <p class="note">Google 搜尋的數字約晚 2 天<?= $scAny ? '（' . h(substr((string) $scAny[0]['from'], 5)) . '～' . h(substr((string) $scAny[0]['to'], 5)) . '）' : '' ?>；網域資源（例如 taiwanbible.com）每一列只算自己網址的網頁，所以跟 Search Console 整個資源的總數會有些微差異。「未授權」＝ Search Console 還沒把 cora@ccnda.org 加成使用者；「尚無資料」＝資源剛建立，Search Console 要幾天後才有數字。</p>
    <?php endif; ?>
  </section>

<?php page_foot();
