<?php
declare(strict_types=1);

/** 戰情室共用版面（首頁摘要卡、網站監控詳情頁）。 */

date_default_timezone_set('Asia/Taipei'); // cora-lab 主機是 UTC，畫面一律台北時間

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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
<?php if ($refresh > 0): ?><meta http-equiv="refresh" content="<?= $refresh ?>">
<?php endif; ?><title><?= h($title) ?></title>
<style>
:root{--bg:#f4f7fa;--card:#fff;--ink:#14303c;--soft:#5c7482;--line:#e2e8ee;--ok:#1a7f4b;--bad:#b42318;--warn:#d97706;--link:#0e6d94}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font-family:"Noto Sans TC","Microsoft JhengHei",sans-serif;line-height:1.7}
header{background:var(--card);border-bottom:1px solid var(--line)}
.wrap{max-width:960px;margin:0 auto;padding:16px 20px}
h1{margin:0;font-size:1.3rem;letter-spacing:.08em}
h1 a{color:inherit}
.sub{color:var(--soft);font-size:.85rem;font-weight:400}
h2{font-size:1.05rem;margin:28px 0 10px}
a{color:var(--link);text-decoration:none}
a:hover{text-decoration:underline}
.dot{width:12px;height:12px;border-radius:50%;flex:0 0 auto;display:inline-block}
.dot.big{width:18px;height:18px}
.dot.ok{background:var(--ok)}.dot.bad{background:var(--bad)}.dot.warn{background:var(--warn)}.dot.paused,.dot.wait{background:#9aa9b3}
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
.chips{display:flex;flex-wrap:wrap;gap:8px}
.chip{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:6px 12px;flex:0 1 auto}
.chip[open]{flex:1 1 100%}
.chip summary{cursor:pointer;list-style:none;display:flex;align-items:center;gap:8px;font-size:.92rem}
.chip summary::-webkit-details-marker{display:none}
.chip .nums{margin-top:6px}
.tag{display:inline-block;font-size:.76rem;padding:0 9px;border-radius:10px;border:1px solid currentColor;margin-left:6px;font-weight:400}
.tag.bad{color:var(--bad)}.tag.warn{color:#b45309}
.notice{color:#8a5a00;background:#fff7e6;border:1px solid #f5d9a8;border-radius:10px;padding:10px 16px;margin:0 0 10px}
</style>
</head>
<body>
<header><div class="wrap">
  <h1><a href="/">CCNDA 戰情室</a></h1>
  <div class="sub"><?= h($sub) ?></div>
</div></header>
<main class="wrap">
<?php
}

function page_foot(): void
{
    echo "</main>\n</body>\n</html>\n";
}
