<?php
/* FOMAXO — what the website needs to show "Only X left" and "Sold out".
   "stock": only sizes that are running low (fomaxo_low_stock() or fewer), for "Only X left".
   "max": every counted size under "perItem", so the bag and product page never go above what we have.
   "perItem": the most of one item per order (admin → Stocks, empty = 30).
   Stock is typed in on fomaxo.com/admin → Stock. */
header('Content-Type: application/json');
header('Cache-Control: no-cache');   // asked again on every page load, so a stock count saved on admin shows straight away; "not changed" when nothing changed
header('X-LiteSpeed-Cache-Control: no-cache');
require __DIR__ . '/orders-lib.php';
$out = []; $max = [];
$pdo = fomaxo_db();
if ($pdo) {
  try {
    foreach (fomaxo_stock_map($pdo) as $k => $n) {
      [$id, $opt] = explode('|', $k, 2);
      if ($n < fomaxo_max_per_item()) $max[$id][$opt] = max(0, $n);
      if ($n > fomaxo_low_stock()) continue;
      $out[$id][$opt] = max(0, $n);
    }
  } catch (Throwable $e) {}
}
$json = json_encode(['low' => fomaxo_low_stock(), 'stock' => (object)$out, 'max' => (object)$max, 'perItem' => fomaxo_max_per_item()]);
$tag = substr(md5($json), 0, 16); header('ETag: "' . $tag . '"');
if (strpos((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''), $tag) !== false) { http_response_code(304); exit; }
echo $json;
