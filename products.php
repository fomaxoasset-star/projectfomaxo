<?php
/* FOMAXO — the product list for the website, as saved on fomaxo.com/admin → Products.
   {"products": null} when the database is not set up or down: the website then keeps the list built into index.html. */
header('Content-Type: application/json');
header('Cache-Control: public, max-age=30');
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
if ($pdo) { try { $live = []; foreach (fomaxo_product_rows($pdo) ?: [] as $r) if (!$r['hidden']) $live[$r['id']] = true;
  foreach (json_decode((string)fomaxo_setting($pdo, 'videos'), true) ?: [] as $v) if (!empty($v['on']) && isset($live[$v['product'] ?? '']) && preg_match('~^vid/[a-z0-9-]+\.(mp4|mov|webm)$~', $v['file'] ?? ''))
    $videos[] = ['v' => $v['file'], 'p' => $v['product']] + (($v['cover'] ?? '') !== '' ? ['c' => $v['cover']] : []); } catch (Throwable $e) {} }
/* cash on delivery minimum and maximum (admin → Settings → Cash on delivery); max 0 = no upper limit */
[$codMin, $codMax, $codFee] = fomaxo_cod_limits($pdo ?: null);
/* site pages hidden on admin → Settings → Site pages (their links go and their address opens Home) */
$hidePages = [];
if ($pdo) { try { $hidePages = array_values(array_intersect(json_decode((string)fomaxo_setting($pdo, 'hide_pages'), true) ?: [], array_keys(FX_SITE_PAGES))); } catch (Throwable $e) {} }
echo '{"videos":' . fomaxo_json($videos) . ',"ads":' . fomaxo_json((object)$ads) . ',"hidePages":' . json_encode($hidePages) . ',"cod":{"min":' . $codMin . ',"max":' . $codMax . ',"fee":' . $codFee . '},"products":' . ($out ? '[' . implode(',', $out) . ']' : 'null') . ',"saleEnds":' . ($saleEnds ?? 'null') . ',"saleAlways":' . ($saleAlways ? 'true' : 'false') . ',"salePopup":' . ($salePopup ? 'true' : 'false') . ',"saleLine":' . ($saleLine ? 'true' : 'false') . ',"salePct":' . ($salePct ?: 'null') . ',"newPop":' . ($newPop ? fomaxo_json($newPop) : 'null') . ',"saleItems":' . fomaxo_json(array_values($saleItems)) . ',"saleWords":' . fomaxo_json((object)array_filter($saleWords, fn($v) => is_string($v) && $v !== '')) . ',"saleLines":' . ($saleLines === null ? 'null' : fomaxo_json($saleLines)) . ',"saleSize":' . fomaxo_json($saleSize) . '}';
