<?php
/* FOMAXO — card / Apple Pay / Google Pay through Ziina (hosted checkout, Payment Intent API).
   POST  ziina.php            → checks prices on the server, creates a Ziina Payment Intent, returns the payment page URL
   GET   ziina.php?verify=ID  → after payment, asks Ziina for the real status and records the paid order
   GET   ziina.php?status     → set-up check: is Ziina-config.php found and does it have a token (never shows the token)
   The access token lives in Ziina-config.php (or ziina-config.php) ONE LEVEL ABOVE public_html (never on GitHub, never public). */
header('Cache-Control: no-store');

/* ---------------- 0) customer comes back from Ziina's page → send them to the right page of the site ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['return'])) {
  $r   = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$_GET['return']);
  $buy = ($_GET['src'] ?? '') === 'buy';
  $to  = $r === 'cancel' || $r === 'failed' ? "#/checkout?ziina=$r" . ($buy ? '&buy=1' : '')
       : '#/thank-you?ziina=' . rawurlencode($r) . ($buy ? '&src=buy' : '');
  header('Location: ./' . $to, true, 302); exit;
}

header('Content-Type: application/json');
require __DIR__ . '/store-lib.php';

$STORE_EMAIL = 'fomaxoasset@gmail.com';
$MIN_ORDER   = 30;   // AED — keep in sync with index.html (minOrder)
$API         = 'https://api-v2.ziina.com/api';

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

/* ---- set-up check: open fomaxo.com/ziina.php?status in a browser (shows only found / not found, never the token) ---- */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['status'])) {
  $home = dirname(__DIR__, 3);
  echo json_encode(['config_file' => $cfgFile ? 'found: ' . ltrim(substr($cfgFile, strlen($home)), '/') : 'NOT FOUND — put Ziina-config.php in domains/fomaxo.com (next to public_html)',
                    'access_token' => $token !== '' ? 'found' : 'NOT FOUND in the file',
                    'mode' => $token === '' ? '-' : ($testMode ? 'test (no real money)' : 'live')], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  exit;
}
if ($token === '') { error_log('FOMAXO Ziina: ' . ($cfgFile ? "no access token in $cfgFile" : 'Ziina-config.php not found')); http_response_code(500); echo json_encode(['error' => 'Card payments are not set up yet. Please choose cash on delivery.']); exit; }
if (is_string($cfg['api'] ?? null) && $cfg['api'] !== '') $API = rtrim($cfg['api'], '/');   // only for testing against a fake Ziina server

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

date_default_timezone_set('Asia/Dubai');

/* ---------------- 2) customer is back from Ziina: confirm the payment ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['verify'])) {
  $id = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$_GET['verify']);
  if ($id === '') { http_response_code(400); echo json_encode(['error' => 'Missing payment reference.']); exit; }
  [$code, $pi, $raw] = ziina_call('GET', "$API/payment_intent/" . rawurlencode($id), $token);
  if ($code !== 200 || !is_array($pi)) { error_log('FOMAXO Ziina verify error: ' . $raw); http_response_code(502); echo json_encode(['error' => 'We could not confirm your payment yet. Please contact us on WhatsApp.']); exit; }

  $status = $pi['status'] ?? '';
  $file = ziina_dir() . "/$id.json";
  $rec = is_file($file) ? json_decode(file_get_contents($file), true) : null;
  $out = ['status' => $status === 'completed' ? 'paid' : (in_array($status, ['failed', 'canceled'], true) ? 'failed' : 'processing')];
  if ($rec) { $out += ['order' => $rec['no'], 'total' => $rec['totalFils'] / 100, 'name' => $rec['cust']['name'], 'email' => $rec['cust']['email']]; }

  // first time we see it paid → record it and send the emails (only once)
  if ($status === 'completed' && $rec && empty($rec['paid'])) {
    $paidFils = (int)($pi['amount'] ?? 0);
    $rec['paid'] = date('Y-m-d H:i');
    require_once __DIR__ . '/whatsapp-lib.php';   // private "review your order" link → Verified Purchaser reviews, asked for on WhatsApp
    if (!empty($rec['pids'])) $rec['review'] = fomaxo_review_link($rec['no'], $rec['pids']);
    file_put_contents($file, json_encode($rec), LOCK_EX);
    $c = $rec['cust']; $total = fomaxo_aed($rec['totalFils']);
    $waLines = fomaxo_wa_review_request($rec['no'], $c, array_map(fn($id) => $CATALOG[$id]['name'] ?? '', (array)($rec['pids'] ?? [])), $rec['review'] ?? null);
    $note = $paidFils && $paidFils !== (int)$rec['totalFils'] ? ' (AMOUNT MISMATCH: paid ' . fomaxo_aed($paidFils) . ')' : '';
    require_once __DIR__ . '/orders-lib.php';
    fomaxo_order_paid($rec['no'], $note !== '' ? trim($note) : '');
    fomaxo_stock_move($rec['no']);   // stock goes down once the card payment is confirmed
    fomaxo_log_order([date('Y-m-d H:i'), $rec['no'], 'Ziina — PAID' . $note, $total, $c['name'], $c['phone'], $c['email'], $c['emirate'], $c['address'], $c['note'], implode(' | ', $rec['summary'])]);
    $host = preg_replace('/^www\./', '', preg_replace('/[^A-Za-z0-9.\-]/', '', $_SERVER['HTTP_HOST'] ?? 'fomaxo.com'));
    $from = "FOMAXO <mail@fomaxo.com>";
    $body = "NEW PAID ORDER (Ziina)  {$rec['no']}$note\n" . date('d M Y, H:i') . " (Dubai)\nZiina payment: $id\n\n" . implode("\n", $rec['rows']) . "\n\n"
          . "TOTAL PAID: $total\nDelivery: Free\n\nName: {$c['name']}\nPhone: {$c['phone']}\n" . ($c['email'] !== '' ? "Email: {$c['email']}\n" : '') . "Address: {$c['address']}, {$c['emirate']}, UAE\n" . ($c['note'] ? "Note: {$c['note']}\n" : '') . $waLines;
    @mail(fomaxo_orders_email($STORE_EMAIL), '=?UTF-8?B?' . base64_encode("New paid order {$rec['no']} — $total") . '?=', $body, "From: $from\r\n" . ($c['email'] !== '' ? "Reply-To: {$c['email']}\r\n" : '') . "Content-Type: text/plain; charset=UTF-8", '-fmail@fomaxo.com');
    $cb = "Thank you for your order, {$c['name']}.\n\nOrder number: {$rec['no']}\n\n" . implode("\n", $rec['rows']) . "\n\nTotal paid: $total\nDelivery: Free, to {$c['address']}, {$c['emirate']}\n\n"
        . "We will call or WhatsApp you on {$c['phone']} to arrange your delivery.\n\n"
        . (!empty($rec['review']) ? "Once your order arrives, we would love your honest review (it will show Verified Purchaser):\nhttps://$host/#/review?t={$rec['review']}\n\n" : '')
        . "FOMAXO\nhttps://$host";
    if ($c['email'] !== '') @mail($c['email'], '=?UTF-8?B?' . base64_encode("Your FOMAXO order {$rec['no']}") . '?=', $cb, "From: $from\r\nReply-To: " . fomaxo_orders_email($STORE_EMAIL) . "\r\nContent-Type: text/plain; charset=UTF-8", '-fmail@fomaxo.com');
  }
  if ($status === 'completed' && !empty($rec['review'])) $out['review'] = $rec['review'];
  echo json_encode($out); exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit; }

/* ---------------- 1) create the payment ---------------- */
$in = json_decode(file_get_contents('php://input'), true) ?: [];
$order = fomaxo_price_order($in);
if (isset($order['error'])) { http_response_code(400); echo json_encode(['error' => $order['error']]); exit; }
$cust = fomaxo_customer($in);
if (isset($cust['error'])) { http_response_code(400); echo json_encode(['error' => $cust['error']]); exit; }
if ($order['totalFils'] < $MIN_ORDER * 100) { http_response_code(400); echo json_encode(['error' => "Minimum order is AED $MIN_ORDER."]); exit; }

/* order database: gives the counting order number (FMX-1001 …); the old random number is only used if the database is down */
require_once __DIR__ . '/orders-lib.php';
$lineIn = array_map(fn($l) => ['id' => (string)($l['id'] ?? ''), 'opt' => (string)($l['opt'] ?? ''), 'qty' => (int)($l['qty'] ?? 0), 'picks' => array_values((array)($l['picks'] ?? []))], (array)($in['lines'] ?? []));
if ($msg = fomaxo_stock_problem($lineIn, $CATALOG)) { http_response_code(409); echo json_encode(['error' => $msg]); exit; }
if ($order['gift']) foreach ($CATALOG as $cid => $c) if ($c['name'] === $order['gift']) { $lineIn[] = ['id' => $cid, 'opt' => '10', 'qty' => 1, 'free' => true]; break; }
$no = fomaxo_save_order(['payment' => 'Card (Ziina)', 'status' => 'Awaiting payment', 'subtotal' => $order['fullFils'] / 100, 'discount' => $order['discountFils'] / 100,
        'fee' => 0, 'total' => $order['totalFils'] / 100, 'items' => implode(' | ', $order['summary']), 'free_mini' => $order['gift'], 'lines' => $lineIn, 'test' => $testMode]
        + array_intersect_key($cust, array_flip(['name', 'phone', 'email', 'emirate', 'building', 'room', 'street', 'area', 'address', 'note'])))
      ?? 'FMX-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$host = preg_replace('/[^A-Za-z0-9.\-:]/', '', $_SERVER['HTTP_HOST'] ?? '');
$dir  = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
$base = ($https ? 'https' : 'http') . "://$host$dir/";

$back = $base . 'ziina.php?return=';
$src  = ($in['src'] ?? '') === 'buy' ? '&src=buy' : '';
$count = array_sum(array_map(fn($it) => $it['qty'], $order['items']));
[$code, $pi, $raw] = ziina_call('POST', "$API/payment_intent", $token, [
  'amount'        => $order['totalFils'],          // AED in fils (base units)
  'currency_code' => 'AED',
  'message'       => mb_substr("FOMAXO order $no · $count item" . ($count > 1 ? 's' : '') . ($order['gift'] ? " + FREE 10ml {$order['gift']} mini" : ''), 0, 200),
  'success_url'   => $back . '{PAYMENT_INTENT_ID}' . $src,
  'cancel_url'    => $back . 'cancel' . $src,
  'failure_url'   => $back . 'failed' . $src,
  'test'          => $testMode,
  'allow_tips'    => false,
]);
if (!in_array($code, [200, 201], true) || empty($pi['redirect_url']) || empty($pi['id'])) {
  error_log('FOMAXO Ziina create error: ' . $raw);
  fomaxo_order_set($no, ['status' => 'Cancelled', 'admin_note' => 'Card payment page could not be opened.']);
  http_response_code(502); echo json_encode(['error' => 'Card payment is unavailable right now. Please choose cash on delivery.']); exit;
}

$rows = [];
foreach ($order['items'] as $it) $rows[] = "• {$it['qty']} x {$it['name']}" . ($it['desc'] ? " ({$it['desc']})" : '') . ' — ' . fomaxo_aed($it['unit'] * $it['qty']);
if ($order['gift']) $rows[] = "• FREE 10ml {$order['gift']} mini";
if ($order['discountFils']) $rows[] = "Multi-buy {$order['pct']}% off: -" . fomaxo_aed($order['discountFils']);

$pid = preg_replace('/[^A-Za-z0-9_\-]/', '', $pi['id']);
fomaxo_order_set($no, ['ref' => $pid]);
@file_put_contents(ziina_dir() . "/$pid.json", json_encode(['no' => $no, 'created' => date('Y-m-d H:i'), 'totalFils' => $order['totalFils'],
  'summary' => $order['summary'], 'rows' => $rows, 'cust' => $cust, 'test' => $testMode,
  'pids' => array_values(array_unique(array_map(fn($l) => (string)($l['id'] ?? ''), (array)($in['lines'] ?? []))))]), LOCK_EX);
fomaxo_log_order([date('Y-m-d H:i'), $no, 'Ziina — awaiting payment' . ($testMode ? ' (TEST)' : ''), fomaxo_aed($order['totalFils']), $cust['name'], $cust['phone'], $cust['email'],
                  $cust['emirate'], $cust['address'], $cust['note'], implode(' | ', $order['summary'])]);

echo json_encode(['url' => $pi['redirect_url']]);
