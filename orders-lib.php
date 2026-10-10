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
  if ($ver >= 26) return;
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
  /* v19: the new 10ml bottle photos are the main 10ml photo of four perfumes (only where the old 10ml photo is still first) */
  if ($ver < 19) foreach (['oldmoney', 'royalcandy', 'matchacoco', 'passionsin'] as $id) {
    $get->execute([$id]); $d = json_decode((string)$get->fetchColumn(), true);
    if (is_array($d) && ($d['miniImage'] ?? '') === "$id-10-1") { $d['miniImage'] = "$id-10-main"; $d['miniImages'] = array_values(array_unique(array_merge(["$id-10-main"], (array)($d['miniImages'] ?? [])))); $set->execute([fomaxo_json($d), $id]); }
  }
  $pdo->exec("REPLACE INTO fx_settings (k, v) VALUES ('schema', '19')");
  /* v20: the bag as a short code (id.size.qty_…) so the Left at checkout WhatsApp message can link straight back to checkout with that bag */
  try { $pdo->exec("ALTER TABLE fx_leads ADD COLUMN bag VARCHAR(300) NOT NULL DEFAULT ''"); } catch (Throwable $e) {}
  if (!$pdo->query("SHOW COLUMNS FROM fx_leads LIKE 'bag'")->fetch()) return;
  $pdo->exec("REPLACE INTO fx_settings (k, v) VALUES ('schema', '20')");
  /* v21: when the owner first opened each order in admin (the red count on the Orders tab = real orders not opened yet); orders already here count as seen */
  try { $pdo->exec("ALTER TABLE fx_orders ADD COLUMN seen_at DATETIME NULL"); } catch (Throwable $e) {}
  if (!$pdo->query("SHOW COLUMNS FROM fx_orders LIKE 'seen_at'")->fetch()) return;
  $pdo->exec("UPDATE fx_orders SET seen_at = NOW() WHERE seen_at IS NULL");
  $pdo->exec("REPLACE INTO fx_settings (k, v) VALUES ('schema', '21')");
  /* v22: Trash on admin Orders: an order closed with its ✕ is moved here whole (as it was), so it leaves every list, count and report; Put back returns it */
  $pdo->exec("CREATE TABLE IF NOT EXISTS fx_orders_trash (order_no VARCHAR(40) NOT NULL PRIMARY KEY, trashed_at DATETIME NOT NULL, created_at DATETIME NULL,
              name VARCHAR(80) NOT NULL DEFAULT '', phone VARCHAR(25) NOT NULL DEFAULT '', total DECIMAL(10,2) NOT NULL DEFAULT 0, payment VARCHAR(30) NOT NULL DEFAULT '',
              status VARCHAR(20) NOT NULL DEFAULT '', row_json MEDIUMTEXT NOT NULL, KEY (trashed_at)) DEFAULT CHARSET=utf8mb4");
  $pdo->exec("REPLACE INTO fx_settings (k, v) VALUES ('schema', '22')");
  /* v23: new 50ml (main) and 100ml bottle photos for Old Money, Royal Candy and Matcha Coco; the old ones leave the gallery; Matcha Coco gets the model photo second */
  foreach (['oldmoney' => 'oldmoney-main', 'royalcandy' => 'royalcandy-main', 'matchacoco' => 'matchacoco-main2'] as $id => $old) {
    $get->execute([$id]); $d = json_decode((string)$get->fetchColumn(), true);
    if (!is_array($d)) continue;
    $add = $id === 'matchacoco' ? ["$id-50-main", 'matchacoco-model'] : ["$id-50-main"];
    $d['images'] = array_values(array_merge($add, array_diff(array_filter((array)($d['images'] ?? []), 'is_string'), array_merge($add, [$old, "$id-100"]))));
    $d['sizeImages'] = (array)($d['sizeImages'] ?? []); $d['sizeImages']['100'] = "$id-100-main";
    $set->execute([fomaxo_json($d), $id]);
  }
  $pdo->exec("REPLACE INTO fx_settings (k, v) VALUES ('schema', '23')");
  /* v24: newer 100ml bottle photos for Old Money, Royal Candy, Matcha Coco and Passion Sin, and 50ml photos zoomed so the bottle is the same size in every photo */
  foreach (['oldmoney' => 'oldmoney-50-main', 'royalcandy' => 'royalcandy-50-main', 'matchacoco' => 'matchacoco-50-main', 'passionsin' => 'passionsin-main'] as $id => $old) {
    $get->execute([$id]); $d = json_decode((string)$get->fetchColumn(), true);
    if (!is_array($d)) continue;
    $d['images'] = array_values(array_merge(["$id-50-v2"], array_diff(array_filter((array)($d['images'] ?? []), 'is_string'), [$old, "$id-50-v2"])));
    $d['sizeImages'] = (array)($d['sizeImages'] ?? []); $d['sizeImages']['100'] = "$id-100-v2";
    $set->execute([fomaxo_json($d), $id]);
  }
  $pdo->exec("REPLACE INTO fx_settings (k, v) VALUES ('schema', '24')");
  /* v25: Passion Sin gets the model photo second, after the bottle photo */
  $get->execute(['passionsin']); $d = json_decode((string)$get->fetchColumn(), true);
  if (is_array($d)) {
    $imgs = array_values(array_diff(array_filter((array)($d['images'] ?? []), 'is_string'), ['passionsin-model']));
    array_splice($imgs, min(1, count($imgs)), 0, ['passionsin-model']); $d['images'] = $imgs;
    $set->execute([fomaxo_json($d), 'passionsin']);
  }
  $pdo->exec("REPLACE INTO fx_settings (k, v) VALUES ('schema', '25')");
  /* v26: Old Money gets the model photo second, after the bottle photo */
  $get->execute(['oldmoney']); $d = json_decode((string)$get->fetchColumn(), true);
  if (is_array($d)) {
    $imgs = array_values(array_diff(array_filter((array)($d['images'] ?? []), 'is_string'), ['oldmoney-model']));
    array_splice($imgs, min(1, count($imgs)), 0, ['oldmoney-model']); $d['images'] = $imgs;
    $set->execute([fomaxo_json($d), 'oldmoney']);
  }
  $pdo->exec("REPLACE INTO fx_settings (k, v) VALUES ('schema', '26')");
}
/* real orders the owner has not opened yet: not test, not an unpaid card attempt, not cancelled or refunded */
const FX_NEW_ORDER_SQL = "seen_at IS NULL AND test = 0 AND status NOT IN ('Awaiting payment', 'Cancelled', 'Refunded')";
function fx_new_orders($pdo) {
  try { return (int)$pdo->query('SELECT COUNT(*) FROM fx_orders WHERE ' . FX_NEW_ORDER_SQL)->fetchColumn(); } catch (Throwable $e) { return 0; }
}
function fomaxo_coupons_table($pdo) {
  $pdo->exec("CREATE TABLE IF NOT EXISTS fx_coupons (code VARCHAR(30) NOT NULL PRIMARY KEY, kind VARCHAR(3) NOT NULL DEFAULT 'pct', amount DECIMAL(10,2) NOT NULL,
              min_order DECIMAL(10,2) NULL, expires DATE NULL, max_uses INT NULL, active TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME NULL) DEFAULT CHARSET=utf8mb4");
  /* stack = 1: "Use both" (the coupon comes off after the multi-buy discount); 0: "Use the bigger offer" */
  try { if (!$pdo->query("SHOW COLUMNS FROM fx_coupons LIKE 'stack'")->fetch()) $pdo->exec("ALTER TABLE fx_coupons ADD COLUMN stack TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
  /* phone: a one-time coupon made for one customer (late delivery, faulty product) only works with that mobile number */
  try { if (!$pdo->query("SHOW COLUMNS FROM fx_coupons LIKE 'phone'")->fetch()) $pdo->exec("ALTER TABLE fx_coupons ADD COLUMN phone VARCHAR(25) NULL"); } catch (Throwable $e) {}
  /* kind 'free': a free product (free_id in size free_opt) joins the order at AED 0; per_cust = 1: each mobile number can use the code once */
  try { if (!$pdo->query("SHOW COLUMNS FROM fx_coupons LIKE 'per_cust'")->fetch()) $pdo->exec("ALTER TABLE fx_coupons MODIFY kind VARCHAR(4) NOT NULL DEFAULT 'pct',
          ADD COLUMN per_cust TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN free_id VARCHAR(40) NULL, ADD COLUMN free_opt VARCHAR(10) NULL"); } catch (Throwable $e) {}
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

/* "Customers bought together" (the product page box; admin → Products → Customers bought together): the partner shown for each product,
   and an extra % off when a product and its partner are both in the bag. Saved as fx_settings 'bt' = {"pct":N,"pairs":{product:partner}}. */
const FX_BT_PAIRS = ['oldmoney' => 'gold', 'royalcandy' => 'dollar', 'matchacoco' => 'gold', 'passionsin' => 'dollar', 'gold' => 'dollar', 'dollar' => 'gold'];
function fomaxo_bt($pdo = null) {
  $pdo = $pdo ?: fomaxo_db(); $v = null;
  if ($pdo) { try { $v = json_decode((string)fomaxo_setting($pdo, 'bt'), true); } catch (Throwable $e) {} }
  $pairs = is_array($v['pairs'] ?? null) ? array_filter($v['pairs'], fn($b, $a) => is_string($a) && is_string($b) && $b !== '' && $a !== $b, ARRAY_FILTER_USE_BOTH) : FX_BT_PAIRS;
  return ['pct' => max(0, min(50, (int)($v['pct'] ?? 0))), 'pairs' => $pairs];
}
/* The extra "bought together" saving in fils, worked out on the server (never taken from the browser). Keep in sync with btSave() in index.html.
   Going down the bag: a product whose partner is also in the bag takes pct % off one bottle of each (the cheapest size in the bag);
   each product counts in one pair only. King and the Discovery Set never count. */
function fomaxo_bt_fils($lines, $catalog, $bt) {
  if (empty($bt['pct'])) return 0;
  $unit = [];
  foreach ((array)$lines as $l) {
    $id = is_string($l['id'] ?? null) ? $l['id'] : ''; $opt = (string)($l['opt'] ?? '');
    if ($id === 'king' || $id === 'discovery' || !isset($catalog[$id]['prices'][$opt]) || (int)($l['qty'] ?? 0) < 1) continue;
    $v = (float)$catalog[$id]['prices'][$opt]; if (!isset($unit[$id]) || $v < $unit[$id]) $unit[$id] = $v;
  }
  $used = []; $fils = 0;
  foreach ($unit as $a => $va) {
    $b = $bt['pairs'][$a] ?? ''; if ($b === '' || !isset($unit[$b]) || isset($used[$a]) || isset($used[$b])) continue;
    $used[$a] = $used[$b] = true; $fils += (int)round(($va + $unit[$b]) * $bt['pct']);   // AED × % = fils
  }
  return $fils;
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
  $ins = $pdo->prepare('INSERT INTO fx_orders (order_no, ' . implode(', ', $cols) . ") SELECT CONCAT('FMX-', GREATEST(1000, COALESCE(MAX(CAST(SUBSTRING(order_no, 5) AS UNSIGNED)), 0), (SELECT COALESCE(MAX(CAST(v AS UNSIGNED)), 0) FROM fx_settings WHERE k = 'trash_max')) + 1)"
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
    try { $s = $pdo->prepare('SELECT order_no FROM fx_orders_trash WHERE order_no IN (' . implode(',', array_fill(0, count($ch), '?')) . ')'); $s->execute($ch);   // closed orders stay closed
          foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $n) $have[$n] = 1; } catch (Throwable $e) {}
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
  $up = $pdo->prepare("UPDATE fx_orders SET order_no = (SELECT n FROM (SELECT CONCAT('FMX-', GREATEST(1000, COALESCE(MAX(CAST(SUBSTRING(order_no, 5) AS UNSIGNED)), 0), (SELECT COALESCE(MAX(CAST(v AS UNSIGNED)), 0) FROM fx_settings WHERE k = 'trash_max')) + 1) n
                       FROM fx_orders WHERE order_no REGEXP '^FMX-[0-9]+$') t) WHERE id = ?");
  for ($try = 0; $try < 5; $try++) {
    try { $up->execute([$id]); $s = $pdo->prepare('SELECT order_no FROM fx_orders WHERE id = ?'); $s->execute([$id]); return (string)$s->fetchColumn() ?: $no; }
    catch (Throwable $e) { error_log('FOMAXO order number: ' . $e->getMessage()); if (!($e instanceof PDOException) || (int)($e->errorInfo[1] ?? 0) !== 1062) return $no; }
  }
  return $no;
}

/* Trash (admin Orders ✕): the order leaves fx_orders whole, so sales, counts, Members, coupons and Excel no longer see it; its stock goes back.
   The number is never given again (trash_max), and Put back returns the order exactly as it was (stock taken again if it was before). */
function fx_order_trash($pdo, $no) {
  $s = $pdo->prepare('SELECT * FROM fx_orders WHERE order_no = ?'); $s->execute([$no]); $o = $s->fetch(PDO::FETCH_ASSOC);
  if (!$o || str_starts_with($no, 'CARD-')) return false;
  $took = (int)$o['stock_taken'] === 1 && fomaxo_stock_move($no, true);
  $o['stock_taken'] = $took ? 1 : (int)$o['stock_taken'];   // as it was, for Put back
  try {
    $pdo->beginTransaction();
    $pdo->prepare('REPLACE INTO fx_orders_trash (order_no, trashed_at, created_at, name, phone, total, payment, status, row_json) VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$no, $o['created_at'], $o['name'], $o['phone'], $o['total'], $o['payment'], $o['status'], json_encode($o, JSON_UNESCAPED_UNICODE)]);
    $pdo->prepare('DELETE FROM fx_orders WHERE order_no = ?')->execute([$no]);
    if (preg_match('/^FMX-(\d+)$/', $no, $m) && (int)$m[1] > (int)fomaxo_setting($pdo, 'trash_max')) fomaxo_setting($pdo, 'trash_max', $m[1]);
    $pdo->commit(); return true;
  } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); if ($took) fomaxo_stock_move($no); error_log('FOMAXO trash: ' . $e->getMessage()); return false; }
}
function fx_order_restore($pdo, $no) {
  $s = $pdo->prepare('SELECT row_json FROM fx_orders_trash WHERE order_no = ?'); $s->execute([$no]); $o = json_decode((string)$s->fetchColumn(), true);
  if (!is_array($o)) return false;
  $cols = array_column($pdo->query('SHOW COLUMNS FROM fx_orders')->fetchAll(PDO::FETCH_ASSOC), 'Field');
  $o = array_intersect_key($o, array_flip($cols)); $took = !empty($o['stock_taken']); $o['stock_taken'] = 0;
  try {
    $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO fx_orders (' . implode(', ', array_keys($o)) . ') VALUES (' . implode(',', array_fill(0, count($o), '?')) . ')')->execute(array_values($o));
    $pdo->prepare('DELETE FROM fx_orders_trash WHERE order_no = ?')->execute([$no]);
    $pdo->commit();
  } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); error_log('FOMAXO put back: ' . $e->getMessage()); return false; }
  if ($took) fomaxo_stock_move($no);
  return true;
}
function fx_trash_count($pdo) { try { return (int)$pdo->query('SELECT COUNT(*) FROM fx_orders_trash')->fetchColumn(); } catch (Throwable $e) { return 0; } }

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
  if (($c['kind'] ?? '') === 'free') return 'Free ' . fomaxo_coupon_free_name($c);
  $a = (float)$c['amount']; $n = rtrim(rtrim(number_format($a, 2, '.', ''), '0'), '.');
  return $c['kind'] === 'aed' ? "AED $n off" : "$n% off";
}
/* a free product coupon's product and size, like "Gold 50ml" */
function fomaxo_coupon_free_name($c) {
  $p = fomaxo_free_product($c['free_id'] ?? '', $c['free_opt'] ?? '');
  return $p ? $p['label'] : (!empty($c['free_id']) ? $c['free_id'] . ' ' . $c['free_opt'] . 'ml' : 'a product');   // a product hidden since: its id
}
/* the product a free product coupon gives: ['id', 'opt', 'name', 'label' ("Gold 50ml"), 'price' (AED)], or null when it is not sold now */
function fomaxo_free_product($id, $opt) {
  global $CATALOG; static $cat = null;
  if ($cat === null) $cat = is_array($CATALOG ?? null) && $CATALOG ? $CATALOG : (fomaxo_catalog_db() ?: []);
  $id = (string)$id; $opt = (string)$opt; $p = $cat[$id] ?? null;
  if (!$p || $p['kind'] === 'set' || !isset($p['prices'][$opt])) return null;   // gift sets need fragrances picked, so they are never free
  return ['id' => $id, 'opt' => $opt, 'name' => $p['name'], 'label' => $p['name'] . " {$opt}ml", 'price' => (float)$p['prices'][$opt]];
}
/* "AED 150" (no .00 on whole amounts) */
function fomaxo_aed_short($v) { return 'AED ' . rtrim(rtrim(number_format((float)$v, 2, '.', ','), '0'), '.'); }
/* Finds a code that can be used now. Returns ['error' => …] or ['code', 'kind', 'amount', 'min', 'label'], plus 'free' => ['id', 'opt', 'name', 'worth'] for a free product */
function fomaxo_coupon_find($code, $phone = null) {
  $code = fomaxo_coupon_norm($code);
  if ($code === '') return ['error' => 'Please type a coupon code.'];
  $pdo = fomaxo_db(); if (!$pdo) return ['error' => 'Coupons are not available right now. Please try again later.'];
  try { $s = $pdo->prepare('SELECT * FROM fx_coupons WHERE code = ?'); $s->execute([$code]); $c = $s->fetch(); }
  catch (Throwable $e) { error_log('FOMAXO coupon: ' . $e->getMessage()); return ['error' => 'Coupons are not available right now. Please try again later.']; }
  $free = $c && $c['kind'] === 'free' ? fomaxo_free_product($c['free_id'] ?? '', $c['free_opt'] ?? '') : null;
  if (!$c || !(int)$c['active'] || ($c['kind'] === 'free' ? !$free : (float)$c['amount'] <= 0)) return ['error' => 'This coupon code is not valid.'];
  $now = (new DateTime('now', new DateTimeZone('Asia/Dubai')))->format('Y-m-d H:i:s');
  $ends = $c['ends'] ?? ($c['expires'] ? $c['expires'] . ' 23:59:59' : null);
  if (!empty($c['starts']) && $c['starts'] > $now) return ['error' => 'This coupon code starts on ' . date('d/m/Y \a\t g:i a', strtotime($c['starts'])) . '.'];
  if ($ends && $ends < $now) return ['error' => 'This coupon code has expired.'];
  if ($c['max_uses'] !== null && fomaxo_coupon_uses($pdo, $code) >= (int)$c['max_uses']) return ['error' => 'This coupon code has been fully used.'];
  if (!empty($c['phone']) && $phone !== null && fomaxo_phone9($phone) !== fomaxo_phone9($c['phone'])) return ['error' => 'This coupon code is for another mobile number.'];
  if (!empty($c['per_cust']) && $phone !== null && strlen(fomaxo_phone9($phone)) === 9) {   // one use per customer: this mobile has not used it on a placed order
    $s = $pdo->prepare("SELECT phone FROM fx_orders WHERE coupon = ? AND test = 0 AND status NOT IN ('Awaiting payment', 'Cancelled', 'Refunded')"); $s->execute([$code]);
    foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $ph) if (fomaxo_phone9($ph) === fomaxo_phone9($phone)) return ['error' => 'This coupon code has already been used with this mobile number.'];
  }
  return ['code' => $c['code'], 'kind' => $c['kind'] === 'free' ? 'free' : ($c['kind'] === 'aed' ? 'aed' : 'pct'), 'amount' => (float)$c['amount'], 'min' => $c['min_order'] !== null ? (float)$c['min_order'] : 0,
          'label' => fomaxo_coupon_label($c), 'ends' => $ends ? str_replace(' ', 'T', $ends) . '+04:00' : null, 'stack' => !empty($c['stack'])]
       + ($free ? ['free' => ['id' => $free['id'], 'opt' => $free['opt'], 'name' => $free['label'], 'worth' => $free['price']]] : []);
}
/* What the code saves on a bag of $subFils (fils, before any discount). Returns ['error' => …] or the coupon plus 'saveFils'.
   A "Use both" coupon ('stack') is worked out on the bag after the multi-buy discount ($multiFils); the minimum still counts the bag before any discount.
   A free product coupon saves nothing (saveFils 0): its product is added to the order at AED 0 and the multi-buy discount stays. */
