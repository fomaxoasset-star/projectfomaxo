<?php
/* FOMAXO — review requests: a few days after each order, a WhatsApp message asking the customer for an honest review through that order's
   private review link (shows Verified Purchaser). Optional single-use coupon (REVIEW-XXXXX), one per customer and only with their mobile.
   Used by admin → Members → Review requests (one tap per customer) and by whatsapp.php (automatic, through the WhatsApp Business API). */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }
require_once __DIR__ . '/refill-lib.php';

/* settings, from admin → Review requests: days after the order, sending hours (Dubai time), coupon on/off + %, automatic on/off.
   The list shows orders from that day until 30 days after it. */
function fx_rq_set($pdo) {
  $t = json_decode((string)fomaxo_setting($pdo, 'rvreq'), true) ?: [];
  $d = max(1, min(120, (int)($t['days'] ?? 7))); $f = max(0, min(23, (int)($t['from'] ?? 11))); $to = max($f + 1, min(24, (int)($t['to'] ?? 20)));
  return ['days' => $d, 'from' => $f, 'to' => $to, 'list_to' => $d + 30, 'coupon' => !empty($t['coupon']), 'pct' => max(1, min(50, (float)($t['pct'] ?? 10))), 'auto' => !empty($t['auto'])];
}

/* the customer's coupon: REVIEW- + 5 letters, always the same for that mobile, so each customer can only ever get (and use) one */
function fx_rq_code($pdo, $phone) {
  $abc = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; $hx = hash_hmac('sha256', 'review|' . fomaxo_phone9($phone), (string)fomaxo_setting($pdo, 'admin_hash')); $c = 'REVIEW-';
  for ($i = 0; $i < 5; $i++) $c .= $abc[hexdec(substr($hx, $i * 2, 2)) % strlen($abc)];
  return $c;
}

/* marks the order's request as sent; with the coupon on, saves it in Coupons (single use, only with this customer's mobile, no end date) */
function fx_rq_mark($pdo, $no, $how = 'tap') {
  $sent = json_decode((string)fomaxo_setting($pdo, 'rvreq_sent'), true) ?: [];
  $sent[$no] = date('Y-m-d') . ($how === 'auto' ? ' auto' : '');
  $set = fx_rq_set($pdo);
  $s = $pdo->prepare('SELECT phone FROM fx_orders WHERE order_no = ?'); $s->execute([$no]); $ph = (string)$s->fetchColumn();
  if ($set['coupon'] && strlen(fomaxo_phone9($ph)) >= 9) {
    fomaxo_coupons_table($pdo);
    $pdo->prepare('INSERT IGNORE INTO fx_coupons (code, kind, amount, min_order, expires, starts, ends, max_uses, active, created_at, stack, phone) VALUES (?, \'pct\', ?, NULL, NULL, NULL, NULL, 1, 1, NOW(), 0, ?)')
        ->execute([fx_rq_code($pdo, $ph), round($set['pct'], 2), mb_substr($ph, 0, 25)]);
  }
  fomaxo_setting($pdo, 'rvreq_sent', json_encode(array_filter($sent, fn($d) => substr($d, 0, 10) >= date('Y-m-d', strtotime('-200 days')))));
}

/* each customer's latest real order that is between 'days' and 'list_to' days old and still has products to review, oldest first.
   'sent' = date already asked, 'optin' = ticked WhatsApp offers (only they get automatic messages), 'stopped' = replied STOP */
