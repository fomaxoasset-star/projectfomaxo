<?php
/* FOMAXO — order database (MySQL on Hostinger). Used by checkout.php (cash on delivery), ziina.php (card) and admin/.
   Every order gets a number that counts up: FMX-1001, FMX-1002 …
   The database login lives in fomaxo-db-config.php ONE LEVEL ABOVE public_html (never on GitHub, never public).
   It is written once by the set-up form on fomaxo.com/admin. If the database is not set up or is down,
   orders still go through with the old random number and are kept in the CSV files and emails as before. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }

const FX_STATUSES = ['Awaiting payment', 'New', 'Paid', 'Delivered', 'Cancelled', 'Refunded'];

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
  if ($ver >= 9) return;
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
}
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

/* Saves a new order and returns its number (FMX-1001 …), or null if the database is not available.
   $o: payment, status, subtotal, discount, fee, total (AED), customer fields, items (text), lines (array), free_mini, ref, test */
function fomaxo_save_order($o) {
  $pdo = fomaxo_db(); if (!$pdo) return null;
  $cols = ['created_at', 'payment', 'status', 'subtotal', 'discount', 'fee', 'total', 'cost', 'name', 'phone', 'email', 'emirate', 'building', 'room',
           'street', 'area', 'address', 'note', 'items', 'lines_json', 'free_mini', 'ref', 'test'];
  $o += ['created_at' => (new DateTime('now', new DateTimeZone('Asia/Dubai')))->format('Y-m-d H:i:s'), 'lines_json' => isset($o['lines']) ? json_encode($o['lines'], JSON_UNESCAPED_UNICODE) : null,
         'subtotal' => null, 'discount' => null, 'fee' => null, 'items' => null, 'free_mini' => null, 'ref' => null, 'test' => 0, 'total' => 0];
  $o['cost'] = isset($o['lines']) ? fomaxo_cost_of($o['lines'], fomaxo_cost_map($pdo)) : null;   // cost of goods at today's cost prices (kept, so later price changes don't rewrite old months)
  $vals = []; foreach ($cols as $c) $vals[] = is_bool($o[$c] ?? null) ? (int)$o[$c] : ($o[$c] ?? '');
  foreach (['subtotal', 'discount', 'fee', 'cost', 'items', 'lines_json', 'free_mini', 'ref'] as $c) if ($o[$c] === null) $vals[array_search($c, $cols)] = null;
  $ins = $pdo->prepare('INSERT INTO fx_orders (order_no, ' . implode(', ', $cols) . ') VALUES (?' . str_repeat(', ?', count($cols)) . ')');
  for ($try = 0; $try < 5; $try++) {   // the number is the row id; if an older order already uses that number, take the next one
    $id = 0;
    try {
      $ins->execute(array_merge(['new-' . bin2hex(random_bytes(8))], $vals));
      $id = (int)$pdo->lastInsertId();
      $no = 'FMX-' . $id;
      $pdo->prepare('UPDATE fx_orders SET order_no = ? WHERE id = ?')->execute([$no, $id]);
      return $no;
    } catch (Throwable $e) {
      if ($id) { try { $pdo->prepare('DELETE FROM fx_orders WHERE id = ?')->execute([$id]); } catch (Throwable $e2) {} }
      error_log('FOMAXO save order: ' . $e->getMessage());
      if (!$id) return null;
    }
  }
  return null;
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
