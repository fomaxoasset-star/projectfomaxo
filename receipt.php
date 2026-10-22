<?php
// Customer receipt (same design as fomaxo.com receipts). Opened from the receipt QR code,
// WhatsApp/email links, after a Ziina payment, and inside the staff app.
require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/ziina.php';
require __DIR__ . '/lib/sales.php';
require __DIR__ . '/lib/schema.php';
require __DIR__ . '/lib/receiptview.php';

header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

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
                // Ziina unreachable right now - show the receipt as waiting.
            }
        }
        $sale = $raw ? load_sale('id', $raw['id']) : null;
    } catch (Throwable $e) {
        $sale = null;
    }
}

if (!$sale) {
    http_response_code(404);
    exit('<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>FOMAXO</title>'
        . '<body style="font:16px system-ui,sans-serif;text-align:center;padding:60px 20px">This receipt link is not valid. Please ask the FOMAXO store for a new one.</body>');
}

$theme = in_array($_GET['theme'] ?? '', ['light', 'dark'], true) ? $_GET['theme'] : '';
echo receipt_page($sale, ($_GET['l'] ?? '') === 'ar', $theme, !empty($_GET['embed']));
