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
function r2_conf(): ?array { $f = r2_file(); $c = is_file($f) ? require $f : null; return is_array($c) && !empty($c['acct']) && !empty($c['key']) && !empty($c['secret']) && !empty($c['bucket']) && !empty($c['url']) ? $c : null; }
function r2_save(?array $c): bool {
  $f = r2_file();
  if (!$c) return @unlink($f) || !is_file($f);
  $ok = @file_put_contents($f, "<?php\n// Cloudflare R2 keys for the shop videos (written by fomaxo.com/admin → Products → Videos)\nreturn " . var_export($c, true) . ";\n", LOCK_EX) !== false;
  if ($ok) @chmod($f, 0600);
  return $ok;
}
function r2_on($pdo): bool { return $pdo && (string)fomaxo_setting($pdo, 'r2_on') === '1' && r2_conf() !== null; }
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
  $w = []; foreach (json_decode((string)fomaxo_setting($pdo, 'videos'), true) ?: [] as $v) foreach ([(string)($v['file'] ?? ''), (string)($v['prev'] ?? '')] as $f)
    if (preg_match('~^vid/[a-z0-9-]+\.(mp4|mov|webm)$~', $f) && ($p = fx_media_path($f)) && is_file($p)) $w[$f] = (int)filesize($p);
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
    }
  } finally { flock($lk, LOCK_UN); fclose($lk); }
  if ($n || $gone) fomaxo_products_drop();   // the website picks up the new Cloudflare addresses straight away
  return [$n, $err];
}
function r2_used(): int { return (int)array_sum(r2_have()); }
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
/* the address the website plays 'vid/x.mp4' from: Cloudflare when on and copied, else the file as it is (index.html adds assets/) */
function r2_cdn($pdo, string $f): string {
  static $on = null, $have = null, $base = '';
  if ($on === null) { $on = r2_on($pdo); $have = $on ? r2_have() : []; $base = $on ? rtrim((string)r2_conf()['url'], '/') : ''; }
  return $on && isset($have[$f]) ? "$base/" . r2_key($f) : $f;   // the assets/vid copy stays, for the fallback
}
