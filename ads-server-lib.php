<?php
/* FOMAXO — tells Meta and TikTok about each order straight from the server (Meta Conversions API, TikTok Events API).
   The website already reports a purchase from the shopper's browser, but ad blockers, iPhone privacy settings and card payments
   that come back from Ziina lose some of them. This sends the same purchase again from fomaxo.com with the same event id
   ("order-FMX-1001"), so Meta and TikTok count it once and keep it even when the browser one never arrived.
   Pixel IDs: admin → Settings → Ads tracking. Access tokens: same card, kept in fomaxo-ads-tokens.php ONE LEVEL ABOVE public_html
   (never on GitHub, never public, never shown again). Name, mobile and email are sent only as one-way codes (SHA-256), as Meta and TikTok ask. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }
require_once __DIR__ . '/orders-lib.php';

const FX_META_API = 'https://graph.facebook.com/v23.0';
const FX_TIKTOK_API = 'https://business-api.tiktok.com/open_api/v1.3/event/track/';

function fomaxo_ads_tokens_file() { return dirname(__DIR__) . '/fomaxo-ads-tokens.php'; }
function fomaxo_ads_tokens() {
  $f = fomaxo_ads_tokens_file();
  $t = is_file($f) ? require $f : null;
  return array_filter(array_intersect_key(is_array($t) ? $t : [], ['meta' => 1, 'tiktok' => 1]), fn($v) => is_string($v) && $v !== '');
}
/* $new: ['meta' => token or '' to remove, 'tiktok' => …]; a key that is not given keeps the saved token */
function fomaxo_ads_tokens_save(array $new) {
  $t = array_merge(fomaxo_ads_tokens(), $new);
  $t = array_filter($t, fn($v) => is_string($v) && $v !== '');
  $f = fomaxo_ads_tokens_file();
  if (!$t) return @unlink($f) || !is_file($f);
  $ok = @file_put_contents($f, "<?php\n// Meta and TikTok access tokens for sending orders to ads (written by fomaxo.com/admin → Settings)\nreturn " . var_export($t, true) . ";\n", LOCK_EX) !== false;
  if ($ok) @chmod($f, 0600);
  return $ok;
}

/* the last result per platform, shown in admin under each token box: ['meta' => ['at' => …, 'no' => …, 'ok' => bool, 'msg' => …], …] */
function fomaxo_ads_last($pdo) { return json_decode((string)fomaxo_setting($pdo, 'ads_sent'), true) ?: []; }

function fomaxo_ads_post($url, $body, $headers) {
  $ch = curl_init($url);
  curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), CURLOPT_RETURNTRANSFER => true,
                          CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers)]);
  $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
  return [$code, $res ? json_decode($res, true) : null, $err ?: (string)$res];
}

/* Sends order $no (FMX-…) as a purchase to every platform that has both a pixel ID and an access token. Call it after the shopper has their answer
   (fomaxo_reply_now), from the request of the shopper's own browser, so their ad cookies, browser and IP go with it. Never throws. */
