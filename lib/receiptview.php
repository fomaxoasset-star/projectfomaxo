<?php
// FOMAXO Store System - the customer receipt, in the same design as fomaxo.com receipts
// (logo, fonts, light/dark, Arabic, Download PDF). Used by receipt.php.

const FX_SYMBOLS = '<symbol id="emblem" viewBox="0 0 2507 2506"><path fill="currentColor" fill-rule="evenodd" d="M1186 0 1072 12 1049 17 1005 23 962 34 943 37 937 40 883 54 878 57 832 71 784 91 773 94 721 117 659 148 615 173 559 208 505 246 464 278 415 320 369 363 324 410 279 463 247 504 216 548 182 601 145 666 119 718 91 782 85 801 74 826 54 886 37 947 26 1001 23 1008 23 1016 20 1025 20 1033 12 1073 9 1108 6 1121 3 1176 1 1185 1 1319 6 1383 9 1396 12 1431 20 1471 23 1496 37 1558 54 1619 71 1667 71 1671 94 1731 125 1800 148 1845 180 1901 213 1953 253 2009 284 2048 332 2103 366 2139 417 2187 457 2221 510 2262 556 2295 610 2329 671 2363 717 2386 776 2412 834 2434 882 2449 893 2454 898 2454 945 2468 997 2480 1053 2489 1057 2491 1111 2497 1124 2500 1178 2503 1188 2505 1319 2505 1329 2503 1383 2500 1396 2497 1449 2491 1550 2471 1597 2457 1613 2454 1664 2437 1730 2412 1783 2389 1845 2358 1900 2327 1946 2298 1994 2264 2046 2224 2094 2183 2128 2151 2182 2095 2225 2045 2264 1995 2304 1937 2329 1897 2358 1846 2386 1791 2412 1732 2434 1675 2454 1614 2463 1577 2468 1564 2483 1499 2483 1492 2494 1436 2505 1328 2505 1177 2494 1068 2491 1059 2483 1006 2468 941 2451 881 2434 830 2412 773 2389 721 2358 659 2330 610 2298 559 2261 506 2221 455 2176 403 2096 324 2040 276 1999 244 1946 207 1888 171 1842 145 1790 119 1787 119 1726 91 1666 68 1563 37 1501 23 1439 14 1435 12 1321 0ZM546 439 550 442 1015 1081 1097 1194 1099 1200 508 2002 494 2018 453 1976 419 1937 359 1856 326 1804 300 1758 277 1712 255 1662 237 1615 220 1563 206 1512 192 1447 192 1439 186 1412 178 1342 175 1292 175 1213 178 1164 186 1094 192 1066 195 1041 206 993 223 932 226 927 240 882 280 787 326 701 357 652 393 602 453 529 511 470ZM1198 175 1309 175 1319 177 1353 178 1409 186 1419 186 1516 206 1613 235 1720 280 1766 303 1810 328 1893 384 1946 427 1945 430 1671 430 1280 956 1282 962 1421 1149 1426 1145 1947 438 1954 433 2007 481 2050 525 2109 596 2152 656 2178 697 2226 786 2251 842 2268 886 2285 937 2299 987 2311 1039 2320 1091 2327 1147 2328 1176 2331 1198 2331 1308 2322 1401 2311 1466 2308 1473 2302 1506 2285 1568 2268 1619 2231 1709 2203 1765 2177 1810 2149 1854 2118 1898 2052 1978 1974 2055 1897 2118 1850 2151 1799 2183 1760 2205 1708 2231 1678 2243 1663 2251 1610 2271 1606 2271 1567 2285 1504 2302 1498 2302 1462 2311 1420 2317 1416 2319 1383 2322 1370 2325 1302 2330 1204 2330 1137 2325 1124 2322 1091 2319 991 2299 940 2285 888 2268 830 2245 748 2206 653 2149 570 2087 524 2047 497 2020 498 2018 779 2018 1248 1399 1252 1399 1707 2017 1991 2017 805 432 802 430 561 429 561 426 572 416 616 382 656 354 707 322 746 300 793 277 880 240 930 223 934 223 939 220 984 209 990 206 1009 203 1039 195 1103 184 1153 178 1173 178Z"/></symbol><symbol id="wordmark" viewBox="0 0 3196 481"><path fill="currentColor" fill-rule="evenodd" d="M1068 147 1068 480 1135 480 1135 214ZM1778 123 1581 480 1672 479 1815 221 1823 204ZM0 480 68 480 69 257 377 257 377 189 69 189 68 105 0 105ZM2934 1 2911 4 2871 15 2837 31 2801 56 2768 90 2743 128 2726 168 2720 191 2716 218 2716 263 2723 303 2739 346 2750 366 2766 389 2776 401 2802 426 2837 450 2864 463 2890 472 2926 479 2973 480 3003 476 3023 471 3048 462 3069 452 3108 426 3141 393 3166 356 3185 311 3194 267 3194 215 3186 174 3178 151 3165 124 3154 106 3138 85 3110 57 3089 41 3069 29 3027 11 2998 4 2976 1ZM2933 70 2966 69 3005 76 3038 90 3064 108 3087 131 3105 157 3120 192 3126 222 3126 259 3120 289 3107 321 3092 344 3064 373 3039 390 3013 402 2976 411 2934 411 2902 404 2869 389 2847 374 2822 349 2803 321 2789 287 2784 262 2783 229 2790 191 2805 157 2823 131 2847 107 2872 90 2901 77ZM2623 1 2538 1 2431 145 2421 161 2463 217ZM2198 1 2366 233 2184 480 2269 480 2411 293 2417 299 2550 480 2635 480 2276 1ZM722 1 677 9 654 17 625 31 590 55 555 91 533 124 520 151 513 171 504 216 504 265 513 311 531 354 557 393 591 427 623 449 647 461 671 470 714 479 760 480 785 477 811 471 855 453 897 425 928 394 948 366 958 348 974 307 982 265 982 217 973 171 954 126 939 102 925 84 893 53 869 36 851 26 815 11 786 4 763 1ZM721 70 754 69 793 76 829 92 848 105 873 129 894 159 908 193 914 224 914 258 907 292 893 324 876 349 854 371 825 391 792 405 764 411 721 411 693 405 660 391 635 374 609 348 592 323 580 297 572 264 571 227 577 194 583 177 594 155 610 132 636 106 658 91 689 77ZM0 1 0 69 423 69 423 1ZM1847 0 1802 82 2022 480 2113 480ZM1068 0 1068 97 1282 311 1425 169 1426 170 1426 480 1494 480 1494 4 1282 215Z"/></symbol>';

