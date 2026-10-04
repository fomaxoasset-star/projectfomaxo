<?php
/* FOMAXO — order database (MySQL on Hostinger). Used by checkout.php (cash on delivery), ziina.php (card) and admin/.
   Every order gets a number that counts up: FMX-1001, FMX-1002 …
   The database login lives in fomaxo-db-config.php ONE LEVEL ABOVE public_html (never on GitHub, never public).
   It is written once by the set-up form on fomaxo.com/admin. If the database is not set up or is down,
   orders still go through with the old random number and are kept in the CSV files and emails as before. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }

const FX_STATUSES = ['Awaiting payment', 'New', 'Paid', 'Delivered', 'Cancelled'];

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

/* Creates the tables the first time, and copies in the orders already saved in the CSV files. */
function fomaxo_db_schema($pdo) {
  try { if ((int)$pdo->query("SELECT v FROM fx_settings WHERE k = 'schema'")->fetchColumn() >= 1) return; } catch (Throwable $e) {}
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
  try { fomaxo_import_csv($pdo); } catch (Throwable $e) { error_log('FOMAXO import: ' . $e->getMessage()); }
  /* new orders count up from FMX-1001 (earlier orders keep their old numbers) */
  $next = max(1001, (int)$pdo->query('SELECT COALESCE(MAX(id), 0) + 1 FROM fx_orders')->fetchColumn());
  $pdo->exec("ALTER TABLE fx_orders AUTO_INCREMENT = $next");
  $pdo->exec("REPLACE INTO fx_settings (k, v) VALUES ('schema', '1')");
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
const FX_LOW_STOCK = 5;   // the website shows "Only X left" from this number down — keep in sync with index.html (LOW_STOCK)

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
    return $have[$k] <= 0 ? "Sorry, $label is sold out. Please remove it from your bag."
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

/* ---- one-time copy of the orders saved before the database existed (CSV files above public_html) ---- */
function fomaxo_import_csv($pdo) {
  $dir = dirname(__DIR__) . '/fomaxo-orders';
  $un = fn($v) => is_string($v) && strlen($v) > 1 && $v[0] === "'" && preg_match('/^[=+\-@]/', substr($v, 1)) ? substr($v, 1) : (string)$v;   // undo the spreadsheet-formula guard
  $num = fn($v) => (float)preg_replace('/[^\d.\-]/', '', (string)$v);
  $ins = $pdo->prepare("INSERT INTO fx_orders (order_no, created_at, payment, status, paid_at, subtotal, discount, fee, total, name, phone, email, emirate,
                          building, room, street, area, address, note, items, test, source)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'import')
                        ON DUPLICATE KEY UPDATE status = IF(VALUES(status) = 'Paid', 'Paid', status), paid_at = COALESCE(paid_at, VALUES(paid_at))");
  $when = fn($d) => ($t = strtotime((string)$d)) ? date('Y-m-d H:i:s', $t) : date('Y-m-d H:i:s');

  /* cash on delivery orders saved by checkout.php (has a header row) */
  if (($f = @fopen("$dir/cash-on-delivery-orders.csv", 'r'))) {
    $head = fgetcsv($f);
    while (($r = fgetcsv($f)) !== false) {
      if (count($r) < 13 || ($r[1] ?? '') === '') continue;
      $r = array_map($un, $r);
      $ins->execute([$r[1], $when($r[0]), 'Cash on delivery', 'New', null, $num($r[9]), $num($r[10]), $num($r[11]), $num($r[12]), $r[2], $r[3], $r[4], $r[5],
                     $r[13] ?? '', $r[14] ?? '', $r[15] ?? '', $r[16] ?? '', $r[6], $r[7], $r[8], 0]);
    }
    fclose($f);
  }
  /* card (Ziina) and older cash orders saved by store-lib.php (no header row) */
  if (($f = @fopen("$dir/orders.csv", 'r'))) {
    while (($r = fgetcsv($f)) !== false) {
      if (count($r) < 11 || ($r[1] ?? '') === '') continue;
      $r = array_map($un, $r);
      $type = $r[2];
      $card = stripos($type, 'ziina') !== false;
      $paid = $card && stripos($type, 'paid') !== false;
      $ins->execute([$r[1], $when($r[0]), $card ? 'Card (Ziina)' : 'Cash on delivery', $card ? ($paid ? 'Paid' : 'Awaiting payment') : 'New', $paid ? $when($r[0]) : null,
                     null, null, null, $num($r[3]), $r[4], $r[5], $r[6], $r[7], '', '', '', '', $r[8], $r[9], $r[10], stripos($type, 'test') !== false ? 1 : 0]);
    }
    fclose($f);
  }
}
