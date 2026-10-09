<?php
/* FOMAXO — refill reminders: customers whose latest order was about 45 days ago (admin can change it) get a personal WhatsApp message with a single-use
   coupon (REFILL-XXXXX, only with their mobile) and their Verified Purchaser review link.
   Used by admin → Members → Refill reminders (one tap per customer) and by whatsapp.php (automatic, through the WhatsApp Business API). */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }
require_once __DIR__ . '/orders-lib.php';
require_once __DIR__ . '/reviews-lib.php';

/* timing, set on admin → Refill reminders → Automatic sending: days after the latest order, and the sending hours (Dubai time).
   The list shows customers from 5 days before that day until 15 days after it. */
function fx_refill_time($pdo) {
  $t = json_decode((string)fomaxo_setting($pdo, 'refill_time'), true) ?: [];
  $d = max(1, min(365, (int)($t['days'] ?? 45))); $f = max(0, min(23, (int)($t['from'] ?? 11))); $to = max($f + 1, min(24, (int)($t['to'] ?? 20)));
  $lf = max(1, min(365, (int)($t['lfrom'] ?? $d - 5))); $lt = max($lf, min(400, (int)($t['lto'] ?? $d + 15)));   // the list: typed on Refill reminders
  return ['days' => $d, 'from' => $f, 'to' => $to, 'list_from' => $lf, 'list_to' => $lt];
}

function fx_refill_pct($pdo) { return (float)(fomaxo_setting($pdo, 'refill_pct') ?? 10); }
/* the automatic coupon's minimum order in AED (0 = none) and the words the automatic message uses for the offer */
function fx_refill_min($pdo) { return max(0, (int)(fomaxo_setting($pdo, 'refill_min') ?? 0)); }
function fx_refill_offer($pdo, $ar = false) {
  $p = rtrim(rtrim(number_format(fx_refill_pct($pdo), 2, '.', ''), '0'), '.'); $m = fx_refill_min($pdo);
  return $ar ? 'خصم ' . $p . '% على طلبك القادم' . ($m ? ' لطلب بقيمة ' . number_format($m) . ' درهم أو أكثر' : '') : $p . '% off your next order' . ($m ? ' of AED ' . number_format($m) . ' or more' : '');
}
/* orders taken off Review requests or Refill reminders with ✕ (order no => day): not listed, no automatic message; kept 400 days */
function fx_list_hidden($pdo, $setting) { return json_decode((string)fomaxo_setting($pdo, $setting), true) ?: []; }
function fx_list_hide($pdo, $setting, $no) {
  $h = array_filter(fx_list_hidden($pdo, $setting), fn($d) => $d >= date('Y-m-d', strtotime('-400 days'))); $h[$no] = date('Y-m-d');
  fomaxo_setting($pdo, $setting, json_encode($h));
}

/* the order's coupon: REFILL- + 5 letters, always the same for that order */
function fx_refill_code($pdo, $no) {
  $abc = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; $hx = hash_hmac('sha256', 'refill|' . $no, (string)fomaxo_setting($pdo, 'admin_hash')); $c = 'REFILL-';
  for ($i = 0; $i < 5; $i++) $c .= $abc[hexdec(substr($hx, $i * 2, 2)) % strlen($abc)];
  return $c;
}

/* marks the order's reminder as sent. Sent by itself, its coupon is saved in Coupons (single use, only with this customer's mobile, no end date);
   a tap on WhatsApp makes its coupon in the WhatsApp box instead, if one is picked */
function fx_refill_mark($pdo, $no, $how = 'tap') {
  $sent = json_decode((string)fomaxo_setting($pdo, 'refill_sent'), true) ?: [];
  $sent[$no] = date('Y-m-d') . ($how === 'auto' ? ' auto' : '');
  $s = $pdo->prepare('SELECT phone FROM fx_orders WHERE order_no = ?'); $s->execute([$no]); $ph = (string)$s->fetchColumn();
  if ($how === 'auto' && strlen(fomaxo_phone9($ph)) >= 9) {
    fomaxo_coupons_table($pdo);
    $pdo->prepare('INSERT IGNORE INTO fx_coupons (code, kind, amount, min_order, expires, starts, ends, max_uses, active, created_at, stack, phone) VALUES (?, \'pct\', ?, ?, NULL, NULL, NULL, 1, 1, NOW(), 0, ?)')
        ->execute([fx_refill_code($pdo, $no), round(fx_refill_pct($pdo), 2), fx_refill_min($pdo) ?: null, mb_substr($ph, 0, 25)]);
  }
  fomaxo_setting($pdo, 'refill_sent', json_encode(array_filter($sent, fn($d) => substr($d, 0, 10) >= date('Y-m-d', strtotime('-120 days')))));
}

/* every customer (same mobile = one customer) whose latest real order is in the list window (fx_refill_time), oldest first; 'sent' = date already reminded,
   'optin' = ticked WhatsApp offers at checkout (only they get automatic messages), 'stopped' = replied STOP and has not ticked offers since */