function rc_words($ar)
{
    $en = ['tag' => 'Perfume & Personal Care', 'title' => 'Receipt', 'tax' => 'Tax invoice', 'to' => 'Issued to', 'no' => 'Receipt no', 'date' => 'Date', 'name' => 'Name', 'mobile' => 'Mobile',
        'item' => 'Item', 'size' => 'Size', 'unit' => 'Unit price', 'qty' => 'Qty', 'amount' => 'Amount', 'sub' => 'Subtotal', 'disc' => 'Discount', 'vat' => 'VAT', 'incl' => 'included',
        'paid_total' => 'Total paid', 'total' => 'Total', 'saved' => 'You saved', 'saved2' => 'on this order', 'store' => 'Store', 'served' => 'Served by', 'trn' => 'TRN',
        'payment' => 'Payment', 'cash' => 'Cash', 'tap' => 'Tap to Pay', 'ziina' => 'Ziina', 'card' => 'Card', 'given' => 'Cash given', 'change' => 'Change',
        'paid' => 'Paid', 'pending' => 'Waiting', 'cancelled' => 'Cancelled', 'failed' => 'Failed', 'void' => 'Voided', 'walkin' => 'Walk-in customer',
        'thanks' => 'Thank you for your purchase', 'country' => 'United Arab Emirates', 'site' => 'fomaxo.com', 'pdf' => 'Download PDF', 'sets' => 'Set of', 'vials' => 'vials',
        'scan' => 'Scan to open this receipt', 'n_pending' => 'Waiting for the payment to be confirmed. Refresh this page in a moment.',
        'n_bad' => 'This payment was not completed. No money was taken.', 'n_void' => 'This sale was cancelled by the store.', 'member' => 'Member'];
    if (!$ar) {
        return $en;
    }
    return ['tag' => 'العطور والعناية الشخصية', 'title' => 'إيصال', 'tax' => 'فاتورة ضريبية', 'to' => 'صادر إلى', 'no' => 'رقم الإيصال', 'date' => 'التاريخ', 'name' => 'الاسم', 'mobile' => 'الجوال',
        'item' => 'المنتج', 'size' => 'الحجم', 'unit' => 'سعر الوحدة', 'qty' => 'الكمية', 'amount' => 'المبلغ', 'sub' => 'المجموع الفرعي', 'disc' => 'خصم', 'vat' => 'ضريبة القيمة المضافة', 'incl' => 'مشمولة',
        'paid_total' => 'المبلغ المدفوع', 'total' => 'الإجمالي', 'saved' => 'وفّرت', 'saved2' => 'في هذا الطلب', 'store' => 'المتجر', 'served' => 'خدمك', 'trn' => 'الرقم الضريبي',
        'payment' => 'الدفع', 'cash' => 'نقداً', 'tap' => 'الدفع باللمس', 'ziina' => 'Ziina', 'card' => 'بطاقة', 'given' => 'المبلغ المستلم', 'change' => 'الباقي',
        'paid' => 'مدفوع', 'pending' => 'بانتظار الدفع', 'cancelled' => 'ملغي', 'failed' => 'لم يكتمل', 'void' => 'ملغى', 'walkin' => 'عميل',
        'thanks' => 'شكراً لشرائك من FOMAXO', 'country' => 'الإمارات العربية المتحدة', 'site' => 'fomaxo.com', 'pdf' => 'تحميل PDF', 'sets' => 'مجموعة من', 'vials' => 'عينات',
        'scan' => 'امسح لفتح هذا الإيصال', 'n_pending' => 'بانتظار تأكيد الدفع. حدّث الصفحة بعد قليل.', 'n_bad' => 'لم يكتمل الدفع. لم يتم خصم أي مبلغ.', 'n_void' => 'ألغى المتجر هذه العملية.', 'member' => 'عضو'];
}

