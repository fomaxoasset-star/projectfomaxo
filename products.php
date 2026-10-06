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
$saleEnds = null; $saleAlways = false; $salePopup = true; $saleLine = true; $salePct = 0; $newPop = null;   // newPop: the "Coming soon" / "Just arrived" popup   // saleAlways: on with no timer until turned off; popup / line: what shows (admin → Offer)
if ($pdo = fomaxo_db()) { try { $v = (int)fomaxo_setting($pdo, 'sale_ends'); if ($v > time()) $saleEnds = $v; $saleAlways = (string)fomaxo_setting($pdo, 'sale_always') === '1'; $salePopup = (string)fomaxo_setting($pdo, 'sale_popup') !== '0'; $saleLine = (string)fomaxo_setting($pdo, 'sale_line') !== '0'; $salePct = (int)fomaxo_setting($pdo, 'sale_pct'); $np = json_decode((string)fomaxo_setting($pdo, 'np'), true); if (is_array($np) && !empty($np['on']) && ($np['name'] ?? '') !== '') $newPop = ['status' => $np['status'], 'name' => $np['name'], 'line' => $np['line'] ?? '', 'product' => $np['product'] ?? '']; } catch (Throwable $e) {} }
echo '{"products":' . ($out ? '[' . implode(',', $out) . ']' : 'null') . ',"saleEnds":' . ($saleEnds ?? 'null') . ',"saleAlways":' . ($saleAlways ? 'true' : 'false') . ',"salePopup":' . ($salePopup ? 'true' : 'false') . ',"saleLine":' . ($saleLine ? 'true' : 'false') . ',"salePct":' . ($salePct ?: 'null') . ',"newPop":' . ($newPop ? fomaxo_json($newPop) : 'null') . '}';
