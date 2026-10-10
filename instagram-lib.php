<?php
/* FOMAXO — Instagram reels for the shop videos (fomaxo.com/admin → Products → Videos → From Instagram).
   Uses the Instagram API with Instagram login (Business or Creator account). The access token is typed in the admin
   and kept in fomaxo-instagram.php ONE LEVEL ABOVE public_html (never on GitHub, never public).
   A chosen reel is copied to assets/vid/ so it keeps playing even if it is removed from Instagram. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }

function fomaxo_ig_file() { return dirname(__DIR__) . '/fomaxo-instagram.php'; }
function fomaxo_ig() { $f = fomaxo_ig_file(); $c = is_file($f) ? require $f : null; return is_array($c) && !empty($c['token']) ? $c : null; }
function fomaxo_ig_save($c) {
  $f = fomaxo_ig_file();
  if (!$c) return @unlink($f) || !is_file($f);
  $ok = @file_put_contents($f, "<?php\n// Instagram access token for the shop videos (written by fomaxo.com/admin → Products → Videos)\nreturn " . var_export($c, true) . ";\n", LOCK_EX) !== false;
  if ($ok) @chmod($f, 0600);
  return $ok;
}
/* GET from the Instagram API: [data, error message] */
function fomaxo_ig_api($path, array $q, $token) {
  $base = defined('FX_IG_API') ? FX_IG_API : 'https://graph.instagram.com';
  $ch = curl_init($base . '/' . ltrim($path, '/') . '?' . http_build_query($q + ['access_token' => $token]));
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8]);
  $r = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
  $d = json_decode((string)$r, true);
  if ($code === 200 && is_array($d)) return [$d, null];
  return [null, (is_array($d) && isset($d['error']['message'])) ? (string)$d['error']['message'] : ($err ?: 'Instagram did not answer (' . $code . ')')];
}
/* checks a newly typed token and returns ['token', 'user', 'at'] or an error message */
function fomaxo_ig_connect($token) {
  [$me, $err] = fomaxo_ig_api('me', ['fields' => 'user_id,username'], $token);
  if (!$me) return $err;
  $c = ['token' => $token, 'user' => (string)($me['username'] ?? ''), 'at' => time(), 'since' => (fomaxo_ig()['since'] ?? time())];   // since: Automatic only adds reels posted after the first connect
  return fomaxo_ig_save($c) ? $c : 'The token could not be saved. Please try again.';
}
/* long-lived tokens last 60 days: renew it once a week while the admin is used, so it never runs out */
function fomaxo_ig_fresh() {
  $c = fomaxo_ig(); if (!$c || time() - (int)($c['at'] ?? 0) < 7 * 86400) return $c;
  [$r] = fomaxo_ig_api('refresh_access_token', ['grant_type' => 'ig_refresh_token'], $c['token']);
  $c['at'] = time(); if (!empty($r['access_token'])) $c['token'] = (string)$r['access_token'];
  fomaxo_ig_save($c);
  return $c;
}
/* the latest videos and reels: [[id, thumb, url, caption, at, link], …] or an error message */
function fomaxo_ig_reels($c, $n = 24) {
  [$d, $err] = fomaxo_ig_api('me/media', ['fields' => 'id,caption,media_type,media_url,thumbnail_url,permalink,timestamp', 'limit' => 50], $c['token']);
  if (!$d) return $err;
  $out = [];
  foreach ((array)($d['data'] ?? []) as $m) if (($m['media_type'] ?? '') === 'VIDEO' && !empty($m['media_url']))
    $out[] = ['id' => (string)$m['id'], 'thumb' => (string)($m['thumbnail_url'] ?? ''), 'caption' => (string)($m['caption'] ?? ''), 'at' => strtotime((string)($m['timestamp'] ?? '')) ?: 0, 'link' => (string)($m['permalink'] ?? '')];
  return array_slice($out, 0, $n);
}
/* one reel's fresh video and cover addresses (Instagram's addresses expire, so they are asked for at the moment of copying) */
function fomaxo_ig_media($c, $id) {
  [$m, $err] = fomaxo_ig_api(rawurlencode($id), ['fields' => 'id,media_type,media_url,thumbnail_url'], $c['token']);
  if (!$m) return $err;
  if (($m['media_type'] ?? '') !== 'VIDEO' || empty($m['media_url'])) return 'That post is not a video.';
  return $m;
}
/* copies a file from Instagram to $dest (at most $max bytes); true or an error message */
function fomaxo_ig_download($url, $dest, $max = 300 * 1048576) {
  if (!preg_match('~^https?://~', $url) || !($fh = @fopen($dest, 'wb'))) return 'The video could not be saved.';
  $ch = curl_init($url); $got = 0;
  curl_setopt_array($ch, [CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 4, CURLOPT_TIMEOUT => 240, CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_WRITEFUNCTION => function ($ch, $s) use ($fh, &$got, $max) { $got += strlen($s); return $got > $max ? 0 : fwrite($fh, $s); }]);
  $ok = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); fclose($fh);
  if ($ok && $code === 200 && $got > 0) return true;
  @unlink($dest);
  return $got > $max ? 'The video is too big.' : 'The video could not be copied from Instagram. Please try again.';
}
/* copies one reel (video + cover photo) to our server: the new entry for the video list, or an error message */
function fomaxo_ig_copy($c, $ig, $prod) {
  $m = fomaxo_ig_media($c, $ig); if (!is_array($m)) return 'Instagram: ' . $m;
  $dir = __DIR__ . '/assets/vid'; $name = $prod . '-' . bin2hex(random_bytes(4)) . '.mp4';
  if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return 'The video could not be saved. Please try again.';
  @set_time_limit(300);
  if (($r = fomaxo_ig_download($m['media_url'], "$dir/$name")) !== true) return $r;
  $cover = '';   // Instagram's cover picture, as a compressed webp in assets/img/up/
  if (!empty($m['thumbnail_url']) && function_exists('imagewebp') && ($tmp = tempnam(sys_get_temp_dir(), 'fxig'))) {
    if (fomaxo_ig_download($m['thumbnail_url'], $tmp, 15 * 1048576) === true && ($im = @imagecreatefromstring((string)file_get_contents($tmp)))) {
      $w = imagesx($im); $h = imagesy($im); if (max($w, $h) > 1600) $im = imagescale($im, $w >= $h ? 1600 : (int)round($w * 1600 / $h), $w >= $h ? (int)round($h * 1600 / $w) : 1600);
      $up = __DIR__ . '/assets/img/up'; $k = $prod . '-cover-' . bin2hex(random_bytes(4));
      imagepalettetotruecolor($im);
      if ((is_dir($up) || @mkdir($up, 0755, true)) && @imagewebp($im, "$up/$k.webp", 80)) $cover = "up/$k";
    }
    @unlink($tmp);
  }
  return ['id' => bin2hex(random_bytes(5)), 'file' => "vid/$name", 'cover' => $cover, 'product' => $prod, 'on' => true, 'ig' => (string)$ig];
}
/* the product a caption names (the longest matching name wins, so "Old Money" beats "Money"); null = none */
function fomaxo_ig_product($caption, array $names) {
  $best = null; $len = 0; $cap = ' ' . strtolower(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $caption)) . ' ';
  foreach ($names as $id => $n) { $w = strtolower(trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $n))); if ($w !== '' && strlen($w) > $len && strpos($cap, " $w ") !== false) { $best = $id; $len = strlen($w); } }
  return $best;
}
/* Automatic: reels posted after Instagram was connected are added by themselves when their caption names a product.
   Runs at most once an hour (from products.php after the page has its answer, and from the admin Videos page). */
