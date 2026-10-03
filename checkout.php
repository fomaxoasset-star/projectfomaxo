<?php
/* FOMAXO — takes the order from the checkout page.
   pay "card" (default): creates a Stripe Checkout page for the customer's bag.
   pay "cod": cash on delivery — the order is saved on the server and emailed to FOMAXO.
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
$cfg = $cfgFile ? (require $cfgFile) : [];
if (!is_array($cfg)) $cfg = [];
$cfg['currency'] = $cfg['currency'] ?? 'aed';
$key = $cfg['secret_key'] ?? '';

/* ---- minimum order + cash on delivery (AED) — keep in sync with the website ----
   Minimums count the total after the multi-buy discount. The cash on delivery fee is added on top. */
$MIN_ORDER = 30; $COD_MIN = 200; $COD_FEE = 10;
function fail($code, $msg) { http_response_code($code); echo json_encode(['error' => $msg]); exit; }
function aed($fils) { return 'AED ' . number_format($fils / 100, 2, '.', ','); }
function aed_short($fils) { return 'AED ' . ($fils % 100 ? number_format($fils / 100, 2, '.', ',') : number_format($fils / 100, 0, '.', ',')); }

/* ---- price list (AED) — keep in sync with the website ---- */
$CATALOG = [
  'king' => ['name' => 'King', 'kind' => '', 'prices' => ['100' => 5000], 'exclude' => []],
  'gold' => ['name' => 'Gold', 'kind' => '', 'prices' => ['100' => 90], 'exclude' => []],
  'oldmoney' => ['name' => 'Old Money', 'kind' => '', 'prices' => ['10' => 5, '50' => 90, '100' => 150], 'exclude' => []],
  'royalcandy' => ['name' => 'Royal Candy', 'kind' => '', 'prices' => ['10' => 40, '50' => 90, '100' => 150], 'exclude' => []],
  'dollar' => ['name' => 'Dollar', 'kind' => '', 'prices' => ['100' => 90], 'exclude' => []],
  'matchacoco' => ['name' => 'Matcha Coco', 'kind' => '', 'prices' => ['10' => 40, '50' => 90, '100' => 150], 'exclude' => []],
  'passionsin' => ['name' => 'Passion Sin', 'kind' => '', 'prices' => ['10' => 40, '50' => 90, '100' => 150], 'exclude' => []],
  'discovery' => ['name' => 'Discovery Set', 'kind' => 'set', 'prices' => ['3' => 30, '5' => 50], 'exclude' => ['king']]
];

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) $in = [];
$pay = ($in['pay'] ?? 'card') === 'cod' ? 'cod' : 'card';
if ($pay === 'card' && (!$key || strpos($key, 'sk_') !== 0)) fail(500, 'Card payments are not set up yet.');

/* customer details from the checkout page (required for cash on delivery) */
$EMIRATES = ['Dubai', 'Abu Dhabi', 'Sharjah', 'Ajman', 'Umm Al Quwain', 'Ras Al Khaimah', 'Fujairah'];
$cu = is_array($in['customer'] ?? null) ? $in['customer'] : null;
$clean = function ($v, $max) { $v = is_string($v) ? trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v)) : ''; return mb_substr($v, 0, $max); };
/* capital first letter of every word (the rest is kept as typed) — matches the checkout page */
$caps = fn($v) => preg_replace_callback('/(^|[\s\-\/(])(\p{Ll})/u', fn($m) => $m[1] . mb_strtoupper($m[2]), $v);
if ($cu) {
  $cu = ['name' => $clean($cu['name'] ?? '', 80), 'phone' => $clean($cu['phone'] ?? '', 20), 'email' => $clean($cu['email'] ?? '', 120),
         'emirate' => $clean($cu['emirate'] ?? '', 30), 'building' => $clean($cu['building'] ?? '', 40), 'room' => $clean($cu['room'] ?? '', 20),
         'street' => $clean($cu['street'] ?? '', 100), 'area' => $clean($cu['area'] ?? '', 80), 'address' => $clean($cu['address'] ?? '', 300), 'note' => $clean($cu['note'] ?? '', 300)];
  foreach (['name', 'building', 'room', 'street', 'area', 'address', 'note'] as $k) $cu[$k] = $caps($cu[$k]);
  /* address in separate boxes: villa/building no, room no / floor (optional), street, area — email is optional */
  if ($cu['building'] !== '' || $cu['street'] !== '' || $cu['area'] !== '') {
    $ok = $cu['building'] !== '' && mb_strlen($cu['street']) >= 2 && mb_strlen($cu['area']) >= 2;
    $cu['address'] = implode(', ', array_filter([$cu['building'], $cu['room'] !== '' ? 'Room/Floor ' . $cu['room'] : '', $cu['street'], $cu['area']]));
  } else {
    $ok = mb_strlen($cu['address']) >= 5;   // older page with a single address box
  }
  if (!$ok || mb_strlen($cu['name']) < 2 || strlen(preg_replace('/\D/', '', $cu['phone'])) < 7 || ($cu['email'] !== '' && !filter_var($cu['email'], FILTER_VALIDATE_EMAIL))
      || !in_array($cu['emirate'], $EMIRATES, true)) fail(400, 'Please check your delivery details and try again.');
} elseif ($pay === 'cod') {
  fail(400, 'Please add your delivery details.');
}
$lines = is_array($in['lines'] ?? null) ? $in['lines'] : [];
if (!$lines || count($lines) > 30) { http_response_code(400); echo json_encode(['error'=>'Your bag is empty.']); exit; }

