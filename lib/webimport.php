<?php
// Brings the product list from the fomaxo.com website into the store system.
// One store product per website product + size. Matched by SKU (e.g. "king-100"),
// so running it again only updates names and prices - stock counts are never touched.

function web_products_url()
{
    return cfg('website_products_url', 'https://fomaxo.com/products.php');
}

function fetch_web_products()
{
    $ch = curl_init(web_products_url());
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_USERAGENT => 'FOMAXO-Store-System',
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = $raw ? json_decode($raw, true) : null;
    if ($code !== 200 || !is_array($data) || empty($data['products']) || !is_array($data['products'])) {
        throw new ApiError('Could not read the product list from the website. Try again in a minute.', 502);
    }
    return $data['products'];
}

/** Turns the website list into store products. Returns ['added' => n, 'updated' => n]. */
function import_web_products($products = null)
{
    $products = $products ?? fetch_web_products();
    $low = (int) cfg('default_low_stock', 5);
    $added = 0;
    $updated = 0;
    $names = [];
    $seen = [];
    foreach ($products as $wp) {
        $id = preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($wp['id'] ?? '')));
        $name = trim((string) ($wp['name'] ?? ''));
        if ($id === '' || $name === '' || empty($wp['prices']) || !is_array($wp['prices'])) {
            continue;
        }
        $isSet = ($wp['kind'] ?? '') === 'set';
        $fullName = stripos($name, 'fomaxo') === 0 ? $name : 'FOMAXO ' . $name;
        $names[] = $fullName;
        $category = 'Fragrance';
        $sizes = !empty($wp['sizes']) && is_array($wp['sizes']) ? $wp['sizes'] : array_keys($wp['prices']);
        foreach ($sizes as $size) {
            if (!isset($wp['prices'][(string) $size])) {
                continue;
            }
            $price = to_fils($wp['prices'][(string) $size]);
            $was = isset($wp['compareAt'][(string) $size]) ? to_fils($wp['compareAt'][(string) $size]) : 0;
            $was = $was > $price ? $was : 0;
            $family = mb_substr(trim((string) ($wp['family'] ?? '')), 0, 80);
            $variant = $isSet ? $size . ' vials' : $size . 'ml';
            $sku = $id . '-' . $size;
            $seen[] = $sku;
            $row = q1('SELECT id, price_lock FROM products WHERE sku = ?', [$sku]);
            if ($row && (int) $row['price_lock'] === 1) {
                // Prices set by hand in the store: keep them, only refresh the name and family.
                q('UPDATE products SET name = ?, variant = ?, family = ?, updated_at = ? WHERE id = ?', [$fullName, $variant, $family, now(), $row['id']]);
                $updated++;
            } elseif ($row) {
                q('UPDATE products SET name = ?, variant = ?, category = ?, price = ?, compare_at = ?, family = ?, updated_at = ? WHERE id = ?',
                    [$fullName, $variant, $category, $price, $was, $family, now(), $row['id']]);
                $updated++;
            } else {
                insert('products', [
                    'name' => $fullName, 'variant' => $variant, 'sku' => $sku, 'category' => $category,
                    'price' => $price, 'compare_at' => $was, 'family' => $family, 'stock' => 0, 'low_at' => $low, 'active' => 1,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $added++;
            }
        }
    }
    // Sizes/products taken off the website are switched off here too (stock counts stay).
    $before = json_decode((string) meta_get('web_skus', '[]'), true) ?: [];
    $removed = 0;
    foreach (array_diff($before, $seen) as $gone) {
        $removed += q('UPDATE products SET active = 0, updated_at = ? WHERE sku = ? AND active = 1', [now(), $gone])->rowCount();
    }
    meta_set('web_skus', json_encode(array_values($seen)));
    meta_set('web_sync_at', (string) time());

    // Remove the empty placeholder products from setup (no price, no SKU, never sold) now that the real ones exist.
    if ($added) {
        foreach (q("SELECT id FROM products WHERE sku = '' AND price = 0 AND stock = 0 AND active = 0")->fetchAll() as $ph) {
            $used = q1('SELECT 1 AS x FROM sale_items WHERE product_id = ? LIMIT 1', [$ph['id']]) ?: q1('SELECT 1 AS x FROM stock_moves WHERE product_id = ? LIMIT 1', [$ph['id']]);
            if (!$used) {
                q('DELETE FROM product_stock WHERE product_id = ?', [$ph['id']]);
                q('DELETE FROM products WHERE id = ?', [$ph['id']]);
            }
        }
    }
    return ['added' => $added, 'updated' => $updated, 'removed' => $removed];
}

/** Called while staff use the app: re-reads fomaxo.com at most every 10 minutes, after the reply is sent. */
function auto_sync_web_soon()
{
    if (!cfg('website_auto_sync', true)) {
        return;
    }
    $last = (int) meta_get('web_sync_at', '0');
    if (time() - $last < 600) {
        return;
    }
    meta_set('web_sync_at', (string) time()); // claim this round so only one request does it
    register_shutdown_function(function () {
        while (ob_get_level()) {
            ob_end_flush();
        }
        if (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        } elseif (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        try {
            $r = import_web_products();
            if ($r['added'] || $r['removed']) {
                error_log('FOMAXO POS: website sync added ' . $r['added'] . ', switched off ' . $r['removed']);
            }
        } catch (Throwable $e) {
            error_log('FOMAXO POS: website sync failed: ' . $e->getMessage());
        }
    });
}
