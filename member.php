<?php
/* FOMAXO — "Check my level" on the FOMAXO Elite page (see member-lib.php).
   POST {phone, name} → {ok:true, name, spend, level, next, needed, web_orders, store_visits, year, tiers} or {ok:false, why:'phone'|'name'|'many'|'none'} */
header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok' => false]); exit; }
require __DIR__ . '/orders-lib.php';
require __DIR__ . '/member-lib.php';
date_default_timezone_set('Asia/Dubai');

$in = json_decode((string)file_get_contents('php://input'), true) ?: [];
$phone = is_string($in['phone'] ?? null) ? mb_substr($in['phone'], 0, 30) : '';
$web = fomaxo_db(); $pos = fx_member_pos_db();
$r = fx_member_lookup($phone, is_string($in['name'] ?? null) ? mb_substr($in['name'], 0, 60) : '', substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45), $web, $pos);
if (!$r['ok'] && ($r['why'] ?? '') === 'many') http_response_code(429);
echo json_encode($r, JSON_UNESCAPED_UNICODE);
