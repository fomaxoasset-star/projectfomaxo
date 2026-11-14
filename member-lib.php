<?php
/* FOMAXO — "Check my level" on the FOMAXO Elite page (#/collections/elite): a customer types their name and mobile and sees their spending this year and their level.
   AJAY 15 Nov 2026: name + mobile, no WhatsApp code. The name must match the name on that mobile's orders (first name), and tries are limited
   (8 an hour from one connection, 5 an hour for one mobile), so numbers can't be run through one after another.
   - Spending this year = fomaxo.com orders + store (POS) purchases on the same mobile, the same sum the store system uses for its tiers
     (pos.fomaxo.com lib/core.php customer_year_spend + lib/website.php). fomaxo.in orders are in rupees and are not counted, as in the store.
   - Levels come from the store system's own settings file ('tiers'), so the website and the store always agree; the four default levels otherwise.
   - Try counts are kept in domains/fomaxo.com/fomaxo-reviews/member/ (private, outside public_html), only as hashed keys. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }
require_once __DIR__ . '/whatsapp-lib.php';

const FX_MEMBER_TIERS = [['name' => 'Black', 'min' => 4000], ['name' => 'Gold', 'min' => 7500], ['name' => 'Elite', 'min' => 15000], ['name' => 'Imperial', 'min' => 30000]];

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
  $r = ['found' => false, 'name' => '', 'names' => [], 'year' => 0, 'web_year' => 0, 'store_year' => 0, 'web_orders' => 0, 'store_visits' => 0, 'since' => ''];
  if (strlen($p9) !== 9) return $r;
  if ($web) try {
    $s = $web->prepare("SELECT name, phone, total, created_at FROM fx_orders WHERE test = 0 AND status NOT IN ('Awaiting payment', 'Cancelled', 'Refunded')
                        AND " . FX_MEMBER_DIGITS . " LIKE ? ORDER BY created_at DESC LIMIT 500");
    $s->execute(['%' . $p9]);
    foreach ($s->fetchAll() as $o) {
      if (fx_member_p9($o['phone']) !== $p9) continue;   // LIKE '%…' also takes longer numbers ending the same way
      $r['found'] = true; $r['web_orders']++;
      if ($r['name'] === '') $r['name'] = trim((string)$o['name']);
      $r['names'][] = (string)$o['name'];
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
      $r['names'][] = (string)$c['name'];
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

/* the level a spend (AED) reaches: '' below the first level */
function fx_member_tier($aed) { $n = ''; foreach (fx_member_tiers() as $t) if ($aed >= $t['min']) $n = $t['name']; return $n; }
/* "Reached a level" for FOMAXO (AJAY 15 Nov 2026): did this website order take its customer to a new level?
   Counts that order's year: website orders on the same mobile placed before it (and itself), plus paid store purchases before it.
   $o = an fx_orders row (order_no, phone, total, status, test, created_at). Returns null when it can't tell, else
   ['before' => level before, 'after' => level with this order, 'spend' => AED this year with this order, 'up' => true when it moved up]. */
