<?php
/* FOMAXO — the list of addresses for Google (fomaxo.com/sitemap.xml, see .htaccess and robots.txt):
   the home page and every product shown in the shop (hidden products are left out). */
require __DIR__ . '/seo-lib.php';
header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');
$urls = [FOMAXO_SITE . '/'];
foreach (fomaxo_seo_products() as $p) if (!$p['hidden']) $urls[] = fomaxo_seo_url($p);
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach (array_unique($urls) as $u) echo '  <url><loc>' . htmlspecialchars($u, ENT_XML1) . "</loc></url>\n";
echo "</urlset>\n";
