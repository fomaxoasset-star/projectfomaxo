<?php
/* FOMAXO — product reviews.
   GET  reviews.php?summary          → average rating and count for every product
   GET  reviews.php?product=ID       → the visible reviews of one product
   GET  reviews.php?link=TOKEN       → the products of an order's private review link (Verified Purchaser)
   GET  reviews.php?photo=FILE       → a customer photo
   POST reviews.php  action=review   → new review (form data: product, rating, name, anon, text, token, photos[])
   POST reviews.php  action=helpful  → "Helpful" vote (one per browser)
   GET  reviews.php?manage&k=KEY     → FOMAXO's page to hide or show reviews (the link is in every new-review email)
   Reviews publish straight away. Every new review is emailed to $STORE_EMAIL with the link to hide it. */
header('Cache-Control: no-store');
require __DIR__ . '/store-lib.php';
require __DIR__ . '/reviews-lib.php';

$STORE_EMAIL = 'fomaxoasset@gmail.com';
$MAX_PHOTOS  = 3;            // photos per review
$MAX_PHOTO_MB = 12;          // per photo, before we shrink it
$PHOTO_PX    = 1600;         // longest side after shrinking

date_default_timezone_set('Asia/Dubai');
$method = $_SERVER['REQUEST_METHOD'];
function out($data, $code = 200) { http_response_code($code); header('Content-Type: application/json'); echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }

function rv_public($r) {
  return ['id' => $r['id'], 'name' => $r['name'], 'rating' => $r['rating'], 'text' => $r['text'], 'verified' => !empty($r['verified']),
          'city' => $r['city'] ?? '', 'country' => $r['country'] ?? '', 'date' => substr($r['created'], 0, 10), 'helpful' => (int)($r['helpful'] ?? 0),
          'photos' => array_map(fn($p) => 'reviews.php?photo=' . rawurlencode($p), $r['photos'] ?? []), 'reply' => empty($r['reply_hidden']) ? (string)($r['reply'] ?? '') : ''];
}
/* send the answer now and keep working after it (translating), so the customer never waits */
function rv_send_then($data) {
  ignore_user_abort(true);
  $b = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  header('Content-Type: application/json'); header('Content-Length: ' . strlen($b)); header('Connection: close');
  echo $b;
  if (function_exists('litespeed_finish_request')) litespeed_finish_request();
  elseif (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
  else { while (ob_get_level()) ob_end_flush(); flush(); }
}
function rv_stats($list) {
  $dist = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0]; $sum = 0;
  foreach ($list as $r) { $dist[$r['rating']]++; $sum += $r['rating']; }
  $n = count($list);
  return ['count' => $n, 'avg' => $n ? round($sum / $n, 1) : 0, 'dist' => $dist];
}
function rv_ip() { return substr(hash_hmac('sha256', $_SERVER['REMOTE_ADDR'] ?? '', rv_secret()), 0, 16); }
function rv_base() {
  $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
  $host = preg_replace('/[^A-Za-z0-9.\-:]/', '', $_SERVER['HTTP_HOST'] ?? 'fomaxo.com');
  $dir  = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
  return ($https ? 'https' : 'http') . "://$host$dir/";
}
function rv_clean($v, $max, $lines = false) {
  $v = is_string($v) ? $v : '';
  $v = str_replace(["\r\n", "\r"], "\n", $v);
  $v = preg_replace($lines ? '/[\x00-\x09\x0B-\x1F\x7F]+/u' : '/[\x00-\x1F\x7F]+/u', ' ', $v);
  if ($lines) $v = preg_replace("/\n{3,}/", "\n\n", preg_replace('/[ \t]+/', ' ', $v)); else $v = preg_replace('/\s+/u', ' ', $v);
  return trim(mb_substr(trim((string)$v), 0, $max));
}
/* "ahmed saleh" → "Ahmed Saleh": full name as typed, each word starting with a capital */
function rv_display_name($full) {
  $parts = preg_split('/\s+/u', trim(preg_replace('/[^\p{L}\p{M}\s\'\-.]/u', '', $full)), -1, PREG_SPLIT_NO_EMPTY);
  return mb_substr(implode(' ', array_map(fn($w) => mb_strtoupper(mb_substr($w, 0, 1)) . mb_substr($w, 1), $parts)), 0, 60);
}

