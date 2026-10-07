<?php
/* FOMAXO — product addresses for Google (used by product.php and sitemap.php).
   Every product gets its own address, made from its name: fomaxo.com/fomaxo-gold, fomaxo.com/fomaxo-old-money, fomaxo.com/fomaxo-discovery-set …
   The list comes from the database (admin → Products); when the database is down, from products-seed.json. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }
require_once __DIR__ . '/orders-lib.php';

const FOMAXO_SITE = 'https://fomaxo.com';

/* all products in shop order, as arrays, each with 'hidden' (true/false) */
function fomaxo_seo_products() {
  $out = [];
  $rows = fomaxo_product_rows();
  if ($rows) {
    foreach ($rows as $r) { $p = json_decode($r['data'], true); if (!is_array($p) || empty($p['id'])) continue; $p['hidden'] = !empty($r['hidden']); $out[] = $p; }
  } else {
    foreach ((array)json_decode((string)@file_get_contents(__DIR__ . '/products-seed.json'), true) as $p) if (is_array($p) && !empty($p['id'])) { $p['hidden'] = !empty($p['hidden']); $out[] = $p; }
  }
  foreach ($out as &$p) {   // every fragrance is unisex (same rule as the website)
    if (($p['kind'] ?? '') !== 'set') $p['family'] = rtrim(trim(preg_replace('/\s*·?\s*unisex\s*/iu', ' ', (string)($p['family'] ?? 'Eau de Parfum'))), ' ·') . ' · Unisex';
  }
  unset($p);
  return $out;
}
function fomaxo_slug($s) { return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower((string)$s)), '-'); }
/* the product's own address, e.g. https://fomaxo.com/fomaxo-old-money */
function fomaxo_seo_url($p) { return FOMAXO_SITE . '/fomaxo-' . (fomaxo_slug($p['name'] ?? '') ?: fomaxo_slug($p['id'])); }
