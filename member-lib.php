<?php
/* FOMAXO — "Check my level" on the FOMAXO Elite page (#/collections/elite): a customer sees their own spending this year and their level.
   Private by design (UAE PDPL): nothing is shown for a typed number until the code we send to that number's WhatsApp is typed back.
   - Spending this year = fomaxo.com orders + store (POS) purchases on the same mobile, the same sum the store system uses for its tiers
     (pos.fomaxo.com lib/core.php customer_year_spend + lib/website.php). fomaxo.in orders are in rupees and are not counted, as in the store.
   - Levels come from the store system's own settings file ('tiers'), so the website and the store always agree; the four default levels otherwise.
   - The code goes out as the approved WhatsApp template 'member_code' (Meta: category Authentication, one code, "Copy code" button).
     whatsapp-config.php can name another one: 'member_template' => '…', 'member_language' => 'en'.
   - Codes: 6 digits, 10 minutes, 5 tries. At most 3 codes an hour and 6 a day for one mobile, 10 an hour from one connection.
     Kept in domains/fomaxo.com/fomaxo-reviews/member/ (private, outside public_html), only as a hash, removed once used. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }
require_once __DIR__ . '/whatsapp-lib.php';

const FX_MEMBER_TIERS = [['name' => 'Black', 'min' => 4000], ['name' => 'Gold', 'min' => 7500], ['name' => 'Elite', 'min' => 15000], ['name' => 'Imperial', 'min' => 30000]];
const FX_MEMBER_CODE_MIN = 10;   // minutes a code works

/* the store system's settings file: the same places pos.fomaxo.com looks (lib/core.php config_paths), from this account */
function fx_member_pos_config() {
  static $c = false; if ($c !== false) return $c;
  $c = null;
  $domains = dirname(__DIR__, 2); $app = $domains . '/pos.fomaxo.com/public_html';
  $home = preg_match('#^(/home/[^/]+)/#', __DIR__, $m) ? $m[1] : '';
  $paths = [$domains . '/pos.fomaxo.com/fomaxo-pos-config.php'];
  if ($home) { $paths[] = "$home/.fomaxo-pos-config.php"; $paths[] = "$home/fomaxo-private/fomaxo-pos-config.php"; }
  $paths[] = "$app/config.php";
  foreach ($paths as $f) if (@is_file($f) && @is_readable($f)) { $r = @require $f; if (is_array($r)) { $c = $r; break; } }
  return $c;
}
/* the store system's database, read only (null when it can't be reached: then only website orders count) */
function fx_member_pos_db() {
  static $pdo = false; if ($pdo !== false) return $pdo;
  $pdo = null; $c = fx_member_pos_config();
  if (!$c || ($c['db_driver'] ?? 'mysql') !== 'mysql' || empty($c['db_name'])) return null;
  try {
    $pdo = new PDO('mysql:host=' . ($c['db_host'] ?? 'localhost') . ';dbname=' . $c['db_name'] . ';charset=utf8mb4', (string)($c['db_user'] ?? ''), (string)($c['db_pass'] ?? ''),
      [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 4]);
    $pdo->exec("SET time_zone = '+04:00'");
  } catch (Throwable $e) { error_log('FOMAXO member check: store database not reachable: ' . $e->getMessage()); $pdo = null; }
  return $pdo;
}
function fx_member_tiers() {
  $t = (fx_member_pos_config() ?: [])['tiers'] ?? null;
  $t = is_array($t) ? array_values(array_filter($t, fn($x) => is_array($x) && isset($x['name'], $x['min']))) : [];
  if (!$t) $t = FX_MEMBER_TIERS;
  usort($t, fn($a, $b) => $a['min'] <=> $b['min']);
  return array_map(fn($x) => ['name' => (string)$x['name'], 'min' => (float)$x['min']], $t);
}

/* the last 9 digits of a mobile: how the website admin, Members and the store system all match one customer */
function fx_member_p9($phone) { $d = preg_replace('/\D/', '', (string)$phone); return strlen($d) >= 9 ? substr($d, -9) : ''; }
const FX_MEMBER_DIGITS = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', ''), '(', ''), ')', '')";

