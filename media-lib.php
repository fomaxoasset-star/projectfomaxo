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
/* a video of 1.5 MB or less (every upload is made 1 MB now) is as light as a preview: the home row plays it as it is */
function fx_small_video($file) { $f = fx_media_path($file); return $f && preg_match('~\.mp4$~', $f) && is_file($f) && filesize($f) <= 1.5 * 1048576; }
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
/* a video uploaded on fomaxo.in with "Also add to fomaxo.com" ticked: fomaxo.in puts the file and a small note (product, words, shape)
   in fomaxo-media/video-inbox. It is added to the top of the list here, the first time the shop or the admin opens after.
   A product this site does not sell is left out (the video then gets a Shop now button). One at a time (a lock), nothing to do = one folder read */
function fx_video_inbox($pdo) {
  $in = dirname(__DIR__) . '/fomaxo-media/video-inbox'; $notes = is_dir($in) ? (glob("$in/*.json") ?: []) : []; if (!$notes || !$pdo) return;
  $lk = @fopen("$in/.lock", 'c'); if (!$lk || !flock($lk, LOCK_EX | LOCK_NB)) return;
  try {
    $vids = json_decode((string)fomaxo_setting($pdo, 'videos'), true) ?: []; $have = []; foreach (fomaxo_product_rows($pdo) ?: [] as $r) $have[$r['id']] = true; $added = 0;
    foreach ($notes as $j) {
      $m = json_decode((string)@file_get_contents($j), true); $f = basename((string)($m['file'] ?? ''));
      if (!is_array($m) || !preg_match('~^[a-z0-9-]+\.(mp4|mov|webm)$~', $f) || !is_file("$in/$f")) { if (@filemtime($j) < time() - 3600) @unlink($j); continue; }   // the file is still being copied: next time
      if (!@rename("$in/$f", fx_media('vid') . "/$f")) continue;
      @chmod(fx_media('vid') . "/$f", 0644); @unlink($j); $prod = (string)($m['product'] ?? ''); $words = substr(trim((string)($m['words'] ?? '')), 0, 60);
      array_unshift($vids, ['id' => bin2hex(random_bytes(5)), 'file' => "vid/$f", 'cover' => '', 'product' => isset($have[$prod]) ? $prod : '', 'on' => true, 'from' => 'fomaxo.in']
        + (preg_match('/^[a-f0-9]{32}$/', (string)($m['fp'] ?? '')) ? ['fp' => (string)$m['fp']] : []) + ($words !== '' ? ['words' => $words] : []) + (!empty($m['wide']) ? ['wide' => true] : []) + (in_array($m['where'] ?? '', ['home', 'product'], true) ? ['where' => $m['where']] : []) + (($nm = mb_substr(trim((string)($m['person'] ?? '')), 0, 30)) !== '' ? ['person' => $nm] : []));
      $added++;
    }
    if ($added) fomaxo_setting($pdo, 'videos', json_encode(array_values($vids)));
  } catch (Throwable $e) {} finally { flock($lk, LOCK_UN); fclose($lk); }
}
