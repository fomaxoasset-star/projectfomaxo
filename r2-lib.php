<?php
/* FOMAXO — Cloudflare R2 (admin → Products → Videos → Cloudflare): the shop video files, also kept on Cloudflare (free up to 10 GB,
   nothing to pay for watching) and played from there once its switch is on. The keys are typed there and kept in
   fomaxo-r2.php ONE LEVEL ABOVE public_html (never on GitHub). A video not copied yet, or the switch off, plays from assets/vid as
   before, and a video Cloudflare fails to send falls back to assets/vid on the website by itself (index.html vidXO).
   The bucket is shared with fomaxo.in: every fomaxo.com file goes under com/ there, and only files this site copied are ever deleted. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }
require_once __DIR__ . '/media-lib.php';

const R2_PREFIX = 'com/';
const R2_FREE = 10 * 1000 ** 3;   // Cloudflare's free space: 10 GB
function r2_file(): string { return dirname(__DIR__) . '/fomaxo-r2.php'; }
/* the keys are pasted once, here: they are also written to fomaxo.in's private folder (same Hostinger account), where its own
   Cloudflare box reads them (fomaxo-private/r2.php), so both sites use them. Keys pasted on fomaxo.in are read from there too */
function r2_in_file(): string { $p = getenv('FOMAXO_IN_PRIVATE') ?: dirname(__DIR__, 2) . '/fomaxo.in/fomaxo-private'; return is_dir($p) && is_writable($p) ? "$p/r2.php" : ''; }
function r2_conf(): ?array {
  foreach ([r2_file(), r2_in_file()] as $f) { $k = $f !== '' && is_file($f) ? require $f : null;
    if (is_array($k) && !empty($k['acct']) && !empty($k['key']) && !empty($k['secret']) && !empty($k['bucket']) && !empty($k['url'])) return $k; }
  return null;
}
function r2_save(?array $c): bool {
  $ok = true;
  foreach ([r2_file(), r2_in_file()] as $f) { if ($f === '') continue;
    if (!$c) { $ok = (@unlink($f) || !is_file($f)) && $ok; continue; }
    $w = @file_put_contents($f, "<?php\n// Cloudflare R2 keys for the shop videos of fomaxo.com and fomaxo.in (written by fomaxo.com/admin → Products → Videos)\nreturn " . var_export($c, true) . ";\n", LOCK_EX) !== false;
    if ($w) @chmod($f, 0600); if ($f === r2_file()) $ok = $w;
  }
  return $ok;
}
function r2_on($pdo): bool { return $pdo && (string)fomaxo_setting($pdo, 'r2_on') === '1' && ($c = r2_conf()) !== null && r2_live($c); }   // switched on AND the public address answers
/* the name on Cloudflare of 'vid/x.mp4': com/x.mp4 */
function r2_key(string $f): string { return R2_PREFIX . substr($f, 4); }
/* the videos already on Cloudflare: 'vid/x.mp4' => size */
function r2_have(): array { $h = json_decode((string)@file_get_contents(fx_media() . '/r2-have.json'), true); return is_array($h) ? $h : []; }
function r2_have_save(array $h): void { @file_put_contents(fx_media() . '/r2-have.json', json_encode($h), LOCK_EX); }
/* the AWS signature (version 4) R2 asks for; $hd: lower-case headers in order, with host, x-amz-content-sha256 and x-amz-date */
function r2_sign(array $c, string $method, string $uri, string $query, array $hd, string $region = 'auto'): string {
  $amz = $hd['x-amz-date']; $day = substr($amz, 0, 8); $scope = "$day/$region/s3/aws4_request";
  $canon = $method . "\n" . $uri . "\n" . $query . "\n" . implode('', array_map(fn($k, $v) => "$k:" . trim((string)$v) . "\n", array_keys($hd), $hd)) . "\n" . implode(';', array_keys($hd)) . "\n" . $hd['x-amz-content-sha256'];
  $k = hash_hmac('sha256', 'aws4_request', hash_hmac('sha256', 's3', hash_hmac('sha256', $region, hash_hmac('sha256', $day, 'AWS4' . $c['secret'], true), true), true), true);
  return 'AWS4-HMAC-SHA256 Credential=' . $c['key'] . "/$scope, SignedHeaders=" . implode(';', array_keys($hd)) . ', Signature=' . hash_hmac('sha256', "AWS4-HMAC-SHA256\n$amz\n$scope\n" . hash('sha256', $canon), $k);
}
/* one signed request to R2 (its S3 address): [HTTP code, answer or error]. $body is a string, or ['file' => path] for a video */
function r2_req(array $c, string $method, string $path, string $query = '', $body = '', array $hd = []): array {
  if (!function_exists('curl_init')) return [0, 'curl is off on this server'];
  $host = $c['acct'] . '.r2.cloudflarestorage.com'; $uri = '/' . $c['bucket'] . ($path !== '' ? '/' . $path : '');
  $file = is_array($body) ? (string)$body['file'] : '';
  $hash = $file !== '' ? hash_file('sha256', $file) : hash('sha256', (string)$body);
  $hd = array_change_key_case($hd) + ['host' => $host, 'x-amz-content-sha256' => $hash, 'x-amz-date' => gmdate('Ymd\THis\Z')]; ksort($hd);
  $auth = r2_sign($c, $method, $uri, $query, $hd);
  $ch = curl_init("https://$host$uri" . ($query !== '' ? "?$query" : ''));
  $opt = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 120,
    CURLOPT_HTTPHEADER => array_merge(['Authorization: ' . $auth], array_map(fn($k, $v) => "$k: $v", array_keys(array_diff_key($hd, ['host' => 1])), array_diff_key($hd, ['host' => 1])))];
  $fh = null;
  if ($file !== '') { $fh = fopen($file, 'rb'); $opt += [CURLOPT_UPLOAD => true, CURLOPT_INFILE => $fh, CURLOPT_INFILESIZE => filesize($file)]; }
  elseif ($method === 'PUT' || $method === 'POST') $opt[CURLOPT_POSTFIELDS] = (string)$body;
  curl_setopt_array($ch, $opt); $out = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch); if ($fh) fclose($fh);
  if ($out === false) return [0, $err ?: 'no answer'];
  return [$code, $code >= 300 && preg_match('~<Message>([^<]+)~', (string)$out, $m) ? $m[1] : (string)$out];
}
/* copies one video ('vid/x.mp4') to R2: '' when done, else what went wrong */
function r2_put(array $c, string $f): string {
  $p = fx_media_path($f); if (!$p || !is_file($p)) return 'file missing';
  $type = ['mp4' => 'video/mp4', 'mov' => 'video/quicktime', 'webm' => 'video/webm'][pathinfo($f, PATHINFO_EXTENSION)] ?? 'video/mp4';
  [$code, $msg] = r2_req($c, 'PUT', r2_key($f), '', ['file' => $p], ['Content-Type' => $type, 'Cache-Control' => 'public, max-age=31536000, immutable']);   // a new name for every upload, so it never changes
  return $code === 200 ? '' : ($msg !== '' ? substr($msg, 0, 160) : "error $code");
}
/* every video file the list uses (the video and its small preview) */
function r2_wanted($pdo): array {
  $w = []; $have = null;
  foreach (json_decode((string)fomaxo_setting($pdo, 'videos'), true) ?: [] as $v) foreach ([(string)($v['file'] ?? ''), (string)($v['prev'] ?? ''), (string)($v['hq'] ?? '')] as $f) {
    if (!preg_match('~^vid/[a-z0-9-]+\.(mp4|mov|webm)$~', $f)) continue;
    if (($p = fx_media_path($f)) && is_file($p)) $w[$f] = (int)filesize($p);
    elseif ($f === (string)($v['hq'] ?? '') && isset(($have ??= r2_have())[$f])) $w[$f] = $have[$f];   // the sharp copy leaves Hostinger once it is on Cloudflare
  }
  return $w;
}
/* copies the videos not on Cloudflare yet, for up to $secs seconds: [copied now, error or ''] */
function r2_sync($pdo, float $secs = 8): array {
  $c = r2_conf(); if (!$c || !$pdo) return [0, ''];
  $lk = @fopen(fx_media() . '/r2-sync.lock', 'c'); if (!$lk || !flock($lk, LOCK_EX | LOCK_NB)) return [0, ''];   // one copy at a time
  $t = microtime(true); $n = 0; $err = ''; $gone = 0;
  try {
    $have = r2_have(); $want = r2_wanted($pdo);
    foreach (array_diff_key($have, $want) as $f => $sz) {   // deleted videos leave Cloudflare too, so the 10 GB bar stays true (only ones this site copied, under com/)
      if (microtime(true) - $t > $secs) break;
      [$code] = r2_req($c, 'DELETE', r2_key($f)); if ($code !== 204 && $code !== 200 && $code !== 404) break;
      unset($have[$f]); r2_have_save($have); $gone++;
    }
    foreach ($want as $f => $sz) {
      if (($have[$f] ?? -1) === $sz) continue;
      if (microtime(true) - $t > $secs) break;
      $e = r2_put($c, $f); if ($e !== '') { $err = $e; break; }
      $have[$f] = $sz; r2_have_save($have); $n++;
      if (str_ends_with($f, '-h.mp4')) @unlink(fx_media('vid') . '/' . substr($f, 4));   // Hostinger keeps only the small copy (the fallback), never the big one
    }
  } finally { flock($lk, LOCK_UN); fclose($lk); }
  if ($n || $gone) fomaxo_products_drop();   // the website picks up the new Cloudflare addresses straight away
  return [$n, $err];
}
/* copies to Cloudflare after the page has gone to the browser, so the admin never waits on (or times out over) the copying */
function r2_sync_later($pdo, float $secs = 120): void {
  static $on = false; if ($on || !r2_conf()) return; $on = true;
  register_shutdown_function(function () use ($pdo, $secs) {
    ignore_user_abort(true); @set_time_limit(300);
    if (function_exists('litespeed_finish_request')) litespeed_finish_request(); elseif (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    try { r2_sync($pdo, $secs); } catch (Throwable $e) { @file_put_contents(__DIR__ . '/vid-report.txt', gmdate('Y-m-d H:i:s') . ' | r2 copy: ' . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX); }
  });
}
/* does the public address really send our files to fomaxo.com (DNS, custom domain, CORS)? One small ask, at most every 10 minutes
   (kept in fomaxo-media/r2-live.json); while it fails the website plays every video from Hostinger as before and the switch won't turn on */
function r2_live(array $c, bool $fresh = false): bool {
  $cf = fx_media() . '/r2-live.json'; $j = json_decode((string)@file_get_contents($cf), true);
  if (!$fresh && is_array($j) && ($j['t'] ?? 0) > time() - 600) return !empty($j['ok']);
  $ok = false;
  if (function_exists('curl_init')) {
    $acao = ''; $ch = curl_init(rtrim($c['url'], '/') . '/' . R2_PREFIX . 'fomaxo-check.txt');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 6, CURLOPT_HTTPHEADER => ['Origin: https://fomaxo.com'],
      CURLOPT_HEADERFUNCTION => function ($ch, $h) use (&$acao) { if (stripos($h, 'access-control-allow-origin:') === 0) $acao = trim(substr($h, 28)); return strlen($h); }]);
    $out = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $ok = $code === 200 && trim((string)$out) === 'ok' && ($acao === '*' || $acao === 'https://fomaxo.com');   // CORS too: the videos are asked for with crossorigin
  }
  @file_put_contents($cf, json_encode(['t' => time(), 'ok' => $ok]), LOCK_EX);
  return $ok;
}
function r2_used(): int { return (int)array_sum(r2_have()); }
/* the whole bucket, both sites (fomaxo.in's files and ours under com/): the free 10 GB is for the two together.
   Asked from Cloudflare at most every 10 minutes (and after a copy), kept in fomaxo-media/r2-total.json; null when Cloudflare can't say */
