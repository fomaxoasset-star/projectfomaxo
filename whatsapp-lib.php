<?php
/* FOMAXO — WhatsApp review requests: shared helpers (used by checkout.php, ziina.php and whatsapp.php).
   Every order with a review link gets a WhatsApp message asking the customer to review the perfumes they bought.
   - Right away: the order email to FOMAXO has a one-tap wa.me link (prefilled message) to send it by hand.
   - Automatically: once whatsapp-config.php (WhatsApp Business / Meta Cloud API) is placed ONE LEVEL ABOVE public_html,
     whatsapp.php sends the message by itself $DAYS days after the order (between $FROM_HOUR and $TO_HOUR, Dubai time).
   Queue: domains/fomaxo.com/fomaxo-reviews/whatsapp/<order>.json (private, kept across GitHub deploys). */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }
require_once __DIR__ . '/reviews-lib.php';

/* ---- defaults (whatsapp-config.php may change them: 'send_after_days', 'from_hour', 'to_hour') ---- */
const FX_WA_DAYS = 3;         // days after the order: time for delivery (usually 1–2 days in the UAE) and to try the perfume
const FX_WA_FROM_HOUR = 11;   // only send between 11:00 …
const FX_WA_TO_HOUR = 20;     // … and 20:00 Dubai time

function fx_wa_config() {
  static $c = null; if ($c !== null) return $c;
  $c = [];
  foreach ([dirname(__DIR__), __DIR__] as $d) {
    foreach (@scandir($d) ?: [] as $n) {
      if (preg_match('/^whatsapp[\s_\-]?config.*\.php$/i', $n) && !preg_match('/example/i', $n) && is_file("$d/$n")) {
        $r = require "$d/$n"; if (is_array($r)) $c = array_change_key_case($r); break 2;
      }
    }
  }
  return $c;
}
/* true when the WhatsApp Business API details are filled in */
function fx_wa_ready() {
  $c = fx_wa_config(); $t = trim((string)($c['access_token'] ?? '')); $p = trim((string)($c['phone_number_id'] ?? ''));
  return $t !== '' && $p !== '' && stripos($t, 'PASTE') !== 0 && stripos($p, 'PASTE') !== 0;
}

/* UAE mobile → international digits for WhatsApp: "050 123 4567", "+971 50…", "00971 50…", "50 123 4567" → 971501234567 */
function fx_wa_number($phone) {
  $d = preg_replace('/\D/', '', (string)$phone);
  if (strpos($d, '00') === 0) $d = substr($d, 2);
  if (strlen($d) === 10 && $d[0] === '0') $d = '971' . substr($d, 1);
  elseif (strlen($d) === 9 && $d[0] === '5') $d = '971' . $d;
  return strlen($d) >= 10 && strlen($d) <= 15 ? $d : null;
}
function fx_wa_first($name) { $w = preg_split('/\s+/', trim((string)$name)); return $w[0] ?? ''; }
function fx_wa_list($names) {
  $names = array_values(array_unique(array_filter($names)));
  return count($names) > 1 ? implode(', ', array_slice($names, 0, -1)) . ' and ' . end($names) : ($names[0] ?? 'your perfume');
}
function fx_wa_host() { return preg_replace('/^www\./', '', preg_replace('/[^A-Za-z0-9.\-]/', '', explode(':', $_SERVER['HTTP_HOST'] ?? 'fomaxo.com')[0])) ?: 'fomaxo.com'; }

/* the message (same words for the wa.me link and, as template variables, for the automatic one) */
function fx_wa_text($name, $perfumes, $url) {
  return "Hi " . fx_wa_first($name) . ", thank you for shopping with FOMAXO. We hope you are enjoying $perfumes.\n\n"
       . "Would you leave a quick review? It takes a minute and shows Verified Purchaser:\n$url\n\nFOMAXO";
}
function fx_wa_me($phone, $text) { $n = fx_wa_number($phone); return $n ? "https://wa.me/$n?text=" . rawurlencode($text) : null; }