function fomaxo_coupon_apply($code, $subFils, $multiFils = 0, $phone = '') {
  $c = fomaxo_coupon_find($code, (string)$phone); if (isset($c['error'])) return $c;
  if ($c['kind'] === 'free') {
    if ($c['min'] > 0 && $subFils < (int)round($c['min'] * 100))
      return ['error' => 'Spend ' . fomaxo_aed_short($c['min']) . ' to get your free ' . $c['free']['name'] . '. Add ' . fomaxo_aed_short(round($c['min'] - $subFils / 100, 2)) . ' more to your bag.'];
    $c['saveFils'] = 0; return $c;
  }
  if ($c['min'] > 0 && $subFils < (int)round($c['min'] * 100)) return ['error' => 'This coupon code is for orders of AED ' . rtrim(rtrim(number_format($c['min'], 2, '.', ''), '0'), '.') . ' or more.'];
  $base = $c['stack'] ? $subFils - $multiFils : $subFils;
  $c['saveFils'] = $c['kind'] === 'aed' ? min($base, (int)round($c['amount'] * 100)) : (int)round($base * min(100, $c['amount']) / 100);
  return $c;
}
/* One-use coupons delete themselves once used up: codes for one mobile (GOODWILL-, REFILL-, REVIEW-, COMEBACK-, THANKS-) and free product
   coupons with a usage limit. Called when the order that uses one is placed (cash) or paid (card). Orders keep the code. */
