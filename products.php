<?php
/* FOMAXO — the product list for the website, as saved on fomaxo.com/admin → Products.
   {"products": null} when the database is not set up or down: the website then keeps the list built into index.html. */
header('Content-Type: application/json');
header('Cache-Control: no-store');   // never kept by the browser, Hostinger or a CDN: a change saved on admin shows on the website straight away
header('X-LiteSpeed-Cache-Control: no-cache');
require __DIR__ . '/orders-lib.php';
$out = [];
foreach (fomaxo_product_rows() ?: [] as $r) {
  if (!$r['hidden']) { $out[] = $r['data']; continue; }
  $p = json_decode($r['data']); if (!is_object($p)) continue;
  $p->hidden = true;   // still sent, so links to it don't break, but not listed or sold
  $out[] = fomaxo_json($p);
}
/* limited-time sale: when it ends (Unix time), as set on fomaxo.com/admin → Offer; null = no countdown */
$saleEnds = null; $saleAlways = false; $salePopup = true; $saleLine = true; $salePct = 0; $newPop = null; $saleItems = []; $saleWords = []; $saleLines = null; $saleSize = fomaxo_popup_sizes(null);   // saleSize: the popup's part sizes on laptop / phone (admin → Offer → Preview)   // saleItems: the products in the offer (empty = every one with an old price); saleWords: the popup's own words   // newPop: the "Coming soon" / "Just arrived" popup   // saleAlways: on with no timer until turned off; popup / line: what shows (admin → Offer)
if ($pdo = fomaxo_db()) { try { $v = (int)fomaxo_setting($pdo, 'sale_ends'); if ($v > time()) $saleEnds = $v; $saleAlways = (string)fomaxo_setting($pdo, 'sale_always') === '1'; $salePopup = (string)fomaxo_setting($pdo, 'sale_popup') !== '0'; $saleLine = (string)fomaxo_setting($pdo, 'sale_line') !== '0'; $salePct = (int)fomaxo_setting($pdo, 'sale_pct'); $np = json_decode((string)fomaxo_setting($pdo, 'np'), true); if (is_array($np) && !empty($np['on']) && ($np['name'] ?? '') !== '') $newPop = ['status' => $np['status'], 'label' => $np['label'] ?? '', 'name' => $np['name'], 'line' => $np['line'] ?? '', 'product' => $np['product'] ?? '', 'fs' => fomaxo_popup_sizes($np['fs'] ?? null, true)];
  $saleItems = json_decode((string)fomaxo_setting($pdo, 'sale_items'), true) ?: []; $saleWords = json_decode((string)fomaxo_setting($pdo, 'sale_words'), true) ?: []; $saleSize = fomaxo_popup_sizes(json_decode((string)fomaxo_setting($pdo, 'sale_size'), true)); $v = fomaxo_setting($pdo, 'sale_lines'); if ($v !== null) $saleLines = array_values((array)(json_decode((string)$v, true) ?: [])); } catch (Throwable $e) {} }
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
    $videos[] = ['v' => $v['file']] + ['p' => $ps[0] ?? ''] + (count($ps) > 1 ? ['a' => array_slice($ps, 1)] : []) + (($v['cover'] ?? '') !== '' ? ['c' => $v['cover']] : []) + (!$ps && ($v['words'] ?? '') !== '' ? ['w' => (string)$v['words']] : []) + (!empty($v['wide']) ? ['wd' => 1] : []); } } catch (Throwable $e) {} }
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
/* cash on delivery minimum and maximum (admin → Settings → Cash on delivery); max 0 = no upper limit */
[$codMin, $codMax, $codFee] = fomaxo_cod_limits($pdo ?: null);
/* site pages hidden on admin → Settings → Site pages (their links go and their address opens Home) */
$hidePages = [];
if ($pdo) { try { $hidePages = array_values(array_intersect(json_decode((string)fomaxo_setting($pdo, 'hide_pages'), true) ?: [], array_keys(FX_SITE_PAGES))); } catch (Throwable $e) {} }
$btSet = fomaxo_bt($pdo ?: null);
echo '{"bt":' . fomaxo_json(['pct' => $btSet['pct'], 'aed' => $btSet['aed'], 'on' => $btSet['on'], 'pairs' => (object)$btSet['pairs']]) . ',"together":' . fomaxo_json((object)$together) . ',"videos":' . fomaxo_json($videos) . ',"ads":' . fomaxo_json((object)$ads) . ',"hidePages":' . json_encode($hidePages) . ',"cod":{"min":' . $codMin . ',"max":' . $codMax . ',"fee":' . $codFee . '},"products":' . ($out ? '[' . implode(',', $out) . ']' : 'null') . ',"saleEnds":' . ($saleEnds ?? 'null') . ',"saleAlways":' . ($saleAlways ? 'true' : 'false') . ',"salePopup":' . ($salePopup ? 'true' : 'false') . ',"saleLine":' . ($saleLine ? 'true' : 'false') . ',"salePct":' . ($salePct ?: 'null') . ',"newPop":' . ($newPop ? fomaxo_json($newPop) : 'null') . ',"saleItems":' . fomaxo_json(array_values($saleItems)) . ',"saleWords":' . fomaxo_json((object)array_filter($saleWords, fn($v) => is_string($v) && $v !== '')) . ',"saleLines":' . ($saleLines === null ? 'null' : fomaxo_json($saleLines)) . ',"saleSize":' . fomaxo_json($saleSize) . '}';

/* Automatic Instagram reels (admin → Products → Videos): at most once an hour, after the visitor already has the answer above */
if ($pdo && is_file(dirname(__DIR__) . '/fomaxo-instagram.php') && time() - (int)fomaxo_setting($pdo, 'ig_sync_at') >= 3600) {
  if (function_exists('litespeed_finish_request')) litespeed_finish_request(); elseif (function_exists('fastcgi_finish_request')) fastcgi_finish_request(); else return;
  ignore_user_abort(true);
  require_once __DIR__ . '/instagram-lib.php';
  try { fomaxo_ig_sync($pdo); } catch (Throwable $e) { error_log('FOMAXO Instagram: ' . $e->getMessage()); }
}
