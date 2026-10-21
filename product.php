<?php
/* FOMAXO — a product's own address for Google: fomaxo.com/fomaxo-gold, fomaxo.com/fomaxo-old-money … (.htaccess sends them here).
   Sends the normal website (index.html, looks exactly the same) with that product's title, description, photo and price data
   (schema.org Product) in the page head, and opens the product page straight away.
   The Arabic address (fomaxo.com/fomaxo-gold?lang=ar) gets an Arabic Google title and description and is its own page for Google;
   both point to each other (hreflang). ?size=50 opens that size (the Google Shopping list links to each size). */
require __DIR__ . '/seo-lib.php';
require_once __DIR__ . '/reviews-lib.php';

$want = fomaxo_slug($_GET['p'] ?? '');
$html = (string)@file_get_contents(__DIR__ . '/index.html');
$p = null;
foreach (fomaxo_seo_products() as $x) {
  $old = fomaxo_slug(preg_replace('#^https?://[^/]+/(fomaxo-)?#', '', (string)($x['url'] ?? '')));   // old addresses, e.g. fomaxo-gold-eau-de-parfum
  if ($want !== '' && in_array($want, [fomaxo_slug($x['name'] ?? ''), fomaxo_slug($x['id']), $old], true)) { $p = $x; break; }
}
if (!$p || $p['hidden']) {   // unknown or hidden product: the home page, but tell Google there is nothing here
  http_response_code(404);
  echo preg_replace('#<head>#', "<head>\n<meta name=\"robots\" content=\"noindex\">", $html, 1);
  exit;
}
$url = fomaxo_seo_url($p);
if (fomaxo_slug($p['name'] ?? '') !== $want && fomaxo_slug($p['name'] ?? '') !== '') {   // old or id address: move to the name address
  header('Location: ' . $url . (($_GET['lang'] ?? '') === 'ar' ? '?lang=ar' : ''), true, 301); exit;
}

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$isSet = ($p['kind'] ?? '') === 'set';
$name = (string)$p['name'];
$family = (string)($p['family'] ?? '');
$desc = trim(implode(' ', array_map('strval', (array)($p['description'] ?? []))));
$short = trim((string)($p['short'] ?? '')) ?: mb_substr($desc, 0, 150);
$images = array_values(array_map('fomaxo_img_url', array_filter((array)($p['images'] ?? []), 'is_string')));
$ar = ($_GET['lang'] ?? '') === 'ar';
$size = (string)($_GET['size'] ?? '');
if (!in_array($size, array_map('strval', (array)($p['sizes'] ?? [])), true)) $size = '';

/* prices and stock, size by size (stock typed in on admin → Stock; not typed in = in stock) */
$stock = [];
if ($pdo = fomaxo_db()) { try { $stock = fomaxo_stock_map($pdo); } catch (Throwable $e) {} }
$offers = []; $lines = []; $min = null;
foreach ((array)($p['sizes'] ?? []) as $s) {
  $v = $p['prices'][(string)$s] ?? null; if (!is_numeric($v) || $v <= 0) continue;
  $label = $isSet ? "Set of $s" : "{$s}ml";
  $out = isset($stock[$p['id'] . '|' . $s]) && $stock[$p['id'] . '|' . $s] <= 0;
  $offers[] = ['@type' => 'Offer', 'name' => "$name $label", 'sku' => $p['id'] . '-' . $s, 'price' => number_format((float)$v, 2, '.', ''), 'priceCurrency' => 'AED',
               'availability' => 'https://schema.org/' . ($out ? 'OutOfStock' : 'InStock'), 'itemCondition' => 'https://schema.org/NewCondition', 'url' => $url,
               'seller' => ['@type' => 'Organization', 'name' => 'FOMAXO'],
               'shippingDetails' => ['@type' => 'OfferShippingDetails', 'shippingRate' => ['@type' => 'MonetaryAmount', 'value' => '0', 'currency' => 'AED'],
                                     'shippingDestination' => ['@type' => 'DefinedRegion', 'addressCountry' => 'AE']]];
  $lines[] = "$label: AED " . ($v + 0);
  $min = $min === null ? $v + 0 : min($min, $v + 0);
}
$ld = ['@context' => 'https://schema.org', '@type' => 'Product', 'name' => "FOMAXO $name", 'sku' => $p['id'], 'url' => $url,
       'brand' => ['@type' => 'Brand', 'name' => 'FOMAXO'], 'description' => $desc ?: $short, 'image' => $images, 'category' => $isSet ? 'Perfume sample set' : 'Perfume'];