function fx_member_order_level($o, $web, $pos) {
  if (!$o || !empty($o['test']) || in_array($o['status'] ?? '', ['Awaiting payment', 'Cancelled', 'Refunded'], true)) return null;
  $p9 = fx_member_p9($o['phone'] ?? ''); $at = (string)($o['created_at'] ?? ''); if (!$p9 || $at === '') return null;
  $start = substr($at, 0, 4) . '-01-01 00:00:00'; $before = 0;
  if ($web) try {
    $s = $web->prepare("SELECT order_no, phone, total, created_at FROM fx_orders WHERE test = 0 AND status NOT IN ('Awaiting payment', 'Cancelled', 'Refunded')
                        AND created_at >= ? AND created_at <= ? AND " . FX_MEMBER_DIGITS . " LIKE ?");
    $s->execute([$start, $at, '%' . $p9]);
    foreach ($s->fetchAll() as $x) if ($x['order_no'] !== $o['order_no'] && fx_member_p9($x['phone']) === $p9) $before += (int)round((float)$x['total'] * 100);
  } catch (Throwable $e) { return null; }
  if ($pos) try {
    $s = $pos->prepare("SELECT c.phone, s.total FROM sales s JOIN customers c ON c.id = s.customer_id WHERE s.status = 'paid' AND s.created_at >= ? AND s.created_at < ? AND " . str_replace('phone', 'c.phone', FX_MEMBER_DIGITS) . " LIKE ?");
    $s->execute([$start, $at, '%' . $p9]);
    foreach ($s->fetchAll() as $x) if (fx_member_p9($x['phone']) === $p9) $before += (int)$x['total'];
  } catch (Throwable $e) {}
  $after = $before + (int)round((float)$o['total'] * 100);
  $b = fx_member_tier(floor($before / 100)); $a = fx_member_tier(floor($after / 100));
  return ['before' => $b, 'after' => $a, 'spend' => floor($after / 100), 'up' => $a !== '' && $a !== $b];
}
/* every website order that took its customer to a new level: [order_no => level], for the admin Orders list and order page (one pass over all orders) */
function fx_member_levelups($web, $pos) {
  $ev = [];   // p9 => [[time, fils, order_no or ''], …]
  if ($web) try {
    foreach ($web->query("SELECT order_no, phone, total, created_at FROM fx_orders WHERE test = 0 AND status NOT IN ('Awaiting payment', 'Cancelled', 'Refunded')") as $x)
      if (($p = fx_member_p9($x['phone'])) !== '') $ev[$p][] = [(string)$x['created_at'], (int)round((float)$x['total'] * 100), (string)$x['order_no']];
  } catch (Throwable $e) { return []; }
  if ($pos && $ev) try {
    foreach ($pos->query("SELECT c.phone, s.total, s.created_at FROM sales s JOIN customers c ON c.id = s.customer_id WHERE s.status = 'paid'") as $x)
      if (($p = fx_member_p9($x['phone'])) !== '' && isset($ev[$p])) $ev[$p][] = [(string)$x['created_at'], (int)$x['total'], ''];
  } catch (Throwable $e) {}
  $up = [];
  foreach ($ev as $list) {
    usort($list, fn($a, $b) => strcmp($a[0], $b[0]));
    $yr = ''; $sum = 0;
    foreach ($list as [$t, $f, $no]) {
      if (substr($t, 0, 4) !== $yr) { $yr = substr($t, 0, 4); $sum = 0; }
      $b = fx_member_tier(floor($sum / 100)); $sum += $f; $a = fx_member_tier(floor($sum / 100));
      if ($no !== '' && $a !== '' && $a !== $b) $up[$no] = $a;
    }
  }
  return $up;
}
/* everyone who spent this year (website orders + paid store purchases), by mobile: [p9 => ['name', 'phone', 'year' (fils), 'web', 'store']], for admin Members → Membership reminders */
function fx_member_all($web, $pos, $year = null) {
  $year = $year ?: date('Y'); $start = "$year-01-01 00:00:00"; $all = [];
  if ($web) try {
    $s = $web->prepare("SELECT name, phone, total FROM fx_orders WHERE test = 0 AND status NOT IN ('Awaiting payment', 'Cancelled', 'Refunded') AND created_at >= ? ORDER BY created_at");
    $s->execute([$start]);
    foreach ($s as $x) { if (($p = fx_member_p9($x['phone'])) === '') continue;
      $all[$p] ??= ['name' => '', 'phone' => '', 'year' => 0, 'web' => 0, 'store' => 0];
      $all[$p]['name'] = trim((string)$x['name']) ?: $all[$p]['name']; $all[$p]['phone'] = (string)$x['phone'];   // latest wins
      $f = (int)round((float)$x['total'] * 100); $all[$p]['year'] += $f; $all[$p]['web']++; }
  } catch (Throwable $e) {}
  if ($pos) try {
    $s = $pos->prepare("SELECT c.name, c.phone, s.total FROM sales s JOIN customers c ON c.id = s.customer_id WHERE s.status = 'paid' AND s.created_at >= ?");
    $s->execute([$start]);
    foreach ($s as $x) { if (($p = fx_member_p9($x['phone'])) === '' || strpos((string)$x['phone'], 'e:') === 0) continue;
      $all[$p] ??= ['name' => trim((string)$x['name']), 'phone' => (string)$x['phone'], 'year' => 0, 'web' => 0, 'store' => 0];
      if ($all[$p]['name'] === '') $all[$p]['name'] = trim((string)$x['name']);
      $all[$p]['year'] += (int)$x['total']; $all[$p]['store']++; }
  } catch (Throwable $e) {}
  return $all;
}
/* level, next level and how much more, for a spend in fils */
function fx_member_where($fils) {
  $aed = floor($fils / 100); $cur = ''; $next = null;
  foreach (fx_member_tiers() as $t) { if ($aed >= $t['min']) $cur = $t['name']; elseif (!$next) $next = $t; }
  return ['level' => $cur, 'spend' => $aed, 'next' => $next ? $next['name'] : '', 'needed' => $next ? ceil($next['min'] - $aed) : 0];
}
/* the WhatsApp membership reminder (admin): [head, tail, arabic]. $w = fx_member_where() */
function fx_member_msg($name, $w, $ar = false) {
  $first = preg_split('/\s+/u', trim((string)$name))[0] ?? ''; $n2 = "\n\n"; $m = fn($v) => 'AED ' . number_format($v);
  $link = 'https://fomaxo.com/?' . ($ar ? 'lang=ar&' : '') . 'utm_source=whatsapp&utm_campaign=membership#/collections/elite?check=1';
  if ($ar) {
    $lv = $w['level'] !== '' ? 'مستواك في FOMAXO Elite: *' . strtoupper($w['level']) . '*' . "\n" . 'إنفاقك هذا العام: ' . $m($w['spend']) : 'إنفاقك في FOMAXO هذا العام: ' . $m($w['spend']);
    $go = $w['next'] !== '' ? 'باقي ' . $m($w['needed']) . ' فقط هذا العام للوصول إلى *' . strtoupper($w['next']) . '*.' : 'أنت في أعلى مستوى. شكراً لك!';
    return [($first !== '' ? 'مرحباً ' . $first . '،' : 'مرحباً،') . $n2 . 'شكراً لكونك من عملاء FOMAXO.' . $n2 . $lv . $n2 . $go,
      $n2 . "اعرف مستواك في أي وقت:\n" . $link . $n2 . 'FOMAXO', true];
  }
  $lv = $w['level'] !== '' ? 'Your FOMAXO Elite level: *' . strtoupper($w['level']) . '*' . "\n" . 'Spent this year: ' . $m($w['spend']) : 'Your FOMAXO spending this year: ' . $m($w['spend']);
  $go = $w['next'] !== '' ? 'Only ' . $m($w['needed']) . ' more this year to reach *' . strtoupper($w['next']) . '*.' : 'You are at our highest level. Thank you!';
  return ['Hi' . ($first !== '' ? ' ' . $first : '') . ',' . $n2 . 'Thank you for being a FOMAXO customer.' . $n2 . $lv . $n2 . $go,
    $n2 . "Check your level anytime:\n" . $link . $n2 . 'FOMAXO', false];
}
/* a "Check my level" try goes to Left at checkout (stage 'member'): name, mobile and what they saw. One line per mobile per day. */
function fx_member_lead($web, $phone, $name, $r) {
  if (!$web) return;
  $p9 = fx_member_p9($phone); if ($p9 === '') return;
  $sid = substr(hash('sha256', 'member:' . $p9 . ':' . date('Y-m-d')), 0, 16);
  $what = !empty($r['ok']) ? 'Checked level: ' . ($r['level'] !== '' ? strtoupper($r['level']) : 'no level yet') . ' · AED ' . number_format($r['spend']) . ' this year'
        . ($r['next'] !== '' ? ' · AED ' . number_format($r['needed']) . ' more to ' . strtoupper($r['next']) : '') : 'Checked level: no purchases found for this name and mobile';
  try { $web->prepare("INSERT INTO fx_leads (sid, vid, created_at, updated_at, stage, name, phone, items, total) VALUES (?, ?, NOW(), NOW(), 'member', ?, ?, ?, ?)
                       ON DUPLICATE KEY UPDATE updated_at = NOW(), name = VALUES(name), items = VALUES(items), total = VALUES(total), hidden = 0")
        ->execute([$sid, $sid, mb_substr(trim((string)$name), 0, 80), mb_substr(trim((string)$phone), 0, 25), mb_substr($what, 0, 600), !empty($r['ok']) ? $r['spend'] : null]);
  } catch (Throwable $e) { error_log('FOMAXO member lead: ' . $e->getMessage()); }
}

/* one line for FOMAXO's new-order email ('' when the order didn't reach a new level) */
function fx_member_email_line($orderNo) {
  try {
    $web = fomaxo_db(); if (!$web) return '';
    $s = $web->prepare('SELECT order_no, phone, total, status, test, created_at FROM fx_orders WHERE order_no = ?'); $s->execute([$orderNo]);
    $l = fx_member_order_level($s->fetch() ?: null, $web, fx_member_pos_db());
    return $l && $l['up'] ? "\n\nFOMAXO ELITE: this customer just reached " . strtoupper($l['after']) . " with this order (AED " . number_format($l['spend']) . " this year, website + store)." : '';
  } catch (Throwable $e) { return ''; }
}

/* ---- tries ---- */
function fx_member_file($key) { return rv_dir('member') . '/' . substr(hash_hmac('sha256', $key, rv_secret()), 0, 40) . '.json'; }
function fx_member_read($f) { $j = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null; return is_array($j) ? $j : []; }
/* how many times $key was used in the last $sec seconds; $add = count this one too */
function fx_member_count($key, $sec, $add) {
  $f = fx_member_file("rate:$key"); $now = time();
  $j = array_values(array_filter((array)(fx_member_read($f)['t'] ?? []), fn($t) => $t > $now - 86400));
  $n = count(array_filter($j, fn($t) => $t > $now - $sec));
  if ($add) { $j[] = $now; @file_put_contents($f, json_encode(['t' => $j]), LOCK_EX); @chmod($f, 0600); }
  return $n;
}
function fx_member_tidy() {   // old try files (now and then)
  if (random_int(1, 20) !== 1) return;
  foreach (glob(rv_dir('member') . '/*.json') ?: [] as $f) if (@filemtime($f) < time() - 2 * 86400) @unlink($f);
}
/* a name in plain letters: "SARA  Ahmed" → ["sara", "ahmed"] (Arabic letters kept) */
function fx_member_words($n) { return array_values(array_filter(preg_split('/[^\p{L}]+/u', mb_strtolower(trim((string)$n))), fn($w) => $w !== '')); }
/* the typed name fits when its first name is the first name on one of that mobile's orders (or the whole name matches) */
function fx_member_name_ok($typed, array $names) {
  $t = fx_member_words($typed); if (!$t) return false;
  foreach ($names as $n) { $w = fx_member_words($n); if ($w && ($w[0] === $t[0] || implode(' ', $w) === implode(' ', $t))) return true; }
  return false;
}

/* "Show my level": ['ok' => true, …level…] or ['ok' => false, 'why' => 'phone'|'name'|'many'|'none'] */
function fx_member_lookup($phone, $name, $ip, $web, $pos) {
  $p9 = fx_member_p9(fx_wa_number($phone) ?: $phone);
  if (!$p9) return ['ok' => false, 'why' => 'phone'];
  if (!fx_member_words($name)) return ['ok' => false, 'why' => 'name'];
  if (fx_member_count("ip:$ip", 3600, false) >= 8 || fx_member_count("m:$p9", 3600, false) >= 5) return ['ok' => false, 'why' => 'many'];
  fx_member_count("ip:$ip", 3600, true); fx_member_count("m:$p9", 3600, true);
  fx_member_tidy();
  $f = fx_member_find($p9, $web, $pos);
  if (!$f['found'] || !fx_member_name_ok($name, $f['names'])) return ['ok' => false, 'why' => 'none'];   // same answer for a wrong name and an unknown number
  return fx_member_view($f);
}