/* everything we know for one mobile: website orders and store purchases, this year and all time (amounts in fils, 1 AED = 100) */
function fx_member_find($p9, $web, $pos, $year = null) {
  $year = $year ?: date('Y'); $start = "$year-01-01 00:00:00";
  $r = ['found' => false, 'name' => '', 'year' => 0, 'web_year' => 0, 'store_year' => 0, 'web_orders' => 0, 'store_visits' => 0, 'since' => ''];
  if (strlen($p9) !== 9) return $r;
  if ($web) try {
    $s = $web->prepare("SELECT name, phone, total, created_at FROM fx_orders WHERE test = 0 AND status NOT IN ('Awaiting payment', 'Cancelled', 'Refunded')
                        AND " . FX_MEMBER_DIGITS . " LIKE ? ORDER BY created_at DESC LIMIT 500");
    $s->execute(['%' . $p9]);
    foreach ($s->fetchAll() as $o) {
      if (fx_member_p9($o['phone']) !== $p9) continue;   // LIKE '%…' also takes longer numbers ending the same way
      $r['found'] = true; $r['web_orders']++;
      if ($r['name'] === '') $r['name'] = trim((string)$o['name']);
      if ((string)$o['created_at'] >= $start) $r['web_year'] += (int)round((float)$o['total'] * 100);
      $r['since'] = substr((string)$o['created_at'], 0, 10);
    }
  } catch (Throwable $e) { error_log('FOMAXO member check: website orders: ' . $e->getMessage()); }
  if ($pos) try {
    $s = $pos->prepare('SELECT id, name, phone FROM customers WHERE ' . FX_MEMBER_DIGITS . ' LIKE ?');
    $s->execute(['%' . $p9]);
    foreach ($s->fetchAll() as $c) {
      if (fx_member_p9($c['phone']) !== $p9) continue;
      $r['found'] = true;
      if ($r['name'] === '') $r['name'] = trim((string)$c['name']);
      $q = $pos->prepare("SELECT COUNT(*) n, COALESCE(SUM(CASE WHEN created_at >= ? THEN total ELSE 0 END), 0) y, MIN(created_at) f FROM sales WHERE customer_id = ? AND status = 'paid'");
      $q->execute([$start, $c['id']]); $x = $q->fetch();
      $r['store_visits'] += (int)$x['n']; $r['store_year'] += (int)$x['y'];
      if ($x['f'] && ($r['since'] === '' || substr((string)$x['f'], 0, 10) < $r['since'])) $r['since'] = substr((string)$x['f'], 0, 10);
    }
  } catch (Throwable $e) { error_log('FOMAXO member check: store purchases: ' . $e->getMessage()); }
  $r['year'] = $r['web_year'] + $r['store_year'];
  return $r;
}
/* what the customer sees: level now, the next one and how much more this year */
function fx_member_view($f) {
  $aed = floor($f['year'] / 100); $cur = null; $next = null;   // whole dirhams, so the spend and "AED … more" always add up to the level
  foreach (fx_member_tiers() as $t) { if ($aed >= $t['min']) $cur = $t; elseif (!$next) $next = $t; }
  $first = preg_split('/\s+/', trim((string)$f['name']))[0] ?? '';
  return ['ok' => true, 'name' => mb_convert_case(mb_strtolower($first), MB_CASE_TITLE), 'spend' => $aed,
    'level' => $cur ? $cur['name'] : '', 'next' => $next ? $next['name'] : '', 'needed' => $next ? ceil($next['min'] - $aed) : 0,
    'web_orders' => $f['web_orders'], 'store_visits' => $f['store_visits'], 'year' => (int)date('Y'),
    'tiers' => array_map(fn($t) => ['name' => $t['name'], 'min' => $t['min']], fx_member_tiers())];
}

/* ---- codes ---- */
function fx_member_file($key) { return rv_dir('member') . '/' . substr(hash_hmac('sha256', $key, rv_secret()), 0, 40) . '.json'; }
function fx_member_read($f) { $j = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null; return is_array($j) ? $j : []; }
function fx_member_write($f, $j) { @file_put_contents($f, json_encode($j), LOCK_EX); @chmod($f, 0600); }
/* how many times $key was used in the last $sec seconds; $add = count this one too */
function fx_member_count($key, $sec, $add) {
  $f = fx_member_file("rate:$key"); $now = time();
  $j = array_values(array_filter((array)(fx_member_read($f)['t'] ?? []), fn($t) => $t > $now - 86400));
  $n = count(array_filter($j, fn($t) => $t > $now - $sec));
  if ($add) { $j[] = $now; fx_member_write($f, ['t' => $j]); }
  return $n;
}
function fx_member_tidy() {   // old code files (now and then)
  if (random_int(1, 20) !== 1) return;
  foreach (glob(rv_dir('member') . '/*.json') ?: [] as $f) if (@filemtime($f) < time() - 2 * 86400) @unlink($f);
}

/* the WhatsApp template with the code (Authentication templates: the code in the body and again in the "Copy code" button) */
function fx_member_wa_send($to, $code, $lang, $c) {
  $ver = preg_replace('/[^v0-9.]/', '', (string)($c['api_version'] ?? 'v21.0')) ?: 'v21.0';
  $api = rtrim((string)($c['api'] ?? 'https://graph.facebook.com'), '/');
  $body = ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'template', 'template' => [
    'name' => (string)($c['member_template'] ?? 'member_code'), 'language' => ['code' => $lang],
    'components' => [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $code]]],
                     ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => $code]]]]]];
  $ch = curl_init("$api/$ver/" . rawurlencode(trim((string)$c['phone_number_id'])) . '/messages');
  curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . trim((string)$c['access_token']), 'Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
  $res = curl_exec($ch); $http = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
  $d = $res ? json_decode($res, true) : null;
  if ($http === 200 && !empty($d['messages'][0]['id'])) return true;
  error_log('FOMAXO member code not sent: HTTP ' . $http . ' ' . ($d['error']['message'] ?? ''));
  return false;
}

