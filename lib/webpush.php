<?php
declare(strict_types=1);

/**
 * 戰情室網頁推播（Web Push，RFC 8291 aes128gcm＋RFC 8292 VAPID；Asana 1219443887250576）。
 * 參考大蘇 it.ccra.tw 的 lib/webpush.php 改寫（大蘇 10/13 信：CCRA 做法可直接參考）：
 * 加密與 VAPID 簽章照抄，儲存改成 state/ 底下的 JSON 檔（cora-lab 的 PHP 沒有 SQLite／不接資料庫）。
 *
 * 推播範圍（熊哥按「照建議範圍」，大蘇 10/13 信轉達，與 CCRA 對齊）：
 *   1. 斷線：Zabbix 嚴重以上（含解除）、對外站台連續 5 分鐘連不上（含恢復）
 *   2. 資安事件  3. 新申請單
 *
 * - VAPID 金鑰第一次用到時產生在 state/vapid.json（600，不進 git）。⚠️ 金鑰換掉，所有人的訂閱都會失效。
 * - 訂閱 state/push_subs.json、紀錄 state/push_log.jsonl；推送回 404／410 代表對方已取消，直接刪除。
 * - 檔案由 www-data 寫（網頁與 www-data 的 cron 都用同一個身分），寫入一律 flock。
 */

const PUSH_STATE_DIR = __DIR__ . '/../state';
const PUSH_SUBS_FILE = PUSH_STATE_DIR . '/push_subs.json';
const PUSH_LOG_FILE = PUSH_STATE_DIR . '/push_log.jsonl';
const VAPID_SUB = 'mailto:cora@ccnda.org';

/** 只收各瀏覽器官方推播服務的網址，不讓人把主機當成對任意網址發 POST 的跳板 */
const PUSH_HOSTS_RE = '/(^|\.)(googleapis\.com|mozilla\.com|push\.apple\.com|notify\.windows\.com)$/';

function wp_b64u(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function wp_unb64u(string $s): string { return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4)); }