function fx_coupon_spent($pdo, $code) {
  if (!$pdo || (string)$code === '') return;
  try {
    $s = $pdo->prepare('SELECT * FROM fx_coupons WHERE code = ?'); $s->execute([$code]); $c = $s->fetch();
    if (!$c || (empty($c['phone']) && ($c['kind'] !== 'free' || $c['max_uses'] === null))) return;
    if (fomaxo_coupon_uses($pdo, $code) >= max(1, (int)$c['max_uses'])) $pdo->prepare('DELETE FROM fx_coupons WHERE code = ?')->execute([$code]);
  } catch (Throwable $e) { error_log('FOMAXO coupon spent: ' . $e->getMessage()); }
}
/* the same for every coupon in the list (admin → Coupons opens): used-up one-use codes go */
function fx_coupon_sweep($pdo) {
  foreach ($pdo->query("SELECT code FROM fx_coupons WHERE (phone IS NOT NULL AND phone <> '') OR (kind = 'free' AND max_uses IS NOT NULL)")->fetchAll(PDO::FETCH_COLUMN) as $code) fx_coupon_spent($pdo, $code);
}
/* A new one-use coupon for one mobile number: $prefix + 4 letters/numbers (no 0/O or 1/I), e.g. GOODWILL-7K2Q. Returns the code.
   $kind 'pct' | 'aed' | 'free' ($value: % or AED off; for 'free' the product $freeId in size $freeOpt), $min: minimum order AED (0 = none),
   $ends: 'Y-m-d' (works to the end of that day), 'Y-m-d H:i:s', or '' for no end. Used by Coupons → Goodwill and the WhatsApp boxes (THANKS-, REFILL-, COMEBACK-). */
