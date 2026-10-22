<?php
// Practice payment page, only active when config.php has 'ziina_mode' => 'demo'.
// Lets you try the QR payment flow before your Ziina API key is ready. No money moves.
require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/ziina.php';

if (cfg('ziina_mode') !== 'demo') {
    http_response_code(404);
    exit('Not available.');
}
$id = $_GET['id'] ?? '';
if (!preg_match('/^demo_[0-9a-f]{16}$/', $id) || demo_store($id) === null) {
    http_response_code(404);
    exit('Payment not found.');
}
$sale = q1('SELECT total, receipt_no, receipt_token FROM sales WHERE ziina_id = ?', [$id]);
if (!$sale) {
    http_response_code(404);
    exit('Payment not found.');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    demo_store($id, ($_POST['do'] ?? '') === 'pay' ? 'completed' : 'canceled');
    header('Location: receipt.php?t=' . $sale['receipt_token']);
    exit;
}
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Practice payment</title><link rel="stylesheet" href="assets/page.css"></head>
<body><main class="card">
  <div class="banner wait">PRACTICE MODE - no real money</div>
  <div class="brand">FOMAXO</div>
  <h1>Pay AED <?= money($sale['total']) ?></h1>
  <p class="muted"><?= h($sale['receipt_no']) ?> - on the live system this page is Ziina's checkout, with card, Apple Pay and Google Pay.</p>
  <form method="post">
    <button class="btn" name="do" value="pay">Pay now (practice)</button>
    <button class="btn ghost" name="do" value="cancel">Cancel</button>
  </form>
</main></body></html>
