<?php
/* FOMAXO — private back office: fomaxo.com/admin
   Lists every order (cash on delivery and card), with details, status and a download for Excel.
   First visit: a set-up form connects the MySQL database and sets the admin password.
   Nothing secret is kept in this file or on GitHub: the database login is saved in fomaxo-db-config.php
   one level above public_html, and the admin password only as a hash inside the database. */
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; script-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'");
require dirname(__DIR__) . '/orders-lib.php';
date_default_timezone_set('Asia/Dubai');

$STORE_EMAIL = 'fomaxoasset@gmail.com';   // set-up and password reset links are sent here
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_name('fxadmin');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function money($v) { return 'AED ' . number_format((float)$v, 2); }
function self_url($q = []) { return './' . ($q ? '?' . http_build_query($q) : ''); }
function csrf_ok() { return hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? '')); }
function client_ip() { return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45); }
function go($q = []) { header('Location: ' . self_url($q), true, 303); exit; }

function page($title, $body, $wide = false) {
  $css = <<<'CSS'
:root{--bg:#f7f3ec;--panel:#fff;--ink:#16130f;--muted:#5d554a;--line:#e3d9c8;--gold:#8f6b37;--gold-ink:#fff;--ok:#2f6b3a;--warn:#9a5b12;--bad:#9b2c2c}
@media (prefers-color-scheme:dark){:root{--bg:#050505;--panel:#0e0d0b;--ink:#f2ebdd;--muted:#b5aa98;--line:#2a251e;--gold:#d7aa69;--gold-ink:#000;--ok:#7fc48b;--warn:#e0a85a;--bad:#ef8a8a}}
*{box-sizing:border-box}html{color-scheme:light dark}
body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.5 Jost,system-ui,sans-serif;font-style:normal}
em,i{font-style:normal}
a{color:var(--gold)}
.top{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 16px;border-bottom:1px solid var(--line);background:var(--panel)}
.brand{font-family:"Cormorant Garamond",serif;font-size:22px;letter-spacing:.18em;color:var(--gold);text-decoration:none}
.brand small{font-family:Jost,sans-serif;font-size:11px;letter-spacing:.3em;color:var(--muted);margin-left:8px}
.wrap{max-width:1180px;margin:0 auto;padding:20px 16px 60px}
.narrow{max-width:420px}
h1{font-family:"Cormorant Garamond",serif;font-weight:500;font-size:28px;margin:6px 0 16px}
h2{font-family:"Cormorant Garamond",serif;font-weight:500;font-size:21px;margin:22px 0 10px}
.card{background:var(--panel);border:1px solid var(--line);border-radius:10px;padding:18px}
label{display:block;font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);margin:12px 0 5px}
input,select,textarea{width:100%;font:inherit;color:var(--ink);background:var(--bg);border:1px solid var(--line);border-radius:7px;padding:10px 11px}
textarea{min-height:80px}
.btn{display:inline-block;font:inherit;font-size:13px;letter-spacing:.12em;text-transform:uppercase;background:var(--gold);color:var(--gold-ink);border:0;border-radius:7px;padding:11px 18px;cursor:pointer;text-decoration:none;white-space:nowrap}
.btn.line{background:transparent;color:var(--gold);box-shadow:inset 0 0 0 1px var(--gold)}
.msg{padding:10px 12px;border-radius:7px;margin:0 0 14px;border:1px solid var(--line)}
.msg.bad{color:var(--bad);border-color:var(--bad)}.msg.ok{color:var(--ok);border-color:var(--ok)}
.muted{color:var(--muted)}.small{font-size:13px}
.filters{display:grid;grid-template-columns:1fr 1fr;gap:10px;align-items:end}
.filters label{margin-top:0}
.filters>div{min-width:0}
.filters .q{grid-column:1/-1}
.filters .acts{grid-column:1/-1;display:flex;gap:8px;flex-wrap:wrap}
@media (min-width:860px){.filters{grid-template-columns:2fr 1fr 1fr 1fr 1fr auto}.filters .q,.filters .acts{grid-column:auto}}
.stats{display:flex;gap:10px;flex-wrap:wrap;margin:16px 0}
.stat{flex:1 1 140px;background:var(--panel);border:1px solid var(--line);border-radius:10px;padding:12px 14px}
.stat b{display:block;font-size:20px;font-weight:500}
.stat span{font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:var(--muted)}
table{width:100%;border-collapse:collapse;background:var(--panel);border:1px solid var(--line);border-radius:10px;overflow:hidden}
th,td{text-align:left;padding:10px 12px;border-bottom:1px solid var(--line);vertical-align:top}
th{font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);font-weight:500}
td.num,th.num{text-align:right;white-space:nowrap}
tr.row{cursor:pointer}table:not(.stock):not(.exp) tr.row:hover td{background:rgba(143,107,55,.08)}.stock tr.row,.exp tr.row{cursor:default}
.no{font-weight:500;white-space:nowrap}
.tag{display:inline-block;font-size:12px;padding:2px 9px;border-radius:20px;border:1px solid currentColor;white-space:nowrap}
.s-New{color:var(--warn)}.s-Paid{color:var(--gold)}.s-Delivered{color:var(--ok)}.s-Cancelled{color:var(--bad)}.s-Awaiting{color:var(--muted)}
@media (max-width:759px){
  table,tbody,tr,td{display:block;border:0}thead{display:none}
  table{background:none;border:0}
  tr.row{background:var(--panel);border:1px solid var(--line);border-radius:10px;margin:0 0 10px;padding:8px 4px}
  td{padding:3px 12px}td.num{text-align:left}
  td[data-l]::before{content:attr(data-l) " ";color:var(--muted);font-size:12px;text-transform:uppercase;letter-spacing:.06em}
}
.grid2{display:grid;gap:14px}@media (min-width:860px){.grid2{grid-template-columns:3fr 2fr}}
dl{display:grid;grid-template-columns:120px 1fr;gap:6px 12px;margin:0}dt{color:var(--muted);font-size:13px}dd{margin:0;word-break:break-word}
.items{margin:0;padding-left:18px}
.pager{display:flex;gap:8px;margin-top:14px}
.btn.sm{padding:8px 12px;font-size:11px}.btn.big{padding:15px 26px;font-size:14px;min-width:200px}
.tabs{display:grid;grid-template-columns:repeat(4,1fr);background:var(--panel);border-bottom:1px solid var(--line)}
.tabs a{text-align:center;text-decoration:none;font-size:13px;letter-spacing:.1em;text-transform:uppercase;padding:14px 4px;color:var(--muted);border-bottom:2px solid transparent}
.tabs a.on{color:var(--gold);border-bottom-color:var(--gold)}
@media (min-width:760px){.tabs{display:flex;justify-content:center;gap:10px}.tabs a{padding:14px 22px}}
.lvl-out{color:var(--bad)}.lvl-low{color:var(--warn)}.lvl-ok{color:var(--ok)}
.stock td,.exp td{vertical-align:middle}.stock input{width:110px;text-align:right}
label.mini{display:none}
.savebar{position:sticky;bottom:0;padding:12px 0;background:linear-gradient(transparent,var(--bg) 30%)}
.g3{display:grid;gap:0 12px}@media (min-width:760px){.g3{grid-template-columns:1fr 1fr 1fr}}
.monthnav{display:flex;align-items:center;justify-content:space-between;gap:10px;margin:22px 0 10px}.monthnav h2{margin:0;text-align:center}.monthnav h2 small{display:block;font-family:Jost,sans-serif;font-size:15px;color:var(--muted)}
.yearbar{display:flex;align-items:center;gap:10px;margin:6px 0 4px;flex-wrap:wrap}.yearbar label{margin:0}.yearbar select{width:auto}
.rep tr.dim td{color:var(--muted)}.rep tr.tot td{font-weight:500;border-top:2px solid var(--line)}.rep td.per{white-space:nowrap}
@media (max-width:759px){
  .stock tr.row{display:grid;grid-template-columns:1fr 1fr;gap:6px 10px;cursor:default;padding:10px 4px}.stock tr.row td:first-child{grid-column:1/-1}
  .stock input{width:100%}label.mini{display:block;margin:0 0 3px}
  .exp tr.row{display:grid;grid-template-columns:1fr auto auto;align-items:center;cursor:default}
  .rep tr{background:var(--panel);border:1px solid var(--line);border-radius:10px;margin:0 0 10px;padding:8px 4px}
  .rep td.per{font-weight:500;padding-bottom:6px}.rep td.num{display:flex;justify-content:space-between;text-align:right}
  .rep td.num::before{content:attr(data-l);color:var(--muted);font-size:12px;text-transform:uppercase;letter-spacing:.06em}
  .rep tr.dim{display:none}.rep tr.tot{border-color:var(--gold)}
}
CSS;
  echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">'
     . '<title>' . h($title) . ' — FOMAXO Admin</title>'
     . '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500&family=Jost:wght@400;500&display=swap">'
     . "<style>$css</style></head><body>"
     . '<header class="top"><a class="brand" href="./">FOMAXO<small>ADMIN</small></a>'
     . (!empty($_SESSION['admin']) ? '<form method="post" action="./?logout=1" style="margin:0"><input type="hidden" name="csrf" value="' . h($_SESSION['csrf']) . '"><button class="btn line sm">Log out</button></form>' : '')
     . '</header>'
     . (!empty($_SESSION['admin']) ? '<nav class="tabs">' . implode('', array_map(fn($t) => '<a href="' . $t[1] . '"' . ($t[2] ? ' class="on"' : '') . '>' . $t[0] . '</a>',
         [['Orders', './', !array_intersect_key($_GET, ['stock' => 1, 'expenses' => 1, 'reports' => 1])], ['Stock', './?stock=1', isset($_GET['stock'])],
          ['Expenses', './?expenses=1', isset($_GET['expenses'])], ['Reports', './?reports=1', isset($_GET['reports'])]])) . '</nav>' : '')
     . '<main class="wrap' . ($wide ? '' : ' narrow') . '">' . $body . '</main></body></html>';
  exit;
}
function flash($m = null, $ok = false) { if ($m !== null) { $_SESSION['flash'] = [$m, $ok]; return ''; } $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $f ? '<p class="msg ' . ($f[1] ? 'ok' : 'bad') . '">' . h($f[0]) . '</p>' : ''; }
function csrf_field() { return '<input type="hidden" name="csrf" value="' . h($_SESSION['csrf']) . '">'; }

