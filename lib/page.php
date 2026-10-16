<?php
declare(strict_types=1);

/** 戰情室共用版面（首頁摘要卡、網站監控詳情頁）。 */

date_default_timezone_set('Asia/Taipei'); // cora-lab 主機是 UTC，畫面一律台北時間

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** 目前版本（repo 根目錄 VERSION）。熊哥 2026-10-11：「戰情網也要有協會logo 版本序號 版本說明」 */
function app_version(): string
{
    $v = @file_get_contents(__DIR__ . '/../VERSION');
    return $v === false ? '' : trim($v);
}

function page_head(string $title, string $sub, int $refresh = 0): void
{
    header('Cache-Control: no-store');
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    ?><!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h($title) ?></title>
<link rel="icon" type="image/png" href="/assets/logo-ccnda.png">
<!-- 加到主畫面的圖示（熊哥 2026-10-12：「讓戰情室放在主畫面的時候有Logo」「Andriod 和iOS 都要有」）。
     manifest 要帶 credentials，否則 Cloudflare Access 會把它轉去登入頁、Android 讀不到圖示。 -->
<link rel="apple-touch-icon" sizes="180x180" href="/assets/apple-touch-icon.png">
<link rel="manifest" href="/manifest.webmanifest" crossorigin="use-credentials">
<meta name="theme-color" content="#14303c">
<meta name="apple-mobile-web-app-title" content="戰情室">
<meta name="application-name" content="戰情室">
<style>
:root{--bg:#f4f7fa;--card:#fff;--ink:#14303c;--soft:#5c7482;--line:#e2e8ee;--ok:#1a7f4b;--bad:#b42318;--warn:#d97706;--link:#0e6d94}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font-family:"Noto Sans TC","Microsoft JhengHei",sans-serif;line-height:1.7}
header{background:var(--card);border-bottom:1px solid var(--line)}
.wrap{max-width:960px;margin:0 auto;padding:16px 20px}
h1{margin:0;font-size:1.3rem;letter-spacing:.08em}
h1 a{color:inherit;display:inline-flex;align-items:center;gap:10px}
h1 a:hover{text-decoration:none}
.brand{width:34px;height:34px;border-radius:6px;flex:0 0 auto}
footer{margin-top:40px;border-top:1px solid var(--line);background:var(--card)}
.ft{max-width:960px;margin:0 auto;padding:16px 20px;font-size:.82rem;color:var(--soft);display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center}
.ft-logo{width:24px;height:24px;border-radius:4px;flex:0 0 auto}
.ft-org{display:inline-flex;align-items:center;gap:8px}
.nw{white-space:nowrap}
.sub{color:var(--soft);font-size:.85rem;font-weight:400}
h2{font-size:1.05rem;margin:28px 0 10px}
a{color:var(--link);text-decoration:none}
a:hover{text-decoration:underline}
.sub a,.notice a,.meta a,.inline a{text-decoration:underline;text-underline-offset:2px}
:focus-visible{outline:3px solid var(--link);outline-offset:2px;border-radius:4px}
.pushbox{display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin:12px 0 0}
.pushbox[hidden]{display:none}
.skip{position:absolute;left:-9999px;top:8px;background:#fff;color:var(--link);padding:8px 14px;border-radius:8px;z-index:10}
.skip:focus{left:12px}
.rf{font:inherit;font-size:.82rem;color:var(--link);background:none;border:1px solid var(--line);border-radius:999px;padding:1px 10px;cursor:pointer;margin-left:4px}
.rf[aria-pressed=true]{color:#8a5a00;border-color:#f5d9a8;background:#fff7e6}
.dot{width:12px;height:12px;border-radius:50%;flex:0 0 auto;display:inline-block}
.dot.big{width:18px;height:18px}
.dot.ok{background:var(--ok)}.dot.bad{background:var(--bad)}.dot.warn{background:var(--warn)}.dot.paused,.dot.wait{background:#9aa9b3}
.meter{display:inline-flex;align-items:center;gap:4px;font-size:12px;color:var(--soft);white-space:nowrap}
.meter .bar{position:relative;width:30px;height:12px;border:1.5px solid #8a9aa5;border-radius:3px;background:#fff;padding:1px;display:inline-block;box-sizing:border-box;margin-right:3px}
.meter .bar::after{content:"";position:absolute;right:-4px;top:2.5px;width:2px;height:4px;background:#8a9aa5;border-radius:0 1px 1px 0}
.meter .fill{display:block;height:100%;border-radius:1px;background:var(--ok)}
.meter .bw{display:inline-flex;flex-direction:column;gap:2px;margin-right:3px}
.meter .bw .bar{margin-right:0}
.meter .time{width:calc(100% - 3px);height:3px;border-radius:2px;background:#e2e8ee;overflow:hidden}
.meter .time span{display:block;height:100%;background:#9aa9b3}
.meter.warn .fill{background:var(--warn)}.meter.warn{color:#b45309}
.meter.bad .fill{background:var(--bad)}.meter.bad{color:var(--bad);font-weight:600}
/* 摘要卡 */
.panel{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:18px 20px;margin:0 0 16px;box-shadow:0 1px 3px rgba(20,48,60,.05)}
.panel.alert{border-color:#f1a7a1}
.panel-hd{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:0 0 12px}
.panel-hd h2{margin:0;font-size:1.1rem}
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:10px}
.stat{display:block;border:1px solid var(--line);border-radius:12px;padding:10px 14px;color:var(--ink)}
.stat:hover{text-decoration:none;border-color:var(--link)}
.stat.on{border-color:var(--link);box-shadow:inset 0 0 0 1px var(--link)}
.stat b{display:block;font-size:1.8rem;line-height:1.2;font-variant-numeric:tabular-nums}
.stat span{color:var(--soft);font-size:.85rem}
.stat.red{border-color:#f1a7a1}.stat.red b{color:var(--bad)}
.inline{display:flex;gap:22px;flex-wrap:wrap;align-items:baseline}
.inline b{font-size:1.4rem;font-variant-numeric:tabular-nums}
.inline .red{color:var(--bad)}
.note{color:var(--soft);font-size:.85rem;margin:10px 0 0}
.list{margin:12px 0 0;padding:0;list-style:none;border-top:1px solid var(--line)}
.list li{padding:6px 0;border-bottom:1px solid var(--line);display:flex;gap:10px;flex-wrap:wrap;font-size:.92rem}
.list .t{flex:1 1 20em}
.list .m{color:var(--soft);font-size:.82rem;white-space:nowrap}
.list .late{color:var(--bad)}
/* 卡片（測試站、網站監控詳情） */
.card{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:14px 18px;margin:0 0 10px;display:flex;gap:14px;align-items:center;flex-wrap:wrap}
.name{font-weight:700;flex:1 1 14em}
.meta{color:var(--soft);font-size:.82rem;flex:1 1 100%}
.btn{border:1px solid var(--line);border-radius:999px;padding:4px 14px;font-size:.9rem}
.todo{color:var(--soft);font-size:.92rem}
.nums{display:flex;gap:16px;flex-wrap:wrap;font-variant-numeric:tabular-nums;font-size:.9rem}
.nums b{font-weight:600}.low{color:var(--bad)}
.chips{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:8px}
.chip{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:6px 12px;min-width:0}
.chip[open]{grid-column:1/-1}
/* 設備監控 */
.dev-cat{font-size:.95rem;margin:12px 0 6px;color:var(--soft);font-weight:600}
.devs{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:8px}
.devs .chip[open]{grid-column:1/-1}
.dev-grp{font-size:.85rem;margin:10px 0 4px;color:var(--soft);font-weight:600}
.chip summary .why{flex:1 1 100%;font-size:.8rem;color:var(--warn-ink,#8a4b00);white-space:normal}
.chip.dev summary{flex-wrap:wrap}
.chip summary .why.soft{color:var(--soft)}
details.sheet{margin-top:14px;border-top:1px solid var(--line);padding-top:10px}
details.sheet>summary{cursor:pointer;color:var(--soft);font-size:.9rem;font-weight:600}
/* 網站流量（表格；手機上可左右捲，捲動區可用鍵盤聚焦） */
.sr{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
.tblwrap{overflow-x:auto;margin:8px 0}
.tblwrap:focus-visible{outline:2px solid var(--link);outline-offset:2px}
.tbl{width:100%;border-collapse:collapse;font-variant-numeric:tabular-nums;font-size:.9rem}
.tbl th,.tbl td{padding:6px 10px;border-bottom:1px solid var(--line);text-align:right;white-space:nowrap}
.tbl thead th{color:var(--soft);font-weight:600}
.tbl thead th[scope=colgroup]{text-align:center;border-bottom:0}
.tbl td.soft{color:var(--soft);text-align:center}
.tbl th[scope=row],.tbl thead th:first-child{text-align:left;font-weight:500}
/* 開發中專案 */
.projs{display:grid;gap:10px}
.card.proj{display:block}
.proj-hd{display:flex;flex-wrap:wrap;align-items:baseline;gap:6px 14px}
.proj-hd .meta{display:flex;flex-wrap:wrap;gap:4px 12px;flex:1 1 auto}
.card.proj > .meta{margin-top:4px}
.proj-items{list-style:none;margin:8px 0 0;padding:0;display:grid;gap:6px}
.proj-items li{display:flex;flex-wrap:wrap;align-items:baseline;gap:4px 10px;font-size:.9rem}
.proj-items .it-title{flex:1 1 14em;min-width:0}
.proj-items .it-wait{color:var(--soft);font-size:.82rem}
.proj-items .it-links{font-size:.82rem}
.stage{display:inline-block;font-size:.76rem;padding:0 9px;border-radius:10px;border:1px solid currentColor;white-space:nowrap}
.st-dev{color:#1d4ed8}.st-queued{color:#5c7482}.st-retest{color:#b45309}.st-confirm{color:#b42318;font-weight:600}
.st-deploy{color:#7c3aed}.st-plan{color:#5c7482}.st-blocked{color:#b42318}.st-done{color:var(--ok)}
/* 兩兩一組：電腦上下疊（一欄）、手機左右並排；共用額度的組加虛線框 */
.chips{gap:12px}
.pair{display:flex;flex-direction:column;gap:8px;min-width:0;border-radius:13px}
.pair.shared{outline:1.5px dashed #b8c4cc;outline-offset:3px}
.pair:has(.chip[open]){grid-column:1/-1}
.chip summary{cursor:pointer;list-style:none;display:flex;align-items:center;gap:8px;font-size:.92rem;min-width:0}
.chip summary .nm{flex:1 1 auto;min-width:2.2em;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.chip summary .sub{flex:0 0 auto}
.clamp{display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:3;overflow:hidden}
.chip summary::-webkit-details-marker{display:none}
.chip .nums{margin-top:6px}
.tag{display:inline-block;font-size:.76rem;padding:0 9px;border-radius:10px;border:1px solid currentColor;margin-left:6px;font-weight:400}
.tag.bad{color:var(--bad)}.tag.warn{color:#b45309}
/* 手機（熊哥：「手機排版不好看」）*/
@media (max-width:600px){
  .chips{grid-template-columns:1fr 1fr}.chip{padding:6px 8px}.chip summary{gap:5px}.meter .bar{width:24px}
  .wrap{padding:12px}
  .panel{padding:14px;border-radius:12px;margin-bottom:12px}
  .panel-hd{gap:8px;margin-bottom:10px}
  .card{padding:10px 12px;gap:8px}
  .nums{gap:4px 12px;font-size:.85rem}
  .chips{grid-template-columns:repeat(2,minmax(0,1fr));gap:6px}
  .chip{padding:6px 10px}
  .chip summary{font-size:.88rem;gap:6px}
  .stats{grid-template-columns:repeat(auto-fit,minmax(96px,1fr));gap:8px}
  .stat{padding:8px 10px}.stat b{font-size:1.5rem}
  .inline{gap:14px}.inline b{font-size:1.25rem}
  .clamp{-webkit-line-clamp:2}
  .list .m{white-space:normal}
  .ft{flex-direction:column;align-items:center;text-align:center;gap:6px;padding:14px 12px}
}
.notice{color:#8a5a00;background:#fff7e6;border:1px solid #f5d9a8;border-radius:10px;padding:10px 16px;margin:0 0 10px}
@media (max-width:600px){.chips{grid-template-columns:1fr;gap:12px}.pair{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px}}
</style>
</head>
<body>
<a class="skip" href="#main">跳到主要內容</a>
<header><div class="wrap">
  <h1><a href="/"><img class="brand" src="/assets/logo-ccnda.png" alt="">CCNDA 戰情室</a></h1>
  <div class="sub"><?= h($sub) ?><?php if ($refresh > 0): ?> <button type="button" id="rf" class="rf" aria-pressed="false">暫停自動更新</button><?php endif; ?></div>
</div></header>
<main class="wrap" id="main">
<?php if ($refresh > 0): ?>
<script>
(function(){
  var KEY = 'ops-refresh-paused', btn = document.getElementById('rf'), paused = false;
  try { paused = localStorage.getItem(KEY) === '1'; } catch (e) {}
  function show(){ btn.setAttribute('aria-pressed', paused ? 'true' : 'false'); btn.textContent = paused ? '已暫停・恢復自動更新' : '暫停自動更新'; }
  btn.addEventListener('click', function(){ paused = !paused; try { localStorage.setItem(KEY, paused ? '1' : '0'); } catch (e) {} show(); });
  show();
  setInterval(function(){
    var busy = document.querySelector('details[open]') || (document.activeElement && document.activeElement !== document.body && document.activeElement !== btn);
    if (!paused && !busy) location.reload();
  }, <?= (int) $refresh ?> * 1000);
})();
</script>
<?php endif; ?>
<?php
}

function page_foot(): void
{
    $v = app_version();
    echo "</main>\n<footer><div class=\"ft\">"
        . '<span class="ft-org"><img class="ft-logo" src="/assets/logo-ccnda.png" alt="CCNDA"><span class="nw">© 2026 中華基督教網路發展協會</span></span>'
        . '<span class="ft-meta"><span class="nw">內部使用</span> ・ <a class="nw" href="/changelog.php">' . ($v !== '' ? 'v' . h($v) . ' 版本說明' : '版本說明') . '</a></span>'
        . "</div></footer>\n</body>\n</html>\n";
}
