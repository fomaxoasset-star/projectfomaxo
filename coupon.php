<?php
/* FOMAXO — "Apply" on the checkout coupon box: says whether a code can be used and what it gives.
   POST {code} → {code, kind: "pct"|"aed", amount, min, label} or {error}.
   This only helps the checkout page show the saving; checkout.php (cash) and ziina.php (card) check the code again before charging. */
header('Content-Type: application/json');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit; }
require __DIR__ . '/orders-lib.php';

$in = json_decode(file_get_contents('php://input'), true) ?: [];
$pdo = fomaxo_db();
/* at most 15 tries per 10 minutes from one connection, so codes can't be guessed */
if ($pdo) {
  $ip = 'c:' . substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 43);
  try {
    $s = $pdo->prepare('SELECT COUNT(*) FROM fx_login WHERE ip = ? AND at > NOW() - INTERVAL 10 MINUTE'); $s->execute([$ip]);
    if ((int)$s->fetchColumn() >= 15) { http_response_code(429); echo json_encode(['error' => 'Too many tries. Please wait a few minutes and try again.']); exit; }
    $pdo->prepare('INSERT INTO fx_login (ip, at) VALUES (?, NOW())')->execute([$ip]);
  } catch (Throwable $e) {}
}
$ph = is_string($in['phone'] ?? null) && trim($in['phone']) !== '' ? $in['phone'] : null;   // a one-time customer coupon is checked against the mobile typed at checkout
$c = fomaxo_coupon_find($in['code'] ?? '', $ph);
if (isset($c['error'])) { http_response_code(400); echo json_encode(['error' => $c['error']]); exit; }
echo json_encode($c, JSON_UNESCAPED_UNICODE);
