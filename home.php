<?php
/* FOMAXO — the home page for an Arabic link (fomaxo.com/?lang=ar, .htaccess sends it here). Sends the normal website (index.html,
   looks exactly the same) with Arabic words in the link preview, so a link shared from the Arabic site shows Arabic on WhatsApp and Instagram.
   The page itself still points Google to the English home page. */
require __DIR__ . '/seo-lib.php';

$html = (string)@file_get_contents(__DIR__ . '/index.html');
$html = fomaxo_ar_preview($html, FOMAXO_SITE . '/?lang=ar', fomaxo_ar_text('FOMAXO — The House of Modern Luxury'),
                          trim(fomaxo_ar_text('For Outstanding Minds, Acquiring Xtraordinary Opulence.') . ' فخامة وُلدت في دبي.'));
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
echo $html;