/* words about a late delivery or a faulty / damaged product (English, Hinglish and Arabic), the same lists as the review form in index.html */
const RV_LATE = '/\b(?:late|delay(?:ed)?|not (?:yet )?(?:received|delivered|arrived)|never (?:came|arrived)|der(?:i|ee)? se)\b|تأخر|تاخر|متأخر|متاخر|تأخير|تاخير|لم يصل|ما وصل|ماوصل|ما جاني|لم يتم التوصيل/iu';
const RV_FAULTY = '/\b(?:faulty|defective|damaged?|broken|cracked|leak(?:ed|ing|s)?|spill(?:ed)?|wrong (?:item|product|perfume)|toot(?:a|i))\b|مكسور|انكسر|تكسر|تالف|خربان|معيوب|يسرب|يسرّب|تسريب|مسكوب|انسكب|منتج خطأ|منتج خطا|منتج غلط|طلب غلط/iu';
/* a UAE mobile: 05x xxx xxxx, 5x xxx xxxx or +971 / 00971 5x xxx xxxx (same rule as rwMobOk in index.html) */
function rv_mob_ok($d) { if (strpos($d, '00') === 0) $d = substr($d, 2); return (bool)preg_match('/^(?:9715\d{8}|05\d{8}|5\d{8})$/', $d); }

/* ---------------- photos ---------------- */
if ($method === 'GET' && isset($_GET['photo'])) {
  $n = (string)$_GET['photo'];
  $f = rv_dir('photos') . '/' . $n;
  if (!preg_match('/^[a-f0-9]{16}-\d\.jpg$/', $n) || !is_file($f)) { http_response_code(404); exit; }
  header_remove('Cache-Control');
  header('Content-Type: image/jpeg'); header('Cache-Control: public, max-age=31536000, immutable');
  header('X-Content-Type-Options: nosniff'); header('Content-Length: ' . filesize($f));
  readfile($f); exit;
}
/* Checks it is a real photo, turns it the right way up, shrinks it and saves a fresh JPEG (drops any hidden data). */
function rv_save_photo($tmp, $name, $px) {
  if (!function_exists('imagecreatefromstring')) return false;
  $info = @getimagesize($tmp);
  if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) return false;
  if ($info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > 50000000) return false;
  $src = @imagecreatefromstring((string)file_get_contents($tmp)); if (!$src) return false;
  if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
    $o = (int)(@exif_read_data($tmp)['Orientation'] ?? 1);
    $rot = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
    if ($rot) { $r = imagerotate($src, $rot, 0); if ($r) { imagedestroy($src); $src = $r; } }
  }
  $w = imagesx($src); $h = imagesy($src); $s = min(1, $px / max($w, $h));
  $nw = max(1, (int)round($w * $s)); $nh = max(1, (int)round($h * $s));
  $dst = imagecreatetruecolor($nw, $nh);
  imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
  imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
  imageinterlace($dst, true);
  $ok = imagejpeg($dst, rv_dir('photos') . "/$name", 82);
  imagedestroy($src); imagedestroy($dst);
  return $ok;
}

/* ---------------- summary of every product (for the stars next to product names) ---------------- */
if ($method === 'GET' && isset($_GET['summary'])) {
  $by = [];
  foreach (rv_all() as $r) if (empty($r['hidden'])) $by[$r['product']][] = $r;
  $res = [];
  foreach ($by as $pid => $list) { $s = rv_stats($list); $res[$pid] = ['avg' => $s['avg'], 'count' => $s['count']]; }
  out(['products' => (object)$res]);
}

/* ---------------- one product's reviews ---------------- */
if ($method === 'GET' && isset($_GET['product'])) {
  $pid = (string)$_GET['product'];
  if (!isset($CATALOG[$pid])) out(['error' => 'Unknown product.'], 404);
  $list = array_values(array_filter(rv_all(), fn($r) => $r['product'] === $pid && empty($r['hidden'])));
  usort($list, fn($a, $b) => strcmp($b['created'], $a['created']));
  $pub = array_map('rv_public', $list);
  if (($_GET['lang'] ?? '') !== 'ar') out(rv_stats($list) + ['reviews' => $pub]);
  /* Arabic site: English reviews and replies also come in Arabic (kept from before, so nothing waits); any not translated yet are done after this answer is sent */
  $texts = []; foreach ($pub as $r) { $texts[] = $r['text']; $texts[] = $r['reply']; }
  $ar = fomaxo_ar_many($texts);
  foreach ($pub as &$r) { if (isset($ar[$r['text']])) $r['text_ar'] = $ar[$r['text']]; if ($r['reply'] !== '' && isset($ar[$r['reply']])) $r['reply_ar'] = $ar[$r['reply']]; }
  unset($r);
  rv_send_then(rv_stats($list) + ['reviews' => $pub]);
  fomaxo_ar_many(array_diff($texts, array_keys($ar)), true, array_column($CATALOG, 'name'));
  exit;
}

