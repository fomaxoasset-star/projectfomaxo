<?php
// FOMAXO Store System - API used by the staff app.
require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/ziina.php';
require __DIR__ . '/lib/sales.php';

set_exception_handler(function ($e) {
    if ($e instanceof ApiError) {
        json_out(['error' => $e->getMessage()], $e->status);
    }
    error_log('FOMAXO POS: ' . $e);
    $msg = cfg('debug') ? $e->getMessage() : 'Something went wrong on the server. Please try again.';
    json_out(['error' => $msg], 500);
});

if (cfg() === false) {
    fail('The app is not set up yet: config.php is missing.', 500);
}

$action = $_GET['a'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$b = body();

function need_post()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        fail('Wrong request type.', 405);
    }
}

function product_out($p)
{
    return [
        'id' => (int) $p['id'],
        'name' => $p['name'],
        'variant' => $p['variant'],
        'sku' => $p['sku'],
        'category' => $p['category'],
        'price' => (int) $p['price'],
        'stock' => (int) $p['stock'],
        'low_at' => (int) $p['low_at'],
        'active' => (int) $p['active'],
        'updated_at' => $p['updated_at'],
    ];
}

function date_range()
{
    $from = $_GET['from'] ?? date('Y-m-d');
    $to = $_GET['to'] ?? $from;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        fail('Wrong date.');
    }
    return [$from . ' 00:00:00', $to . ' 23:59:59'];
}

