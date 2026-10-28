<?php
/* FOMAXO — a short report from the visitor's browser on how the shop videos played (each video's state, or its error),
   so a video that will not play can be looked into from the server. Kept in vid-report.txt (not readable from the web,
   see .htaccess), newest last, never bigger than 200 KB. No names, no IP addresses. */
header('Cache-Control: no-store');
http_response_code(204);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') exit;
$raw = (string)file_get_contents('php://input', false, null, 0, 4096);
$d = json_decode($raw, true);
/* Improve → Video speed (AJAY 28/10/2026): one line per preview row or video opened, how fast it started, froze or did not play.
   vid-speed.txt (private like vid-report.txt), newest half kept past 300 KB. Same lines as fomaxo.in's api/data/vtime.txt */
if (is_array($d) && ($d['t'] ?? '') === 'vtime' && is_array($d['vt'] ?? null) && in_array($d['how'] ?? '', ['preview', 'video'], true)) {
  $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? ''); if (preg_match('/bot|crawl|spider|headless|lighthouse|preview|python|curl/i', $ua)) exit;
  $st = []; foreach (array_slice($d['vt'], 0, 6) as $x) if (is_array($x) && preg_match('/^[a-z]{2,12}$/', (string)($x[0] ?? ''))) $st[] = $x[0] . ' ' . max(0, min(99999, (int)($x[1] ?? 0)));
  $br = preg_match('/Edg/', $ua) ? 'Edge' : (preg_match('/SamsungBrowser/', $ua) ? 'Samsung' : (preg_match('/CriOS|Chrome/', $ua) ? 'Chrome' : (preg_match('/FxiOS|Firefox/', $ua) ? 'Firefox' : (preg_match('/Safari/', $ua) ? 'Safari' : 'Other'))));
  $os = preg_match('/iPhone|iPad|iPod/', $ua) ? 'iPhone' : (preg_match('/Android/', $ua) ? 'Android' : (preg_match('/Mac OS X/', $ua) ? 'Mac' : (preg_match('/Windows/', $ua) ? 'Windows' : 'Other')));
  $cl = fn($v, $n) => substr(preg_replace('/[^\w .\/-]/', '', (string)$v), 0, $n);
  $f = __DIR__ . '/vid-speed.txt'; if (@filesize($f) > 300000) @file_put_contents($f, substr((string)@file_get_contents($f), -150000), LOCK_EX);
  date_default_timezone_set('Asia/Dubai');
  @file_put_contents($f, date('d/m H:i:s') . ' | ' . $os . ' ' . $br . ' | ' . $d['how'] . ' | ' . $cl($d['net'] ?? '', 16) . ' | ' . $cl($d['vf'] ?? '', 40) . ' | ' . implode(', ', $st) . "\n", FILE_APPEND | LOCK_EX);
  exit;
}
if (!is_array($d) || !isset($d['v']) || !is_array($d['v'])) exit;
$ua = substr(preg_replace('/[\x00-\x1f]+/', ' ', (string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 200);
$rows = array_map(fn($x) => substr(preg_replace('/[^a-z0-9 .:\/_=,-]+/i', '', (string)$x), 0, 160), array_slice($d['v'], 0, 30));
$f = __DIR__ . '/vid-report.txt';
if (@filesize($f) > 200 * 1024) @rename($f, $f . '.old.txt');
@file_put_contents($f, gmdate('Y-m-d H:i:s') . ' | ' . $ua . "\n  " . implode("\n  ", $rows) . "\n", FILE_APPEND | LOCK_EX);