function rc_money($fils)
{
    return '<span class="m"><small>AED</small>' . number_format($fils / 100, 2) . '</span>';
}

/** $sale from load_sale(); $theme '' (follow device) | 'light' | 'dark'; $embed = shown inside the app */
function receipt_page($sale, $ar = false, $theme = '', $embed = false)
{
    $w = rc_words($ar);
    $e = function ($v) {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    };
    $rows = '';
    $save = (int) $sale['discount'];
    foreach ($sale['items'] as $it) {
        $name = preg_replace('/^fomaxo\s+/i', '', $it['name']);
        $variant = (string) $it['variant'];
        $size = preg_match('/^(\d+)\s*vials$/i', $variant, $m) ? $w['sets'] . ' ' . $m[1] : $variant;
        $was = isset($it['was']) && (int) $it['was'] > (int) $it['price'] ? (int) $it['was'] : 0;
        if ($was) {
            $save += ($was - (int) $it['price']) * (int) $it['qty'];
        }
        $fam = trim((string) ($it['family'] ?? ''));
        $rows .= '<tr><td class="c-it"><b class="pn">' . $e($name) . '</b>' . ($fam !== '' ? '<span class="pf">' . $e($fam) . '</span>' : '') . '</td>'
            . '<td class="c-sz">' . $e($size) . '</td><td class="c-un">' . ($was ? '<s>' . rc_money($was) . '</s>' : '') . rc_money((int) $it['price']) . '</td>'
            . '<td class="c-q">' . (int) $it['qty'] . '</td><td class="c-am">' . rc_money((int) $it['line_total']) . '</td></tr>';
    }
    $rate = $sale['subtotal'] - $sale['discount'] > 0 && (int) $sale['vat'] > 0 ? cfg('vat_rate', 5) : 0;
    $sum = '<div class="tr"><span>' . $e($w['sub']) . '</span>' . rc_money((int) $sale['subtotal']) . '</div>';
    if ((int) $sale['discount'] > 0) {
        $sum .= '<div class="tr dc"><span>' . $e($w['disc']) . '</span><span class="m">−&nbsp;<small>AED</small>' . number_format($sale['discount'] / 100, 2) . '</span></div>';
    }
    if ((int) $sale['vat'] > 0) $sum .= '<div class="tr vt"><span>' . $e($w['vat']) . ' ' . $e($rate) . '%' . (cfg('prices_include_vat', true) ? ' (' . $e($w['incl']) . ')' : '') . '</span>' . rc_money((int) $sale['vat']) . '</div>';
    $paid = $sale['status'] === 'paid';
    $sum .= '<div class="tt"><span>' . $e($paid ? $w['paid_total'] : $w['total']) . '</span>' . rc_money((int) $sale['total']) . '</div>';
    if ($sale['method'] === 'cash' && $sale['cash_given'] !== null && $paid) {
        $sum .= '<div class="tr"><span>' . $e($w['given']) . '</span>' . rc_money((int) $sale['cash_given']) . '</div>'
            . '<div class="tr"><span>' . $e($w['change']) . '</span>' . rc_money((int) $sale['change_due']) . '</div>';
    }
    if ($save > 0 && $paid) {
        $sum .= '<div class="sv">' . $e($w['saved']) . ' ' . rc_money($save) . ' ' . $e($w['saved2']) . '</div>';
    }

    $storeName = $sale['store_name'] ?? '';
    $storeLines = array_filter([
        $storeName && $storeName !== 'Main store' ? 'FOMAXO ' . $storeName : 'FOMAXO',
        ($sale['store_address'] ?? '') ?: cfg('store_address'),
        ($sale['store_phone'] ?? '') ?: cfg('store_phone'),
        cfg('store_trn') ? $w['trn'] . ' ' . cfg('store_trn') : '',
        $w['served'] . ': ' . $sale['staff_name'],
    ], function ($x) { return trim((string) $x) !== ''; });

    $st = in_array($sale['status'], ['paid', 'pending', 'cancelled', 'failed', 'void'], true) ? $sale['status'] : 'pending';
    $method = $w[$sale['method']] ?? $sale['method'];
    $ref = trim((string) ($sale['payment_ref'] ?? ''));
    $date = date('d/m/Y H:i', strtotime($sale['created_at']));
    $cust = trim((string) ($sale['customer_name'] ?? ''));
    $phone = trim((string) ($sale['customer_phone'] ?? ''));
    if (preg_match('/^\+971(5\d)(\d{3})(\d{4})$/', $phone, $pm)) {
        $phone = '+971 ' . $pm[1] . ' ' . $pm[2] . ' ' . $pm[3];
    }
    $notice = '';
    if ($st === 'pending') {
        $notice = '<div class="note">' . $e($w['n_pending']) . '</div>';
    } elseif ($st === 'cancelled' || $st === 'failed') {
        $notice = '<div class="note bad">' . $e($w['n_bad']) . '</div>';
    } elseif ($st === 'void') {
        $notice = '<div class="note bad">' . $e($w['n_void']) . '</div>';
    }
    $base = '?t=' . rawurlencode($sale['receipt_token']) . ($theme ? '&theme=' . $theme : '');
    $qr = '<span class="qrc" data-url="' . $e($sale['receipt_url']) . '"></span>';
    $title = cfg('store_trn') ? $w['tax'] : $w['title'];

    return '<!doctype html><html lang="' . ($ar ? 'ar' : 'en') . '" dir="' . ($ar ? 'rtl' : 'ltr') . '"' . ($theme ? ' data-theme="' . $theme . '"' : '') . ($embed ? ' class="embed"' : '') . '><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex,nofollow"><title>FOMAXO ' . $e($w['title']) . ' ' . $e($sale['receipt_no']) . '</title><link rel="icon" href="assets/favicon-32.png">'
        . '<style>' . rc_css() . '</style></head><body>'
        . '<div class="bar"><a class="lng" href="' . $e($base . ($ar ? '' : '&l=ar')) . '"' . ($ar ? '' : ' lang="ar"') . '>' . ($ar ? 'English' : 'عربي') . '</a>'
        . '<button type="button" class="pdf" onclick="window.print()">' . $e($w['pdf']) . '</button></div>' . $notice
        . '<main class="sheet">'
        . '<header class="hd"><svg class="em" viewBox="0 0 2507 2506" aria-hidden="true"><use href="#emblem"/></svg><svg class="wm" viewBox="0 0 3196 481" role="img" aria-label="FOMAXO"><use href="#wordmark"/></svg>'
        . '<p class="tg">' . $e($w['tag']) . '</p><h1><span>' . $e($title) . '</span></h1></header>'
        . '<section class="meta"><div><h2>' . $e($w['to']) . '</h2><dl><dt>' . $e($w['name']) . '</dt><dd>' . $e($cust !== '' ? $cust : $w['walkin']) . '</dd>'
        . ($phone !== '' ? '<dt>' . $e($w['mobile']) . '</dt><dd dir="ltr">' . $e($phone) . '</dd>' : '')
        . (!empty($sale['customer_tier']) ? '<dt>' . $e($w['member']) . '</dt><dd>FOMAXO ' . $e($sale['customer_tier']) . '</dd>' : '') . '</dl></div>'
        . '<div class="rn"><h2>' . $e($w['no']) . '</h2><p class="no" dir="ltr">#' . $e($sale['receipt_no']) . '</p><p class="dt"><span>' . $e($w['date']) . '</span> <bdi>' . $e($date) . '</bdi></p></div></section>'
        . '<table class="it"><thead><tr><th class="c-it">' . $e($w['item']) . '</th><th class="c-sz">' . $e($w['size']) . '</th><th class="c-un">' . $e($w['unit']) . '</th><th class="c-q">' . $e($w['qty']) . '</th><th class="c-am">' . $e($w['amount']) . '</th></tr></thead><tbody>' . $rows . '</tbody></table>'
        . '<section class="bt"><div class="lf"><h2>' . $e($w['store']) . '</h2><p class="ad">' . implode('<br>', array_map(function ($x) use ($e) { return '<bdi>' . $e($x) . '</bdi>'; }, $storeLines)) . '</p>'
        . '<div class="py"><span>' . $e($w['payment']) . '</span><b>' . $e($method) . ($ref !== '' ? ' <span dir="ltr">· ' . $e($ref) . '</span>' : '') . '</b><i class="st st-' . $st . '">' . $e($w[$st]) . '</i></div></div>'
        . '<div class="sm">' . $sum . '</div></section>'
        . '<footer class="ft"><p class="ty">' . $e($w['thanks']) . '</p><p class="si">' . $e($w['country']) . '</p><p class="si site" dir="ltr">' . $e($w['site']) . '</p>'
        . ($qr ? '<div class="qr">' . $qr . '<span>' . $e($w['scan']) . '</span></div>' : '') . '</footer>'
        . '</main><div class="wmk" aria-hidden="true"><svg viewBox="0 0 2507 2506"><use href="#emblem"/></svg></div>'
        . '<svg width="0" height="0" style="position:absolute" aria-hidden="true">' . FX_SYMBOLS . '</svg>'
        . '<script src="assets/qrcode.js"></script><script>(function(){var el=document.querySelector(".qrc");if(!el||typeof qrcode!=="function")return;var q=qrcode(0,"M");q.addData(el.getAttribute("data-url"));q.make();el.outerHTML=q.createSvgTag({cellSize:3,margin:0,scalable:true});})();</script></body></html>';
}