/* ---------------- an order's review link ---------------- */
function rv_link($t) {
  if (!is_string($t) || !preg_match('/^[a-f0-9]{24}$/', $t)) return null;
  $f = rv_dir('links') . "/$t.json";
  $l = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
  return is_array($l) && rv_order_ok($l['no'] ?? '') ? $l + ['file' => $f] : null;
}
/* the link's order must be a real order: placed, not a test, not cancelled or refunded, and a card order must be paid
   (when the database cannot be reached the link still works, so real customers are not turned away) */
function rv_order_ok($no) {
  $pdo = fomaxo_db(); if (!$pdo) return true;
  try { $s = $pdo->prepare('SELECT status, test FROM fx_orders WHERE order_no = ?'); $s->execute([(string)$no]); $o = $s->fetch(PDO::FETCH_ASSOC); }
  catch (Throwable $e) { return true; }
  return $o && !(int)$o['test'] && !in_array($o['status'], ['Awaiting payment', 'Cancelled', 'Refunded'], true);
}
if ($method === 'GET' && isset($_GET['link'])) {
  $l = rv_link($_GET['link']);
  if (!$l) out(['error' => 'This review link is not valid. Please use the link from your order confirmation.'], 404);
  $done = (array)($l['done'] ?? []);
  out(['order' => $l['no'], 'products' => array_map(fn($id) => ['id' => $id, 'done' => isset($done[$id])], array_values(array_filter($l['products'], fn($id) => isset($CATALOG[$id]))))]);
}

/* ---------------- FOMAXO's manage page: hide / show reviews ---------------- */
if (isset($_GET['manage']) || ($_POST['action'] ?? '') === 'toggle') {
  $k = (string)($_GET['k'] ?? $_POST['k'] ?? '');
  if (!hash_equals(rv_sign('manage'), $k)) { http_response_code(403); header('Content-Type: text/plain'); echo 'This link is not valid.'; exit; }
  if ($method === 'POST') {
    $id = (string)($_POST['id'] ?? ''); $hide = ($_POST['hide'] ?? '') === '1';
    rv_change(function (&$list) use ($id, $hide) { foreach ($list as &$r) if ($r['id'] === $id) $r['hidden'] = $hide; });
    header('Location: reviews.php?manage&k=' . rawurlencode($k) . '#r-' . rawurlencode($id), true, 303); exit;
  }
  header('Content-Type: text/html; charset=UTF-8');
  $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
  $list = rv_all(); usort($list, fn($a, $b) => strcmp($b['created'], $a['created']));
  echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">'
     . '<title>FOMAXO reviews</title><style>body{margin:0;background:#050505;color:#f2ebdd;font:15px/1.6 Arial,sans-serif;padding:24px 16px}main{max-width:820px;margin:auto}'
     . 'h1{color:#d7aa69;font-weight:400;letter-spacing:.1em}.r{border:1px solid rgba(215,170,105,.35);padding:16px;margin:0 0 14px}.r.off{opacity:.55}.m{color:#b5ab98;font-size:13px}'
     . '.s{color:#d7aa69;letter-spacing:.15em}button{background:#d7aa69;color:#000;border:0;padding:10px 16px;font:inherit;cursor:pointer}.off button{background:#f2ebdd}p{white-space:pre-wrap;margin:8px 0}'
     . 'img{width:80px;height:80px;object-fit:cover;margin:4px 4px 0 0}</style></head><body><main><h1>FOMAXO reviews</h1>'
     . '<p class="m">' . count($list) . ' reviews. Hidden reviews are not shown on the website and do not count in the star rating. Keep this link private.</p>';
  foreach ($list as $r) {
    $off = !empty($r['hidden']);
    echo '<div class="r' . ($off ? ' off' : '') . '" id="r-' . $h($r['id']) . '"><div><b>' . $h($CATALOG[$r['product']]['name'] ?? $r['product']) . '</b> · <span class="s">' . str_repeat('★', $r['rating']) . str_repeat('☆', 5 - $r['rating']) . '</span></div>'
       . '<div class="m">' . $h($r['name']) . ($r['anon'] ? ' (real name: ' . $h($r['real'] ?? '') . ')' : '') . (($r['city'] ?? '') . ($r['country'] ?? '') !== '' ? ' · ' . $h(trim(($r['city'] ?? '') . ' ' . ($r['country'] ?? ''))) : '') . ' · ' . ($r['verified'] ? 'Verified Purchaser, order ' . $h($r['order']) : 'not verified') . ' · ' . $h(substr($r['created'], 0, 16)) . ($off ? ' · HIDDEN' : '') . '</div>'
       . '<p>' . $h($r['text']) . '</p>';
    foreach ($r['photos'] ?? [] as $p) echo '<a href="reviews.php?photo=' . $h($p) . '" target="_blank"><img src="reviews.php?photo=' . $h($p) . '" alt=""></a>';
    echo '<form method="post" action="reviews.php"><input type="hidden" name="action" value="toggle"><input type="hidden" name="k" value="' . $h($k) . '"><input type="hidden" name="id" value="' . $h($r['id']) . '">'
       . '<input type="hidden" name="hide" value="' . ($off ? '0' : '1') . '"><button>' . ($off ? 'Show on website' : 'Hide from website') . '</button></form></div>';
  }
  echo '</main></body></html>'; exit;
}

