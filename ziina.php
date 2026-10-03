<?php
/* FOMAXO — card / Apple Pay / Google Pay through Ziina (hosted checkout, Payment Intent API).
   POST  ziina.php            → checks prices on the server, creates a Ziina Payment Intent, returns the payment page URL
   GET   ziina.php?verify=ID  → after payment, asks Ziina for the real status and records the paid order
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

$cfgFile = null;
foreach ([dirname(__DIR__), __DIR__] as $d) foreach (['Ziina-config.php', 'ziina-config.php'] as $n) { if (!$cfgFile && is_file("$d/$n")) $cfgFile = "$d/$n"; }
$cfg = $cfgFile ? require $cfgFile : [];
if (!is_array($cfg)) $cfg = [];
$token = '';
foreach (['access_token', 'api_key', 'token', 'secret_key', 'key'] as $k) { if ($token === '' && is_string($cfg[$k] ?? null)) $token = trim($cfg[$k]); }
if ($token === '' || stripos($token, 'PASTE') === 0) { http_response_code(500); echo json_encode(['error' => 'Card payments are not set up yet. Please choose cash on delivery or WhatsApp.']); exit; }
$testMode = !empty($cfg['test']) && $cfg['test'] !== 'false';
if (!empty($cfg['api'])) $API = rtrim($cfg['api'], '/');   // only for testing against a fake Ziina server

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
    $rec['paid'] = date('Y-m-d H:i'); file_put_contents($file, json_encode($rec), LOCK_EX);
    $c = $rec['cust']; $total = fomaxo_aed($rec['totalFils']);
    $note = $paidFils && $paidFils !== (int)$rec['totalFils'] ? ' (AMOUNT MISMATCH: paid ' . fomaxo_aed($paidFils) . ')' : '';
    fomaxo_log_order([date('Y-m-d H:i'), $rec['no'], 'Ziina — PAID' . $note, $total, $c['name'], $c['phone'], $c['email'], $c['emirate'], $c['address'], $c['note'], implode(' | ', $rec['summary'])]);
    $host = preg_replace('/^www\./', '', preg_replace('/[^A-Za-z0-9.\-]/', '', $_SERVER['HTTP_HOST'] ?? 'fomaxo.com'));
    $from = "FOMAXO <orders@$host>";
    $body = "NEW PAID ORDER (Ziina)  {$rec['no']}$note\n" . date('d M Y, H:i') . " (Dubai)\nZiina payment: $id\n\n" . implode("\n", $rec['rows']) . "\n\n"
          . "TOTAL PAID: $total\nDelivery: Free\n\nName: {$c['name']}\nPhone: {$c['phone']}\n" . ($c['email'] !== '' ? "Email: {$c['email']}\n" : '') . "Address: {$c['address']}, {$c['emirate']}, UAE\n" . ($c['note'] ? "Note: {$c['note']}\n" : '');
    @mail($STORE_EMAIL, '=?UTF-8?B?' . base64_encode("New paid order {$rec['no']} — $total") . '?=', $body, "From: $from\r\n" . ($c['email'] !== '' ? "Reply-To: {$c['email']}\r\n" : '') . "Content-Type: text/plain; charset=UTF-8");
    $cb = "Thank you for your order, {$c['name']}.\n\nOrder number: {$rec['no']}\n\n" . implode("\n", $rec['rows']) . "\n\nTotal paid: $total\nDelivery: Free, to {$c['address']}, {$c['emirate']}\n\n"
        . "We will call or WhatsApp you on {$c['phone']} to arrange your delivery.\n\nFOMAXO\nhttps://$host";
    if ($c['email'] !== '') @mail($c['email'], '=?UTF-8?B?' . base64_encode("Your FOMAXO order {$rec['no']}") . '?=', $cb, "From: $from\r\nReply-To: $STORE_EMAIL\r\nContent-Type: text/plain; charset=UTF-8");
  }
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

$no = 'FMX-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
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
  http_response_code(502); echo json_encode(['error' => 'Card payment is unavailable right now. Please choose cash on delivery or WhatsApp.']); exit;
}

$rows = [];
foreach ($order['items'] as $it) $rows[] = "• {$it['qty']} x {$it['name']}" . ($it['desc'] ? " ({$it['desc']})" : '') . ' — ' . fomaxo_aed($it['unit'] * $it['qty']);
if ($order['gift']) $rows[] = "• FREE 10ml {$order['gift']} mini";
if ($order['discountFils']) $rows[] = "Multi-buy {$order['pct']}% off: -" . fomaxo_aed($order['discountFils']);

$pid = preg_replace('/[^A-Za-z0-9_\-]/', '', $pi['id']);
@file_put_contents(ziina_dir() . "/$pid.json", json_encode(['no' => $no, 'created' => date('Y-m-d H:i'), 'totalFils' => $order['totalFils'],
  'summary' => $order['summary'], 'rows' => $rows, 'cust' => $cust, 'test' => $testMode]), LOCK_EX);
fomaxo_log_order([date('Y-m-d H:i'), $no, 'Ziina — awaiting payment' . ($testMode ? ' (TEST)' : ''), fomaxo_aed($order['totalFils']), $cust['name'], $cust['phone'], $cust['email'],
                  $cust['emirate'], $cust['address'], $cust['note'], implode(' | ', $order['summary'])]);

echo json_encode(['url' => $pi['redirect_url']]);
