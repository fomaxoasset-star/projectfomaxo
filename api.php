<?php
// FOMAXO Store System - API used by the staff app.
require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/ziina.php';
require __DIR__ . '/lib/sales.php';
require __DIR__ . '/lib/schema.php';
require __DIR__ . '/lib/webimport.php';
require __DIR__ . '/lib/coupons.php';

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
migrate();

$action = $_GET['a'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$b = body();

function need_post()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        fail('Wrong request type.', 405);
    }
}

function web_skus()
{
    static $list = null;
    if ($list === null) {
        $list = json_decode((string) meta_get('web_skus', '[]'), true) ?: [];
    }
    return $list;
}

function stock_map()
{
    $map = [];
    foreach (q('SELECT product_id, store_id, stock FROM product_stock')->fetchAll() as $r) {
        $map[(int) $r['product_id']][(int) $r['store_id']] = (int) $r['stock'];
    }
    return $map;
}

/** $storeId 0 = total of all stores */
function product_out($p, $map = null, $storeId = 0)
{
    $map = $map === null ? stock_map() : $map;
    $mine = $map[(int) $p['id']] ?? [];
    return [
        'id' => (int) $p['id'],
        'name' => $p['name'],
        'variant' => $p['variant'],
        'sku' => $p['sku'],
        'category' => $p['category'],
        'price' => (int) $p['price'],
        'compare_at' => (int) ($p['compare_at'] ?? 0),
        'family' => (string) ($p['family'] ?? ''),
        'image' => photo_url((string) ($p['image'] ?? '')),
        'price_lock' => (int) ($p['price_lock'] ?? 0),
        'from_site' => in_array($p['sku'], web_skus(), true),
        'stock' => $storeId ? ($mine[$storeId] ?? 0) : (int) $p['stock'],
        'stocks' => (object) $mine,
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
        $u = q1('SELECT * FROM users WHERE removed = 0 AND LOWER(username) = ?', [strtolower(trim($b['username'] ?? ''))]);
        if (!$u || !$u['active'] || !password_verify((string) ($b['password'] ?? ''), $u['pass_hash'])) {
            insert('login_attempts', ['ip' => $ip, 'created_at' => now()]);
            fail('Wrong username or password.', 401);
        }
        q('DELETE FROM login_attempts WHERE ip = ?', [$ip]);
        log_activity((int) $u['id'], 'login', 'Logged in', '', $u['store_id'] ?? null);
        q('DELETE FROM tokens WHERE expires_at < ?', [now()]);
        $me = ['id' => (int) $u['id'], 'name' => $u['name'], 'username' => $u['username'], 'role' => $u['role'],
               'active' => (int) $u['active'], 'store_id' => $u['store_id'] === null ? null : (int) $u['store_id']];
        // Everything the app needs to start, in one reply (saves a second request after logging in).
        json_out(['token' => issue_token((int) $u['id'])] + me_payload($me));

    case 'logout':
        need_post();
        $token = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? '';
        if ($lu = current_user()) {
            log_activity($lu, 'logout', 'Logged out');
        }
        q('DELETE FROM tokens WHERE token_hash = ?', [hash('sha256', $token)]);
        json_out(['ok' => true]);

    case 'me':
        json_out(me_payload(require_user()));

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
        log_activity($u, 'password', 'Changed own password');
        json_out(['ok' => true]);

    // ---------------- Products & stock ----------------
    case 'products':
        $u = require_user();
        auto_sync_web_soon();
        $scope = $_GET['store'] ?? '';
        $storeId = ($scope === 'all' && $u['role'] === 'manager') ? 0 : resolve_store($u, $scope);
        $map = stock_map();
        $rows = q('SELECT * FROM products ORDER BY category, name, variant')->fetchAll();
        // Units sold in the last 30 days (for "Best sellers" sorting on Sell)
        $sold = [];
        foreach (q("SELECT i.product_id, SUM(i.qty) AS n FROM sale_items i JOIN sales s ON s.id = i.sale_id
                    WHERE s.status = 'paid' AND s.created_at >= ? GROUP BY i.product_id", [date('Y-m-d H:i:s', time() - 30 * 86400)])->fetchAll() as $r) {
            $sold[(int) $r['product_id']] = (int) $r['n'];
        }
        $list = array_map(function ($p) use ($map, $storeId, $sold) {
            $o = product_out($p, $map, $storeId);
            $o['sold30'] = $sold[(int) $p['id']] ?? 0;
            return $o;
        }, $rows);
        // The app checks every 30 seconds: when nothing changed, send a tiny "same" reply instead of the whole list.
        $hash = md5(json_encode($list) . '|' . $storeId);
        if (($_GET['h'] ?? '') === $hash) {
            json_out(['same' => true, 'hash' => $hash]);
        }
        json_out(['products' => $list, 'store_id' => $storeId, 'hash' => $hash, 'time' => now()]);

    case 'product_save':
        need_post();
        $u = require_manager();
        $name = text_in($b['name'] ?? '', 120);
        if ($name === '') {
            fail('Product name is needed.');
        }
        $price = to_fils($b['price'] ?? 0);
        if ($price < 0) {
            fail('Price cannot be negative.');
        }
        $compare = to_fils($b['compare_at'] ?? 0);
        if ($compare && $compare <= $price) {
            fail('The original price must be higher than the exclusive price (or leave it empty).');
        }
        $data = [
            'name' => $name,
            'variant' => text_in($b['variant'] ?? '', 60),
            'sku' => text_in($b['sku'] ?? '', 64),
            'category' => text_in($b['category'] ?? '', 60),
            'price' => $price,
            'compare_at' => max(0, $compare),
            'family' => text_in($b['family'] ?? '', 80),
            'price_lock' => empty($b['price_lock']) ? 0 : 1,
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
                move_stock($id, resolve_store($u, $b['store_id'] ?? 0), $u['id'], $start, 'opening', 'Opening stock');
            }
        }
        log_activity($u, 'product', ($b['id'] ?? 0 ? 'Edited ' : 'Added ') . trim($name . ' ' . $data['variant']) . ' - AED ' . money($price) . ($data['active'] ? '' : ' (off sale)'));
        json_out(['product' => product_out(q1('SELECT * FROM products WHERE id = ?', [$id]))]);

    case 'stock_adjust':
        need_post();
        $u = require_manager();
        $id = (int) ($b['product_id'] ?? 0);
        $p = q1('SELECT * FROM products WHERE id = ?', [$id]);
        if (!$p) {
            fail('Product not found.', 404);
        }
        $storeId = resolve_store($u, $b['store_id'] ?? 0);
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
            $change = $counted - store_stock($id, $storeId);
        } else {
            $change = (int) ($b['change'] ?? 0);
        }
        if ($change !== 0) {
            move_stock($id, $storeId, $u['id'], $change, $reason, $b['note'] ?? '');
            $rl = ['restock' => 'Received stock', 'count' => 'Stock count', 'damaged' => 'Damaged', 'tester' => 'Tester', 'return' => 'Customer return', 'other' => 'Stock correction'][$reason];
            log_activity($u, 'stock', $rl . ': ' . trim($p['name'] . ' ' . $p['variant']) . ' ' . ($change > 0 ? '+' : '') . $change, '', $storeId);
        }
        json_out(['product' => product_out(q1('SELECT * FROM products WHERE id = ?', [$id]), null, $storeId)]);

    case 'import_web':
        // Copy products, sizes and prices from the fomaxo.com website. Stock counts stay as they are.
        need_post();
        $u = require_manager();
        $r = import_web_products();
        log_activity($u, 'product', 'Synced products with the website: ' . $r['added'] . ' added, ' . $r['updated'] . ' prices checked');
        json_out($r);

    case 'stock_bulk':
        // Enter many stock numbers at once for one store.
        // mode 'set' = the number on the shelf, mode 'add' = how many arrived.
        need_post();
        $u = require_manager();
        $storeId = resolve_store($u, $b['store_id'] ?? 0);
        $mode = ($b['mode'] ?? 'set') === 'add' ? 'add' : 'set';
        $items = is_array($b['items'] ?? null) ? $b['items'] : [];
        $changed = 0;
        $pdo = db();
        $pdo->beginTransaction();
        try {
            foreach ($items as $it) {
                $pid = (int) ($it['product_id'] ?? 0);
                if ($pid <= 0 || !isset($it['qty']) || $it['qty'] === '' || !q1('SELECT id FROM products WHERE id = ?', [$pid])) {
                    continue;
                }
                $n = (int) $it['qty'];
                if ($mode === 'set' && $n < 0) {
                    continue;
                }
                $change = $mode === 'set' ? $n - store_stock($pid, $storeId) : $n;
                if ($change !== 0) {
                    move_stock($pid, $storeId, $u['id'], $change, $mode === 'set' ? 'count' : 'restock', $b['note'] ?? '');
                    $changed++;
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $sn = q1('SELECT name FROM stores WHERE id = ?', [$storeId])['name'] ?? '';
        log_activity($u, 'stock', ($mode === 'set' ? 'Stock count' : 'Received stock') . ' for ' . $changed . ' products at ' . $sn, '', $storeId);
        json_out(['changed' => $changed]);

    case 'stock_transfer':
        // Move stock from one store to another.
        need_post();
        $u = require_manager();
        $id = (int) ($b['product_id'] ?? 0);
        $from = (int) ($b['from'] ?? 0);
        $to = (int) ($b['to'] ?? 0);
        $qty = (int) ($b['qty'] ?? 0);
        $p = q1('SELECT * FROM products WHERE id = ?', [$id]);
        $sf = q1('SELECT * FROM stores WHERE id = ?', [$from]);
        $stt = q1('SELECT * FROM stores WHERE id = ?', [$to]);
        if (!$p || !$sf || !$stt) {
            fail('Product or store not found.', 404);
        }
        if ($from === $to) {
            fail('Choose two different stores.');
        }
        if ($qty <= 0) {
            fail('Enter how many to move.');
        }
        $have = store_stock($id, $from);
        if ($have < $qty) {
            fail($sf['name'] . ' only has ' . max(0, $have) . ' of this product.');
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            move_stock($id, $from, $u['id'], -$qty, 'transfer', 'To ' . $stt['name'] . ($b['note'] ?? '' ? ' - ' . $b['note'] : ''));
            move_stock($id, $to, $u['id'], $qty, 'transfer', 'From ' . $sf['name'] . ($b['note'] ?? '' ? ' - ' . $b['note'] : ''));
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        log_activity($u, 'transfer', 'Moved ' . $qty . ' x ' . trim($p['name'] . ' ' . $p['variant']) . ' from ' . $sf['name'] . ' to ' . $stt['name'], '', $from);
        json_out(['product' => product_out(q1('SELECT * FROM products WHERE id = ?', [$id]))]);

    case 'stock_moves':
        require_user();
        $id = (int) ($_GET['product_id'] ?? 0);
        $rows = q(
            'SELECT m.qty_change, m.stock_after, m.reason, m.note, m.created_at, u.name AS staff, s.receipt_no, st.name AS store_name
             FROM stock_moves m JOIN users u ON u.id = m.user_id LEFT JOIN sales s ON s.id = m.sale_id
             LEFT JOIN stores st ON st.id = m.store_id
             WHERE m.product_id = ? ORDER BY m.id DESC LIMIT 150',
            [$id]
        )->fetchAll();
        json_out(['moves' => $rows]);

    // ---------------- Customers ----------------
    case 'customer':
        require_user();
        $phone = normalize_phone($_GET['phone'] ?? '');
        $email = clean_email($_GET['email'] ?? '');
        $c = strlen(preg_replace('/\D/', '', $phone)) >= 7 ? q1('SELECT * FROM customers WHERE phone = ?', [$phone]) : null;
        if (!$c && $email !== '') {
            $c = q1('SELECT * FROM customers WHERE email = ? ORDER BY id LIMIT 1', [$email]);
        }
        require_once __DIR__ . '/lib/website.php';
        $web = cfg('website_customers', true) ? web_customer($phone, $email) : null;
        $webIn = cfg('website_customers', true) ? web_in_customer($phone, $email) : null;
        if (!$c) {
            $ws = $web ? (int) $web['year_spend'] : 0;
            json_out(['customer' => null, 'phone' => $phone, 'web' => $web, 'web_in' => $webIn,
                'tier' => $web ? tier_for($ws) : null, 'next_tier' => $web ? next_tier($ws) : null, 'year_spend' => $ws]);
        }
        $spend = customer_year_spend($c['id']);
        $storeVisits = (int) q1("SELECT COUNT(*) AS n FROM sales WHERE customer_id = ? AND status = 'paid'", [$c['id']])['n'];
        json_out(['web' => $web, 'web_in' => $webIn, 'customer' => [
            'visits' => $storeVisits,
            'id' => (int) $c['id'],
            'phone' => strpos($c['phone'], 'e:') === 0 ? '' : $c['phone'],
            'name' => $c['name'],
            'email' => (string) ($c['email'] ?? ''),
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

    case 'sale_cancel':
        // Cancel at the Cash / Card / Tap payment screen, with a reason. Kept in Sales so it can be paid later.
        need_post();
        $u = require_user();
        $m = $b['method'] ?? 'cash';
        if (!in_array($m, ['cash', 'tap', 'card'], true)) {
            fail('Unknown payment method.');
        }
        $reason = trim((string) ($b['cancel_reason'] ?? ''));
        if ($reason === '') {
            fail('Pick a reason.');
        }
        unset($b['cash_given']);
        $id = create_sale($u, $b, $m);
        $s = q1('SELECT total, store_id FROM sales WHERE id = ?', [$id]);
        log_activity($u, 'cancel', 'Cancelled ' . method_name($m) . ' sale of AED ' . money((int) $s['total']) . ': ' . mb_substr($reason, 0, 200), '', (int) $s['store_id']);
        json_out(['ok' => true, 'id' => $id]);

    case 'sale_pay':
        // Pay again by Cash / Card / Tap for a sale that was cancelled, failed or is still waiting.
        need_post();
        $u = require_user();
        $m = $b['method'] ?? 'cash';
        if (!in_array($m, ['cash', 'tap', 'card'], true)) {
            fail('Unknown payment method.');
        }
        $sale = q1('SELECT * FROM sales WHERE id = ?', [(int) ($b['id'] ?? 0)]);
        if (!$sale) {
            fail('Sale not found.', 404);
        }
        if ($sale['status'] === 'pending' && $sale['method'] === 'ziina' && refresh_ziina_sale($sale, $u['id']) === 'paid') {
            json_out(['sale' => load_sale('id', $sale['id']), 'already' => true]);
        }
        if (!in_array($sale['status'], ['pending', 'cancelled', 'failed'], true)) {
            json_out(['sale' => load_sale('id', $sale['id']), 'already' => true]);
        }
        $total = (int) $sale['total'];
        $given = null;
        $change = null;
        if ($m === 'cash') {
            $given = isset($b['cash_given']) && $b['cash_given'] !== '' ? to_fils($b['cash_given']) : $total;
            if ($given < $total) {
                fail('Cash received is less than the total.');
            }
            $change = $given - $total;
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            q("UPDATE sales SET method = ?, cash_given = ?, change_due = ?, payment_ref = ?, status = 'pending',
                 note = CASE WHEN note LIKE 'Cancelled:%' THEN '' ELSE note END WHERE id = ?",
              [$m, $given, $change, text_in($b['payment_ref'] ?? '', 64), $sale['id']]);
            mark_paid((int) $sale['id'], $u['id']);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        json_out(['sale' => load_sale('id', $sale['id'])]);

    case 'coupon_check':
        // Is this code valid at fomaxo.com right now? (The sale checks it again when paid.)
        need_post();
        require_user();
        $u = current_user();
        $c = coupon_lookup($b['code'] ?? '', normalize_phone($b['phone'] ?? ''), resolve_store($u, $b['store_id'] ?? 0));
        if ($c['kind'] === 'free' && !$c['free']['product_id']) {
            fail('This coupon gives a free product that is not in the store system yet.');
        }
        json_out(['coupon' => $c]);

    case 'coupons':
        require_manager();
        $rows = q('SELECT c.*, p.name AS free_name, p.variant AS free_variant, st.name AS store_name FROM coupons c
                   LEFT JOIN products p ON p.id = c.free_product_id LEFT JOIN stores st ON st.id = c.store_id ORDER BY c.active DESC, c.created_at DESC')->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['id' => (int) $r['id'], 'code' => $r['code'], 'kind' => $r['kind'], 'amount' => (int) $r['amount'], 'min_order' => (int) $r['min_order'],
                'free_product_id' => $r['free_product_id'] === null ? null : (int) $r['free_product_id'], 'ends' => $r['ends'] ? substr($r['ends'], 0, 10) : '',
                'max_uses' => $r['max_uses'] === null ? null : (int) $r['max_uses'], 'store_id' => $r['store_id'] === null ? null : (int) $r['store_id'],
                'store_name' => $r['store_name'], 'active' => (int) $r['active'], 'note' => $r['note'], 'label' => store_coupon_label($r), 'uses' => coupon_uses($r['code'])];
        }
        json_out(['coupons' => $out, 'web' => (bool) meta_get('set_web_coupons')]);

    case 'coupon_save':
        need_post();
        $u = require_manager();
        $id = (int) ($b['id'] ?? 0);
        $code = coupon_norm($b['code'] ?? '');
        if (strlen($code) < 3) {
            fail('Code needs at least 3 letters or numbers (no spaces).');
        }
        if (q1('SELECT id FROM coupons WHERE code = ? AND id <> ?', [$code, $id])) {
            fail('That code already exists.');
        }
        $kind = in_array($b['kind'] ?? '', ['pct', 'aed', 'free'], true) ? $b['kind'] : 'pct';
        $amount = $kind === 'pct' ? (int) round(((float) ($b['amount'] ?? 0)) * 100) : to_fils($b['amount'] ?? 0);
        if ($kind === 'pct' && ($amount <= 0 || $amount > 10000)) {
            fail('Percent must be between 1 and 100.');
        }
        if ($kind === 'aed' && $amount <= 0) {
            fail('Enter the AED amount off.');
        }
        $freeId = $kind === 'free' ? (int) ($b['free_product_id'] ?? 0) : null;
        if ($kind === 'free' && !q1('SELECT id FROM products WHERE id = ?', [$freeId])) {
            fail('Choose the free product.');
        }
        $ends = trim((string) ($b['ends'] ?? ''));
        if ($ends !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ends)) {
            fail('Wrong end date.');
        }
        $data = [
            'code' => $code, 'kind' => $kind, 'amount' => $kind === 'free' ? 0 : $amount, 'min_order' => max(0, to_fils($b['min_order'] ?? 0)),
            'free_product_id' => $freeId, 'ends' => $ends !== '' ? $ends . ' 23:59:59' : null,
            'max_uses' => isset($b['max_uses']) && $b['max_uses'] !== '' ? max(1, (int) $b['max_uses']) : null,
            'store_id' => !empty($b['store_id']) ? (int) $b['store_id'] : null, 'active' => empty($b['active']) ? 0 : 1,
            'note' => text_in($b['note'] ?? '', 120),
        ];
        if ($id) {
            $sets = implode(', ', array_map(function ($k) { return "$k = ?"; }, array_keys($data)));
            q("UPDATE coupons SET $sets WHERE id = ?", array_merge(array_values($data), [$id]));
        } else {
            $data['created_at'] = now();
            $id = insert('coupons', $data);
        }
        log_activity($u, 'coupon', ($b['id'] ?? 0 ? 'Updated' : 'Created') . ' coupon ' . $code . ' (' . store_coupon_label($data) . ')' . ($data['active'] ? '' : ', switched off'));
        json_out(['ok' => true, 'id' => $id]);

    case 'ziina_start':
        need_post();
        $u = require_user();
        if (!ziina_enabled()) {
            fail('Ziina is not set up yet. Add your Ziina key in config.php.');
        }
        $id = create_sale($u, $b, 'ziina');
        log_activity($u, 'ziina', 'Showed Ziina QR', q1('SELECT receipt_no FROM sales WHERE id = ?', [$id])['receipt_no'], resolve_store($u, $b['store_id'] ?? 0));
        $sale = q1('SELECT * FROM sales WHERE id = ?', [$id]);
        if ($sale['status'] !== 'pending') {
            json_out(['sale' => load_sale('id', $id)]);
        }
        try {
            $z = ziina_intent_for($sale);
        } catch (Throwable $e) {
            q("UPDATE sales SET status = 'failed' WHERE id = ?", [$id]);
            throw $e;
        }
        q('UPDATE sales SET ziina_id = ? WHERE id = ?', [$z['id'], $id]);
        ziina_note_host($z['redirect_url']);
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
        // Any unpaid sale (waiting, cancelled or failed) can be cancelled (again) with a reason.
        if (!in_array($sale['status'], ['pending', 'cancelled', 'failed'], true)) {
            json_out(['status' => $sale['status'], 'sale' => load_sale('id', $sale['id'])]);
        }
        $reason = text_in($b['reason'] ?? '', 200);
        q("UPDATE sales SET status = 'cancelled', note = ? WHERE id = ? AND status IN ('pending', 'cancelled', 'failed')", [$reason !== '' ? 'Cancelled: ' . $reason : '', $sale['id']]);
        log_activity($u, 'cancel', ($sale['status'] === 'pending' ? 'Cancelled ' : 'Cancelled again: ') . method_name($sale['method']) . ' sale of AED ' . money((int) $sale['total']) . ($reason !== '' ? ': ' . $reason : ''), '', (int) $sale['store_id']);
        json_out(['status' => 'cancelled']);

    case 'ziina_retry':
        // Pay again: a fresh Ziina QR for a sale that is still waiting, or was cancelled or failed.
        need_post();
        $u = require_user();
        if (!ziina_enabled()) {
            fail('Ziina is not set up yet. Add your Ziina key in Settings.');
        }
        $sale = q1('SELECT * FROM sales WHERE id = ?', [(int) ($b['id'] ?? 0)]);
        if (!$sale) {
            fail('Sale not found.', 404);
        }
        if ($u['role'] !== 'manager' && (int) $sale['user_id'] !== (int) $u['id'] && !q1('SELECT id FROM users WHERE id = ? AND (store_id IS NULL OR store_id = ?)', [$u['id'], $sale['store_id']])) {
            fail('This sale belongs to another store.', 403);
        }
        // The customer may have paid the old QR after all.
        if ($sale['method'] === 'ziina' && ($sale['status'] === 'pending' || $sale['status'] === 'cancelled')) {
            $was = $sale['status'];
            if ($was === 'cancelled' && $sale['ziina_id']) {
                $zs = ziina_status($sale['ziina_id']);
                if ($zs === 'completed') {
                    q("UPDATE sales SET status = 'pending' WHERE id = ?", [$sale['id']]);
                    $sale['status'] = 'pending';
                }
            }
            if ($sale['status'] === 'pending' && refresh_ziina_sale($sale, $u['id']) === 'paid') {
                json_out(['sale' => load_sale('id', $sale['id'])]);
            }
        }
        if ($sale['status'] === 'paid' || $sale['status'] === 'void') {
            json_out(['sale' => load_sale('id', $sale['id'])]);
        }
        if ((int) $sale['total'] < 200) {
            fail('Ziina needs at least AED 2.00.');
        }
        $z = ziina_intent_for($sale);
        q("UPDATE sales SET method = 'ziina', status = 'pending', ziina_id = ?, note = CASE WHEN note LIKE 'Cancelled:%' THEN '' ELSE note END WHERE id = ?", [$z['id'], $sale['id']]);
        log_activity($u, 'ziina', 'Showed Ziina QR again', '', (int) $sale['store_id']);
        ziina_note_host($z['redirect_url']);
        json_out(['sale' => load_sale('id', $sale['id']), 'pay_url' => $z['redirect_url']]);

    // ---------------- Sales history ----------------
    case 'sales':
        $u = require_user();
        // QR sales still "Waiting" (the QR screen was closed): ask Ziina if the customer has paid since.
        check_waiting_ziina($u, 10);
        [$from, $to] = date_range();
        $params = [$from, $to];
        $where = 's.created_at BETWEEN ? AND ?';
        if ($u['role'] !== 'manager') {
            $where .= ' AND s.user_id = ?';
            $params[] = $u['id'];
        } elseif (!empty($_GET['store']) && $_GET['store'] !== 'all') {
            $where .= ' AND s.store_id = ?';
            $params[] = (int) $_GET['store'];
        }
        $rows = q(
            "SELECT s.id, s.receipt_no, s.total, s.method, s.status, s.offline, s.created_at, u.name AS staff_name,
                    c.name AS customer_name, c.phone AS customer_phone, st.name AS store_name,
                    (SELECT SUM(qty) FROM sale_items i WHERE i.sale_id = s.id) AS items
             FROM sales s JOIN users u ON u.id = s.user_id LEFT JOIN customers c ON c.id = s.customer_id
             LEFT JOIN stores st ON st.id = s.store_id
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
        $w = q1("SELECT * FROM sales WHERE id = ? AND status = 'pending' AND method = 'ziina'", [(int) ($_GET['id'] ?? 0)]);
        if ($w) {
            try {
                refresh_ziina_sale($w, $u['id']);
            } catch (Throwable $e) { /* Ziina not reachable: show it as waiting */ }
        }
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
        $reason = text_in($b['reason'] ?? '', 200);
        if ($reason === '') {
            fail('Please give a reason.');
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $st = q("UPDATE sales SET status = 'void', note = ? WHERE id = ? AND status = 'paid'", ['VOID: ' . $reason, $s['id']]);
            if ($st->rowCount() === 1 && !empty($b['restock'])) {
                foreach (q('SELECT product_id, qty FROM sale_items WHERE sale_id = ?', [$s['id']])->fetchAll() as $it) {
                    move_stock((int) $it['product_id'], (int) $s['store_id'], $u['id'], (int) $it['qty'], 'void', $s['receipt_no'], $s['id']);
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        log_activity($u, 'void', 'Voided sale of AED ' . money((int) $s['total']) . ': ' . $reason, $s['receipt_no'], (int) $s['store_id']);
        json_out(['sale' => load_sale('id', $s['id'])]);

    case 'sale_delete':
        // Managers only: remove one order completely. Any stock it still holds goes back on the shelf.
        // If it was the store's newest numbered order, its number is reused so the count has no gap.
        need_post();
        $u = require_manager();
        $s = q1('SELECT * FROM sales WHERE id = ?', [(int) ($b['id'] ?? 0)]);
        if (!$s) {
            fail('Order not found.', 404);
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $back = 0;
            foreach (q('SELECT product_id, store_id, SUM(qty_change) AS chg FROM stock_moves WHERE sale_id = ? GROUP BY product_id, store_id', [$s['id']])->fetchAll() as $mv) {
                $taken = -(int) $mv['chg'];
                if ($taken > 0) {
                    move_stock((int) $mv['product_id'], (int) ($mv['store_id'] ?: $s['store_id']), $u['id'], $taken, 'count', 'Deleted order ' . $s['receipt_no']);
                    $back += $taken;
                }
            }
            q('UPDATE stock_moves SET sale_id = NULL WHERE sale_id = ?', [$s['id']]);
            q('DELETE FROM sale_items WHERE sale_id = ?', [$s['id']]);
            q('DELETE FROM sales WHERE id = ?', [$s['id']]);
            $reused = false;
            if (preg_match('/^FMX-[A-Z0-9]+-(\d+)$/', $s['receipt_no'], $mm) && $s['store_id']) {
                $st = q1('SELECT next_no FROM stores WHERE id = ?', [$s['store_id']]);
                if ($st && (int) $st['next_no'] - 1 === (int) $mm[1]) {
                    q('UPDATE stores SET next_no = next_no - 1 WHERE id = ?', [$s['store_id']]);
                    $reused = true;
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        log_activity($u, 'void', 'Deleted order ' . $s['receipt_no'] . ' (' . $s['status'] . ', AED ' . money((int) $s['total']) . ')' . ($back ? ', ' . $back . ' item' . ($back === 1 ? '' : 's') . ' back in stock' : '') . ($reused ? ', number will be reused' : ''), '', (int) $s['store_id']);
        json_out(['ok' => true, 'stock_back' => $back, 'reused' => $reused]);

    // ---------------- Reports ----------------
    case 'report':
        require_manager();
        [$from, $to] = date_range();
        $p = [$from, $to];
        $sf = '';
        if (!empty($_GET['store']) && $_GET['store'] !== 'all') {
            $sf = ' AND s.store_id = ' . (int) $_GET['store'];
        }
        $base = "FROM sales s WHERE s.status = 'paid' AND s.created_at BETWEEN ? AND ?$sf";
        $sum = q1("SELECT COUNT(*) AS sales, COALESCE(SUM(total),0) AS total, COALESCE(SUM(vat),0) AS vat, COALESCE(SUM(discount),0) AS discount $base", $p);
        $byMethod = q("SELECT method, COUNT(*) AS sales, SUM(total) AS total $base GROUP BY method", $p)->fetchAll();
        $byStaff = q("SELECT u.name, COUNT(*) AS sales, SUM(s.total) AS total FROM sales s JOIN users u ON u.id = s.user_id
                      WHERE s.status = 'paid' AND s.created_at BETWEEN ? AND ?$sf GROUP BY u.id, u.name ORDER BY total DESC", $p)->fetchAll();
        $byStore = q("SELECT st.name, COUNT(*) AS sales, SUM(s.total) AS total FROM sales s JOIN stores st ON st.id = s.store_id
                      WHERE s.status = 'paid' AND s.created_at BETWEEN ? AND ?$sf GROUP BY st.id, st.name ORDER BY total DESC", $p)->fetchAll();
        $byDay = q("SELECT DATE(s.created_at) AS day, COUNT(*) AS sales, SUM(s.total) AS total $base GROUP BY DATE(s.created_at) ORDER BY day", $p)->fetchAll();
        $top = q("SELECT i.name, i.variant, SUM(i.qty) AS qty, SUM(i.line_total) AS total FROM sale_items i JOIN sales s ON s.id = i.sale_id
                  WHERE s.status = 'paid' AND s.created_at BETWEEN ? AND ?$sf GROUP BY i.product_id, i.name, i.variant ORDER BY qty DESC, total DESC LIMIT 10", $p)->fetchAll();
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
            'by_store' => $toInt($byStore),
            'by_day' => $toInt($byDay),
            'top' => $toInt($top),
        ]);

    // ---------------- Staff accounts ----------------
    case 'users':
        require_manager();
        $rows = q('SELECT id, name, username, role, active, store_id, created_at FROM users WHERE removed = 0 ORDER BY active DESC, name')->fetchAll();
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['store_id'] = $r['store_id'] === null ? null : (int) $r['store_id'];
            $r['active'] = (int) $r['active'];
        }
        $gone = q('SELECT id, name, username, role FROM users WHERE removed = 1 ORDER BY name')->fetchAll();
        foreach ($gone as &$g) {
            $g['id'] = (int) $g['id'];
            $g['username'] = preg_replace('/~\d+$/', '', $g['username']);
        }
        json_out(['users' => $rows, 'removed' => $gone]);

    case 'user_forget':
        // Remove forever: cannot be reactivated. If they ever sold or changed stock, their name
        // stays on those old records (receipts, reports); otherwise the account is deleted completely.
        need_post();
        $me = require_manager();
        $id = (int) ($b['id'] ?? 0);
        $x = q1('SELECT * FROM users WHERE id = ? AND removed = 1', [$id]);
        if (!$x) {
            fail('Staff member not found.');
        }
        $used = q1('SELECT id FROM sales WHERE user_id = ? LIMIT 1', [$id]) || q1('SELECT id FROM stock_moves WHERE user_id = ? LIMIT 1', [$id])
            || q1('SELECT id FROM activity WHERE user_id = ? LIMIT 1', [$id]);
        if ($used) {
            q('UPDATE users SET removed = 2, active = 0 WHERE id = ?', [$id]);
        } else {
            q('DELETE FROM tokens WHERE user_id = ?', [$id]);
            q('DELETE FROM users WHERE id = ?', [$id]);
        }
        log_activity($me, 'staff', 'Removed forever: ' . $x['name']);
        json_out(['ok' => true]);

    case 'user_restore':
        // Bring a removed staff member back. They log in with their old password.
        need_post();
        $me = require_manager();
        $id = (int) ($b['id'] ?? 0);
        $x = q1('SELECT * FROM users WHERE id = ? AND removed = 1', [$id]);
        if (!$x) {
            fail('Staff member not found.');
        }
        $username = strtolower(trim((string) ($b['username'] ?? '')));
        if ($username === '') {
            $username = strtolower(preg_replace('/~\d+$/', '', $x['username']));
        }
        if (!preg_match('/^[a-z0-9._-]{3,50}$/', $username)) {
            fail('Username needs 3+ letters or numbers, no spaces.');
        }
        if (q1('SELECT id FROM users WHERE LOWER(username) = ? AND id <> ?', [$username, $id])) {
            json_out(['error' => 'The username ' . username_case($username) . ' is now used by someone else. Choose a new one.', 'username_taken' => true], 400);
        }
        q('UPDATE users SET removed = 0, active = 1, username = ? WHERE id = ?', [username_case($username), $id]);
        log_activity($me, 'staff', 'Reactivated staff: ' . $x['name'] . ' (' . username_case($username) . ')');
        json_out(['ok' => true]);

    case 'user_save':
        need_post();
        $me = require_manager();
        $id = (int) ($b['id'] ?? 0);
        $name = text_in($b['name'] ?? '', 100);
        $username = strtolower(trim((string) ($b['username'] ?? '')));
        $role = ($b['role'] ?? 'staff') === 'manager' ? 'manager' : 'staff';
        $active = empty($b['active']) ? 0 : 1;
        $pass = (string) ($b['password'] ?? '');
        $userStore = (int) ($b['store_id'] ?? 0);
        $userStore = $userStore && q1('SELECT id FROM stores WHERE id = ?', [$userStore]) ? $userStore : null;
        if ($name === '' || !preg_match('/^[a-z0-9._-]{3,50}$/', $username)) {
            fail('Enter a name and a username (3+ letters/numbers, no spaces).');
        }
        $clash = q1('SELECT id FROM users WHERE LOWER(username) = ? AND id <> ?', [$username, $id]);
        $name = name_case($name);
        if ($clash) {
            fail('That username is already taken.');
        }
        if ($id === $me['id'] && (!$active || $role !== 'manager')) {
            fail('You cannot remove your own manager access.');
        }
        if ($id && !q1('SELECT id FROM users WHERE id = ? AND removed = 0', [$id])) {
            fail('This staff member was removed.');
        }
        if ($id) {
            q('UPDATE users SET name = ?, username = ?, role = ?, active = ?, store_id = ? WHERE id = ?', [$name, username_case($username), $role, $active, $userStore, $id]);
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
                'username' => username_case($username),
                'pass_hash' => password_hash($pass, PASSWORD_DEFAULT),
                'role' => $role,
                'active' => $active,
                'store_id' => $userStore,
                'created_at' => now(),
            ]);
        }
        log_activity($me, 'staff', ($b['id'] ?? 0 ? 'Updated' : 'Added') . ' staff account: ' . $name . ' (' . $role . ($active ? '' : ', switched off') . ')' . ($pass !== '' && ($b['id'] ?? 0) ? ', new password' : ''));
        json_out(['ok' => true, 'id' => $id]);

    case 'user_remove':
        // Removed staff can no longer log in and leave the staff list. Their past sales,
        // receipts and activity stay in the reports under their name.
        need_post();
        $me = require_manager();
        $id = (int) ($b['id'] ?? 0);
        $x = q1('SELECT * FROM users WHERE id = ? AND removed = 0', [$id]);
        if (!$x) {
            fail('Staff member not found.');
        }
        if ($id === (int) $me['id']) {
            fail('You cannot remove yourself.');
        }
        // Free the username so it can be given to someone new later.
        $freed = substr($x['username'], 0, 40) . '~' . $id;
        q('UPDATE users SET removed = 1, active = 0, username = ? WHERE id = ?', [$freed, $id]);
        q('DELETE FROM tokens WHERE user_id = ?', [$id]);
        log_activity($me, 'staff', 'Removed staff: ' . $x['name'] . ' (' . $x['username'] . ')');
        json_out(['ok' => true]);

    // ---------------- Stores ----------------
    case 'stores':
        $u = require_user();
        json_out(['stores' => stores_list($u['role'] !== 'manager')]);

    case 'store_save':
        need_post();
        require_manager();
        $id = (int) ($b['id'] ?? 0);
        $data = [
            'name' => text_in($b['name'] ?? '', 80),
            'address' => text_in($b['address'] ?? '', 255),
            'phone' => text_in($b['phone'] ?? '', 40),
            'active' => empty($b['active']) ? 0 : 1,
            'code' => clean_store_code($b['code'] ?? ''),
        ];
        if ($data['name'] === '') {
            fail('Store name is needed.');
        }
        if ($data['code'] === '') {
            $data['code'] = store_code_from_name($data['name']);
        }
        if (strlen($data['code']) < 2) {
            fail('Store code needs 2 to 6 letters or numbers.');
        }
        if (q1('SELECT id FROM stores WHERE code = ? AND id <> ?', [$data['code'], $id])) {
            fail('Another store already uses the code ' . $data['code'] . '.');
        }
        if ($id) {
            if (!q1('SELECT id FROM stores WHERE id = ?', [$id])) {
                fail('Store not found.', 404);
            }
            if (!$data['active'] && !q1('SELECT id FROM stores WHERE active = 1 AND id <> ?', [$id])) {
                fail('At least one store has to stay open.');
            }
            q('UPDATE stores SET name = ?, address = ?, phone = ?, active = ?, code = ? WHERE id = ?', [$data['name'], $data['address'], $data['phone'], $data['active'], $data['code'], $id]);
        } else {
            $data['created_at'] = now();
            $id = insert('stores', $data);
        }
        if (isset($b['staff']) && is_array($b['staff'])) {
            // Ticked staff work at this store; unticked staff who were here can use any store.
            $want = array_map('intval', $b['staff']);
            $moved = [];
            foreach (q('SELECT id, name, store_id FROM users WHERE removed = 0')->fetchAll() as $su) {
                $sid = (int) $su['id'];
                $here = (int) $su['store_id'] === $id;
                if (in_array($sid, $want, true) && !$here) {
                    q('UPDATE users SET store_id = ? WHERE id = ?', [$id, $sid]);
                    $moved[] = $su['name'] . ' moved here';
                } elseif (!in_array($sid, $want, true) && $here) {
                    q('UPDATE users SET store_id = NULL WHERE id = ?', [$sid]);
                    $moved[] = $su['name'] . ' moved out';
                }
            }
            if ($moved) {
                log_activity(current_user(), 'staff', $data['name'] . ': ' . implode(', ', $moved));
            }
        }
        log_activity(current_user(), 'store', 'Saved store: ' . $data['name'] . ($data['active'] ? '' : ' (closed)'));
        json_out(['ok' => true, 'id' => $id, 'stores' => stores_list()]);

    // ---------------- Settings (managers) ----------------
    case 'settings_get':
        require_manager();
        $tok = trim((string) cfg('ziina_token', ''));
        // Is the fomaxo.com customer lookup working? (read-only)
        require_once __DIR__ . '/lib/website.php';
        $web = ['ok' => false, 'orders' => 0, 'why' => ''];
        if (!cfg('website_customers', true)) {
            $web['why'] = 'switched off';
        } elseif ($wpdo = web_db()) {
            try {
                $web = ['ok' => true, 'orders' => (int) $wpdo->query("SELECT COUNT(*) FROM fx_orders WHERE test = 0 AND status NOT IN ('Awaiting payment', 'Cancelled', 'Refunded')")->fetchColumn(), 'why' => ''];
            } catch (Throwable $e) {
                $web['why'] = 'orders table not readable';
            }
        } else {
            $web['why'] = 'website database not reachable';
        }
        $webIn = ['ok' => false, 'orders' => 0, 'why' => ''];
        if ($ipdo = web_in_db()) {
            try {
                $webIn = ['ok' => true, 'orders' => (int) $ipdo->query("SELECT COUNT(*) FROM orders WHERE test = 0 AND status NOT IN ('awaiting', 'cancelled', 'refunded')")->fetchColumn(), 'why' => ''];
            } catch (Throwable $e) {
                $webIn['why'] = 'orders table not readable';
            }
        } else {
            $webIn['why'] = 'fomaxo.in database not reachable';
        }
        json_out([
            'website' => $web,
            'website_in' => $webIn,
            'ziina_mode' => ziina_mode(),
            'ziina_key_saved' => $tok !== '',
            'ziina_key_end' => $tok !== '' ? substr($tok, -4) : '',
            'store_trn' => (string) cfg('store_trn', ''),
            'web_coupons' => (bool) meta_get('set_web_coupons'),
        ]);

    case 'reset_all':
        // Start fresh: delete all store orders (fomaxo.com website orders are never touched).
        // A full backup is written on the server first. Order numbers restart at 10000.
        need_post();
        $u = require_manager();
        if (trim((string) ($b['confirm'] ?? '')) !== 'DELETE') {
            fail('Type DELETE to confirm.');
        }
        $stockMode = in_array($b['stock'] ?? 'keep', ['keep', 'restore', 'five'], true) ? $b['stock'] : 'keep';
        $clrActivity = !empty($b['activity']);
        $clrHistory = !empty($b['history']);
        $clrCustomers = !empty($b['customers']);

        // 1. Backup, saved outside the website folder when possible.
        $backup = ['made_at' => now(), 'by' => $u['name'], 'options' => compact('stockMode', 'clrActivity', 'clrHistory', 'clrCustomers')];
        foreach (['sales', 'sale_items', 'stock_moves', 'activity', 'customers', 'product_stock', 'stores'] as $tbl) {
            $backup[$tbl] = q("SELECT * FROM $tbl")->fetchAll();
        }
        $json = json_encode($backup, JSON_UNESCAPED_UNICODE);
        $name = 'pos-backup-' . date('Y-m-d-His') . '.json.gz';
        $saved = null;
        foreach ([dirname(dirname(__DIR__)) . '/fomaxo-pos-backups', __DIR__ . '/data/backups'] as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0700, true);
            }
            if (is_dir($dir) && @file_put_contents($dir . '/' . $name, gzencode($json, 6), LOCK_EX) !== false) {
                @chmod($dir . '/' . $name, 0600);
                $saved = $dir . '/' . $name;
                break;
            }
        }
        if (!$saved) {
            fail('Could not save a backup first, so nothing was deleted.');
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            // 2. Stock
            if ($stockMode === 'restore') {
                foreach (q("SELECT i.product_id, s.store_id, SUM(i.qty) AS qty FROM sale_items i JOIN sales s ON s.id = i.sale_id
                            WHERE s.status = 'paid' GROUP BY i.product_id, s.store_id")->fetchAll() as $r) {
                    if ((int) $r['qty'] > 0 && $r['store_id']) {
                        move_stock((int) $r['product_id'], (int) $r['store_id'], $u['id'], (int) $r['qty'], 'count', 'Start fresh: stock from deleted sales put back');
                    }
                }
            } elseif ($stockMode === 'five') {
                foreach (q('SELECT id FROM stores WHERE active = 1')->fetchAll() as $st) {
                    foreach (q('SELECT id FROM products WHERE active = 1')->fetchAll() as $p) {
                        $chg = 5 - store_stock((int) $p['id'], (int) $st['id']);
                        if ($chg) {
                            move_stock((int) $p['id'], (int) $st['id'], $u['id'], $chg, 'count', 'Start fresh: set to 5');
                        }
                    }
                }
            }
            // 3. Orders
            $n = (int) q1('SELECT COUNT(*) AS n FROM sales')['n'];
            q('UPDATE stock_moves SET sale_id = NULL WHERE sale_id IS NOT NULL');
            q('DELETE FROM sale_items');
            q('DELETE FROM sales');
            q('UPDATE stores SET next_no = 10000');
            if ($clrHistory) {
                q('DELETE FROM stock_moves');
            }
            if ($clrActivity) {
                q('DELETE FROM activity');
            }
            if ($clrCustomers) {
                q('DELETE FROM customers');
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        log_activity($u, 'settings', 'Started fresh: deleted ' . $n . ' sale' . ($n === 1 ? '' : 's') . ', backup ' . $name);
        json_out(['ok' => true, 'deleted' => $n, 'backup' => $name]);

    case 'settings_save':
        need_post();
        $u = require_manager();
        $changes = [];
        if (isset($b['ziina_token']) && trim((string) $b['ziina_token']) !== '') {
            $tok = preg_replace('/\s+/', '', (string) $b['ziina_token']);
            if (strlen($tok) < 20 || strlen($tok) > 4000) {
                fail('That does not look like a Ziina API key. Copy the whole key from Ziina.');
            }
            meta_set('set_ziina_token', $tok);
            $changes[] = 'Ziina key saved';
        }
        if (!empty($b['remove_ziina_token'])) {
            q("DELETE FROM meta WHERE k = 'set_ziina_token'");
            $changes[] = 'Ziina key removed';
        }
        if (isset($b['ziina_mode'])) {
            $m = in_array($b['ziina_mode'], ['demo', 'test', 'live'], true) ? $b['ziina_mode'] : 'demo';
            app_settings(true);
            if ($m !== 'demo' && trim((string) cfg('ziina_token', '')) === '') {
                fail('Add your Ziina API key first, then choose Test or Live.');
            }
            meta_set('set_ziina_mode', $m);
            $changes[] = 'Ziina mode: ' . $m;
        }
        if (array_key_exists('store_trn', $b)) {
            $trn = preg_replace('/\s+/', '', (string) $b['store_trn']);
            if ($trn !== '' && !preg_match('/^\d{15}$/', $trn)) {
                fail('A UAE TRN has 15 digits.');
            }
            meta_set('set_store_trn', $trn);
            $changes[] = $trn === '' ? 'TRN cleared (no VAT)' : 'TRN set (VAT on)';
        }
        if (array_key_exists('web_coupons', $b)) {
            meta_set('set_web_coupons', empty($b['web_coupons']) ? '' : '1');
            $changes[] = empty($b['web_coupons']) ? 'website coupons off' : 'website coupons on';
        }
        app_settings(true);
        log_activity($u, 'settings', 'Settings: ' . implode(', ', $changes ?: ['no change']));
        json_out(['ok' => true]);

    // ---------------- Owner: team performance & activity ----------------
    case 'team':
        require_manager();
        [$from, $to] = date_range();
        $p = [$from, $to];
        $sf = (!empty($_GET['store']) && $_GET['store'] !== 'all') ? ' AND s.store_id = ' . (int) $_GET['store'] : '';
        $users = q('SELECT u.id, u.name, u.username, u.role, u.active, u.removed, u.store_id, st.name AS store_name,
                           (SELECT MAX(last_used) FROM tokens t WHERE t.user_id = u.id) AS last_seen
                    FROM users u LEFT JOIN stores st ON st.id = u.store_id ORDER BY u.active DESC, u.name')->fetchAll();
        $paid = [];
        foreach (q("SELECT s.user_id, COUNT(*) AS sales, SUM(s.total) AS total, SUM(s.discount) AS discount,
                           MAX(s.created_at) AS last_sale
                    FROM sales s WHERE s.status = 'paid' AND s.created_at BETWEEN ? AND ?$sf GROUP BY s.user_id", $p)->fetchAll() as $r) {
            $paid[(int) $r['user_id']] = $r;
        }
        $items = [];
        foreach (q("SELECT s.user_id, SUM(i.qty) AS qty FROM sale_items i JOIN sales s ON s.id = i.sale_id
                    WHERE s.status = 'paid' AND s.created_at BETWEEN ? AND ?$sf GROUP BY s.user_id", $p)->fetchAll() as $r) {
            $items[(int) $r['user_id']] = (int) $r['qty'];
        }
        $voids = [];
        foreach (q("SELECT s.user_id, COUNT(*) AS n, SUM(s.total) AS total FROM sales s
                    WHERE s.status = 'void' AND s.created_at BETWEEN ? AND ?$sf GROUP BY s.user_id", $p)->fetchAll() as $r) {
            $voids[(int) $r['user_id']] = $r;
        }
        $methods = [];
        foreach (q("SELECT s.user_id, s.method, SUM(s.total) AS total FROM sales s
                    WHERE s.status = 'paid' AND s.created_at BETWEEN ? AND ?$sf GROUP BY s.user_id, s.method", $p)->fetchAll() as $r) {
            $methods[(int) $r['user_id']][$r['method']] = (int) $r['total'];
        }
        $out = [];
        $gonePerf = [];
        foreach ($users as $x) {
            $id = (int) $x['id'];
            $pd = $paid[$id] ?? null;
            if ((int) $x['removed'] === 2) {
                continue; // removed forever
            }
            $x['username'] = preg_replace('/~\d+$/', '', $x['username']);
            $row = [
                'id' => $id, 'name' => $x['name'], 'username' => $x['username'], 'role' => $x['role'],
                'active' => (int) $x['active'], 'removed' => (int) $x['removed'], 'store_name' => $x['store_name'],
                'last_seen' => $x['last_seen'],
                'online' => $x['last_seen'] && strtotime($x['last_seen']) > time() - 300,
                'sales' => $pd ? (int) $pd['sales'] : 0,
                'total' => $pd ? (int) $pd['total'] : 0,
                'discount' => $pd ? (int) $pd['discount'] : 0,
                'last_sale' => $pd ? $pd['last_sale'] : null,
                'items' => $items[$id] ?? 0,
                'voids' => isset($voids[$id]) ? (int) $voids[$id]['n'] : 0,
                'void_total' => isset($voids[$id]) ? (int) $voids[$id]['total'] : 0,
                'methods' => (object) ($methods[$id] ?? []),
            ];
            if ((int) $x['removed']) {
                $gonePerf[] = $row; // removed staff: hidden from performance, shown in the Staff box
            } else {
                $out[] = $row;
            }
        }
        usort($out, function ($a, $b) {
            return $b['total'] <=> $a['total'] ?: strcmp($a['name'], $b['name']);
        });
        usort($gonePerf, function ($a, $b) { return strcmp($a['name'], $b['name']); });
        json_out(['team' => $out, 'removed' => $gonePerf]);

    case 'activity':
        require_manager();
        [$from, $to] = date_range();
        $params = [$from, $to];
        $where = 'a.created_at BETWEEN ? AND ?';
        if (!empty($_GET['user'])) {
            $where .= ' AND a.user_id = ?';
            $params[] = (int) $_GET['user'];
        }
        if (!empty($_GET['store']) && $_GET['store'] !== 'all') {
            $where .= ' AND a.store_id = ?';
            $params[] = (int) $_GET['store'];
        }
        $rows = q("SELECT a.action, a.detail, a.ref, a.created_at, u.name AS user_name, st.name AS store_name
                   FROM activity a JOIN users u ON u.id = a.user_id LEFT JOIN stores st ON st.id = a.store_id
                   WHERE $where ORDER BY a.id DESC LIMIT 400", $params)->fetchAll();
        json_out(['activity' => $rows]);

    default:
        fail('Unknown request.', 404);
}
