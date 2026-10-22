<?php
// Sales: creating, paying, voiding and loading sales. Stock moves only when a sale is paid.

function stores_list($activeOnly = false)
{
    $rows = q('SELECT * FROM stores' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY id')->fetchAll();
    return array_map(function ($r) {
        return ['id' => (int) $r['id'], 'name' => $r['name'], 'address' => $r['address'], 'phone' => $r['phone'], 'active' => (int) $r['active'], 'code' => (string) ($r['code'] ?? '')];
    }, $rows);
}

/** Which store a request works on. Staff tied to a store always use their own store. */
function resolve_store($user, $requested)
{
    if ($user['role'] !== 'manager' && !empty($user['store_id'])) {
        return (int) $user['store_id'];
    }
    $id = (int) $requested;
    if ($id > 0 && q1('SELECT id FROM stores WHERE id = ? AND active = 1', [$id])) {
        return $id;
    }
    $first = q1('SELECT id FROM stores WHERE active = 1 ORDER BY id LIMIT 1');
    if (!$first) {
        fail('No store is set up. A manager can add one under Staff > Stores.');
    }
    return (int) $first['id'];
}

function store_stock($productId, $storeId)
{
    $r = q1('SELECT stock FROM product_stock WHERE product_id = ? AND store_id = ?', [$productId, $storeId]);
    return $r ? (int) $r['stock'] : 0;
}

function normalize_phone($p)
{
    $p = preg_replace('/[^0-9+]/', '', (string) $p);
    if ($p === '') {
        return '';
    }
    // UAE: 05x xxx xxxx -> +9715x xxx xxxx
    if (preg_match('/^05\d{8}$/', $p)) {
        $p = '+971' . substr($p, 1);
    } elseif (preg_match('/^9715\d{8}$/', $p)) {
        $p = '+' . $p;
    } elseif (strpos($p, '00') === 0) {
        $p = '+' . substr($p, 2);
    }
    return $p;
}

function clean_email($e)
{
    $e = strtolower(trim((string) $e));
    return filter_var($e, FILTER_VALIDATE_EMAIL) ? mb_substr($e, 0, 120) : '';
}

/** A customer is found by mobile, or by email when there is no mobile. Name and email are updated when given. */
function find_or_create_customer($phone, $name, $email = '')
{
    $phone = normalize_phone($phone);
    $email = clean_email($email);
    $name = trim(preg_replace('/\s+/', ' ', mb_substr((string) $name, 0, 100)));
    if ($name !== '' && preg_match('/^[\p{Latin}\s\'\-.]+$/u', $name)) {
        $name = mb_strtoupper($name, 'UTF-8'); // aisha khan -> AISHA KHAN
    }
    if (strlen(preg_replace('/\D/', '', $phone)) < 7) {
        if ($email === '') {
            return null;
        }
        $phone = 'e:' . $email; // email-only customer
    }
    $c = q1('SELECT * FROM customers WHERE phone = ?', [$phone]);
    if (!$c && $email !== '' && strpos($phone, 'e:') === 0) {
        $c = q1('SELECT * FROM customers WHERE email = ? ORDER BY id LIMIT 1', [$email]);
    }
    if ($c) {
        if ($name !== '' && $name !== $c['name']) {
            q('UPDATE customers SET name = ? WHERE id = ?', [$name, $c['id']]);
        }
        if ($email !== '' && $email !== ($c['email'] ?? '')) {
            q('UPDATE customers SET email = ? WHERE id = ?', [$email, $c['id']]);
        }
        return (int) $c['id'];
    }
    return insert('customers', ['phone' => $phone, 'name' => $name, 'email' => $email, 'created_at' => now()]);
}

function valid_uuid($u)
{
    return is_string($u) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $u);
}

/**
 * Builds and saves a sale from the app's request.
 * $method: cash | card | ziina. Ziina sales stay 'pending' until Ziina confirms payment.
 */
