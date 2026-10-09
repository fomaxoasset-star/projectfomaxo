<?php
/* FOMAXO — product reviews: shared helpers (used by reviews.php, checkout.php and ziina.php).
   Everything is kept OUTSIDE public_html in domains/fomaxo.com/fomaxo-reviews/ (private, kept across GitHub deploys):
     reviews.json   all reviews (hidden ones stay in the file with "hidden": true). Private keys, never in the public list (rv_public):
                    "mobile" UAE mobile digits as typed ('' when none; reviews without an order link only),
                    "issue"  'late' | 'faulty' | '' — "Any problem with your order?" on a 1–3 star review without an order link
     photos/        customer photos, re-encoded as JPEG
     links/         one file per order: the private "review your order" link that gives Verified Purchaser
     secret.key     random key made on first use; signs the hide/show links emailed to FOMAXO */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }

function rv_dir($sub = '') {
  $d = dirname(__DIR__) . '/fomaxo-reviews' . ($sub !== '' ? "/$sub" : '');
  if (!is_dir($d)) @mkdir($d, 0700, true);
  return $d;
}
function rv_secret() {
  static $k = null; if ($k !== null) return $k;
  $f = rv_dir() . '/secret.key';
  $k = is_file($f) ? trim((string)@file_get_contents($f)) : '';
  if (strlen($k) < 32) { $k = bin2hex(random_bytes(32)); @file_put_contents($f, $k, LOCK_EX); @chmod($f, 0600); }
  return $k;
}
function rv_sign($what) { return substr(hash_hmac('sha256', $what, rv_secret()), 0, 32); }

/* Makes the private review link for an order. $ids = product ids bought in that order (from the bag lines).
   Returns the token for #/review?t=TOKEN, or null if it could not be saved. */
function fomaxo_review_link($orderNo, $ids) {
  $ids = array_values(array_unique(array_filter(array_map(fn($x) => is_string($x) ? $x : '', (array)$ids))));
  if (!$ids) return null;
  $t = bin2hex(random_bytes(12));
  $ok = @file_put_contents(rv_dir('links') . "/$t.json",
    json_encode(['no' => (string)$orderNo, 'products' => $ids, 'created' => date('c'), 'done' => new stdClass()]), LOCK_EX);
  return $ok ? $t : null;
}

/* ---- reviews.json: read, or change under a file lock ---- */
/* first letter of every word in capitals ("ahmed saleh" -> "Ahmed Saleh"); the rest is left as typed */
function rv_caps($s) { return preg_replace_callback('/(^|[\s\-\/(])(\p{Ll})/u', fn($m) => $m[1] . mb_strtoupper($m[2]), (string)$s); }
function rv_all() {
  $f = rv_dir() . '/reviews.json';
  $d = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
  $list = is_array($d['reviews'] ?? null) ? $d['reviews'] : [];
  /* name, real name and state / city always shown with capitals, also for reviews saved before this rule */
  foreach ($list as &$r) { foreach (['name', 'real', 'city'] as $k) if (isset($r[$k]) && is_string($r[$k])) $r[$k] = rv_caps($r[$k]); }
  unset($r);
  return $list;
}
function rv_change(callable $fn) {
  $fh = @fopen(rv_dir() . '/reviews.json', 'c+'); if (!$fh) return false;
  if (!flock($fh, LOCK_EX)) { fclose($fh); return false; }
  $d = json_decode(stream_get_contents($fh), true);
  $list = is_array($d['reviews'] ?? null) ? $d['reviews'] : [];
  $res = $fn($list);
  ftruncate($fh, 0); rewind($fh);
  fwrite($fh, json_encode(['reviews' => $list], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  fflush($fh); flock($fh, LOCK_UN); fclose($fh);
  return $res;
}
