<?php
/* FOMAXO — email sign-ups from the website (footer "Join", "Notify me" on Personal Care and similar forms).
   Saved in the database (fx_subscribers) so they can be downloaded on fomaxo.com/admin → Members → Download email list.
   If the database is not available, the sign-up is emailed to the store inbox instead, so nobody is lost. */
header('Content-Type: application/json');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo '{"error":"POST only"}'; exit; }
$d = json_decode((string)file_get_contents('php://input', false, null, 0, 2048), true);
if (!is_array($d)) { http_response_code(400); echo '{"error":"Bad request"}'; exit; }
if (!empty($d['website'])) { echo '{"ok":true}'; exit; }   // hidden box only robots fill in
$clean = fn($v, $max) => trim(mb_substr(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', is_string($v) ? $v : ''), 0, $max));
$email = mb_strtolower($clean($d['email'] ?? '', 120));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { http_response_code(400); echo '{"error":"Please enter a valid email address."}'; exit; }
$interest = $clean($d['interest'] ?? '', 60) ?: 'FOMAXO launches';
$page = $clean($d['page'] ?? '', 80);

require __DIR__ . '/orders-lib.php';
$saved = false;
if ($pdo = fomaxo_db()) {
  try {
    fomaxo_subscribers_table($pdo);
    $pdo->prepare('INSERT INTO fx_subscribers (email, created_at, updated_at, interest, page) VALUES (?, NOW(), NOW(), ?, ?)
                   ON DUPLICATE KEY UPDATE updated_at = NOW(), interest = VALUES(interest), page = VALUES(page)')->execute([$email, $interest, $page]);
    $saved = true;
  } catch (Throwable $e) { error_log('FOMAXO subscribe: ' . $e->getMessage()); }
}
if (!$saved) {
  $sent = fomaxo_mail(fomaxo_orders_email(), 'New sign-up: ' . $interest, "Email: $email\nInterest: $interest\nPage: $page\n", "From: FOMAXO <mail@fomaxo.com>\r\nContent-Type: text/plain; charset=UTF-8");
  if (!$sent) { http_response_code(500); echo '{"error":"We could not save your email right now. Please try again."}'; exit; }
}
echo '{"ok":true}';