/* "Send code": the same answer whether or not the number has shopped with us, so nobody learns who our customers are.
   Returns ['ok' => true] (sent, or nothing to send), ['ok' => false, 'why' => 'phone'|'many'|'wa'] */
function fx_member_send($phone, $lang, $ip, $web, $pos, $wa) {
  $to = fx_wa_number($phone); $p9 = fx_member_p9($to);
  if (!$to || !$p9) return ['ok' => false, 'why' => 'phone'];
  if (!$wa) return ['ok' => false, 'why' => 'wa'];
  if (fx_member_count("ip:$ip", 3600, false) >= 10 || fx_member_count("m:$p9", 3600, false) >= 3 || fx_member_count("m:$p9", 86400, false) >= 6) return ['ok' => false, 'why' => 'many'];
  fx_member_count("ip:$ip", 3600, true); fx_member_count("m:$p9", 3600, true);
  fx_member_tidy();
  if (!fx_member_find($p9, $web, $pos)['found']) return ['ok' => true];   // never shopped: no message (and no cost), same answer
  $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
  $sent = fx_member_wa_send($to, $code, $lang === 'ar' ? 'ar' : 'en', $wa) || ($lang === 'ar' && fx_member_wa_send($to, $code, 'en', $wa));   // no Arabic version approved: English
  if (!$sent) return ['ok' => false, 'why' => 'wa'];
  fx_member_write(fx_member_file("code:$p9"), ['h' => hash_hmac('sha256', $code, rv_secret() . $p9), 'exp' => time() + FX_MEMBER_CODE_MIN * 60, 'tries' => 0]);
  return ['ok' => true];
}
/* "Show my level": the right code (once) gives that mobile's spending and level */
function fx_member_check($phone, $code, $web, $pos) {
  $p9 = fx_member_p9(fx_wa_number($phone)); $code = preg_replace('/\D/', '', (string)$code);
  if (!$p9 || strlen($code) !== 6) return ['ok' => false, 'why' => 'code'];
  $f = fx_member_file("code:$p9"); $j = fx_member_read($f);
  if (!$j) return ['ok' => false, 'why' => 'code'];   // no code was sent to this number: the same answer as a wrong code
  if (($j['exp'] ?? 0) < time() || ($j['tries'] ?? 0) >= 5) { @unlink($f); return ['ok' => false, 'why' => 'old']; }
  if (!hash_equals((string)$j['h'], hash_hmac('sha256', $code, rv_secret() . $p9))) { $j['tries'] = ($j['tries'] ?? 0) + 1; fx_member_write($f, $j); return ['ok' => false, 'why' => 'code']; }
  @unlink($f);
  return fx_member_view(fx_member_find($p9, $web, $pos));
}
