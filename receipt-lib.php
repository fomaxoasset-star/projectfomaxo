<?php
/* FOMAXO — the customer receipt for one order (AJAY, 20/10/2026).
   Sent only when a customer asks: admin → the order → Receipt → WhatsApp (a private link) or Email.
   receipt.php shows it at fomaxo.com/receipt.php?n=FMX-1001&t=… (t = a signature, so nobody can open other people's receipts by guessing numbers).
   Fits one A4 page; a long order carries on to more pages with the column heads repeated (Download PDF = the browser's Save as PDF).
   Courier and tracking number are typed on the order in admin and only appear when filled in. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }
require_once __DIR__ . '/orders-lib.php';
require_once __DIR__ . '/reviews-lib.php';   // rv_sign: the same private key as the review links

/* courier and tracking number on each order: added the first time they are needed (no schema step) */
function fx_receipt_cols($pdo) {
  static $done = false; if ($done) return; $done = true;
  try {
    if (!$pdo->query("SHOW COLUMNS FROM fx_orders LIKE 'courier'")->fetch())
      $pdo->exec("ALTER TABLE fx_orders ADD COLUMN courier VARCHAR(60) NOT NULL DEFAULT '', ADD COLUMN tracking VARCHAR(80) NOT NULL DEFAULT ''");
  } catch (Throwable $e) { error_log('FOMAXO receipt columns: ' . $e->getMessage()); }
}
function fx_receipt_token($no) { return substr(rv_sign('receipt|' . $no), 0, 20); }
function fx_receipt_url($no, $ar = false) { return 'https://fomaxo.com/receipt.php?n=' . rawurlencode($no) . '&t=' . fx_receipt_token($no) . ($ar ? '&l=ar' : ''); }
function fx_receipt_ok($no, $t) { return is_string($t) && $t !== '' && hash_equals(fx_receipt_token((string)$no), $t); }

/* words on the receipt, English and Arabic (product names, FOMAXO, AED and ml stay English) */
function fx_receipt_words($ar) {
  $en = ['tag' => 'Perfume & Personal Care', 'title' => 'Receipt', 'to' => 'Issued to', 'no' => 'Receipt no', 'date' => 'Date', 'name' => 'Name', 'mobile' => 'Mobile', 'email' => 'Email',
    'item' => 'Item', 'size' => 'Size', 'unit' => 'Unit price', 'qty' => 'Qty', 'amount' => 'Amount', 'free' => 'Free', 'gift' => 'Free gift', 'cgift' => 'Free with coupon',
    'sub' => 'Subtotal', 'multi' => 'Multi-buy discount', 'bt' => 'Bought together', 'coupon' => 'Coupon', 'disc' => 'Discount', 'codfee' => 'Cash on delivery fee',
    'delivery' => 'Delivery', 'sameday' => 'Same-day delivery', 'paid_total' => 'Total paid', 'due_total' => 'Amount to pay', 'total' => 'Total', 'saved' => 'You saved', 'saved2' => 'on this order',
    'address' => 'Address', 'grand' => 'Grand total', 'apt' => 'Apartment', 'building' => 'Building', 'street' => 'Street', 'area' => 'Area', 'emirate' => 'Emirate', 'status' => 'Status', 'ship' => 'Shipping', 'courier' => 'Courier', 'tracking' => 'Tracking no', 'payment' => 'Payment', 'cod' => 'Cash on delivery', 'card' => 'Card',
    'paid' => 'Paid', 'unpaid' => 'Unpaid', 'cancelled' => 'Cancelled', 'refunded' => 'Refunded', 'thanks' => 'Thank You For Your Purchase',
    'foot' => 'Dubai, United Arab Emirates', 'pdf' => 'Download PDF', 'eco' => 'Print (less ink)', 'page' => 'Page', 'sets' => 'Set of', 'vials' => 'vials', 'with' => 'Fragrances'];
  if (!$ar) return $en;
  return ['tag' => 'العطور والعناية الشخصية', 'title' => 'إيصال', 'to' => 'صادر إلى', 'no' => 'رقم الإيصال', 'date' => 'التاريخ', 'name' => 'الاسم', 'mobile' => 'الجوال', 'email' => 'البريد',
    'item' => 'المنتج', 'size' => 'الحجم', 'unit' => 'سعر الوحدة', 'qty' => 'الكمية', 'amount' => 'المبلغ', 'free' => 'مجاناً', 'gift' => 'هدية مجانية', 'cgift' => 'مجاناً مع الكوبون',
    'sub' => 'المجموع الفرعي', 'multi' => 'خصم الشراء المتعدد', 'bt' => 'خصم الشراء معاً', 'coupon' => 'كوبون', 'disc' => 'خصم', 'codfee' => 'رسوم الدفع عند الاستلام',
    'delivery' => 'التوصيل', 'sameday' => 'توصيل في نفس اليوم', 'paid_total' => 'المبلغ المدفوع', 'due_total' => 'المبلغ المستحق', 'total' => 'الإجمالي', 'saved' => 'وفّرت', 'saved2' => 'في هذا الطلب',
    'address' => 'العنوان', 'grand' => 'الإجمالي الكلي', 'apt' => 'الشقة', 'building' => 'المبنى', 'street' => 'الشارع', 'area' => 'المنطقة', 'emirate' => 'الإمارة', 'status' => 'الحالة', 'ship' => 'الشحن', 'courier' => 'شركة الشحن', 'tracking' => 'رقم التتبع', 'payment' => 'الدفع', 'cod' => 'الدفع عند الاستلام', 'card' => 'بطاقة',
    'paid' => 'مدفوع', 'unpaid' => 'غير مدفوع', 'cancelled' => 'ملغي', 'refunded' => 'مسترد', 'thanks' => 'شكراً لشرائك',
    'foot' => 'دبي، الإمارات العربية المتحدة', 'pdf' => 'تحميل PDF', 'eco' => 'طباعة (حبر أقل)', 'page' => 'صفحة', 'sets' => 'مجموعة من', 'vials' => 'عينات', 'with' => 'العطور'];
}

/* Everything the receipt shows, worked out from the saved order: lines with their prices, each discount, the gifts and the totals.
   Prices come from the order line when it has one (orders from 20/10/2026 on), else today's price list; if those no longer add up
   to the saved subtotal, the line prices are left out and only the saved totals are shown (never a made-up number). */