function fx_phone_coupon($pdo, $prefix, $phone, $kind, $value, $min = 0, $ends = '', $freeId = '', $freeOpt = '') {
  fomaxo_coupons_table($pdo);
  $kind = in_array($kind, ['aed', 'free'], true) ? $kind : 'pct'; $ends = (string)$ends;
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $ends)) $ends .= ' 23:59:59';
  $abc = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ'; $chk = $pdo->prepare('SELECT 1 FROM fx_coupons WHERE code = ?');
  do { $code = $prefix; for ($i = 0; $i < 4; $i++) $code .= $abc[random_int(0, strlen($abc) - 1)]; $chk->execute([$code]); } while ($chk->fetchColumn());
  $pdo->prepare('INSERT INTO fx_coupons (code, kind, amount, min_order, expires, starts, ends, max_uses, active, created_at, stack, phone, per_cust, free_id, free_opt) VALUES (?, ?, ?, ?, NULL, NULL, ?, 1, 1, NOW(), 0, ?, 0, ?, ?)')
      ->execute([$code, $kind, $kind === 'free' ? 0 : round((float)$value, 2), (float)$min > 0 ? round((float)$min, 2) : null, $ends !== '' ? $ends : null,
                 mb_substr(trim(strtr((string)$phone, FX_AR_DIGITS)), 0, 25), $kind === 'free' ? (string)$freeId : null, $kind === 'free' ? (string)$freeOpt : null]);
  return $code;
}
/* A coupon's WhatsApp message: short lines, a blank line between parts, the offer and code in *bold* (WhatsApp shows *text* as bold).
   No emoji (wa.me shows them as "?"). $intro = the opening line, $extra = more detail lines. */
