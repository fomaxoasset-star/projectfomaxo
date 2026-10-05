<?php
/* FOMAXO — Cash on delivery orders.
   Checks prices on the server, saves the order privately (outside public_html) and emails it to the store.
   Settings: change $STORE_EMAIL below if orders should go to another inbox. */
header('Content-Type: application/json');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit; }
require __DIR__ . '/store-lib.php';

$STORE_EMAIL = 'fomaxoasset@gmail.com';
$COD_FEE = 10;    // AED added to every cash on delivery order — keep in sync with index.html (codFee)
$COD_MIN = 200;   // AED minimum order (after discount, before the fee) — keep in sync with index.html (codMin)

$in = json_decode(file_get_contents('php://input'), true) ?: [];
if (!empty($in['website'])) { echo json_encode(['order' => 'FMX-0']); exit; }   // spam trap (hidden field)

$order = fomaxo_price_order($in);
if (isset($order['error'])) { http_response_code(400); echo json_encode(['error' => $order['error']]); exit; }
$cust = fomaxo_customer($in);
if (isset($cust['error'])) { http_response_code(400); echo json_encode(['error' => $cust['error']]); exit; }

if ($order['totalFils'] < $COD_MIN * 100) { http_response_code(400); echo json_encode(['error' => "Cash on delivery is available for orders of AED $COD_MIN or more."]); exit; }
$order['feeFils'] = $COD_FEE * 100;
$order['totalFils'] += $order['feeFils'];

date_default_timezone_set('Asia/Dubai');
$no = 'FMX-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
$total = fomaxo_aed($order['totalFils']);

$rows = [];
foreach ($order['items'] as $it) {
  $rows[] = "• {$it['qty']} x {$it['name']}" . ($it['desc'] ? " ({$it['desc']})" : '') . ' — ' . fomaxo_aed($it['unit'] * $it['qty']);
}
if ($order['gift']) $rows[] = "• FREE 10ml {$order['gift']} mini";
$body = "NEW CASH ON DELIVERY ORDER  $no\n" . date('d M Y, H:i') . " (Dubai)\n\n"
      . implode("\n", $rows) . "\n\n"
      . 'Subtotal: ' . fomaxo_aed($order['fullFils']) . "\n"
      . ($order['discountFils'] ? "Multi-buy {$order['pct']}% off: -" . fomaxo_aed($order['discountFils']) . "\n" : '')
      . 'Cash on delivery fee: ' . fomaxo_aed($order['feeFils']) . "\n"
      . "Delivery: Free\nTOTAL TO COLLECT IN CASH: $total\n\n"
      . "Name: {$cust['name']}\nPhone: {$cust['phone']}\nEmail: {$cust['email']}\n"
      . "Address: {$cust['address']}, {$cust['emirate']}, UAE\n"
      . ($cust['note'] ? "Note: {$cust['note']}\n" : '');

$logged = fomaxo_log_order([date('Y-m-d H:i'), $no, 'Cash on delivery', $total, $cust['name'], $cust['phone'], $cust['email'],
                            $cust['emirate'], $cust['address'], $cust['note'], implode(' | ', $order['summary'])]);

$host = preg_replace('/^www\./', '', preg_replace('/[^A-Za-z0-9.\-]/', '', $_SERVER['HTTP_HOST'] ?? 'fomaxo.com'));
$from = "FOMAXO <mail@fomaxo.com>";
$h = "From: $from\r\nReply-To: {$cust['email']}\r\nContent-Type: text/plain; charset=UTF-8";
$mailed = fomaxo_mail($STORE_EMAIL, "=?UTF-8?B?" . base64_encode("New COD order $no — $total") . "?=", $body, $h);

/* confirmation for the customer */
$cb = "Thank you for your order, {$cust['name']}.\n\nOrder number: $no\n\n" . implode("\n", $rows) . "\n\n"
    . "Total to pay in cash on delivery: $total (includes AED $COD_FEE cash on delivery fee)\nDelivery: Free, to {$cust['address']}, {$cust['emirate']}\n\n"
    . "We will call or WhatsApp you on {$cust['phone']} to confirm your delivery time.\n\nFOMAXO\nhttps://$host";
fomaxo_mail($cust['email'], "=?UTF-8?B?" . base64_encode("Your FOMAXO order $no") . "?=", $cb, "From: $from\r\nReply-To: $STORE_EMAIL\r\nContent-Type: text/plain; charset=UTF-8");

if (!$logged && !$mailed) {
  http_response_code(502);
  echo json_encode(['error' => 'We could not place your order right now. Please try again or contact us on WhatsApp.']);
  exit;
}
echo json_encode(['order' => $no, 'total' => $order['totalFils'] / 100]);