function fx_refill_due($pdo) {
  $last = [];
  $opt = $pdo->query("SHOW COLUMNS FROM fx_orders LIKE 'wa_optin'")->fetch() ? 'wa_optin' : '0 AS wa_optin';
  foreach ($pdo->query("SELECT order_no, created_at, name, phone, lines_json, $opt FROM fx_orders WHERE status IN ('New','Paid','Delivered') AND test = 0 ORDER BY created_at, id") as $o) {
    if (strlen($k = fomaxo_phone9($o['phone'])) < 9) continue;
    $o['optin'] = !empty($o['wa_optin']) || !empty($last[$k]['optin']);   // ticked "Send me offers on WhatsApp" on any order
    $last[$k] = $o;   // the latest order wins
  }
  $sent = json_decode((string)fomaxo_setting($pdo, 'refill_sent'), true) ?: [];
  $due = []; $tm = fx_refill_time($pdo); $stops = json_decode((string)fomaxo_setting($pdo, 'wa_stop'), true) ?: [];
  $gone = fx_list_hidden($pdo, 'refill_sent_hide');   // ✕ on the list: no reminder for that order
  foreach ($last as $k => $o) {
    if (isset($gone[$o['order_no']])) continue;
    $days = (int)floor((strtotime('today') - strtotime(date('Y-m-d', strtotime($o['created_at'])))) / 86400);
    if ($days >= $tm['list_from'] && $days <= $tm['list_to']) $due['m' . $k] = $o + ['days' => $days, 'sent' => isset($sent[$o['order_no']]) ? substr($sent[$o['order_no']], 0, 10) : null, 'auto' => str_ends_with((string)($sent[$o['order_no']] ?? ''), 'auto'), 'stopped' => isset($stops[$k]) && !$o['optin']];
  }
  uasort($due, fn($a, $b) => (int)($a['sent'] || $a['stopped']) <=> (int)($b['sent'] || $b['stopped']) ?: $b['days'] <=> $a['days']);
  return $due;
}

/* what the message says for one order: first name, perfumes, days, coupon, %, review link (null once anything in the order is reviewed), Arabic or not */
function fx_refill_parts($pdo, $o) {
  static $names = null, $links = null;
  if ($names === null) { $names = []; foreach (fomaxo_product_rows($pdo) ?: [] as $r) { $p = json_decode($r['data'], true); $names[$r['id']] = (string)($p['name'] ?? $r['id']); } }
  if ($links === null) { $links = [];
    foreach (glob(rv_dir('links') . '/*.json') ?: [] as $f) { $j = json_decode((string)@file_get_contents($f), true); if (isset($j['no'])) $links[$j['no']] = ['t' => basename($f, '.json'), 'left' => array_diff((array)($j['products'] ?? []), array_keys((array)($j['done'] ?? []))), 'any' => !empty((array)($j['done'] ?? []))]; } }
  $lines = array_filter((array)json_decode((string)($o['lines_json'] ?? ''), true), fn($l) => empty($l['free']) && ($l['id'] ?? '') !== '');
  $pn = implode(', ', array_unique(array_filter(array_map(fn($l) => $names[$l['id']] ?? '', $lines))));
  if (isset($links[$o['order_no']])) $rv = $links[$o['order_no']]['left'] && empty($links[$o['order_no']]['any']) ? $links[$o['order_no']]['t'] : null;   // the link made at checkout; none once anything in the order is reviewed
  else { $rv = fomaxo_review_link($o['order_no'], array_column($lines, 'id')); if ($rv) $links[$o['order_no']] = ['t' => $rv, 'left' => [1]]; }
  $ar = fx_has_ar($o['name']);
  $pct = fx_refill_pct($pdo);
  return ['first' => preg_split('/\s+/u', trim((string)$o['name']))[0] ?? '', 'perfumes' => $pn, 'days' => (int)$o['days'], 'code' => fx_refill_code($pdo, $o['order_no']),
          'pct' => rtrim(rtrim(number_format($pct, 2, '.', ''), '0'), '.'), 'ar' => $ar, 'offer' => fx_refill_offer($pdo, $ar),
          'review' => $rv ? 'https://fomaxo.com/' . ($ar ? '?lang=ar' : '') . '#/review?t=' . $rv : null];
}

/* the message, with a blank line between each part so it reads like a personal note. The admin WhatsApp box puts the coupon it makes (if one
   is picked) between fx_refill_head and fx_refill_tail. */
function fx_refill_head($p) {
  if ($p['ar']) return 'مرحباً ' . $p['first'] . '،' . "\n\n" . 'نتمنى أن تكون مستمتعاً ' . ($p['perfumes'] !== '' ? 'بعطر ' . $p['perfumes'] : 'بعطرك من FOMAXO') . '. مرّ ' . $p['days'] . ' يوماً على طلبك، وقد يكون عطرك قارب على النفاد.';
  return 'Hi ' . $p['first'] . ',' . "\n\n" . 'I hope you are enjoying ' . ($p['perfumes'] !== '' ? $p['perfumes'] : 'your FOMAXO perfume') . '. It has been ' . $p['days'] . ' days since your order, so your bottle may be running low.';
}
function fx_refill_tail($p) {
  $n2 = "\n\n";
  if ($p['ar']) return $n2 . "يمكنك الطلب من جديد هنا:\nhttps://fomaxo.com/?lang=ar"
    . ($p['review'] ? $n2 . "إن سمح وقتك، يسعدنا تقييمك الصادق، وسيظهر بشارة \"مشتري موثّق\":\n" . $p['review'] : '')
    . $n2 . 'راسلنا هنا إن احتجت مساعدة في اختيار عطرك القادم. وأرسل كلمة STOP إن كنت لا ترغب في هذه الرسائل.' . $n2 . "شكراً لك،\nFOMAXO";
  return $n2 . "You can reorder anytime here:\nhttps://fomaxo.com"
    . ($p['review'] ? $n2 . "If you have a moment, we would love your honest review. It will show as Verified Purchaser:\n" . $p['review'] : '')
    . $n2 . 'Just reply here if you would like help choosing your next scent. If you would rather not get these messages, reply STOP.' . $n2 . "Thank you,\nFOMAXO";
}
