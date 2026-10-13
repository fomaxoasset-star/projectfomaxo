<?php
/* FOMAXO — the product list for free Google Shopping listings (Google Merchant Center → Products → Add from a file → link):
   fomaxo.com/google-shopping.xml (English) and fomaxo.com/google-shopping-ar.xml (Arabic words, Arabic links), see .htaccess.
   One line per size (Old Money 10ml, 50ml, 100ml …), grouped by product, with the same prices, "was" prices, stock and photos as the shop.
   Hidden products (admin → Products) are left out. Google re-reads it by itself on the schedule set in Merchant Center. */
require __DIR__ . '/seo-lib.php';

$ar = ($_GET['lang'] ?? '') === 'ar';
$stock = [];
if ($pdo = fomaxo_db()) { try { $stock = fomaxo_stock_map($pdo); } catch (Throwable $e) {} }
$x = fn($s) => htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
$tr = fn($s) => $ar ? (fomaxo_ar_text($s) ?: (string)$s) : (string)$s;   // Arabic list: Arabic words; names, FOMAXO, AED and ml stay English
$money = fn($v) => number_format((float)$v, 2, '.', '') . ' AED';

$items = [];
foreach (fomaxo_seo_products() as $p) {
  if ($p['hidden']) continue;
  $isSet = ($p['kind'] ?? '') === 'set';
  $name = (string)($p['name'] ?? $p['id']);
  $family = (string)($p['family'] ?? '');
  $desc = trim(implode(' ', array_map($tr, array_map('strval', (array)($p['description'] ?? []))))) ?: $tr((string)($p['short'] ?? ''));
  $notes = array_filter([$p['notes']['top'] ?? '', $p['notes']['heart'] ?? '', $p['notes']['base'] ?? ''], fn($v) => is_string($v) && $v !== '');
  if ($notes) $desc .= ($ar ? ' النفحات: ' : ' Notes: ') . implode(' · ', $notes) . '.';
  $desc .= $ar ? ' توصيل مجاني إلى جميع أنحاء الإمارات.' : ' Free delivery anywhere in the UAE.';
  $sizes = array_values(array_filter((array)($p['sizes'] ?? []), fn($s) => is_numeric($p['prices'][(string)$s] ?? null) && $p['prices'][(string)$s] > 0));
  $extra = array_values(array_map('fomaxo_img_url', array_filter((array)($p['images'] ?? []), 'is_string')));
  foreach ($sizes as $s) {
    $s = (string)$s; $v = (float)$p['prices'][$s]; $was = $p['compareAt'][$s] ?? null;
    $fam = str_replace(' · ', ' ', $tr($family));
    $title = $isSet ? ($ar ? "FOMAXO $name من {$s}، $fam" : "FOMAXO $name of $s, $family") : "FOMAXO $name $fam {$s}ml";
    $link = fomaxo_seo_url($p) . '?size=' . $s . ($ar ? '&lang=ar' : '');
    $img = fomaxo_size_img($p, $s);
    $more = array_slice(array_values(array_diff($extra, [$img])), 0, 10);
    $out = isset($stock[$p['id'] . '|' . $s]) && $stock[$p['id'] . '|' . $s] <= 0;
    $f = ['g:id' => $p['id'] . '-' . $s, 'title' => $title, 'description' => $desc, 'link' => $link, 'g:image_link' => $img];
    foreach ($more as $i => $m) $f["g:additional_image_link#$i"] = $m;
    $f += ['g:availability' => $out ? 'out_of_stock' : 'in_stock', 'g:condition' => 'new', 'g:brand' => 'FOMAXO'];
    if (is_numeric($was) && $was > $v) $f += ['g:price' => $money($was), 'g:sale_price' => $money($v)]; else $f['g:price'] = $money($v);
    $f += ['g:google_product_category' => '479', 'g:product_type' => $isSet ? 'Perfume > Discovery Sets' : 'Perfume > ' . trim(explode('·', $family)[0]),
           'g:identifier_exists' => 'no', 'g:gender' => 'unisex', 'g:age_group' => 'adult'];
    if (count($sizes) > 1) $f['g:item_group_id'] = $p['id'];
    $f['g:size'] = $isSet ? ($ar ? "$s × 2ml" : "$s x 2ml") : "$s ml";
    $items[] = $f;
  }
}

header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">' . "\n<channel>\n"
   . '<title>FOMAXO</title>' . "\n<link>" . FOMAXO_SITE . "/</link>\n<description>" . $x($ar ? 'عطور FOMAXO الفاخرة، دبي' : 'FOMAXO luxury perfumes, Dubai') . "</description>\n";
foreach ($items as $f) {
  echo "<item>\n";
  foreach ($f as $k => $v) { $k = explode('#', $k)[0]; echo "  <$k>" . $x($v) . "</$k>\n"; }
  echo "  <g:shipping><g:country>AE</g:country><g:service>Free delivery</g:service><g:price>0.00 AED</g:price></g:shipping>\n</item>\n";
}
echo "</channel>\n</rss>\n";
