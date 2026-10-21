<?php
/* FOMAXO — Ziina card payments, the parts used by ziina.php AND by the server's own re-check of card payments that were
   still waiting (ziina_sweep, run by products.php and track.php after the visitor has their answer, at most once a minute).
   Why the re-check: a shopper who pays on Ziina's page but closes it (or the Instagram app) before coming back to fomaxo.com
   never reaches the thank-you page, so the order would stay "Awaiting payment" with no email and no stock change. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }

const ZIINA_STORE_EMAIL = 'fomaxoasset@gmail.com';

/* ---- Ziina-config.php: found by name in any case (Ziina-config.php, ziina-config.php, ziina_config.php …),
   first one level above public_html (domains/fomaxo.com/), then public_html, then the folders above.
   The file may return an array (['access_token' => '…']) or set a variable or constant ($token = '…' / define('ZIINA_TOKEN', '…')). ---- */
function ziina_find_config() {
  foreach ([dirname(__DIR__), __DIR__, dirname(__DIR__, 2), dirname(__DIR__, 3)] as $d) {
    $names = @scandir($d) ?: [];
    foreach ($names as $n) if (preg_match('/^ziina[\s_\-]?config.*\.php$/i', $n) && !preg_match('/example/i', $n) && is_file("$d/$n")) return "$d/$n";
  }
  return null;
}
function ziina_load_config($file) {
  $consts = array_keys(get_defined_constants(true)['user'] ?? []);
  $ret = require $file;
  $vars = get_defined_vars(); unset($vars['file'], $vars['consts'], $vars['ret']);
  $new = array_diff_key(get_defined_constants(true)['user'] ?? [], array_flip($consts));
  $all = (is_array($ret) ? $ret : []) + $vars + $new;
  if (is_string($ret) && trim($ret) !== '') $all['token'] = $ret;
  $cfg = [];
  foreach ($all as $k => $v) {
    if (is_array($v)) { foreach ($v as $k2 => $v2) if (!isset($cfg[strtolower($k2)])) $cfg[strtolower($k2)] = $v2; continue; }
    $cfg[strtolower((string)$k)] = $v;
  }
  return $cfg;
}

/* the Ziina settings: [config file or null, access token ('' = not set up), test mode, API address] */
function ziina_settings() {
  static $done = null;   // the config file is read once per request (it may define constants)
  if ($done) return $done;
  $API = 'https://api-v2.ziina.com/api';
  $cfgFile = ziina_find_config();
  $cfg = $cfgFile ? ziina_load_config($cfgFile) : [];
  $token = '';
  foreach (['access_token', 'api_key', 'apikey', 'token', 'secret_key', 'secret', 'key', 'ziina_access_token', 'ziina_api_key', 'ziina_token', 'ziina_key', 'ziina_secret'] as $k) {
    if ($token === '' && is_string($cfg[$k] ?? null)) $token = trim($cfg[$k]);
  }
  if ($token === '') foreach ($cfg as $k => $v) { if (is_string($v) && preg_match('/token|key|secret/', $k) && strlen(trim($v)) >= 20) { $token = trim($v); break; } }
  if (stripos($token, 'PASTE') === 0 || stripos($token, 'YOUR_') === 0) $token = '';
  $t = $cfg['test'] ?? $cfg['test_mode'] ?? $cfg['ziina_test'] ?? false;
  $testMode = $t === true || $t === 1 || in_array(strtolower((string)$t), ['1', 'true', 'yes', 'on'], true);
  if (is_string($cfg['api'] ?? null) && $cfg['api'] !== '') $API = rtrim($cfg['api'], '/');   // only for testing against a fake Ziina server
  return $done = [$cfgFile, $token, $testMode, $API];
}