function fx_receipt_data($o) {
  $prods = [];
  foreach ((array)fomaxo_product_rows() as $r) { $d = json_decode((string)$r['data'], true); if (is_array($d)) $prods[$r['id']] = $d; }
  if (!$prods) { require_once __DIR__ . '/store-lib.php'; global $CATALOG; foreach ((array)$CATALOG as $id => $c) $prods[$id] = $c + ['family' => '']; }
  $live = fn($id) => isset($prods[$id]) ? fomaxo_sale_apply($prods[$id]) : null;
  $lines = []; $lj = json_decode((string)($o['lines_json'] ?? ''), true);
  if (is_array($lj) && $lj) {
    foreach ($lj as $l) {
      $id = (string)($l['id'] ?? ''); $opt = (string)($l['opt'] ?? ''); $p = $live($id) ?? ['name' => ucfirst($id)];
      $isSet = ($p['kind'] ?? '') === 'set';
      $price = isset($l['price']) ? (float)$l['price'] : (is_numeric($p['prices'][$opt] ?? null) ? (float)$p['prices'][$opt] : null);
      $was = is_numeric($p['compareAt'][$opt] ?? null) && $price !== null && (float)$p['compareAt'][$opt] > $price ? (float)$p['compareAt'][$opt] : null;
      $picks = $isSet ? array_values(array_filter(array_map(fn($x) => is_string($x) && isset($prods[$x]) ? (string)$prods[$x]['name'] : '', (array)($l['picks'] ?? [])))) : [];
      $lines[] = ['name' => (string)($p['name'] ?? $id), 'family' => (string)($p['family'] ?? ''), 'set' => $isSet, 'opt' => $opt, 'qty' => max(1, (int)($l['qty'] ?? 1)),
                  'price' => $price, 'was' => $was, 'picks' => $picks, 'free' => !empty($l['free']) ? (!empty($l['coupon']) ? 'coupon' : 'gift') : ''];
    }
  } else {   // an order without saved lines: read them from the items text ("2 x FOMAXO Gold — 100ml")
    foreach (array_map('trim', explode('|', (string)$o['items'])) as $row) {
      if (!preg_match('/^(\d+) x FOMAXO (.+?) — (?:Set of )?(\d+)(?:ml)?(?: \((?:Fragrances: )?(.*)\))?(?: — FREE.*)?$/u', $row, $m)) continue;
      $id = ''; foreach ($prods as $k => $d) if (($d['name'] ?? '') === $m[2]) $id = $k;
      $p = $id !== '' ? $live($id) : ['name' => $m[2]];
      $lines[] = ['name' => $m[2], 'family' => (string)($p['family'] ?? ''), 'set' => ($p['kind'] ?? '') === 'set', 'opt' => $m[3], 'qty' => (int)$m[1],
                  'price' => is_numeric($p['prices'][$m[3]] ?? null) ? (float)$p['prices'][$m[3]] : null, 'was' => null, 'picks' => isset($m[4]) && $m[4] !== '' ? explode(', ', $m[4]) : [],
                  'free' => str_contains($row, 'FREE with coupon') ? 'coupon' : ''];
    }
    if (!empty($o['free_mini'])) {
      $id = ''; foreach ($prods as $k => $d) if (($d['name'] ?? '') === $o['free_mini']) $id = $k;
      $p = $id !== '' ? $live($id) : null;
      $lines[] = ['name' => (string)$o['free_mini'], 'family' => (string)($p['family'] ?? ''), 'set' => false, 'opt' => '10', 'qty' => 1,
                  'price' => is_numeric($p['prices']['10'] ?? null) ? (float)$p['prices']['10'] : null, 'was' => null, 'picks' => [], 'free' => 'gift'];
    }
  }
  /* the line prices must add up to the subtotal the customer saw */
  $sum = 0.0; $priced = true;
  foreach ($lines as $l) if (!$l['free']) { if ($l['price'] === null) $priced = false; else $sum += $l['price'] * $l['qty']; }
  if ($o['subtotal'] !== null && abs($sum - (float)$o['subtotal']) > 0.01) $priced = false;
  if (!$priced) foreach ($lines as &$l) { if (!$l['free']) { $l['price'] = null; $l['was'] = null; } } unset($l);

  /* discounts: the order keeps each one as "Label: -AED 12.00" in its items; a card order keeps its multi-buy inside the prices, so what is left is the multi-buy */
  $disc = []; $listed = 0.0;
  foreach (array_map('trim', explode('|', (string)$o['items'])) as $row)
    if (preg_match('/^(.+?):\s*-\s*AED\s*([\d,]+(?:\.\d+)?)$/u', $row, $m)) {
      $a = (float)str_replace(',', '', $m[2]); $listed += $a;
      /* a cash order keeps "Multi-buy discount (10%) + Coupon CODE (AED 20 off)" as one amount: the multi-buy part is worked out from the prices, the coupon is the rest */
      if ($priced && preg_match('/^Multi-buy discount \((\d+(?:\.\d+)?)%\) \+ (Coupon .+)$/u', $m[1], $q)) {
        $base = 0.0; foreach ($lines as $l) if (!$l['free'] && !in_array(mb_strtolower($l['name']), ['king', 'discovery set'], true)) $base += $l['price'] * $l['qty'];
        $mb = round($base * (float)$q[1] / 100, 2);
        if ($mb > 0 && $mb < $a) { $disc[] = ['label' => "Multi-buy discount ({$q[1]}%)", 'amt' => $mb]; $disc[] = ['label' => $q[2], 'amt' => round($a - $mb, 2)]; continue; }
      }
      $disc[] = ['label' => $m[1], 'amt' => $a];
    }
  $left = round((float)$o['discount'] - $listed, 2);
  if ($left > 0.009) {
    $n = 0; foreach ($lines as $l) if (!$l['free'] && !in_array(mb_strtolower($l['name']), ['king', 'discovery set'], true)) $n += $l['qty'];
    $pct = $n >= 5 ? 15 : ($n >= 3 ? 10 : ($n >= 2 ? 5 : 0));
    array_unshift($disc, ['label' => 'Multi-buy discount' . ($pct ? " ($pct%)" : ''), 'amt' => $left]);
  }
  $save = (float)$o['discount'];
  foreach ($lines as $l) {
    if ($l['was'] !== null) $save += ($l['was'] - $l['price']) * $l['qty'];
    if ($l['free'] && $l['price'] !== null) $save += $l['price'] * $l['qty'];
  }
  $st = (string)$o['status'];
  $pay = in_array($st, ['Cancelled', 'Refunded'], true) ? strtolower($st) : (in_array($st, ['Paid', 'Delivered'], true) ? 'paid' : 'unpaid');
  /* same-day delivery (checkout from 20/10/2026): kept as "Same-day delivery (Area, Emirate): AED 25.00" or ": FREE" in the items; null = standard, free */
  $sd = preg_match('/(?:^|\|)\s*Same-day delivery \([^|]*\):\s*(FREE|AED\s*([\d,]+(?:\.\d+)?))\s*(?:\||$)/u', (string)$o['items'], $m) ? (isset($m[2]) && $m[2] !== '' ? (float)str_replace(',', '', $m[2]) : 0.0) : null;
  return ['lines' => $lines, 'priced' => $priced, 'disc' => $disc, 'sd' => $sd, 'save' => round($save, 2), 'pay' => $pay,
          'cod' => stripos((string)$o['payment'], 'cash') !== false, 'sub' => $o['subtotal'] !== null ? (float)$o['subtotal'] : $sum];
}

/* a discount label in Arabic: the words change, a coupon code and the % stay */
function fx_receipt_disc_label($label, $w) {
  return strtr($label, ['Multi-buy discount' => $w['multi'], 'Multi-buy' => $w['multi'], 'Bought together' => $w['bt'], 'Coupon' => $w['coupon'], ' off' => '']);
}
function fx_receipt_money($v) { return '<span class="m"><small>AED</small>' . number_format((float)$v, 2) . '</span>'; }

