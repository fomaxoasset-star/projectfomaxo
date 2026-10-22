<?php
// Customer receipt page. Opened from WhatsApp/email links and after a Ziina payment.
require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/ziina.php';
require __DIR__ . '/lib/sales.php';
require __DIR__ . '/lib/schema.php';

header('X-Robots-Tag: noindex');
$token = $_GET['t'] ?? '';
$sale = null;
if (preg_match('/^[0-9a-f]{32}$/', $token) && cfg() !== false) {
    try {
        migrate();
        $raw = q1('SELECT * FROM sales WHERE receipt_token = ?', [$token]);
        if ($raw && $raw['status'] === 'pending' && $raw['method'] === 'ziina') {
            try {
                refresh_ziina_sale($raw, (int) $raw['user_id']);
            } catch (Throwable $e) {
                // Ziina unreachable right now - show the receipt as pending.
            }
        }
        $sale = $raw ? load_sale('id', $raw['id']) : null;
    } catch (Throwable $e) {
        $sale = null;
    }
}

$methods = ['cash' => 'Cash', 'tap' => 'Tap to Pay', 'card' => 'Card', 'ziina' => 'Ziina'];
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<title><?= $sale ? h(cfg('store_name') . ' receipt ' . $sale['receipt_no']) : 'Receipt' ?></title>
<link rel="stylesheet" href="assets/page.css"><link rel="icon" href="assets/favicon-32.png"><link rel="apple-touch-icon" href="assets/apple-touch-icon.png">
</head>
<body class="receipt-page">
<?php if (!$sale): ?>
<main class="card"><div class="brand">FOMAXO</div><h1>Receipt not found</h1><p>Please check the link, or ask the store to send it again.</p></main>
<?php else: ?>
<main class="receipt">
  <?php if ($sale['status'] === 'pending'): ?>
    <div class="banner wait">Waiting for payment to be confirmed. Refresh this page in a moment.</div>
  <?php elseif (in_array($sale['status'], ['cancelled', 'failed'], true)): ?>
    <div class="banner bad">This payment was <?= h($sale['status']) ?>. No money was taken.</div>
  <?php elseif ($sale['status'] === 'void'): ?>
    <div class="banner bad">This sale was cancelled by the store.</div>
  <?php elseif (isset($_GET['cancelled'])): ?>
    <div class="banner ok">Payment received.</div>
  <?php else: ?>
    <div class="banner ok">Paid - thank you!</div>
  <?php endif; ?>

  <header>
    <div class="brand"><?= h(cfg('store_name')) ?></div>
    <?php if (cfg('store_tagline')): ?><div class="tag"><?= h(cfg('store_tagline')) ?></div><?php endif; ?>
    <?php if (!empty($sale['store_name']) && $sale['store_name'] !== 'Main store'): ?><div class="small"><b><?= h($sale['store_name']) ?></b></div><?php endif; ?>
    <div class="small"><?= implode('<br>', array_map('h', array_filter([$sale['store_address'] ?: cfg('store_address'), $sale['store_phone'] ?: cfg('store_phone')]))) ?></div>
    <?php if (cfg('store_trn')): ?><div class="small">TRN <?= h(cfg('store_trn')) ?></div><?php endif; ?>
    <div class="title"><?= cfg('store_trn') ? 'TAX INVOICE' : 'RECEIPT' ?></div>
  </header>

  <dl class="meta">
    <div><dt>Receipt</dt><dd><?= h($sale['receipt_no']) ?></dd></div>
    <div><dt>Date</dt><dd><?= h(date('d M Y, H:i', strtotime($sale['created_at']))) ?></dd></div>
    <div><dt>Served by</dt><dd><?= h($sale['staff_name']) ?></dd></div>
    <?php if (!empty($sale['customer_name']) || !empty($sale['customer_phone'])): ?>
      <div><dt>Customer</dt><dd><?= h(trim(($sale['customer_name'] ?? '') . ' ' . ($sale['customer_phone'] ?? ''))) ?></dd></div>
    <?php endif; ?>
  </dl>

  <table class="lines">
    <?php foreach ($sale['items'] as $it): ?>
    <tr>
      <td><div class="pname"><?= h($it['name']) ?></div><?php if ($it['variant']): ?><div class="small"><?= h($it['variant']) ?></div><?php endif; ?>
        <div class="small"><?= (int) $it['qty'] ?> x <?= money($it['price']) ?></div></td>
      <td class="num"><?= money($it['line_total']) ?></td>
    </tr>
    <?php endforeach; ?>
  </table>

  <table class="totals">
    <tr><td>Subtotal</td><td class="num"><?= money($sale['subtotal']) ?></td></tr>
    <?php if ($sale['discount'] > 0): ?><tr><td>Discount</td><td class="num">-<?= money($sale['discount']) ?></td></tr><?php endif; ?>
    <tr><td>VAT <?= h(cfg('vat_rate', 5)) ?>%<?= cfg('prices_include_vat', true) ? ' (included)' : '' ?></td><td class="num"><?= money($sale['vat']) ?></td></tr>
    <tr class="grand"><td>Total <?= h(cfg('currency', 'AED')) ?></td><td class="num"><?= money($sale['total']) ?></td></tr>
    <tr><td>Paid by</td><td class="num"><?= h($methods[$sale['method']] ?? $sale['method']) ?></td></tr>
    <?php if ($sale['method'] === 'cash' && $sale['cash_given'] !== null): ?>
      <tr><td>Cash given</td><td class="num"><?= money($sale['cash_given']) ?></td></tr>
      <tr><td>Change</td><td class="num"><?= money($sale['change_due']) ?></td></tr>
    <?php endif; ?>
  </table>

  <?php if (!empty($sale['customer_tier'])): ?>
    <p class="tier">FOMAXO <?= h($sale['customer_tier']) ?> member</p>
  <?php endif; ?>
  <p class="foot"><?= h(cfg('receipt_footer')) ?></p>
  <p class="noprint"><button class="btn" onclick="window.print()">Print / save as PDF</button></p>
</main>
<?php endif; ?>
</body>
</html>