/* ================= 1) first set-up: connect the database ================= */
$cfgFile = fomaxo_db_config_file();
if (!is_file($cfgFile)) {
  $err = '';
  $v = ['name' => 'u934663824_fomaxo', 'user' => 'u934663824_fomaxo'];
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {
    $v = ['name' => trim((string)($_POST['name'] ?? '')), 'user' => trim((string)($_POST['user'] ?? ''))];
    $dbPass = (string)($_POST['dbpass'] ?? ''); $p1 = (string)($_POST['pw1'] ?? ''); $p2 = (string)($_POST['pw2'] ?? '');
    $cfg = ['host' => '127.0.0.1', 'port' => 3306, 'name' => $v['name'], 'user' => $v['user'], 'pass' => $dbPass];
    if (strlen($p1) < 8) $err = 'Choose an admin password of at least 8 characters.';
    elseif ($p1 !== $p2) $err = 'The two admin passwords are not the same.';
    else {
      try {
        try { $pdo = fomaxo_db_connect($cfg); } catch (Throwable $e) { $cfg['host'] = 'localhost'; $pdo = fomaxo_db_connect($cfg); }
        fomaxo_db_schema($pdo);
        $php = "<?php\n/* FOMAXO order database login. Written by fomaxo.com/admin set-up. Keep this file private (not in public_html, not on GitHub). */\nreturn " . var_export($cfg, true) . ";\n";
        fomaxo_setting($pdo, 'admin_hash', password_hash($p1, PASSWORD_DEFAULT));
        if (@file_put_contents($cfgFile, $php, LOCK_EX) === false) $err = 'The database works, but the settings file could not be saved next to public_html. Please contact your developer.';
        else {
          @chmod($cfgFile, 0600);
          session_regenerate_id(true); $_SESSION['admin'] = 1;
          flash('All set. Your orders are now saved in the database.', true); go();
        }
      } catch (Throwable $e) { sleep(2); $err = 'Could not connect to the database. Check the database name, user and password in hPanel → Databases.'; }
    }
  }
  page('Set up', '<h1>Set up your back office</h1>'
    . ($err ? '<p class="msg bad">' . h($err) . '</p>' : '')
    . '<form class="card" method="post" autocomplete="off">' . csrf_field()
    . '<p class="muted small" style="margin-top:0">One time only. Enter the MySQL database details from hPanel → Databases, then choose the password you will use to open this page.</p>'
    . '<label for="name">Database name</label><input id="name" name="name" value="' . h($v['name']) . '" required>'
    . '<label for="user">Database user</label><input id="user" name="user" value="' . h($v['user']) . '" required>'
    . '<label for="dbpass">Database password</label><input id="dbpass" name="dbpass" type="password" required>'
    . '<label for="pw1">New admin password</label><input id="pw1" name="pw1" type="password" minlength="8" required autocomplete="new-password">'
    . '<label for="pw2">Admin password again</label><input id="pw2" name="pw2" type="password" minlength="8" required autocomplete="new-password">'
    . '<p style="margin:18px 0 0"><button class="btn">Connect and save</button></p></form>');
}

