<?php
/* FOMAXO — order database (MySQL on Hostinger). Used by checkout.php (cash on delivery), ziina.php (card) and admin/.
   Every order gets a number that counts up: FMX-1001, FMX-1002 …
   The database login lives in fomaxo-db-config.php ONE LEVEL ABOVE public_html (never on GitHub, never public).
   It is written once by the set-up form on fomaxo.com/admin. If the database is not set up or is down,
   orders still go through with the old random number and are kept in the CSV files and emails as before. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }

const FX_STATUSES = ['Awaiting payment', 'New', 'Paid', 'Delivered', 'Cancelled', 'Refunded'];
/* Arabic (٠١٢…) and Persian (۰۱۲…) digits → 0-9, for phone numbers typed on an Arabic keyboard (names and addresses stay exactly as typed) */
const FX_AR_DIGITS = ['٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9','۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9'];

function fomaxo_db_config_file() { return dirname(__DIR__) . '/fomaxo-db-config.php'; }
function fomaxo_db_connect($c) {
  $pdo = new PDO("mysql:host={$c['host']};port=" . ($c['port'] ?? 3306) . ";dbname={$c['name']};charset=utf8mb4", $c['user'], $c['pass'],
                 [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 5]);
  $pdo->exec("SET time_zone = '+04:00'");   // Dubai
  return $pdo;
}
/* Returns the database connection, or null when it is not set up / not reachable (orders then fall back to CSV + email). */
function fomaxo_db() {
  static $pdo = false;
  if ($pdo !== false) return $pdo;
  $pdo = null;
  $f = fomaxo_db_config_file();
  if (!is_file($f)) return null;
  $c = require $f;
  if (!is_array($c) || empty($c['name'])) return null;
  try { $pdo = fomaxo_db_connect($c); fomaxo_db_schema($pdo); }
  catch (Throwable $e) { error_log('FOMAXO database: ' . $e->getMessage()); $pdo = null; }
  return $pdo;
}