/* Called when an order gets its review link. Saves it for the automatic message and returns the lines for FOMAXO's order email. */
function fomaxo_wa_review_request($orderNo, $cust, $productNames, $token) {
  if (!$token) return '';
  $host = fx_wa_host();
  $url = "https://$host/#/review?t=$token";
  $perfumes = fx_wa_list($productNames);
  $text = fx_wa_text($cust['name'] ?? '', $perfumes, $url);
  $num = fx_wa_number($cust['phone'] ?? '');
  $c = fx_wa_config();
  $days = (float)($c['send_after_days'] ?? FX_WA_DAYS);
  $due = fx_wa_slot(time() + (int)round($days * 86400));
  $no = preg_replace('/[^A-Za-z0-9\-]/', '', (string)$orderNo);
  if ($num && $no !== '') @file_put_contents(rv_dir('whatsapp') . "/$no.json", json_encode([
    'no' => $no, 'to' => $num, 'name' => fx_wa_first($cust['name'] ?? ''), 'perfumes' => $perfumes, 'url' => $url, 'token' => $token,
    'created' => date('c'), 'due' => $due], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);

  $out = "\n\nREVIEW REQUEST ON WHATSAPP\n";
  if (!$num) return $out . "Mobile number does not look like a WhatsApp number, so no message is planned.\nReview link: $url\n";
  $out .= "Send by hand once the order is delivered (one tap, opens WhatsApp with the message ready):\n" . fx_wa_me($cust['phone'], $text) . "\n";
  $skip = "https://$host/whatsapp.php?skip=$no&k=" . rv_sign("wa-skip:$no");
  if (fx_wa_ready()) $out .= "Automatic message will go out on " . date('D d M, H:i', $due) . " (Dubai). Order cancelled? Stop it here:\n$skip\n";
  else $out .= "Automatic sending is not switched on yet (needs whatsapp-config.php).\n";
  return $out;
}

/* moves a time into the allowed sending hours (Dubai time) */
function fx_wa_slot($ts) {
  $c = fx_wa_config();
  $from = (int)($c['from_hour'] ?? FX_WA_FROM_HOUR); $to = (int)($c['to_hour'] ?? FX_WA_TO_HOUR);
  $tz = new DateTimeZone('Asia/Dubai');
  $d = (new DateTime('@' . $ts))->setTimezone($tz);
  $h = (int)$d->format('G');
  if ($h < $from) $d->setTime($from, 0);
  elseif ($h >= $to) { $d->modify('+1 day'); $d->setTime($from, 0); }
  return $d->getTimestamp();
}

/* Sends every message that is due. Returns a short report. Called by whatsapp.php (Hostinger cron job). */
function fomaxo_wa_send_due($max = 20) {
  if (!fx_wa_ready()) return ['sent' => 0, 'note' => 'whatsapp-config.php not found or not filled in'];
  $c = fx_wa_config();
  if (($c['review_auto'] ?? true) === false) return ['sent' => 0, 'note' => 'automatic review requests are off'];   // set up from admin → Refill reminders: only refills go out
  if (function_exists('fomaxo_db') && ($pdo = fomaxo_db())) { require_once __DIR__ . '/review-req-lib.php'; if (fx_rq_set($pdo)['auto']) return ['sent' => 0, 'note' => 'review requests now go out from admin → Review requests']; }
  $dir = rv_dir('whatsapp'); $sent = 0; $failed = 0;
  $lock = @fopen("$dir/.lock", 'c'); if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return ['sent' => 0, 'note' => 'already running'];
  $h = (int)(new DateTime('now', new DateTimeZone('Asia/Dubai')))->format('G');
  if ($h < (int)($c['from_hour'] ?? FX_WA_FROM_HOUR) || $h >= (int)($c['to_hour'] ?? FX_WA_TO_HOUR)) { flock($lock, LOCK_UN); fclose($lock); return ['sent' => 0, 'note' => 'outside sending hours']; }
  foreach (glob("$dir/*.json") ?: [] as $f) {
    if ($sent + $failed >= $max) break;
    $m = json_decode((string)@file_get_contents($f), true);
    if (!is_array($m) || !empty($m['sent']) || !empty($m['skipped']) || ($m['due'] ?? PHP_INT_MAX) > time() || (int)($m['tries'] ?? 0) >= 3) continue;
    if (fx_wa_reviewed($m['token'] ?? '')) { $m['skipped'] = 'already reviewed'; @file_put_contents($f, json_encode($m, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX); continue; }
    [$ok, $info] = fx_wa_send($m, $c);
    $m['tries'] = (int)($m['tries'] ?? 0) + 1;
    if ($ok) { $m['sent'] = date('c'); $m['wamid'] = $info; $sent++; }
    else { $m['error'] = mb_substr($info, 0, 500); $m['due'] = time() + 6 * 3600; $failed++; error_log("FOMAXO WhatsApp {$m['no']}: $info"); }
    @file_put_contents($f, json_encode($m, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
  }
  flock($lock, LOCK_UN); fclose($lock);
  return ['sent' => $sent, 'failed' => $failed];
}
/* the customer already reviewed something from this order → no reminder needed */
function fx_wa_reviewed($token) {
  $t = preg_replace('/[^a-f0-9]/', '', (string)$token); if ($t === '') return false;
  $l = json_decode((string)@file_get_contents(rv_dir('links') . "/$t.json"), true);
  return is_array($l) && !empty((array)($l['done'] ?? []));
}
/* WhatsApp Cloud API: an approved template with 3 body variables {{1}} first name, {{2}} perfumes, {{3}} review link */
function fx_wa_send($m, $c) {
  return fx_wa_template($m['to'], (string)($c['template'] ?? 'review_request'), (string)($c['language'] ?? 'en'),
                        [$m['name'] !== '' ? $m['name'] : 'there', $m['perfumes'], $m['url']], $c);
}
/* sends an approved template with its body variables in order ({{1}}, {{2}} …); returns [ok, message id or error] */
function fx_wa_template($to, $name, $lang, array $vars, $c) {
  $ver = preg_replace('/[^v0-9.]/', '', (string)($c['api_version'] ?? 'v21.0')) ?: 'v21.0';
  $api = rtrim((string)($c['api'] ?? 'https://graph.facebook.com'), '/');   // 'api' only for testing against a fake server
  $body = ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'template', 'template' => [
    'name' => $name, 'language' => ['code' => $lang],
    'components' => [['type' => 'body', 'parameters' => array_map(fn($v) => ['type' => 'text', 'text' => (string)$v], $vars)]]]];
  $ch = curl_init("$api/$ver/" . rawurlencode(trim((string)$c['phone_number_id'])) . '/messages');
  curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . trim((string)$c['access_token']), 'Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
  $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
  $d = $res ? json_decode($res, true) : null;
  if ($code === 200 && !empty($d['messages'][0]['id'])) return [true, $d['messages'][0]['id']];
  return [false, "HTTP $code " . ($d['error']['message'] ?? ($err ?: (string)$res))];
}

/* ---- automatic refill reminders (admin → Members → Refill reminders → Automatic sending) ----
   The set number of days after a customer's latest order (fx_refill_time, default 45, 11:00–20:00 Dubai), sends the approved template 'refill_reminder' (Arabic names: its Arabic version, 'ar').
   Template body variables: {{1}} first name, {{2}} perfumes, {{3}} days since the order, {{4}} % off, {{5}} coupon code, {{6}} review link. */
function fx_wa_config_file() { return dirname(__DIR__) . '/whatsapp-config.php'; }
/* saves the WhatsApp Business details typed in admin; a key not given keeps its saved value. New files keep the automatic review requests off. */
function fx_wa_config_save(array $new) {
  $f = fx_wa_config_file();
  $c = is_file($f) ? (array)(require $f) : ['review_auto' => false];
  foreach ($new as $k => $v) if ($v !== '') $c[$k] = $v;
  $ok = @file_put_contents($f, "<?php\n// WhatsApp Business (Meta Cloud API) details for FOMAXO (written by fomaxo.com/admin → Members → Refill reminders)\nreturn " . var_export($c, true) . ";\n", LOCK_EX) !== false;
  if ($ok) @chmod($f, 0600);
  return $ok;
}
/* STOP: the customer replied STOP on WhatsApp (whatsapp.php webhook) or FOMAXO tapped Stop on their admin page.
   WhatsApp offers go off on all their orders, so no more automatic messages, until they tick offers again on a new order. */
function fx_wa_stops($pdo) { return json_decode((string)fomaxo_setting($pdo, 'wa_stop'), true) ?: []; }
function fx_wa_stop($pdo, $phone, $how) {
  if (strlen($k = fomaxo_phone9($phone)) < 9) return false;
  $ids = [];
  foreach ($pdo->query('SELECT id, phone FROM fx_orders WHERE wa_optin = 1') as $o) if (fomaxo_phone9($o['phone']) === $k) $ids[] = (int)$o['id'];
  if ($ids) $pdo->exec('UPDATE fx_orders SET wa_optin = 0 WHERE id IN (' . implode(',', $ids) . ')');
  $s = fx_wa_stops($pdo); $s[$k] = date('Y-m-d H:i') . ' ' . $how;
  fomaxo_setting($pdo, 'wa_stop', json_encode($s));
  return true;
}
/* the whole reply is a stop word (English or Arabic), so "don't stop" or a longer message never counts */
function fx_wa_is_stop($t) {
  $t = trim(mb_strtolower(preg_replace('/[\s\p{P}]+/u', ' ', (string)$t)));
  return in_array($t, ['stop', 'unsubscribe', 'stop promotions', 'stop messages', 'توقف', 'إيقاف', 'ايقاف', 'الغاء', 'إلغاء', 'الغاء الاشتراك', 'إلغاء الاشتراك', 'stop توقف'], true);
}
/* the Verify token FOMAXO pastes into Meta's webhook settings */
function fx_wa_hook_token() { return rv_sign('wa-hook'); }
function fomaxo_wa_send_refills($max = 20) {
  require_once __DIR__ . '/refill-lib.php';
  $pdo = fomaxo_db(); if (!$pdo) return ['refills' => 0, 'note' => 'no database'];
  if (fomaxo_setting($pdo, 'refill_auto') !== '1') return ['refills' => 0, 'note' => 'automatic refill reminders are off'];
  if (!fx_wa_ready()) return ['refills' => 0, 'note' => 'WhatsApp Business details missing'];
  $c = fx_wa_config();
  $h = (int)(new DateTime('now', new DateTimeZone('Asia/Dubai')))->format('G');
  $tm = fx_refill_time($pdo);
  if ($h < $tm['from'] || $h >= $tm['to']) return ['refills' => 0, 'note' => 'outside sending hours'];
  $tries = json_decode((string)fomaxo_setting($pdo, 'refill_tries'), true) ?: [];
  $tpl = (string)($c['refill_template'] ?? 'refill_reminder'); $sent = 0; $failed = 0;
  foreach (fx_refill_due($pdo) as $o) {
    if ($sent + $failed >= $max) break;
    if ($o['sent'] || empty($o['optin']) || $o['days'] < $tm['days'] || ($tries[$o['order_no']] ?? 0) >= 3 || !($to = fx_wa_number($o['phone']))) continue;
    $p = fx_refill_parts($pdo, $o);
    $vars = [$p['first'] !== '' ? $p['first'] : 'there', $p['perfumes'] !== '' ? $p['perfumes'] : 'your FOMAXO perfume', $p['days'], $p['pct'], $p['code'], $p['review'] ?: 'https://fomaxo.com'];
    [$ok, $info] = fx_wa_template($to, $tpl, $p['ar'] ? 'ar' : (string)($c['refill_language'] ?? 'en'), $vars, $c);
    if (!$ok && $p['ar']) [$ok, $info] = fx_wa_template($to, $tpl, (string)($c['refill_language'] ?? 'en'), $vars, $c);   // no Arabic version approved: English
    if ($ok) { fx_refill_mark($pdo, $o['order_no'], 'auto'); $sent++; }
    else { $tries[$o['order_no']] = ($tries[$o['order_no']] ?? 0) + 1; $failed++; fomaxo_setting($pdo, 'refill_auto_err', date('d/m H:i') . ' ' . $o['order_no'] . ': ' . mb_substr($info, 0, 200)); error_log("FOMAXO refill WhatsApp {$o['order_no']}: $info"); }
  }
  fomaxo_setting($pdo, 'refill_tries', json_encode($tries));
  return ['refills' => $sent, 'refills_failed' => $failed];
}

/* automatic review requests (admin → Members → Review requests): only customers who ticked WhatsApp offers, once per order, skipped once reviewed.
   Meta templates: review_ask ({{1}} name, {{2}} perfumes, {{3}} review link) and, with the coupon on, review_ask_coupon (+ {{4}} %, {{5}} code). */
function fomaxo_wa_send_rvreqs($max = 20) {
  require_once __DIR__ . '/review-req-lib.php';
  $pdo = fomaxo_db(); if (!$pdo) return ['reviews' => 0, 'note' => 'no database'];
  $set = fx_rq_set($pdo);
  if (!$set['auto']) return ['reviews' => 0, 'note' => 'automatic review requests are off'];
  if (!fx_wa_ready()) return ['reviews' => 0, 'note' => 'WhatsApp Business details missing'];
  $c = fx_wa_config();
  $h = (int)(new DateTime('now', new DateTimeZone('Asia/Dubai')))->format('G');
  if ($h < $set['from'] || $h >= $set['to']) return ['reviews' => 0, 'note' => 'outside sending hours'];
  $tries = json_decode((string)fomaxo_setting($pdo, 'rvreq_tries'), true) ?: [];
  $sent = 0; $failed = 0;
  foreach (fx_rq_due($pdo) as $o) {
    if ($sent + $failed >= $max) break;
    if ($o['sent'] || empty($o['optin']) || ($tries[$o['order_no']] ?? 0) >= 3 || !($to = fx_wa_number($o['phone']))) continue;
    $p = $o['parts'];
    $vars = [$p['first'] !== '' ? $p['first'] : 'there', $p['perfumes'] !== '' ? $p['perfumes'] : 'your FOMAXO perfume', $p['review']];
    if ($p['code']) { $vars[] = $p['pct']; $vars[] = $p['code']; }
    $tpl = $p['code'] ? 'review_ask_coupon' : 'review_ask';
    [$ok, $info] = fx_wa_template($to, $tpl, $p['ar'] ? 'ar' : 'en', $vars, $c);
    if (!$ok && $p['ar']) [$ok, $info] = fx_wa_template($to, $tpl, 'en', $vars, $c);   // no Arabic version approved: English
    if ($ok) { fx_rq_mark($pdo, $o['order_no'], 'auto'); $sent++; }
    else { $tries[$o['order_no']] = ($tries[$o['order_no']] ?? 0) + 1; $failed++; fomaxo_setting($pdo, 'rvreq_auto_err', date('d/m H:i') . ' ' . $o['order_no'] . ': ' . mb_substr($info, 0, 200)); error_log("FOMAXO review request WhatsApp {$o['order_no']}: $info"); }
  }
  fomaxo_setting($pdo, 'rvreq_tries', json_encode($tries));
  return ['reviews' => $sent, 'reviews_failed' => $failed];
}