/* ---- quantity discount — keep in sync with the website ----
   Counts every unit in the bag except the products below.
   1 unit: none · 2: 5% · 3-4: 10% + FREE 10ml mini · 5 or more: 15% + FREE 10ml mini.
   No free mini when the bag has any 10ml bottle (the % discount still applies). */
$QTY_DISCOUNT_SKIP = ['discovery', 'king'];
function qty_pct($n) { return $n >= 5 ? 15 : ($n >= 3 ? 10 : ($n >= 2 ? 5 : 0)); }
function qty_mini($n) { return $n >= 3; }

$items = []; $summary = []; $discUnits = 0; $discBase = 0; $has10 = false; $subFils = 0;
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
  $subFils += (int)round($p['prices'][$opt] * 100) * $qty;
  if (!in_array($id, $QTY_DISCOUNT_SKIP, true)) { $discUnits += $qty; $discBase += $p['prices'][$opt] * $qty; if ($opt === '10') $has10 = true; }
  $summary[] = "$qty x $name" . ($desc ? " ($desc)" : '');
}

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$host = preg_replace('/[^A-Za-z0-9.\-:]/', '', $_SERVER['HTTP_HOST'] ?? '');
$dir  = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
$base = ($https ? 'https' : 'http') . "://$host$dir/";

/* quantity discount, worked out here from $CATALOG (never taken from the browser) */
$discPct = qty_pct($discUnits);
$discFils = (int)round($discBase * $discPct);   // AED × % = fils (1/100 AED)
$pctTxt = rtrim(rtrim(number_format($discPct, 1, '.', ''), '0'), '.');
$afterFils = $subFils - $discFils;               // total after the multi-buy discount

/* minimum order: checked here too, so it can't be bypassed */
if ($afterFils < $MIN_ORDER * 100) fail(400, 'Minimum order ' . aed_short($MIN_ORDER * 100) . ' · add ' . aed_short($MIN_ORDER * 100 - $afterFils) . ' more.');
if ($pay === 'cod' && $afterFils < $COD_MIN * 100) fail(400, 'Cash on delivery is available for orders from ' . aed_short($COD_MIN * 100) . ' · add ' . aed_short($COD_MIN * 100 - $afterFils) . ' more.');

/* FREE 10ml mini: the customer's pick if it comes in 10ml, otherwise the first scent that does */
$miniName = null;
if (qty_mini($discUnits) && !$has10) {
  $ok = fn($x) => is_string($x) && isset($CATALOG[$x]) && !in_array($x, $QTY_DISCOUNT_SKIP, true) && isset($CATALOG[$x]['prices']['10']);
  $mini = $in['mini'] ?? null;
  if (!$ok($mini)) $mini = array_values(array_filter(array_keys($CATALOG), $ok))[0];
  $miniName = $CATALOG[$mini]['name'];
}

