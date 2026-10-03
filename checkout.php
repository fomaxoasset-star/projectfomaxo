<?php
/* FOMAXO — creates a Stripe Checkout page for the customer's bag.
   Prices are checked HERE (server side) so nobody can change them in the browser.
   If you change a price on the website, change it in $CATALOG below too. */
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

/* ---- price list (AED) — keep in sync with the website ---- */
$CATALOG = [
  'king' => ['name' => 'King', 'kind' => '', 'prices' => ['100' => 5000], 'exclude' => []],
  'gold' => ['name' => 'Gold', 'kind' => '', 'prices' => ['100' => 90], 'exclude' => []],
  'oldmoney' => ['name' => 'Old Money', 'kind' => '', 'prices' => ['10' => 40, '50' => 90, '100' => 150], 'exclude' => []],
  'royalcandy' => ['name' => 'Royal Candy', 'kind' => '', 'prices' => ['10' => 40, '50' => 90, '100' => 150], 'exclude' => []],
  'dollar' => ['name' => 'Dollar', 'kind' => '', 'prices' => ['100' => 90], 'exclude' => []],
  'matchacoco' => ['name' => 'Matcha Coco', 'kind' => '', 'prices' => ['10' => 40, '50' => 90, '100' => 150], 'exclude' => []],
  'passionsin' => ['name' => 'Passion Sin', 'kind' => '', 'prices' => ['10' => 40, '50' => 90, '100' => 150], 'exclude' => []],
  'discovery' => ['name' => 'Discovery Set', 'kind' => 'set', 'prices' => ['3' => 30, '5' => 50], 'exclude' => ['king']]
];

$in = json_decode(file_get_contents('php://input'), true);
$lines = is_array($in['lines'] ?? null) ? $in['lines'] : [];
if (!$lines || count($lines) > 30) { http_response_code(400); echo json_encode(['error'=>'Your bag is empty.']); exit; }

/* ---- quantity discount — keep in sync with the website ----
   Counts every unit in the bag except the products below.
   1-4 units: none · 5: 1% · 6: 1.5% · then +0.5% per extra unit, up to $QTY_DISCOUNT_MAX %. */
$QTY_DISCOUNT_SKIP = ['discovery', 'king'];
$QTY_DISCOUNT_MAX = 10;
function qty_pct($n, $max) { return $n < 5 ? 0 : min($max, 1 + ($n - 5) * 0.5); }

$items = []; $summary = []; $discUnits = 0; $discBase = 0;
foreach ($lines as $l) {
  $id  = is_string($l['id'] ?? null) ? $l['id'] : '';
  $opt = (string)($l['opt'] ?? '');
  $qty = (int)($l['qty'] ?? 0);
  if (!isset($CATALOG[$id]) || !isset($CATALOG[$id]['prices'][$opt]) || $qty < 1 || $qty > 20) {
    http_response_code(400); echo json_encode(['error'=>'An item in your bag is no longer available. Please refresh and try again.']); exit;
  }
  $p = $CATALOG[$id];
  $isSet = $p['kind'] === 'set';
  $label = $isSet ? "Set of $opt" : "{$opt}ml";
  $name = "FOMAXO {$p['name']} — $label";
  $desc = null;
  if ($isSet) {
    $picks = array_values(array_filter((array)($l['picks'] ?? []), fn($x) => is_string($x) && isset($CATALOG[$x]) && $CATALOG[$x]['kind'] !== 'set' && !in_array($x, $p['exclude'], true)));
    if (count($picks) !== (int)$opt) { http_response_code(400); echo json_encode(['error'=>"Please choose $opt fragrances for your Discovery Set."]); exit; }
    $desc = 'Fragrances: ' . implode(', ', array_map(fn($x) => $CATALOG[$x]['name'], $picks));
  }
  $pd = ['currency'=>$cfg['currency'], 'unit_amount'=>(int)round($p['prices'][$opt] * 100), 'product_data'=>['name'=>$name]];
  if ($desc) $pd['product_data']['description'] = $desc;
  $items[] = ['price_data'=>$pd, 'quantity'=>$qty];
  if (!in_array($id, $QTY_DISCOUNT_SKIP, true)) { $discUnits += $qty; $discBase += $p['prices'][$opt] * $qty; }
  $summary[] = "$qty x $name" . ($desc ? " ($desc)" : '');
}

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$host = preg_replace('/[^A-Za-z0-9.\-:]/', '', $_SERVER['HTTP_HOST'] ?? '');
$dir  = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
$base = ($https ? 'https' : 'http') . "://$host$dir/";

/* quantity discount, worked out here from $CATALOG (never taken from the browser) */
$discPct = qty_pct($discUnits, $QTY_DISCOUNT_MAX);
$discFils = (int)round($discBase * $discPct);   // AED × % = fils (1/100 AED)
$coupon = null;
if ($discFils > 0) {
  $pctTxt = rtrim(rtrim(number_format($discPct, 1, '.', ''), '0'), '.');
  $ch = curl_init('https://api.stripe.com/v1/coupons');
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query(['amount_off' => $discFils, 'currency' => $cfg['currency'], 'duration' => 'once', 'max_redemptions' => 1, 'name' => "Multi-buy discount ($pctTxt%)"]),
    CURLOPT_USERPWD => $key . ':',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 25,
  ]);
  $res = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = curl_error($ch);
  curl_close($ch);
  $c = $res ? json_decode($res, true) : null;
  if ($code !== 200 || empty($c['id'])) {
    error_log('FOMAXO Stripe coupon error: ' . ($err ?: $res));
    http_response_code(502);
    echo json_encode(['error' => 'Card payment is temporarily unavailable. Please try again or order via WhatsApp.']);
    exit;
  }
  $coupon = $c['id'];
  $summary[] = "Multi-buy discount ($pctTxt%): -" . number_format($discFils / 100, 2, '.', '') . ' ' . strtoupper($cfg['currency']);
}

$params = [
  'mode' => 'payment',
  'line_items' => $items,
  'success_url' => $base . '#/thank-you?session_id={CHECKOUT_SESSION_ID}',
  'cancel_url'  => $base . '#/',
  'shipping_address_collection' => ['allowed_countries' => ['AE']],
  'phone_number_collection' => ['enabled' => 'true'],
  'billing_address_collection' => 'auto',
  'metadata' => ['order' => substr(implode(' | ', $summary), 0, 490)],
  'payment_intent_data' => ['description' => substr('FOMAXO order: ' . implode(' | ', $summary), 0, 990)],
];
if ($coupon) $params['discounts'] = [['coupon' => $coupon]];

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
echo json_encode(['url' => $data['url']]);