function r2_total(bool $fresh = false): ?int {
  $j = fx_media() . '/r2-total.json'; $t = json_decode((string)@file_get_contents($j), true);
  if (!$fresh && is_array($t) && time() - (int)($t['at'] ?? 0) < 600) return (int)$t['bytes'];
  $c = r2_conf(); if (!$c) return null;
  $sum = 0; $tok = ''; $pages = 0;
  do {
    [$code, $out] = r2_req($c, 'GET', '', ($tok !== '' ? 'continuation-token=' . rawurlencode($tok) . '&' : '') . 'list-type=2');
    if ($code !== 200) return is_array($t) ? (int)$t['bytes'] : null;
    preg_match_all('~<Size>(\d+)</Size>~', (string)$out, $m); $sum += array_sum(array_map('intval', $m[1]));
    $tok = preg_match('~<IsTruncated>true</IsTruncated>~', (string)$out) && preg_match('~<NextContinuationToken>([^<]+)~', (string)$out, $n) ? html_entity_decode($n[1], ENT_XML1) : '';
  } while ($tok !== '' && ++$pages < 20);
  @file_put_contents($j, json_encode(['bytes' => $sum, 'at' => time()]), LOCK_EX);
  return $sum;
}
/* checks the keys (a small test file), lets both websites play the videos (CORS, so Safari's black-frame check still works),
   and checks the public address: '' when all is well, else what to fix */
