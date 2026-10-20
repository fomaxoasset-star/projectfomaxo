<?php
/* FOMAXO — the product list for the website, as saved on fomaxo.com/admin → Products.
   {"products": null} when the database is not set up or down: the website then keeps the list built into index.html. */
header('Content-Type: application/json');
header('Cache-Control: no-store');   // never kept by the browser, Hostinger or a CDN: a change saved on admin shows on the website straight away
header('X-LiteSpeed-Cache-Control: no-cache');
require __DIR__ . '/orders-lib.php';
require_once __DIR__ . '/media-lib.php';
$out = [];
foreach (fomaxo_product_rows() ?: [] as $r) {
  if (!$r['hidden'] && strpos($r['data'], '"saleOff"') === false) { $out[] = $r['data']; continue; }
  $p = json_decode($r['data']); if (!is_object($p)) continue;
  if (!empty($p->saleOff)) {   // discount Off (admin → Products): the Original price, no crossed-out price; same rule as fomaxo_sale_apply()
    foreach ((array)($p->compareAt ?? []) as $s => $c) if (is_numeric($c) && isset($p->prices->{$s}) && $c > $p->prices->{$s}) $p->prices->{$s} = $c + 0;
    unset($p->compareAt);
  }
  unset($p->saleOff);
  if ($r['hidden']) $p->hidden = true;   // still sent, so links to it don't break, but not listed or sold
  $out[] = fomaxo_json($p);
}
/* limited-time sale: when it ends (Unix time), as set on fomaxo.com/admin → Offer; null = no countdown */
$saleEnds = null; $saleAlways = false; $salePopup = true; $saleLine = true; $salePct = 0; $newPop = null; $saleItems = []; $saleWords = []; $saleLines = null; $saleSize = fomaxo_popup_sizes(null);   // saleLines: the products with the line by prices, 'all' = every product with an old price   // saleSize: the popup's part sizes on laptop / phone (admin → Offer → Preview)   // saleItems: the products in the offer (empty = every one with an old price); saleWords: the popup's own words   // newPop: the "Coming soon" / "Just arrived" popup   // saleAlways: on with no timer until turned off; popup / line: what shows (admin → Offer)
if ($pdo = fomaxo_db()) { try { $v = (int)fomaxo_setting($pdo, 'sale_ends'); if ($v > time()) $saleEnds = $v; $saleAlways = (string)fomaxo_setting($pdo, 'sale_always') === '1'; $salePopup = (string)fomaxo_setting($pdo, 'sale_popup') !== '0'; $saleLine = (string)fomaxo_setting($pdo, 'sale_line') !== '0'; $salePct = (int)fomaxo_setting($pdo, 'sale_pct'); $np = json_decode((string)fomaxo_setting($pdo, 'np'), true); if (is_array($np) && !empty($np['on']) && ($np['name'] ?? '') !== '') $newPop = ['status' => $np['status'], 'label' => $np['label'] ?? '', 'name' => $np['name'], 'line' => $np['line'] ?? '', 'product' => $np['product'] ?? '', 'fs' => fomaxo_popup_sizes($np['fs'] ?? null, true)];
  $saleItems = json_decode((string)fomaxo_setting($pdo, 'sale_items'), true) ?: []; $saleWords = json_decode((string)fomaxo_setting($pdo, 'sale_words'), true) ?: []; $saleSize = fomaxo_popup_sizes(json_decode((string)fomaxo_setting($pdo, 'sale_size'), true)); $v = fomaxo_setting($pdo, 'sale_lines'); if ($v !== null) $saleLines = array_values((array)(json_decode((string)$v, true) ?: [])); if ((string)fomaxo_setting($pdo, 'sale_lines_all') === '1') $saleLines = 'all'; } catch (Throwable $e) {} }
