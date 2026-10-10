<?php
/* FOMAXO — cash on delivery orders from the checkout page (card payments go through ziina.php).
   Prices are checked HERE (server side) so nobody can change them in the browser.
   The price list comes from fomaxo.com/admin → Products (store-lib.php; its fallback list is used only when the database is down). */
header('Content-Type: application/json');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error'=>'Method not allowed']); exit; }
require __DIR__ . '/store-lib.php';   // $CATALOG, fomaxo_customer(), orders-lib.php

/* ---- minimum order (AED) — keep in sync with the website ----
   Counts the total after the multi-buy discount. Cash on delivery minimum, maximum and fee: admin → Settings → Cash on delivery. */
$MIN_ORDER = 30;
function fail($code, $msg) { http_response_code($code); echo json_encode(['error' => $msg]); exit; }
function aed($fils) { return 'AED ' . number_format($fils / 100, 2, '.', ','); }
function aed_short($fils) { return 'AED ' . ($fils % 100 ? number_format($fils / 100, 2, '.', ',') : number_format($fils / 100, 0, '.', ',')); }
[$COD_MIN, $COD_MAX, $COD_FEE] = fomaxo_cod_limits();   // $COD_MAX 0 = no upper limit, $COD_FEE 0 = no fee

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) $in = [];
if (($in['pay'] ?? '') !== 'cod') fail(400, 'Card payments go through ziina.php.');
/* customer details from the checkout page: name, mobile, emirate, address in separate boxes; email optional */
$cu = fomaxo_customer($in);
if (isset($cu['error'])) fail(400, $cu['error']);
$lines = is_array($in['lines'] ?? null) ? $in['lines'] : [];
if (!$lines || count($lines) > 30) fail(400, 'Your bag is empty.');

/* ---- quantity discount — keep in sync with the website ----
   Counts every unit in the bag except the products below.
   1 unit: none · 2: 5% · 3-4: 10% + FREE 10ml mini · 5 or more: 15% + FREE 10ml mini.
   No free mini when the bag has any 10ml bottle (the % discount still applies). */
$QTY_DISCOUNT_SKIP = ['discovery', 'king'];
function qty_pct($n) { return $n >= 5 ? 15 : ($n >= 3 ? 10 : ($n >= 2 ? 5 : 0)); }
function qty_mini($n) { return $n >= 3; }

$summary = []; $discUnits = 0; $discBase = 0; $has10 = false; $subFils = 0;
foreach ($lines as $l) {
  $id  = is_string($l['id'] ?? null) ? $l['id'] : '';
  $opt = (string)($l['opt'] ?? '');
  $qty = (int)($l['qty'] ?? 0);
  if (!isset($CATALOG[$id]) || !isset($CATALOG[$id]['prices'][$opt]) || $qty < 1) fail(400, 'An item in your bag is no longer available. Please refresh and try again.');
  if ($qty > 99) fail(400, 'You can order up to 99 of one item online. For a bigger order, please message us on WhatsApp.');   // same limit as the bag
  $p = $CATALOG[$id];
  $isSet = $p['kind'] === 'set';
  $label = $isSet ? "Set of $opt" : "{$opt}ml";
  $name = "FOMAXO {$p['name']} — $label";
  $desc = null;
  if ($isSet) {
    $picks = array_values(array_filter((array)($l['picks'] ?? []), fn($x) => is_string($x) && isset($CATALOG[$x]) && $CATALOG[$x]['kind'] !== 'set' && !in_array($x, $p['exclude'], true)));
    if (count($picks) !== (int)$opt) fail(400, "Please choose $opt fragrances for your Discovery Set.");
    $desc = 'Fragrances: ' . implode(', ', array_map(fn($x) => $CATALOG[$x]['name'], $picks));
  }
  $subFils += (int)round($p['prices'][$opt] * 100) * $qty;
  if (!in_array($id, $QTY_DISCOUNT_SKIP, true)) { $discUnits += $qty; $discBase += $p['prices'][$opt] * $qty; if ($opt === '10') $has10 = true; }
  $summary[] = "$qty x $name" . ($desc ? " ($desc)" : '');
}