function ziina_call($method, $url, $token, $body = null) {
  $ch = curl_init($url);
  $opts = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25,
           CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json', 'Accept: application/json']];
  if ($body !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($body);
  curl_setopt_array($ch, $opts);
  $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
  return [$code, $res ? json_decode($res, true) : null, $err ?: $res];
}
function ziina_dir() { $d = dirname(__DIR__) . '/fomaxo-orders/ziina'; if (!is_dir($d)) @mkdir($d, 0700, true); return $d; }

/* asks Ziina for the real status of one payment; the first time it is paid, records the order (FMX number, stock, coupon,
   emails, ads) exactly once. $reply: answer the shopper's browser first (thank-you page), then send the emails. */
function ziina_verify($id, $reply = false) {
  global $CATALOG;
  [, $token, , $API] = ziina_settings();
  [$code, $pi, $raw] = ziina_call('GET', "$API/payment_intent/" . rawurlencode($id), $token);
  if ($code !== 200 || !is_array($pi)) { error_log('FOMAXO Ziina verify error: ' . $raw); return null; }
  $status = $pi['status'] ?? '';
  $file = ziina_dir() . "/$id.json";
  $lock = is_file($file) ? fopen($file, 'r+') : false;
  if ($lock) flock($lock, LOCK_EX);   // the thank-you page and the server's own re-check can ask at the same moment: only one records the order
  $rec = is_file($file) ? json_decode(file_get_contents($file), true) : null;
  $out = ['status' => $status === 'completed' ? 'paid' : (in_array($status, ['failed', 'canceled'], true) ? 'failed' : 'processing')];
  if ($rec) { $out += ['order' => $rec['no'], 'total' => $rec['totalFils'] / 100, 'name' => $rec['cust']['name'], 'email' => $rec['cust']['email']] + (!empty($rec['ship']) ? ['sd' => 1] : []); }

  // first time we see it paid → record it and send the emails (only once)
  if ($status === 'completed' && $rec && empty($rec['paid'])) {
    $paidFils = (int)($pi['amount'] ?? 0);
    $rec['paid'] = date('Y-m-d H:i');
    require_once __DIR__ . '/orders-lib.php';
    $rec['no'] = fomaxo_order_number($rec['no']);   // paid: now it is an order and gets its FMX number
    $out['order'] = $rec['no'];
    require_once __DIR__ . '/whatsapp-lib.php';   // private "review your order" link → Verified Purchaser reviews, asked for on WhatsApp
    if (!empty($rec['pids'])) $rec['review'] = fomaxo_review_link($rec['no'], $rec['pids']);
    if ($lock) { ftruncate($lock, 0); rewind($lock); fwrite($lock, json_encode($rec)); fflush($lock); flock($lock, LOCK_UN); fclose($lock); $lock = false; }
    else file_put_contents($file, json_encode($rec), LOCK_EX);
    $c = $rec['cust']; $total = fomaxo_aed($rec['totalFils']);
    $waLines = fomaxo_wa_review_request($rec['no'], $c, array_map(fn($id) => $CATALOG[$id]['name'] ?? '', (array)($rec['pids'] ?? [])), $rec['review'] ?? null);
    $note = $paidFils && $paidFils !== (int)$rec['totalFils'] ? ' (AMOUNT MISMATCH: paid ' . fomaxo_aed($paidFils) . ')' : '';
    require_once __DIR__ . '/orders-lib.php';
    fomaxo_order_paid($rec['no'], $note !== '' ? trim($note) : '');
    fomaxo_stock_move($rec['no']);   // stock goes down once the card payment is confirmed
    if (!empty($rec['coupon'])) fx_coupon_spent(fomaxo_db(), $rec['coupon']);   // a used-up one-use coupon deletes itself (the order keeps the code)
    fomaxo_log_order([date('Y-m-d H:i'), $rec['no'], 'Ziina — PAID' . $note, $total, $c['name'], $c['phone'], $c['email'], $c['emirate'], $c['address'], $c['note'], implode(' | ', $rec['summary'])]);
    /* the paid order is recorded: show the shopper their confirmation now, the emails go out right after */
    if (!empty($rec['review'])) $out['review'] = $rec['review'];
    if ($reply) { fomaxo_reply_now($out); $out['replied'] = true; }
    require_once __DIR__ . '/ads-server-lib.php'; fomaxo_ads_server_buy($rec['no']);   // the purchase also goes to Meta / TikTok ads from here (admin → Settings → Ads tracking)
    $host = preg_replace('/^www\./', '', preg_replace('/[^A-Za-z0-9.\-]/', '', $_SERVER['HTTP_HOST'] ?? 'fomaxo.com'));
    $from = "FOMAXO <mail@fomaxo.com>";
    fomaxo_en_many([$c['name'], $c['address'], $c['note']]);   // Arabic typed by the customer: English in this email and in admin
    $body = "NEW PAID ORDER (Ziina)  {$rec['no']}$note\n" . date('d M Y, H:i') . " (Dubai)\nZiina payment: $id\n\n" . implode("\n", $rec['rows']) . "\n\n"
          . "TOTAL PAID: $total\n" . (!empty($rec['ship']) ? $rec['ship'] : 'Delivery: Free') . "\n\nName: " . fomaxo_en_both($c['name']) . "\nPhone: {$c['phone']}\n" . ($c['email'] !== '' ? "Email: {$c['email']}\n" : '') . 'Address: ' . fomaxo_en_both($c['address']) . ", {$c['emirate']}, UAE\n" . ($c['note'] ? 'Note: ' . fomaxo_en_both($c['note']) . "\n" : '') . $waLines;
    fomaxo_mail(fomaxo_orders_email(ZIINA_STORE_EMAIL), '=?UTF-8?B?' . base64_encode((!empty($rec['ship']) ? 'SAME-DAY ' : '') . "New paid order {$rec['no']} — $total") . '?=', $body, "From: $from\r\n" . ($c['email'] !== '' ? "Reply-To: {$c['email']}\r\n" : '') . "Content-Type: text/plain; charset=UTF-8");
    $cb = "Thank you for your order, {$c['name']}.\n\nOrder number: {$rec['no']}\n\n" . implode("\n", $rec['rows']) . "\n\nTotal paid: $total\n" . (!empty($rec['ship']) ? $rec['ship'] . ', to ' : 'Delivery: Free, to ') . "{$c['address']}, {$c['emirate']}\n\n"
        . "We will call or WhatsApp you on {$c['phone']} to arrange your delivery.\n\n"
        . (!empty($rec['review']) ? "Once your order arrives, we would love your honest review (it will show Verified Purchaser):\nhttps://$host/#/review?t={$rec['review']}\n\n" : '')
        . "FOMAXO\nhttps://$host";
    if ($c['email'] !== '') fomaxo_mail($c['email'], '=?UTF-8?B?' . base64_encode("Your FOMAXO order {$rec['no']}") . '?=', $cb, "From: $from\r\nReply-To: " . fomaxo_orders_email(ZIINA_STORE_EMAIL) . "\r\nContent-Type: text/plain; charset=UTF-8");
  }
  if ($status === 'completed' && !empty($rec['review'])) $out['review'] = $rec['review'];
  if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
  if ($rec && empty($rec['paid']) && in_array($status, ['failed', 'canceled'], true) && empty($rec['closed'])) {   // not paid in the end: the re-check stops asking about it
    $rec['closed'] = $status; file_put_contents($file, json_encode($rec), LOCK_EX);
  }
  return $out;
}

/* the re-check: every card payment from the last 3 days that is not paid and not failed/cancelled is asked about again.
   ziina_sweep_due() is cheap (one file date, then a folder list once a minute) so products.php and track.php can ask it on every call. */
function ziina_waiting() {
  $out = [];
  foreach (glob(ziina_dir() . '/*.json') ?: [] as $f) {
    $t = filemtime($f);
    if ($t < time() - 3 * 86400 || $t > time() - 60) continue;   // older than 3 days, or opened under a minute ago
    $rec = json_decode((string)@file_get_contents($f), true);
    if (is_array($rec) && empty($rec['paid']) && empty($rec['closed'])) $out[] = basename($f, '.json');
  }
  return $out;
}
function ziina_sweep_due() {
  $stamp = ziina_dir() . '/.recheck';
  if (is_file($stamp) && time() - filemtime($stamp) < 60) return false;
  if (!ziina_waiting()) { @touch($stamp); return false; }
  return true;
}
function ziina_sweep() {
  @touch(ziina_dir() . '/.recheck');
  [, $token] = ziina_settings();
  if ($token === '') return;
  date_default_timezone_set('Asia/Dubai');
  ignore_user_abort(true);
  foreach (ziina_waiting() as $id) {
    try { ziina_verify($id); } catch (Throwable $e) { error_log('FOMAXO Ziina re-check: ' . $e->getMessage()); }
  }
}

/* the shopper is sent to Ziina's card page: they show in admin → Analytics → Left at checkout at "Card payment page" straight away,
   from the server (the website's own note is sent just before it leaves for Ziina and can be lost). Their latest checkout line from the
   last hour (same mobile) moves to that step, else a new line. A paid order hides it there (same mobile, status Paid). */
function ziina_lead_card($cust, $order) {
  $pdo = fomaxo_db(); $p9 = fomaxo_phone9((string)$cust['phone']);
  if (!$pdo || $p9 === '') return;
  try {
    $total = round($order['totalFils'] / 100, 2); $items = mb_substr(implode(', ', $order['summary']), 0, 600);
    $s = $pdo->prepare("UPDATE fx_leads SET stage = 'card', updated_at = NOW(), total = ?, items = ? WHERE order_no IS NULL AND updated_at > NOW() - INTERVAL 1 HOUR
                        AND RIGHT(REGEXP_REPLACE(phone, '[^0-9]', ''), 9) = ? ORDER BY updated_at DESC LIMIT 1");
    $s->execute([$total, $items, $p9]);
    if ($s->rowCount()) return;
    $sid = substr(md5('ziina' . $cust['phone'] . microtime()), 0, 16);
    $pdo->prepare("INSERT INTO fx_leads (sid, vid, created_at, updated_at, stage, name, phone, email, emirate, items, total, address) VALUES (?, ?, NOW(), NOW(), 'card', ?, ?, ?, ?, ?, ?, ?)")
        ->execute([$sid, $sid, mb_substr($cust['name'], 0, 80), mb_substr($cust['phone'], 0, 25), mb_substr($cust['email'], 0, 120), mb_substr($cust['emirate'], 0, 30), $items, $total, mb_substr($cust['address'], 0, 300)]);
  } catch (Throwable $e) { error_log('FOMAXO Ziina lead: ' . $e->getMessage()); }
}