function create_sale($user, $b, $method)
{
    $offline = !empty($b['offline']);
    $uuid = $b['uuid'] ?? '';
    if (!valid_uuid($uuid)) {
        $uuid = uuid4();
    }
    $existing = q1('SELECT id FROM sales WHERE uuid = ?', [$uuid]);
    if ($existing) {
        return (int) $existing['id']; // already saved (e.g. offline sync retried)
    }

    $items = $b['items'] ?? [];
    if (!is_array($items) || !$items) {
        fail('The cart is empty.');
    }
    $qtyById = [];
    $priceById = [];
    foreach ($items as $it) {
        $pid = (int) ($it['product_id'] ?? 0);
        $qty = (int) ($it['qty'] ?? 0);
        if ($pid <= 0 || $qty <= 0 || $qty > 999) {
            fail('A cart line has a wrong quantity.');
        }
        $qtyById[$pid] = ($qtyById[$pid] ?? 0) + $qty;
        if (isset($it['price'])) {
            $priceById[$pid] = (int) $it['price'];
        }
    }
    $storeId = resolve_store($user, $b['store_id'] ?? 0);
    $ids = array_keys($qtyById);
    $rows = q('SELECT * FROM products WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids)->fetchAll();
    $products = [];
    foreach ($rows as $r) {
        $products[(int) $r['id']] = $r;
    }

    $lines = [];
    foreach ($qtyById as $pid => $qty) {
        $p = $products[$pid] ?? null;
        if (!$p) {
            fail('A product in the cart no longer exists. Refresh and try again.');
        }
        if (!$offline) {
            if (!$p['active']) {
                fail($p['name'] . ' is switched off for sale.');
            }
            $have = store_stock($pid, $storeId);
            if ($have < $qty) {
                $left = max(0, $have);
                fail('Not enough stock: only ' . $left . ' left of ' . trim($p['name'] . ' ' . $p['variant']) . '.');
            }
        }
        // Offline sales keep the price the customer was actually charged.
        $price = ($offline && isset($priceById[$pid]) && $priceById[$pid] >= 0) ? $priceById[$pid] : (int) $p['price'];
        $was = (int) ($p['compare_at'] ?? 0);
        $lines[] = ['product_id' => $pid, 'name' => $p['name'], 'variant' => $p['variant'], 'price' => $price, 'qty' => $qty, 'was' => $was > $price ? $was : null];
    }

    // Coupon code (same codes as fomaxo.com). Checked again here; offline sales keep what the till showed.
    $coupon = null;
    $code = trim((string) ($b['coupon'] ?? ''));
    if ($code !== '') {
        require_once __DIR__ . '/coupons.php';
        if ($offline && is_array($b['coupon_info'] ?? null)) {
            $ci = $b['coupon_info'];
            $coupon = ['code' => mb_substr(strtoupper($code), 0, 30), 'kind' => in_array($ci['kind'] ?? '', ['pct', 'aed', 'free'], true) ? $ci['kind'] : 'aed',
                'amount' => (float) ($ci['amount'] ?? 0), 'min' => 0, 'label' => mb_substr((string) ($ci['label'] ?? ''), 0, 80), 'free' => null];
        } else {
            $cphone = is_array($b['customer'] ?? null) ? normalize_phone($b['customer']['phone'] ?? '') : '';
            $coupon = coupon_lookup($code, $cphone, $storeId);
        }
        if ($coupon['kind'] === 'free') {
            $fid = (int) ($coupon['free']['product_id'] ?? 0);
            if (!$fid && $offline) {
                $fid = (int) ($b['coupon_info']['free_product_id'] ?? 0);
            }
            $fp = $fid ? q1('SELECT * FROM products WHERE id = ?', [$fid]) : null;
            if (!$fp) {
                fail('The free gift for coupon ' . $coupon['code'] . ' is not in this store system.');
            }
            if (!$offline) {
                $need = 1 + ($qtyById[$fid] ?? 0);
                if (store_stock($fid, $storeId) < $need) {
                    fail('The free gift (' . trim($fp['name'] . ' ' . $fp['variant']) . ') is out of stock here.');
                }
            }
            $worth = (int) ($coupon['free']['worth'] ?? 0) ?: (int) $fp['price'];
            $lines[] = ['product_id' => $fid, 'name' => $fp['name'], 'variant' => $fp['variant'], 'price' => 0, 'qty' => 1, 'was' => $worth, 'free' => 1];
        }
    }

    $discount = $b['discount'] ?? null;
    $t0 = calc_totals($lines, $discount);
    $couponFils = $coupon ? coupon_discount($coupon, $t0['subtotal'] - $t0['discount']) : 0;
    $t = calc_totals($lines, $discount, $couponFils);
    if ($user['role'] !== 'manager' && $t['discount'] > 0 && $t['subtotal'] > 0) {
        $pct = $t['discount'] * 100 / $t['subtotal'];
        if ($pct > (float) cfg('staff_max_discount', 10) + 0.001) {
            fail('Staff discounts are limited to ' . cfg('staff_max_discount', 10) . '%. Ask a manager.');
        }
    }
    if ($t['total'] <= 0 && $method === 'ziina') {
        fail('Total must be more than zero.');
    }
    if ($method === 'ziina' && $t['total'] < 200) {
        fail('Ziina needs at least AED 2.00. Take cash for this sale.');
    }

    $cashGiven = null;
    $change = null;
    if ($method === 'cash') {
        $cashGiven = isset($b['cash_given']) && $b['cash_given'] !== '' ? to_fils($b['cash_given']) : $t['total'];
        if ($cashGiven < $t['total'] && !$offline) {
            fail('Cash received is less than the total.');
        }
        $change = max(0, $cashGiven - $t['total']);
    }

    $createdAt = now();
    if ($offline && !empty($b['created_at']) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $b['created_at'])) {
        if (strtotime($b['created_at']) <= time() + 300) {
            $createdAt = $b['created_at'];
        }
    }

    $token = $b['receipt_token'] ?? '';
    if (!is_string($token) || !preg_match('/^[0-9a-f]{32}$/', $token)) {
        $token = bin2hex(random_bytes(16));
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $customerId = null;
        $cust = $b['customer'] ?? null;
        if (is_array($cust) && (!empty($cust['phone']) || !empty($cust['email']))) {
            $customerId = find_or_create_customer($cust['phone'] ?? '', $cust['name'] ?? '', $cust['email'] ?? '');
        }
        $saleId = insert('sales', [
            'uuid' => $uuid,
            'receipt_no' => 'NOT PAID', // the order number is given only when the payment goes through (mark_paid), so there are no gaps
            'receipt_token' => $token,
            'user_id' => $user['id'],
            'store_id' => $storeId,
            'customer_id' => $customerId,
            'subtotal' => $t['subtotal'],
            'discount' => $t['discount'] + $t['coupon'],
            'coupon' => $coupon ? $coupon['code'] : '',
            'coupon_label' => $coupon ? $coupon['label'] : '',
            'coupon_disc' => $t['coupon'],
            'vat' => $t['vat'],
            'total' => $t['total'],
            'method' => $method,
            'status' => 'pending',
            'cash_given' => $cashGiven,
            'change_due' => $change,
            'note' => mb_substr((string) ($b['note'] ?? ''), 0, 255),
            'payment_ref' => mb_substr(trim((string) ($b['payment_ref'] ?? '')), 0, 64),
            'offline' => $offline ? 1 : 0,
            'created_at' => $createdAt,
        ]);
        foreach ($lines as $l) {
            insert('sale_items', [
                'sale_id' => $saleId,
                'product_id' => $l['product_id'],
                'name' => $l['name'],
                'variant' => $l['variant'],
                'price' => $l['price'],
                'qty' => $l['qty'],
                'line_total' => $l['price'] * $l['qty'],
                'was' => $l['was'],
                'free' => empty($l['free']) ? 0 : 1,
            ]);
        }
        if ($method !== 'ziina') {
            mark_paid($saleId, $user['id'], $offline ? $createdAt : null);
        }
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        $again = q1('SELECT id FROM sales WHERE uuid = ?', [$uuid]);
        if ($again) {
            return (int) $again['id'];
        }
        throw $e;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $saleId;
}

/** Marks a pending sale paid and takes the items out of stock. Safe to call twice. */
function mark_paid($saleId, $userId, $paidAt = null)
{
    $st = q("UPDATE sales SET status = 'paid', paid_at = ? WHERE id = ? AND status = 'pending'", [$paidAt ?: now(), $saleId]);
    if ($st->rowCount() !== 1) {
        return false;
    }
    $sale = q1('SELECT store_id, receipt_no, total, method, offline, user_id FROM sales WHERE id = ?', [$saleId]);
    $storeId = (int) $sale['store_id'];
    if (strpos($sale['receipt_no'], 'FMX-') !== 0) {
        $sale['receipt_no'] = next_order_no($storeId);
        q('UPDATE sales SET receipt_no = ? WHERE id = ?', [$sale['receipt_no'], $saleId]);
    }
    $mname = ['cash' => 'Cash', 'tap' => 'Tap to Pay', 'card' => 'Card machine', 'ziina' => 'Ziina QR'][$sale['method']] ?? $sale['method'];
    log_activity((int) $sale['user_id'], 'sale', 'Sold AED ' . money((int) $sale['total']) . ' by ' . $mname . ($sale['offline'] ? ' (made offline)' : ''), $sale['receipt_no'], $storeId);
    $items = q('SELECT product_id, qty FROM sale_items WHERE sale_id = ?', [$saleId])->fetchAll();
    foreach ($items as $it) {
        move_stock((int) $it['product_id'], $storeId, $userId, -(int) $it['qty'], 'sale', '', $saleId);
    }
    return true;
}

function move_stock($productId, $storeId, $userId, $change, $reason, $note = '', $saleId = null)
{
    $st = q('UPDATE product_stock SET stock = stock + ? WHERE product_id = ? AND store_id = ?', [$change, $productId, $storeId]);
    if ($st->rowCount() === 0) {
        insert('product_stock', ['product_id' => $productId, 'store_id' => $storeId, 'stock' => $change]);
    }
    // products.stock keeps the total across all stores
    q('UPDATE products SET stock = stock + ?, updated_at = ? WHERE id = ?', [$change, now(), $productId]);
    $after = store_stock($productId, $storeId);
    insert('stock_moves', [
        'product_id' => $productId,
        'store_id' => $storeId,
        'user_id' => $userId,
        'qty_change' => $change,
        'stock_after' => $after,
        'reason' => $reason,
        'note' => mb_substr((string) $note, 0, 255),
        'sale_id' => $saleId,
        'created_at' => now(),
    ]);
    return $after;
}

/** Checks a pending Ziina sale with Ziina and updates it. Returns the sale status. */
function refresh_ziina_sale($sale, $userId)
{
    if ($sale['method'] !== 'ziina' || $sale['status'] !== 'pending' || !$sale['ziina_id']) {
        return $sale['status'];
    }
    $zs = ziina_status($sale['ziina_id']);
    if ($zs === 'completed') {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            mark_paid((int) $sale['id'], $userId);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return 'paid';
    }
    if ($zs === 'failed' || $zs === 'canceled') {
        q("UPDATE sales SET status = ? WHERE id = ? AND status = 'pending'", [$zs === 'failed' ? 'failed' : 'cancelled', $sale['id']]);
        return $zs === 'failed' ? 'failed' : 'cancelled';
    }
    return 'pending';
}

function load_sale($where, $param)
{
    $s = q1(
        "SELECT s.*, u.name AS staff_name, c.phone AS customer_phone, c.name AS customer_name, c.email AS customer_email,
                st.name AS store_name, st.address AS store_address, st.phone AS store_phone
         FROM sales s JOIN users u ON u.id = s.user_id LEFT JOIN customers c ON c.id = s.customer_id
         LEFT JOIN stores st ON st.id = s.store_id
         WHERE s.$where = ?",
        [$param]
    );
    if (!$s) {
        return null;
    }
    $s['items'] = q('SELECT i.product_id, i.name, i.variant, i.price, i.qty, i.line_total, i.was, i.free, p.family
                     FROM sale_items i LEFT JOIN products p ON p.id = i.product_id WHERE i.sale_id = ? ORDER BY i.id', [$s['id']])->fetchAll();
    $s['coupon_disc'] = (int) ($s['coupon_disc'] ?? 0);
    foreach (['id', 'user_id', 'store_id', 'customer_id', 'subtotal', 'discount', 'vat', 'total', 'cash_given', 'change_due', 'offline'] as $k) {
        if ($s[$k] !== null) {
            $s[$k] = (int) $s[$k];
        }
    }
    foreach ($s['items'] as &$it) {
        foreach (['product_id', 'price', 'qty', 'line_total'] as $k) {
            $it[$k] = (int) $it[$k];
        }
        $it['was'] = $it['was'] === null ? null : (int) $it['was'];
        $it['free'] = (int) ($it['free'] ?? 0);
        $it['family'] = (string) ($it['family'] ?? '');
    }
    unset($s['ziina_id']);
    if (strpos((string) $s['customer_phone'], 'e:') === 0) {
        $s['customer_phone'] = '';
    }
    $s['receipt_url'] = cfg('base_url') . '/receipt.php?t=' . $s['receipt_token'];
    if ($s['customer_id']) {
        $spend = customer_year_spend($s['customer_id']);
        $s['customer_tier'] = tier_for($spend);
        $s['customer_year_spend'] = $spend;
    }
    return $s;
}