/* ---- cash on delivery: save the order (file outside public_html) and email it ---- */
if ($pay === 'cod') {
  $no = 'FX' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
  $totalFils = $afterFils + $COD_FEE * 100;
  $rows = $summary;
  if ($discFils > 0) $rows[] = "Multi-buy discount ($pctTxt%): -" . aed($discFils);
  if ($miniName) $rows[] = "FREE 10ml mini: $miniName";
  $rows[] = 'Cash on delivery fee: ' . aed($COD_FEE * 100);
  $cell = fn($v) => preg_match('/^[=+\-@]/', (string)$v) ? "'" . $v : $v;   // stop spreadsheet formulas
  $order = [date('Y-m-d H:i:s'), $no, $cu['name'], $cu['phone'], $cu['email'], $cu['emirate'], $cu['address'], $cu['note'],
            implode(' | ', $rows), number_format($subFils / 100, 2, '.', ''), number_format($discFils / 100, 2, '.', ''),
            number_format($COD_FEE, 2, '.', ''), number_format($totalFils / 100, 2, '.', ''),
            $cu['building'], $cu['room'], $cu['street'], $cu['area']];   // new columns go last so older rows still line up
  $saved = false;
  $dir = dirname(__DIR__) . '/fomaxo-orders';     // one level above public_html: private, kept across deploys
  if (is_dir($dir) || @mkdir($dir, 0700, true)) {
    $file = "$dir/cash-on-delivery-orders.csv";
    $new = !is_file($file);
    if ($fh = @fopen($file, 'a')) {
      if (flock($fh, LOCK_EX)) {
        if ($new) fputcsv($fh, ['Date', 'Order', 'Name', 'Mobile', 'Email', 'Emirate', 'Address', 'Note', 'Items', 'Subtotal', 'Discount', 'COD fee', 'Total to collect (AED)', 'Villa/Building no', 'Room No / Floor', 'Street', 'Area']);
        $saved = fputcsv($fh, array_map($cell, $order)) !== false;
        flock($fh, LOCK_UN);
      }
      fclose($fh);
    }
  }
  $to = $cfg['orders_email'] ?? 'fomaxoasset@gmail.com';
  $host = preg_replace('/^www\./', '', preg_replace('/[^A-Za-z0-9.\-]/', '', explode(':', $_SERVER['HTTP_HOST'] ?? 'fomaxo.com')[0])) ?: 'fomaxo.com';
  $body = "New cash on delivery order $no\n\nCollect in cash: " . aed($totalFils) . "\n\n" . implode("\n", $rows)
        . "\n\nSubtotal: " . aed($subFils) . ($discFils > 0 ? "\nMulti-buy discount ($pctTxt%): -" . aed($discFils) : '')
        . "\nCash on delivery fee: " . aed($COD_FEE * 100) . "\nTotal: " . aed($totalFils)
        . "\n\nName: {$cu['name']}\nMobile: {$cu['phone']}\nEmail: {$cu['email']}\nEmirate: {$cu['emirate']}\nAddress: {$cu['address']}" . ($cu['note'] !== '' ? "\nNote: {$cu['note']}" : '');
  $mailed = @mail($to, "FOMAXO cash on delivery order $no — " . aed($totalFils), $body,
                  "From: FOMAXO Orders <orders@$host>\r\n" . ($cu['email'] !== '' ? "Reply-To: {$cu['email']}\r\n" : '') . "Content-Type: text/plain; charset=UTF-8");
  if (!$saved && !$mailed) { error_log("FOMAXO COD order $no could not be saved or emailed: " . json_encode($order)); fail(500, 'We could not place your order right now. Please try again or order via WhatsApp.'); }
  echo json_encode(['order' => $no, 'total' => number_format($totalFils / 100, 2, '.', '')]);
  exit;
}

$coupon = null;
if ($discFils > 0) {
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

if ($miniName) $summary[] = "FREE 10ml mini: $miniName";

$buy = ($in['src'] ?? '') === 'buy';
$params = [
  'mode' => 'payment',
  'line_items' => $items,
  'success_url' => $base . '#/thank-you?session_id={CHECKOUT_SESSION_ID}' . ($buy ? '&src=buy' : ''),
  'cancel_url'  => $base . ($cu ? '#/checkout' . ($buy ? '?buy=1' : '') : '#/'),
  'billing_address_collection' => 'auto',
  'metadata' => ['order' => substr(implode(' | ', $summary), 0, 490)],
  'payment_intent_data' => ['description' => substr('FOMAXO order: ' . implode(' | ', $summary), 0, 990)],
];
if ($cu) {
  /* details typed on the checkout page go to Stripe, so the customer doesn't type them twice */
  if ($cu['email'] !== '') $params['customer_email'] = $cu['email'];
  $ship = $cu['building'] !== ''
    ? ['line1' => mb_substr($cu['building'] . ($cu['room'] !== '' ? ', Room/Floor ' . $cu['room'] : ''), 0, 200), 'line2' => $cu['street'], 'city' => $cu['area']]
    : ['line1' => mb_substr($cu['address'], 0, 200), 'city' => $cu['emirate']];
  $params['payment_intent_data']['shipping'] = ['name' => $cu['name'], 'phone' => $cu['phone'], 'address' => $ship + ['state' => $cu['emirate'], 'country' => 'AE']];
  $params['metadata'] += ['name' => $cu['name'], 'mobile' => $cu['phone'], 'emirate' => $cu['emirate'], 'address' => $cu['address']];
  if ($cu['email'] !== '') $params['metadata']['email'] = $cu['email'];
  if ($cu['note'] !== '') $params['metadata']['note'] = $cu['note'];
} else {
  $params['shipping_address_collection'] = ['allowed_countries' => ['AE']];
  $params['phone_number_collection'] = ['enabled' => 'true'];
}
if ($coupon) $params['discounts'] = [['coupon' => $coupon]];
if ($miniName) {
  $params['metadata']['free_mini'] = "10ml $miniName";
  $params['custom_text'] = ['submit' => ['message' => "Includes your FREE 10ml $miniName mini."]];
}

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
