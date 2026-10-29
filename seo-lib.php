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
    foreach ($rows as $r) { $p = fomaxo_sale_apply(json_decode($r['data'], true)); if (!is_array($p) || empty($p['id'])) continue; $p['hidden'] = !empty($r['hidden']); $out[] = $p; }
  } else {
    foreach ((array)json_decode((string)@file_get_contents(__DIR__ . '/products-seed.json'), true) as $p) if (is_array($p) && !empty($p['id'])) { $p['hidden'] = !empty($p['hidden']); $out[] = $p; }
  }
  foreach ($out as &$p) {   // every fragrance is unisex (same rule as the website)
    if (($p['kind'] ?? '') !== 'set') $p['family'] = rtrim(trim(preg_replace('/\s*·?\s*unisex\s*/iu', ' ', (string)($p['family'] ?? 'Eau de Parfum'))), ' ·') . ' · Unisex';
  }
  unset($p);
  return $out;
}
/* a shop photo's full address: built-in names (oldmoney-2) and admin uploads (up/abc123) */
function fomaxo_img_url($k) { return FOMAXO_SITE . '/assets/img/' . implode('/', array_map('rawurlencode', explode('/', (string)$k))) . '.webp'; }
/* the photo of one size, same rule as the website (imgFor): the size's own photo, the 10ml photo, else the main photo */
function fomaxo_size_img($p, $s) {
  $k = $p['sizeImages'][(string)$s] ?? null;
  if (!is_string($k) || $k === '') $k = ((string)$s === '10' && !empty($p['miniImage'])) ? $p['miniImage'] : (($p['images'] ?? [])[0] ?? '');
  return is_string($k) && $k !== '' ? fomaxo_img_url($k) : '';
}
/* the words people type into Google for each perfume (scent and occasion), taken from its notes; used only in Google titles,
   descriptions and the Shopping list, never on the page itself. Products not listed here keep their usual title. [English, Arabic] */
function fomaxo_scent($id, $ar = false) {
  static $w = [
    'king'       => ['Luxury Vanilla & Amber Perfume with Real Gold', 'عطر فاخر بالفانيليا والعنبر بلمسات من الذهب الحقيقي'],
    'gold'       => ['Oud & Leather Arabic Perfume', 'عطر عربي بالعود والجلد'],
    'oldmoney'   => ['Fresh Citrus & Sandalwood Perfume', 'عطر منعش بالحمضيات وخشب الصندل'],
    'royalcandy' => ['Sweet Candy & Praline Gourmand Perfume', 'عطر حلو بالحلوى والبرالين'],
    'dollar'     => ['Fresh Mint & Incense Winter Perfume', 'عطر شتوي منعش بالنعناع والبخور'],
    'matchacoco' => ['Coconut & Vanilla Summer Perfume', 'عطر صيفي بجوز الهند والفانيليا'],
    'passionsin' => ['Fruity Passion Fruit Evening Perfume', 'عطر مسائي فاكهي بالباشن فروت'],
    'discovery'  => ['Perfume Sample & Gift Set', 'مجموعة عينات وهدايا عطور'],
  ];
  return $w[$id][$ar ? 1 : 0] ?? '';
}
function fomaxo_slug($s) { return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower((string)$s)), '-'); }
/* the product's own address, e.g. https://fomaxo.com/fomaxo-old-money */
function fomaxo_seo_url($p) { return FOMAXO_SITE . '/fomaxo-' . (fomaxo_slug($p['name'] ?? '') ?: fomaxo_slug($p['id'])); }

/* English → Arabic for link previews (WhatsApp, Instagram) of Arabic links (?lang=ar): the same word list the Arabic site uses
   (assets/i18n-ar.js), then the translations already kept for the shop; '' when there is none (the English stays) */
function fomaxo_ar_text($en) {
  static $d = null;
  if ($d === null) {
    $d = [];
    preg_match_all('/^("(?:[^"\\\\]|\\\\.)*")\s*:\s*("(?:[^"\\\\]|\\\\.)*"),?\s*$/m', (string)@file_get_contents(__DIR__ . '/assets/i18n-ar.js'), $m, PREG_SET_ORDER);
    foreach ($m as $x) { $k = json_decode($x[1]); $v = json_decode($x[2]); if (is_string($k) && is_string($v)) $d[$k] = $v; }
  }
  $en = trim((string)$en);
  if ($en === '') return '';
  if (isset($d[$en])) return $d[$en];
  return function_exists('fomaxo_ar_many') ? (string)(fomaxo_ar_many([$en])[$en] ?? '') : '';
}
/* the preview words of a page for an Arabic link: Arabic description, Arabic address, Arabic language tag */
function fomaxo_ar_preview($html, $url, $title, $desc) {
  $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
  $q = fn($s) => strtr((string)$s, ['\\' => '\\\\', '$' => '\$']);
  if ($title !== '') $html = preg_replace('#<meta property="og:title" content="[^"]*">#', $q('<meta property="og:title" content="' . $h($title) . '">'), $html, 1);
  if ($desc !== '') $html = preg_replace('#<meta property="og:description" content="[^"]*">#', $q('<meta property="og:description" content="' . $h($desc) . '">'), $html, 1);
  $html = preg_replace('#<meta property="og:url" content="[^"]*">\n?#', '', $html);
  return preg_replace('#(<meta property="og:site_name"[^>]*>\n)#', '$1' . $q('<meta property="og:url" content="' . $h($url) . "\">\n<meta property=\"og:locale\" content=\"ar_AE\">\n"), $html, 1);
}
/* fomaxo_send_page($html) sends a page made here (product address, home) without its code notes and with its own fingerprint (ETag):
   on a reload, or a visit after the 5-minute keep time, a page that has not changed answers "not modified" in a few bytes instead
   of the whole page again. It lives in lean-lib.php, which the plain home page uses on its own. */
require_once __DIR__ . '/lean-lib.php';
