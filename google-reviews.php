<?php
/* FOMAXO — product reviews for Google (Merchant Center → Product reviews feed, link fomaxo.com/google-reviews.xml, see .htaccess).
   Google's product review format 2.3: every visible review with its stars, words, photos and the shop's size ids (same g:id as
   google-shopping.xml), so Google Shopping and search can show FOMAXO star ratings. Verified Purchaser reviews are marked
   post_fulfillment (written from the order link). Hidden reviews are left out; mobile, real names and order numbers are never sent. */
date_default_timezone_set('Asia/Dubai');
require __DIR__ . '/seo-lib.php';
require_once __DIR__ . '/reviews-lib.php';

$x = fn($s) => htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
$prods = [];
foreach (fomaxo_seo_products() as $p) if (!$p['hidden']) $prods[$p['id']] = $p;

header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
   . '<feed xmlns:vc="http://www.w3.org/2007/XMLSchema-versioning" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="http://www.google.com/shopping/reviews/schema/product/2.3/product_reviews.xsd">' . "\n"
   . "<version>2.3</version>\n<aggregator><name>FOMAXO</name></aggregator>\n"
   . '<publisher><name>FOMAXO</name><favicon>' . FOMAXO_SITE . "/favicon.ico</favicon></publisher>\n<reviews>\n";
foreach (rv_all() as $r) {
  $p = $prods[$r['product'] ?? ''] ?? null; $text = trim((string)($r['text'] ?? ''));
  if (!$p || !empty($r['hidden']) || $text === '' || !is_numeric($r['rating'] ?? null)) continue;
  $url = fomaxo_seo_url($p);
  $t = strtotime((string)($r['created'] ?? '')) ?: time();
  $skus = array_map(fn($s) => '<sku>' . $x($p['id'] . '-' . $s) . '</sku>', array_values(array_filter((array)($p['sizes'] ?? []), fn($s) => is_numeric($p['prices'][(string)$s] ?? null) && $p['prices'][(string)$s] > 0)));
  $anon = !empty($r['anon']) || ($r['name'] ?? '') === 'Anonymous';
  echo "<review>\n  <review_id>" . $x($r['id']) . "</review_id>\n"
     . '  <reviewer><name' . ($anon ? ' is_anonymous="true"' : '') . '>' . $x($anon ? 'Anonymous' : $r['name']) . "</name></reviewer>\n"
     . '  <review_timestamp>' . date('c', $t) . "</review_timestamp>\n"
     . '  <content>' . $x($text) . "</content>\n"
     . '  <review_url type="group">' . $x($url) . "</review_url>\n";
  if (!empty($r['photos'])) { echo "  <reviewer_images>\n"; foreach ((array)$r['photos'] as $ph) echo '    <reviewer_image><url>' . $x(FOMAXO_SITE . '/media/r/' . rawurlencode($ph)) . "</url></reviewer_image>\n"; echo "  </reviewer_images>\n"; }
  echo '  <ratings><overall min="1" max="5">' . (int)$r['rating'] . "</overall></ratings>\n"
     . "  <products><product>\n    <product_ids>" . ($skus ? '<skus>' . implode('', $skus) . '</skus>' : '') . "<brands><brand>FOMAXO</brand></brands></product_ids>\n"
     . '    <product_name>' . $x('FOMAXO ' . ($p['name'] ?? $p['id'])) . "</product_name>\n    <product_url>" . $x($url) . "</product_url>\n  </product></products>\n"
     . "  <is_spam>false</is_spam>\n  <collection_method>" . (!empty($r['verified']) ? 'post_fulfillment' : 'unsolicited') . "</collection_method>\n</review>\n";
}
echo "</reviews>\n</feed>\n";