function fomaxo_ig_sync($pdo, $force = false) {
  $c = fomaxo_ig(); if (!$c || (string)fomaxo_setting($pdo, 'ig_auto') === '0') return 0;
  if (!$force && time() - (int)fomaxo_setting($pdo, 'ig_sync_at') < 3600) return 0;
  fomaxo_setting($pdo, 'ig_sync_at', (string)time());
  $c = fomaxo_ig_fresh(); $reels = fomaxo_ig_reels($c, 25); if (!is_array($reels)) return 0;
  $names = []; foreach (fomaxo_product_rows($pdo) ?: [] as $r) if (!$r['hidden']) { $d = json_decode($r['data'], true) ?: []; $names[$r['id']] = (string)($d['name'] ?? ''); }
  $since = (int)($c['since'] ?? $c['at'] ?? 0);
  $seen = json_decode((string)fomaxo_setting($pdo, 'ig_seen'), true) ?: [];   // reels already looked at (added, deleted or with no product name)
  $vids = json_decode((string)fomaxo_setting($pdo, 'videos'), true) ?: [];
  $have = array_filter(array_column($vids, 'ig')); $added = 0;
  foreach (array_reverse($reels) as $r) {   // oldest first, so the newest ends up first on the website
    if ($r['at'] < $since || in_array($r['id'], $have, true) || in_array($r['id'], $seen, true) || $added >= 3) continue;
    $seen[] = $r['id'];
    if (!($prod = fomaxo_ig_product($r['caption'], $names))) continue;
    $v = fomaxo_ig_copy($c, $r['id'], $prod); if (!is_array($v)) { array_pop($seen); continue; }   // try again next hour
    $v['auto'] = true; array_unshift($vids, $v); $added++;
  }
  fomaxo_setting($pdo, 'ig_seen', json_encode(array_slice($seen, -300)));
  if ($added) fomaxo_setting($pdo, 'videos', json_encode(array_values($vids)));
  return $added;
}
