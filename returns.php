<?php
/* FOMAXO — the Returns page at its own address, fomaxo.com/returns (.htaccess sends it here; Google Merchant Center links to it).
   Sends the normal website (index.html, looks exactly the same) with the page's own title, description and the policy as plain text,
   and opens #/returns straight away. Keep the words in step with returnsPage() in index.html. */
require __DIR__ . '/seo-lib.php';

$html = (string)@file_get_contents(__DIR__ . '/index.html');
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$q = fn($s) => strtr((string)$s, ['\\' => '\\\\', '$' => '\$']);
$url = FOMAXO_SITE . '/returns';
$title = 'Returns & refunds | FOMAXO Dubai';
if (($_GET['lang'] ?? '') === 'ar') $title = 'الإرجاع واسترداد المبلغ | FOMAXO دبي';
$desc = 'Return any unopened FOMAXO product within 7 days of delivery. Damaged or wrong items replaced or refunded. Free collection anywhere in the UAE, refund within 5 working days.';
$items = [
  ['7-day returns', 'You can return any FOMAXO product within 7 days of delivery if it is unopened, with its seal and box intact.'],
  ['Damaged or wrong item', 'If your order arrives damaged, or you received the wrong item, tell us within 7 days of delivery and we will replace it or refund you in full.'],
  ['Free collection', 'We collect the item from your address anywhere in the UAE, free of charge.'],
  ['Refunds', 'We refund you within 5 working days of receiving the item: to the card you paid with, or by bank transfer for cash on delivery orders.'],
  ['Opened bottles', 'For hygiene reasons, opened or used perfumes cannot be returned unless they arrived damaged or faulty.'],
  ['How to return', 'Message us on WhatsApp (+971 54 314 6334) or email fomaxoasset@gmail.com with your order number (FMX-…) and we will arrange the collection.']];

$ar = ($_GET['lang'] ?? '') === 'ar';   // the Arabic page (?lang=ar) is its own page for Google, both point to each other
$head = "<link rel=\"canonical\" href=\"{$h($url . ($ar ? '?lang=ar' : ''))}\">\n<meta property=\"og:url\" content=\"{$h($url . ($ar ? '?lang=ar' : ''))}\">\n"
      . "<link rel=\"alternate\" hreflang=\"en\" href=\"{$h($url)}\">\n<link rel=\"alternate\" hreflang=\"ar\" href=\"{$h($url . '?lang=ar')}\">\n"
      . "<link rel=\"alternate\" hreflang=\"x-default\" href=\"{$h($url)}\">\n"
      . "<script>if(!location.hash)history.replaceState(null,'',location.pathname+location.search+'#/returns');</script>\n";
if ($ar) $html = preg_replace('#<html lang="en">#', '<html lang="ar" dir="rtl">', $html, 1);
$html = preg_replace('#<link rel="canonical"[^>]*>\n?#', '', $html);
$html = preg_replace('#<title>.*?</title>#s', $q('<title>' . $h($title) . '</title>'), $html, 1);
$html = preg_replace('#<meta name="description" content="[^"]*">#', $q('<meta name="description" content="' . $h($desc) . '">'), $html, 1);
$html = preg_replace('#<meta property="og:title" content="[^"]*">#', $q('<meta property="og:title" content="' . $h('FOMAXO Returns & refunds') . '">'), $html, 1);
$html = preg_replace('#<meta property="og:description" content="[^"]*">#', $q('<meta property="og:description" content="' . $h($desc) . '">'), $html, 1);
$html = preg_replace('#(<meta charset="utf-8">\n)#', '$1' . $q($head), $html, 1);
$text = '<noscript><div style="max-width:720px;margin:40px auto;padding:0 16px"><h1>FOMAXO Returns &amp; refunds</h1>'
      . implode('', array_map(fn($i) => '<h2>' . $h($i[0]) . '</h2><p>' . $h($i[1]) . '</p>', $items)) . '</div></noscript>';
$html = preg_replace('#<body>#', '<body>' . $q($text), $html, 1);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: ' . ($ar ? 'no-cache' : 'public, max-age=300'));
echo fomaxo_lean_page($html);   // without the code notes (lean-lib.php)
