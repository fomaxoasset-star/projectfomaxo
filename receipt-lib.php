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
    'delivery' => 'Delivery', 'paid_total' => 'Total paid', 'due_total' => 'Amount to pay', 'total' => 'Total', 'saved' => 'You saved', 'saved2' => 'on this order',
    'address' => 'Delivery address', 'ship' => 'Shipping', 'courier' => 'Courier', 'tracking' => 'Tracking no', 'payment' => 'Payment', 'cod' => 'Cash on delivery', 'card' => 'Card',
    'paid' => 'Paid', 'unpaid' => 'Unpaid', 'cancelled' => 'Cancelled', 'refunded' => 'Refunded', 'thanks' => 'Thank you for your purchase',
    'foot' => 'fomaxo.com · Dubai, United Arab Emirates', 'pdf' => 'Download PDF', 'page' => 'Page', 'sets' => 'Set of', 'vials' => 'vials', 'with' => 'Fragrances'];
  if (!$ar) return $en;
  return ['tag' => 'العطور والعناية الشخصية', 'title' => 'إيصال', 'to' => 'صادر إلى', 'no' => 'رقم الإيصال', 'date' => 'التاريخ', 'name' => 'الاسم', 'mobile' => 'الجوال', 'email' => 'البريد',
    'item' => 'المنتج', 'size' => 'الحجم', 'unit' => 'سعر الوحدة', 'qty' => 'الكمية', 'amount' => 'المبلغ', 'free' => 'مجاناً', 'gift' => 'هدية مجانية', 'cgift' => 'مجاناً مع الكوبون',
    'sub' => 'المجموع الفرعي', 'multi' => 'خصم الشراء المتعدد', 'bt' => 'خصم الشراء معاً', 'coupon' => 'كوبون', 'disc' => 'خصم', 'codfee' => 'رسوم الدفع عند الاستلام',
    'delivery' => 'التوصيل', 'paid_total' => 'المبلغ المدفوع', 'due_total' => 'المبلغ المستحق', 'total' => 'الإجمالي', 'saved' => 'وفّرت', 'saved2' => 'في هذا الطلب',
    'address' => 'عنوان التوصيل', 'ship' => 'الشحن', 'courier' => 'شركة الشحن', 'tracking' => 'رقم التتبع', 'payment' => 'الدفع', 'cod' => 'الدفع عند الاستلام', 'card' => 'بطاقة',
    'paid' => 'مدفوع', 'unpaid' => 'غير مدفوع', 'cancelled' => 'ملغي', 'refunded' => 'مسترد', 'thanks' => 'شكراً لشرائك من FOMAXO',
    'foot' => 'fomaxo.com · دبي، الإمارات العربية المتحدة', 'pdf' => 'تحميل PDF', 'page' => 'صفحة', 'sets' => 'مجموعة من', 'vials' => 'عينات', 'with' => 'العطور'];
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
  return ['lines' => $lines, 'priced' => $priced, 'disc' => $disc, 'save' => round($save, 2), 'pay' => $pay,
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
  $sum = '<div class="tr"><span>' . $e($w['sub']) . '</span>' . $M($d['sub']) . '</div>';
  foreach ($d['disc'] as $x) $sum .= '<div class="tr dc"><span>' . $e($ar ? fx_receipt_disc_label($x['label'], $w) : $x['label']) . '</span><span class="m">−&nbsp;<small>AED</small>' . number_format($x['amt'], 2) . '</span></div>';
  if ((float)$o['fee'] > 0) $sum .= '<div class="tr"><span>' . $e($w['codfee']) . '</span>' . $M($o['fee']) . '</div>';
  $sum .= '<div class="tr"><span>' . $e($w['delivery']) . '</span><b class="fr">' . $e($w['free']) . '</b></div>';
  $sum .= '<div class="tt"><span>' . $e($d['pay'] === 'paid' ? $w['paid_total'] : ($d['pay'] === 'unpaid' ? $w['due_total'] : $w['total'])) . '</span>' . $M($o['total']) . '</div>';
  if ($d['save'] > 0.009) $sum .= '<div class="sv">' . $e($w['saved']) . ' ' . $M($d['save']) . ' ' . $e($w['saved2']) . '</div>';

  $addr = array_filter([trim((string)$o['name']), trim((string)$o['phone']), trim(implode(', ', array_filter([$o['room'] ?? '', $o['building'] ?? '']))), trim((string)($o['street'] ?? '')), trim((string)($o['area'] ?? '')), trim((string)$o['emirate'])], fn($x) => $x !== '');
  if (count($addr) <= 3 && trim((string)$o['address']) !== '') $addr = array_filter([trim((string)$o['name']), trim((string)$o['phone']), trim((string)$o['address']), trim((string)$o['emirate'])], fn($x) => $x !== '');
  $ship = '';
  if (trim((string)($o['courier'] ?? '')) !== '') $ship .= '<div><span>' . $e($w['courier']) . '</span><b>' . $e($o['courier']) . '</b></div>';
  if (trim((string)($o['tracking'] ?? '')) !== '') $ship .= '<div><span>' . $e($w['tracking']) . '</span><b class="trk">' . $e($o['tracking']) . '</b></div>';
  $payWord = $d['cod'] ? $w['cod'] : $w['card'];
  $no = (string)$o['order_no']; $date = date('d/m/Y', strtotime((string)$o['created_at']));
  $sw = '?n=' . rawurlencode($no) . '&t=' . fx_receipt_token($no);

  return '<!doctype html><html lang="' . ($ar ? 'ar' : 'en') . '" dir="' . ($ar ? 'rtl' : 'ltr') . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
    . '<meta name="robots" content="noindex,nofollow"><title>FOMAXO ' . $e($w['title']) . ' ' . $e($no) . '</title><link rel="icon" href="/assets/img/favicon-32.png">'
    . '<style>' . fx_receipt_css() . '</style></head><body>'
    . '<div class="bar"><a class="lng" href="' . $e($sw . ($ar ? '' : '&l=ar')) . '"' . ($ar ? '' : ' lang="ar"') . '>' . ($ar ? 'English' : 'عربي') . '</a>'
    . '<button type="button" class="pdf" onclick="window.print()">' . $e($w['pdf']) . '</button></div>'
    . '<main class="sheet">'
    . '<header class="hd"><svg class="em" viewBox="0 0 2507 2506" aria-hidden="true"><use href="#emblem"/></svg><svg class="wm" viewBox="0 0 3196 481" role="img" aria-label="FOMAXO"><use href="#wordmark"/></svg>'
    . '<p class="tg">' . $e($w['tag']) . '</p><h1><span>' . $e($w['title']) . '</span></h1></header>'
    . '<section class="meta"><div><h2>' . $e($w['to']) . '</h2><dl><dt>' . $e($w['name']) . '</dt><dd>' . $e($o['name']) . '</dd>'
    . ($o['phone'] !== '' ? '<dt>' . $e($w['mobile']) . '</dt><dd dir="ltr">' . $e($o['phone']) . '</dd>' : '') . ($o['email'] !== '' ? '<dt>' . $e($w['email']) . '</dt><dd>' . $e($o['email']) . '</dd>' : '') . '</dl></div>'
    . '<div class="rn"><h2>' . $e($w['no']) . '</h2><p class="no" dir="ltr">#' . $e($no) . '</p><p class="dt"><span>' . $e($w['date']) . '</span> ' . $e($date) . '</p></div></section>'
    . '<table class="it"><thead><tr><th class="c-it">' . $e($w['item']) . '</th><th class="c-sz">' . $e($w['size']) . '</th><th class="c-un">' . $e($w['unit']) . '</th><th class="c-q">' . $e($w['qty']) . '</th><th class="c-am">' . $e($w['amount']) . '</th></tr></thead><tbody>' . $rows . '</tbody></table>'
    . '<section class="bt"><div class="lf"><h2>' . $e($w['address']) . '</h2><p class="ad">' . implode('<br>', array_map($e, $addr)) . '</p>'
    . ($ship !== '' ? '<h2>' . $e($w['ship']) . '</h2><div class="sh">' . $ship . '</div>' : '')
    . '<div class="py"><span>' . $e($w['payment']) . '</span><b>' . $e($payWord) . '</b><i class="st st-' . $d['pay'] . '">' . $e($w[$d['pay']]) . '</i></div></div>'
    . '<div class="sm">' . $sum . '</div></section>'
    . '<footer class="ft"><p class="ty">' . $e($w['thanks']) . '</p><p class="si">' . $e($w['foot']) . '</p></footer>'
    . '</main><script>if(/[?&]print=1/.test(location.search))addEventListener("load",function(){(document.fonts?document.fonts.ready:Promise.resolve()).then(function(){setTimeout(print,200)})})</script><svg width="0" height="0" style="position:absolute" aria-hidden="true">' . FX_RECEIPT_SYMBOLS . '</svg></body></html>';
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
@media (prefers-color-scheme:dark){.bar .pdf{color:#0b0b0b}}
.sheet{max-width:794px;margin:0 auto;background:var(--paper);border-top:6px solid var(--band);border-bottom:6px solid var(--band);box-shadow:0 10px 40px rgba(0,0,0,.08);padding:30px 44px 26px;position:relative}
.sheet::before,.sheet::after{content:"";position:absolute;left:0;right:0;height:1px;background:var(--gold2)}
.sheet::before{top:4px}.sheet::after{bottom:4px}
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
.bt{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.05fr);gap:28px;margin-top:20px;break-inside:avoid;page-break-inside:avoid}
.lf h2{margin-top:2px}.lf h2+*{margin-bottom:14px}
.ad{margin:0;font-size:13px;line-height:1.55;color:var(--soft);overflow-wrap:anywhere}
.sh{border:1px solid var(--line);border-inline-start:3px solid var(--gold2);padding:8px 12px;display:grid;gap:4px}
.sh div{display:flex;justify-content:space-between;gap:12px;font-size:13px}.sh span{color:var(--muted)}.sh b{font-weight:500;text-align:end;overflow-wrap:anywhere}
.sh .trk{font-family:'Charles Wright Singapore',Jost,sans-serif;letter-spacing:.06em}
.py{display:flex;align-items:center;gap:10px;flex-wrap:wrap;border:1px solid var(--ink);padding:10px 12px;font-size:13px}
.py>span{color:var(--muted);text-transform:uppercase;letter-spacing:.12em;font-size:10.5px}
.py b{font-weight:500}
.st{margin-inline-start:auto;padding:4px 12px;border-radius:999px;font-size:10.5px;font-weight:500;letter-spacing:.16em;text-transform:uppercase}
[dir=rtl] .st,[dir=rtl] .py>span{letter-spacing:0}
.st-paid{background:var(--gold);color:#fff}
@media (prefers-color-scheme:dark){.st-paid{color:#0b0b0b}}
.st-unpaid,.st-cancelled,.st-refunded{border:1px solid var(--red);color:var(--red)}
.sm .tr{display:flex;justify-content:space-between;gap:12px;padding:5px 0;font-size:13.5px}
.sm .tr>span:first-child{color:var(--soft)}
.sm .dc>span{color:var(--gold)!important}
.sm .tt{display:flex;justify-content:space-between;align-items:baseline;gap:12px;margin-top:8px;padding:12px 0 10px;border-top:1px solid var(--ink);border-bottom:1px solid var(--ink)}
.sm .tt>span:first-child{font:600 18px/1 'Cormorant Garamond',Tajawal,serif;letter-spacing:.14em;text-transform:uppercase}
[dir=rtl] .sm .tt>span:first-child{letter-spacing:0}
.sm .tt .m{font-size:28px;line-height:1}
.sv{margin-top:10px;text-align:center;padding:8px 10px;background:var(--zebra);border:1px dashed var(--gold2);color:var(--gold);font-size:12.5px;letter-spacing:.04em}
.ft{text-align:center;margin-top:26px;break-inside:avoid;page-break-inside:avoid}
.ty{margin:0;font:500 26px/1.1 'Cormorant Garamond',Tajawal,serif;letter-spacing:.04em}
.ty::after{content:"";display:block;width:60px;height:1px;background:var(--gold2);margin:12px auto 0}
.si{margin:10px 0 0;font-size:11px;letter-spacing:.18em;text-transform:uppercase;color:var(--muted)}
[dir=rtl] .si{letter-spacing:0}
@media (max-width:640px){
  body{padding:10px 8px 24px;font-size:13px}
  .bar{justify-content:center}
  .sheet{padding:22px 16px 20px}
  .hd .em{width:42px;height:42px}.hd .wm{height:20px}.hd h1{font-size:21px;gap:10px}
  .meta{flex-direction:column;gap:14px;margin:18px 0 14px}.meta .rn{text-align:start}.meta .no{font-size:20px}
  .it .c-un{display:none}.it th{padding:7px 6px}.it td{padding:5px 6px}.it th{letter-spacing:.08em;font-size:9.5px}.pn{font-size:15px}
  .bt{grid-template-columns:1fr;gap:18px}.bt .sm{order:-1}
  .sm .tt .m{font-size:24px}.ty{font-size:22px}
}
@page{size:A4;margin:12mm 12mm 14mm}
@page{@bottom-center{content:counter(page) " / " counter(pages);font:9px Jost,sans-serif;color:#8a8273}}
@media print{
  :root{--bg:#fff;--paper:#fff;--ink:#0b0b0b;--soft:#3d3a35;--muted:#6f685c;--line:#e6dfcf;--gold:#9a7a2c;--gold2:#c9a64b;--zebra:#faf8f3;--red:#a3261b;color-scheme:light}
  body{padding:0;background:#fff;font-size:12.5px;-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .bar{display:none}
  .sheet{max-width:none;box-shadow:none;padding:6mm 6mm 4mm;border-width:4px}
  .st-paid{color:#fff!important}
  .it .c-un{display:table-cell!important}
}
CSS; }

/* the email: a short note with the receipt in simple tables (email apps ignore most page styling) and the full receipt link */
function fx_receipt_email($o) {
  $d = fx_receipt_data($o); $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
  $aed = fn($v) => 'AED ' . number_format((float)$v, 2);
  $first = preg_split('/\s+/u', trim((string)$o['name']))[0] ?? '';
  $td = 'padding:9px 6px;border-bottom:1px solid #e6dfcf;font:14px Arial,sans-serif;color:#0b0b0b';
  $rows = '';
  foreach ($d['lines'] as $l)
    $rows .= '<tr><td style="' . $td . '"><b style="letter-spacing:1px;text-transform:uppercase">' . $e($l['name']) . '</b> ' . $e($l['set'] ? 'Set of ' . $l['opt'] : $l['opt'] . 'ml')
           . ($l['free'] ? ' <span style="color:#9a7a2c">· Free gift</span>' : '') . '</td><td style="' . $td . ';text-align:center">' . (int)$l['qty'] . '</td>'
           . '<td style="' . $td . ';text-align:right;white-space:nowrap">' . ($l['free'] ? '<span style="color:#9a7a2c">FREE</span>' : ($l['price'] !== null ? $aed($l['price'] * $l['qty']) : '')) . '</td></tr>';
  $tr = fn($k, $v, $c = '#3d3a35') => '<tr><td style="padding:4px 6px;font:14px Arial,sans-serif;color:' . $c . '">' . $e($k) . '</td><td style="padding:4px 6px;font:14px Arial,sans-serif;text-align:right;color:' . $c . ';white-space:nowrap">' . $v . '</td></tr>';
  $sum = $tr('Subtotal', $aed($d['sub']));
  foreach ($d['disc'] as $x) $sum .= $tr($x['label'], '-' . $aed($x['amt']), '#9a7a2c');
  if ((float)$o['fee'] > 0) $sum .= $tr('Cash on delivery fee', $aed($o['fee']));
  $sum .= $tr('Delivery', 'FREE');
  $sum .= '<tr><td style="padding:10px 6px;border-top:1px solid #0b0b0b;font:bold 16px Arial,sans-serif;color:#0b0b0b">' . ($d['pay'] === 'paid' ? 'Total paid' : ($d['pay'] === 'unpaid' ? 'Amount to pay' : 'Total')) . '</td><td style="padding:10px 6px;border-top:1px solid #0b0b0b;font:bold 18px Arial,sans-serif;text-align:right;color:#0b0b0b;white-space:nowrap">' . $aed($o['total']) . '</td></tr>';
  $ship = (trim((string)($o['courier'] ?? '')) !== '' ? 'Courier: <b>' . $e($o['courier']) . '</b><br>' : '') . (trim((string)($o['tracking'] ?? '')) !== '' ? 'Tracking no: <b>' . $e($o['tracking']) . '</b><br>' : '');
  $url = fx_receipt_url((string)$o['order_no']);
  return '<!doctype html><html><body style="margin:0;background:#efebe3;padding:20px 10px">'
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center"><table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-top:6px solid #0b0b0b;border-bottom:6px solid #0b0b0b">'
    . '<tr><td style="padding:28px 28px 8px;text-align:center"><div style="font:600 30px Georgia,serif;letter-spacing:10px;color:#0b0b0b">FOMAXO</div><div style="font:11px Arial,sans-serif;letter-spacing:4px;color:#6f685c;margin-top:6px">PERFUME &amp; PERSONAL CARE</div>'
    . '<div style="font:20px Georgia,serif;letter-spacing:8px;color:#9a7a2c;margin-top:18px">RECEIPT</div></td></tr>'
    . '<tr><td style="padding:10px 28px;font:15px Arial,sans-serif;color:#0b0b0b;line-height:1.5">Hi ' . $e($first !== '' ? $first : 'there') . ',<br><br>Thank you for your purchase. Here is your receipt for order <b>#' . $e($o['order_no']) . '</b> of ' . date('d/m/Y', strtotime((string)$o['created_at'])) . '.</td></tr>'
    . '<tr><td style="padding:6px 22px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . $rows . '</table></td></tr>'
    . '<tr><td style="padding:10px 22px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . $sum . '</table></td></tr>'
    . '<tr><td style="padding:6px 28px;font:14px Arial,sans-serif;color:#3d3a35;line-height:1.6">Payment: <b>' . ($d['cod'] ? 'Cash on delivery' : 'Card') . ' · ' . ucfirst($d['pay']) . '</b><br>' . $ship . '</td></tr>'
    . '<tr><td style="padding:16px 28px 26px;text-align:center"><a href="' . $e($url) . '" style="display:inline-block;background:#9a7a2c;color:#ffffff;text-decoration:none;font:bold 13px Arial,sans-serif;letter-spacing:2px;padding:14px 26px;border-radius:999px">VIEW OR DOWNLOAD RECEIPT</a>'
    . '<div style="font:22px Georgia,serif;color:#0b0b0b;margin-top:22px">Thank you for your purchase</div><div style="font:11px Arial,sans-serif;letter-spacing:3px;color:#6f685c;margin-top:8px">FOMAXO.COM · DUBAI, UAE</div></td></tr>'
    . '</table></td></tr></table></body></html>';
}

const FX_RECEIPT_SYMBOLS = '<symbol id="emblem" viewBox="0 0 2507 2506"><path fill="currentColor" fill-rule="evenodd" d="M1186 0 1072 12 1049 17 1005 23 962 34 943 37 937 40 883 54 878 57 832 71 784 91 773 94 721 117 659 148 615 173 559 208 505 246 464 278 415 320 369 363 324 410 279 463 247 504 216 548 182 601 145 666 119 718 91 782 85 801 74 826 54 886 37 947 26 1001 23 1008 23 1016 20 1025 20 1033 12 1073 9 1108 6 1121 3 1176 1 1185 1 1319 6 1383 9 1396 12 1431 20 1471 23 1496 37 1558 54 1619 71 1667 71 1671 94 1731 125 1800 148 1845 180 1901 213 1953 253 2009 284 2048 332 2103 366 2139 417 2187 457 2221 510 2262 556 2295 610 2329 671 2363 717 2386 776 2412 834 2434 882 2449 893 2454 898 2454 945 2468 997 2480 1053 2489 1057 2491 1111 2497 1124 2500 1178 2503 1188 2505 1319 2505 1329 2503 1383 2500 1396 2497 1449 2491 1550 2471 1597 2457 1613 2454 1664 2437 1730 2412 1783 2389 1845 2358 1900 2327 1946 2298 1994 2264 2046 2224 2094 2183 2128 2151 2182 2095 2225 2045 2264 1995 2304 1937 2329 1897 2358 1846 2386 1791 2412 1732 2434 1675 2454 1614 2463 1577 2468 1564 2483 1499 2483 1492 2494 1436 2505 1328 2505 1177 2494 1068 2491 1059 2483 1006 2468 941 2451 881 2434 830 2412 773 2389 721 2358 659 2330 610 2298 559 2261 506 2221 455 2176 403 2096 324 2040 276 1999 244 1946 207 1888 171 1842 145 1790 119 1787 119 1726 91 1666 68 1563 37 1501 23 1439 14 1435 12 1321 0ZM546 439 550 442 1015 1081 1097 1194 1099 1200 508 2002 494 2018 453 1976 419 1937 359 1856 326 1804 300 1758 277 1712 255 1662 237 1615 220 1563 206 1512 192 1447 192 1439 186 1412 178 1342 175 1292 175 1213 178 1164 186 1094 192 1066 195 1041 206 993 223 932 226 927 240 882 280 787 326 701 357 652 393 602 453 529 511 470ZM1198 175 1309 175 1319 177 1353 178 1409 186 1419 186 1516 206 1613 235 1720 280 1766 303 1810 328 1893 384 1946 427 1945 430 1671 430 1280 956 1282 962 1421 1149 1426 1145 1947 438 1954 433 2007 481 2050 525 2109 596 2152 656 2178 697 2226 786 2251 842 2268 886 2285 937 2299 987 2311 1039 2320 1091 2327 1147 2328 1176 2331 1198 2331 1308 2322 1401 2311 1466 2308 1473 2302 1506 2285 1568 2268 1619 2231 1709 2203 1765 2177 1810 2149 1854 2118 1898 2052 1978 1974 2055 1897 2118 1850 2151 1799 2183 1760 2205 1708 2231 1678 2243 1663 2251 1610 2271 1606 2271 1567 2285 1504 2302 1498 2302 1462 2311 1420 2317 1416 2319 1383 2322 1370 2325 1302 2330 1204 2330 1137 2325 1124 2322 1091 2319 991 2299 940 2285 888 2268 830 2245 748 2206 653 2149 570 2087 524 2047 497 2020 498 2018 779 2018 1248 1399 1252 1399 1707 2017 1991 2017 805 432 802 430 561 429 561 426 572 416 616 382 656 354 707 322 746 300 793 277 880 240 930 223 934 223 939 220 984 209 990 206 1009 203 1039 195 1103 184 1153 178 1173 178Z"/></symbol><symbol id="wordmark" viewBox="0 0 3196 481"><path fill="currentColor" fill-rule="evenodd" d="M1068 147 1068 480 1135 480 1135 214ZM1778 123 1581 480 1672 479 1815 221 1823 204ZM0 480 68 480 69 257 377 257 377 189 69 189 68 105 0 105ZM2934 1 2911 4 2871 15 2837 31 2801 56 2768 90 2743 128 2726 168 2720 191 2716 218 2716 263 2723 303 2739 346 2750 366 2766 389 2776 401 2802 426 2837 450 2864 463 2890 472 2926 479 2973 480 3003 476 3023 471 3048 462 3069 452 3108 426 3141 393 3166 356 3185 311 3194 267 3194 215 3186 174 3178 151 3165 124 3154 106 3138 85 3110 57 3089 41 3069 29 3027 11 2998 4 2976 1ZM2933 70 2966 69 3005 76 3038 90 3064 108 3087 131 3105 157 3120 192 3126 222 3126 259 3120 289 3107 321 3092 344 3064 373 3039 390 3013 402 2976 411 2934 411 2902 404 2869 389 2847 374 2822 349 2803 321 2789 287 2784 262 2783 229 2790 191 2805 157 2823 131 2847 107 2872 90 2901 77ZM2623 1 2538 1 2431 145 2421 161 2463 217ZM2198 1 2366 233 2184 480 2269 480 2411 293 2417 299 2550 480 2635 480 2276 1ZM722 1 677 9 654 17 625 31 590 55 555 91 533 124 520 151 513 171 504 216 504 265 513 311 531 354 557 393 591 427 623 449 647 461 671 470 714 479 760 480 785 477 811 471 855 453 897 425 928 394 948 366 958 348 974 307 982 265 982 217 973 171 954 126 939 102 925 84 893 53 869 36 851 26 815 11 786 4 763 1ZM721 70 754 69 793 76 829 92 848 105 873 129 894 159 908 193 914 224 914 258 907 292 893 324 876 349 854 371 825 391 792 405 764 411 721 411 693 405 660 391 635 374 609 348 592 323 580 297 572 264 571 227 577 194 583 177 594 155 610 132 636 106 658 91 689 77ZM0 1 0 69 423 69 423 1ZM1847 0 1802 82 2022 480 2113 480ZM1068 0 1068 97 1282 311 1425 169 1426 170 1426 480 1494 480 1494 4 1282 215Z"/></symbol>';