if ($offers) $ld['offers'] = $offers;
$notes = array_filter([$p['notes']['top'] ?? '', $p['notes']['heart'] ?? '', $p['notes']['base'] ?? ''], fn($v) => is_string($v) && $v !== '');
/* star rating from the Verified Purchaser reviews */
$rs = array_filter(rv_all(), fn($r) => ($r['product'] ?? '') === $p['id'] && empty($r['hidden']) && is_numeric($r['rating'] ?? null));
if ($rs) $ld['aggregateRating'] = ['@type' => 'AggregateRating', 'ratingValue' => round(array_sum(array_column($rs, 'rating')) / count($rs), 1), 'reviewCount' => count($rs), 'bestRating' => 5, 'worstRating' => 1];
/* Google: the best real reviews with the buyer's city (typed by them, or the emirate of their order), so the page carries
   "Verified buyer in Dubai" words for local searches; and the cities in the description. Nothing is shown that a buyer did not write. */
$top = array_values(array_filter(rv_order_city(array_values($rs)), fn($r) => trim((string)($r['text'] ?? '')) !== ''));
usort($top, fn($a, $b) => (empty($b['verified']) ? 0 : 1) - (empty($a['verified']) ? 0 : 1) ?: $b['rating'] - $a['rating'] ?: strcmp((string)$b['created'], (string)$a['created']));
$top = array_slice($top, 0, 5);
$rvCity = fn($r) => trim((string)($r['city'] ?? ''));
if ($top) $ld['review'] = array_map(fn($r) => ['@type' => 'Review', 'reviewRating' => ['@type' => 'Rating', 'ratingValue' => (int)$r['rating'], 'bestRating' => 5, 'worstRating' => 1],
  'author' => ['@type' => 'Person', 'name' => (string)$r['name']] + ($rvCity($r) !== '' ? ['address' => ['@type' => 'PostalAddress', 'addressLocality' => $rvCity($r)] + (($r['country'] ?? '') === 'AE' || in_array($rvCity($r), ['Dubai', 'Abu Dhabi', 'Sharjah', 'Ajman', 'Umm Al Quwain', 'Ras Al Khaimah', 'Fujairah'], true) ? ['addressCountry' => 'AE'] : [])] : []),
  'datePublished' => substr((string)$r['created'], 0, 10), 'reviewBody' => mb_substr((string)$r['text'], 0, 500)], $top);
$buyCities = [];   /* emirates / cities of verified buyers, most reviews first */
foreach (rv_order_city(array_values($rs)) as $r) if (!empty($r['verified']) && $rvCity($r) !== '') $buyCities[$rvCity($r)] = ($buyCities[$rvCity($r)] ?? 0) + 1;
arsort($buyCities); $buyCities = array_slice(array_keys($buyCities), 0, 3);
$crumbs = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => [
  ['@type' => 'ListItem', 'position' => 1, 'name' => 'FOMAXO', 'item' => FOMAXO_SITE . '/'],
  ['@type' => 'ListItem', 'position' => 2, 'name' => $name, 'item' => $url]]];

$kw = fomaxo_scent($p['id']);   // scent words people search for, e.g. "Oud & Leather Arabic Perfume"
$title = $kw !== '' ? "$name $kw · Unisex | FOMAXO Dubai" : "$name $family | FOMAXO Dubai";
if ($kw !== '' && $isSet) $title = "$name, $kw | FOMAXO Dubai";
$meta = "FOMAXO $name, " . ($kw !== '' ? "$kw, " : '') . ($isSet ? $family : "$family perfume") . '. ' . $short . ($min !== null ? ' From AED ' . $min . '.' : '') . ' Free delivery anywhere in the UAE.';
if ($ar) {   /* Arabic Google title and description (names, FOMAXO, AED and ml stay English); a missing translation keeps the English */
  $familyAr = fomaxo_ar_text($family); $shortAr = fomaxo_ar_text($short) ?: fomaxo_ar_text($desc);
  $kwAr = fomaxo_scent($p['id'], true);
  if ($familyAr !== '') $title = $kwAr !== '' ? "$name $kwAr · للجنسين | FOMAXO دبي" : "$name $familyAr | FOMAXO دبي";
  if ($kwAr !== '' && $isSet) $title = "{$name}، $kwAr | FOMAXO دبي";
  if ($familyAr !== '' && $shortAr !== '')
    $meta = "FOMAXO {$name}، " . ($kwAr !== '' ? "{$kwAr}، " : '') . ($isSet ? $familyAr : "عطر $familyAr") . '. ' . $shortAr . ($min !== null ? ' يبدأ من AED ' . $min . '.' : '') . ' توصيل مجاني إلى جميع أنحاء الإمارات.';
}
if ($buyCities) $meta .= $ar ? ' يحبه مشترون موثّقون في ' . implode('، ', $buyCities) . '.' : ' Loved by verified buyers in ' . implode(', ', $buyCities) . '.';
$J = fn($v) => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);