function rc_css()
{
    return <<<'CSS'
@font-face{font-family:'Cormorant Garamond';font-style:normal;font-weight:300 600;font-display:swap;src:url(assets/fonts/cormorant-latin.woff2) format('woff2')}
@font-face{font-family:'Jost';font-style:normal;font-weight:300 500;font-display:swap;src:url(assets/fonts/jost-latin.woff2) format('woff2')}
@font-face{font-family:'Charles Wright Singapore';src:url(assets/fonts/CharlesWrightSingapore.woff2) format('woff2');font-display:swap}
@font-face{font-family:'Tajawal';font-weight:400;font-display:swap;src:url(assets/fonts/tajawal-400-arabic.woff2) format('woff2')}
@font-face{font-family:'Tajawal';font-weight:700;font-display:swap;src:url(assets/fonts/tajawal-700-arabic.woff2) format('woff2')}
:root{--bg:#efebe3;--paper:#fff;--ink:#0b0b0b;--soft:#3d3a35;--muted:#6f685c;--line:#e6dfcf;--gold:#9a7a2c;--gold2:#c9a64b;--band:#0b0b0b;--bandink:#d4af37;--zebra:#faf8f3;--red:#a3261b;--green:#2f6b3a;color-scheme:light}
@media (prefers-color-scheme:dark){:root:not([data-theme=light]){--bg:#050505;--paper:#111010;--ink:#f3eee3;--soft:#d8d1c3;--muted:#a9a091;--line:#2c2820;--gold:#d4af37;--gold2:#d4af37;--band:#000;--bandink:#d4af37;--zebra:#171513;--red:#ff8a7a;--green:#8fd19e;color-scheme:dark}}
:root[data-theme=dark]{--bg:#050505;--paper:#111010;--ink:#f3eee3;--soft:#d8d1c3;--muted:#a9a091;--line:#2c2820;--gold:#d4af37;--gold2:#d4af37;--band:#000;--bandink:#d4af37;--zebra:#171513;--red:#ff8a7a;--green:#8fd19e;color-scheme:dark}
:root[data-theme=light]{--bg:#efebe3;--paper:#fff;--ink:#0b0b0b;--soft:#3d3a35;--muted:#6f685c;--line:#e6dfcf;--gold:#9a7a2c;--gold2:#c9a64b;--band:#0b0b0b;--bandink:#d4af37;--zebra:#faf8f3;--red:#a3261b;--green:#2f6b3a;color-scheme:light}
*{box-sizing:border-box;font-style:normal}
html{-webkit-text-size-adjust:100%}
body{margin:0;background:var(--bg);color:var(--ink);font:400 14px/1.45 Jost,Tajawal,system-ui,sans-serif;padding:16px 12px 32px}
[dir=rtl] body,[dir=rtl] .pn{font-family:Tajawal,Jost,system-ui,sans-serif}
.bar{max-width:794px;margin:0 auto 12px;display:flex;justify-content:flex-end;gap:8px}
.bar a,.bar button{font:500 13px/1 Jost,Tajawal,sans-serif;letter-spacing:.06em;text-transform:uppercase;padding:11px 18px;border-radius:999px;cursor:pointer;text-decoration:none}
.bar .lng{border:1px solid var(--line);color:var(--ink);background:var(--paper)}
.bar .pdf{border:0;background:var(--gold);color:#fff}
@media (prefers-color-scheme:dark){:root:not([data-theme=light]) .bar .pdf{color:#0b0b0b}}
:root[data-theme=dark] .bar .pdf{color:#0b0b0b}
.sheet{max-width:794px;margin:0 auto;background:var(--paper);border-top:6px solid var(--band);border-bottom:6px solid var(--band);box-shadow:0 10px 40px rgba(0,0,0,.08);padding:30px 44px 26px;position:relative}
.sheet::before,.sheet::after{content:"";position:absolute;left:0;right:0;height:1px;background:var(--gold2)}
.sheet::before{top:4px}.sheet::after{bottom:4px}
/* the FOMAXO logo as a faint watermark in the middle (on every page of the PDF) */
.wmk{position:fixed;left:50%;top:50%;width:min(460px,72vw);transform:translate(-50%,-50%);color:var(--ink);opacity:.045;pointer-events:none;z-index:2;display:flex;flex-direction:column;align-items:center;gap:6%}
.wmk svg{display:block;width:70%;height:auto}.wmk svg+svg{width:100%;aspect-ratio:3196/481}
@media (prefers-color-scheme:dark){:root:not([data-theme=light]) .wmk{opacity:.06}}
:root[data-theme=dark] .wmk{opacity:.06}
.hd{text-align:center;color:var(--ink)}
.hd .em{width:52px;height:52px;display:block;margin:0 auto 10px;color:var(--ink)}
.hd .wm{height:26px;width:auto;aspect-ratio:3196/481;display:block;margin:0 auto;color:var(--ink)}
.hd .tg{margin:8px 0 0;font-size:10.5px;letter-spacing:.32em;text-transform:uppercase;color:var(--muted)}
.hd h1{margin:16px 0 0;display:flex;align-items:center;gap:16px;font:500 26px/1 'Cormorant Garamond',Tajawal,serif;letter-spacing:.42em;text-transform:uppercase;color:var(--gold)}
.hd h1::before,.hd h1::after{content:"";flex:1;height:1px;background:linear-gradient(90deg,transparent,var(--gold2))}
.hd h1::after{background:linear-gradient(270deg,transparent,var(--gold2))}
.hd h1 span{padding-left:.42em}
[dir=rtl] .hd h1{letter-spacing:0}[dir=rtl] .hd h1 span{padding:0}
h2{margin:0 0 6px;font:500 10.5px/1.2 Jost,Tajawal,sans-serif;letter-spacing:.22em;text-transform:uppercase;color:var(--gold)}
[dir=rtl] h2,[dir=rtl] .tg,[dir=rtl] th{letter-spacing:0}
.meta{display:flex;justify-content:space-between;gap:16px;margin:22px 0 18px}
.meta dl{margin:0;display:grid;grid-template-columns:auto 1fr;gap:3px 14px}
.meta dt{color:var(--muted);font-size:12px;text-transform:uppercase;letter-spacing:.08em}
.meta dd{margin:0;font-weight:500;overflow-wrap:anywhere}
.meta .rn{text-align:end}
.meta .no{margin:0;font:500 24px/1.1 'Charles Wright Singapore',Jost,sans-serif;letter-spacing:.02em}
.meta .dt{margin:6px 0 0;font-weight:500}.meta .dt span{color:var(--muted);font-size:12px;text-transform:uppercase;letter-spacing:.08em;margin-inline-end:6px}
.it{width:100%;border-collapse:collapse;margin:0}
.it th{font:500 10.5px/1.2 Jost,Tajawal,sans-serif;letter-spacing:.16em;text-transform:uppercase;color:var(--paper);background:var(--ink);padding:8px 10px;text-align:start}
.it td{padding:5px 10px;border-bottom:1px solid var(--line);vertical-align:middle}
.it tbody tr:nth-child(even) td{background:var(--zebra)}
.it tr{break-inside:avoid;page-break-inside:avoid}
.it thead{display:table-header-group}
.it .c-sz,.it .c-q{text-align:center;white-space:nowrap}
.it .c-un,.it .c-am{text-align:end;white-space:nowrap}
.it .c-am{font-weight:500}
.pn{display:inline;font:600 15.5px/1.15 'Cormorant Garamond',Tajawal,serif;letter-spacing:.06em;text-transform:uppercase}
.pf{display:block;font-size:10.5px;line-height:1.2;color:var(--muted);margin-top:1px}
.gift{display:inline-block;margin-inline-start:8px;padding:1px 7px;border:1px solid var(--gold2);border-radius:999px;font-size:9.5px;letter-spacing:.14em;text-transform:uppercase;color:var(--gold);vertical-align:2px}
.m{font-family:'Charles Wright Singapore',Jost,sans-serif;white-space:nowrap;direction:ltr;unicode-bidi:isolate}
.m small{font-size:.68em;margin-right:3px;letter-spacing:.04em}
s{display:inline-block;position:relative;color:var(--muted);font-size:11px;text-decoration:none;margin-inline-end:6px}
s::after{content:"";position:absolute;left:-1px;right:-1px;top:52%;height:1.2px;background:currentColor}   /* the price font draws its own strike line at the bottom, so the line is drawn here through the middle */
.fr{color:var(--gold);letter-spacing:.14em;text-transform:uppercase;font-size:12px;font-weight:500}
[dir=rtl] .fr{letter-spacing:0}
.dash{color:var(--muted)}
.bt{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.05fr);gap:28px;margin-top:20px;break-inside:avoid;page-break-inside:avoid}
.lf h2{margin-top:2px}.lf h2+*{margin-bottom:14px}
.ad{margin:0;font-size:13px;line-height:1.55;color:var(--soft);overflow-wrap:anywhere}
.sh{border:1px solid var(--line);border-inline-start:3px solid var(--gold2);padding:8px 12px;display:grid;gap:4px}
.sh div{display:flex;justify-content:space-between;gap:12px;font-size:13px}.sh span{color:var(--muted)}.sh b{font-weight:500;text-align:end;overflow-wrap:anywhere}
.sh .trk{font-family:'Charles Wright Singapore',Jost,sans-serif;letter-spacing:.06em}
.py{display:flex;align-items:center;gap:10px;flex-wrap:wrap;border:1px solid var(--ink);padding:10px 12px;font-size:13px}
.py>span{color:var(--muted);text-transform:uppercase;letter-spacing:.12em;font-size:10.5px}
.py b{font-weight:500}
.st{margin-inline-start:auto;padding:4px 12px;border-radius:999px;font-size:10.5px;font-weight:500;letter-spacing:.16em;text-transform:uppercase}
[dir=rtl] .st,[dir=rtl] .py>span{letter-spacing:0}
.st-paid{background:var(--gold);color:#fff}
@media (prefers-color-scheme:dark){:root:not([data-theme=light]) .st-paid{color:#0b0b0b}}
:root[data-theme=dark] .st-paid{color:#0b0b0b}
.st-unpaid,.st-cancelled,.st-refunded{border:1px solid var(--red);color:var(--red)}
.sm .tr{display:flex;justify-content:space-between;gap:12px;padding:5px 0;font-size:13.5px}
.sm .tr>span:first-child{color:var(--soft)}
.sm .dc>span{color:var(--gold)!important}
.sm .tt{display:flex;justify-content:space-between;align-items:baseline;gap:12px;margin-top:8px;padding:12px 0 10px;border-top:1px solid var(--ink);border-bottom:1px solid var(--ink)}
.sm .tt>span:first-child{font:600 18px/1 'Cormorant Garamond',Tajawal,serif;letter-spacing:.14em;text-transform:uppercase}
[dir=rtl] .sm .tt>span:first-child{letter-spacing:0}
.sm .tt .m{font-size:28px;line-height:1}
.sv{margin-top:10px;text-align:center;padding:8px 10px;background:var(--zebra);border:1px dashed var(--gold2);color:var(--gold);font-size:12.5px;letter-spacing:.04em}
.ft{text-align:center;margin-top:26px;break-inside:avoid;page-break-inside:avoid}
.ty{margin:0;font:500 26px/1.1 'Cormorant Garamond',Tajawal,serif;letter-spacing:.04em}
.ty::after{content:"";display:block;width:60px;height:1px;background:var(--gold2);margin:12px auto 0}
.si{margin:10px 0 0;font-size:11px;letter-spacing:.18em;text-transform:uppercase;color:var(--muted)}
[dir=rtl] .si{letter-spacing:0}
@media (max-width:640px){
  body{padding:10px 8px 24px;font-size:13px}
  .bar{justify-content:center}
  .sheet{padding:22px 16px 20px}
  .hd .em{width:42px;height:42px}.hd .wm{height:20px}.hd h1{font-size:21px;gap:10px}
  .meta{flex-direction:column;gap:14px;margin:18px 0 14px}.meta .rn{text-align:start}.meta .no{font-size:20px}
  .it .c-un{display:none}.it th{padding:7px 6px}.it td{padding:5px 6px}.it th{letter-spacing:.08em;font-size:9.5px}.pn{font-size:15px}
  .bt{grid-template-columns:1fr;gap:18px}.bt .sm{order:-1}
  .sm .tt .m{font-size:24px}.ty{font-size:22px}
}
/* .pm: the printed page's width and text size, used for a moment before printing to count the pages (so each page gets its watermark) */
.pm .bar{display:none}.pm body{padding:0;font-size:12.5px}.pm .sheet{width:186mm;max-width:none;padding:6mm 6mm 4mm;border-width:4px}.pm .it .c-un{display:table-cell!important}
@page{size:A4;margin:12mm 12mm 14mm}
@page{@bottom-center{content:counter(page) " / " counter(pages);font:9px Jost,sans-serif;color:#8a8273}}
@media print{
  :root{--bg:#fff;--paper:#fff;--ink:#0b0b0b;--soft:#3d3a35;--muted:#6f685c;--line:#e6dfcf;--gold:#9a7a2c;--gold2:#c9a64b;--zebra:#faf8f3;--red:#a3261b;color-scheme:light}
  body{padding:0;background:#fff;font-size:12.5px;-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .bar{display:none}
  .wmk{display:none}
  .sheet{max-width:none;box-shadow:none;padding:6mm 6mm 4mm;border-width:4px;min-height:calc(var(--pages,1) * 271mm - 20mm);background:var(--wmprint) center top/100% 271mm repeat-y;-webkit-print-color-adjust:exact;print-color-adjust:exact}   /* one watermark per A4 page: the picture is one page tall (297mm less the page margins) */
  .p1 .sheet{min-height:0;background-size:contain;background-position:center;background-repeat:no-repeat}   /* one page: no fixed height (some browsers, like Safari, print a shorter page), the watermark in the middle of the receipt */
  .it tbody tr:nth-child(even) td{background:rgba(120,100,50,.05)}
  .st-paid{color:#fff!important}
  .it .c-un{display:table-cell!important}
}
/* ---- store receipt additions ---- */
.st-pending{border:1px solid var(--gold2);color:var(--gold)}
.st-void,.st-failed{border:1px solid var(--red);color:var(--red)}
.note{max-width:794px;margin:0 auto 12px;padding:10px 14px;border:1px solid var(--gold2);color:var(--gold);background:var(--paper);text-align:center;font-size:13px}
.note.bad{border-color:var(--red);color:var(--red)}
.si.site{margin-top:4px}
.qr{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;margin:18px auto 0;text-align:center;font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:var(--muted)}
.qr svg{width:84px;height:84px;background:#fff;padding:5px;border-radius:4px}
[dir=rtl] .qr{letter-spacing:0}
.tr.vt span{color:var(--muted)!important;font-size:12.5px}
.embed .bar{display:none}.embed body{padding:0;background:var(--paper)}.embed .sheet{box-shadow:none}

CSS;
}