$pdo = fomaxo_db();
if (!$pdo) page('Database', '<h1>Database not reachable</h1><p class="msg bad">The order database could not be opened right now. Orders are still being emailed and saved as files. Try again in a few minutes.</p>');

/* ================= 2) password: log in, log out, reset by email ================= */
if (isset($_GET['logout']) && $_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) { $_SESSION = []; session_destroy(); header('Location: ./', true, 303); exit; }

if (isset($_GET['reset'])) {   // link from the email: choose a new password
  $tok = (string)$_GET['reset'];
  $saved = json_decode((string)fomaxo_setting($pdo, 'reset_token'), true);
  $valid = $saved && $tok !== '' && hash_equals($saved['hash'], hash('sha256', $tok)) && $saved['until'] > time();
  if (!$valid) page('Link expired', '<h1>Link expired</h1><p class="card">This link has expired or was already used. <a href="./?forgot=1">Send a new one</a>.</p>');
  $err = '';
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {
    $p1 = (string)($_POST['pw1'] ?? ''); $p2 = (string)($_POST['pw2'] ?? '');
    if (strlen($p1) < 8) $err = 'Choose a password of at least 8 characters.';
    elseif ($p1 !== $p2) $err = 'The two passwords are not the same.';
    else {
      fomaxo_setting($pdo, 'admin_hash', password_hash($p1, PASSWORD_DEFAULT));
      fomaxo_setting($pdo, 'reset_token', '');
      session_regenerate_id(true); $_SESSION['admin'] = 1;
      flash('Your new password is saved.', true); go();
    }
  }
  page('New password', '<h1>Choose a new password</h1>' . ($err ? '<p class="msg bad">' . h($err) . '</p>' : '')
    . '<form class="card" method="post">' . csrf_field()
    . '<label for="pw1">New password</label><input id="pw1" name="pw1" type="password" minlength="8" required autocomplete="new-password">'
    . '<label for="pw2">Password again</label><input id="pw2" name="pw2" type="password" minlength="8" required autocomplete="new-password">'
    . '<p style="margin:18px 0 0"><button class="btn">Save password</button></p></form>');
}

if (isset($_GET['forgot'])) {   // emails a one-hour link to the store inbox (never to anyone else)
  $sent = false;
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {
    $last = json_decode((string)fomaxo_setting($pdo, 'reset_token'), true);
    if (!$last || ($last['sent'] ?? 0) < time() - 120) {
      $tok = bin2hex(random_bytes(24));
      fomaxo_setting($pdo, 'reset_token', json_encode(['hash' => hash('sha256', $tok), 'until' => time() + 3600, 'sent' => time()]));
      $host = preg_replace('/[^A-Za-z0-9.\-:]/', '', $_SERVER['HTTP_HOST'] ?? 'fomaxo.com');
      $link = ($https ? 'https' : 'http') . "://$host" . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/admin/index.php'), '/') . "/?reset=$tok";
      $mailHost = preg_replace('/^www\./', '', explode(':', $host)[0]);
      @mail($STORE_EMAIL, 'FOMAXO admin password link', "Someone asked to set the password for the FOMAXO back office.\n\nOpen this link within one hour to choose a new password:\n$link\n\nIf this was not you, ignore this email. Your password stays the same.",
            "From: FOMAXO <orders@$mailHost>\r\nContent-Type: text/plain; charset=UTF-8");
    }
    $sent = true;
  }
  page('Forgot password', '<h1>Forgot password</h1>'
    . ($sent ? '<p class="msg ok">A link has been sent to the store email. It works for one hour.</p>'
             : '<form class="card" method="post">' . csrf_field() . '<p style="margin-top:0">We will email a link to set a new password to the store inbox (' . h(preg_replace('/(?<=.).(?=[^@]*@)/', '•', $STORE_EMAIL)) . ').</p><button class="btn">Email me a link</button></form>')
    . '<p class="small"><a href="./">Back to log in</a></p>');
}