$head = "<link rel=\"canonical\" href=\"{$h($url . ($ar ? '?lang=ar' : ''))}\">\n"
      . "<link rel=\"alternate\" hreflang=\"en\" href=\"{$h($url)}\">\n<link rel=\"alternate\" hreflang=\"ar\" href=\"{$h($url . '?lang=ar')}\">\n"
      . "<link rel=\"alternate\" hreflang=\"x-default\" href=\"{$h($url)}\">\n"
      . "<meta property=\"og:url\" content=\"{$h($url)}\">\n<meta property=\"og:type\" content=\"product\">\n"
      . "<script>window.FX_TITLE=" . $J([$p['id'] => $title]) . ";</script>\n"
      . "<script>if(!location.hash)history.replaceState(null,'',location.pathname+location.search+'#/product/'+" . $J($p['id'] . ($size !== '' ? "?size=$size" : '')) . ");</script>\n"
      . "<script type=\"application/ld+json\">" . $J($ld) . "</script>\n"
      . "<script type=\"application/ld+json\">" . $J($crumbs) . "</script>\n";
$html = preg_replace('#<link rel="canonical"[^>]*>\n?#', '', $html);
$html = preg_replace('#<title>.*?</title>#s', '<title>' . $h($title) . '</title>', $html, 1);
$html = preg_replace('#<meta name="description" content="[^"]*">#', '<meta name="description" content="' . $h($meta) . '">', $html, 1);
$html = preg_replace('#<meta property="og:title" content="[^"]*">#', '<meta property="og:title" content="' . $h("FOMAXO $name") . '">', $html, 1);
$html = preg_replace('#<meta property="og:description" content="[^"]*">#', '<meta property="og:description" content="' . $h($short) . '">', $html, 1);
if ($images) $html = preg_replace('#<meta property="og:image" content="[^"]*">#', '<meta property="og:image" content="' . $h($images[0]) . '">', $html, 1);
if ($images) $html = preg_replace(['#<meta property="og:image:(width|height)"[^>]*>\n?#', '#<meta name="twitter:image" content="[^"]*">#'], ['', '<meta name="twitter:image" content="' . $h($images[0]) . '">'], $html);  /* product photo has its own size */
$html = preg_replace('#(<meta charset="utf-8">\n)#', '$1' . strtr($head, ['\\' => '\\\\', '$' => '\$']), $html, 1);
if (($_GET['lang'] ?? '') === 'ar')   /* an Arabic link (shared from the Arabic site): Arabic words in the WhatsApp / Instagram preview */
  $html = fomaxo_ar_preview($html, $url . '?lang=ar', 'FOMAXO ' . $name, fomaxo_ar_text($short) ?: fomaxo_ar_text($desc));
/* the same words as plain text, for anything that reads the page without running it */
if ($ar) $html = preg_replace('#<html lang="en">#', '<html lang="ar" dir="rtl">', $html, 1);
if ($ar) {   /* the plain text in Arabic too, paragraph by paragraph */
  $descAr = implode(' ', array_filter(array_map('fomaxo_ar_text', array_map('strval', (array)($p['description'] ?? [])))));
  if ($descAr !== '' || fomaxo_ar_text($short) !== '') { $desc = $descAr; $short = fomaxo_ar_text($short) ?: $short; $family = fomaxo_ar_text($family) ?: $family; }
}
$tNotes = $ar ? 'النفحات: ' : 'Notes: '; $tFree = $ar ? 'توصيل مجاني إلى جميع أنحاء الإمارات.' : 'Free delivery anywhere in the UAE.';
$text = '<noscript><div style="max-width:720px;margin:40px auto;padding:0 16px"><h1>FOMAXO ' . $h($name) . '</h1><p>' . $h($family) . '</p><p>' . $h($desc ?: $short) . '</p>'
      . ($notes ? '<p>' . $tNotes . $h(implode(' · ', $notes)) . '</p>' : '') . ($lines ? '<p>' . $h(implode(' · ', $lines)) . '</p>' : '') . '<p>' . $tFree . '</p>'
      . implode('', array_map(fn($r) => '<blockquote><p>' . $h((string)$r['text']) . '</p><p>' . $h((string)$r['name']) . ($rvCity($r) !== '' ? ', ' . $h($rvCity($r)) : '') . (!empty($r['verified']) ? ($ar ? ' · مشترٍ موثّق' : ' · Verified Purchaser') : '') . ' · ' . (int)$r['rating'] . '/5</p></blockquote>', $top)) . '</div></noscript>';
$html = preg_replace('#<body>#', '<body>' . strtr($text, ['\\' => '\\\\', '$' => '\$']), $html, 1);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');   /* asked again on every visit, so a name, photo or price saved on admin shows straight away (and the Arabic link never gets the English preview from a cache); a reload with nothing changed gets "not modified" (fomaxo_send_page) */
header('X-LiteSpeed-Cache-Control: no-cache');
fomaxo_send_page($html);
