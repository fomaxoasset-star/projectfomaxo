<?php
// Coupon codes: the store accepts the same codes as fomaxo.com, checked live with the website.

function coupon_url()
{
    return cfg('website_coupon_url', 'https://fomaxo.com/coupon.php');
}

function coupon_norm($code)
{
    return substr(preg_replace('/[^A-Z0-9_\-]/', '', strtoupper(trim((string) $code))), 0, 30);
}

function store_coupon_label($r)
{
    if ($r['kind'] === 'free') {
        $p = $r['free_product_id'] ? q1('SELECT name, variant FROM products WHERE id = ?', [$r['free_product_id']]) : null;
        return 'Free ' . ($p ? trim(preg_replace('/^fomaxo\s+/i', '', $p['name']) . ' ' . $p['variant']) : 'gift');
    }
    return $r['kind'] === 'pct' ? rtrim(rtrim(number_format($r['amount'] / 100, 2), '0'), '.') . '% off' : 'AED ' . number_format($r['amount'] / 100, 2) . ' off';
}

function coupon_uses($code)
{
    return (int) q1("SELECT COUNT(*) AS n FROM sales WHERE coupon = ? AND status IN ('paid', 'pending')", [$code])['n'];
}

/**
 * Finds a coupon: the store's own codes first (made under Coupons), then - only if switched on in
 * Settings - the codes from fomaxo.com. Returns code, kind pct|aed|free, amount, min, label, free.
 */
function coupon_lookup($code, $phone = '', $storeId = null)
{
    $code = coupon_norm($code);
    if ($code === '') {
        fail('Type the coupon code.');
    }
    $r = q1('SELECT * FROM coupons WHERE code = ?', [$code]);
    if ($r) {
        if (!(int) $r['active']) {
            fail('This coupon code is switched off.');
        }
        if ($r['ends'] && $r['ends'] < now()) {
            fail('This coupon code has expired.');
        }
        if ($r['store_id'] && $storeId && (int) $r['store_id'] !== (int) $storeId) {
            fail('This coupon code is for another store.');
        }
        if ($r['max_uses'] !== null && coupon_uses($code) >= (int) $r['max_uses']) {
            fail('This coupon code has been fully used.');
        }
        $c = ['code' => $code, 'kind' => $r['kind'], 'amount' => $r['kind'] === 'pct' ? $r['amount'] / 100 : $r['amount'] / 100,
            'min' => $r['min_order'] / 100, 'label' => store_coupon_label($r), 'free' => null, 'source' => 'store'];
        if ($r['kind'] === 'free') {
            $p = q1('SELECT id, name, price, active FROM products WHERE id = ?', [(int) $r['free_product_id']]);
            if (!$p) {
                fail('The free gift of this coupon no longer exists.');
            }
            $c['free'] = ['sku' => '', 'product_id' => (int) $p['id'], 'name' => $p['name'], 'worth' => (int) $p['price']];
        }
        return $c;
    }
    if (!meta_get('set_web_coupons')) {
        fail('This coupon code is not valid.');
    }
    return web_coupon_lookup($code, $phone);
}

/** Asks fomaxo.com about a code (only when "Also accept fomaxo.com codes" is on). */
function web_coupon_lookup($code, $phone = '')
{
    $ch = curl_init(coupon_url());
    $payload = ['code' => $code];
    if ($phone !== '') {
        $payload['phone'] = $phone;
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_USERAGENT => 'FOMAXO-Store-System',
    ]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $d = $raw ? json_decode($raw, true) : null;
    if (!is_array($d)) {
        fail('Could not check the coupon with fomaxo.com. Try again.', 502);
    }
    if (!empty($d['error'])) {
        fail((string) $d['error']);
    }
    if ($status !== 200 || empty($d['code']) || !in_array($d['kind'] ?? '', ['pct', 'aed', 'free'], true)) {
        fail('Could not check the coupon with fomaxo.com. Try again.', 502);
    }
    $c = [
        'code' => (string) $d['code'],
        'kind' => $d['kind'],
        'amount' => (float) ($d['amount'] ?? 0),
        'min' => (float) ($d['min'] ?? 0),
        'label' => mb_substr((string) ($d['label'] ?? ''), 0, 80),
        'free' => null,
        'source' => 'web',
    ];
    if ($c['kind'] === 'free' && !empty($d['free']['id'])) {
        $sku = preg_replace('/[^a-z0-9-]/', '', strtolower($d['free']['id'])) . '-' . preg_replace('/[^0-9]/', '', (string) ($d['free']['opt'] ?? ''));
        $p = q1('SELECT id, name, variant, price FROM products WHERE sku = ?', [$sku]);
        $c['free'] = [
            'sku' => $sku,
            'product_id' => $p ? (int) $p['id'] : 0,
            'name' => (string) ($d['free']['name'] ?? ''),
            'worth' => to_fils($d['free']['worth'] ?? ($p['price'] ?? 0) / 100),
        ];
    }
    return $c;
}

/** Money off for a coupon on a bag worth $subtotal (fils). Free-gift coupons give 0 here (the gift line is free). */
function coupon_discount($c, $subtotal)
{
    if (!$c) {
        return 0;
    }
    if ($c['min'] > 0 && $subtotal < to_fils($c['min'])) {
        fail('Coupon ' . $c['code'] . ' needs a bag of at least AED ' . number_format($c['min'], 2) . '.');
    }
    if ($c['kind'] === 'free') {
        return 0;
    }
    if ($c['kind'] === 'pct') {
        return (int) round($subtotal * min(100, max(0, $c['amount'])) / 100);
    }
    return min($subtotal, to_fils($c['amount']));
}
