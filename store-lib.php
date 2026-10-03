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
    if (!isset($CATALOG[$id]) || !isset($CATALOG[$id]['prices'][$opt]) || $qty < 1 || $qty > 99)
      return ['error' => 'An item in your bag is no longer available. Please refresh and try again.'];
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
    $items[] = ['name' => $name, 'desc' => $desc, 'pct' => $linePct, 'unit' => $unit, 'qty' => $qty];
    $summary[] = "$qty x $name" . ($desc ? " ($desc)" : '');
  }

  $gift = null;
  if ($count >= $MINI_AT && !$hasMini) {
    $gid = is_string($in['gift'] ?? null) ? $in['gift'] : '';
    if (!isset($CATALOG[$gid]['prices']['10'])) { $gid = ''; foreach ($CATALOG as $cid => $c) { if (isset($c['prices']['10'])) { $gid = $cid; break; } } }
    if ($gid !== '') { $gift = $CATALOG[$gid]['name']; $summary[] = "FREE 10ml $gift mini"; }
  }
  return ['items' => $items, 'summary' => $summary, 'pct' => $pct, 'gift' => $gift,
          'fullFils' => $fullFils, 'discountFils' => $fullFils - $netFils, 'totalFils' => $netFils];
}

/* Delivery details sent from the bag. Returns ['error'=>...] or clean details. Used by every payment type. */
$EMIRATES = ['Dubai', 'Abu Dhabi', 'Sharjah', 'Ajman', 'Umm Al Quwain', 'Ras Al Khaimah', 'Fujairah'];
function fomaxo_customer($in) {
  global $EMIRATES;
  $c = is_array($in['customer'] ?? null) ? $in['customer'] : [];
  $t = fn($k, $max) => trim(mb_substr(preg_replace('/\s+/u', ' ', (string)($c[$k] ?? '')), 0, $max));
  $out = ['name' => $t('name', 80), 'phone' => $t('phone', 25), 'email' => $t('email', 120),
          'emirate' => $t('emirate', 30), 'address' => $t('address', 300), 'note' => $t('note', 300)];
  if (mb_strlen($out['name']) < 2) return ['error' => 'Please enter your full name.'];
  $digits = preg_replace('/\D/', '', $out['phone']);
  if (strlen($digits) < 9 || strlen($digits) > 15) return ['error' => 'Please enter a valid mobile number.'];
  if (!filter_var($out['email'], FILTER_VALIDATE_EMAIL)) return ['error' => 'Please enter a valid email address.'];
  if (!in_array($out['emirate'], $EMIRATES, true)) return ['error' => 'Please choose your emirate.'];
  if (mb_strlen($out['address']) < 6) return ['error' => 'Please enter your full delivery address.'];
  return $out;
}

/* Keeps a private copy of every order OUTSIDE public_html (never public, never overwritten by GitHub). */
function fomaxo_log_order($row) {
  $dir = dirname(__DIR__) . '/fomaxo-orders';
  if (!is_dir($dir) && !@mkdir($dir, 0700, true)) return false;
  $f = @fopen($dir . '/orders.csv', 'a'); if (!$f) return false;
  $ok = @fputcsv($f, $row) !== false; fclose($f); return $ok;
}
function fomaxo_aed($fils) { return 'AED ' . number_format($fils / 100, 2); }
