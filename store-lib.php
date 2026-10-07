<?php
/* FOMAXO — shared price list and offer rules, used by checkout.php (card) and cod.php (cash on delivery).
   If you change a price or an offer on the website (index.html), change it HERE too. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }

/* ---- multi-buy discount by total items in the order — keep in sync with index.html MB_TIERS ----
   1 item: none · 2 items: 5% · 3–4 items: 10% · 5 or more: 15%. King & Discovery Set excluded. */
$MB_TIERS   = [2 => 5, 3 => 10, 5 => 15];      // items => % off
$MB_EXCLUDE = ['king', 'discovery'];           // not discounted and not counted
/* ---- FREE 10ml mini (customer's choice) from MINI_AT items, not when the bag already has a 10ml ---- */
$MINI_AT = 3;

/* ---- price list (AED) ---- */
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
/* products added or edited on fomaxo.com/admin → Products win; the list above is only used when the database is down */
require_once __DIR__ . '/orders-lib.php';
if ($dbCatalog = fomaxo_catalog_db()) $CATALOG = $dbCatalog;

/* Prices the bag on the server. Returns ['error'=>...] or the priced order (amounts in fils). */
function fomaxo_price_order($in) {
  global $MB_TIERS, $MB_EXCLUDE, $MINI_AT, $CATALOG;
  $lines = is_array($in['lines'] ?? null) ? $in['lines'] : [];
  if (!$lines || count($lines) > 30) return ['error' => 'Your bag is empty.'];

  $count = 0; $hasMini = false;
  foreach ($lines as $l) {
    $lid = $l['id'] ?? '';
    if (!in_array($lid, $MB_EXCLUDE, true)) $count += max(0, (int)($l['qty'] ?? 0));
    if (isset($CATALOG[$lid]) && $CATALOG[$lid]['kind'] !== 'set' && (string)($l['opt'] ?? '') === '10') $hasMini = true;
  }
  $pct = 0; foreach ($MB_TIERS as $min => $p) { if ($count >= $min) $pct = $p; }

  $items = []; $summary = []; $fullFils = 0; $netFils = 0;
  foreach ($lines as $l) {
    $id  = is_string($l['id'] ?? null) ? $l['id'] : '';
    $opt = (string)($l['opt'] ?? '');
    $qty = (int)($l['qty'] ?? 0);
    if (!isset($CATALOG[$id]) || !isset($CATALOG[$id]['prices'][$opt]) || $qty < 1)
      return ['error' => 'An item in your bag is no longer available. Please refresh and try again.'];
    if ($qty > 99) return ['error' => 'You can order up to 99 of one item online. For a bigger order, please message us on WhatsApp.'];   // same limit as the bag
    $p = $CATALOG[$id];
    $isSet = $p['kind'] === 'set';
    $name = "FOMAXO {$p['name']} — " . ($isSet ? "Set of $opt" : "{$opt}ml");
    $desc = null;
    if ($isSet) {
      $picks = array_values(array_filter((array)($l['picks'] ?? []), fn($x) => is_string($x) && isset($CATALOG[$x]) && $CATALOG[$x]['kind'] !== 'set' && !in_array($x, $p['exclude'], true)));
      if (count($picks) !== (int)$opt) return ['error' => "Please choose $opt fragrances for your Discovery Set."];
      $desc = 'Fragrances: ' . implode(', ', array_map(fn($x) => $CATALOG[$x]['name'], $picks));
    }
    $linePct = in_array($id, $MB_EXCLUDE, true) ? 0 : $pct;
    $unit = (int)round($p['prices'][$opt] * (100 - $linePct));   // price per item after multi-buy, in fils
    $fullFils += (int)round($p['prices'][$opt] * 100) * $qty;
    $netFils  += $unit * $qty;
    $items[] = ['name' => $name, 'desc' => $desc, 'pct' => $linePct, 'unit' => $unit, 'full' => (int)round($p['prices'][$opt] * 100), 'qty' => $qty];
    $summary[] = "$qty x $name" . ($desc ? " ($desc)" : '');
  }

  $gift = null;
  if ($count >= $MINI_AT && !$hasMini) {
    $gid = $in['mini'] ?? $in['gift'] ?? '';   // the free mini the customer picked in the bag
    $gid = is_string($gid) ? $gid : '';
    if (!isset($CATALOG[$gid]['prices']['10']) || !fomaxo_mini_ok($gid)) {   // never a mini that is out of stock, unless every 10ml is
      $pick = ''; foreach ($CATALOG as $cid => $c) { if (isset($c['prices']['10']) && fomaxo_mini_ok($cid)) { $pick = $cid; break; } }
      if ($pick === '' && !isset($CATALOG[$gid]['prices']['10'])) foreach ($CATALOG as $cid => $c) { if (isset($c['prices']['10'])) { $pick = $cid; break; } }
      $gid = $pick !== '' ? $pick : $gid;
    }
    if ($gid !== '') { $gift = $CATALOG[$gid]['name']; $summary[] = "FREE 10ml $gift mini"; }
  }
  /* coupon code (checked again here, never trusted from the browser): used only when it saves more than the multi-buy discount */
  $coupon = null; $discLabel = "Multi-buy $pct% off";
  if (is_string($in['coupon'] ?? null) && trim($in['coupon']) !== '') {
    $cp = fomaxo_coupon_apply($in['coupon'], $fullFils);
    if (isset($cp['error'])) return ['error' => $cp['error'] . ' Please remove it and try again.'];
    if ($cp['saveFils'] > $fullFils - $netFils) {
      foreach ($items as &$it) { $it['unit'] = $it['full']; $it['pct'] = 0; } unset($it);
      $pct = 0; $netFils = $fullFils - $cp['saveFils']; $coupon = $cp['code']; $discLabel = "Coupon {$cp['code']} ({$cp['label']})";
      $summary[] = "$discLabel: -" . fomaxo_aed($cp['saveFils']);
    }
  }
  return ['items' => $items, 'summary' => $summary, 'pct' => $pct, 'gift' => $gift, 'coupon' => $coupon, 'discLabel' => $discLabel,
          'fullFils' => $fullFils, 'discountFils' => $fullFils - $netFils, 'totalFils' => $netFils];
}

