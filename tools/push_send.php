<?php
declare(strict_types=1);

/**
 * 戰情室推播的命令列入口（資安事件、新申請單等其他程式呼叫；參考 it.ccra.tw deploy/push_send.php）。
 *   sudo -u www-data php /srv/dev/ops.ccnda.net/tools/push_send.php --kind security --title "…" --body "…" [--url /] [--tag xxx] [--email 某人]
 * 印出「ok=N fail=M」；沒有任何訂閱時 ok=0 fail=0，呼叫端不要把它當成失敗。
 */

if (PHP_SAPI !== 'cli') {
    exit;
}
require __DIR__ . '/../lib/webpush.php';
date_default_timezone_set('Asia/Taipei');

$o = getopt('', ['kind:', 'title:', 'body:', 'url:', 'tag:', 'email:']);
if (empty($o['title'])) {
    fwrite(STDERR, "need --title\n");
    exit(2);
}
[$ok, $fail] = push_send(['title' => $o['title'], 'body' => $o['body'] ?? '', 'url' => $o['url'] ?? '/', 'tag' => $o['tag'] ?? null],
    $o['kind'] ?? 'cli', $o['email'] ?? null);
echo "ok=$ok fail=$fail\n";
