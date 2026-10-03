<?php
/* FOMAXO — creates a Stripe Checkout page for the customer's bag.
   Prices are checked HERE (server side) so nobody can change them in the browser.
   Prices and offers live in store-lib.php — if you change a price on the website, change it there too. */
header('Content-Type: application/json');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error'=>'Method not allowed']); exit; }

/* The key file lives OUTSIDE public_html (and outside GitHub), so it is never public
   and is never overwritten when the site redeploys from GitHub:
   /home/<account>/domains/fomaxo.com/stripe-config.php   (one level above public_html) */
$cfgFile = null;
foreach ([dirname(__DIR__) . '/stripe-config.php', __DIR__ . '/stripe-config.php'] as $f) { if (is_file($f)) { $cfgFile = $f; break; } }
if (!$cfgFile) { http_response_code(500); echo json_encode(['error'=>'Card payments are not set up yet.']); exit; }
$cfg = require $cfgFile;
$key = $cfg['secret_key'] ?? '';
if (!$key || strpos($key, 'sk_') !== 0) { http_response_code(500); echo json_encode(['error'=>'Card payments are not set up yet.']); exit; }

require __DIR__ . '/store-lib.php';   // price list, multi-buy and free-mini rules (shared with cod.php)

$in = json_decode(file_get_contents('php://input'), true) ?: [];
$order = fomaxo_price_order($in);
if (isset($order['error'])) { http_response_code(400); echo json_encode(['error' => $order['error']]); exit; }
$cust = fomaxo_customer($in);
if (isset($cust['error'])) { http_response_code(400); echo json_encode(['error' => $cust['error']]); exit; }
$MIN_ORDER = 30;   // AED minimum order (after discount) for card payments — keep in sync with index.html (minOrder)
if ($order['totalFils'] < $MIN_ORDER * 100) { http_response_code(400); echo json_encode(['error' => "Minimum order is AED $MIN_ORDER."]); exit; }

$items = [];
foreach ($order['items'] as $it) {
  $pd = ['currency' => $cfg['currency'], 'unit_amount' => $it['unit'], 'product_data' => ['name' => $it['name'] . ($it['pct'] ? " ({$it['pct']}% multi-buy)" : '')]];
  if ($it['desc']) $pd['product_data']['description'] = $it['desc'];
  $items[] = ['price_data' => $pd, 'quantity' => $it['qty']];
}
$summary = $order['summary'];
$giftName = $order['gift'];

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$host = preg_replace('/[^A-Za-z0-9.\-:]/', '', $_SERVER['HTTP_HOST'] ?? '');
$dir  = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
$base = ($https ? 'https' : 'http') . "://$host$dir/";

$addr = "{$cust['address']}, {$cust['emirate']}";
$params = [
  'mode' => 'payment',
  'line_items' => $items,
  'success_url' => $base . '#/thank-you?session_id={CHECKOUT_SESSION_ID}',
  'cancel_url'  => $base . '#/',
  'customer_email' => $cust['email'],
  'metadata' => ['order' => mb_substr(implode(' | ', $summary), 0, 490), 'name' => $cust['name'], 'phone' => $cust['phone'],
                 'address' => mb_substr($addr, 0, 490), 'note' => mb_substr($cust['note'], 0, 490)],
  'custom_text' => $giftName ? ['submit' => ['message' => "Includes your FREE 10ml {$giftName} mini."]] : null,
  'payment_intent_data' => [
    'description' => mb_substr('FOMAXO order: ' . implode(' | ', $summary), 0, 990),
    'shipping' => ['name' => $cust['name'], 'phone' => $cust['phone'],
                   'address' => ['line1' => mb_substr($cust['address'], 0, 200), 'city' => $cust['emirate'], 'country' => 'AE']],
  ],
];
$params = array_filter($params, fn($v) => $v !== null);
$ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
curl_setopt_array($ch, [
  CURLOPT_POST => true,
  CURLOPT_POSTFIELDS => http_build_query($params),
  CURLOPT_USERPWD => $key . ':',
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_TIMEOUT => 25,
]);
$res = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

$data = $res ? json_decode($res, true) : null;
if ($code !== 200 || empty($data['url'])) {
  error_log('FOMAXO Stripe error: ' . ($err ?: $res));
  http_response_code(502);
  echo json_encode(['error' => 'Card payment is temporarily unavailable. Please try again or order via WhatsApp.']);
  exit;
}
date_default_timezone_set('Asia/Dubai');
fomaxo_log_order([date('Y-m-d H:i'), $data['id'] ?? '', 'Card (Stripe)', fomaxo_aed($order['totalFils']), $cust['name'], $cust['phone'], $cust['email'],
                  $cust['emirate'], $cust['address'], $cust['note'], implode(' | ', $summary)]);
echo json_encode(['url' => $data['url']]);
