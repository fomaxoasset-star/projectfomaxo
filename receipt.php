<?php
/* FOMAXO — a customer's receipt: fomaxo.com/receipt.php?n=FMX-1001&t=… (the link admin sends on WhatsApp or by email). See receipt-lib.php. */
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
require __DIR__ . '/receipt-lib.php';
date_default_timezone_set('Asia/Dubai');
$no = (string)($_GET['n'] ?? '');
$pdo = fomaxo_db();
$o = null;
if ($pdo && preg_match('/^[A-Z0-9-]{3,40}$/', $no) && fx_receipt_ok($no, $_GET['t'] ?? '')) {
  fx_receipt_cols($pdo);
  $s = $pdo->prepare("SELECT * FROM fx_orders WHERE order_no = ? AND status <> 'Awaiting payment'"); $s->execute([$no]); $o = $s->fetch() ?: null;
}
if (!$o) {
  http_response_code(404);
  exit('<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>FOMAXO</title><body style="font:16px system-ui,sans-serif;text-align:center;padding:60px 20px">This receipt link is not valid. Please message FOMAXO on WhatsApp for a new one.</body>');
}
echo fx_receipt_page($o, ($_GET['l'] ?? '') === 'ar');