/* the receipt page (receipt.php). $admin: opened from admin, so the language switch keeps the same link */
function fx_receipt_page($o, $ar = false) {
  $w = fx_receipt_words($ar); $d = fx_receipt_data($o); $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
  $M = 'fx_receipt_money';
  $rows = '';
  foreach ($d['lines'] as $l) {
    $size = $l['set'] ? $w['sets'] . ' ' . $l['opt'] : $l['opt'] . 'ml';
    $sub = $l['set'] && $l['picks'] ? $w['with'] . ': ' . implode(', ', $l['picks']) : $l['family'];
    $tag = $l['free'] ? '<span class="gift">' . $e($l['free'] === 'coupon' ? $w['cgift'] : $w['gift']) . '</span>' : '';
    if ($l['free']) {
      $unit = $l['price'] !== null ? '<s>' . $M($l['was'] ?? $l['price']) . '</s>' : '';
      $amt = '<b class="fr">' . $e($w['free']) . '</b>';
    } else {
      $unit = $l['price'] === null ? '<span class="dash">—</span>' : ($l['was'] !== null ? '<s>' . $M($l['was']) . '</s>' : '') . $M($l['price']);
      $amt = $l['price'] === null ? '<span class="dash">—</span>' : $M($l['price'] * $l['qty']);
    }
    $rows .= '<tr><td class="c-it"><b class="pn">' . $e($l['name']) . '</b>' . $tag . ($sub !== '' ? '<span class="pf">' . $e($sub) . '</span>' : '') . '</td>'
           . '<td class="c-sz">' . $e($size) . '</td><td class="c-un">' . $unit . '</td><td class="c-q">' . (int)$l['qty'] . '</td><td class="c-am">' . $amt . '</td></tr>';
  }
  /* under the items, like AJAY's Excel invoice (12/11/2026): address on the left; subtotal, delivery, grand total (only with a discount), discounts in red and the big total on the right */
  $sum = '<div class="tr"><span>' . $e($w['sub']) . '</span>' . $M($d['sub']) . '</div>';
  $sum .= '<div class="tr"><span>' . $e($d['sd'] !== null ? $w['sameday'] : $w['delivery']) . '</span>' . ($d['sd'] ? $M($d['sd']) : '<b class="fr">' . $e($w['free']) . '</b>') . '</div>';
  if ((float)$o['fee'] > 0) $sum .= '<div class="tr"><span>' . $e($w['codfee']) . '</span>' . $M($o['fee']) . '</div>';
  if ($d['disc']) {
    $grand = (float)$o['total']; foreach ($d['disc'] as $x) $grand += $x['amt'];   // the total before the discounts, so the red lines take it down to the total
    $sum .= '<div class="tr gt"><span>' . $e($w['grand']) . '</span>' . $M($grand) . '</div>';
  }
  foreach ($d['disc'] as $x) $sum .= '<div class="tr dc"><span>' . $e($ar ? fx_receipt_disc_label($x['label'], $w) : $x['label']) . '</span><span class="m">−&nbsp;<small>AED</small>' . number_format($x['amt'], 2) . '</span></div>';
  $sum .= '<div class="tt"><span>' . $e($d['pay'] === 'paid' ? $w['paid_total'] : ($d['pay'] === 'unpaid' ? $w['due_total'] : $w['total'])) . '</span>' . $M($o['total']) . '</div>';

  $t = fn($x) => trim((string)$x);
  $nameUp = mb_strtoupper($t($o['name']), 'UTF-8');
  $addr = [[$w['name'], $nameUp], [$w['mobile'], $t($o['phone'])], [$w['apt'], $t($o['room'] ?? '')], [$w['building'], $t($o['building'] ?? '')],
           [$w['street'], $t($o['street'] ?? '')], [$w['area'], $t($o['area'] ?? '')], [$w['emirate'], $t($o['emirate'])]];
  if ($t($o['room'] ?? '') . $t($o['building'] ?? '') . $t($o['street'] ?? '') . $t($o['area'] ?? '') === '' && $t($o['address']) !== '')   // older orders keep the address as one line
    array_splice($addr, 2, 4, [[$w['address'], $t($o['address'])]]);
  $adl = ''; foreach ($addr as [$k, $v]) if ($v !== '') $adl .= '<dt>' . $e($k) . '</dt><dd>' . ($k === $w['mobile'] ? '<bdi dir="ltr">' . $e($v) . '</bdi>' : $e($v)) . '</dd>';
  $ship = '';
  if ($t($o['courier'] ?? '') !== '') $ship .= '<div><span>' . $e($w['courier']) . '</span><b>' . $e(mb_strtoupper($t($o['courier']), 'UTF-8')) . '</b></div>';
  if ($t($o['tracking'] ?? '') !== '') $ship .= '<div><span>' . $e($w['tracking']) . '</span><b class="trk" dir="ltr">' . $e($o['tracking']) . '</b></div>';
  $payWord = $d['cod'] ? $w['cod'] : $w['card'];
  $no = (string)$o['order_no']; $date = date('d/m/Y', strtotime((string)$o['created_at']));
  $sw = '?n=' . rawurlencode($no) . '&t=' . fx_receipt_token($no);

  return '<!doctype html><html lang="' . ($ar ? 'ar' : 'en') . '" dir="' . ($ar ? 'rtl' : 'ltr') . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
    . '<meta name="robots" content="noindex,nofollow"><title>FOMAXO ' . $e($w['title']) . ' ' . $e($no) . '</title><link rel="icon" href="/assets/img/favicon-32.png">'
    . '<style>' . fx_receipt_css() . ':root{--wmprint:url("data:image/svg+xml,' . rawurlencode(fx_receipt_wm_svg()) . '")}</style></head><body>'
    . '<div class="bar"><a class="lng" href="' . $e($sw . ($ar ? '' : '&l=ar')) . '"' . ($ar ? '' : ' lang="ar"') . '>' . ($ar ? 'English' : 'عربي') . '</a>'
    . '<button type="button" class="pdf" onclick="window.print()">' . $e($w['pdf']) . '</button><button type="button" class="eco-b" onclick="fxEco()">' . $e($w['eco']) . '</button></div>'
    . '<main class="sheet">'
    . '<header class="hd"><svg class="em" viewBox="0 0 2507 2506" aria-hidden="true"><use href="#emblem"/></svg><svg class="wm" viewBox="0 0 3196 481" role="img" aria-label="FOMAXO"><use href="#wordmark"/></svg>'
    . '<p class="tg">' . $e($w['tag']) . '</p><h1><span>' . $e($w['title']) . '</span></h1></header>'
    . '<section class="meta"><div><h2>' . $e($w['to']) . '</h2><dl><dt>' . $e($w['name']) . '</dt><dd>' . $e($nameUp) . '</dd>'
    . ($o['phone'] !== '' ? '<dt>' . $e($w['mobile']) . '</dt><dd><bdi dir="ltr">' . $e($o['phone']) . '</bdi></dd>' : '') . ($o['email'] !== '' ? '<dt>' . $e($w['email']) . '</dt><dd>' . $e($o['email']) . '</dd>' : '') . '</dl></div>'
    . '<div class="rn"><h2>' . $e($w['no']) . '</h2><p class="no" dir="ltr">#' . $e($no) . '</p><p class="dt"><span>' . $e($w['date']) . '</span> ' . $e($date) . '</p></div></section>'
    . '<table class="it"><thead><tr><th class="c-it">' . $e($w['item']) . '</th><th class="c-sz">' . $e($w['size']) . '</th><th class="c-un">' . $e($w['unit']) . '</th><th class="c-q">' . $e($w['qty']) . '</th><th class="c-am">' . $e($w['amount']) . '</th></tr></thead><tbody>' . $rows . '</tbody></table>'
    . '<section class="bt"><div class="lf"><h3>' . $e($w['address']) . '</h3><dl class="ad">' . $adl . '</dl></div><div class="sm">' . $sum . '</div></section>'
    . '<section class="bx">' . ($ship !== '' ? '<div class="box sh"><h4>' . $e($w['ship']) . '</h4>' . $ship . '</div>' : '')
    . '<div class="box py"><h4>' . $e($w['payment']) . '</h4><div class="pr"><b>' . $e($payWord) . '</b><b class="st st-' . $d['pay'] . '">' . $e($w[$d['pay']]) . '</b></div></div></section>'
    . '<footer class="ft"><p class="ty">' . $e($w['thanks']) . '</p><p class="si"><span dir="ltr">fomaxo.com</span><br>' . $e($w['foot']) . '</p></footer>'
    . '</main><div class="wmk" aria-hidden="true"><svg viewBox="0 0 2507 2506"><use href="#emblem"/></svg></div><script>/* Print (less ink): the same receipt in black and white, thin lines and no filled areas (AJAY 12/11/2026); ?eco=1 shows it on screen */var fxR=document.documentElement,fxE=/[?&]eco=1/.test(location.search);if(fxE)fxR.classList.add("eco");function fxEco(){fxR.classList.add("eco");print()}addEventListener("afterprint",function(){if(!fxE)fxR.classList.remove("eco")});(function(){var s=document.querySelector(".sheet"),r=document.documentElement;addEventListener("beforeprint",function(){r.classList.add("pm");var mm=s.offsetWidth/186,n=Math.max(1,Math.ceil((s.offsetHeight+2*mm)/(271*mm)));r.classList.remove("pm");s.style.setProperty("--pages",n);r.classList.toggle("p1",n<2)})})();if(/[?&]print=1/.test(location.search))addEventListener("load",function(){(document.fonts?document.fonts.ready:Promise.resolve()).then(function(){setTimeout(print,200)})})</script><svg width="0" height="0" style="position:absolute" aria-hidden="true">' . FX_RECEIPT_SYMBOLS . '</svg></body></html>';
}

/* the watermark for the PDF, as a picture one A4 page tall (186 x 271 mm) with the logo in the middle */
function fx_receipt_wm_svg() {
  preg_match_all('/<path[^>]*\/>/', FX_RECEIPT_SYMBOLS, $m);
  return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1860 2710"><g fill="#0b0b0b" opacity=".05">'
    . '<g transform="translate(554 979) scale(.3)">' . str_replace('currentColor', '#0b0b0b', $m[0][0] ?? '') . '</g></g></svg>';
}