if ($method !== 'POST') out(['error' => 'Method not allowed'], 405);

/* ---------------- helpful vote (one per browser) ---------------- */
$json = json_decode((string)file_get_contents('php://input'), true);
if (is_array($json) && ($json['action'] ?? '') === 'helpful') {
  $id = (string)($json['id'] ?? ''); $voter = (string)($json['voter'] ?? '');
  if (!preg_match('/^[a-f0-9]{16}$/', $id) || !preg_match('/^[A-Za-z0-9]{12,64}$/', $voter)) out(['error' => 'Invalid vote.'], 400);
  $vh = substr(hash_hmac('sha256', "v|$voter", rv_secret()), 0, 16); $ip = rv_ip();
  $res = rv_change(function (&$list) use ($id, $vh, $ip) {
    foreach ($list as &$r) if ($r['id'] === $id && empty($r['hidden'])) {
      $r['voters'] = $r['voters'] ?? []; $r['ipv'] = $r['ipv'] ?? [];
      if (in_array($vh, $r['voters'], true) || ($r['ipv'][$ip] ?? 0) >= 3) return ['helpful' => (int)($r['helpful'] ?? 0), 'voted' => true];
      $r['voters'][] = $vh; $r['ipv'][$ip] = ($r['ipv'][$ip] ?? 0) + 1; $r['helpful'] = (int)($r['helpful'] ?? 0) + 1;
      return ['helpful' => $r['helpful'], 'voted' => true];
    }
    return null;
  });
  if (!$res) out(['error' => 'Review not found.'], 404);
  out($res);
}

/* ---------------- new review ---------------- */
if (($_POST['action'] ?? '') !== 'review') {
  if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 && !$_POST) out(['error' => 'Your photos are too large. Please choose smaller photos.'], 413);
  out(['error' => 'Unknown request.'], 400);
}
if (!empty($_POST['website'])) out(['ok' => true, 'review' => null]);   // spam trap (hidden box people never see)

$pid = (string)($_POST['product'] ?? '');
if (!isset($CATALOG[$pid])) out(['error' => 'Unknown product.'], 400);
$rating = (int)($_POST['rating'] ?? 0);
if ($rating < 1 || $rating > 5) out(['error' => 'Please choose a star rating.'], 400);
/* Verified Purchaser: only through the order's private review link, once per product */
$token = (string)($_POST['token'] ?? '');
$link = $token !== '' ? rv_link($token) : null;
if ($token !== '' && !$link) out(['error' => 'This review link is not valid. Please use the link from your order confirmation.'], 400);
if ($link && !in_array($pid, $link['products'], true)) $link = null;   // not in that order → normal review
if ($link && isset(((array)($link['done'] ?? []))[$pid])) out(['error' => "You have already reviewed this product for order {$link['no']}. Thank you!"], 409);