if (empty($_SESSION['admin'])) {
  $hash = fomaxo_setting($pdo, 'admin_hash');
  if (!$hash) page('Set password', '<h1>Set your password</h1><p class="card">No admin password is set yet. <a href="./?forgot=1">Email a link to the store inbox</a> to choose one.</p>');
  $err = '';
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {
    $pdo->prepare('DELETE FROM fx_login WHERE at < NOW() - INTERVAL 1 DAY')->execute();
    $s = $pdo->prepare('SELECT COUNT(*) FROM fx_login WHERE ip = ? AND at > NOW() - INTERVAL 15 MINUTE'); $s->execute([client_ip()]);
    if ($s->fetchColumn() >= 8) $err = 'Too many tries. Please wait 15 minutes, or use Forgot password.';
    elseif (password_verify((string)($_POST['pw'] ?? ''), $hash)) {
      if (password_needs_rehash($hash, PASSWORD_DEFAULT)) fomaxo_setting($pdo, 'admin_hash', password_hash((string)$_POST['pw'], PASSWORD_DEFAULT));
      session_regenerate_id(true); $_SESSION['admin'] = 1; go();
    } else {
      $pdo->prepare('INSERT INTO fx_login (ip, at) VALUES (?, NOW())')->execute([client_ip()]);
      sleep(1); $err = 'Wrong password.';
    }
  }
  page('Log in', '<h1>Back office</h1>' . flash() . ($err ? '<p class="msg bad">' . h($err) . '</p>' : '')
    . '<form class="card" method="post">' . csrf_field()
    . '<label for="pw" style="margin-top:0">Password</label><input id="pw" name="pw" type="password" required autofocus autocomplete="current-password">'
    . '<p style="margin:18px 0 0"><button class="btn">Log in</button></p></form>'
    . '<p class="small"><a href="./?forgot=1">Forgot password?</a></p>');
}

/* ================= 3) logged in: orders ================= */

/* save a change to one order */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order'])) {
  if (!csrf_ok()) { flash('Please try again.'); go(['o' => $_POST['order']]); }
  $st = (string)($_POST['status'] ?? '');
  if (!in_array($st, FX_STATUSES, true)) { flash('Unknown status.'); go(['o' => $_POST['order']]); }
  $s = $pdo->prepare('SELECT status FROM fx_orders WHERE order_no = ?'); $s->execute([(string)$_POST['order']]); $was = $s->fetchColumn();
  $pdo->prepare("UPDATE fx_orders SET status = ?, admin_note = ?, updated_at = NOW(),
                 paid_at = CASE WHEN ? IN ('Paid', 'Delivered') AND paid_at IS NULL THEN NOW() ELSE paid_at END WHERE order_no = ?")
      ->execute([$st, mb_substr(trim((string)($_POST['admin_note'] ?? '')), 0, 2000), $st, (string)$_POST['order']]);
  /* stock: a cancelled order goes back into stock; taking it out of Cancelled takes it out again */
  $msg = 'Saved.';
  if ($st === 'Cancelled' && $was !== 'Cancelled' && fomaxo_stock_move((string)$_POST['order'], true)) $msg = 'Saved. The items are back in stock.';
  if (in_array($st, ['New', 'Paid', 'Delivered'], true) && in_array($was, ['Cancelled', 'Awaiting payment'], true) && fomaxo_stock_move((string)$_POST['order'])) $msg = 'Saved. The items were taken out of stock.';
  flash($msg, true); go(['o' => $_POST['order']]);
}

/* ---- stock and cost price: one row per product and size ---- */
if (isset($_GET['stock'])) {
  require_once dirname(__DIR__) . '/store-lib.php';
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) { flash('Please try again.'); go(['stock' => 1]); }
    $set = $pdo->prepare('INSERT INTO fx_stock (product, size, qty, cost, updated_at) VALUES (?, ?, ?, ?, NOW())
                          ON DUPLICATE KEY UPDATE qty = VALUES(qty), cost = VALUES(cost), updated_at = NOW()');
    $old = []; foreach ($pdo->query('SELECT product, size, qty, cost FROM fx_stock') as $r) $old[$r['product'] . '|' . $r['size']] = [$r['qty'] === null ? null : (int)$r['qty'], $r['cost'] === null ? null : round((float)$r['cost'], 2)];
    foreach ($CATALOG as $id => $p) foreach (array_keys($p['prices']) as $opt) {
      $v = trim((string)($_POST['q'][$id][$opt] ?? '')); $c = trim(str_replace(',', '.', (string)($_POST['c'][$id][$opt] ?? '')));
      $q = $v === '' ? null : max(0, min(100000, (int)$v));
      $c = $c === '' || !is_numeric($c) ? null : round(max(0, min(1000000, (float)$c)), 2);
      $k = "$id|$opt";
      if (($old[$k] ?? [null, null]) !== [$q, $c]) $set->execute([$id, (string)$opt, $q, $c]);
    }
    flash('Saved.', true); go(['stock' => 1]);
  }
  $have = []; $cost = [];
  foreach ($pdo->query('SELECT product, size, qty, cost FROM fx_stock') as $r) { $have[$r['product'] . '|' . $r['size']] = $r['qty']; $cost[$r['product'] . '|' . $r['size']] = $r['cost']; }
  $sold = [];   // sold in the last 30 days (orders that are not cancelled), to help decide when to restock
  $s = $pdo->query("SELECT lines_json FROM fx_orders WHERE stock_taken = 1 AND created_at > NOW() - INTERVAL 30 DAY");
  foreach ($s as $r) foreach (fomaxo_stock_lines(json_decode((string)$r['lines_json'], true) ?: []) as $k => $n) $sold[$k] = ($sold[$k] ?? 0) + $n;
  $tr = '';
  foreach ($CATALOG as $id => $p) foreach (array_keys($p['prices']) as $opt) {
    $k = "$id|$opt"; $q = $have[$k] ?? null; $c = $cost[$k] ?? null;
    $lvl = $q === null ? '<span class="muted">Not counted</span>' : ((int)$q <= 0 ? '<span class="lvl-out">Sold out</span>' : ((int)$q <= FX_LOW_STOCK ? '<span class="lvl-low">Only ' . (int)$q . ' left</span>' : '<span class="lvl-ok">In stock</span>'));
    $margin = ' · <span class="muted">sells at AED ' . h(number_format($p['prices'][$opt], 0)) . '</span>';
    $tr .= '<tr class="row"><td><b>' . h($p['name']) . '</b> <span class="muted">' . h($p['kind'] === 'set' ? "Set of $opt" : "{$opt}ml") . '</span>'
         . '<div class="small">' . $lvl . $margin . (!empty($sold[$k]) ? ' <span class="muted">· ' . (int)$sold[$k] . ' sold in 30 days</span>' : '') . '</div></td>'
         . '<td class="num"><label class="mini">Stock</label><input type="number" min="0" inputmode="numeric" name="q[' . h($id) . '][' . h($opt) . ']" value="' . ($q === null ? '' : (int)$q) . '" placeholder="—" aria-label="' . h($p['name'] . ' ' . $opt) . ' stock"></td>'
         . '<td class="num"><label class="mini">Cost AED</label><input type="number" min="0" step="0.01" inputmode="decimal" name="c[' . h($id) . '][' . h($opt) . ']" value="' . ($c === null ? '' : h(rtrim(rtrim($c, '0'), '.'))) . '" placeholder="—" aria-label="' . h($p['name'] . ' ' . $opt) . ' cost price"></td></tr>';
  }
  page('Stock', '<h1>Stock &amp; cost</h1>' . flash()
    . '<p class="muted small"><b>Stock:</b> how many bottles you have. It goes down by itself with every cash order and every paid card order (the free mini counts as one 10ml) and goes back up if you cancel an order. Leave it empty to not count that size. The website shows "Only X left" at ' . FX_LOW_STOCK . ' or fewer, and "Sold out" at 0.<br>'
    . '<b>Cost:</b> what one bottle costs you. Reports use it to work out your profit.</p>'
    . '<form method="post">' . csrf_field() . '<table class="stock"><thead><tr><th>Product</th><th class="num">Stock</th><th class="num">Cost (AED)</th></tr></thead><tbody>' . $tr . '</tbody></table>'
    . '<div class="savebar"><button class="btn big">Save</button></div></form>', true);
}