function fx_receipt_css() { return <<<'CSS'
@font-face{font-family:'Cormorant Garamond';font-style:normal;font-weight:300 600;font-display:swap;src:url(/assets/fonts/cormorant-latin.woff2?v=1) format('woff2')}
@font-face{font-family:'Jost';font-style:normal;font-weight:300 500;font-display:swap;src:url(/assets/fonts/jost-latin.woff2?v=1) format('woff2')}
@font-face{font-family:'Charles Wright Singapore';src:url(/assets/fonts/CharlesWrightSingapore.woff2) format('woff2');font-display:swap}
@font-face{font-family:'Tajawal';font-weight:400;font-display:swap;src:url(/assets/fonts/tajawal-400-arabic.woff2?v=1) format('woff2')}
@font-face{font-family:'Tajawal';font-weight:700;font-display:swap;src:url(/assets/fonts/tajawal-700-arabic.woff2?v=1) format('woff2')}
:root{--bg:#efebe3;--paper:#fff;--ink:#0b0b0b;--soft:#3d3a35;--muted:#6f685c;--line:#e6dfcf;--gold:#9a7a2c;--gold2:#c9a64b;--band:#0b0b0b;--bandink:#d4af37;--zebra:#faf8f3;--red:#a3261b;--green:#2f6b3a;color-scheme:light}
@media (prefers-color-scheme:dark){:root{--bg:#050505;--paper:#111010;--ink:#f3eee3;--soft:#d8d1c3;--muted:#a9a091;--line:#2c2820;--gold:#d4af37;--gold2:#d4af37;--band:#000;--bandink:#d4af37;--zebra:#171513;--red:#ff8a7a;--green:#8fd19e;color-scheme:dark}}
*{box-sizing:border-box;font-style:normal}
html{-webkit-text-size-adjust:100%}
body{margin:0;background:var(--bg);color:var(--ink);font:400 14px/1.45 Jost,Tajawal,system-ui,sans-serif;padding:16px 12px 32px}
[dir=rtl] body,[dir=rtl] .pn{font-family:Tajawal,Jost,system-ui,sans-serif}
.bar{max-width:794px;margin:0 auto 12px;display:flex;justify-content:flex-end;gap:8px}
.bar a,.bar button{font:500 13px/1 Jost,Tajawal,sans-serif;letter-spacing:.06em;text-transform:uppercase;padding:11px 18px;border-radius:999px;cursor:pointer;text-decoration:none}
.bar .lng{border:1px solid var(--line);color:var(--ink);background:var(--paper)}
.bar .pdf{border:0;background:var(--gold);color:#fff}
.bar .eco-b{border:1px solid var(--ink);background:var(--paper);color:var(--ink)}
@media (prefers-color-scheme:dark){.bar .pdf{color:#0b0b0b}}
.sheet{max-width:794px;margin:0 auto;background:var(--paper);border-top:6px solid var(--band);border-bottom:6px solid var(--band);box-shadow:0 10px 40px rgba(0,0,0,.08);padding:30px 44px 26px;position:relative}
.sheet::before,.sheet::after{content:"";position:absolute;left:0;right:0;height:1px;background:var(--gold2)}
.sheet::before{top:4px}.sheet::after{bottom:4px}
/* the FOMAXO logo as a faint watermark in the middle (on every page of the PDF) */
.wmk{position:fixed;left:50%;top:50%;width:min(460px,72vw);transform:translate(-50%,-50%);color:var(--ink);opacity:.045;pointer-events:none;z-index:2;display:flex;flex-direction:column;align-items:center;gap:6%}
.wmk svg{display:block;width:70%;height:auto}.wmk svg+svg{width:100%;aspect-ratio:3196/481}
@media (prefers-color-scheme:dark){.wmk{opacity:.06}}
.hd{text-align:center;color:var(--ink)}
.hd .em{width:52px;height:52px;display:block;margin:0 auto 10px;color:var(--ink)}
.hd .wm{height:26px;width:auto;aspect-ratio:3196/481;display:block;margin:0 auto;color:var(--ink)}
.hd .tg{margin:8px 0 0;font-size:10.5px;letter-spacing:.32em;text-transform:uppercase;color:var(--muted)}
.hd h1{margin:16px 0 0;display:flex;align-items:center;gap:16px;font:500 26px/1 'Cormorant Garamond',Tajawal,serif;letter-spacing:.42em;text-transform:uppercase;color:var(--gold)}
.hd h1::before,.hd h1::after{content:"";flex:1;height:1px;background:linear-gradient(90deg,transparent,var(--gold2))}
.hd h1::after{background:linear-gradient(270deg,transparent,var(--gold2))}
.hd h1 span{padding-left:.42em}
[dir=rtl] .hd h1{letter-spacing:0}[dir=rtl] .hd h1 span{padding:0}
h2{margin:0 0 6px;font:500 10.5px/1.2 Jost,Tajawal,sans-serif;letter-spacing:.22em;text-transform:uppercase;color:var(--gold)}
[dir=rtl] h2,[dir=rtl] .tg,[dir=rtl] th{letter-spacing:0}
.meta{display:flex;justify-content:space-between;gap:16px;margin:22px 0 18px}
.meta dl{margin:0;display:grid;grid-template-columns:auto 1fr;gap:3px 14px}
.meta dt{color:var(--muted);font-size:12px;text-transform:uppercase;letter-spacing:.08em}
.meta dd{margin:0;font-weight:500;overflow-wrap:anywhere}
.meta .rn{text-align:end}
.meta .no{margin:0;font:500 24px/1.1 'Charles Wright Singapore',Jost,sans-serif;letter-spacing:.02em}
.meta .dt{margin:6px 0 0;font-weight:500}.meta .dt span{color:var(--muted);font-size:12px;text-transform:uppercase;letter-spacing:.08em;margin-inline-end:6px}
.it{width:100%;border-collapse:collapse;margin:0}
.it th{font:500 10.5px/1.2 Jost,Tajawal,sans-serif;letter-spacing:.16em;text-transform:uppercase;color:var(--paper);background:var(--ink);padding:8px 10px;text-align:start}
.it td{padding:5px 10px;border-bottom:1px solid var(--line);vertical-align:middle}
.it tbody tr:nth-child(even) td{background:var(--zebra)}
.it tr{break-inside:avoid;page-break-inside:avoid}
.it thead{display:table-header-group}
.it .c-sz,.it .c-q{text-align:center;white-space:nowrap}
.it .c-un,.it .c-am{text-align:end;white-space:nowrap}
.it .c-am{font-weight:500}
.pn{display:inline;font:600 15.5px/1.15 'Cormorant Garamond',Tajawal,serif;letter-spacing:.06em;text-transform:uppercase}
.pf{display:block;font-size:10.5px;line-height:1.2;color:var(--muted);margin-top:1px}
.gift{display:inline-block;margin-inline-start:8px;padding:1px 7px;border:1px solid var(--gold2);border-radius:999px;font-size:9.5px;letter-spacing:.14em;text-transform:uppercase;color:var(--gold);vertical-align:2px}
.m{font-family:'Charles Wright Singapore',Jost,sans-serif;white-space:nowrap;direction:ltr;unicode-bidi:isolate}
.m small{font-size:.68em;margin-right:3px;letter-spacing:.04em}
s{display:inline-block;position:relative;color:var(--muted);font-size:11px;text-decoration:none;margin-inline-end:6px}
s::after{content:"";position:absolute;left:-1px;right:-1px;top:52%;height:1.2px;background:currentColor}   /* the price font draws its own strike line at the bottom, so the line is drawn here through the middle */
.fr{color:var(--gold);letter-spacing:.14em;text-transform:uppercase;font-size:12px;font-weight:500}
[dir=rtl] .fr{letter-spacing:0}
.dash{color:var(--muted)}
.bt{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.3fr);gap:24px;margin-top:22px;break-inside:avoid;page-break-inside:avoid}
h3{margin:0 0 8px;font:500 19px/1.1 Jost,Tajawal,sans-serif;letter-spacing:.08em;text-transform:uppercase;color:var(--ink)}
.ad{margin:0;display:grid;grid-template-columns:auto 1fr;gap:3px 16px;font-size:13px;line-height:1.45}
.ad dt{color:var(--muted);font-size:11.5px;text-transform:uppercase;letter-spacing:.08em;line-height:1.7}
.ad dd{margin:0;font-weight:500;overflow-wrap:anywhere}
.sm .tr{display:grid;grid-template-columns:1fr auto;gap:22px;align-items:baseline;padding:4px 0;font-size:13.5px}
.sm .tr>span:first-child{text-align:end;font-weight:500;font-size:12px;letter-spacing:.12em;text-transform:uppercase;color:var(--soft)}
.sm .gt>span:first-child{font-size:15px;color:var(--ink);letter-spacing:.1em}.sm .gt .m{font-size:16px}
.sm .dc,.sm .dc>span:first-child{color:var(--red)}
.sm .tt{display:grid;grid-template-columns:1fr auto;gap:16px;align-items:baseline;margin-top:10px;padding-top:12px;border-top:2px solid var(--ink);padding-bottom:12px;border-bottom:2px solid var(--ink)}
.sm .tt>span:first-child{text-align:end;white-space:nowrap;font:500 22px/1 Jost,Tajawal,sans-serif;letter-spacing:.06em;text-transform:uppercase}
[dir=rtl] .sm .tr>span:first-child,[dir=rtl] .sm .tt>span:first-child,[dir=rtl] h3,[dir=rtl] .ad dt,[dir=rtl] .box h4,[dir=rtl] .box span,[dir=rtl] .box b{letter-spacing:0}
.sm .tt .m{font-size:30px;line-height:1}
.bx{width:58%;margin:24px auto 0;display:grid;gap:12px;break-inside:avoid;page-break-inside:avoid}
.box{border:1.5px solid var(--ink)}
.box h4{margin:0;padding:7px 10px;text-align:center;font:500 11.5px/1.2 Jost,Tajawal,sans-serif;letter-spacing:.24em;text-transform:uppercase;border-bottom:1.5px solid var(--ink)}
.sh div{display:flex;justify-content:space-between;gap:12px;padding:9px 16px;font-size:13px}.sh div+div{border-top:1px solid var(--line)}
.sh span{color:var(--gold);font-weight:500;letter-spacing:.1em;text-transform:uppercase;font-size:12px}.sh b{font-weight:500;text-align:end;overflow-wrap:anywhere;letter-spacing:.06em}
.sh .trk{font-family:'Charles Wright Singapore',Jost,sans-serif}
.py .pr{display:grid;grid-template-columns:1fr 1fr}
.py .pr b{padding:11px 10px;text-align:center;font-weight:500;font-size:13px;letter-spacing:.12em;text-transform:uppercase}
.py .pr b+b{border-inline-start:1.5px solid var(--ink)}
.st-paid{color:var(--gold)}
.st-unpaid,.st-cancelled,.st-refunded{color:var(--red)}
.ft{text-align:center;margin-top:24px;break-inside:avoid;page-break-inside:avoid}
.ty{margin:0;font:600 24px/1.15 Jost,Tajawal,sans-serif;letter-spacing:.01em}
.ty::after{content:"";display:block;width:60px;height:1.5px;background:var(--gold2);margin:12px auto 0}
.si{margin:10px 0 0;font-size:11px;line-height:1.7;letter-spacing:.18em;text-transform:uppercase;color:var(--muted)}
[dir=rtl] .si{letter-spacing:0}
@media (max-width:640px){
  body{padding:10px 8px 24px;font-size:13px}
  .bar{justify-content:center;flex-wrap:wrap}
  .sheet{padding:22px 16px 20px}
  .hd .em{width:42px;height:42px}.hd .wm{height:20px}.hd h1{font-size:21px;gap:10px}
  .meta{flex-direction:column;gap:14px;margin:18px 0 14px}.meta .rn{text-align:start}.meta .no{font-size:20px}
  .it .c-un{display:none}.it th{padding:7px 6px}.it td{padding:5px 6px}.it th{letter-spacing:.08em;font-size:9.5px}.pn{font-size:15px}
  .bt{grid-template-columns:1fr;gap:22px}.bt .sm{order:-1}.bx{width:100%;margin-top:20px}
  .sm .tt>span:first-child{font-size:19px}.sm .tt .m{font-size:27px}.ty{font-size:21px}
}
/* .eco: Print (less ink) — black and white, thin lines, nothing filled, no watermark; words (PAID / UNPAID, the minus sign) carry the meaning, not colours */
html.eco{--bg:#fff;--paper:#fff;--ink:#000;--soft:#222;--muted:#555;--line:#bbb;--gold:#000;--gold2:#999;--band:#000;--zebra:#fff;--red:#000;color-scheme:light}
.eco .sheet{border-top:1px solid #000;border-bottom:1px solid #000;box-shadow:none;background:#fff!important}
.eco .sheet::before,.eco .sheet::after,.eco .wmk{display:none}
.eco .hd .em{width:34px;height:34px;margin-bottom:6px}.eco .hd .wm{height:20px}
.eco .hd h1{font-size:20px;color:#000}.eco .hd h1::before,.eco .hd h1::after{background:#bbb}
.eco h2{color:#000}
.eco .it th{background:none;color:#000;border-top:1px solid #000;border-bottom:1px solid #000;font-weight:600}
.eco .it tbody tr td{background:none!important}
.eco .gift{border-color:#999;color:#000}
.eco .fr{color:#000}
.eco .sm .gt>span:first-child,.eco .sm .tt>span:first-child{font-weight:500}
.eco .sm .tt{border-top-width:1px;border-bottom-width:1px}.eco .sm .tt .m{font-size:26px}
.eco .box,.eco .box h4,.eco .py .pr b+b{border-width:1px}
.eco .sh span{color:#000}
.eco .st-paid,.eco .st-unpaid,.eco .st-cancelled,.eco .st-refunded{color:#000;font-weight:600}
.eco .ty{font-weight:500;font-size:20px}.eco .ty::after{background:#bbb}
/* .pm: the printed page's width and text size, used for a moment before printing to count the pages (so each page gets its watermark) */
.pm .bar{display:none}.pm body{padding:0;font-size:12.5px}.pm .sheet{width:186mm;max-width:none;padding:6mm 6mm 4mm;border-width:4px}.pm .it .c-un{display:table-cell!important}
@page{size:A4;margin:12mm 12mm 14mm}
@page{@bottom-center{content:counter(page) " / " counter(pages);font:9px Jost,sans-serif;color:#8a8273}}
@media print{
  :root{--bg:#fff;--paper:#fff;--ink:#0b0b0b;--soft:#3d3a35;--muted:#6f685c;--line:#e6dfcf;--gold:#9a7a2c;--gold2:#c9a64b;--zebra:#faf8f3;--red:#a3261b;color-scheme:light}
  body{padding:0;background:#fff;font-size:12.5px;-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .bar{display:none}
  .wmk{display:none}
  .sheet{max-width:none;box-shadow:none;padding:6mm 6mm 4mm;border-width:4px;min-height:calc(var(--pages,1) * 271mm - 20mm);background:var(--wmprint) center top/100% 271mm repeat-y;-webkit-print-color-adjust:exact;print-color-adjust:exact}   /* one watermark per A4 page: the picture is one page tall (297mm less the page margins) */
  .p1 .sheet{min-height:0;background-size:contain;background-position:center;background-repeat:no-repeat}   /* one page: no fixed height (some browsers, like Safari, print a shorter page), the watermark in the middle of the receipt */
  .it tbody tr:nth-child(even) td{background:rgba(120,100,50,.05)}
  .it .c-un{display:table-cell!important}
  .eco .sheet{background:none!important;border-width:1px}
  .meta{margin:16px 0 12px}.bt{margin-top:14px}.bx{margin-top:16px;gap:9px}.box h4{padding:5px 10px}.sh div{padding:6px 14px}.py .pr b{padding:8px 10px}
  .ft{margin-top:16px}.ty{font-size:22px}.ty::after{margin-top:8px}.sm .tt .m{font-size:30px}.sm .tt>span:first-child{font-size:22px}
}
CSS; }

/* The receipt email: the same receipt as the receipt page (logo, Issued to, Receipt no, items, Address beside the totals,
   Shipping and Payment boxes, Thank You For Your Purchase), built from tables with the styles written on each part, because email apps
   (Gmail, Outlook, Apple Mail) drop page styles. Phones: the Unit price column hides and the Address comes above the totals. (AJAY 14 Nov 2026) */
function fx_receipt_mail_html(array $r) {
  $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
  /* the receipt page's own fonts and sizes (Jost, Cormorant Garamond, and on fomaxo.com Charles Wright for numbers), loaded from the website.
     Apple Mail and iPhone Mail show them; Gmail and Outlook do not load web fonts, so they show the nearest standard font in the same sizes. */
  $J = "font-family:Jost,'Helvetica Neue',Helvetica,Arial,sans-serif;"; $C = "font-family:'Cormorant Garamond',Georgia,'Times New Roman',serif;";
  $N = $r['num'] !== '' ? "font-family:'" . $r['num'] . "',Jost,'Helvetica Neue',Helvetica,Arial,sans-serif;" : $J;
  $ink = '#0b0b0b'; $soft = '#3d3a35'; $muted = '#6f685c'; $line = '#e6dfcf'; $gold = '#9a7a2c'; $gold2 = '#c9a64b'; $red = '#a3261b';
  $lab = $J . 'font-size:12px;font-weight:400;letter-spacing:.08em;text-transform:uppercase;color:' . $muted . ';';
  $h2 = $J . 'font-size:10.5px;font-weight:500;letter-spacing:.22em;text-transform:uppercase;color:' . $gold . ';';
  $dl = function (array $rows, $labCss, $valCss) use ($e) {
    $h = '';
    foreach ($rows as [$k, $v]) if ((string)$v !== '') $h .= '<tr><td style="' . $labCss . 'padding:1.5px 14px 1.5px 0;vertical-align:top;white-space:nowrap">' . $e($k) . '</td><td style="' . $valCss . 'padding:1.5px 0;word-break:break-word">' . $e($v) . '</td></tr>';
    return '<table role="presentation" cellpadding="0" cellspacing="0" border="0">' . $h . '</table>';
  };
  $th = $J . 'font-size:10.5px;font-weight:500;letter-spacing:.16em;text-transform:uppercase;color:#ffffff;background:' . $ink . ';padding:8px 10px;';
  $td = $J . 'font-size:14px;color:' . $ink . ';padding:5px 10px;border-bottom:1px solid ' . $line . ';vertical-align:middle;';
  $rows = ''; $i = 0;
  foreach ($r['rows'] as $l) {
    $bg = $i++ % 2 ? 'background:#faf8f3;' : 'background:#ffffff;';
    $rows .= '<tr><td style="' . $td . $bg . '"><span style="' . $C . 'font-size:15.5px;font-weight:600;line-height:1.15;letter-spacing:.06em;text-transform:uppercase">' . $e($l['name']) . '</span>'
      . ($l['tag'] !== '' ? ' <span style="display:inline-block;margin-left:6px;border:1px solid ' . $gold2 . ';border-radius:999px;padding:1px 7px;' . $J . 'font-size:9.5px;letter-spacing:.14em;text-transform:uppercase;color:' . $gold . ';vertical-align:2px">' . $e($l['tag']) . '</span>' : '')
      . ($l['sub'] !== '' ? '<div style="' . $J . 'font-size:10.5px;line-height:1.2;color:' . $muted . ';margin-top:1px">' . $e($l['sub']) . '</div>' : '') . '</td>'
      . '<td style="' . $td . $bg . 'text-align:center;white-space:nowrap">' . $e($l['size']) . '</td>'
      . '<td class="fxun" style="' . $td . $bg . $N . 'text-align:right;white-space:nowrap">' . $l['unit'] . '</td>'
      . '<td style="' . $td . $bg . 'text-align:center">' . (int)$l['qty'] . '</td>'
      . '<td style="' . $td . $bg . $N . 'text-align:right;white-space:nowrap;font-weight:500">' . $l['amt'] . '</td></tr>';
  }
  $sum = '';
  foreach ($r['sum'] as [$k, $v, $kind]) {
    $gt = $kind === 'gt'; $c = $kind === 'dc' ? $red : ($gt ? $ink : $soft);
    $sum .= '<tr><td style="' . $J . 'font-size:' . ($gt ? '15px' : '12px') . ';font-weight:500;letter-spacing:' . ($gt ? '.1em' : '.12em') . ';text-transform:uppercase;color:' . $c . ';text-align:right;padding:4px 22px 4px 0">' . $e($k) . '</td>'
      . '<td style="' . $N . 'font-size:' . ($gt ? '16px' : '13.5px') . ';color:' . ($kind === 'dc' ? $red : $ink) . ';text-align:right;white-space:nowrap;padding:4px 0">' . $v . '</td></tr>';
  }
  $tb = 'border-top:2px solid ' . $ink . ';border-bottom:2px solid ' . $ink . ';';
  $sum .= '<tr><td class="fxtl" style="' . $J . 'font-size:22px;font-weight:500;line-height:1;letter-spacing:.06em;text-transform:uppercase;color:' . $ink . ';text-align:right;padding:12px 16px 12px 0;' . $tb . 'white-space:nowrap">' . $e($r['total_label']) . '</td>'
    . '<td class="fxtv" style="' . $N . 'font-size:30px;line-height:1;color:' . $ink . ';text-align:right;white-space:nowrap;padding:12px 0;' . $tb . '">' . $r['total'] . '</td></tr>';
  $box = function ($title, $inner) use ($J, $ink) {
    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1.5px solid ' . $ink . ';margin-top:12px"><tr><td colspan="2" style="' . $J . 'font-size:11.5px;font-weight:500;letter-spacing:.24em;text-transform:uppercase;text-align:center;padding:7px 10px;border-bottom:1.5px solid ' . $ink . ';color:' . $ink . '">' . $title . '</td></tr>' . $inner . '</table>';
  };
  $ship = '';
  foreach ($r['ship'] as $j => [$k, $v]) $ship .= '<tr><td style="' . $J . 'font-size:12px;font-weight:500;letter-spacing:.1em;text-transform:uppercase;color:' . $gold . ';padding:9px 16px;' . ($j ? 'border-top:1px solid ' . $line . ';' : '') . '">' . $e($k) . '</td><td style="' . ($j ? $N : $J) . 'font-size:13px;font-weight:500;letter-spacing:.06em;text-align:right;padding:9px 16px;color:' . $ink . ';' . ($j ? 'border-top:1px solid ' . $line . ';' : '') . '">' . $e($v) . '</td></tr>';
  $pb = $J . 'font-size:13px;font-weight:500;letter-spacing:.12em;text-transform:uppercase;text-align:center;padding:11px 10px;';
  $pay = '<tr><td width="50%" style="' . $pb . 'color:' . $ink . ';border-right:1.5px solid ' . $ink . '">' . $e($r['pay']) . '</td><td width="50%" style="' . $pb . 'color:' . ($r['paid'] ? $gold : $red) . '">' . $e($r['status']) . '</td></tr>';
  $fu = $e(rtrim($r['fonts'], '/')) . '/assets/fonts/';
  return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light"><meta name="supported-color-schemes" content="light">'
    . '<style>@font-face{font-family:\'Jost\';font-style:normal;font-weight:300 500;src:url(' . $fu . 'jost-latin.woff2) format(\'woff2\')}@font-face{font-family:\'Cormorant Garamond\';font-style:normal;font-weight:300 600;src:url(' . $fu . 'cormorant-latin.woff2) format(\'woff2\')}'
    . ($r['num'] !== '' ? '@font-face{font-family:\'' . $r['num'] . '\';src:url(' . $fu . 'CharlesWrightSingapore.woff2) format(\'woff2\')}' : '')
    . '@media (max-width:640px){.fxun{display:none!important}.fxcol{display:block!important;width:100%!important;padding:0 0 18px!important}.fxpad{padding:22px 16px 20px!important}.fxbx{width:100%!important}.fxmeta td.fxcol{text-align:left!important}.fxtl{font-size:19px!important}.fxtv{font-size:27px!important}}</style></head>'
    . '<body style="margin:0;padding:0;background:#efebe3">'
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#efebe3"><tr><td align="center" style="padding:16px 8px 32px">'
    . '<table role="presentation" width="794" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:794px;background:#ffffff;border-top:6px solid ' . $ink . ';border-bottom:6px solid ' . $ink . '">'
    . '<tr><td style="height:1px;line-height:1px;font-size:1px;background:' . $gold2 . '">&nbsp;</td></tr>'
    . '<tr><td class="fxpad" style="padding:30px 44px 26px">'
    // header: logo, tag, RECEIPT between two gold lines
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td align="center"><img src="' . $e($r['logo']) . '" width="177" height="90" alt="FOMAXO" style="display:block;width:177px;height:90px;border:0"></td></tr>'
    . '<tr><td align="center" style="' . $J . 'font-size:10.5px;letter-spacing:.32em;text-transform:uppercase;color:' . $muted . ';padding-top:8px">Perfume &amp; Personal Care</td></tr>'
    . '<tr><td style="padding-top:16px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td style="border-bottom:1px solid ' . $gold2 . ';font-size:1px;line-height:13px">&nbsp;</td><td width="1" style="' . $C . 'font-size:26px;font-weight:500;line-height:1;letter-spacing:.42em;text-transform:uppercase;color:' . $gold . ';padding:0 16px 0 27px;white-space:nowrap">Receipt</td><td style="border-bottom:1px solid ' . $gold2 . ';font-size:1px;line-height:13px">&nbsp;</td></tr></table></td></tr></table>'
    // Issued to | Receipt no
    . '<table role="presentation" class="fxmeta" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:22px 0 18px"><tr>'
    . '<td class="fxcol" valign="top" style="padding-right:16px"><div style="' . $h2 . 'margin-bottom:6px">Issued to</div>' . $dl([['Name', $r['name']], ['Mobile', $r['phone']], ['Email', $r['email']]], $lab, $J . 'font-size:14px;font-weight:500;color:' . $ink . ';') . '</td>'
    . '<td class="fxcol" valign="top" align="right" style="text-align:right;white-space:nowrap"><div style="' . $h2 . 'margin-bottom:6px">Receipt no</div><div style="' . $N . 'font-size:24px;font-weight:500;line-height:1.1;letter-spacing:.02em;color:' . $ink . '">#' . $e($r['no']) . '</div>'
    . '<div style="' . $J . 'font-size:14px;font-weight:500;color:' . $ink . ';margin-top:6px"><span style="' . $lab . 'margin-right:6px">Date</span>' . $e($r['date']) . '</div></td></tr></table>'
    // items
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse"><tr><td style="' . $th . 'text-align:left">Item</td><td style="' . $th . 'text-align:center">Size</td><td class="fxun" style="' . $th . 'text-align:right">Unit price</td><td style="' . $th . 'text-align:center">Qty</td><td style="' . $th . 'text-align:right">Amount</td></tr>' . $rows . '</table>'
    // address | totals
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:22px"><tr>'
    . '<td class="fxcol" valign="top" width="43%" style="padding-right:24px"><div style="' . $J . 'font-size:19px;font-weight:500;line-height:1.1;letter-spacing:.08em;text-transform:uppercase;color:' . $ink . ';margin-bottom:8px">Address</div>'
    . $dl($r['addr'], $J . 'font-size:11.5px;letter-spacing:.08em;text-transform:uppercase;color:' . $muted . ';line-height:1.7;', $J . 'font-size:13px;font-weight:500;line-height:1.45;color:' . $ink . ';') . '</td>'
    . '<td class="fxcol" valign="top" width="57%"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">' . $sum . '</table></td></tr></table>'
    // shipping + payment boxes
    . '<table role="presentation" class="fxbx" width="58%" align="center" cellpadding="0" cellspacing="0" border="0" style="margin:12px auto 0"><tr><td>'
    . ($ship !== '' ? $box('Shipping', $ship) : '') . $box('Payment', $pay) . '</td></tr></table>'
    // button + thank you
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:24px"><tr><td align="center">'
    . '<a href="' . $e($r['url']) . '" style="display:inline-block;background:' . $gold . ';color:#ffffff;text-decoration:none;' . $J . 'font-size:13px;font-weight:500;letter-spacing:.06em;text-transform:uppercase;padding:11px 18px;border-radius:999px">View or download receipt</a></td></tr>'
    . '<tr><td align="center" style="' . $J . 'font-size:24px;font-weight:600;line-height:1.15;letter-spacing:.01em;color:' . $ink . ';padding-top:24px">Thank You For Your Purchase</td></tr>'
    . '<tr><td align="center" style="padding-top:12px"><table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td width="60" style="height:1.5px;line-height:1px;font-size:1px;background:' . $gold2 . '">&nbsp;</td></tr></table></td></tr>'
    . '<tr><td align="center" style="' . $J . 'font-size:11px;line-height:1.7;letter-spacing:.18em;text-transform:uppercase;color:' . $muted . ';padding-top:10px">' . $e($r['site']) . '<br>' . $e($r['foot']) . '</td></tr></table>'
    . '</td></tr><tr><td style="height:1px;line-height:1px;font-size:1px;background:' . $gold2 . '">&nbsp;</td></tr></table>'
    . '</td></tr></table></body></html>';
}
function fx_receipt_email($o) {
  $w = fx_receipt_words(false); $d = fx_receipt_data($o); $t = fn($x) => trim((string)$x);
  $M = fn($v) => '<span style="font-size:.68em;margin-right:3px;letter-spacing:.04em">AED</span>' . number_format((float)$v, 2);
  $rows = [];
  foreach ($d['lines'] as $l) {
    if ($l['free']) { $unit = $l['price'] !== null ? '<s style="color:#555555;font-size:11px">' . $M($l['was'] ?? $l['price']) . '</s>' : ''; $amt = '<span style="font-family:Jost,Helvetica,Arial,sans-serif;color:#9a7a2c;font-size:12px;font-weight:500;letter-spacing:.14em">FREE</span>'; }
    else { $unit = $l['price'] === null ? '—' : ($l['was'] !== null ? '<s style="color:#555555;font-size:11px">' . $M($l['was']) . '</s> ' : '') . $M($l['price']); $amt = $l['price'] === null ? '—' : $M($l['price'] * $l['qty']); }
    $rows[] = ['name' => $l['name'], 'tag' => $l['free'] ? ($l['free'] === 'coupon' ? $w['cgift'] : $w['gift']) : '', 'sub' => $l['set'] && $l['picks'] ? $w['with'] . ': ' . implode(', ', $l['picks']) : $l['family'],
               'size' => $l['set'] ? $w['sets'] . ' ' . $l['opt'] : $l['opt'] . 'ml', 'unit' => $unit, 'qty' => $l['qty'], 'amt' => $amt];
  }
  $sum = [[$w['sub'], $M($d['sub']), '']];
  $sum[] = [$d['sd'] !== null ? $w['sameday'] : $w['delivery'], $d['sd'] ? $M($d['sd']) : '<span style="font-family:Jost,Helvetica,Arial,sans-serif;color:#9a7a2c;font-size:12px;font-weight:500;letter-spacing:.14em">FREE</span>', ''];
  if ((float)$o['fee'] > 0) $sum[] = [$w['codfee'], $M($o['fee']), ''];
  if ($d['disc']) { $grand = (float)$o['total']; foreach ($d['disc'] as $x) $grand += $x['amt']; $sum[] = [$w['grand'], $M($grand), 'gt']; }
  foreach ($d['disc'] as $x) $sum[] = [$x['label'], '−&nbsp;' . $M($x['amt']), 'dc'];
  $addr = [[$w['name'], mb_strtoupper($t($o['name']), 'UTF-8')], [$w['mobile'], $t($o['phone'])], [$w['apt'], $t($o['room'] ?? '')], [$w['building'], $t($o['building'] ?? '')],
           [$w['street'], $t($o['street'] ?? '')], [$w['area'], $t($o['area'] ?? '')], [$w['emirate'], $t($o['emirate'])]];
  if ($t($o['room'] ?? '') . $t($o['building'] ?? '') . $t($o['street'] ?? '') . $t($o['area'] ?? '') === '' && $t($o['address']) !== '') array_splice($addr, 2, 4, [[$w['address'], $t($o['address'])]]);
  $ship = [];
  if ($t($o['courier'] ?? '') !== '') $ship[] = [$w['courier'], mb_strtoupper($t($o['courier']), 'UTF-8')];
  if ($t($o['tracking'] ?? '') !== '') $ship[] = [$w['tracking'], $t($o['tracking'])];
  return fx_receipt_mail_html(['fonts' => 'https://fomaxo.com', 'num' => 'Charles Wright Singapore', 'logo' => 'https://fomaxo.com/assets/img/receipt-logo.png', 'no' => (string)$o['order_no'], 'date' => date('d/m/Y', strtotime((string)$o['created_at'])),
    'name' => mb_strtoupper($t($o['name']), 'UTF-8'), 'phone' => $t($o['phone']), 'email' => $t($o['email']), 'rows' => $rows, 'sum' => $sum,
    'total_label' => $d['pay'] === 'paid' ? $w['paid_total'] : ($d['pay'] === 'unpaid' ? $w['due_total'] : $w['total']), 'total' => $M($o['total']),
    'addr' => $addr, 'ship' => $ship, 'pay' => $d['cod'] ? $w['cod'] : $w['card'], 'status' => $w[$d['pay']], 'paid' => $d['pay'] === 'paid',
    'url' => fx_receipt_url((string)$o['order_no']), 'site' => 'fomaxo.com', 'foot' => $w['foot']]);
}

const FX_RECEIPT_SYMBOLS = '<symbol id="emblem" viewBox="0 0 2507 2506"><path fill="currentColor" fill-rule="evenodd" d="M1186 0 1072 12 1049 17 1005 23 962 34 943 37 937 40 883 54 878 57 832 71 784 91 773 94 721 117 659 148 615 173 559 208 505 246 464 278 415 320 369 363 324 410 279 463 247 504 216 548 182 601 145 666 119 718 91 782 85 801 74 826 54 886 37 947 26 1001 23 1008 23 1016 20 1025 20 1033 12 1073 9 1108 6 1121 3 1176 1 1185 1 1319 6 1383 9 1396 12 1431 20 1471 23 1496 37 1558 54 1619 71 1667 71 1671 94 1731 125 1800 148 1845 180 1901 213 1953 253 2009 284 2048 332 2103 366 2139 417 2187 457 2221 510 2262 556 2295 610 2329 671 2363 717 2386 776 2412 834 2434 882 2449 893 2454 898 2454 945 2468 997 2480 1053 2489 1057 2491 1111 2497 1124 2500 1178 2503 1188 2505 1319 2505 1329 2503 1383 2500 1396 2497 1449 2491 1550 2471 1597 2457 1613 2454 1664 2437 1730 2412 1783 2389 1845 2358 1900 2327 1946 2298 1994 2264 2046 2224 2094 2183 2128 2151 2182 2095 2225 2045 2264 1995 2304 1937 2329 1897 2358 1846 2386 1791 2412 1732 2434 1675 2454 1614 2463 1577 2468 1564 2483 1499 2483 1492 2494 1436 2505 1328 2505 1177 2494 1068 2491 1059 2483 1006 2468 941 2451 881 2434 830 2412 773 2389 721 2358 659 2330 610 2298 559 2261 506 2221 455 2176 403 2096 324 2040 276 1999 244 1946 207 1888 171 1842 145 1790 119 1787 119 1726 91 1666 68 1563 37 1501 23 1439 14 1435 12 1321 0ZM546 439 550 442 1015 1081 1097 1194 1099 1200 508 2002 494 2018 453 1976 419 1937 359 1856 326 1804 300 1758 277 1712 255 1662 237 1615 220 1563 206 1512 192 1447 192 1439 186 1412 178 1342 175 1292 175 1213 178 1164 186 1094 192 1066 195 1041 206 993 223 932 226 927 240 882 280 787 326 701 357 652 393 602 453 529 511 470ZM1198 175 1309 175 1319 177 1353 178 1409 186 1419 186 1516 206 1613 235 1720 280 1766 303 1810 328 1893 384 1946 427 1945 430 1671 430 1280 956 1282 962 1421 1149 1426 1145 1947 438 1954 433 2007 481 2050 525 2109 596 2152 656 2178 697 2226 786 2251 842 2268 886 2285 937 2299 987 2311 1039 2320 1091 2327 1147 2328 1176 2331 1198 2331 1308 2322 1401 2311 1466 2308 1473 2302 1506 2285 1568 2268 1619 2231 1709 2203 1765 2177 1810 2149 1854 2118 1898 2052 1978 1974 2055 1897 2118 1850 2151 1799 2183 1760 2205 1708 2231 1678 2243 1663 2251 1610 2271 1606 2271 1567 2285 1504 2302 1498 2302 1462 2311 1420 2317 1416 2319 1383 2322 1370 2325 1302 2330 1204 2330 1137 2325 1124 2322 1091 2319 991 2299 940 2285 888 2268 830 2245 748 2206 653 2149 570 2087 524 2047 497 2020 498 2018 779 2018 1248 1399 1252 1399 1707 2017 1991 2017 805 432 802 430 561 429 561 426 572 416 616 382 656 354 707 322 746 300 793 277 880 240 930 223 934 223 939 220 984 209 990 206 1009 203 1039 195 1103 184 1153 178 1173 178Z"/></symbol><symbol id="wordmark" viewBox="0 0 3196 481"><path fill="currentColor" fill-rule="evenodd" d="M1068 147 1068 480 1135 480 1135 214ZM1778 123 1581 480 1672 479 1815 221 1823 204ZM0 480 68 480 69 257 377 257 377 189 69 189 68 105 0 105ZM2934 1 2911 4 2871 15 2837 31 2801 56 2768 90 2743 128 2726 168 2720 191 2716 218 2716 263 2723 303 2739 346 2750 366 2766 389 2776 401 2802 426 2837 450 2864 463 2890 472 2926 479 2973 480 3003 476 3023 471 3048 462 3069 452 3108 426 3141 393 3166 356 3185 311 3194 267 3194 215 3186 174 3178 151 3165 124 3154 106 3138 85 3110 57 3089 41 3069 29 3027 11 2998 4 2976 1ZM2933 70 2966 69 3005 76 3038 90 3064 108 3087 131 3105 157 3120 192 3126 222 3126 259 3120 289 3107 321 3092 344 3064 373 3039 390 3013 402 2976 411 2934 411 2902 404 2869 389 2847 374 2822 349 2803 321 2789 287 2784 262 2783 229 2790 191 2805 157 2823 131 2847 107 2872 90 2901 77ZM2623 1 2538 1 2431 145 2421 161 2463 217ZM2198 1 2366 233 2184 480 2269 480 2411 293 2417 299 2550 480 2635 480 2276 1ZM722 1 677 9 654 17 625 31 590 55 555 91 533 124 520 151 513 171 504 216 504 265 513 311 531 354 557 393 591 427 623 449 647 461 671 470 714 479 760 480 785 477 811 471 855 453 897 425 928 394 948 366 958 348 974 307 982 265 982 217 973 171 954 126 939 102 925 84 893 53 869 36 851 26 815 11 786 4 763 1ZM721 70 754 69 793 76 829 92 848 105 873 129 894 159 908 193 914 224 914 258 907 292 893 324 876 349 854 371 825 391 792 405 764 411 721 411 693 405 660 391 635 374 609 348 592 323 580 297 572 264 571 227 577 194 583 177 594 155 610 132 636 106 658 91 689 77ZM0 1 0 69 423 69 423 1ZM1847 0 1802 82 2022 480 2113 480ZM1068 0 1068 97 1282 311 1425 169 1426 170 1426 480 1494 480 1494 4 1282 215Z"/></symbol>';
