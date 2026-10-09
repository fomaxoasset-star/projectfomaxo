<?php
/* FOMAXO — automatic WhatsApp messages: review requests (admin → Members → Review requests) and refill reminders (see whatsapp-lib.php).
   php whatsapp.php                     → Hostinger cron job (e.g. every 30 minutes): sends the messages that are due
   GET whatsapp.php?status              → set-up check: is whatsapp-config.php found and filled in (never shows the token), messages waiting
   GET whatsapp.php?skip=ORDER&k=KEY    → stop the message for one order (link in FOMAXO's order email, e.g. for a cancelled order)
   GET/POST whatsapp.php (Meta webhook) → customers' replies: STOP switches their WhatsApp offers off (signed with the app secret) */
require __DIR__ . '/whatsapp-lib.php';
date_default_timezone_set('Asia/Dubai');

if (PHP_SAPI === 'cli') { echo json_encode(fomaxo_wa_send_due() + fomaxo_wa_send_refills() + fomaxo_wa_send_rvreqs()) . "\n"; exit; }

header('Cache-Control: no-store');
if (isset($_GET['skip'])) {
  header('Content-Type: text/html; charset=UTF-8');
  $no = preg_replace('/[^A-Za-z0-9\-]/', '', (string)$_GET['skip']);
  $f = rv_dir('whatsapp') . "/$no.json";
  $m = $no !== '' && is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
  if (!is_array($m) || !hash_equals(rv_sign("wa-skip:$no"), (string)($_GET['k'] ?? ''))) { http_response_code(404); echo '<p style="font-family:sans-serif">Link not valid.</p>'; exit; }
  if (!empty($m['sent'])) $msg = "The WhatsApp message for order $no was already sent.";
  else { $m['skipped'] = 'stopped by FOMAXO ' . date('c'); file_put_contents($f, json_encode($m, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX); $msg = "Done. No WhatsApp review message will be sent for order $no."; }
  echo '<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><title>FOMAXO</title><p style="font-family:sans-serif;padding:24px">' . htmlspecialchars($msg) . '</p>';
  exit;
}
if (isset($_GET['hub_mode'])) {   // Meta checks the webhook once when FOMAXO saves it
  if ($_GET['hub_mode'] === 'subscribe' && hash_equals(fx_wa_hook_token(), (string)($_GET['hub_verify_token'] ?? ''))) { header('Content-Type: text/plain'); echo preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($_GET['hub_challenge'] ?? '')); exit; }
  http_response_code(403); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {   // a customer's WhatsApp reply
  $raw = (string)file_get_contents('php://input'); $sec = trim((string)(fx_wa_config()['app_secret'] ?? ''));
  if ($sec === '' || !hash_equals('sha256=' . hash_hmac('sha256', $raw, $sec), (string)($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? ''))) { http_response_code(403); exit; }
  require_once __DIR__ . '/orders-lib.php';
  $pdo = fomaxo_db(); $j = json_decode($raw, true);
  foreach ((array)($j['entry'] ?? []) as $e) foreach ((array)($e['changes'] ?? []) as $ch) foreach ((array)($ch['value']['messages'] ?? []) as $m) {
    $t = (string)($m['text']['body'] ?? $m['button']['text'] ?? $m['interactive']['button_reply']['title'] ?? '');
    if ($pdo && fx_wa_is_stop($t)) fx_wa_stop($pdo, (string)($m['from'] ?? ''), 'reply');
  }
  echo 'ok'; exit;
}
if (isset($_GET['status'])) {
  header('Content-Type: application/json');
  $c = fx_wa_config(); $wait = 0; $sent = 0;
  foreach (glob(rv_dir('whatsapp') . '/*.json') ?: [] as $f) { $m = json_decode((string)@file_get_contents($f), true); if (!empty($m['sent'])) $sent++; elseif (empty($m['skipped'])) $wait++; }
  echo json_encode(['config_file' => $c ? 'found' : 'NOT FOUND — put whatsapp-config.php in domains/fomaxo.com (next to public_html)',
                    'ready' => fx_wa_ready() ? 'yes' : 'no (access_token / phone_number_id missing)',
                    'template' => (string)($c['template'] ?? 'review_request'), 'messages_waiting' => $wait, 'messages_sent' => $sent], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  exit;
}
http_response_code(404);