/* ---- expenses: money going out that is not a bottle sold (ads, delivery, packaging, rent …) ---- */
if (isset($_GET['expenses'])) {
  $month = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['m'] ?? '')) ? $_GET['m'] : date('Y-m');
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) { flash('Please try again.'); go(['expenses' => 1, 'm' => $month]); }
    if (isset($_POST['delete'])) {
      $pdo->prepare('DELETE FROM fx_expenses WHERE id = ?')->execute([(int)$_POST['delete']]);
      flash('Expense deleted.', true); go(['expenses' => 1, 'm' => $month]);
    }
    $day = (string)($_POST['day'] ?? ''); $cat = (string)($_POST['category'] ?? ''); $amt = (float)str_replace(',', '.', (string)($_POST['amount'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || !in_array($cat, FX_EXPENSE_TYPES, true) || $amt <= 0) { flash('Please fill in the date, type and amount.'); go(['expenses' => 1, 'm' => $month]); }
    $pdo->prepare('INSERT INTO fx_expenses (day, category, amount, note, created_at) VALUES (?, ?, ?, ?, NOW())')->execute([$day, $cat, round($amt, 2), mb_substr(trim((string)($_POST['note'] ?? '')), 0, 200)]);
    flash('Expense added.', true); go(['expenses' => 1, 'm' => substr($day, 0, 7)]);
  }
  $s = $pdo->prepare('SELECT * FROM fx_expenses WHERE day >= ? AND day < ? ORDER BY day DESC, id DESC');
  $s->execute(["$month-01", date('Y-m-d', strtotime("$month-01 +1 month"))]); $list = $s->fetchAll();
  $sum = array_sum(array_map(fn($e) => (float)$e['amount'], $list));
  $opts = implode('', array_map(fn($c) => '<option>' . h($c) . '</option>', FX_EXPENSE_TYPES));
  $tr = '';
  foreach ($list as $e) $tr .= '<tr class="row"><td><b>' . h($e['category']) . '</b><div class="muted small">' . h(date('d M', strtotime($e['day']))) . ($e['note'] !== '' ? ' · ' . h($e['note']) : '') . '</div></td>'
    . '<td class="num">' . money($e['amount']) . '</td><td class="num"><form method="post" style="margin:0" onsubmit="return confirm(\'Delete this expense?\')">' . csrf_field()
    . '<input type="hidden" name="delete" value="' . (int)$e['id'] . '"><button class="btn line sm">Delete</button></form></td></tr>';
  $prev = date('Y-m', strtotime("$month-01 -1 month")); $next = date('Y-m', strtotime("$month-01 +1 month"));
  page('Expenses', '<h1>Expenses</h1>' . flash()
    . '<form class="card add" method="post">' . csrf_field() . '<h2 style="margin-top:0">Add an expense</h2>'
    . '<div class="g3"><div><label for="day">Date</label><input id="day" type="date" name="day" value="' . h(date('Y-m-d')) . '" required></div>'
    . '<div><label for="category">Type</label><select id="category" name="category">' . $opts . '</select></div>'
    . '<div><label for="amount">Amount (AED)</label><input id="amount" type="number" min="0.01" step="0.01" inputmode="decimal" name="amount" required></div></div>'
    . '<label for="note">Note (optional)</label><input id="note" name="note" maxlength="200" placeholder="e.g. Instagram ads, Aramex invoice">'
    . '<p style="margin:16px 0 0"><button class="btn big">Add expense</button></p></form>'
    . '<div class="monthnav"><a class="btn line" href="' . h(self_url(['expenses' => 1, 'm' => $prev])) . '">←</a><h2>' . h(date('F Y', strtotime("$month-01"))) . '<small>' . money($sum) . '</small></h2><a class="btn line" href="' . h(self_url(['expenses' => 1, 'm' => $next])) . '">→</a></div>'
    . ($list ? '<table class="exp"><tbody>' . $tr . '</tbody></table>' : '<p class="card muted">No expenses this month.</p>')
    . '<p class="muted small">"Stock purchase" is shown in reports but not taken off profit, because the cost of each bottle is already counted when it sells.</p>', true);
}

