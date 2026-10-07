<?php
/* FOMAXO — a product's own address for Google: fomaxo.com/fomaxo-gold, fomaxo.com/fomaxo-old-money … (.htaccess sends them here).
   Sends the normal website (index.html, looks exactly the same) with that product's title, description, photo and price data
   (schema.org Product) in the page head, and opens the product page straight away. */
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
$img = fn($k) => FOMAXO_SITE . '/assets/img/' . rawurlencode((string)$k) . '.webp';
$images = array_values(array_map($img, array_filter((array)($p['images'] ?? []), 'is_string')));

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
$crumbs = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => [
  ['@type' => 'ListItem', 'position' => 1, 'name' => 'FOMAXO', 'item' => FOMAXO_SITE . '/'],
  ['@type' => 'ListItem', 'position' => 2, 'name' => $name, 'item' => $url]]];

$title = "$name $family | FOMAXO Dubai";
$meta = "FOMAXO $name, " . ($isSet ? $family : "$family perfume") . '. ' . $short . ($min !== null ? ' From AED ' . $min . '.' : '') . ' Free delivery anywhere in the UAE.';
$J = fn($v) => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);

$head = "<link rel=\"canonical\" href=\"{$h($url)}\">\n"
      . "<meta property=\"og:url\" content=\"{$h($url)}\">\n<meta property=\"og:type\" content=\"product\">\n"
      . "<script>if(!location.hash)history.replaceState(null,'',location.pathname+location.search+'#/product/'+" . $J($p['id']) . ");</script>\n"
      . "<script type=\"application/ld+json\">" . $J($ld) . "</script>\n"
      . "<script type=\"application/ld+json\">" . $J($crumbs) . "</script>\n";
$html = preg_replace('#<link rel="canonical"[^>]*>\n?#', '', $html);
$html = preg_replace('#<title>.*?</title>#s', '<title>' . $h($title) . '</title>', $html, 1);
$html = preg_replace('#<meta name="description" content="[^"]*">#', '<meta name="description" content="' . $h($meta) . '">', $html, 1);
$html = preg_replace('#<meta property="og:title" content="[^"]*">#', '<meta property="og:title" content="' . $h("FOMAXO $name") . '">', $html, 1);
$html = preg_replace('#<meta property="og:description" content="[^"]*">#', '<meta property="og:description" content="' . $h($short) . '">', $html, 1);
if ($images) $html = preg_replace('#<meta property="og:image" content="[^"]*">#', '<meta property="og:image" content="' . $h($images[0]) . '">', $html, 1);
$html = preg_replace('#(<meta charset="utf-8">\n)#', '$1' . strtr($head, ['\\' => '\\\\', '$' => '\$']), $html, 1);
/* the same words as plain text, for anything that reads the page without running it */
$text = '<noscript><div style="max-width:720px;margin:40px auto;padding:0 16px"><h1>FOMAXO ' . $h($name) . '</h1><p>' . $h($family) . '</p><p>' . $h($desc ?: $short) . '</p>'
      . ($notes ? '<p>Notes: ' . $h(implode(' · ', $notes)) . '</p>' : '') . ($lines ? '<p>' . $h(implode(' · ', $lines)) . '</p>' : '') . '<p>Free delivery anywhere in the UAE.</p></div></noscript>';
$html = preg_replace('#<body>#', '<body>' . strtr($text, ['\\' => '\\\\', '$' => '\$']), $html, 1);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, max-age=300');
echo $html;
