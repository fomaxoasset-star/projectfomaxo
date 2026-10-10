<?php
/* FOMAXO — a short report from the visitor's browser on how the shop videos played (each video's state, or its error),
   so a video that will not play can be looked into from the server. Kept in vid-report.txt (not readable from the web,
   see .htaccess), newest last, never bigger than 200 KB. No names, no IP addresses. */
header('Cache-Control: no-store');
http_response_code(204);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') exit;
$raw = (string)file_get_contents('php://input', false, null, 0, 4096);
$d = json_decode($raw, true);
if (!is_array($d) || !isset($d['v']) || !is_array($d['v'])) exit;
$ua = substr(preg_replace('/[\x00-\x1f]+/', ' ', (string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 200);
$rows = array_map(fn($x) => substr(preg_replace('/[^a-z0-9 .:\/_=,-]+/i', '', (string)$x), 0, 160), array_slice($d['v'], 0, 30));
$f = __DIR__ . '/vid-report.txt';
if (@filesize($f) > 200 * 1024) @rename($f, $f . '.old.txt');
@file_put_contents($f, gmdate('Y-m-d H:i:s') . ' | ' . $ua . "\n  " . implode("\n  ", $rows) . "\n", FILE_APPEND | LOCK_EX);
