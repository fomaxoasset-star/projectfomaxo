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
require_once __DIR__ . '/ziina-lib.php';   // config lookup, Ziina calls and the paid-order recording (shared with the re-check)

$STORE_EMAIL = ZIINA_STORE_EMAIL;
$MIN_ORDER   = 30;   // AED — keep in sync with index.html (minOrder)
[$cfgFile, $token, $testMode, $API] = ziina_settings();

/* ---- set-up check: open fomaxo.com/ziina.php?status in a browser (shows only found / not found, never the token) ---- */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['status'])) {
  $home = dirname(__DIR__, 3);
  echo json_encode(['config_file' => $cfgFile ? 'found: ' . ltrim(substr($cfgFile, strlen($home)), '/') : 'NOT FOUND — put Ziina-config.php in domains/fomaxo.com (next to public_html)',
                    'access_token' => $token !== '' ? 'found' : 'NOT FOUND in the file',
                    'mode' => $token === '' ? '-' : ($testMode ? 'test (no real money)' : 'live')], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  exit;
}
if ($token === '') { error_log('FOMAXO Ziina: ' . ($cfgFile ? "no access token in $cfgFile" : 'Ziina-config.php not found')); http_response_code(500); echo json_encode(['error' => 'Card payments are not set up yet. Please choose cash on delivery.']); exit; }

date_default_timezone_set('Asia/Dubai');

/* ---------------- 2) customer is back from Ziina: confirm the payment ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['verify'])) {
  $id = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$_GET['verify']);
  if ($id === '') { http_response_code(400); echo json_encode(['error' => 'Missing payment reference.']); exit; }
  $out = ziina_verify($id, true);
  if ($out === null) { http_response_code(502); echo json_encode(['error' => 'We could not confirm your payment yet. Please contact us on WhatsApp.']); exit; }
  if (empty($out['replied'])) echo json_encode($out);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit; }

/* ---------------- 1) create the payment ---------------- */
$in = json_decode(file_get_contents('php://input'), true) ?: [];
$order = fomaxo_price_order($in);
if (isset($order['error'])) { http_response_code(400); echo json_encode(['error' => $order['error']]); exit; }
$cust = fomaxo_customer($in);
if (isset($cust['error'])) { http_response_code(400); echo json_encode(['error' => $cust['error']]); exit; }
if ($order['totalFils'] < $MIN_ORDER * 100) { http_response_code(400); echo json_encode(['error' => "Minimum order is AED $MIN_ORDER."]); exit; }
/* same-day delivery (Parcel Link from Al Azra): area, 2 PM and fee checked here (orders-lib.php fomaxo_sameday_pick) */
require_once __DIR__ . '/orders-lib.php';
$sd = fomaxo_sameday_pick($in['sameday'] ?? null, $cust['emirate'], $order['totalFils']);
if (isset($sd['error'])) { http_response_code(400); echo json_encode(['error' => $sd['error']]); exit; }
if ($sd) { $order['totalFils'] += $sd['fils']; $order['summary'][] = $sd['row']; }

/* order database: gives the counting order number (FMX-1001 …); the old random number is only used if the database is down */
require_once __DIR__ . '/orders-lib.php';
$lineIn = array_map(fn($l) => ['id' => (string)($l['id'] ?? ''), 'opt' => (string)($l['opt'] ?? ''), 'qty' => (int)($l['qty'] ?? 0), 'picks' => array_values((array)($l['picks'] ?? [])),
                               'price' => ($CATALOG[(string)($l['id'] ?? '')]['prices'][(string)($l['opt'] ?? '')] ?? 0) + 0], (array)($in['lines'] ?? []));   // price: the AED each, for the receipt
if ($msg = fomaxo_stock_problem($lineIn, $CATALOG)) { http_response_code(409); echo json_encode(['error' => $msg]); exit; }
if ($f = $order['free']) {   // a free product coupon: its product joins the order at AED 0 and comes off stock once paid
  $lineIn[] = ['id' => $f['id'], 'opt' => $f['opt'], 'qty' => 1, 'free' => true, 'coupon' => $f['coupon']];
  if (fomaxo_stock_problem($lineIn, $CATALOG)) { http_response_code(409); echo json_encode(['error' => "Sorry, your free {$f['name']} is out of stock right now. Please remove the coupon and try again."]); exit; }
}
if ($order['gift']) foreach ($CATALOG as $cid => $c) if ($c['name'] === $order['gift']) { $lineIn[] = ['id' => $cid, 'opt' => '10', 'qty' => 1, 'free' => true]; break; }
$no = fomaxo_save_order(['temp' => true, 'payment' => 'Card (Ziina)', 'status' => 'Awaiting payment', 'subtotal' => $order['fullFils'] / 100, 'discount' => $order['discountFils'] / 100,
        'fee' => 0, 'total' => $order['totalFils'] / 100, 'items' => implode(' | ', $order['summary']), 'free_mini' => $order['gift'], 'coupon' => $order['coupon'], 'wa_optin' => $cust['wa'], 'lines' => $lineIn, 'test' => $testMode]
        + array_intersect_key($cust, array_flip(['name', 'phone', 'email', 'emirate', 'building', 'room', 'street', 'area', 'address', 'note'])))
      ?? 'CARD-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));   // not an order yet: the FMX number is given once it is paid
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
  'message'       => mb_substr("FOMAXO · $count item" . ($count > 1 ? 's' : '') . ($order['gift'] ? " + FREE 10ml {$order['gift']} mini" : ''), 0, 200),
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
if ($order['free']) $rows[] = "• 1 x FOMAXO {$order['free']['name']} — FREE with coupon {$order['free']['coupon']}";
if ($order['gift']) $rows[] = "• FREE 10ml {$order['gift']} mini";
if ($order['discountFils']) $rows[] = "{$order['discLabel']}: -" . fomaxo_aed($order['discountFils']);
if ($sd) $rows[] = $sd['row'];

$pid = preg_replace('/[^A-Za-z0-9_\-]/', '', $pi['id']);
fomaxo_order_set($no, ['ref' => $pid]);
$visit = ziina_lead_card($cust, $order);   // admin → Left at checkout shows them at "Card payment page" now, paid or not
@file_put_contents(ziina_dir() . "/$pid.json", json_encode(['no' => $no, 'sid' => $visit['sid'] ?? '', 'vid' => $visit['vid'] ?? '', 'created' => date('Y-m-d H:i'), 'totalFils' => $order['totalFils'],
  'summary' => $order['summary'], 'rows' => $rows, 'cust' => $cust, 'ship' => $sd ? $sd['row'] : '', 'test' => $testMode, 'coupon' => $order['coupon'],
  'pids' => array_values(array_unique(array_map(fn($l) => (string)($l['id'] ?? ''), (array)($in['lines'] ?? []))))]), LOCK_EX);
fomaxo_log_order([date('Y-m-d H:i'), $no, 'Ziina — awaiting payment' . ($testMode ? ' (TEST)' : ''), fomaxo_aed($order['totalFils']), $cust['name'], $cust['phone'], $cust['email'],
                  $cust['emirate'], $cust['address'], $cust['note'], implode(' | ', $order['summary'])]);

echo json_encode(['url' => $pi['redirect_url']]);