/** 在 flock 保護下讀改寫訂閱表；$fn 拿到陣列、回傳新陣列（回傳 null＝不寫回） */
function push_subs_update(callable $fn): array
{
    $fp = fopen(PUSH_SUBS_FILE, 'c+');
    if ($fp === false) {
        throw new RuntimeException('打不開訂閱檔');
    }
    @chmod(PUSH_SUBS_FILE, 0600);
    try {
        flock($fp, LOCK_EX);
        $raw = stream_get_contents($fp);
        $subs = json_decode($raw !== false && $raw !== '' ? $raw : '{}', true);
        $subs = is_array($subs) ? $subs : [];
        $new = $fn($subs);
        if (is_array($new)) {
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($new, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            fflush($fp);
            $subs = $new;
        }
        return $subs;
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

function push_subs(): array
{
    return is_file(PUSH_SUBS_FILE) ? push_subs_update(fn($s) => null) : [];
}

/** 回傳 ['public' => base64url(65 bytes), 'pem' => 私鑰 PEM] */
function vapid_keys(): array
{
    $f = PUSH_STATE_DIR . '/vapid.json';
    if (is_file($f)) {
        return json_decode((string) file_get_contents($f), true);
    }
    $k = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    openssl_pkey_export($k, $pem);
    $d = openssl_pkey_get_details($k)['ec'];
    $v = ['public' => wp_b64u("\x04" . str_pad($d['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['y'], 32, "\0", STR_PAD_LEFT)),
          'pem' => $pem, 'created_at' => date('c')];
    $tmp = $f . '.tmp';
    file_put_contents($tmp, json_encode($v));
    chmod($tmp, 0600);
    rename($tmp, $f);
    return $v;
}

/** 65 bytes 未壓縮公鑰 → OpenSSL 可讀的公鑰 */
function wp_pub_from_raw(string $raw)
{
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $raw;
    return openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n");
}

/** openssl_sign 回 DER，JWT ES256 要 r||s 各 32 bytes */
function wp_der2raw(string $der): string
{
    $o = 2;
    if (ord($der[1]) & 0x80) {
        $o += ord($der[1]) & 0x7f;
    }
    $out = '';
    for ($i = 0; $i < 2; $i++) {
        $len = ord($der[$o + 1]);
        $n = substr($der, $o + 2, $len);
        $o += 2 + $len;
        $out .= str_pad(ltrim($n, "\0"), 32, "\0", STR_PAD_LEFT);
    }
    return $out;
}

/** $eph／$salt 只給測試用（RFC 8291 附錄 A 的固定值），正式呼叫一律隨機產生 */
function wp_encrypt(string $payload, string $p256dh, string $auth, $eph = null, ?string $salt = null): string
{
    $ua = wp_unb64u($p256dh);
    $authSecret = wp_unb64u($auth);
    if (strlen($ua) !== 65 || strlen($authSecret) !== 16) {
        throw new InvalidArgumentException('bad subscription keys');
    }
    $eph = $eph ?: openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    $e = openssl_pkey_get_details($eph)['ec'];
    $as = "\x04" . str_pad($e['x'], 32, "\0", STR_PAD_LEFT) . str_pad($e['y'], 32, "\0", STR_PAD_LEFT);
    $shared = openssl_pkey_derive(wp_pub_from_raw($ua), $eph, 32);
    if ($shared === false) {
        throw new RuntimeException('ECDH failed');
    }
    $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $ua . $as, $authSecret);
    $salt = $salt ?? random_bytes(16);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
    $ct = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
    return $salt . pack('N', 4096) . chr(65) . $as . $ct . $tag;
}

function wp_vapid_header(string $endpoint): string
{
    $v = vapid_keys();
    $u = parse_url($endpoint);
    $h = wp_b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $c = wp_b64u(json_encode(['aud' => $u['scheme'] . '://' . $u['host'], 'exp' => time() + 12 * 3600, 'sub' => VAPID_SUB]));
    openssl_sign("$h.$c", $sig, $v['pem'], OPENSSL_ALGO_SHA256);
    return 'vapid t=' . "$h.$c." . wp_b64u(wp_der2raw($sig)) . ', k=' . $v['public'];
}

/** 送給一筆訂閱，回傳 HTTP 狀態碼（0 = 連線失敗） */
function wp_send_one(array $sub, string $payload, int $ttl = 3600): int
{
    $body = wp_encrypt($payload, $sub['p256dh'], $sub['auth']);
    $ch = curl_init($sub['endpoint']);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Content-Type: application/octet-stream', 'Content-Encoding: aes128gcm', "TTL: $ttl",
            'Urgency: high', 'Authorization: ' . wp_vapid_header($sub['endpoint'])]]);
    curl_exec($ch);
    return (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
}

/** 訂閱是否合法（網址在白名單、金鑰長度對） */
function push_sub_valid(string $ep, string $p256dh, string $auth): bool
{
    $host = (string) (parse_url($ep, PHP_URL_HOST) ?: '');
    return str_starts_with($ep, 'https://') && preg_match(PUSH_HOSTS_RE, $host) === 1
        && strlen(wp_unb64u($p256dh)) === 65 && strlen(wp_unb64u($auth)) === 16;
}

/**
 * 推給所有訂閱（或指定 email 的訂閱）。回傳 [成功數, 失敗數]。
 * $msg：title, body, url（點通知開哪頁）, tag（同 tag 的新通知會取代舊的，例如同一個站台）
 */
function push_send(array $msg, string $kind = 'manual', ?string $email = null): array
{
    $payload = json_encode(['title' => $msg['title'] ?? '戰情室', 'body' => $msg['body'] ?? '', 'url' => $msg['url'] ?? '/',
        'tag' => $msg['tag'] ?? null], JSON_UNESCAPED_UNICODE);
    $ok = $fail = 0;
    $result = [];
    foreach (push_subs() as $ep => $sub) {
        if ($email !== null && ($sub['email'] ?? '') !== strtolower($email)) {
            continue;
        }
        try {
            $code = wp_send_one($sub + ['endpoint' => $ep], $payload);
        } catch (Throwable $e) {
            error_log('[ops] webpush: ' . $e->getMessage());
            $code = 0;
        }
        $result[$ep] = $code;
        $code >= 200 && $code < 300 ? $ok++ : $fail++;
        if ($code !== 0 && ($code < 200 || $code >= 300) && $code !== 404 && $code !== 410) {
            error_log("[ops] webpush: HTTP $code for " . ($sub['email'] ?? '?'));
        }
    }
    if ($result) {
        push_subs_update(function (array $subs) use ($result) {
            foreach ($result as $ep => $code) {
                if (!isset($subs[$ep])) {
                    continue;
                }
                if ($code >= 200 && $code < 300) {
                    $subs[$ep]['last_ok_at'] = date('c');
                    $subs[$ep]['fails'] = 0;
                } elseif ($code === 404 || $code === 410) { // 對方已取消訂閱或過期
                    unset($subs[$ep]);
                } else {
                    $subs[$ep]['fails'] = (int) ($subs[$ep]['fails'] ?? 0) + 1;
                }
            }
            return $subs;
        });
    }
    @file_put_contents(PUSH_LOG_FILE, json_encode(['at' => date('c'), 'kind' => $kind, 'title' => $msg['title'] ?? '',
        'body' => $msg['body'] ?? '', 'ok' => $ok, 'fail' => $fail], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
    return [$ok, $fail];
}
