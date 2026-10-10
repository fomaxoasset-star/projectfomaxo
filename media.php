<?php
/* FOMAXO — sends an uploaded video or photo kept in fomaxo-media/ (see media-lib.php).
   .htaccess sends assets/vid/… and assets/img/up/… here when the file is not in public_html.
   Videos answer in parts (Range), so they start at once and phones can skip through them. */
require __DIR__ . '/media-lib.php';
$f = (string)($_GET['f'] ?? '');
if (preg_match('~^vid/[a-z0-9-]+\.(mp4|mov|webm)$~', $f, $m)) { $path = fx_media_path($f); $type = ['mp4' => 'video/mp4', 'mov' => 'video/quicktime', 'webm' => 'video/webm'][$m[1]]; }
elseif (preg_match('~^img/(up/[a-z0-9-]+)\.webp$~', $f, $m)) { $path = fx_media_path($m[1]); $type = 'image/webp'; }
if (empty($path) || !is_file($path)) { http_response_code(404); header('Cache-Control: no-store'); exit; }
$size = filesize($path); $mt = filemtime($path); $etag = '"' . dechex($size) . '-' . dechex($mt) . '"';
header('Content-Type: ' . $type);
header('Cache-Control: public, max-age=2592000, immutable');   // each upload has its own new name
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mt) . ' GMT');
header('ETag: ' . $etag);
header('Accept-Ranges: bytes');
header('X-Content-Type-Options: nosniff');
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) { http_response_code(304); exit; }
$from = 0; $to = $size - 1;
if (preg_match('~^bytes=(\d*)-(\d*)$~', (string)($_SERVER['HTTP_RANGE'] ?? ''), $r) && ($r[1] !== '' || $r[2] !== '')) {
  if ($r[1] === '') { $from = max(0, $size - (int)$r[2]); } else { $from = (int)$r[1]; if ($r[2] !== '') $to = min($to, (int)$r[2]); }
  if ($from > $to || $from >= $size) { http_response_code(416); header("Content-Range: bytes */$size"); exit; }
  http_response_code(206); header("Content-Range: bytes $from-$to/$size");
}
header('Content-Length: ' . ($to - $from + 1));
if ($_SERVER['REQUEST_METHOD'] === 'HEAD') exit;
@set_time_limit(0); while (ob_get_level()) ob_end_clean();
$h = fopen($path, 'rb'); fseek($h, $from); $left = $to - $from + 1;
while ($left > 0 && !feof($h) && !connection_aborted()) { $chunk = fread($h, min(262144, $left)); echo $chunk; flush(); $left -= strlen($chunk); }
fclose($h);