/* ads tracking IDs (admin → Settings → Ads tracking): Meta Pixel, TikTok Pixel, Google tag, Microsoft Clarity; the website loads only the ones filled in */
$ads = [];
if ($pdo) { try { $ads = json_decode((string)fomaxo_setting($pdo, 'ads'), true) ?: []; } catch (Throwable $e) {} }
$ads = array_filter(array_intersect_key((array)$ads, array_flip(['meta', 'tiktok', 'google', 'gads', 'clarity'])), fn($v) => is_string($v) && $v !== '');
/* shop videos (admin → Products → Videos): only the ones switched on, of products on the website; the website shows them on the home page */
$videos = [];
if ($pdo && (string)fomaxo_setting($pdo, 'videos_off') !== '1') { try { $live = []; foreach (fomaxo_product_rows($pdo) ?: [] as $r) if (!$r['hidden']) $live[$r['id']] = true;
  foreach (json_decode((string)fomaxo_setting($pdo, 'videos'), true) ?: [] as $v) if (!empty($v['on']) && preg_match('~^vid/[a-z0-9-]+\.(mp4|mov|webm)$~', $v['file'] ?? '')) {   // only videos on our server: an Instagram-player entry sends shoppers off to Instagram
    $all = array_values(array_unique(array_filter(array_merge([(string)($v['product'] ?? '')], (array)($v['also'] ?? [])), fn($id) => is_string($id) && $id !== '')));   // one product, several, or none (a Shop now button)
    $ps = array_values(array_filter($all, fn($id) => isset($live[$id]))); if ($all && !$ps) continue;   // every product in it is hidden
    $videos[] = ['v' => $v['file']] + ['p' => $ps[0] ?? ''] + (count($ps) > 1 ? ['a' => array_slice($ps, 1)] : []) + (($v['cover'] ?? '') !== '' ? ['c' => $v['cover']] : []) + (preg_match('~^vid/[a-z0-9-]+-p\.mp4$~', (string)($v['prev'] ?? '')) ? ['pv' => $v['prev']] : (fx_small_video($v['file'] ?? '') ? ['pv' => $v['file']] : [])) + (!$ps && ($v['words'] ?? '') !== '' ? ['w' => (string)$v['words']] : []) + (!empty($v['wide']) ? ['wd' => 1] : []); } } catch (Throwable $e) {} }
/* bought together (product page): per product, the other products most often in the same real order (2+ orders together), best first.
   King and the Discovery Set are left out (not part of multi-buy); hidden products are left out on the website. */
$together = [];
if ($pdo) { try { $pair = [];
  foreach ($pdo->query("SELECT lines_json FROM fx_orders WHERE test = 0 AND status IN ('New', 'Paid', 'Delivered') AND lines_json IS NOT NULL ORDER BY id DESC LIMIT 2000") as $r) {
    $ids = [];
    foreach ((array)json_decode($r['lines_json'], true) as $l) { $id = is_array($l) ? (string)($l['id'] ?? '') : ''; if ($id !== '' && $id !== 'king' && $id !== 'discovery' && preg_match('~^[a-z0-9-]{1,30}$~', $id)) $ids[$id] = true; }
    $ids = array_keys($ids);
    foreach ($ids as $a) foreach ($ids as $b) if ($a !== $b) $pair[$a][$b] = ($pair[$a][$b] ?? 0) + 1;
  }
  foreach ($pair as $a => $m) { $m = array_filter($m, fn($n) => $n >= 2); arsort($m); if ($m) $together[$a] = array_slice(array_keys($m), 0, 4); }
} catch (Throwable $e) {} }
/* "You may also like" (product page) order, AJAY 2026-10-11: per product, the other products most often in the same real order (any count, King and the
   Discovery Set too), and every product by how many real orders it was in. Only the order is sent, never the counts. The website fills the rest at random. */
$also = ['pairs' => [], 'top' => []];
if ($pdo) { try { $pair = []; $top = [];
  foreach ($pdo->query("SELECT lines_json FROM fx_orders WHERE test = 0 AND status IN ('New', 'Paid', 'Delivered') AND lines_json IS NOT NULL ORDER BY id DESC LIMIT 3000") as $r) {
    $ids = [];
    foreach ((array)json_decode($r['lines_json'], true) as $l) { $id = is_array($l) ? (string)($l['id'] ?? '') : ''; if ($id !== '' && preg_match('~^[a-z0-9-]{1,30}$~', $id)) $ids[$id] = true; }
    $ids = array_keys($ids);
    foreach ($ids as $a) { $top[$a] = ($top[$a] ?? 0) + 1; foreach ($ids as $b) if ($a !== $b) $pair[$a][$b] = ($pair[$a][$b] ?? 0) + 1; }
  }
  foreach ($pair as $a => $m) { arsort($m); $also['pairs'][$a] = array_keys($m); }
  arsort($top); $also['top'] = array_keys($top);
} catch (Throwable $e) {} }
$also['pairs'] = (object)$also['pairs'];
/* "14 purchased this week" (product page): bottles of each product really bought in the last 7 days, the same number as admin → Products → Sold → 7 days, from real orders only
   (test, unpaid and cancelled orders don't count; trashed ones still do, AJAY: the number purchased stays). Only products with more than 5 (6 or more) are sent, with the real number; 5 or fewer and the line doesn't show. Never a made-up number. */
