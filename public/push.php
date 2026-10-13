<?php
declare(strict_types=1);

/**
 * 戰情室網頁推播：訂閱／取消／測試（參考 it.ccra.tw site/ops/push.php）。
 * 登入由 Cloudflare Access 把關（限 @ccnda.org）；nginx 只聽本機，身分取 Access 帶進來的 email 標頭。
 * GET → {"key": VAPID 公鑰, "mine": 這個帳號的訂閱數}；POST JSON {op: sub|unsub|test, sub?, endpoint?}
 */

require __DIR__ . '/../lib/webpush.php';

date_default_timezone_set('Asia/Taipei');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$email = strtolower(trim((string) ($_SERVER['HTTP_CF_ACCESS_AUTHENTICATED_USER_EMAIL'] ?? '')));
if (!preg_match('/^[^@\s]+@ccnda\.org$/', $email)) {
    http_response_code(403);
    exit('{"error":"forbidden"}');
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mine = count(array_filter(push_subs(), fn($s) => ($s['email'] ?? '') === $email));
    exit(json_encode(['key' => vapid_keys()['public'], 'mine' => $mine]));
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('{}');
}
$in = json_decode((string) file_get_contents('php://input'), true) ?: [];
$op = (string) ($in['op'] ?? '');

if ($op === 'sub') {
    $s = is_array($in['sub'] ?? null) ? $in['sub'] : [];
    $ep = (string) ($s['endpoint'] ?? '');
    $p = (string) ($s['keys']['p256dh'] ?? '');
    $a = (string) ($s['keys']['auth'] ?? '');
    if (!push_sub_valid($ep, $p, $a)) {
        http_response_code(400);
        exit('{"error":"bad subscription"}');
    }
    $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300);
    push_subs_update(function (array $subs) use ($ep, $p, $a, $email, $ua) {
        $subs[$ep] = ['p256dh' => $p, 'auth' => $a, 'email' => $email, 'ua' => $ua,
            'created_at' => $subs[$ep]['created_at'] ?? date('c'), 'fails' => 0];
        return $subs;
    });
    exit('{"ok":true}');
}
if ($op === 'unsub') {
    $ep = (string) ($in['endpoint'] ?? '');
    push_subs_update(function (array $subs) use ($ep, $email) {
        if (($subs[$ep]['email'] ?? '') === $email) {
            unset($subs[$ep]);
        }
        return $subs;
    });
    exit('{"ok":true}');
}
if ($op === 'test') {
    [$ok, $fail] = push_send(['title' => '戰情室測試通知', 'body' => '看到這則就表示這台裝置收得到。',
        'url' => '/', 'tag' => 'test'], 'test', $email);
    exit(json_encode(['ok' => $ok, 'fail' => $fail]));
}
http_response_code(400);
echo '{"error":"bad op"}';