/* quantity discount, worked out here from $CATALOG (never taken from the browser) */
$discPct = qty_pct($discUnits);
$discFils = (int)round($discBase * $discPct);   // AED × % = fils (1/100 AED)
$pctTxt = rtrim(rtrim(number_format($discPct, 1, '.', ''), '0'), '.');
/* coupon code: checked here again (never trusted from the browser); it replaces the multi-buy discount only when it saves more */
$discLabel = "Multi-buy discount ($pctTxt%)"; $couponCode = null;
/* "Customers bought together" extra % (admin → Products → Together extra off): counts as part of the multi-buy offer */
$bt = fomaxo_bt(); $btFils = fomaxo_bt_fils($lines, $CATALOG, $bt);
if ($btFils > 0) { $discFils += $btFils; $discLabel = ($discPct ? "Multi-buy discount ($pctTxt%) + " : '') . "Bought together ({$bt['pct']}%)"; }
if (is_string($in['coupon'] ?? null) && trim($in['coupon']) !== '') {
  $cp = fomaxo_coupon_apply($in['coupon'], $subFils, $discFils, $cu['phone']);
  if (isset($cp['error'])) fail(400, $cp['error'] . ' Please remove it and try again.');
  if ($cp['stack'] && $cp['saveFils'] > 0) {   // "Use both": the coupon comes off after the multi-buy discount
    $discLabel = ($discFils > 0 ? "$discLabel + " : '') . "Coupon {$cp['code']} ({$cp['label']})"; $discFils += $cp['saveFils']; $couponCode = $cp['code'];
  } elseif (!$cp['stack'] && $cp['saveFils'] > $discFils) { $discFils = $cp['saveFils']; $couponCode = $cp['code']; $discLabel = "Coupon {$cp['code']} ({$cp['label']})"; }
}
/* a free product coupon: its product joins the order at AED 0 (and comes off stock); the multi-buy discount stays */
$freeCp = isset($cp['free']) && !isset($cp['error']) ? $cp : null;
if ($freeCp) { $couponCode = $freeCp['code']; $summary[] = "1 x FOMAXO {$freeCp['free']['name']} — FREE with coupon {$freeCp['code']}"; }
$afterFils = $subFils - $discFils;               // total after the multi-buy discount or the coupon

/* minimum order: checked here too, so it can't be bypassed */
if ($afterFils < $MIN_ORDER * 100) fail(400, 'Minimum order ' . aed_short($MIN_ORDER * 100) . ' · add ' . aed_short($MIN_ORDER * 100 - $afterFils) . ' more.');
if ($afterFils < $COD_MIN * 100) fail(400, 'Cash on delivery is available for orders above ' . aed_short($COD_MIN * 100) . '. Add ' . aed_short($COD_MIN * 100 - $afterFils) . ' more to activate COD.');
if ($COD_MAX > 0 && $afterFils >= $COD_MAX * 100) fail(400, 'COD for orders under ' . aed_short($COD_MAX * 100) . '. Please pay by card.');

/* FREE 10ml mini: the customer's pick if it comes in 10ml, otherwise the first scent that does */
$miniName = null;
if (qty_mini($discUnits) && !$has10) {
  $ok = fn($x) => is_string($x) && isset($CATALOG[$x]) && !in_array($x, $QTY_DISCOUNT_SKIP, true) && isset($CATALOG[$x]['prices']['10']);
  $mini = $in['mini'] ?? null;
  $inStock = fn($x) => $ok($x) && fomaxo_mini_ok($x);   // never a mini that is out of stock, unless every 10ml is
  if (!$inStock($mini)) $mini = array_values(array_filter(array_keys($CATALOG), $inStock))[0] ?? ($ok($mini) ? $mini : array_values(array_filter(array_keys($CATALOG), $ok))[0]);
  $miniName = $CATALOG[$mini]['name'];
}

/* ---- save the order (database, plus a file outside public_html) and email it ---- */
$totalFils = $afterFils + $COD_FEE * 100;
$rows = $summary;
if ($discFils > 0) $rows[] = "$discLabel: -" . aed($discFils);
if ($miniName) $rows[] = "FREE 10ml mini: $miniName";
if ($COD_FEE > 0) $rows[] = 'Cash on delivery fee: ' . aed($COD_FEE * 100);
/* order database: gives the counting order number (FMX-1001 …); the old random number is only used if the database is down */
$saveLines = array_map(fn($l) => ['id' => $l['id'] ?? '', 'opt' => (string)($l['opt'] ?? ''), 'qty' => (int)($l['qty'] ?? 0), 'picks' => array_values((array)($l['picks'] ?? []))], $lines);
if ($msg = fomaxo_stock_problem($saveLines, $CATALOG)) fail(409, $msg);
if ($freeCp) {
  $saveLines[] = ['id' => $freeCp['free']['id'], 'opt' => $freeCp['free']['opt'], 'qty' => 1, 'free' => true, 'coupon' => $freeCp['code']];
  if (fomaxo_stock_problem($saveLines, $CATALOG)) fail(409, "Sorry, your free {$freeCp['free']['name']} is out of stock right now. Please remove the coupon and try again.");
}
if ($miniName) $saveLines[] = ['id' => $mini, 'opt' => '10', 'qty' => 1, 'free' => true];
$no = fomaxo_save_order(['payment' => 'Cash on delivery', 'status' => 'New', 'subtotal' => $subFils / 100, 'discount' => $discFils / 100,
        'fee' => $COD_FEE, 'total' => $totalFils / 100, 'items' => implode(' | ', $rows), 'free_mini' => $miniName, 'coupon' => $couponCode, 'wa_optin' => $cu['wa'], 'lines' => $saveLines]
        + array_intersect_key($cu, array_flip(['name', 'phone', 'email', 'emirate', 'building', 'room', 'street', 'area', 'address', 'note'])));
