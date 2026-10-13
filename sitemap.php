<?php
/* FOMAXO — the list of addresses for Google (fomaxo.com/sitemap.xml, see .htaccess and robots.txt):
   the home page and every product shown in the shop (hidden products are left out), each in English and Arabic (?lang=ar),
   with the two languages pointing to each other (hreflang) so Google shows Arabic searchers the Arabic page. */
require __DIR__ . '/seo-lib.php';
header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');
$urls = [FOMAXO_SITE . '/', FOMAXO_SITE . '/returns'];
foreach (fomaxo_seo_products() as $p) if (!$p['hidden']) $urls[] = fomaxo_seo_url($p);
$x = fn($s) => htmlspecialchars($s, ENT_XML1);
$ar = fn($u) => $u . '?lang=ar';
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";
foreach (array_unique($urls) as $u) {
  $alt = '<xhtml:link rel="alternate" hreflang="en" href="' . $x($u) . '"/><xhtml:link rel="alternate" hreflang="ar" href="' . $x($ar($u)) . '"/>'
       . '<xhtml:link rel="alternate" hreflang="x-default" href="' . $x($u) . '"/>';
  foreach ([$u, $ar($u)] as $loc) echo '  <url><loc>' . $x($loc) . '</loc>' . $alt . "</url>\n";
}
echo "</urlset>\n";
