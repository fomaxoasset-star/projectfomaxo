<?php
/* FOMAXO — where uploaded videos and photos are kept.
   Every GitHub update of the website removes files that are not on GitHub, so uploads made in the admin
   (shop videos, their cover photos, product photos) are kept in fomaxo-media/ ONE LEVEL ABOVE public_html instead.
   Their addresses stay the same (assets/vid/…, assets/img/up/…): .htaccess hands those to media.php. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }

function fx_media($sub = '') {   // fomaxo-media/vid, fomaxo-media/up, fomaxo-media/parts …
  $d = dirname(__DIR__) . '/fomaxo-media' . ($sub !== '' ? "/$sub" : '');
  if (!is_dir($d)) @mkdir($d, 0755, true);
  return $d;
}
/* the file on the server for 'vid/x.mp4' (a video) or 'up/x' (a photo key); older files on GitHub stay in assets/ */
function fx_media_path($file) {
  $file = (string)$file;
  if (preg_match('~^vid/([a-z0-9-]+\.(?:mp4|mov|webm))$~', $file, $m)) { $f = fx_media('vid') . '/' . $m[1]; return is_file($f) ? $f : __DIR__ . '/assets/' . $file; }
  if (preg_match('~^up/([a-z0-9-]+)$~', $file, $m)) { $f = fx_media('up') . '/' . $m[1] . '.webp'; return is_file($f) ? $f : __DIR__ . '/assets/img/' . $file . '.webp'; }
  return null;
}
function fx_media_delete($file) { $f = fx_media_path($file); if (($l = fx_media_public($file)) && (is_link($l) || ($f !== $l && is_file($l)))) @unlink($l); if ($f && is_file($f)) @unlink($f); }
/* where the website shows it: assets/vid/x.mp4 or assets/img/up/x.webp in public_html */
function fx_media_public($file) {
  if (preg_match('~^vid/[a-z0-9-]+\.(?:mp4|mov|webm)$~', (string)$file)) return __DIR__ . '/assets/' . $file;
  if (preg_match('~^up/[a-z0-9-]+$~', (string)$file)) return __DIR__ . '/assets/img/' . $file . '.webp';
  return null;
}
/* puts a link to the kept file at its website address, so the web server sends it itself (starts at once, in parts) instead of media.php.
   A GitHub update removes the link; the next visit through media.php (or the admin Videos page) puts it back. */
function fx_media_link($file) {
  $pub = fx_media_public($file); $real = fx_media_path($file);
  if (!$pub || !$real || !is_file($real) || strpos($real, dirname(__DIR__) . '/fomaxo-media/') !== 0 || is_file($pub)) return;
  if (is_link($pub)) @unlink($pub);
  if (!is_dir(dirname($pub))) @mkdir(dirname($pub), 0755, true);
  if (function_exists('symlink') && @symlink($real, $pub) && is_file($pub)) return;   // many hosts switch symlink() and link() off: calling them then stops the page
  if (is_link($pub)) @unlink($pub);
  if (function_exists('link') && @link($real, $pub)) return;   // servers that refuse links: a second name for the same file, or else a copy (a GitHub update removes it, media.php puts it back)
  $tmp = $pub . '.part' . getmypid(); if (@copy($real, $tmp) && filesize($tmp) === filesize($real) && @rename($tmp, $pub)) { @chmod($pub, 0644); return; }
  @unlink($tmp);
}