$sold = [];
if ($pdo) { try { $who = []; $year = date('Y-m-d', strtotime('-6 day')) . ' 00:00:00';   // the last 7 days: today and the 6 days before, like admin → 7 days
  foreach (array_merge($pdo->query("SELECT lines_json, phone, created_at FROM fx_orders WHERE test = 0 AND status IN ('New', 'Paid', 'Delivered') AND lines_json IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC), fx_trash_sold($pdo, $year)) as $r) {
    foreach ((array)json_decode($r['lines_json'], true) as $l) { $id = is_array($l) ? (string)($l['id'] ?? '') : ''; if ($id === '' || !preg_match('~^[a-z0-9-]{1,30}$~', $id)) continue;
      $opt = preg_replace('~[^a-z0-9]~', '', strtolower((string)($l['opt'] ?? ''))); if ($opt === '') continue;
      if ($r['created_at'] >= $year) $who[$id][$opt] = ($who[$id][$opt] ?? 0) + max(0, (int)($l['qty'] ?? 0)); }
  }
  foreach ($who as $id => $byOpt) foreach ($byOpt as $opt => $c) if ($c > 5) $sold[$id][(string)$opt] = $c;   // each size on its own: the real number of bottles of that size, from 6 up ("14 purchased this week")
  foreach ($who as $id => $byOpt) if (($t = array_sum($byOpt)) > 5) $sold[$id]['all'] = $t;   // shop cards: all sizes of that product together
  foreach ($sold as $id => $byOpt) $sold[$id] = (object)$byOpt;
} catch (Throwable $e) {} }
/* cash on delivery minimum and maximum (admin → Settings → Cash on delivery); max 0 = no upper limit */
[$codMin, $codMax, $codFee] = fomaxo_cod_limits($pdo ?: null);
/* site pages hidden on admin → Settings → Site pages (their links go and their address opens Home) */
$hidePages = [];
if ($pdo) { try { $hidePages = array_values(array_intersect(json_decode((string)fomaxo_setting($pdo, 'hide_pages'), true) ?: [], array_keys(FX_SITE_PAGES))); } catch (Throwable $e) {} }
$btSet = fomaxo_bt($pdo ?: null);
/* same-day delivery (admin → Settings → Cash): fee, free from, 2 PM and the areas it reaches; null = not offered */
$sdSet = fomaxo_sameday($pdo ?: null);
$sdOut = $sdSet['on'] ? ['fee' => $sdSet['fee'], 'free' => $sdSet['free'], 'cut' => FX_SD_CUTOFF, 'cod' => $sdSet['cod'], 'by' => $sdSet['by'], 'full' => fomaxo_sameday_full($sdSet, $pdo ?: null), 'areas' => array_map('array_keys', fomaxo_sameday_areas($sdSet))] : null;
if ($sdOut) {   // this visitor's emirate from their internet address (approximate, nothing asked), only when it has same-day areas: the product page says so
  require_once __DIR__ . '/geo-lib.php';
  [$geoCc, $geoEm] = fomaxo_geo(fomaxo_geo_ip());
  if ($geoEm !== null && isset($sdOut['areas'][$geoEm])) $sdOut['here'] = $geoEm;
}
echo '{"sold":' . fomaxo_json((object)$sold) . ',"bt":' . fomaxo_json(['pct' => $btSet['pct'], 'aed' => $btSet['aed'], 'on' => $btSet['on'], 'pairs' => (object)$btSet['pairs']]) . ',"together":' . fomaxo_json((object)$together) . ',"also":' . fomaxo_json($also) . ',"videos":' . fomaxo_json($videos) . ',"ads":' . fomaxo_json((object)$ads) . ',"hidePages":' . json_encode($hidePages) . ',"cod":{"min":' . $codMin . ',"max":' . $codMax . ',"fee":' . $codFee . '},"sameday":' . fomaxo_json($sdOut) . ',"products":' . ($out ? '[' . implode(',', $out) . ']' : 'null') . ',"saleEnds":' . ($saleEnds ?? 'null') . ',"saleAlways":' . ($saleAlways ? 'true' : 'false') . ',"salePopup":' . ($salePopup ? 'true' : 'false') . ',"saleLine":' . ($saleLine ? 'true' : 'false') . ',"salePct":' . ($salePct ?: 'null') . ',"newPop":' . ($newPop ? fomaxo_json($newPop) : 'null') . ',"saleItems":' . fomaxo_json(array_values($saleItems)) . ',"saleWords":' . fomaxo_json((object)array_filter($saleWords, fn($v) => is_string($v) && $v !== '')) . ',"saleLines":' . ($saleLines === null ? 'null' : fomaxo_json($saleLines)) . ',"saleSize":' . fomaxo_json($saleSize) . '}';

/* Automatic Instagram reels (admin → Products → Videos): at most once an hour, after the visitor already has the answer above */
if ($pdo && is_file(dirname(__DIR__) . '/fomaxo-instagram.php') && time() - (int)fomaxo_setting($pdo, 'ig_sync_at') >= 3600) {
  if (function_exists('litespeed_finish_request')) litespeed_finish_request(); elseif (function_exists('fastcgi_finish_request')) fastcgi_finish_request(); else return;
  ignore_user_abort(true);
  require_once __DIR__ . '/instagram-lib.php';
  try { fomaxo_ig_sync($pdo); } catch (Throwable $e) { error_log('FOMAXO Instagram: ' . $e->getMessage()); }
}
