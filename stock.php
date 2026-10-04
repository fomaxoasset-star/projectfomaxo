<?php
/* FOMAXO — what the website needs to show "Only X left" and "Sold out".
   Only sizes that are running low (FX_LOW_STOCK or fewer) are listed; everything else is simply in stock.
   Stock is typed in on fomaxo.com/admin → Stock. */
header('Content-Type: application/json');
header('Cache-Control: public, max-age=30');
require __DIR__ . '/orders-lib.php';
$out = [];
$pdo = fomaxo_db();
if ($pdo) {
  try {
    foreach (fomaxo_stock_map($pdo) as $k => $n) {
      if ($n > FX_LOW_STOCK) continue;
      [$id, $opt] = explode('|', $k, 2);
      $out[$id][$opt] = max(0, $n);
    }
  } catch (Throwable $e) {}
}
echo json_encode(['low' => FX_LOW_STOCK, 'stock' => (object)$out]);