$text = rv_clean($_POST['text'] ?? '', 5000, true);
/* reviews without an order link, 1–3 stars: "Any problem with your order?" (ignored above 3 stars). The picked problem must fit the words. */
$issue = $rating <= 3 && !$link && in_array($_POST['issue'] ?? '', ['late', 'faulty'], true) ? $_POST['issue'] : '';
if ($rating <= 3 && !$link && $issue === '' && (preg_match(RV_LATE, $text) || preg_match(RV_FAULTY, $text)))
  out(['error' => 'It sounds like there was a problem with your order. Please pick Late delivery or Faulty or damaged product under “Any problem with your order?”.'], 400);
if ($issue === 'late' && preg_match(RV_FAULTY, $text) && !preg_match(RV_LATE, $text)) out(['error' => 'Your review is about a faulty or damaged product. Please pick Faulty or damaged product instead of Late delivery.'], 400);
if ($issue === 'faulty' && preg_match(RV_LATE, $text) && !preg_match(RV_FAULTY, $text)) out(['error' => 'Your review is about a late delivery. Please pick Late delivery instead of Faulty or damaged product.'], 400);

$anon = ($_POST['anon'] ?? '') === '1';
$real = rv_caps(rv_clean($_POST['name'] ?? '', 60));
$name = $anon ? 'Anonymous' : rv_display_name($real);
if (!$anon && mb_strlen($name) < 2) out(['error' => 'Please enter your name, or choose Post anonymously.'], 400);
/* optional city and country (country = 2-letter code from the list on the website, shown with its flag) */
$city = rv_caps(rv_clean(preg_replace('/[^\p{L}\p{M}\s\'\-.]/u', '', (string)($_POST['city'] ?? '')), 40));
$country = strtoupper((string)($_POST['country'] ?? ''));
if (!in_array($country, explode(' ', 'AD AE AF AG AI AL AM AO AQ AR AS AT AU AW AX AZ BA BB BD BE BF BG BH BI BJ BL BM BN BO BQ BR BS BT BV BW BY BZ CA CC CD CF CG CH CI CK CL CM CN CO CR CU CV CW CX CY CZ DE DJ DK DM DO DZ EC EE EG EH ER ES ET FI FJ FK FM FO FR GA GB GD GE GF GG GH GI GL GM GN GP GQ GR GS GT GU GW GY HK HM HN HR HT HU ID IE IL IM IN IO IQ IR IS IT JE JM JO JP KE KG KH KI KM KN KP KR KW KY KZ LA LB LC LI LK LR LS LT LU LV LY MA MC MD ME MF MG MH MK ML MM MN MO MP MQ MR MS MT MU MV MW MX MY MZ NA NC NE NF NG NI NL NO NP NR NU NZ OM PA PE PF PG PH PK PL PM PN PR PS PT PW PY QA RE RO RS RU RW SA SB SC SD SE SG SH SI SJ SK SL SM SN SO SR SS ST SV SX SY SZ TC TD TF TG TH TJ TK TL TM TN TO TR TT TV TW TZ UA UG UM US UY UZ VA VC VE VG VI VN VU WF WS YE YT ZA ZM ZW'), true)) $country = '';
if ($text === '') out(['error' => 'Please write your review.'], 400);

/* optional UAE mobile (reviews without an order link), needed with a problem for its WhatsApp coupon; saved as digits, only FOMAXO sees it */
$mobile = $link ? '' : substr(preg_replace('/\D/', '', strtr((string)($_POST['mobile'] ?? ''), FX_AR_DIGITS)), 0, 15);
if ($issue !== '' && $mobile === '') out(['error' => 'Please add your mobile number, so we can send your coupon on WhatsApp upon proof.'], 400);
if ($mobile !== '' && !rv_mob_ok($mobile)) out(['error' => 'Please enter a valid UAE mobile number, e.g. 050 123 4567.'], 400);
/* photos chosen (counted before saving): proof of a damaged or faulty product */
$photoCount = count(array_filter((array)($_FILES['photos']['error'] ?? []), fn($e) => $e === UPLOAD_ERR_OK));
if ($link && $rating <= 3 && preg_match(RV_FAULTY, $text) && !$photoCount) out(['error' => 'Sorry about that. Your review is about a damaged or faulty product, so please add a photo of it for proof. We will check it and send you a coupon on WhatsApp.'], 400);
if ($issue === 'faulty' && !$photoCount) out(['error' => 'Please add a photo of the faulty or damaged product as proof.'], 400);

