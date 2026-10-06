<?php
/* FOMAXO — visit stats for fomaxo.com/admin → Analytics.
   The website sends one tiny message per step (page view, product view, add to bag, checkout, payment step, card page, purchase, how far down the homepage).
   Visitors are an anonymous random id from their browser; the first page of a visit also notes the country (and UAE emirate), never the IP address. Names and mobile numbers are only kept for a checkout
   where the customer typed them in, so you can see who left without buying. Bots and the admin are not counted. */
header('Cache-Control: no-store');
http_response_code(204);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') exit;
$ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 400);
if ($ua === '' || preg_match('/bot|crawl|spider|slurp|headless|lighthouse|pagespeed|preview|facebookexternalhit|whatsapp\/|curl|wget|python|monitor/i', $ua)) exit;
$raw = file_get_contents('php://input', false, null, 0, 4096);
$d = json_decode((string)$raw, true);
if (!is_array($d)) exit;
$id = fn($k) => preg_match('/^[a-z0-9]{8,16}$/', (string)($d[$k] ?? '')) ? (string)$d[$k] : null;
$vid = $id('v'); $sid = $id('s'); $ev = (string)($d['e'] ?? '');
if (!$vid || !$sid || !in_array($ev, ['view', 'product', 'cart', 'checkout', 'pay', 'card', 'buy', 'ping', 'lead', 'scroll'], true)) exit;
$t = fn($k, $max) => trim(mb_substr(preg_replace('/[\x00-\x1f]+/u', ' ', (string)($d[$k] ?? '')), 0, $max));

require __DIR__ . '/orders-lib.php';
$pdo = fomaxo_db();
if (!$pdo) exit;
date_default_timezone_set('Asia/Dubai');
try {
  $page = $t('p', 80);
  $pdo->prepare('REPLACE INTO fx_live (vid, seen, page) VALUES (?, NOW(), ?)')->execute([$vid, $page]);
  if ($ev === 'ping') {   // still on the page: note it on the latest page of this visit, for the time spent on each page
    try { $pdo->prepare("UPDATE fx_events SET left_at = NOW() WHERE sid = ? AND ev = 'view' ORDER BY id DESC LIMIT 1")->execute([$sid]); } catch (Throwable $e) {}
    exit;
  }

  if ($ev === 'lead') {   // checkout details, kept only once the customer typed a name or mobile
    $stages = ['details' => 1, 'payment' => 2, 'card' => 3];
    $stage = isset($stages[$d['st'] ?? '']) ? $d['st'] : 'details';
    $name = $t('name', 80); $phone = $t('phone', 25);
    if ($name === '' && $phone === '') exit;
    $args = [$sid, $vid, $stage, $name, $phone, $t('email', 120), $t('emirate', 30), $t('items', 600), is_numeric($d['total'] ?? null) ? round((float)$d['total'], 2) : null];
    $sql = fn($a, $b, $c) => "INSERT INTO fx_leads (sid, vid, created_at, updated_at, stage, name, phone, email, emirate, items, total$a)
                   VALUES (?, ?, NOW(), NOW(), ?, ?, ?, ?, ?, ?, ?$b)
                   ON DUPLICATE KEY UPDATE updated_at = NOW(), name = VALUES(name), phone = VALUES(phone), email = VALUES(email), emirate = VALUES(emirate),
                     items = VALUES(items), total = VALUES(total)$c,
                     stage = IF(FIELD(VALUES(stage), 'details', 'payment', 'card') > FIELD(stage, 'details', 'payment', 'card'), VALUES(stage), stage)";
    try { $pdo->prepare($sql(', address', ', ?', ', address = VALUES(address)'))->execute(array_merge($args, [$t('addr', 300)])); }
    catch (Throwable $e) { $pdo->prepare($sql('', '', ''))->execute($args); }   // before the address column exists
    exit;
  }

  $depth = null;
  if ($ev === 'scroll') { $depth = (int)($d['d'] ?? 0); if (!in_array($depth, [25, 50, 75, 100], true)) exit; }   // how far down the homepage
  $source = null; $ref = null; $country = null; $region = null; $campaign = null;
  if ($ev === 'view' && !empty($d['first'])) {   // the first page of a visit says where it came from
    $ref = $t('r', 300);
    $source = fomaxo_source($ref, strtolower($t('u', 40)), $ua);
    $campaign = mb_strtolower($t('c', 60)) ?: null;   // the utm_campaign name in the link, e.g. ?utm_source=instagram&utm_campaign=eid-post
    $ref = mb_substr((string)parse_url($ref, PHP_URL_HOST), 0, 120) ?: null;
    require_once __DIR__ . '/geo-lib.php';   // country and UAE emirate of the visit; the IP address itself is not kept
    [$country, $region] = fomaxo_geo(fomaxo_geo_ip());
  }
  $device = preg_match('/iPad|Tablet|(Android(?!.*Mobile))/i', $ua) ? 'tablet' : (preg_match('/Mobi|iPhone|Android/i', $ua) ? 'phone' : 'computer');
  $product = $ev === 'product' || $ev === 'cart' ? (preg_match('/^[a-z0-9-]{1,40}$/', (string)($d['id'] ?? '')) ? $d['id'] : null) : null;
  try {
    $pdo->prepare('INSERT INTO fx_events (at, vid, sid, ev, page, product, source, ref, device, country, region, campaign, depth) VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$vid, $sid, $ev, $page, $product, $source, $ref, $device, $country, $region, $campaign, $depth]);
  } catch (Throwable $e) { if ($ev === 'scroll') exit; try {   // before the campaign column exists
    $pdo->prepare('INSERT INTO fx_events (at, vid, sid, ev, page, product, source, ref, device, country, region) VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$vid, $sid, $ev, $page, $product, $source, $ref, $device, $country, $region]);
  } catch (Throwable $e) {   // before the country columns exist: keep counting the visit without them
    $pdo->prepare('INSERT INTO fx_events (at, vid, sid, ev, page, product, source, ref, device) VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$vid, $sid, $ev, $page, $product, $source, $ref, $device]);
  } }
  if ($ev === 'buy' && ($no = $t('o', 40)) !== '') $pdo->prepare('UPDATE fx_leads SET order_no = ? WHERE sid = ?')->execute([$no, $sid]);
  if (mt_rand(1, 500) === 1) {   // keep the tables small: 400 days of steps, one day of "on the website now" (checkout details are kept for good)
    $pdo->exec('DELETE FROM fx_events WHERE at < NOW() - INTERVAL 400 DAY');
    $pdo->exec('DELETE FROM fx_live WHERE seen < NOW() - INTERVAL 1 DAY');
  }
} catch (Throwable $e) { error_log('FOMAXO track: ' . $e->getMessage()); }