/* ---- reports: sales, costs and profit or loss by month and by year ---- */
if (isset($_GET['reports'])) {
  $years = $pdo->query("SELECT DISTINCT YEAR(created_at) y FROM fx_orders UNION SELECT DISTINCT YEAR(day) FROM fx_expenses ORDER BY y DESC")->fetchAll(PDO::FETCH_COLUMN);
  if (!in_array((int)date('Y'), array_map('intval', $years), true)) array_unshift($years, date('Y'));
  $year = (int)($_GET['y'] ?? date('Y')); if ($year < 2000 || $year > 2100) $year = (int)date('Y');
  $rows = fomaxo_report($pdo, $year);
  $all = fomaxo_report($pdo, null);
  $cols = ['orders' => 'Orders', 'sales' => 'Sales', 'cogs' => 'Cost of goods', 'expenses' => 'Expenses', 'profit' => 'Profit / loss', 'discount' => 'Discounts given', 'fees' => 'COD fees in sales', 'stock_bought' => 'Stock bought'];
  $tot = fn($rs) => array_reduce($rs, function ($a, $r) { foreach ($r as $k => $v) $a[$k] = ($a[$k] ?? 0) + $v; return $a; }, []);
  $T = $tot($rows); $missing = (int)($T['no_cost'] ?? 0);

  if (isset($_GET['export'])) {   // Excel: the months of this year, the year total, then every year
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="fomaxo-profit-and-loss-' . $year . '.csv"');
    $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, array_merge(['Period'], array_map(fn($c) => $c === 'Orders' ? $c : "$c (AED)", array_values($cols))));
    $line = fn($label, $r) => fputcsv($out, array_merge([$label], array_map(fn($k) => $k === 'orders' ? (int)($r[$k] ?? 0) : number_format((float)($r[$k] ?? 0), 2, '.', ''), array_keys($cols))));
    foreach ($rows as $k => $r) $line(date('F Y', strtotime("$k-01")), $r);
    $line("Total $year", $T);
    fputcsv($out, []);
    foreach ($all as $k => $r) $line("Year $k", $r);
    exit;
  }

  $cell = fn($k, $v) => $k === 'orders' ? (int)$v : ($k === 'profit' ? '<b class="' . ($v < 0 ? 'lvl-out' : 'lvl-ok') . '">' . ($v < 0 ? '−' : '') . money(abs($v)) . '</b>' : money($v));
  $main = ['orders', 'sales', 'cogs', 'expenses', 'profit'];
  $table = function ($rs, $labelFn, $total = null) use ($cols, $main, $cell) {
    $h = '<table class="rep"><thead><tr><th></th>' . implode('', array_map(fn($k) => '<th class="num">' . h($cols[$k]) . '</th>', $main)) . '</tr></thead><tbody>';
    foreach ($rs as $k => $r) { $empty = !$r['orders'] && !$r['expenses'] && !$r['stock_bought'];
      $h .= '<tr class="' . ($empty ? 'dim' : '') . '"><td class="per">' . h($labelFn($k)) . '</td>' . implode('', array_map(fn($c) => '<td class="num" data-l="' . h($cols[$c]) . '">' . $cell($c, $r[$c]) . '</td>', $main)) . '</tr>'; }
    if ($total) $h .= '<tr class="tot"><td class="per">' . h($total[0]) . '</td>' . implode('', array_map(fn($c) => '<td class="num" data-l="' . h($cols[$c]) . '">' . $cell($c, $total[1][$c] ?? 0) . '</td>', $main)) . '</tr>';
    return $h . '</tbody></table>';
  };
  $thisM = $rows[date('Y-m')] ?? null;
  $yOpts = implode('', array_map(fn($y) => '<option' . ((int)$y === $year ? ' selected' : '') . '>' . (int)$y . '</option>', $years));
  page('Reports', '<h1>Profit &amp; loss</h1>'
    . ($thisM ? '<div class="stats"><div class="stat"><b>' . money($thisM['sales']) . '</b><span>Sales this month</span></div><div class="stat"><b class="' . ($thisM['profit'] < 0 ? 'lvl-out' : 'lvl-ok') . '">' . ($thisM['profit'] < 0 ? '−' : '') . money(abs($thisM['profit'])) . '</b><span>Profit this month</span></div>'
             . '<div class="stat"><b>' . money($T['sales'] ?? 0) . '</b><span>Sales ' . $year . '</span></div><div class="stat"><b class="' . (($T['profit'] ?? 0) < 0 ? 'lvl-out' : 'lvl-ok') . '">' . (($T['profit'] ?? 0) < 0 ? '−' : '') . money(abs($T['profit'] ?? 0)) . '</b><span>Profit ' . $year . '</span></div></div>' : '')
    . '<form class="yearbar" method="get"><input type="hidden" name="reports" value="1"><label for="y">Year</label><select id="y" name="y" onchange="this.form.submit()">' . $yOpts . '</select>'
    . '<a class="btn line" href="' . h(self_url(['reports' => 1, 'y' => $year, 'export' => 1])) . '">Download Excel</a></form>'
    . ($missing ? '<p class="msg bad">' . $missing . ' order' . ($missing > 1 ? 's' : '') . ' in ' . $year . ' ha' . ($missing > 1 ? 've' : 's') . ' no cost price, so profit is too high. Type the cost of each bottle on the <a href="./?stock=1">Stock</a> page. Orders from before this system have no item list, so their cost cannot be counted.</p>' : '')
    . '<h2>' . $year . ' by month</h2>' . $table($rows, fn($k) => date('M Y', strtotime("$k-01")), ["Total $year", $T])
    . '<h2>By year</h2>' . $table($all, fn($k) => (string)$k)
    . '<p class="muted small">Sales are what customers paid (VAT included, COD fee included) for New, Paid and Delivered orders; cancelled orders, unpaid card attempts and test payments are left out. Cost of goods is the cost price of the bottles sold, including free minis. Profit = sales − cost of goods − expenses. In ' . $year . ': discounts given ' . money($T['discount'] ?? 0) . ', COD fees ' . money($T['fees'] ?? 0) . ', stock bought ' . money($T['stock_bought'] ?? 0) . ' (not taken off profit).</p>', true);
}