function fx_rq_due($pdo) {
  $set = fx_rq_set($pdo); $last = [];
  $opt = $pdo->query("SHOW COLUMNS FROM fx_orders LIKE 'wa_optin'")->fetch() ? 'wa_optin' : '0 AS wa_optin';
  foreach ($pdo->query("SELECT order_no, created_at, name, phone, lines_json, $opt FROM fx_orders WHERE status IN ('New','Paid','Delivered') AND test = 0 AND created_at >= '" . date('Y-m-d', strtotime('-' . ($set['list_to'] + 1) . ' days')) . "' ORDER BY created_at, id") as $o) {
    if (strlen($k = fomaxo_phone9($o['phone'])) < 9) continue;
    $o['days'] = (int)floor((strtotime('today') - strtotime(date('Y-m-d', strtotime($o['created_at'])))) / 86400);
    if ($o['days'] < $set['days'] || $o['days'] > $set['list_to']) continue;
    $o['optin'] = !empty($o['wa_optin']) || !empty($last[$k]['optin']);
    $last[$k] = $o;   // the latest order wins
  }
  $sent = json_decode((string)fomaxo_setting($pdo, 'rvreq_sent'), true) ?: [];
  $stops = json_decode((string)fomaxo_setting($pdo, 'wa_stop'), true) ?: [];
  $due = [];
  foreach ($last as $k => $o) {
    $p = fx_rq_parts($pdo, $o);
    if (!$p['review']) continue;   // everything in the order is already reviewed
    $due['m' . $k] = $o + ['parts' => $p, 'sent' => isset($sent[$o['order_no']]) ? substr($sent[$o['order_no']], 0, 10) : null,
                          'auto' => str_ends_with((string)($sent[$o['order_no']] ?? ''), 'auto'), 'stopped' => isset($stops[$k]) && !$o['optin']];
  }
  uasort($due, fn($a, $b) => (int)($a['sent'] || $a['stopped']) <=> (int)($b['sent'] || $b['stopped']) ?: $b['days'] <=> $a['days']);
  return $due;
}

/* what the message says: first name, perfumes, review link (null once everything is reviewed), coupon (null when the coupon is off), Arabic or not */
function fx_rq_parts($pdo, $o) {
  $p = fx_refill_parts($pdo, $o); $set = fx_rq_set($pdo);
  $p['code'] = $set['coupon'] ? fx_rq_code($pdo, $o['phone']) : null;
  $p['pct'] = rtrim(rtrim(number_format($set['pct'], 2, '.', ''), '0'), '.');
  return $p;
}

/* the message, a blank line between each part, the code in WhatsApp bold on its own line (the same words as the WhatsApp templates) */
function fx_rq_text($p) {
  $n2 = "\n\n";
  if ($p['ar']) return 'مرحباً ' . $p['first'] . '،' . $n2 . "*شكراً مرة أخرى على طلبك*\nنتمنى أن يكون قد وصلك بأمان وأن تكون مستمتعاً " . ($p['perfumes'] !== '' ? 'بعطر ' . $p['perfumes'] : 'بعطرك من FOMAXO') . '.'
    . $n2 . "هل يمكنك مشاركتنا تقييمك الصادق في دقيقة؟\nسيظهر بشارة \"مشتري موثّق\".\n" . $p['review'] . "\nتقييمك الصادق يساعد الآخرين على اختيار عطرهم من FOMAXO."
    . ($p['code'] ? $n2 . 'تقديراً لوقتك، هذا رمزك الخاص للحصول على خصم ' . $p['pct'] . '% على طلبك القادم (لمرة واحدة):' . $n2 . '*' . $p['code'] . '*' : '')
    . $n2 . 'راسلنا هنا إن احتجت أي مساعدة. وأرسل كلمة STOP إن كنت لا ترغب في هذه الرسائل.' . $n2 . "شكراً لك،\nFOMAXO";
  return 'Hi ' . $p['first'] . ',' . $n2 . "*Thank You Again For Your Order*\nI hope you received it safely and are enjoying " . ($p['perfumes'] !== '' ? $p['perfumes'] : 'your FOMAXO perfume') . '.'
    . $n2 . "Could you spare a minute to share your honest review?\nIt will show as Verified Purchaser.\n" . $p['review'] . "\nYour honest review helps others choose their FOMAXO."
    . ($p['code'] ? $n2 . 'As a thank you for your time, here is your personal code for ' . $p['pct'] . '% off your next order (single use):' . $n2 . '*' . $p['code'] . '*' : '')
    . $n2 . 'Just reply here if you need anything. If you would rather not get these messages, reply STOP.' . $n2 . "Thank you,\nFOMAXO";
}
