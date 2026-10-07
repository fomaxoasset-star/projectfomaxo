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
/* limited-time sale, as set on fomaxo.com/admin → Offer: when it ends (Unix time; null = no countdown), always on (no timer), the popup's words and products, which products show the line by prices */
$saleEnds = null; $saleAlways = false; $salePopup = true; $saleLine = true; $salePct = 0; $newPop = null;
$saleTxt = ['saleTop' => 'Limited time offer', 'saleUnder' => 'on selected fragrances', 'saleBtn' => 'Shop the offer']; $popIds = []; $lineIds = null;   // lineIds null = every product with an old price (before the product list was set)
$ids = fn($v) => is_array($a = json_decode((string)$v, true)) ? array_values(array_filter($a, 'is_string')) : null;
if ($pdo = fomaxo_db()) { try {
  $v = (int)fomaxo_setting($pdo, 'sale_ends'); if ($v > time()) $saleEnds = $v; $saleAlways = (string)fomaxo_setting($pdo, 'sale_always') === '1';
  $salePopup = (string)fomaxo_setting($pdo, 'sale_popup') !== '0'; $saleLine = (string)fomaxo_setting($pdo, 'sale_line') !== '0'; $salePct = (int)fomaxo_setting($pdo, 'sale_pct');
  foreach (['saleTop' => 'sale_top', 'saleUnder' => 'sale_under', 'saleBtn' => 'sale_btn'] as $o => $k) { $t = trim((string)fomaxo_setting($pdo, $k)); if ($t !== '') $saleTxt[$o] = $t; }
  $popIds = $ids(fomaxo_setting($pdo, 'sale_pop_ids')) ?: []; $lineIds = $ids(fomaxo_setting($pdo, 'sale_line_ids'));
  $np = json_decode((string)fomaxo_setting($pdo, 'np'), true);   // the new product popup ("Coming soon", "Just arrived" …)
  if (is_array($np) && !empty($np['on']) && ($np['name'] ?? '') !== '') $newPop = ['type' => $np['type'] ?? (($np['status'] ?? '') === 'arrived' ? 'Just arrived' : 'Coming soon'), 'name' => $np['name'], 'line' => $np['line'] ?? '', 'product' => $np['product'] ?? ''];
} catch (Throwable $e) {} }
echo '{"products":' . ($out ? '[' . implode(',', $out) . ']' : 'null') . ',"saleEnds":' . ($saleEnds ?? 'null') . ',"saleAlways":' . ($saleAlways ? 'true' : 'false') . ',"salePopup":' . ($salePopup ? 'true' : 'false') . ',"saleLine":' . ($saleLine ? 'true' : 'false') . ',"salePct":' . ($salePct ?: 'null')
  . ',"saleText":' . fomaxo_json($saleTxt) . ',"salePopIds":' . fomaxo_json($popIds) . ',"saleLineIds":' . ($lineIds === null ? 'null' : fomaxo_json($lineIds)) . ',"newPop":' . ($newPop ? fomaxo_json($newPop) : 'null') . '}';