/* Creates the tables the first time. The back office starts empty: older orders stay in the CSV files above public_html as a backup. */
function fomaxo_db_schema($pdo) {
  $ver = 0;
  try { $ver = (int)$pdo->query("SELECT v FROM fx_settings WHERE k = 'schema'")->fetchColumn(); } catch (Throwable $e) {}
  if ($ver >= 18) return;
  /* start from zero: remove the copies of old CSV orders that the first version pulled in (the CSV files themselves stay as a backup) */
  if ($ver === 1) $pdo->exec("DELETE FROM fx_orders WHERE source = 'import'");
  if ($ver === 0) fomaxo_db_tables($pdo);
  /* v3: products move into the database (added and edited on fomaxo.com/admin → Products); orders, stock, expenses are not touched */
  if ($ver < 3) fomaxo_products_table($pdo);
  /* v4: visit stats for fomaxo.com/admin → Analytics */
  fomaxo_analytics_tables($pdo);
  /* v5: the country (and UAE emirate) of each visit, for Analytics → Top countries and UAE emirates */
  if ($ver < 5) {
    try { $pdo->exec("ALTER TABLE fx_events ADD COLUMN country CHAR(2) NULL, ADD COLUMN region VARCHAR(20) NULL, ADD KEY (country, at)"); } catch (Throwable $e) {}
    if (!$pdo->query("SHOW COLUMNS FROM fx_events LIKE 'country'")->fetch()) return;
    if (!fomaxo_setting($pdo, 'geo_since')) fomaxo_setting($pdo, 'geo_since', date('Y-m-d'));
    $pdo->exec("REPLACE INTO fx_settings (k, v) VALUES ('schema', '5')");
  }
  /* v6: the delivery address typed at checkout, kept with the other "Left at checkout" details */
  try { $pdo->exec("ALTER TABLE fx_leads ADD COLUMN address VARCHAR(300) NOT NULL DEFAULT ''"); } catch (Throwable $e) {}
  if (!$pdo->query("SHOW COLUMNS FROM fx_leads LIKE 'address'")->fetch()) return;
  $pdo->exec("REPLACE INTO fx_settings (k, v) VALUES ('schema', '6')");
  /* v7: when an order was delivered, for the order tracker on fomaxo.com/admin (old delivered orders take their last change) */
  try { $pdo->exec("ALTER TABLE fx_orders ADD COLUMN delivered_at DATETIME NULL AFTER paid_at"); $pdo->exec("UPDATE fx_orders SET delivered_at = updated_at WHERE status = 'Delivered'"); } catch (Throwable $e) {}
  if (!$pdo->query("SHOW COLUMNS FROM fx_orders LIKE 'delivered_at'")->fetch()) return;
  $pdo->exec("REPLACE INTO fx_settings (k, v) VALUES ('schema', '7')");
  /* v8: the new poster is the main photo of six perfumes (only where the old main photo is still first, so a photo picked in admin stays) */
  $get = $pdo->prepare('SELECT data FROM fx_products WHERE id = ?'); $set = $pdo->prepare('UPDATE fx_products SET data = ? WHERE id = ?');
  if ($ver < 10) {
  foreach (['gold', 'oldmoney', 'royalcandy', 'dollar', 'matchacoco', 'passionsin'] as $id) {
    $get->execute([$id]); $d = json_decode((string)$get->fetchColumn(), true);
    if (is_array($d) && ($d['images'][0] ?? '') === "$id-1") { $d['images'][0] = "$id-main"; $set->execute([fomaxo_json($d), $id]); }
  }
  $pdo->exec("REPLACE INTO fx_settings (k, v) VALUES ('schema', '8')");
  /* v9: newer posters (without the notes text) for three of them, again only where the v8 poster is still first */
  foreach (['gold', 'dollar', 'matchacoco'] as $id) {
    $get->execute([$id]); $d = json_decode((string)$get->fetchColumn(), true);
    if (is_array($d) && ($d['images'][0] ?? '') === "$id-main") { $d['images'][0] = "$id-main2"; $set->execute([fomaxo_json($d), $id]); }
  }
  $pdo->exec("REPLACE INTO fx_settings (k, v) VALUES ('schema', '9')");
  /* v10: King's poster as its main photo (only where the old one is still first) */
  $get->execute(['king']); $d = json_decode((string)$get->fetchColumn(), true);
  if (is_array($d) && ($d['images'][0] ?? '') === 'king-1') { $d['images'][0] = 'king-main'; $set->execute([fomaxo_json($d), 'king']); }
  $pdo->exec("REPLACE INTO fx_settings (k, v) VALUES ('schema', '10')");
  }
  /* v11: King's new fragrance notes (top, heart and base) */
  if ($ver < 11) {
    $get->execute(['king']); $d = json_decode((string)$get->fetchColumn(), true);
    if (is_array($d)) { $d['notes'] = ['top' => 'Almond', 'heart' => 'Cinnamon · Tunisian Orange Blossom · Turkish Rose', 'base' => 'Tonka Bean · Vanilla · Amberwood']; $set->execute([fomaxo_json($d), 'king']); }
    $pdo->exec("REPLACE INTO fx_settings (k, v) VALUES ('schema', '11')");
  }
  /* v12: when a visitor was last seen on each page, for Analytics → Pages (time spent on a page) */
  if ($ver < 12) {
    try { $pdo->exec("ALTER TABLE fx_events ADD COLUMN left_at DATETIME NULL"); } catch (Throwable $e) {}
    if (!$pdo->query("SHOW COLUMNS FROM fx_events LIKE 'left_at'")->fetch()) return;
    if (!fomaxo_setting($pdo, 'time_since')) fomaxo_setting($pdo, 'time_since', date('Y-m-d'));
    $pdo->exec("REPLACE INTO fx_settings (k, v) VALUES ('schema', '12')");
  }
  /* v13: the campaign name of a visit (utm_campaign) and how far down the homepage it scrolled, for Analytics → Conversion */
  try { $pdo->exec("ALTER TABLE fx_events ADD COLUMN campaign VARCHAR(60) NULL, ADD COLUMN depth TINYINT NULL"); } catch (Throwable $e) {}
  if (!$pdo->query("SHOW COLUMNS FROM fx_events LIKE 'depth'")->fetch()) return;
  if (!fomaxo_setting($pdo, 'conv_since')) fomaxo_setting($pdo, 'conv_since', date('Y-m-d'));
  $pdo->exec("REPLACE INTO fx_settings (k, v) VALUES ('schema', '13')");
  /* v14: coupon codes made on fomaxo.com/admin → Coupons, and the code each order used */
  fomaxo_coupons_table($pdo);
  try { $pdo->exec("ALTER TABLE fx_orders ADD COLUMN coupon VARCHAR(30) NULL"); } catch (Throwable $e) {}
  if (!$pdo->query("SHOW COLUMNS FROM fx_orders LIKE 'coupon'")->fetch()) return;
  $pdo->exec("REPLACE INTO fx_settings (k, v) VALUES ('schema', '14')");
  /* v15: time-limited coupons: a start and an end date + time (Dubai); a code made with only an expiry day ends at 23:59 that day */
  try { $pdo->exec("ALTER TABLE fx_coupons ADD COLUMN starts DATETIME NULL, ADD COLUMN ends DATETIME NULL"); } catch (Throwable $e) {}
  if (!$pdo->query("SHOW COLUMNS FROM fx_coupons LIKE 'ends'")->fetch()) return;
  $pdo->exec("UPDATE fx_coupons SET ends = CONCAT(expires, ' 23:59:59') WHERE expires IS NOT NULL AND ends IS NULL");
  $pdo->exec("REPLACE INTO fx_settings (k, v) VALUES ('schema', '15')");
  /* v16: which language a page was seen in (English or Arabic site), for Analytics → Devices */
  try { $pdo->exec("ALTER TABLE fx_events ADD COLUMN lang CHAR(2) NULL"); } catch (Throwable $e) {}
  if (!$pdo->query("SHOW COLUMNS FROM fx_events LIKE 'lang'")->fetch()) return;
  if (!fomaxo_setting($pdo, 'lang_since')) fomaxo_setting($pdo, 'lang_since', date('Y-m-d'));
  $pdo->exec("REPLACE INTO fx_settings (k, v) VALUES ('schema', '16')");
  /* v17: the customer ticked "Send me offers and updates on WhatsApp" at checkout */
  try { $pdo->exec("ALTER TABLE fx_orders ADD COLUMN wa_optin TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
  if (!$pdo->query("SHOW COLUMNS FROM fx_orders LIKE 'wa_optin'")->fetch()) return;
  $pdo->exec("REPLACE INTO fx_settings (k, v) VALUES ('schema', '17')");
  /* v18: a row you took off Analytics → Left at checkout with its × (kept, just not listed) */
  try { $pdo->exec("ALTER TABLE fx_leads ADD COLUMN hidden TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
  if (!$pdo->query("SHOW COLUMNS FROM fx_leads LIKE 'hidden'")->fetch()) return;
  $pdo->exec("REPLACE INTO fx_settings (k, v) VALUES ('schema', '18')");
}
function fomaxo_coupons_table($pdo) {
  $pdo->exec("CREATE TABLE IF NOT EXISTS fx_coupons (code VARCHAR(30) NOT NULL PRIMARY KEY, kind VARCHAR(3) NOT NULL DEFAULT 'pct', amount DECIMAL(10,2) NOT NULL,
              min_order DECIMAL(10,2) NULL, expires DATE NULL, max_uses INT NULL, active TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME NULL) DEFAULT CHARSET=utf8mb4");
  /* stack = 1: "Use both" (the coupon comes off after the multi-buy discount); 0: "Use the bigger offer" */
  try { if (!$pdo->query("SHOW COLUMNS FROM fx_coupons LIKE 'stack'")->fetch()) $pdo->exec("ALTER TABLE fx_coupons ADD COLUMN stack TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
  /* phone: a one-time coupon made for one customer (late delivery, faulty product) only works with that mobile number */
  try { if (!$pdo->query("SHOW COLUMNS FROM fx_coupons LIKE 'phone'")->fetch()) $pdo->exec("ALTER TABLE fx_coupons ADD COLUMN phone VARCHAR(25) NULL"); } catch (Throwable $e) {}
}
/* the same mobile written any way (+971 50…, 050…, Arabic digits) gives the same last 9 digits */
function fomaxo_phone9($p) { return substr(preg_replace('/\D/', '', strtr((string)$p, FX_AR_DIGITS)), -9); }
function fomaxo_db_tables($pdo) {
  $pdo->exec("CREATE TABLE IF NOT EXISTS fx_orders (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    order_no VARCHAR(40) NOT NULL UNIQUE,
    created_at DATETIME NOT NULL,
    payment VARCHAR(30) NOT NULL,
    status VARCHAR(20) NOT NULL,
    paid_at DATETIME NULL,
    subtotal DECIMAL(10,2) NULL, discount DECIMAL(10,2) NULL, fee DECIMAL(10,2) NULL, total DECIMAL(10,2) NOT NULL DEFAULT 0,
    cost DECIMAL(10,2) NULL,
    name VARCHAR(80) NOT NULL DEFAULT '', phone VARCHAR(25) NOT NULL DEFAULT '', email VARCHAR(120) NOT NULL DEFAULT '',
    emirate VARCHAR(30) NOT NULL DEFAULT '', building VARCHAR(40) NOT NULL DEFAULT '', room VARCHAR(20) NOT NULL DEFAULT '',
    street VARCHAR(100) NOT NULL DEFAULT '', area VARCHAR(80) NOT NULL DEFAULT '', address VARCHAR(300) NOT NULL DEFAULT '',
    note VARCHAR(300) NOT NULL DEFAULT '',
    items TEXT NULL, lines_json TEXT NULL, free_mini VARCHAR(40) NULL,
    ref VARCHAR(80) NULL, test TINYINT(1) NOT NULL DEFAULT 0, source VARCHAR(10) NOT NULL DEFAULT 'site',
    admin_note TEXT NULL, updated_at DATETIME NULL, stock_taken TINYINT(1) NOT NULL DEFAULT 0,
    KEY (created_at), KEY (status), KEY (ref)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  /* per product and size: stock (NULL = not counted, always available) and cost price (what one bottle costs you) */
  $pdo->exec("CREATE TABLE IF NOT EXISTS fx_stock (product VARCHAR(30) NOT NULL, size VARCHAR(10) NOT NULL, qty INT NULL, cost DECIMAL(10,2) NULL,
              updated_at DATETIME NULL, PRIMARY KEY (product, size)) DEFAULT CHARSET=utf8mb4");
  $pdo->exec("CREATE TABLE IF NOT EXISTS fx_expenses (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, day DATE NOT NULL, category VARCHAR(30) NOT NULL,
              amount DECIMAL(10,2) NOT NULL, note VARCHAR(200) NOT NULL DEFAULT '', created_at DATETIME NULL, KEY (day)) DEFAULT CHARSET=utf8mb4");
  $pdo->exec("CREATE TABLE IF NOT EXISTS fx_settings (k VARCHAR(40) NOT NULL PRIMARY KEY, v TEXT NULL) DEFAULT CHARSET=utf8mb4");
  $pdo->exec("CREATE TABLE IF NOT EXISTS fx_login (ip VARCHAR(45) NOT NULL, at DATETIME NOT NULL, KEY (ip, at)) DEFAULT CHARSET=utf8mb4");
  /* orders count up from FMX-1001 */
  $next = max(1001, (int)$pdo->query('SELECT COALESCE(MAX(id), 0) + 1 FROM fx_orders')->fetchColumn());
  $pdo->exec("ALTER TABLE fx_orders AUTO_INCREMENT = $next");
}

/* ---- visit stats (track.php writes, fomaxo.com/admin → Analytics reads) ----
   fx_events: one row per step a visitor takes (page view, product view, add to bag, checkout, payment step, card page, purchase).
   Visitors are an anonymous random id kept in their browser; no names, IP addresses or cookies.
   fx_leads: only for checkouts where the customer typed their name or mobile, so you can see who left and at which step.
   fx_live: when each visitor was last seen, for "on the website now". */
function fomaxo_analytics_tables($pdo) {
  $pdo->exec("CREATE TABLE IF NOT EXISTS fx_events (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, at DATETIME NOT NULL,
              vid CHAR(16) NOT NULL, sid CHAR(16) NOT NULL, ev VARCHAR(10) NOT NULL, page VARCHAR(80) NOT NULL DEFAULT '',
              product VARCHAR(40) NULL, source VARCHAR(40) NULL, ref VARCHAR(120) NULL, device VARCHAR(8) NOT NULL DEFAULT '',
              KEY (at), KEY (sid), KEY (vid), KEY (ev, at)) DEFAULT CHARSET=utf8mb4");
  $pdo->exec("CREATE TABLE IF NOT EXISTS fx_live (vid CHAR(16) NOT NULL PRIMARY KEY, seen DATETIME NOT NULL, page VARCHAR(80) NOT NULL DEFAULT '', KEY (seen)) DEFAULT CHARSET=utf8mb4");
  $pdo->exec("CREATE TABLE IF NOT EXISTS fx_leads (sid CHAR(16) NOT NULL PRIMARY KEY, vid CHAR(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
              stage VARCHAR(10) NOT NULL, name VARCHAR(80) NOT NULL DEFAULT '', phone VARCHAR(25) NOT NULL DEFAULT '', email VARCHAR(120) NOT NULL DEFAULT '',
              emirate VARCHAR(30) NOT NULL DEFAULT '', items VARCHAR(600) NOT NULL DEFAULT '', total DECIMAL(10,2) NULL, order_no VARCHAR(40) NULL,
              KEY (updated_at)) DEFAULT CHARSET=utf8mb4");
}
/* email list (subscribe.php writes, fomaxo.com/admin → Members → Download email list reads): one row per email, newest interest wins */
function fomaxo_subscribers_table($pdo) {
  $pdo->exec("CREATE TABLE IF NOT EXISTS fx_subscribers (email VARCHAR(120) NOT NULL PRIMARY KEY, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
              interest VARCHAR(60) NOT NULL DEFAULT '', page VARCHAR(80) NOT NULL DEFAULT '', KEY (created_at)) DEFAULT CHARSET=utf8mb4");
}
/* where a visit came from: utm_source first, then the in-app browser (Instagram, TikTok … often send no referrer), then the referring site */
function fomaxo_source($ref, $utm, $ua) {
  $names = ['instagram' => 'Instagram', 'facebook' => 'Facebook', 'fb' => 'Facebook', 'whatsapp' => 'WhatsApp', 'wa.me' => 'WhatsApp', 'google' => 'Google',
            'tiktok' => 'TikTok', 'snapchat' => 'Snapchat', 'youtube' => 'YouTube', 'youtu.be' => 'YouTube', 'bing' => 'Bing', 't.co' => 'X', 'twitter' => 'X', 'x.com' => 'X'];
  $match = function ($s) use ($names) { foreach ($names as $k => $v) if (preg_match('/(^|[^a-z])' . preg_quote($k, '/') . '/i', $s)) return $v; return null; };
  if ($utm !== '' && ($m = $match($utm))) return $m;
  if (preg_match('/Instagram/', $ua)) return 'Instagram';
  if (preg_match('/FBAN|FBAV|FB_IAB/', $ua)) return 'Facebook';
  if (preg_match('/musical_ly|TikTok|BytedanceWebview/i', $ua)) return 'TikTok';
  if (preg_match('/Snapchat/', $ua)) return 'Snapchat';
  $host = strtolower((string)parse_url($ref, PHP_URL_HOST));
  $host = preg_replace('/^(www|m|l|lm)\./', '', $host);
  if ($host === '' || preg_match('/(^|\.)(fomaxo\.com|ziina\.com)$/', $host)) return $utm !== '' ? mb_substr(ucfirst($utm), 0, 40) : 'Direct';
  return $match($host) ?? mb_substr($host, 0, 40);
}

/* ---- products: the shop list (index.html) and the checkout price lists read these when the database is up ----
   Each row keeps the whole product as the website uses it (JSON). The first time, it is filled from products-seed.json,
   which is the product list exactly as it was in index.html, so nothing changes on the site until a product is edited. */
function fomaxo_products_table($pdo) {
  $pdo->exec("CREATE TABLE IF NOT EXISTS fx_products (id VARCHAR(40) NOT NULL PRIMARY KEY, pos INT NOT NULL DEFAULT 0,
              hidden TINYINT(1) NOT NULL DEFAULT 0, data MEDIUMTEXT NOT NULL, updated_at DATETIME NULL) DEFAULT CHARSET=utf8mb4");
  if ((int)$pdo->query('SELECT COUNT(*) FROM fx_products')->fetchColumn()) return;
  $seed = json_decode((string)@file_get_contents(__DIR__ . '/products-seed.json'));
  if (!is_array($seed)) return;
  $ins = $pdo->prepare('INSERT INTO fx_products (id, pos, hidden, data, updated_at) VALUES (?, ?, 0, ?, NOW())');
  foreach ($seed as $i => $p) if (is_object($p) && !empty($p->id)) $ins->execute([$p->id, ($i + 1) * 10, fomaxo_json($p)]);
}
function fomaxo_json($v) { return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
/* Offer popup sizes (admin → Offer → Preview → size bar): each part's [standard, smallest, biggest] on laptop (L) and phone (P).
   Sale popup: img = product pictures in px; the rest in % of the original size. New product popup: all in %. */
function fomaxo_popup_spec($new = false) {
  $pc = fn($a, $b) => ['L' => [100, $a, $b], 'P' => [100, $a, $b]];
  return $new ? ['title' => $pc(80, 200), 'img' => $pc(60, 200), 'name' => $pc(60, 160), 'line' => $pc(70, 200), 'btn' => $pc(80, 160)]
    : ['img' => ['L' => [150, 90, 220], 'P' => [120, 80, 160]], 'title' => $pc(80, 200), 'pct' => $pc(60, 160), 'sub' => $pc(70, 200), 'btn' => $pc(80, 160)];
}
/* saved sizes, each kept inside its range; missing or not a number = standard */
function fomaxo_popup_sizes($v, $new = false) {
  $v = is_array($v) ? $v : []; $out = ['L' => [], 'P' => []];
  foreach (fomaxo_popup_spec($new) as $k => $s) foreach (['L', 'P'] as $d) { $x = $v[$d][$k] ?? null; [$std, $lo, $hi] = $s[$d];
    $out[$d][$k] = is_numeric($x) ? max($lo, min($hi, (int)round($x))) : $std; }
  return $out;
}
/* All products in shop order: [['id','pos','hidden','data'(JSON text)]], or null when the database is not available. */
function fomaxo_product_rows($pdo = null) {
  $pdo = $pdo ?: fomaxo_db(); if (!$pdo) return null;
  try { $r = $pdo->query('SELECT id, pos, hidden, data FROM fx_products ORDER BY pos, id')->fetchAll(); } catch (Throwable $e) { return null; }
  return $r ?: null;
}
/* Price list for checkout.php / ziina.php built from the database (shown products only), or null to keep the built-in list. */
function fomaxo_catalog_db() {
  $rows = fomaxo_product_rows(); if (!$rows) return null;
  $cat = [];
  foreach ($rows as $r) {
    if ($r['hidden']) continue;
    $p = json_decode($r['data'], true); if (!is_array($p)) continue;
    $prices = [];
    foreach ((array)($p['sizes'] ?? []) as $s) { $v = $p['prices'][(string)$s] ?? null; if (is_numeric($v) && $v > 0) $prices[(string)$s] = $v + 0; }
    if (!$prices) continue;
    $cat[$r['id']] = ['name' => (string)($p['name'] ?? $r['id']), 'kind' => ($p['kind'] ?? '') === 'set' ? 'set' : '', 'prices' => $prices,
                      'exclude' => array_values(array_filter((array)($p['exclude'] ?? []), 'is_string'))];
  }
  return $cat ?: null;
}

function fomaxo_setting($pdo, $k, $v = null) {
  if ($v === null) { $s = $pdo->prepare('SELECT v FROM fx_settings WHERE k = ?'); $s->execute([$k]); $r = $s->fetchColumn(); return $r === false ? null : $r; }
  $pdo->prepare('REPLACE INTO fx_settings (k, v) VALUES (?, ?)')->execute([$k, $v]);
  return $v;
}

/* cash on delivery in AED, as set on fomaxo.com/admin → Settings → Cash on delivery: [min, max, fee]; max 0 = no upper limit, fee 0 = no fee.
   Defaults (nothing saved yet, or database down): from AED 200, under AED 2,000, AED 10 fee. */
function fomaxo_cod_limits($pdo = null) {
  $min = 200; $max = 2000; $fee = 10;
  try { $pdo = $pdo ?: fomaxo_db(); $c = $pdo ? json_decode((string)fomaxo_setting($pdo, 'cod'), true) : null;
    if (is_array($c)) { $min = max(0, (int)($c['min'] ?? $min)); $max = max(0, (int)($c['max'] ?? $max)); $fee = max(0, (int)($c['fee'] ?? $fee)); } } catch (Throwable $e) {}
  return [$min, $max, $fee];
}

/* Saves a new order and returns its number (FMX-1001 …), or null if the database is not available.
   $o: payment, status, subtotal, discount, fee, total (AED), customer fields, items (text), lines (array), free_mini, ref, test */
function fomaxo_save_order($o) {
  $pdo = fomaxo_db(); if (!$pdo) return null;
  $cols = ['created_at', 'payment', 'status', 'subtotal', 'discount', 'fee', 'total', 'cost', 'name', 'phone', 'email', 'emirate', 'building', 'room',
           'street', 'area', 'address', 'note', 'items', 'lines_json', 'free_mini', 'ref', 'test'];
  $o += ['created_at' => (new DateTime('now', new DateTimeZone('Asia/Dubai')))->format('Y-m-d H:i:s'), 'lines_json' => isset($o['lines']) ? json_encode($o['lines'], JSON_UNESCAPED_UNICODE) : null,
         'subtotal' => null, 'discount' => null, 'fee' => null, 'items' => null, 'free_mini' => null, 'ref' => null, 'test' => 0, 'total' => 0];
  $o['cost'] = isset($o['lines']) ? fomaxo_cost_of($o['lines'], fomaxo_cost_map($pdo)) : null;   // cost of goods at today's cost prices (kept, so later price changes don't rewrite old months)
  if (!empty($o['coupon'])) $cols[] = 'coupon';   // the coupon code the order used (only sent when there is one)
  if (!empty($o['wa_optin'])) { $cols[] = 'wa_optin'; $o['wa_optin'] = 1; }   // ticked "Send me offers and updates on WhatsApp"
  $vals = []; foreach ($cols as $c) $vals[] = is_bool($o[$c] ?? null) ? (int)$o[$c] : ($o[$c] ?? '');
  foreach (['subtotal', 'discount', 'fee', 'cost', 'items', 'lines_json', 'free_mini', 'ref'] as $c) if ($o[$c] === null) $vals[array_search($c, $cols)] = null;
  if (!empty($o['temp'])) {   // a card payment not made yet: only a CARD-… reference; the FMX order number comes when it is paid (fomaxo_order_number)
    $ins = $pdo->prepare('INSERT INTO fx_orders (order_no, ' . implode(', ', $cols) . ') VALUES (?' . str_repeat(', ?', count($cols)) . ')');
    for ($try = 0; $try < 5; $try++) {
      $no = 'CARD-' . strtoupper(bin2hex(random_bytes(4)));
      try { $ins->execute(array_merge([$no], $vals)); return $no; }
      catch (Throwable $e) { error_log('FOMAXO save card attempt: ' . $e->getMessage()); if (!($e instanceof PDOException) || (int)($e->errorInfo[1] ?? 0) !== 1062) return null; }
    }
    return null;
  }
  /* the number is given in the same step that saves the order (the highest FMX number + 1), so a save that fails never uses up a number */
  $ins = $pdo->prepare('INSERT INTO fx_orders (order_no, ' . implode(', ', $cols) . ") SELECT CONCAT('FMX-', GREATEST(1000, COALESCE(MAX(CAST(SUBSTRING(order_no, 5) AS UNSIGNED)), 0)) + 1)"
                       . str_repeat(', ?', count($cols)) . " FROM fx_orders WHERE order_no REGEXP '^FMX-[0-9]+$'");
  for ($try = 0; $try < 5; $try++) {   // two orders at the same moment: the second one takes the next number
    try {
      $ins->execute($vals);
      $s = $pdo->prepare('SELECT order_no FROM fx_orders WHERE id = ?'); $s->execute([(int)$pdo->lastInsertId()]);
      return (string)$s->fetchColumn() ?: null;
    } catch (Throwable $e) {
      error_log('FOMAXO save order: ' . $e->getMessage());
      if (!($e instanceof PDOException) || (int)($e->errorInfo[1] ?? 0) !== 1062) return null;
    }
  }
  return null;
}

/* Orders written to the order files (one level above the website) that are not in the order list: kept when the database could not take them.
   Only from the day the order list started, newest first. */
function fomaxo_log_missing($pdo) {
  $dir = dirname(__DIR__) . '/fomaxo-orders'; $out = [];
  $since = (string)$pdo->query('SELECT MIN(created_at) FROM fx_orders')->fetchColumn();
  $read = function ($file, $map) use (&$out) {
    if (!is_file($file) || !($fh = @fopen($file, 'r'))) return;
    if (fread($fh, 3) !== "\xEF\xBB\xBF") rewind($fh);   // the Excel mark at the start of the file
    while (($r = fgetcsv($fh)) !== false) {
      $no = trim($r[1] ?? '');
      if (!preg_match('/^(FMX|FX|CARD)[-0-9A-Z]+$/', $no) || !strtotime($r[0] ?? '')) continue;   // the heading row and broken lines
      $x = $map($r); $x['no'] = $no; $x['date'] = date('Y-m-d H:i:s', strtotime($r[0]));
      $out[$no] = isset($out[$no]) ? array_merge($out[$no], array_filter($x, fn($v) => $v !== '')) : $x;   // a card order's later "PAID" line wins
    }
    fclose($fh);
  };
  $num = fn($v) => (float)preg_replace('/[^0-9.]/', '', (string)$v);
  $read("$dir/cash-on-delivery-orders.csv", fn($r) => ['payment' => 'Cash on delivery', 'status' => 'New', 'name' => $r[2] ?? '', 'phone' => $r[3] ?? '', 'email' => $r[4] ?? '',
        'emirate' => $r[5] ?? '', 'address' => $r[6] ?? '', 'note' => $r[7] ?? '', 'items' => $r[8] ?? '', 'subtotal' => $num($r[9] ?? ''), 'discount' => $num($r[10] ?? ''),
        'fee' => $num($r[11] ?? ''), 'total' => $num($r[12] ?? ''), 'building' => $r[13] ?? '', 'room' => $r[14] ?? '', 'street' => $r[15] ?? '', 'area' => $r[16] ?? '']);
  $read("$dir/orders.csv", fn($r) => ['payment' => str_starts_with((string)($r[2] ?? ''), 'Ziina') ? 'Card (Ziina)' : 'Cash on delivery',
        'status' => str_contains((string)($r[2] ?? ''), 'PAID') ? 'Paid' : (str_contains((string)($r[2] ?? ''), 'awaiting') ? 'Awaiting payment' : 'New'),
        'test' => str_contains((string)($r[2] ?? ''), 'TEST') ? 1 : 0, 'total' => $num($r[3] ?? ''), 'name' => $r[4] ?? '', 'phone' => $r[5] ?? '', 'email' => $r[6] ?? '',
        'emirate' => $r[7] ?? '', 'address' => $r[8] ?? '', 'note' => $r[9] ?? '', 'items' => $r[10] ?? '']);
  if (!$out) return [];
  $have = [];
  foreach (array_chunk(array_keys($out), 500) as $ch) {
    $s = $pdo->prepare('SELECT order_no FROM fx_orders WHERE order_no IN (' . implode(',', array_fill(0, count($ch), '?')) . ')'); $s->execute($ch);
    foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $n) $have[$n] = 1;
  }
  $out = array_filter($out, fn($x) => !isset($have[$x['no']]) && $x['status'] !== 'Awaiting payment' && ($since === '' || $x['date'] >= $since));   // a card payment not made is not an order
  uasort($out, fn($a, $b) => strcmp($b['date'], $a['date']));
  return array_values($out);
}

/* Puts one order from the order files into the order list with its own number (nothing else changes: stock is not moved). */
function fomaxo_log_add($pdo, $no) {
  foreach (fomaxo_log_missing($pdo) as $x) {
    if ($x['no'] !== $no) continue;
    $x += ['subtotal' => null, 'discount' => null, 'fee' => null, 'building' => '', 'room' => '', 'street' => '', 'area' => '', 'test' => 0];
    $cols = ['order_no', 'created_at', 'payment', 'status', 'subtotal', 'discount', 'fee', 'total', 'name', 'phone', 'email', 'emirate', 'building', 'room', 'street', 'area', 'address', 'note', 'items', 'test', 'source', 'admin_note'];
    $vals = [$no, $x['date'], $x['payment'], $x['status'], $x['subtotal'], $x['discount'], $x['fee'], $x['total'], mb_substr($x['name'], 0, 80), mb_substr($x['phone'], 0, 25), mb_substr($x['email'], 0, 120),
             mb_substr($x['emirate'], 0, 30), mb_substr($x['building'], 0, 40), mb_substr($x['room'], 0, 20), mb_substr($x['street'], 0, 100), mb_substr($x['area'], 0, 80), mb_substr($x['address'], 0, 300),
             mb_substr($x['note'], 0, 300), $x['items'], (int)$x['test'], 'file', 'Added from the order file.'];
    if ($x['status'] === 'Paid') { $cols[] = 'paid_at'; $vals[] = $x['date']; }
    try { $pdo->prepare('INSERT INTO fx_orders (' . implode(', ', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute($vals); return true; }
    catch (Throwable $e) { error_log('FOMAXO add logged order: ' . $e->getMessage()); return false; }
  }
  return false;
}

/* A card order is only an order once it is paid: this gives its CARD-… attempt the next FMX number and returns that number
   (any other number comes back unchanged; on a database problem the CARD-… reference stays). */
function fomaxo_order_number($no) {
  if (!str_starts_with((string)$no, 'CARD-')) return $no;
  $pdo = fomaxo_db(); if (!$pdo) return $no;
  $s = $pdo->prepare('SELECT id FROM fx_orders WHERE order_no = ?'); $s->execute([$no]); $id = (int)$s->fetchColumn(); if (!$id) return $no;
  $up = $pdo->prepare("UPDATE fx_orders SET order_no = (SELECT n FROM (SELECT CONCAT('FMX-', GREATEST(1000, COALESCE(MAX(CAST(SUBSTRING(order_no, 5) AS UNSIGNED)), 0)) + 1) n
                       FROM fx_orders WHERE order_no REGEXP '^FMX-[0-9]+$') t) WHERE id = ?");
  for ($try = 0; $try < 5; $try++) {
    try { $up->execute([$id]); $s = $pdo->prepare('SELECT order_no FROM fx_orders WHERE id = ?'); $s->execute([$id]); return (string)$s->fetchColumn() ?: $no; }
    catch (Throwable $e) { error_log('FOMAXO order number: ' . $e->getMessage()); if (!($e instanceof PDOException) || (int)($e->errorInfo[1] ?? 0) !== 1062) return $no; }
  }
  return $no;
}

/* Changes a few fields of an order (ref, status …). */
function fomaxo_order_set($no, $fields) {
  $pdo = fomaxo_db(); if (!$pdo || !$fields) return false;
  $ok = ['ref', 'status', 'admin_note'];
  $fields = array_intersect_key($fields, array_flip($ok));
  try {
    $pdo->prepare('UPDATE fx_orders SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($fields))) . ', updated_at = NOW() WHERE order_no = ?')
        ->execute(array_merge(array_values($fields), [$no]));
    return true;
  } catch (Throwable $e) { error_log('FOMAXO order update: ' . $e->getMessage()); return false; }
}

/* Marks a card order paid (once). */
function fomaxo_order_paid($no, $note = '') {
  $pdo = fomaxo_db(); if (!$pdo) return false;
  try {
    $pdo->prepare("UPDATE fx_orders SET status = 'Paid', paid_at = NOW(), updated_at = NOW(),
                   admin_note = TRIM(CONCAT(COALESCE(admin_note, ''), ' ', ?)) WHERE order_no = ? AND status = 'Awaiting payment'")->execute([$note, $no]);
    return true;
  } catch (Throwable $e) { error_log('FOMAXO order paid: ' . $e->getMessage()); return false; }
}

/* ================= coupon codes (made on fomaxo.com/admin → Coupons) =================
   A coupon never adds to the multi-buy discount: the customer gets whichever saving is bigger (the free 10ml mini still applies).
   Minimum order on a coupon counts the bag before any discount. */
function fomaxo_coupon_norm($code) { return substr(preg_replace('/[^A-Z0-9_\-]/', '', strtoupper(trim((string)$code))), 0, 30); }
/* orders that count as a use: placed and not cancelled, refunded, unpaid card attempts or test payments */
function fomaxo_coupon_uses($pdo, $code) {
  $s = $pdo->prepare("SELECT COUNT(*) FROM fx_orders WHERE coupon = ? AND test = 0 AND status NOT IN ('Awaiting payment', 'Cancelled', 'Refunded')");
  $s->execute([$code]); return (int)$s->fetchColumn();
}
function fomaxo_coupon_label($c) {
  $a = (float)$c['amount']; $n = rtrim(rtrim(number_format($a, 2, '.', ''), '0'), '.');
  return $c['kind'] === 'aed' ? "AED $n off" : "$n% off";
}
/* Finds a code that can be used now. Returns ['error' => …] or ['code', 'kind', 'amount', 'min', 'label'] */
function fomaxo_coupon_find($code, $phone = null) {
  $code = fomaxo_coupon_norm($code);
  if ($code === '') return ['error' => 'Please type a coupon code.'];
  $pdo = fomaxo_db(); if (!$pdo) return ['error' => 'Coupons are not available right now. Please try again later.'];
  try { $s = $pdo->prepare('SELECT * FROM fx_coupons WHERE code = ?'); $s->execute([$code]); $c = $s->fetch(); }
  catch (Throwable $e) { error_log('FOMAXO coupon: ' . $e->getMessage()); return ['error' => 'Coupons are not available right now. Please try again later.']; }
  if (!$c || !(int)$c['active'] || (float)$c['amount'] <= 0) return ['error' => 'This coupon code is not valid.'];
  $now = (new DateTime('now', new DateTimeZone('Asia/Dubai')))->format('Y-m-d H:i:s');
  $ends = $c['ends'] ?? ($c['expires'] ? $c['expires'] . ' 23:59:59' : null);
  if (!empty($c['starts']) && $c['starts'] > $now) return ['error' => 'This coupon code starts on ' . date('d/m/Y \a\t g:i a', strtotime($c['starts'])) . '.'];
  if ($ends && $ends < $now) return ['error' => 'This coupon code has expired.'];
  if ($c['max_uses'] !== null && fomaxo_coupon_uses($pdo, $code) >= (int)$c['max_uses']) return ['error' => 'This coupon code has been fully used.'];
  if (!empty($c['phone']) && $phone !== null && fomaxo_phone9($phone) !== fomaxo_phone9($c['phone'])) return ['error' => 'This coupon code is for another mobile number.'];
  return ['code' => $c['code'], 'kind' => $c['kind'] === 'aed' ? 'aed' : 'pct', 'amount' => (float)$c['amount'], 'min' => $c['min_order'] !== null ? (float)$c['min_order'] : 0,
          'label' => fomaxo_coupon_label($c), 'ends' => $ends ? str_replace(' ', 'T', $ends) . '+04:00' : null, 'stack' => !empty($c['stack'])];
}
/* What the code saves on a bag of $subFils (fils, before any discount). Returns ['error' => …] or the coupon plus 'saveFils'.
   A "Use both" coupon ('stack') is worked out on the bag after the multi-buy discount ($multiFils); the minimum still counts the bag before any discount. */
function fomaxo_coupon_apply($code, $subFils, $multiFils = 0, $phone = '') {
  $c = fomaxo_coupon_find($code, (string)$phone); if (isset($c['error'])) return $c;
  if ($c['min'] > 0 && $subFils < (int)round($c['min'] * 100)) return ['error' => 'This coupon code is for orders of AED ' . rtrim(rtrim(number_format($c['min'], 2, '.', ''), '0'), '.') . ' or more.'];
  $base = $c['stack'] ? $subFils - $multiFils : $subFils;
  $c['saveFils'] = $c['kind'] === 'aed' ? min($base, (int)round($c['amount'] * 100)) : (int)round($base * min(100, $c['amount']) / 100);
  return $c;
}

/* ================= stock ================= */
const FX_LOW_STOCK = 5;   // default for the "Only X left" level; can be changed on fomaxo.com/admin → Settings
/* "Only X left" shows on the website from this number down (Settings → Low stock warning). */
function fomaxo_low_stock() {
  static $n = null; if ($n !== null) return $n;
  $v = null; if ($pdo = fomaxo_db()) { try { $v = fomaxo_setting($pdo, 'low_stock'); } catch (Throwable $e) {} }
  return $n = ($v !== null && ctype_digit((string)$v) ? min(100, (int)$v) : FX_LOW_STOCK);
}
/* Where store emails go (Settings → Store emails): new orders, new reviews and the admin password reset link. Falls back to the store inbox. */
const FX_STORE_EMAIL = 'fomaxoasset@gmail.com';
function fomaxo_orders_email($fallback = FX_STORE_EMAIL) {
  $v = null; if ($pdo = fomaxo_db()) { try { $v = fomaxo_setting($pdo, 'orders_email'); } catch (Throwable $e) {} }
  return $v !== null && filter_var($v, FILTER_VALIDATE_EMAIL) ? $v : $fallback;
}

/* What an order takes from stock: [['product', 'size', qty], …]. The free mini counts as one 10ml. */
function fomaxo_stock_lines($lines, $giftId = null) {
  $out = [];
  foreach ((array)$lines as $l) {
    $k = ($l['id'] ?? '') . '|' . ($l['opt'] ?? '');
    $out[$k] = ($out[$k] ?? 0) + max(0, (int)($l['qty'] ?? 0));
  }
  if ($giftId) $out["$giftId|10"] = ($out["$giftId|10"] ?? 0) + 1;
  return $out;
}
function fomaxo_stock_map($pdo) {
  $m = [];
  foreach ($pdo->query('SELECT product, size, qty FROM fx_stock WHERE qty IS NOT NULL') as $r) $m[$r['product'] . '|' . $r['size']] = (int)$r['qty'];
  return $m;
}
/* Free 10ml mini: false when its 10ml is counted in admin → Stock and none are left (then a different mini is given). */
function fomaxo_mini_ok($id) {
  static $m = null;
  if ($m === null) { $m = []; $pdo = fomaxo_db(); if ($pdo) { try { $m = fomaxo_stock_map($pdo); } catch (Throwable $e) {} } }
  return !isset($m["$id|10"]) || $m["$id|10"] > 0;
}
/* Before an order: returns a message if something in the bag is sold out or there are not enough left, else null.
   The free mini never blocks an order. Works without the database (then nothing is counted). */
function fomaxo_stock_problem($lines, $catalog) {
  $pdo = fomaxo_db(); if (!$pdo) return null;
  try { $have = fomaxo_stock_map($pdo); } catch (Throwable $e) { return null; }
  foreach (fomaxo_stock_lines($lines) as $k => $need) {
    if (!isset($have[$k]) || $have[$k] >= $need) continue;
    [$id, $opt] = explode('|', $k, 2);
    $p = $catalog[$id] ?? ['name' => $id, 'kind' => ''];
    $label = $p['name'] . ' ' . ($p['kind'] === 'set' ? "(set of $opt)" : "{$opt}ml");
    return $have[$k] <= 0 ? "Sorry, $label is out of stock (restocking soon). Please remove it from your bag."
                          : "Sorry, only {$have[$k]} left of $label. Please lower the quantity in your bag.";
  }
  return null;
}
/* Takes an order's items out of stock (once), or puts them back ($back = true) when it is cancelled. */
function fomaxo_stock_move($no, $back = false) {
  $pdo = fomaxo_db(); if (!$pdo) return false;
  try {
    $pdo->beginTransaction();
    $s = $pdo->prepare('SELECT lines_json, stock_taken FROM fx_orders WHERE order_no = ? FOR UPDATE'); $s->execute([$no]); $o = $s->fetch();
    if (!$o || $o['lines_json'] === null || (bool)$o['stock_taken'] !== $back) { $pdo->commit(); return false; }
    $u = $pdo->prepare($back ? 'UPDATE fx_stock SET qty = qty + ?, updated_at = NOW() WHERE product = ? AND size = ? AND qty IS NOT NULL'
                             : 'UPDATE fx_stock SET qty = GREATEST(0, qty - ?), updated_at = NOW() WHERE product = ? AND size = ? AND qty IS NOT NULL');
    foreach (fomaxo_stock_lines(json_decode($o['lines_json'], true)) as $k => $n) { [$id, $opt] = explode('|', $k, 2); $u->execute([$n, $id, $opt]); }
    $pdo->prepare('UPDATE fx_orders SET stock_taken = ? WHERE order_no = ?')->execute([$back ? 0 : 1, $no]);
    $pdo->commit(); return true;
  } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); error_log('FOMAXO stock: ' . $e->getMessage()); return false; }
}

/* ================= cost prices and reports ================= */
function fomaxo_cost_map($pdo) {
  $m = [];
  try { foreach ($pdo->query('SELECT product, size, cost FROM fx_stock WHERE cost IS NOT NULL') as $r) $m[$r['product'] . '|' . $r['size']] = (float)$r['cost']; } catch (Throwable $e) {}
  return $m;
}
/* Cost of an order's bottles (free mini included). Null when a product in it has no cost price yet. */
function fomaxo_cost_of($lines, $costs) {
  $t = 0.0;
  foreach (fomaxo_stock_lines($lines) as $k => $n) { if (!isset($costs[$k])) return null; $t += $costs[$k] * $n; }
  return round($t, 2);
}
const FX_EXPENSE_TYPES = ['Ads & marketing', 'Delivery', 'Packaging', 'Rent', 'Salaries', 'Card & bank fees', 'Stock purchase', 'Other'];

/* Monthly report for one year (or every year when $year is null): rows keyed 'YYYY-MM' (or 'YYYY').
   Sales = what customers paid (VAT included, COD fee included), counting New, Paid and Delivered orders, no test payments.
   Cost of goods = cost price of the bottles sold (orders saved before a cost price was typed in use today's cost price).
   Profit = sales − cost of goods − expenses. "Stock purchase" expenses are shown but not taken off profit,
   because those bottles are counted as cost of goods when they sell. */
function fomaxo_report($pdo, $year = null) {
  $key = fn($d) => $year === null ? substr($d, 0, 4) : substr($d, 0, 7);
  $rows = [];
  $blank = ['orders' => 0, 'sales' => 0.0, 'discount' => 0.0, 'fees' => 0.0, 'cogs' => 0.0, 'no_cost' => 0, 'expenses' => 0.0, 'stock_bought' => 0.0];
  if ($year !== null) for ($m = 1; $m <= 12; $m++) $rows[sprintf('%04d-%02d', $year, $m)] = $blank;
  $costs = fomaxo_cost_map($pdo);
  $w = $year === null ? '' : ' AND created_at >= ? AND created_at < ?';
  $s = $pdo->prepare("SELECT created_at, total, discount, fee, cost, lines_json FROM fx_orders WHERE status IN ('New', 'Paid', 'Delivered') AND test = 0$w");
  $s->execute($year === null ? [] : ["$year-01-01", ($year + 1) . '-01-01']);
  foreach ($s as $o) {
    $k = $key($o['created_at']); $rows[$k] ??= $blank; $r = &$rows[$k];
    $r['orders']++; $r['sales'] += (float)$o['total']; $r['discount'] += (float)$o['discount']; $r['fees'] += (float)$o['fee'];
    $c = $o['cost'] !== null ? (float)$o['cost'] : ($o['lines_json'] ? fomaxo_cost_of(json_decode($o['lines_json'], true) ?: [], $costs) : null);
    if ($c === null) $r['no_cost']++; else $r['cogs'] += $c;
    unset($r);
  }
  $w = $year === null ? '' : ' WHERE day >= ? AND day < ?';
  $s = $pdo->prepare("SELECT day, category, amount FROM fx_expenses$w");
  $s->execute($year === null ? [] : ["$year-01-01", ($year + 1) . '-01-01']);
  foreach ($s as $e) {
    $k = $key($e['day']); $rows[$k] ??= $blank;
    if ($e['category'] === 'Stock purchase') $rows[$k]['stock_bought'] += (float)$e['amount']; else $rows[$k]['expenses'] += (float)$e['amount'];
  }
  ksort($rows);
  foreach ($rows as &$r) $r['profit'] = round($r['sales'] - $r['cogs'] - $r['expenses'], 2);
  unset($r);
  return $rows;
}

/* the same report for any dates: one row for every day of the period (or every month when $byMonth) */
function fomaxo_report_span($pdo, $d1, $d2, $byMonth = false) {
  $len = $byMonth ? 7 : 10; $rows = [];
  $blank = ['orders' => 0, 'sales' => 0.0, 'discount' => 0.0, 'fees' => 0.0, 'cogs' => 0.0, 'no_cost' => 0, 'expenses' => 0.0, 'stock_bought' => 0.0];
  for ($t = strtotime($d1); $t <= strtotime($d2); $t = strtotime($byMonth ? 'first day of next month' : '+1 day', $t)) $rows[substr(date('Y-m-d', $t), 0, $len)] = $blank;
  $costs = fomaxo_cost_map($pdo);
  $s = $pdo->prepare("SELECT created_at, total, discount, fee, cost, lines_json FROM fx_orders WHERE status IN ('New', 'Paid', 'Delivered') AND test = 0 AND created_at BETWEEN ? AND ?");
  $s->execute(["$d1 00:00:00", "$d2 23:59:59"]);
  foreach ($s as $o) {
    $k = substr($o['created_at'], 0, $len); $rows[$k] ??= $blank; $r = &$rows[$k];
    $r['orders']++; $r['sales'] += (float)$o['total']; $r['discount'] += (float)$o['discount']; $r['fees'] += (float)$o['fee'];
    $c = $o['cost'] !== null ? (float)$o['cost'] : ($o['lines_json'] ? fomaxo_cost_of(json_decode($o['lines_json'], true) ?: [], $costs) : null);
    if ($c === null) $r['no_cost']++; else $r['cogs'] += $c;
    unset($r);
  }
  $s = $pdo->prepare('SELECT day, category, amount FROM fx_expenses WHERE day BETWEEN ? AND ?'); $s->execute([$d1, $d2]);
  foreach ($s as $e) {
    $k = substr($e['day'], 0, $len); $rows[$k] ??= $blank;
    if ($e['category'] === 'Stock purchase') $rows[$k]['stock_bought'] += (float)$e['amount']; else $rows[$k]['expenses'] += (float)$e['amount'];
  }
  ksort($rows);
  foreach ($rows as &$r) $r['profit'] = round($r['sales'] - $r['cogs'] - $r['expenses'], 2);
  unset($r);
  return $rows;
}

/* ---- store emails ----
   Sent through the mail@fomaxo.com mailbox (Hostinger SMTP, signed for fomaxo.com) once its password is saved in
   fomaxo.com/admin → Settings, so they do not land in spam. The password sits in fomaxo-mail-config.php ONE LEVEL ABOVE
   public_html (never on GitHub, never shown again). Without it, or if Hostinger cannot be reached, PHP mail() is used as before. */
const FX_MAIL_FROM = 'mail@fomaxo.com';
function fomaxo_mail_config_file() { return dirname(__DIR__) . '/fomaxo-mail-config.php'; }
function fomaxo_mail_config() {
  $f = fomaxo_mail_config_file();
  $c = is_file($f) ? require $f : null;
  return is_array($c) && ($c['pass'] ?? '') !== '' ? $c + ['server' => 'ssl://smtp.hostinger.com:465'] : null;
}
function fomaxo_mail_save_password($pass) {
  $f = fomaxo_mail_config_file();
  if ($pass === '') return @unlink($f) || !is_file($f);
  $ok = @file_put_contents($f, "<?php\n// mail@fomaxo.com password for store emails (written by fomaxo.com/admin → Settings)\nreturn " . var_export(['pass' => $pass], true) . ";\n", LOCK_EX) !== false;
  if ($ok) @chmod($f, 0600);
  return $ok;
}
/* $subject may be plain text or already =?UTF-8?B?…?= encoded; $headers is the usual "From: …\r\nReply-To: …" block. Returns true when sent. */
/* answer the shopper right away, then keep working (emails, Arabic → English) after the page has its reply.
   Hostinger runs PHP on LiteSpeed (litespeed_finish_request); elsewhere fastcgi_finish_request; with neither, the reply simply goes out at the end as before. */
function fomaxo_reply_now(array $answer) {
  echo json_encode($answer);
  ignore_user_abort(true);
  if (function_exists('litespeed_finish_request')) litespeed_finish_request();
  elseif (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
}
function fomaxo_mail($to, $subject, $body, $headers, &$err = null) {
  if (!preg_match('/^=\?UTF-8\?B\?/i', $subject) && preg_match('/[^\x20-\x7e]/', $subject)) $subject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
  $err = null;
  if ($c = fomaxo_mail_config()) {
    $err = fomaxo_smtp_send($c, $to, $subject, $body, $headers);
    if ($err === null) return true;
    error_log("FOMAXO email by SMTP failed ($err), trying PHP mail()");
  }
  return @mail($to, $subject, $body, $headers, '-f' . FX_MAIL_FROM);
}
/* Returns null when sent, else a short reason (never the password). */
function fomaxo_smtp_send($c, $to, $subject, $body, $headers) {
  $to = str_replace(["\r", "\n", '<', '>'], '', $to);
  $s = @stream_socket_client($c['server'], $no, $msg, 15);
  if (!$s) return "could not reach the mail server ($msg)";
  stream_set_timeout($s, 15);
  $read = function () use ($s) { $all = ''; while (($l = fgets($s, 1024)) !== false) { $all .= $l; if (strlen($l) < 4 || $l[3] !== '-') break; } return $all; };
  $cmd = function ($line, $want) use ($s, $read) { if ($line !== null) fwrite($s, $line . "\r\n"); $r = $read(); return (int)substr($r, 0, 3) === $want ? null : (trim(preg_replace('/\s+/', ' ', $r)) ?: 'no answer'); };
  $hdr = preg_replace("/\r?\n/", "\r\n", trim($headers));
  $data = "Date: " . date('r') . "\r\nTo: <$to>\r\nSubject: $subject\r\nMessage-ID: <" . bin2hex(random_bytes(12)) . '@fomaxo.com>' . "\r\nMIME-Version: 1.0\r\n"
        . $hdr . "\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode(preg_replace("/\r?\n/", "\r\n", $body)), 76, "\r\n");
  $steps = [[null, 220], ['EHLO fomaxo.com', 250], ['AUTH LOGIN', 334], [base64_encode(FX_MAIL_FROM), 334], [base64_encode($c['pass']), 235],
            ['MAIL FROM:<' . FX_MAIL_FROM . '>', 250], ["RCPT TO:<$to>", 250], ['DATA', 354], [$data . '.', 250]];
  foreach ($steps as $i => [$line, $want]) {
    if (($e = $cmd($line, $want)) !== null) { fclose($s); return ($i === 4 ? 'the mailbox password was not accepted: ' : '') . substr($e, 0, 160); }
  }
  $cmd('QUIT', 221); fclose($s);
  return null;
}

/* ---- Arabic → English for FOMAXO (admin, order emails, Excel). What the customer typed is never changed: the English is shown next to it.
   Free MyMemory translation (no key, about 5,000 characters a day — plenty for orders and reviews); every result is kept in fx_tr so each text is translated once.
   If the service can't be reached, the Arabic shows as typed (with Arabic digits turned into 0-9) and is tried again next time. */
function fx_has_ar($s) { return (bool)preg_match('/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', (string)$s); }
function fomaxo_en_many(array $texts) {
  static $memo = [], $budget = 12;   // at most 12 new look-ups per page, so a page never hangs on the service
  $out = []; $need = [];
  foreach ($texts as $s) {
    $s = (string)$s; if (isset($memo[$s])) { $out[$s] = $memo[$s]; continue; }
    $d = strtr($s, FX_AR_DIGITS);
    if (!fx_has_ar($d)) { $out[$s] = $memo[$s] = $d; continue; }
    $out[$s] = $d; $need[sha1($s)] = $s;
  }
  if (!$need) return $out;
  $pdo = fomaxo_db();
  if ($pdo) {
    try {
      static $made = false;
      if (!$made) { $pdo->exec("CREATE TABLE IF NOT EXISTS fx_tr (h CHAR(40) NOT NULL PRIMARY KEY, en TEXT NOT NULL, created_at DATETIME NULL) DEFAULT CHARSET=utf8mb4"); $made = true; }
      $q = $pdo->prepare('SELECT h, en FROM fx_tr WHERE h IN (' . implode(',', array_fill(0, count($need), '?')) . ')'); $q->execute(array_keys($need));
      foreach ($q->fetchAll(PDO::FETCH_KEY_PAIR) as $h => $en) { $s = $need[$h]; $out[$s] = $memo[$s] = $en; unset($need[$h]); }
    } catch (Throwable $e) { error_log('FOMAXO translate cache: ' . $e->getMessage()); }
  }
  if (!$need || $budget <= 0 || !function_exists('curl_multi_init')) return $out;
  $need = array_slice($need, 0, $budget, true); $budget -= count($need);
  /* the free service takes about 450 bytes at a time: long reviews go in sentence-sized pieces */
  $parts = [];
  foreach ($need as $h => $s) {
    $buf = ''; $i = 0;
    foreach (preg_split('/(?<=[.!?؟،,\n])\s*/u', strtr($s, FX_AR_DIGITS), -1, PREG_SPLIT_NO_EMPTY) as $p) {
      while (strlen($p) > 450) { $cut = mb_strcut($p, 0, 450); if ($buf !== '') { $parts[$h][$i++] = $buf; $buf = ''; } $parts[$h][$i++] = $cut; $p = substr($p, strlen($cut)); }
      if ($buf !== '' && strlen($buf) + strlen($p) + 1 > 450) { $parts[$h][$i++] = $buf; $buf = ''; }
      $buf .= ($buf === '' ? '' : ' ') . $p;
    }
    if ($buf !== '') $parts[$h][$i++] = $buf;
  }
  $mh = curl_multi_init(); $hs = [];
  foreach ($parts as $h => $list) foreach ($list as $i => $p) {
    $c = curl_init('https://api.mymemory.translated.net/get?' . http_build_query(['q' => $p, 'langpair' => 'ar|en']));
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 3]);
    curl_multi_add_handle($mh, $c); $hs[] = [$h, $i, $c];
  }
  do { $st = curl_multi_exec($mh, $run); if ($run) curl_multi_select($mh, 1); } while ($run && $st === CURLM_OK);
  $got = []; $bad = [];
  foreach ($hs as [$h, $i, $c]) {
    $j = json_decode((string)curl_multi_getcontent($c), true); curl_multi_remove_handle($mh, $c); curl_close($c);
    if (!is_array($j)) $budget = 0;   // service not reachable: don't try again on this page
    $t = trim(html_entity_decode((string)($j['responseData']['translatedText'] ?? ''), ENT_QUOTES, 'UTF-8'));
    if ((int)($j['responseStatus'] ?? 0) !== 200 || $t === '' || stripos($t, 'MYMEMORY WARNING') !== false || fx_has_ar($t) && mb_strlen(preg_replace('/[^\x{0600}-\x{06FF}]/u', '', $t)) > mb_strlen($t) / 2) $bad[$h] = true;
    else $got[$h][$i] = $t;
  }
  curl_multi_close($mh);
  foreach ($need as $h => $s) {
    if (!empty($bad[$h]) || count($got[$h] ?? []) !== count($parts[$h])) continue;
    ksort($got[$h]); $en = implode(' ', $got[$h]);
    $out[$s] = $memo[$s] = $en;
    if ($pdo) { try { $pdo->prepare('REPLACE INTO fx_tr (h, en, created_at) VALUES (?, ?, NOW())')->execute([$h, $en]); } catch (Throwable $e) {} }
  }
  return $out;
}
function fomaxo_en($s) { $s = (string)$s; return $s === '' ? '' : fomaxo_en_many([$s])[$s]; }
/* English → Arabic for the shop's reply to an Arabic review (written in English in admin). Same free service; null when it can't be reached */
function fomaxo_ar($s) {
  $s = trim((string)$s); if ($s === '' || !function_exists('curl_init')) return null;
  $out = [];
  foreach (preg_split('/(?<=[.!?\n])\s*/u', $s, -1, PREG_SPLIT_NO_EMPTY) as $p) {
    while (strlen($p) > 450) { $cut = mb_strcut($p, 0, 450); $out[] = $cut; $p = substr($p, strlen($cut)); }
    $out[] = $p;
  }
  foreach ($out as &$p) {
    $c = curl_init('https://api.mymemory.translated.net/get?' . http_build_query(['q' => $p, 'langpair' => 'en|ar']));
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 3]);
    $j = json_decode((string)curl_exec($c), true); curl_close($c);
    $t = trim(html_entity_decode((string)($j['responseData']['translatedText'] ?? ''), ENT_QUOTES, 'UTF-8'));
    if ((int)($j['responseStatus'] ?? 0) !== 200 || $t === '' || stripos($t, 'MYMEMORY WARNING') !== false || !fx_has_ar($t)) return null;
    $p = $t;
  }
  return implode(' ', $out);
}
/* for emails and Excel: "English (Arabic as typed)" when the customer typed Arabic, else the text as it is */
function fomaxo_en_both($s) { $s = (string)$s; if (!fx_has_ar($s)) return strtr($s, FX_AR_DIGITS); $en = fomaxo_en($s); return $en === strtr($s, FX_AR_DIGITS) ? $s : "$en ($s)"; }