/* one order */
if (isset($_GET['o'])) {
  $s = $pdo->prepare('SELECT * FROM fx_orders WHERE order_no = ?'); $s->execute([(string)$_GET['o']]); $o = $s->fetch();
  if (!$o) page('Not found', '<h1>Order not found</h1><p><a href="./">Back to orders</a></p>');
  $items = array_filter(array_map('trim', explode('|', (string)$o['items'])));
  $opts = ''; foreach (FX_STATUSES as $x) $opts .= '<option' . ($x === $o['status'] ? ' selected' : '') . '>' . h($x) . '</option>';
  $wa = preg_replace('/\D/', '', $o['phone']); if (str_starts_with($wa, '05')) $wa = '971' . substr($wa, 1);
  $row = fn($k, $v) => $v === '' || $v === null ? '' : '<dt>' . h($k) . '</dt><dd>' . $v . '</dd>';
  page($o['order_no'], '<p class="small"><a href="./">← All orders</a></p>'
    . '<h1>' . h($o['order_no']) . ' <span class="tag s-' . h(strtok($o['status'], ' ')) . '">' . h($o['status']) . '</span></h1>' . flash()
    . '<div class="grid2"><div>'
    . '<div class="card"><h2 style="margin-top:0">Items</h2><ul class="items">' . implode('', array_map(fn($i) => '<li>' . h($i) . '</li>', $items)) . '</ul>'
    . '<dl style="margin-top:14px">' . $row('Subtotal', $o['subtotal'] !== null ? money($o['subtotal']) : '') . $row('Discount', $o['discount'] > 0 ? '-' . money($o['discount']) : '')
    . $row('COD fee', $o['fee'] > 0 ? money($o['fee']) : '') . $row('Total', '<b>' . money($o['total']) . '</b>') . $row('Payment', h($o['payment']) . ($o['test'] ? ' (test)' : ''))
    . $row('Ordered', h(date('d M Y, H:i', strtotime($o['created_at'])))) . $row('Paid', $o['paid_at'] ? h(date('d M Y, H:i', strtotime($o['paid_at']))) : '')
    . $row('Card ref', h($o['ref'] ?? '')) . '</dl></div>'
    . '<div class="card" style="margin-top:14px"><h2 style="margin-top:0">Customer</h2><dl>'
    . $row('Name', h($o['name'])) . $row('Mobile', h($o['phone']) . ($wa ? ' · <a href="https://wa.me/' . h($wa) . '" target="_blank" rel="noopener">WhatsApp</a>' : ''))
    . $row('Email', $o['email'] !== '' ? '<a href="mailto:' . h($o['email']) . '">' . h($o['email']) . '</a>' : '')
    . $row('Address', h($o['address'])) . $row('Emirate', h($o['emirate'])) . $row('Customer note', h($o['note'])) . '</dl></div>'
    . '</div><form class="card" method="post" style="align-self:start">' . csrf_field() . '<input type="hidden" name="order" value="' . h($o['order_no']) . '">'
    . '<h2 style="margin-top:0">Status</h2><label for="status">Order status</label><select id="status" name="status">' . $opts . '</select>'
    . '<label for="admin_note">Your note (only you see this)</label><textarea id="admin_note" name="admin_note">' . h($o['admin_note'] ?? '') . '</textarea>'
    . '<p style="margin:16px 0 0"><button class="btn">Save</button></p></form></div>', true);
}

/* list + filters (the same filters are used for the Excel download) */
$f = ['q' => trim((string)($_GET['q'] ?? '')), 'status' => (string)($_GET['status'] ?? ''), 'pay' => (string)($_GET['pay'] ?? ''),
      'from' => (string)($_GET['from'] ?? ''), 'to' => (string)($_GET['to'] ?? '')];
$where = []; $args = [];
if ($f['status'] === '') $where[] = "status <> 'Awaiting payment'";            // default: every real order
elseif ($f['status'] !== 'all' && in_array($f['status'], FX_STATUSES, true)) { $where[] = 'status = ?'; $args[] = $f['status']; }
if ($f['pay'] === 'cod') $where[] = "payment = 'Cash on delivery'"; elseif ($f['pay'] === 'card') $where[] = "payment LIKE 'Card%'";
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['from'])) { $where[] = 'created_at >= ?'; $args[] = $f['from'] . ' 00:00:00'; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['to']))   { $where[] = 'created_at <= ?'; $args[] = $f['to'] . ' 23:59:59'; }
if ($f['q'] !== '') { $where[] = '(order_no LIKE ? OR name LIKE ? OR phone LIKE ? OR email LIKE ? OR items LIKE ?)'; $like = '%' . addcslashes($f['q'], '%_\\') . '%'; array_push($args, $like, $like, $like, $like, $like); }
$W = $where ? 'WHERE ' . implode(' AND ', $where) : '';