function r2_check(array $c): string {
  [$code, $msg] = r2_req($c, 'PUT', R2_PREFIX . 'fomaxo-check.txt', '', 'ok', ['Content-Type' => 'text/plain']);
  if ($code !== 200) return 'Cloudflare did not accept the keys: ' . ($code ? substr($msg, 0, 160) : $msg);
  $cors = '<?xml version="1.0" encoding="UTF-8"?><CORSConfiguration><CORSRule>'   // the bucket is shared: the rule keeps fomaxo.in in too
    . implode('', array_map(fn($o) => "<AllowedOrigin>$o</AllowedOrigin>", ['https://fomaxo.com', 'https://www.fomaxo.com', 'https://fomaxo.in', 'https://www.fomaxo.in']))
    . '<AllowedMethod>GET</AllowedMethod><AllowedMethod>HEAD</AllowedMethod><AllowedHeader>*</AllowedHeader><ExposeHeader>Content-Length</ExposeHeader><ExposeHeader>Content-Range</ExposeHeader><MaxAgeSeconds>86400</MaxAgeSeconds></CORSRule></CORSConfiguration>';
  [$code, $msg] = r2_req($c, 'PUT', '', 'cors=', $cors, ['Content-Type' => 'application/xml', 'Content-MD5' => base64_encode(md5($cors, true))]);
  $note = $code === 200 ? '' : 'The keys work, but CORS could not be set (' . substr($msg, 0, 80) . '): if videos do not play, add fomaxo.com to the CORS rule in Cloudflare → R2 → your bucket → Settings';   // a token for one bucket only may not set it
  $ch = curl_init(rtrim($c['url'], '/') . '/' . R2_PREFIX . 'fomaxo-check.txt'); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 20]);
  $out = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
  if ($code !== 200 || trim((string)$out) !== 'ok') $note .= ($note !== '' ? '. Also, ' : 'The keys work, but ') . $c['url'] . ' does not open the files yet (custom domain not connected to the bucket?)';
  return $note;
}
/* the full video for the player: the sharp 1080p copy when Cloudflare plays it (vf = the small Hostinger copy the page falls back to), else the usual one */
function r2_full($pdo, array $v): array {
  $h = (string)($v['hq'] ?? ''); $u = $h !== '' ? r2_cdn($pdo, $h) : '';
  return str_starts_with($u, 'https://') ? ['v' => $u, 'vf' => (string)$v['file']] : ['v' => r2_cdn($pdo, (string)$v['file'])];
}
/* the address the website plays 'vid/x.mp4' from: Cloudflare when on and copied, else the file as it is (index.html adds assets/) */
function r2_cdn($pdo, string $f): string {
  static $on = null, $have = null, $base = '';
  if ($on === null) { $on = r2_on($pdo); $have = $on ? r2_have() : []; $base = $on ? rtrim((string)r2_conf()['url'], '/') : ''; }
  return $on && isset($have[$f]) ? "$base/" . r2_key($f) : $f;   // the assets/vid copy stays, for the fallback
}
