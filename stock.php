<?php
/* FOMAXO — what the website needs to show "Only X left" and "Sold out".
   "stock": only sizes that are running low (fomaxo_low_stock() or fewer), for "Only X left".
   "max": every counted size under "perItem", so the bag and product page never go above what we have.
   "perItem": the most of one item per order (admin → Stocks, empty = 30).
   Stock is typed in on fomaxo.com/admin → Stock. */
header('Content-Type: application/json');
header('Cache-Control: public, max-age=30');
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
echo json_encode(['low' => fomaxo_low_stock(), 'stock' => (object)$out, 'max' => (object)$max, 'perItem' => fomaxo_max_per_item()]);