/* no daily limit: a customer can review every product they bought, in one go */
$ip = rv_ip();

$id = bin2hex(random_bytes(8));
$photos = []; $photoNote = null;
$files = $_FILES['photos'] ?? null;
if ($files && is_array($files['tmp_name'])) {
  foreach ($files['tmp_name'] as $i => $tmp) {
    if (count($photos) >= $MAX_PHOTOS) break;
    if (($files['error'][$i] ?? 4) === UPLOAD_ERR_NO_FILE) continue;
    if (($files['error'][$i] ?? 1) !== UPLOAD_ERR_OK || ($files['size'][$i] ?? 0) > $MAX_PHOTO_MB * 1048576 || !is_uploaded_file($tmp)) { $photoNote = 'One photo could not be added.'; continue; }
    $fn = "$id-" . count($photos) . '.jpg';
    if (rv_save_photo($tmp, $fn, $PHOTO_PX)) $photos[] = $fn; else $photoNote = 'One photo could not be added.';
  }
}

$rec = ['id' => $id, 'product' => $pid, 'rating' => $rating, 'name' => $name, 'anon' => $anon, 'real' => $real, 'city' => $city, 'country' => $country, 'text' => $text,
        'verified' => (bool)$link, 'order' => $link['no'] ?? null, 'photos' => $photos, 'helpful' => 0, 'hidden' => false,
        'created' => date('c'), 'ip' => $ip, 'mobile' => $mobile, 'issue' => $issue];   // mobile and issue: for FOMAXO only, never in the public list
$saved = rv_change(function (&$list) use ($rec) { $list[] = $rec; return true; });
if (!$saved) { foreach ($photos as $p) @unlink(rv_dir('photos') . "/$p"); out(['error' => 'We could not save your review right now. Please try again.'], 500); }
if ($link) {
  $done = (array)($link['done'] ?? []); $done[$pid] = $id; $file = $link['file']; unset($link['file']);
  $link['done'] = $done; @file_put_contents($file, json_encode($link), LOCK_EX);
}

/* email FOMAXO so a review can be hidden quickly if needed */
$manage = rv_base() . 'reviews.php?manage&k=' . rv_sign('manage') . '#r-' . $id;
$host = preg_replace('/^www\./', '', preg_replace('/[^A-Za-z0-9.\-]/', '', explode(':', $_SERVER['HTTP_HOST'] ?? 'fomaxo.com')[0])) ?: 'fomaxo.com';
$pname = $CATALOG[$pid]['name'];
fomaxo_en_many([$text, $name, trim("$city $country")]);   // Arabic review: English in this email and in admin (the review on the site stays as written)
$body = "New review on fomaxo.com — it is live now.\n\nProduct: $pname\nRating: " . str_repeat('★', $rating) . str_repeat('☆', 5 - $rating) . " ($rating/5)\n"
      . 'Name shown: ' . fomaxo_en_both($name) . ($anon ? " (real name: $real)" : '') . "\n" . ($city . $country !== '' ? 'From: ' . fomaxo_en_both(trim("$city $country")) . "\n" : '') . ($link ? "Verified Purchaser — order {$link['no']}\n" : "Not a verified purchase\n")
      . ($issue !== '' ? 'Problem: ' . ($issue === 'late' ? 'Late delivery' : 'Faulty or damaged product') . "\n" : '') . ($mobile !== '' ? "Mobile: $mobile\n" : '')
      . 'Photos: ' . count($photos) . "\n\n" . (fx_has_ar($text) ? fomaxo_en($text) . "\n\nAs written: $text" : $text) . "\n\nTo hide this review (or any other), open:\n$manage\n";
fomaxo_mail(fomaxo_orders_email($STORE_EMAIL), '=?UTF-8?B?' . base64_encode("New $rating★ review — $pname") . '?=', $body, "From: FOMAXO Reviews <mail@fomaxo.com>\r\nContent-Type: text/plain; charset=UTF-8");

rv_send_then(['ok' => true, 'review' => rv_public($rec), 'note' => $photoNote]);
fomaxo_ar_many([$text], true, array_column($CATALOG, 'name'));   // ready in Arabic for the Arabic site
