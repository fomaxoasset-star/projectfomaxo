<?php
/* FOMAXO — the home page (fomaxo.com/, .htaccess sends it here) and the home page for an Arabic link (fomaxo.com/?lang=ar). Sends the normal website (index.html,
   looks exactly the same) with Arabic words in the link preview, so a link shared from the Arabic site shows Arabic on WhatsApp and Instagram.
   Google gets an Arabic title and description here, and the Arabic and English home pages point to each other (hreflang). */
if (($_GET['lang'] ?? '') !== 'ar') {   // the plain home page (fomaxo.com/): index.html as it is, only without its code notes (lean-lib.php)
  require __DIR__ . '/lean-lib.php';
  header('Content-Type: text/html; charset=utf-8');
  header('Cache-Control: no-cache');
  fomaxo_send_page((string)@file_get_contents(__DIR__ . '/index.html'));
  exit;
}
require __DIR__ . '/seo-lib.php';

$html = (string)@file_get_contents(__DIR__ . '/index.html');
$html = fomaxo_ar_preview($html, FOMAXO_SITE . '/?lang=ar', fomaxo_ar_text('FOMAXO — The House of Modern Luxury'),
                          trim(fomaxo_ar_text('For Outstanding Minds, Acquiring Xtraordinary Opulence.') . ' فخامة وُلدت في دبي.'));
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$q = fn($s) => strtr((string)$s, ['\\' => '\\\\', '$' => '\$']);
$tAr = fomaxo_ar_text('FOMAXO — The House of Modern Luxury');
if ($tAr !== '') $html = preg_replace('#<title>.*?</title>#s', $q('<title>' . $h($tAr) . '</title>'), $html, 1);
$html = preg_replace('#<meta name="description" content="[^"]*">#', $q('<meta name="description" content="' . $h('FOMAXO — دار فخامة وُلدت في دبي. عطور فاخرة للجنسين بأحجام 10ml و50ml و100ml، ومجموعة الاكتشاف. توصيل مجاني إلى جميع أنحاء الإمارات.') . '">'), $html, 1);
$html = preg_replace('#<html lang="en">#', '<html lang="ar" dir="rtl">', $html, 1);
$html = preg_replace('#(<meta charset="utf-8">\n)#', '$1' . $q('<link rel="canonical" href="' . FOMAXO_SITE . "/?lang=ar\">\n<link rel=\"alternate\" hreflang=\"en\" href=\"" . FOMAXO_SITE . "/\">\n"
  . '<link rel="alternate" hreflang="ar" href="' . FOMAXO_SITE . "/?lang=ar\">\n<link rel=\"alternate\" hreflang=\"x-default\" href=\"" . FOMAXO_SITE . "/\">\n"), $html, 1);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
fomaxo_send_page($html);