function fx_coupon_wa_text($c, $intro, $extra = []) {
  $n2 = "\n\n"; $min = (float)($c['min_order'] ?? 0); $ends = $c['ends'] ?? (!empty($c['expires']) ? $c['expires'] . ' 23:59:59' : null);
  $details = array_values(array_filter(array_merge([
    $min > 0 && $c['kind'] !== 'free' ? 'Minimum order: *' . fomaxo_aed_short($min) . '*' : '',   // a free product's minimum is already in the opening line
    $ends ? 'Valid till: *' . date('j M Y', strtotime($ends)) . '*' : '',
  ], $extra)));
  return "Hi,$n2$intro{$n2}Your code:\n*{$c['code']}*" . ($details ? $n2 . implode("\n", $details) : '')
    . "{$n2}Type the code at checkout on our website:\nhttps://fomaxo.com{$n2}Thank you,\n*FOMAXO*";
}
/* the message that shares a coupon (WhatsApp opens without a number, so the owner picks the customer) */
function fx_coupon_share_text($c) {
  $min = (float)($c['min_order'] ?? 0);
  $intro = $c['kind'] === 'free'
    ? ($min > 0 ? 'Shop for *' . fomaxo_aed_short($min) . '* or more and get a *free ' . fomaxo_coupon_free_name($c) . '* with your order.' : 'Here is a *free ' . fomaxo_coupon_free_name($c) . '* with your next order.')
    : 'Here is *' . fomaxo_coupon_label($c) . '* your next order.';
  return fx_coupon_wa_text($c, $intro, [!empty($c['per_cust']) ? 'One use per customer.' : '']);
}
/* the message that sends a goodwill coupon (late delivery, faulty product) to its customer */
function fx_goodwill_text($c) {
  $ends = $c['ends'] ?? $c['expires'] ?? null;
  return fx_coupon_wa_text($c, 'We are sorry about your last order. As a goodwill gesture, here is '
      . ($c['kind'] === 'free' ? 'a *free ' . fomaxo_coupon_free_name($c) . '* with your next order.' : '*' . fomaxo_coupon_label($c) . '* your next order.'),
    ['Works one time, only with this mobile number' . ($ends ? '.' : ', no end date.')]);
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
/* website pages that admin → Settings → Site pages can hide (Home, Fragrances, products, bag and checkout always stay) */
const FX_SITE_PAGES = ['personal-care' => 'Personal Care', 'collections' => 'Collections', 'about' => 'About FOMAXO', 'contact' => 'Contact', 'franchise' => 'Franchise'];
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
/* Left at checkout: the ready WhatsApp message (name, the bag they left, a link that opens checkout with that bag), Arabic for an Arabic name,
   in two parts: the admin WhatsApp box puts a coupon it makes (COMEBACK-, one use, that mobile only, 7 days) between them. Returns [head, tail, arabic]. */
function fx_left_msg($l, $ar = null) {
  $ar ??= fx_has_ar($l['name']); $first = preg_split('/\s+/u', trim((string)$l['name']))[0] ?? '';
  $bag = preg_match('/^[a-z0-9_.-]{1,300}$/i', (string)($l['bag'] ?? '')) ? $l['bag'] : '';
  $link = 'https://fomaxo.com/?' . ($ar ? 'lang=ar&' : '') . 'utm_source=whatsapp&utm_campaign=left-checkout#/' . ($bag !== '' ? 'checkout?bag=' . $bag : 'checkout');
  $items = implode("\n", array_filter(array_map('trim', preg_split('/,\s*(?=\d+ ×)/u', (string)$l['items']))));
  $n2 = "\n\n";
  if ($ar) return [($first !== '' ? 'مرحباً ' . $first . '،' : 'مرحباً،') . ($items !== '' ? $n2 . "تركت هذه في حقيبة FOMAXO:\n" . $items : $n2 . 'لاحظنا أنك لم تكمل طلبك من FOMAXO.'),
    $n2 . "حقيبتك محفوظة. اضغط هنا لإكمال طلبك:\n" . $link . $n2 . 'توصيل مجاني داخل الإمارات خلال 1–3 أيام.' . $n2 . 'إن كان لديك أي سؤال عن العطور أو الأحجام، راسلنا هنا.' . $n2 . 'FOMAXO', true];
  return ['Hi' . ($first !== '' ? ' ' . $first : '') . ',' . ($items !== '' ? $n2 . "You left these in your FOMAXO bag:\n" . $items : $n2 . 'We noticed you did not finish your FOMAXO order.'),
    $n2 . "Your bag is saved. Tap here to finish your order:\n" . $link . $n2 . 'Free delivery across the UAE in 1–3 days.' . $n2 . 'If you have any questions about the scents or sizes, just reply here.' . $n2 . 'FOMAXO', false];
}
function fx_has_ar($s) { return (bool)preg_match('/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', (string)$s); }
/* a text in pieces of at most 450 bytes, cut after sentences where possible */
function fx_tr_parts($s) {
  $parts = []; $buf = '';
  foreach (preg_split('/(?<=[.!?؟،,\n])\s*/u', (string)$s, -1, PREG_SPLIT_NO_EMPTY) as $p) {
    while (strlen($p) > 450) { $cut = mb_strcut($p, 0, 450); if ($buf !== '') { $parts[] = $buf; $buf = ''; } $parts[] = $cut; $p = substr($p, strlen($cut)); }
    if ($buf !== '' && strlen($buf) + strlen($p) + 1 > 450) { $parts[] = $buf; $buf = ''; }
    $buf .= ($buf === '' ? '' : ' ') . $p;
  }
  if ($buf !== '') $parts[] = $buf;
  return $parts;
}
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
  foreach ($need as $h => $s) $parts[$h] = fx_tr_parts(strtr($s, FX_AR_DIGITS));
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
/* English → Arabic for reviews on the Arabic site (the review itself is never changed; the site shows the Arabic with "Show original").
   Each text is translated once and kept in fx_tr_ar. $fetch = false: only what is already kept, so the review list never waits;
   true: up to 12 new look-ups (run after the page has been sent). FOMAXO and the product names in $keep stay in English.
   A text the service could not translate well is kept as '' and tried again after 3 days. Returns [text => Arabic] for the ones that have it. */
function fomaxo_ar_many(array $texts, $fetch = false, array $keep = []) {
  static $made = false, $budget = 12;
  $out = []; $need = [];
  foreach ($texts as $s) { $s = (string)$s; if (trim($s) !== '' && !fx_has_ar($s) && preg_match('/[A-Za-z]{2}/', $s)) $need[sha1($s)] = $s; }
  if (!$need || !($pdo = fomaxo_db())) return $out;
  try {
    if (!$made) { $pdo->exec("CREATE TABLE IF NOT EXISTS fx_tr_ar (h CHAR(40) NOT NULL PRIMARY KEY, ar TEXT NOT NULL, created_at DATETIME NULL) DEFAULT CHARSET=utf8mb4"); $made = true; }
    $q = $pdo->prepare('SELECT h, ar, created_at > NOW() - INTERVAL 3 DAY AS fresh FROM fx_tr_ar WHERE h IN (' . implode(',', array_fill(0, count($need), '?')) . ')'); $q->execute(array_keys($need));
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
      if ($row['ar'] !== '') $out[$need[$row['h']]] = $row['ar'];
      if ($row['ar'] !== '' || $row['fresh']) unset($need[$row['h']]);
    }
  } catch (Throwable $e) { error_log('FOMAXO translate cache: ' . $e->getMessage()); return $out; }
  if (!$fetch || !$need || $budget <= 0 || !function_exists('curl_multi_init')) return $out;
  $need = array_slice($need, 0, $budget, true); $budget -= count($need);
  /* names that stay English go to the service as ZQX1, ZQX2… and are put back afterwards */
  $keep = array_values(array_unique(array_filter(array_map('trim', array_merge(['FOMAXO'], $keep)), fn($k) => mb_strlen($k) > 1)));
  usort($keep, fn($a, $b) => mb_strlen($b) - mb_strlen($a));
  $re = $keep ? '/(?<![\p{L}\p{N}])(' . implode('|', array_map(fn($k) => $k === 'FOMAXO' ? '(?i:FOMAXO)' : preg_quote($k, '/'), $keep)) . ')(?![\p{L}\p{N}])/u' : null;
  $parts = []; $tok = [];
  foreach ($need as $h => $s) {
    $map = [];
    $p = $re ? preg_replace_callback($re, function ($m) use (&$map) { $k = strcasecmp($m[1], 'fomaxo') === 0 ? 'FOMAXO' : $m[1]; $t = array_search($k, $map, true); if ($t === false) { $t = 'ZQX' . (count($map) + 1); $map[$t] = $k; } return $t; }, $s) : $s;
    $parts[$h] = fx_tr_parts($p); $tok[$h] = $map;
  }
  $mh = curl_multi_init(); $hs = [];
  foreach ($parts as $h => $list) foreach ($list as $i => $p) {
    $c = curl_init('https://api.mymemory.translated.net/get?' . http_build_query(['q' => $p, 'langpair' => 'en|ar']));
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 3]);
    curl_multi_add_handle($mh, $c); $hs[] = [$h, $i, $c];
  }
  do { $st = curl_multi_exec($mh, $run); if ($run) curl_multi_select($mh, 1); } while ($run && $st === CURLM_OK);
  $got = []; $bad = []; $down = [];
  foreach ($hs as [$h, $i, $c]) {
    $j = json_decode((string)curl_multi_getcontent($c), true); curl_multi_remove_handle($mh, $c); curl_close($c);
    if (!is_array($j) || stripos((string)($j['responseData']['translatedText'] ?? ''), 'MYMEMORY WARNING') !== false) { $down[$h] = true; $budget = 0; continue; }   // not reachable or today's limit: try again later
    $t = trim(html_entity_decode((string)($j['responseData']['translatedText'] ?? ''), ENT_QUOTES, 'UTF-8'));
    if ((int)($j['responseStatus'] ?? 0) !== 200 || !fx_has_ar($t)) $bad[$h] = true; else $got[$h][$i] = $t;
  }
  curl_multi_close($mh);
  foreach ($need as $h => $s) {
    if (!empty($down[$h])) continue;
    $ar = '';
    if (empty($bad[$h]) && count($got[$h] ?? []) === count($parts[$h])) {
      ksort($got[$h]); $ar = implode(' ', $got[$h]);
      foreach ($tok[$h] as $t => $k) {   // every kept name must come back, else the Arabic is not used
        if (!preg_match('/' . $t . '(?!\d)/i', $ar)) { $ar = ''; break; }
        $ar = preg_replace('/' . $t . '(?!\d)/i', "\u{2068}" . addcslashes($k, '\\$') . "\u{2069}", $ar);
      }
      if (preg_match('/ZQX\d/i', $ar)) $ar = '';
    }
    if ($ar !== '') $out[$s] = $ar;
    try { $pdo->prepare('REPLACE INTO fx_tr_ar (h, ar, created_at) VALUES (?, ?, NOW())')->execute([$h, $ar]); } catch (Throwable $e) {}
  }
  return $out;
}
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