$inDb = (bool)$no;
if ($no) { fomaxo_stock_move($no); fx_coupon_spent(fomaxo_db(), $couponCode); }   // stock goes down as soon as a cash order is placed; a used-up one-use coupon deletes itself
else $no = 'FX' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
$cell = fn($v) => preg_match('/^[=+\-@]/', (string)$v) ? "'" . $v : $v;   // stop spreadsheet formulas
$order = [date('Y-m-d H:i:s'), $no, $cu['name'], $cu['phone'], $cu['email'], $cu['emirate'], $cu['address'], $cu['note'],
          implode(' | ', $rows), number_format($subFils / 100, 2, '.', ''), number_format($discFils / 100, 2, '.', ''),
          number_format($COD_FEE, 2, '.', ''), number_format($totalFils / 100, 2, '.', ''),
          $cu['building'], $cu['room'], $cu['street'], $cu['area'], $cu['wa'] ? 'Yes' : 'No'];   // new columns go last so older rows still line up
$saved = false;
$dir = dirname(__DIR__) . '/fomaxo-orders';     // one level above public_html: private, kept across deploys
if (is_dir($dir) || @mkdir($dir, 0700, true)) {
  $file = "$dir/cash-on-delivery-orders.csv";
  $new = !is_file($file);
  if ($fh = @fopen($file, 'a')) {
    if (flock($fh, LOCK_EX)) {
      if ($new) fwrite($fh, "\xEF\xBB\xBF");   // so Excel reads Arabic names correctly
      if ($new) fputcsv($fh, ['Date', 'Order', 'Name', 'Mobile', 'Email', 'Emirate', 'Address', 'Note', 'Items', 'Subtotal', 'Discount', 'COD fee', 'Total to collect (AED)', 'Villa/Building no', 'Room No / Floor', 'Street', 'Area', 'WhatsApp offers']);
      $saved = fputcsv($fh, array_map($cell, $order)) !== false;
      flock($fh, LOCK_UN);
    }
    fclose($fh);
  }
}
require_once __DIR__ . '/whatsapp-lib.php';   // private "review your order" link → Verified Purchaser reviews, asked for on WhatsApp
$ids = array_map(fn($l) => $l['id'] ?? '', $lines);
$review = fomaxo_review_link($no, $ids);
$waLines = fomaxo_wa_review_request($no, $cu, array_map(fn($id) => $CATALOG[$id]['name'] ?? '', $ids), $review);
$to = fomaxo_orders_email();   // Settings on fomaxo.com/admin, else the store inbox
/* the order is already kept (database or order file): the shopper sees "order confirmed" now, the email goes out right after */
$answer = ['order' => $no, 'total' => number_format($totalFils / 100, 2, '.', ''), 'review' => $review];
$early = $saved || $inDb;
if ($early) fomaxo_reply_now($answer);
if ($inDb) { require_once __DIR__ . '/ads-server-lib.php'; fomaxo_ads_server_buy($no); }   // the purchase also goes to Meta / TikTok ads from here (admin → Settings → Ads tracking)
fomaxo_en_many([$cu['name'], $cu['address'], $cu['note']]);   // Arabic typed by the customer: English in this email and in admin
$body = "New cash on delivery order $no\n\nCollect in cash: " . aed($totalFils) . "\n\n" . implode("\n", $rows)
      . "\n\nSubtotal: " . aed($subFils) . ($discFils > 0 ? "\n$discLabel: -" . aed($discFils) : '')
      . ($COD_FEE > 0 ? "\nCash on delivery fee: " . aed($COD_FEE * 100) : '') . "\nTotal: " . aed($totalFils)
      . "\n\nName: " . fomaxo_en_both($cu['name']) . "\nMobile: {$cu['phone']}\nEmail: {$cu['email']}\nEmirate: {$cu['emirate']}\nAddress: " . fomaxo_en_both($cu['address']) . ($cu['note'] !== '' ? "\nNote: " . fomaxo_en_both($cu['note']) : '') . "\nWhatsApp offers: " . ($cu['wa'] ? 'Yes' : 'No') . $waLines;
$mailed = fomaxo_mail($to, "FOMAXO cash on delivery order $no — " . aed($totalFils), $body,
                "From: FOMAXO Orders <mail@fomaxo.com>\r\n" . ($cu['email'] !== '' ? "Reply-To: {$cu['email']}\r\n" : '') . "Content-Type: text/plain; charset=UTF-8");
if ($early) exit;
if (!$mailed) { error_log("FOMAXO COD order $no could not be saved or emailed: " . json_encode($order)); fail(500, 'We could not place your order right now. Please try again or contact us on WhatsApp.'); }
echo json_encode($answer);
exit;