switch ($action) {

    // ---------------- Login ----------------
    case 'login':
        need_post();
        $ip = client_ip();
        $since = date('Y-m-d H:i:s', time() - 900);
        $tries = (int) q1('SELECT COUNT(*) AS n FROM login_attempts WHERE ip = ? AND created_at > ?', [$ip, $since])['n'];
        if ($tries >= 10) {
            fail('Too many wrong passwords. Wait 15 minutes and try again.', 429);
        }
        $u = q1('SELECT * FROM users WHERE username = ?', [strtolower(trim($b['username'] ?? ''))]);
        if (!$u || !$u['active'] || !password_verify((string) ($b['password'] ?? ''), $u['pass_hash'])) {
            insert('login_attempts', ['ip' => $ip, 'created_at' => now()]);
            fail('Wrong username or password.', 401);
        }
        q('DELETE FROM login_attempts WHERE ip = ?', [$ip]);
        q('DELETE FROM tokens WHERE expires_at < ?', [now()]);
        json_out([
            'token' => issue_token((int) $u['id']),
            'user' => ['id' => (int) $u['id'], 'name' => $u['name'], 'username' => $u['username'], 'role' => $u['role']],
        ]);

    case 'logout':
        need_post();
        $token = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? '';
        q('DELETE FROM tokens WHERE token_hash = ?', [hash('sha256', $token)]);
        json_out(['ok' => true]);

    case 'me':
        $u = require_user();
        json_out([
            'user' => $u,
            'settings' => [
                'store_name' => cfg('store_name'),
                'store_tagline' => cfg('store_tagline'),
                'store_address' => cfg('store_address'),
                'store_phone' => cfg('store_phone'),
                'store_trn' => cfg('store_trn'),
                'receipt_footer' => cfg('receipt_footer'),
                'currency' => cfg('currency', 'AED'),
                'vat_rate' => (float) cfg('vat_rate', 5),
                'prices_include_vat' => (bool) cfg('prices_include_vat', true),
                'default_low_stock' => (int) cfg('default_low_stock', 5),
                'staff_max_discount' => (float) cfg('staff_max_discount', 10),
                'ziina' => ziina_enabled(),
                'ziina_mode' => ziina_mode(),
                'tiers' => cfg('tiers', []),
                'base_url' => cfg('base_url'),
            ],
        ]);

    case 'change_password':
        need_post();
        $u = require_user();
        $row = q1('SELECT pass_hash FROM users WHERE id = ?', [$u['id']]);
        if (!password_verify((string) ($b['current'] ?? ''), $row['pass_hash'])) {
            fail('Current password is wrong.');
        }
        $new = (string) ($b['new'] ?? '');
        if (strlen($new) < 8) {
            fail('New password needs at least 8 characters.');
        }
        q('UPDATE users SET pass_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $u['id']]);
        json_out(['ok' => true]);

    // ---------------- Products & stock ----------------
    case 'products':
        $u = require_user();
        $rows = q('SELECT * FROM products ORDER BY category, name, variant')->fetchAll();
        json_out(['products' => array_map('product_out', $rows), 'time' => now()]);

    case 'product_save':
        need_post();
        $u = require_manager();
        $name = trim(mb_substr((string) ($b['name'] ?? ''), 0, 120));
        if ($name === '') {
            fail('Product name is needed.');
        }
        $price = to_fils($b['price'] ?? 0);
        if ($price < 0) {
            fail('Price cannot be negative.');
        }
        $data = [
            'name' => $name,
            'variant' => trim(mb_substr((string) ($b['variant'] ?? ''), 0, 60)),
            'sku' => trim(mb_substr((string) ($b['sku'] ?? ''), 0, 64)),
            'category' => trim(mb_substr((string) ($b['category'] ?? ''), 0, 60)),
            'price' => $price,
            'low_at' => max(0, (int) ($b['low_at'] ?? cfg('default_low_stock', 5))),
            'active' => empty($b['active']) ? 0 : 1,
            'updated_at' => now(),
        ];
        $id = (int) ($b['id'] ?? 0);
        if ($id) {
            if (!q1('SELECT id FROM products WHERE id = ?', [$id])) {
                fail('Product not found.', 404);
            }
            $sets = implode(', ', array_map(function ($k) {
                return "$k = ?";
            }, array_keys($data)));
            q("UPDATE products SET $sets WHERE id = ?", array_merge(array_values($data), [$id]));
        } else {
            $data['stock'] = 0;
            $data['created_at'] = now();
            $id = insert('products', $data);
            $start = (int) ($b['stock'] ?? 0);
            if ($start !== 0) {
                move_stock($id, $u['id'], $start, 'opening', 'Opening stock');
            }
        }
        json_out(['product' => product_out(q1('SELECT * FROM products WHERE id = ?', [$id]))]);

    case 'stock_adjust':
        need_post();
        $u = require_manager();
        $id = (int) ($b['product_id'] ?? 0);
        $p = q1('SELECT * FROM products WHERE id = ?', [$id]);
        if (!$p) {
            fail('Product not found.', 404);
        }
        $reason = $b['reason'] ?? 'restock';
        if (!in_array($reason, ['restock', 'count', 'damaged', 'tester', 'return', 'other'], true)) {
            $reason = 'other';
        }
        if ($reason === 'count') {
            // Stock count: set the exact number found on the shelf.
            $counted = (int) ($b['counted'] ?? -1);
            if ($counted < 0) {
                fail('Enter the number you counted.');
            }
            $change = $counted - (int) $p['stock'];
        } else {
            $change = (int) ($b['change'] ?? 0);
        }
        if ($change === 0) {
            json_out(['product' => product_out($p)]);
        }
        move_stock($id, $u['id'], $change, $reason, $b['note'] ?? '');
        json_out(['product' => product_out(q1('SELECT * FROM products WHERE id = ?', [$id]))]);

    case 'stock_moves':
        require_user();
        $id = (int) ($_GET['product_id'] ?? 0);
        $rows = q(
            'SELECT m.qty_change, m.stock_after, m.reason, m.note, m.created_at, u.name AS staff, s.receipt_no
             FROM stock_moves m JOIN users u ON u.id = m.user_id LEFT JOIN sales s ON s.id = m.sale_id
             WHERE m.product_id = ? ORDER BY m.id DESC LIMIT 100',
            [$id]
        )->fetchAll();
        json_out(['moves' => $rows]);

    // ---------------- Customers ----------------
    case 'customer':
        require_user();
        $phone = normalize_phone($_GET['phone'] ?? '');
        $c = $phone ? q1('SELECT * FROM customers WHERE phone = ?', [$phone]) : null;
        if (!$c) {
            json_out(['customer' => null, 'phone' => $phone]);
        }
        $spend = customer_year_spend($c['id']);
        json_out(['customer' => [
            'id' => (int) $c['id'],
            'phone' => $c['phone'],
            'name' => $c['name'],
            'year_spend' => $spend,
            'tier' => tier_for($spend),
            'next_tier' => next_tier($spend),
        ]]);

    // ---------------- Selling ----------------
    case 'sale':
        // Cash, Tap to Pay (taken in the Ziina app) or card-machine sale.
        // Also used to upload sales made offline.
        need_post();
        $u = require_user();
        $m = $b['method'] ?? 'cash';
        if (!in_array($m, ['cash', 'tap', 'card'], true)) {
            fail('Unknown payment method.');
        }
        $id = create_sale($u, $b, $m);
        json_out(['sale' => load_sale('id', $id)]);

    case 'ziina_start':
        need_post();
        $u = require_user();
        if (!ziina_enabled()) {
            fail('Ziina is not set up yet. Add your Ziina key in config.php.');
        }
        $id = create_sale($u, $b, 'ziina');
        $sale = q1('SELECT * FROM sales WHERE id = ?', [$id]);
        if ($sale['status'] !== 'pending') {
            json_out(['sale' => load_sale('id', $id)]);
        }
        try {
            $z = ziina_create(
                (int) $sale['total'],
                cfg('store_name', 'FOMAXO') . ' ' . $sale['receipt_no'],
                cfg('base_url') . '/receipt.php?t=' . $sale['receipt_token'],
                cfg('base_url') . '/receipt.php?t=' . $sale['receipt_token'] . '&cancelled=1'
            );
        } catch (Throwable $e) {
            q("UPDATE sales SET status = 'failed' WHERE id = ?", [$id]);
            throw $e;
        }
        q('UPDATE sales SET ziina_id = ? WHERE id = ?', [$z['id'], $id]);
        json_out(['sale' => load_sale('id', $id), 'pay_url' => $z['redirect_url']]);

    case 'ziina_check':
        $u = require_user();
        $sale = q1('SELECT * FROM sales WHERE id = ?', [(int) ($_GET['id'] ?? 0)]);
        if (!$sale) {
            fail('Sale not found.', 404);
        }
        $status = refresh_ziina_sale($sale, $u['id']);
        json_out(['status' => $status, 'sale' => $status === 'paid' ? load_sale('id', $sale['id']) : null]);

    case 'ziina_cancel':
        need_post();
        $u = require_user();
        $sale = q1('SELECT * FROM sales WHERE id = ?', [(int) ($b['id'] ?? 0)]);
        if (!$sale) {
            fail('Sale not found.', 404);
        }
        // Last check, in case the customer paid a second ago.
        $status = refresh_ziina_sale($sale, $u['id']);
        if ($status === 'paid') {
            json_out(['status' => 'paid', 'sale' => load_sale('id', $sale['id'])]);
        }
        q("UPDATE sales SET status = 'cancelled' WHERE id = ? AND status = 'pending'", [$sale['id']]);
        json_out(['status' => 'cancelled']);

    // ---------------- Sales history ----------------
    case 'sales':
        $u = require_user();
        [$from, $to] = date_range();
        $params = [$from, $to];
        $where = 's.created_at BETWEEN ? AND ?';
        if ($u['role'] !== 'manager') {
            $where .= ' AND s.user_id = ?';
            $params[] = $u['id'];
        }
        $rows = q(
            "SELECT s.id, s.receipt_no, s.total, s.method, s.status, s.offline, s.created_at, u.name AS staff_name,
                    c.name AS customer_name, c.phone AS customer_phone,
                    (SELECT SUM(qty) FROM sale_items i WHERE i.sale_id = s.id) AS items
             FROM sales s JOIN users u ON u.id = s.user_id LEFT JOIN customers c ON c.id = s.customer_id
             WHERE $where ORDER BY s.created_at DESC, s.id DESC LIMIT 500",
            $params
        )->fetchAll();
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['total'] = (int) $r['total'];
            $r['items'] = (int) $r['items'];
            $r['offline'] = (int) $r['offline'];
        }
        json_out(['sales' => $rows]);

    case 'sale_get':
        $u = require_user();
        $s = load_sale('id', (int) ($_GET['id'] ?? 0));
        if (!$s || ($u['role'] !== 'manager' && $s['user_id'] !== $u['id'])) {
            fail('Sale not found.', 404);
        }
        json_out(['sale' => $s]);

    case 'void_sale':
        need_post();
        $u = require_manager();
        $s = q1('SELECT * FROM sales WHERE id = ?', [(int) ($b['id'] ?? 0)]);
        if (!$s || $s['status'] !== 'paid') {
            fail('Only paid sales can be voided.');
        }
        $reason = trim(mb_substr((string) ($b['reason'] ?? ''), 0, 200));
        if ($reason === '') {
            fail('Please give a reason.');
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $st = q("UPDATE sales SET status = 'void', note = ? WHERE id = ? AND status = 'paid'", ['VOID: ' . $reason, $s['id']]);
            if ($st->rowCount() === 1 && !empty($b['restock'])) {
                foreach (q('SELECT product_id, qty FROM sale_items WHERE sale_id = ?', [$s['id']])->fetchAll() as $it) {
                    move_stock((int) $it['product_id'], $u['id'], (int) $it['qty'], 'void', $s['receipt_no'], $s['id']);
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        json_out(['sale' => load_sale('id', $s['id'])]);

    // ---------------- Reports ----------------
    case 'report':
        require_manager();
        [$from, $to] = date_range();
        $p = [$from, $to];
        $base = "FROM sales s WHERE s.status = 'paid' AND s.created_at BETWEEN ? AND ?";
        $sum = q1("SELECT COUNT(*) AS sales, COALESCE(SUM(total),0) AS total, COALESCE(SUM(vat),0) AS vat, COALESCE(SUM(discount),0) AS discount $base", $p);
        $byMethod = q("SELECT method, COUNT(*) AS sales, SUM(total) AS total $base GROUP BY method", $p)->fetchAll();
        $byStaff = q("SELECT u.name, COUNT(*) AS sales, SUM(s.total) AS total FROM sales s JOIN users u ON u.id = s.user_id
                      WHERE s.status = 'paid' AND s.created_at BETWEEN ? AND ? GROUP BY u.id, u.name ORDER BY total DESC", $p)->fetchAll();
        $byDay = q("SELECT DATE(s.created_at) AS day, COUNT(*) AS sales, SUM(s.total) AS total $base GROUP BY DATE(s.created_at) ORDER BY day", $p)->fetchAll();
        $top = q("SELECT i.name, i.variant, SUM(i.qty) AS qty, SUM(i.line_total) AS total FROM sale_items i JOIN sales s ON s.id = i.sale_id
                  WHERE s.status = 'paid' AND s.created_at BETWEEN ? AND ? GROUP BY i.product_id, i.name, i.variant ORDER BY qty DESC, total DESC LIMIT 10", $p)->fetchAll();
        $toInt = function ($rows) {
            return array_map(function ($r) {
                foreach (['sales', 'total', 'qty'] as $k) {
                    if (isset($r[$k])) {
                        $r[$k] = (int) $r[$k];
                    }
                }
                return $r;
            }, $rows);
        };
        json_out([
            'summary' => array_map('intval', $sum),
            'by_method' => $toInt($byMethod),
            'by_staff' => $toInt($byStaff),
            'by_day' => $toInt($byDay),
            'top' => $toInt($top),
        ]);

    // ---------------- Staff accounts ----------------
    case 'users':
        require_manager();
        $rows = q('SELECT id, name, username, role, active, created_at FROM users ORDER BY active DESC, name')->fetchAll();
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['active'] = (int) $r['active'];
        }
        json_out(['users' => $rows]);

    case 'user_save':
        need_post();
        $me = require_manager();
        $id = (int) ($b['id'] ?? 0);
        $name = trim(mb_substr((string) ($b['name'] ?? ''), 0, 100));
        $username = strtolower(trim((string) ($b['username'] ?? '')));
        $role = ($b['role'] ?? 'staff') === 'manager' ? 'manager' : 'staff';
        $active = empty($b['active']) ? 0 : 1;
        $pass = (string) ($b['password'] ?? '');
        if ($name === '' || !preg_match('/^[a-z0-9._-]{3,50}$/', $username)) {
            fail('Enter a name and a username (3+ letters/numbers, no spaces).');
        }
        $clash = q1('SELECT id FROM users WHERE username = ? AND id <> ?', [$username, $id]);
        if ($clash) {
            fail('That username is already taken.');
        }
        if ($id === $me['id'] && (!$active || $role !== 'manager')) {
            fail('You cannot remove your own manager access.');
        }
        if ($id) {
            q('UPDATE users SET name = ?, username = ?, role = ?, active = ? WHERE id = ?', [$name, $username, $role, $active, $id]);
            if ($pass !== '') {
                if (strlen($pass) < 8) {
                    fail('Password needs at least 8 characters.');
                }
                q('UPDATE users SET pass_hash = ? WHERE id = ?', [password_hash($pass, PASSWORD_DEFAULT), $id]);
                q('DELETE FROM tokens WHERE user_id = ?', [$id]);
            }
            if (!$active) {
                q('DELETE FROM tokens WHERE user_id = ?', [$id]);
            }
        } else {
            if (strlen($pass) < 8) {
                fail('Password needs at least 8 characters.');
            }
            $id = insert('users', [
                'name' => $name,
                'username' => $username,
                'pass_hash' => password_hash($pass, PASSWORD_DEFAULT),
                'role' => $role,
                'active' => $active,
                'created_at' => now(),
            ]);
        }
        json_out(['ok' => true, 'id' => $id]);

    default:
        fail('Unknown request.', 404);
}