/* Delivery details from the checkout page. Returns ['error'=>...] or clean details. Used by every payment type.
   Address comes in separate boxes (villa/building no, room no / floor optional, street, area); email is optional. */
$EMIRATES = ['Dubai', 'Abu Dhabi', 'Sharjah', 'Ajman', 'Umm Al Quwain', 'Ras Al Khaimah', 'Fujairah'];
/* a real-looking phone number, any country — same rules as phoneOk() in index.html */
if (!function_exists('fomaxo_phone_ok')) { function fomaxo_phone_ok($v) {
  $v = trim(strtr((string)$v, FX_AR_DIGITS)); if (!preg_match('/^\+?[\d\s\-()]+$/', $v)) return false;
  $d = preg_replace('/\D/', '', $v); if (strpos($d, '00') === 0) $d = substr($d, 2);
  $n = strlen($d);
  if ($n < 9 || $n > 15 || preg_match('/^(\d)\1+$/', $d) || strpos('01234567890123456789', $d) !== false || strpos('98765432109876543210', $d) !== false) return false;
  if (strpos($d, '971') === 0) return (bool)preg_match('/^9710?[1-9]\d{7,8}$/', $d);
  if (strpos($d, '05') === 0) return $n === 10;
  return true;
} }
function fomaxo_customer($in) {
  global $EMIRATES;
  $c = is_array($in['customer'] ?? null) ? $in['customer'] : [];
  $t = fn($k, $max) => trim(mb_substr(preg_replace('/[\x00-\x1F\x7F\s]+/u', ' ', is_string($c[$k] ?? null) ? $c[$k] : ''), 0, $max));
  /* capital first letter of every word (the rest is kept as typed) — matches the checkout page */
  $caps = fn($v) => preg_replace_callback('/(^|[\s\-\/(])(\p{Ll})/u', fn($m) => $m[1] . mb_strtoupper($m[2]), $v);
  $out = ['name' => $t('name', 80), 'phone' => strtr($t('phone', 25), FX_AR_DIGITS), 'email' => $t('email', 120), 'emirate' => $t('emirate', 30),
          'building' => $t('building', 40), 'room' => $t('room', 20), 'street' => $t('street', 100), 'area' => $t('area', 80),
          'address' => $t('address', 300), 'note' => $t('note', 300), 'wa' => !empty($c['wa'])];   // wa: ticked "Send me offers and updates on WhatsApp"
  foreach (['name', 'building', 'room', 'street', 'area', 'address', 'note'] as $k) $out[$k] = $caps($out[$k]);
  if (mb_strlen($out['name']) < 2) return ['error' => 'Please enter your full name.'];
  if (!fomaxo_phone_ok($out['phone'])) return ['error' => 'Please enter a valid mobile number.'];
  if ($out['email'] !== '' && !filter_var($out['email'], FILTER_VALIDATE_EMAIL)) return ['error' => 'Please enter a valid email address.'];
  if (!in_array($out['emirate'], $EMIRATES, true)) return ['error' => 'Please choose your emirate.'];
  if ($out['building'] !== '' || $out['street'] !== '' || $out['area'] !== '') {
    if ($out['building'] === '' || mb_strlen($out['street']) < 2 || mb_strlen($out['area']) < 2) return ['error' => 'Please enter your full delivery address.'];
    $out['address'] = implode(', ', array_filter([$out['building'], $out['room'] !== '' ? 'Room/Floor ' . $out['room'] : '', $out['street'], $out['area']]));
  } elseif (mb_strlen($out['address']) < 5) {   // older page with a single address box
    return ['error' => 'Please enter your full delivery address.'];
  }
  return $out;
}

/* Keeps a private copy of every order OUTSIDE public_html (never public, never overwritten by GitHub). */
function fomaxo_log_order($row) {
  $dir = dirname(__DIR__) . '/fomaxo-orders';
  if (!is_dir($dir) && !@mkdir($dir, 0700, true)) return false;
  $new = !is_file($dir . '/orders.csv');
  $f = @fopen($dir . '/orders.csv', 'a'); if (!$f) return false;
  if ($new) fwrite($f, "\xEF\xBB\xBF");   // so Excel reads Arabic names correctly
  $row = array_map(fn($v) => preg_match('/^[=+\-@]/', (string)$v) && !preg_match('/^\+?[\d\s()\-]+$/', (string)$v) ? "'" . $v : $v, $row);   // stop spreadsheet formulas (phone numbers stay as typed)
  $ok = @fputcsv($f, $row) !== false; fclose($f); return $ok;
}
function fomaxo_aed($fils) { return 'AED ' . number_format($fils / 100, 2); }