function fomaxo_ads_server_buy($no) {
  try {
    $pdo = fomaxo_db(); if (!$pdo || !preg_match('/^FMX-\d+$/', (string)$no)) return;
    $ids = json_decode((string)fomaxo_setting($pdo, 'ads'), true) ?: [];
    $tok = fomaxo_ads_tokens();
    $go = array_filter(['meta' => ($ids['meta'] ?? '') !== '' && isset($tok['meta']), 'tiktok' => ($ids['tiktok'] ?? '') !== '' && isset($tok['tiktok'])]);
    if (!$go) return;
    $s = $pdo->prepare('SELECT * FROM fx_orders WHERE order_no = ?'); $s->execute([$no]); $o = $s->fetch(PDO::FETCH_ASSOC);
    if (!$o || !empty($o['test'])) return;   // Ziina test payments are not real sales

    $h = fn($v) => ($v = mb_strtolower(trim((string)$v))) === '' ? null : hash('sha256', $v);
    $digits = preg_replace('/\D/', '', strtr((string)$o['phone'], FX_AR_DIGITS));
    $phone = preg_match('/^0?5\d{8}$/', $digits) ? '971' . substr($digits, -9) : ltrim($digits, '0');   // UAE mobile 05x… → 9715x…
    $name = preg_split('/\s+/u', trim((string)$o['name']), 2);
    $ip = null; if (is_file(__DIR__ . '/geo-lib.php')) { require_once __DIR__ . '/geo-lib.php'; $ip = fomaxo_geo_ip(); }
    $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 400);
    $ck = fn($k) => preg_match('/^[\w.\-]{1,300}$/', (string)($_COOKIE[$k] ?? '')) ? $_COOKIE[$k] : null;
    $host = preg_replace('/^www\./', '', preg_replace('/[^A-Za-z0-9.\-]/', '', explode(':', $_SERVER['HTTP_HOST'] ?? 'fomaxo.com')[0])) ?: 'fomaxo.com';
    $url = "https://$host/#/checkout";
    $lines = array_values(array_filter((array)json_decode((string)$o['lines_json'], true), fn($l) => empty($l['free']) && ($l['id'] ?? '') !== ''));
    $qty = []; foreach ($lines as $l) $qty[$l['id']] = ($qty[$l['id']] ?? 0) + max(1, (int)($l['qty'] ?? 1));
    $value = round((float)$o['total'], 2); $event = 'order-' . $no; $when = time();
    $out = [];

    if (isset($go['meta'])) {
      $ud = array_filter(['em' => $h($o['email']) ? [$h($o['email'])] : null, 'ph' => $phone !== '' ? [hash('sha256', $phone)] : null,
                          'fn' => $h($name[0] ?? '') ? [$h($name[0])] : null, 'ln' => $h($name[1] ?? '') ? [$h($name[1])] : null,
                          'country' => [hash('sha256', 'ae')], 'client_ip_address' => $ip, 'client_user_agent' => $ua ?: null, 'fbp' => $ck('_fbp'), 'fbc' => $ck('_fbc')]);
      $ev = ['event_name' => 'Purchase', 'event_time' => $when, 'event_id' => $event, 'action_source' => 'website', 'event_source_url' => $url, 'user_data' => $ud,
             'custom_data' => ['currency' => 'AED', 'value' => $value, 'order_id' => $no, 'content_type' => 'product', 'content_ids' => array_keys($qty),
                               'contents' => array_map(fn($id, $n) => ['id' => $id, 'quantity' => $n], array_keys($qty), $qty), 'num_items' => array_sum($qty)]];
      [$code, $r, $raw] = fomaxo_ads_post(FX_META_API . '/' . rawurlencode($ids['meta']) . '/events?access_token=' . rawurlencode($tok['meta']), ['data' => [$ev]], []);
      $ok = $code === 200 && (int)($r['events_received'] ?? 0) > 0;
      $out['meta'] = ['ok' => $ok, 'msg' => $ok ? '' : mb_substr((string)($r['error']['message'] ?? ($code ? "HTTP $code" : $raw)), 0, 160)];
    }
    if (isset($go['tiktok'])) {
      $user = array_filter(['email' => $h($o['email']), 'phone' => $phone !== '' ? hash('sha256', '+' . $phone) : null, 'ip' => $ip, 'user_agent' => $ua ?: null,
                            'ttp' => $ck('_ttp'), 'ttclid' => $ck('ttclid')]);
      $ev = ['event' => 'CompletePayment', 'event_time' => $when, 'event_id' => $event, 'user' => $user, 'page' => ['url' => $url],
             'properties' => ['currency' => 'AED', 'value' => $value, 'order_id' => $no, 'content_type' => 'product',
                              'contents' => array_map(fn($id, $n) => ['content_id' => $id, 'quantity' => $n], array_keys($qty), $qty)]];
      [$code, $r, $raw] = fomaxo_ads_post(FX_TIKTOK_API, ['event_source' => 'web', 'event_source_id' => $ids['tiktok'], 'data' => [$ev]], ['Access-Token: ' . $tok['tiktok']]);
      $ok = $code === 200 && (int)($r['code'] ?? -1) === 0;
      $out['tiktok'] = ['ok' => $ok, 'msg' => $ok ? '' : mb_substr((string)($r['message'] ?? ($code ? "HTTP $code" : $raw)), 0, 160)];
    }
    $last = fomaxo_ads_last($pdo); date_default_timezone_set('Asia/Dubai');
    foreach ($out as $k => $v) { $last[$k] = $v + ['at' => date('Y-m-d H:i'), 'no' => $no]; if (!$v['ok']) error_log("FOMAXO ads server $k $no: {$v['msg']}"); }
    fomaxo_setting($pdo, 'ads_sent', json_encode($last, JSON_UNESCAPED_UNICODE));
  } catch (Throwable $e) { error_log('FOMAXO ads server: ' . $e->getMessage()); }
}