if (isset($_GET['export'])) {   // CSV that opens straight in Excel
  $s = $pdo->prepare("SELECT * FROM fx_orders $W ORDER BY created_at, id"); $s->execute($args);
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="fomaxo-orders-' . date('Y-m-d') . '.csv"');
  $out = fopen('php://output', 'w');
  fwrite($out, "\xEF\xBB\xBF");   // so Excel reads Arabic names and the dash correctly
  fputcsv($out, ['Order no', 'Date', 'Time', 'Payment', 'Status', 'Paid on', 'Subtotal (AED)', 'Discount (AED)', 'COD fee (AED)', 'Total (AED)',
                 'Name', 'Mobile', 'Email', 'Emirate', 'Address', 'Items', 'Free mini', 'Customer note', 'Your note', 'Card ref', 'Test']);
  $cell = fn($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) && !preg_match('/^\+?[\d\s()\-]+$/', $v) ? "'" . $v : $v;
  while ($o = $s->fetch()) {
    $t = strtotime($o['created_at']);
    fputcsv($out, array_map($cell, [$o['order_no'], date('Y-m-d', $t), date('H:i', $t), $o['payment'], $o['status'], $o['paid_at'] ? date('Y-m-d', strtotime($o['paid_at'])) : '',
      $o['subtotal'], $o['discount'], $o['fee'], $o['total'], $o['name'], $o['phone'], $o['email'], $o['emirate'], $o['address'],
      str_replace(' | ', "\n", (string)$o['items']), $o['free_mini'], $o['note'], $o['admin_note'], $o['ref'], $o['test'] ? 'yes' : '']));
  }
  exit;
}

$s = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(CASE WHEN status IN ('New','Paid','Delivered') AND test = 0 THEN total END), 0) sales,
                    SUM(status = 'New') todo_cod, SUM(status = 'Paid') todo_card FROM fx_orders $W");
$s->execute($args); $sum = $s->fetch();
$per = 100; $pg = max(1, (int)($_GET['p'] ?? 1));
$s = $pdo->prepare("SELECT order_no, created_at, payment, status, total, name, phone, emirate, items, test FROM fx_orders $W ORDER BY created_at DESC, id DESC LIMIT $per OFFSET " . (($pg - 1) * $per));
$s->execute($args); $rows = $s->fetchAll();

$sel = fn($name, $opts) => '<select id="' . $name . '" name="' . $name . '">' . implode('', array_map(fn($k, $v) => '<option value="' . h($k) . '"' . ((string)$f[$name] === (string)$k ? ' selected' : '') . '>' . h($v) . '</option>', array_keys($opts), $opts)) . '</select>';
$stOpts = ['' => 'All orders', 'all' => 'Everything (incl. unpaid card)'] + array_combine(FX_STATUSES, FX_STATUSES);
$tr = '';
foreach ($rows as $o) {
  $u = h(self_url(['o' => $o['order_no']]));
  $tr .= '<tr class="row" onclick="location.href=\'' . $u . '\'"><td class="no" data-l=""><a href="' . $u . '">' . h($o['order_no']) . '</a>' . ($o['test'] ? ' <span class="muted small">test</span>' : '') . '</td>'
       . '<td data-l="">' . h(date('d M Y, H:i', strtotime($o['created_at']))) . '</td>'
       . '<td data-l="">' . h($o['name']) . '<div class="muted small">' . h($o['phone']) . ($o['emirate'] !== '' ? ' · ' . h($o['emirate']) : '') . '</div></td>'
       . '<td class="small" data-l="">' . h(mb_strimwidth(str_replace(' | ', ', ', (string)$o['items']), 0, 90, '…')) . '</td>'
       . '<td data-l="">' . h($o['payment'] === 'Cash on delivery' ? 'Cash' : 'Card') . '</td>'
       . '<td data-l=""><span class="tag s-' . h(strtok($o['status'], ' ')) . '">' . h($o['status']) . '</span></td>'
       . '<td class="num" data-l="">' . money($o['total']) . '</td></tr>';
}
$qs = array_filter($f, fn($v) => $v !== '');
$pager = ($pg > 1 ? '<a class="btn line" href="' . h(self_url($qs + ['p' => $pg - 1])) . '">Newer</a>' : '')
       . ($sum['n'] > $pg * $per ? '<a class="btn line" href="' . h(self_url($qs + ['p' => $pg + 1])) . '">Older</a>' : '');

page('Orders', '<h1>Orders</h1>' . flash()
  . '<form class="filters card" method="get">'
  . '<div class="q"><label for="q">Search</label><input id="q" name="q" value="' . h($f['q']) . '" placeholder="Order no, name, mobile, email or product"></div>'
  . '<div><label for="status">Status</label>' . $sel('status', $stOpts) . '</div>'
  . '<div><label for="pay">Payment</label>' . $sel('pay', ['' => 'All', 'cod' => 'Cash on delivery', 'card' => 'Card']) . '</div>'
  . '<div><label for="from">From</label><input id="from" type="date" name="from" value="' . h($f['from']) . '"></div>'
  . '<div><label for="to">To</label><input id="to" type="date" name="to" value="' . h($f['to']) . '"></div>'
  . '<div class="acts"><button class="btn">Show</button><a class="btn line" href="' . h(self_url($qs + ['export' => 1])) . '">Download Excel</a></div></form>'
  . '<div class="stats"><div class="stat"><b>' . (int)$sum['n'] . '</b><span>Orders</span></div>'
  . '<div class="stat"><b>' . money($sum['sales']) . '</b><span>Sales (not cancelled)</span></div>'
  . '<div class="stat"><b>' . (int)$sum['todo_cod'] . '</b><span>Cash to deliver</span></div>'
  . '<div class="stat"><b>' . (int)$sum['todo_card'] . '</b><span>Paid, to deliver</span></div></div>'
  . ($rows ? '<table><thead><tr><th>Order</th><th>Date</th><th>Customer</th><th>Items</th><th>Pay</th><th>Status</th><th class="num">Total</th></tr></thead><tbody>' . $tr . '</tbody></table>'
           : '<p class="card muted">No orders match.</p>')
  . '<div class="pager">' . $pager . '</div>'
  . '<p class="muted small">Sales count New, Paid and Delivered orders and leave out test payments. Unpaid card attempts are hidden unless you pick "Everything".</p>', true);
