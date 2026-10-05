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
.tabs{display:flex;overflow-x:auto;scrollbar-width:none;background:var(--panel);border-bottom:1px solid var(--line)}
.tabs a{text-align:center;text-decoration:none;font-size:13px;letter-spacing:.1em;text-transform:uppercase;padding:14px 4px;color:var(--muted);border-bottom:2px solid transparent}
.tabs a.on{color:var(--gold);border-bottom-color:var(--gold)}
@media (max-width:759px){.tabs a{flex:0 0 auto;padding:14px 13px;font-size:12px;letter-spacing:.08em}}.tabs::-webkit-scrollbar{display:none}
.todos{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin:0 0 4px}
.todo{display:block;background:var(--panel);border:1px solid var(--line);border-radius:10px;padding:12px 14px;text-decoration:none;color:var(--ink)}
.todo b{display:block;font-size:26px;font-weight:500;color:var(--muted)}.todo span{font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:var(--muted)}
.todo.hot{border-color:var(--gold)}.todo.hot b{color:var(--gold)}
.dash{display:grid;gap:14px}@media (min-width:860px){.dash{grid-template-columns:3fr 2fr}}.dash>*{min-width:0}
.chart{width:100%;height:auto;display:block;overflow:visible}.chart .bar{fill:var(--gold)}.chart .grid,.chart .axis{stroke:var(--line);stroke-width:1}
.chart text{fill:var(--muted);font-size:11px}@media (max-width:759px){.chart text{font-size:21px}}.chart .hit{fill:transparent;cursor:pointer}.chart .hit.on{fill:rgba(143,107,55,.12)}
#tip{min-height:1.5em;margin:6px 0 0}
.list{list-style:none;margin:0;padding:0}.list li{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:9px 0;border-bottom:1px solid var(--line)}.list li:last-child{border:0}
.list.orders li{padding:0}.list.orders a{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:10px 0;color:var(--ink);text-decoration:none;width:100%}
.list small{display:block}.list .r{display:flex;flex-direction:column;align-items:flex-end;gap:4px;white-space:nowrap}
.prods td{vertical-align:middle}.pimg img{width:52px;height:52px;object-fit:cover;border-radius:7px;display:block}
@media (max-width:759px){.prods tr.row{display:grid;grid-template-columns:64px 1fr auto;align-items:center;padding:8px 4px}.prods td.pimg{grid-row:span 2}.prods td.num{display:none}}
.pform .card{margin-bottom:6px}.opt{text-transform:none;letter-spacing:0}label.sub{margin-top:4px;text-transform:none;letter-spacing:0;font-size:13px}
.g2{display:grid;gap:0 12px;grid-template-columns:1fr 1fr}.g2>div{min-width:0}
.szrow{display:grid;grid-template-columns:1fr 1fr;gap:0 12px;padding:4px 0 14px;border-bottom:1px solid var(--line)}.szrow:last-child{border:0;padding-bottom:0}.szrow>div{min-width:0}
@media (min-width:760px){.szrow{grid-template-columns:repeat(4,1fr)}}
label.chk{display:flex;align-items:center;gap:8px;text-transform:none;letter-spacing:0;font-size:14px;color:var(--ink);margin:8px 0 0}label.chk input{width:auto;margin:0}label.chk.big{font-size:16px;margin:0}
.phs{display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:12px;margin-bottom:6px}.ph img{width:100%;aspect-ratio:1;object-fit:cover;border-radius:8px;display:block;border:1px solid var(--line)}
@media (min-width:760px){.tabs{display:flex;justify-content:center;gap:10px}.tabs a{padding:14px 22px}}
.lvl-out{color:var(--bad)}.lvl-low{color:var(--warn)}.lvl-ok{color:var(--ok)}
.lowlvl{margin:0 0 14px}.lowlvl label{margin-top:0;text-transform:none;letter-spacing:0;font-size:15px;color:var(--ink)}.lowlvl input{max-width:120px}
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
         [['Home', './', !$_GET], ['Orders', './?orders=1', (bool)array_intersect_key($_GET, array_flip(['orders', 'o', 'q', 'status', 'pay', 'from', 'to', 'p']))],
          ['Products', './?products=1', isset($_GET['products'])], ['Stock', './?stock=1', isset($_GET['stock'])],
          ['Expenses', './?expenses=1', isset($_GET['expenses'])], ['Reports', './?reports=1', isset($_GET['reports'])], ['Settings', './?settings=1', isset($_GET['settings'])]])) . '</nav>' : '')
     . '<main class="wrap' . ($wide ? '' : ' narrow') . '">' . $body . '</main>'
     . '<script>var t=document.querySelector(".tabs a.on");if(t&&t.parentNode.scrollWidth>t.parentNode.clientWidth)t.parentNode.scrollLeft=t.offsetLeft-(t.parentNode.clientWidth-t.offsetWidth)/2;</script></body></html>';
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

/* ---- products: add a new perfume or change an existing one (name, sizes and prices, words, photos, show or hide) ----
   The website and both checkouts read this list. Old orders keep the prices they were sold at. */
function fx_slug($name, $taken) {
  $base = substr(preg_replace('/[^a-z0-9]+/', '', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name)), 0, 30) ?: 'product';
  for ($id = $base, $n = 2; isset($taken[$id]) || $id === 'new'; $n++) $id = $base . $n;
  return $id;
}
/* Saves an uploaded photo as a compressed webp in assets/img/up/ and returns its key for the website (or null). */
function fx_save_photo($tmp, $id) {
  if (!is_uploaded_file($tmp) || !($info = @getimagesize($tmp)) || !function_exists('imagewebp')) return null;
  $im = @imagecreatefromstring((string)file_get_contents($tmp)); if (!$im) return null;
  if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {   // phone photos: turn them the right way up
    $o = (int)((@exif_read_data($tmp) ?: [])['Orientation'] ?? 1);
    if ($o === 3) $im = imagerotate($im, 180, 0); elseif ($o === 6) $im = imagerotate($im, -90, 0); elseif ($o === 8) $im = imagerotate($im, 90, 0);
  }
  $w = imagesx($im); $h = imagesy($im); $max = 1600;
  if (max($w, $h) > $max) $im = imagescale($im, $w >= $h ? $max : (int)round($w * $max / $h), $w >= $h ? (int)round($h * $max / $w) : $max);
  imagepalettetotruecolor($im); imagealphablending($im, false); imagesavealpha($im, true);
  $dir = dirname(__DIR__) . '/assets/img/up';
  if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return null;
  $name = $id . '-' . bin2hex(random_bytes(4));
  return @imagewebp($im, "$dir/$name.webp", 80) ? "up/$name" : null;
}
if (isset($_GET['products'])) {
  $rows = fomaxo_product_rows($pdo) ?: [];
  $byId = []; foreach ($rows as $r) $byId[$r['id']] = $r;
  $pid = (string)($_GET['p'] ?? '');
  $isNew = $pid === 'new';
  if ($pid !== '' && !$isNew && !isset($byId[$pid])) go(['products' => 1]);
  $costs = []; foreach ($pdo->query('SELECT product, size, cost FROM fx_stock') as $r) $costs[$r['product'] . '|' . $r['size']] = $r['cost'];

  if ($pid !== '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) { flash('Please try again. If you added big photos, try fewer at a time.'); go(['products' => 1, 'p' => $pid]); }
    $p = $isNew ? ['id' => '', 'name' => '', 'family' => '', 'short' => '', 'description' => [], 'sizes' => [], 'prices' => [], 'images' => [], 'url' => ''] : json_decode($byId[$pid]['data'], true);
    $isSet = ($p['kind'] ?? '') === 'set';
    $t = fn($k, $max) => trim(mb_substr(preg_replace('/\s+/u', ' ', (string)($_POST[$k] ?? '')), 0, $max));
    $back = fn($m) => [flash($m), go(['products' => 1, 'p' => $pid])];
    $p['name'] = $t('name', 60); if (mb_strlen($p['name']) < 2) $back('Please type the product name.');
    $p['family'] = $t('family', 60) ?: 'Eau de Parfum';
    $p['short'] = $t('short', 200);
    if (($tag = $t('tag', 30)) !== '') $p['tag'] = $tag; else unset($p['tag']);
    if (!$isSet) { $tier = (string)($_POST['tier'] ?? ''); if (in_array($tier, ['elite', 'signature', 'prestige'], true)) $p['tier'] = $tier; else unset($p['tier']); }
    $desc = array_values(array_filter(array_map(fn($x) => trim(preg_replace('/\s+/u', ' ', $x)), preg_split('/\R\s*\R/u', mb_substr((string)($_POST['description'] ?? ''), 0, 4000)))));
    $p['description'] = $desc ?: [$p['short'] ?: $p['name']];
    if (!$isSet) {
      $notes = [];
      foreach (['key', 'top', 'heart', 'base'] as $k) if (($v = $t("note_$k", 120)) !== '') $notes[$k] = $v;
      if (isset($notes['key'])) $notes = ['key' => $notes['key']];
      elseif ($notes) $notes += ['top' => '', 'heart' => '', 'base' => ''];
      if ($notes) $p['notes'] = $notes; else unset($p['notes']);
    }
    /* sizes: one row each; a row without a price is removed */
    $sizes = []; $prices = []; $was = []; $cost = [];
    foreach ((array)($_POST['size'] ?? []) as $i => $s) {
      $s = (int)$s; $pr = str_replace(',', '.', trim((string)($_POST['price'][$i] ?? '')));
      if ($s < 1 || $s > 1000 || !is_numeric($pr) || $pr <= 0 || in_array($s, $sizes, true)) continue;
      $sizes[] = $s; $prices[(string)$s] = round($pr + 0, 2) + 0;
      $w = str_replace(',', '.', trim((string)($_POST['was'][$i] ?? ''))); if (is_numeric($w) && $w > $pr) $was[(string)$s] = round($w + 0, 2) + 0;
      $c = str_replace(',', '.', trim((string)($_POST['cost'][$i] ?? ''))); $cost[(string)$s] = is_numeric($c) && $c >= 0 ? round((float)$c, 2) : null;
    }
    if (!$sizes) $back('Please type at least one size with its price.');
    sort($sizes);
    $p['sizes'] = $sizes;
    $p['prices'] = (object)array_combine(array_map('strval', $sizes), array_map(fn($s) => $prices[(string)$s], $sizes));
    if ($was) $p['compareAt'] = (object)$was; else unset($p['compareAt']);
    /* photos: remove ticked ones, put the chosen main photo first, add new uploads at the end */
    $imgs = array_values(array_filter((array)($p['images'] ?? []), 'is_string'));
    $drop = array_map('strval', (array)($_POST['drop'] ?? []));
    $keep = array_values(array_filter($imgs, fn($k) => !in_array($k, $drop, true)));
    $main = (string)($_POST['main'] ?? '');
    if (in_array($main, $keep, true)) $keep = array_values(array_unique(array_merge([$main], $keep)));
    $id = $isNew ? fx_slug($p['name'], $byId) : $pid;
    $bad = 0;
    $files = $_FILES['photos'] ?? null;
    if ($files && is_array($files['tmp_name'])) foreach ($files['tmp_name'] as $i => $tmp) {
      if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
      if (count($keep) >= 12 || ($files['error'][$i] ?? 1) !== UPLOAD_ERR_OK || !($k = fx_save_photo($tmp, $id))) { $bad++; continue; }
      $keep[] = $k;
    }
    if (!$keep) $back($bad ? 'The photo could not be saved. Please try a JPG or PNG photo.' : 'Please add at least one photo.');
    $p['images'] = $keep;
    $p['id'] = $id;
    $hidden = empty($_POST['show']) ? 1 : 0;
    if ($isNew) {
      $pos = (int)$pdo->query('SELECT COALESCE(MAX(pos), 0) + 10 FROM fx_products')->fetchColumn();
      $pdo->prepare('INSERT INTO fx_products (id, pos, hidden, data, updated_at) VALUES (?, ?, ?, ?, NOW())')->execute([$id, $pos, $hidden, fomaxo_json($p)]);
    } else {
      $pdo->prepare('UPDATE fx_products SET hidden = ?, data = ?, updated_at = NOW() WHERE id = ?')->execute([$hidden, fomaxo_json($p), $id]);
    }
    $setCost = $pdo->prepare('INSERT INTO fx_stock (product, size, qty, cost, updated_at) VALUES (?, ?, NULL, ?, NOW()) ON DUPLICATE KEY UPDATE cost = VALUES(cost), updated_at = NOW()');
    foreach ($cost as $s => $c) {
      $old = $costs["$id|$s"] ?? null;
      if (($old === null ? null : round((float)$old, 2)) !== $c) $setCost->execute([$id, (string)$s, $c]);
    }
    flash(($hidden ? 'Saved. It is hidden from the website.' : 'Saved. The website shows the change within a minute.') . ($bad ? " $bad photo" . ($bad > 1 ? 's' : '') . ' could not be added (use JPG or PNG).' : ''), !$bad);
    go(['products' => 1]);
  }

  if ($pid === '') {   // the list
    $tr = '';
    foreach ($rows as $r) {
      $p = json_decode($r['data'], true) ?: [];
      $isSet = ($p['kind'] ?? '') === 'set';
      $sz = implode(' · ', array_map(fn($s) => ($isSet ? "Set of $s" : "{$s}ml") . ' AED ' . number_format((float)($p['prices'][(string)$s] ?? 0), 0), (array)($p['sizes'] ?? [])));
      $img = $p['images'][0] ?? '';
      $tr .= '<tr class="row" onclick="location.href=this.dataset.href" data-href="' . h(self_url(['products' => 1, 'p' => $r['id']])) . '">'
           . '<td class="pimg">' . ($img ? '<img src="../assets/img/' . h($img) . '.webp" alt="">' : '') . '</td>'
           . '<td><b>' . h($p['name'] ?? $r['id']) . '</b><div class="small muted">' . h($sz) . '</div></td>'
           . '<td>' . ($r['hidden'] ? '<span class="tag s-Cancelled">Hidden</span>' : '<span class="tag s-Delivered">On website</span>') . '</td>'
           . '<td class="num"><a class="btn line sm" href="' . h(self_url(['products' => 1, 'p' => $r['id']])) . '">Edit</a></td></tr>';
    }
    page('Products', '<div class="monthnav" style="margin-top:0"><h1 style="margin:0">Products</h1><a class="btn" href="' . h(self_url(['products' => 1, 'p' => 'new'])) . '">Add product</a></div>' . flash()
      . ($rows ? '<table class="prods"><thead><tr><th></th><th>Product</th><th>Website</th><th></th></tr></thead><tbody>' . $tr . '</tbody></table>'
               : '<p class="msg bad">The product list could not be loaded from the database.</p>')
      . '<p class="muted small">New sizes show up on the Stock page by themselves. Changing a price does not change old orders or reports.</p>', true);
  }

  /* add or edit form */
  $p = $isNew ? ['name' => '', 'family' => 'Eau de Parfum', 'short' => '', 'description' => [], 'sizes' => [], 'prices' => [], 'images' => [], 'tier' => 'prestige'] : (json_decode($byId[$pid]['data'], true) ?: []);
  $isSet = ($p['kind'] ?? '') === 'set';
  $hidden = !$isNew && $byId[$pid]['hidden'];
  $notes = (array)($p['notes'] ?? []);
  $sizes = (array)($p['sizes'] ?? []);
  if ($isNew) $sizes = [10, 50, 100];
  $rowsHtml = '';
  foreach (array_merge($sizes, [null]) as $i => $s) {
    $v = fn($arr) => $s !== null && isset($p[$arr][(string)$s]) ? h($p[$arr][(string)$s] + 0) : '';
    $c = $s !== null && isset($costs["$pid|$s"]) && $costs["$pid|$s"] !== null ? h(rtrim(rtrim($costs["$pid|$s"], '0'), '.')) : '';
    $rowsHtml .= '<div class="szrow">'
      . '<div><label>' . ($isSet ? 'Set of (vials)' : 'Size (ml)') . '</label><input type="number" min="1" max="1000" inputmode="numeric" name="size[' . $i . ']" value="' . h($s ?? '') . '"' . ($s === null ? ' placeholder="' . ($isSet ? 'e.g. 10' : 'e.g. 30') . '"' : '') . '></div>'
      . '<div><label>Price AED</label><input type="number" min="0" step="0.01" inputmode="decimal" name="price[' . $i . ']" value="' . $v('prices') . '"></div>'
      . '<div><label>Was AED <span class="opt">(optional)</span></label><input type="number" min="0" step="0.01" inputmode="decimal" name="was[' . $i . ']" value="' . $v('compareAt') . '" placeholder="—"></div>'
      . '<div><label>Your cost <span class="opt">(optional)</span></label><input type="number" min="0" step="0.01" inputmode="decimal" name="cost[' . $i . ']" value="' . $c . '" placeholder="—"></div></div>';
  }
  $photos = '';
  foreach ((array)($p['images'] ?? []) as $i => $k) {
    $photos .= '<div class="ph"><img src="../assets/img/' . h($k) . '.webp" alt="">'
      . '<label class="chk"><input type="radio" name="main" value="' . h($k) . '"' . ($i === 0 ? ' checked' : '') . '> Main photo</label>'
      . '<label class="chk"><input type="checkbox" name="drop[]" value="' . h($k) . '"> Remove</label></div>';
  }
  $tiers = ['' => 'None', 'elite' => 'Elite', 'signature' => 'Signature', 'prestige' => 'Prestige'];
  page($isNew ? 'Add product' : 'Edit ' . ($p['name'] ?? ''), '<p><a href="' . h(self_url(['products' => 1])) . '">← All products</a></p>'
    . '<h1>' . ($isNew ? 'Add product' : h($p['name'] ?? '')) . '</h1>' . flash()
    . '<form method="post" enctype="multipart/form-data" class="pform">' . csrf_field()
    . '<div class="card"><label class="chk big"><input type="checkbox" name="show" value="1"' . ($hidden ? '' : ' checked') . '> Show on the website</label>'
    . '<label for="name">Name</label><input id="name" name="name" maxlength="60" required value="' . h($p['name'] ?? '') . '" placeholder="e.g. Velvet Oud">'
    . '<label for="family">Type</label><input id="family" name="family" maxlength="60" value="' . h($p['family'] ?? '') . '" placeholder="e.g. Eau de Parfum · Unisex">'
    . (!$isSet ? '<div class="g2"><div><label for="tier">Collection</label><select id="tier" name="tier">' . implode('', array_map(fn($k) => '<option value="' . $k . '"' . (($p['tier'] ?? '') === $k ? ' selected' : '') . '>' . $tiers[$k] . '</option>', array_keys($tiers))) . '</select></div>' : '<div class="g2">')
    . '<div><label for="tag">Badge <span class="opt">(optional)</span></label><input id="tag" name="tag" maxlength="30" value="' . h($p['tag'] ?? '') . '" placeholder="e.g. New"></div></div>'
    . '<label for="short">One-line description</label><input id="short" name="short" maxlength="200" value="' . h($p['short'] ?? '') . '">'
    . '<label for="description">Full description <span class="opt">(leave an empty line between paragraphs)</span></label><textarea id="description" name="description" rows="6">' . h(implode("\n\n", (array)($p['description'] ?? []))) . '</textarea>'
    . (!$isSet ? '<label>Fragrance notes</label><div class="g2">'
        . '<div><label class="sub" for="nt">Top</label><input id="nt" name="note_top" value="' . h($notes['top'] ?? '') . '" placeholder="e.g. Bergamot · Pepper"></div>'
        . '<div><label class="sub" for="nh">Heart</label><input id="nh" name="note_heart" value="' . h($notes['heart'] ?? '') . '"></div>'
        . '<div><label class="sub" for="nb">Base</label><input id="nb" name="note_base" value="' . h($notes['base'] ?? '') . '"></div>'
        . '<div><label class="sub" for="nk">Or key notes only</label><input id="nk" name="note_key" value="' . h($notes['key'] ?? '') . '"></div></div>' : '')
    . '</div>'
    . '<h2>Sizes and prices</h2><p class="muted small">Prices include VAT. "Was" shows a crossed-out price. Clear a price to remove that size.</p><div class="card">' . $rowsHtml . '</div>'
    . '<h2>Photos</h2><div class="card">' . ($photos ? '<div class="phs">' . $photos . '</div>' : '')
    . '<label for="photos">' . ($photos ? 'Add more photos' : 'Add photos') . '</label><input id="photos" type="file" name="photos[]" accept="image/*" multiple>'
    . '<p class="muted small">Square or portrait photos look best. They are made smaller automatically.</p></div>'
    . '<div class="savebar"><button class="btn big">' . ($isNew ? 'Add product' : 'Save') . '</button></div></form>', true);
}

/* ---- settings: admin password, where order emails go, low stock warning ---- */
if (isset($_GET['settings'])) {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) { flash('Please try again.'); go(['settings' => 1]); }
    if (isset($_POST['pw_now'])) {
      $hash = (string)fomaxo_setting($pdo, 'admin_hash');
      $p1 = (string)($_POST['pw1'] ?? ''); $p2 = (string)($_POST['pw2'] ?? '');
      if (!password_verify((string)$_POST['pw_now'], $hash)) { sleep(1); flash('Your current password is not right.'); }
      elseif (strlen($p1) < 8) flash('Choose a new password of at least 8 characters.');
      elseif ($p1 !== $p2) flash('The two new passwords are not the same.');
      else { fomaxo_setting($pdo, 'admin_hash', password_hash($p1, PASSWORD_DEFAULT)); session_regenerate_id(true); flash('Your new password is saved.', true); }
      go(['settings' => 1]);
    }
    $email = trim((string)($_POST['orders_email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { flash('Please type a valid email address.'); go(['settings' => 1]); }
    fomaxo_setting($pdo, 'orders_email', $email);   // the "Only X left" level is set on the Stock page
    flash('Settings saved.', true); go(['settings' => 1]);
  }
  page('Settings', '<h1>Settings</h1>' . flash()
    . '<form class="card" method="post">' . csrf_field()
    . '<h2 style="margin-top:0">Store</h2>'
    . '<label for="orders_email">New order emails go to</label><input id="orders_email" type="email" name="orders_email" required value="' . h(fomaxo_orders_email()) . '">'
    . '<p class="muted small" style="margin:6px 0 0">Every cash and card order is emailed here. Password reset links always go to ' . h(FX_STORE_EMAIL) . '.</p>'
    . '<p style="margin:18px 0 0"><button class="btn">Save</button></p></form>'
    . '<form class="card" method="post" style="margin-top:16px">' . csrf_field()
    . '<h2 style="margin-top:0">Admin password</h2>'
    . '<label for="pw_now">Current password</label><input id="pw_now" name="pw_now" type="password" required autocomplete="current-password">'
    . '<label for="pw1">New password</label><input id="pw1" name="pw1" type="password" minlength="8" required autocomplete="new-password">'
    . '<label for="pw2">New password again</label><input id="pw2" name="pw2" type="password" minlength="8" required autocomplete="new-password">'
    . '<p style="margin:18px 0 0"><button class="btn">Change password</button></p></form>');
}

/* ---- stock and cost price: one row per product and size ---- */
if (isset($_GET['stock'])) {
  require_once dirname(__DIR__) . '/store-lib.php';
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) { flash('Please try again.'); go(['stock' => 1]); }
    $lvl = trim((string)($_POST['low_stock'] ?? ''));   // the "Only X left" level for the website (only set here)
    if (ctype_digit($lvl) && (int)$lvl <= 100) fomaxo_setting($pdo, 'low_stock', (string)(int)$lvl);
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
    $lvl = $q === null ? '<span class="muted">Not counted</span>' : ((int)$q <= 0 ? '<span class="lvl-out">Sold out</span>' : ((int)$q <= fomaxo_low_stock() ? '<span class="lvl-low">Only ' . (int)$q . ' left</span>' : '<span class="lvl-ok">In stock</span>'));
    $margin = ' · <span class="muted">sells at AED ' . h(number_format($p['prices'][$opt], 0)) . '</span>';
    $tr .= '<tr class="row"><td><b>' . h($p['name']) . '</b> <span class="muted">' . h($p['kind'] === 'set' ? "Set of $opt" : "{$opt}ml") . '</span>'
         . '<div class="small">' . $lvl . $margin . (!empty($sold[$k]) ? ' <span class="muted">· ' . (int)$sold[$k] . ' sold in 30 days</span>' : '') . '</div></td>'
         . '<td class="num"><label class="mini">Stock</label><input type="number" min="0" inputmode="numeric" name="q[' . h($id) . '][' . h($opt) . ']" value="' . ($q === null ? '' : (int)$q) . '" placeholder="—" aria-label="' . h($p['name'] . ' ' . $opt) . ' stock"></td>'
         . '<td class="num"><label class="mini">Cost AED</label><input type="number" min="0" step="0.01" inputmode="decimal" name="c[' . h($id) . '][' . h($opt) . ']" value="' . ($c === null ? '' : h(rtrim(rtrim($c, '0'), '.'))) . '" placeholder="—" aria-label="' . h($p['name'] . ' ' . $opt) . ' cost price"></td></tr>';
  }
  page('Stock', '<h1>Stock &amp; cost</h1>' . flash()
    . '<p class="muted small"><b>Stock:</b> how many bottles you have. It goes down by itself with every cash order and every paid card order (the free mini counts as one 10ml) and goes back up if you cancel an order. Leave it empty to not count that size. The website shows "Only X left" at ' . fomaxo_low_stock() . ' or fewer, and "Sold out" at 0.<br>'
    . '<b>Cost:</b> what one bottle costs you. Reports use it to work out your profit.</p>'
    . '<form method="post">' . csrf_field()
    . '<div class="card lowlvl"><label for="low_stock">Show "Only X left" on the website when stock is at or below</label><input id="low_stock" type="number" min="0" max="100" inputmode="numeric" name="low_stock" required value="' . fomaxo_low_stock() . '">'
    . '<p class="muted small" style="margin:6px 0 0">One level for every product and size. 0 turns it off (Sold out still shows).</p></div>'
    . '<table class="stock"><thead><tr><th>Product</th><th class="num">Stock</th><th class="num">Cost (AED)</th></tr></thead><tbody>' . $tr . '</tbody></table>'
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
    . ($missing ? '<p class="msg bad">' . $missing . ' order' . ($missing > 1 ? 's' : '') . ' in ' . $year . ' ha' . ($missing > 1 ? 've' : 's') . ' no cost price, so profit is too high. Type the cost of each bottle on the <a href="./?stock=1">Stock</a> page.</p>' : '')
    . '<h2>' . $year . ' by month</h2>' . $table($rows, fn($k) => date('M Y', strtotime("$k-01")), ["Total $year", $T])
    . '<h2>By year</h2>' . $table($all, fn($k) => (string)$k)
    . '<p class="muted small">Sales are what customers paid (VAT included, COD fee included) for New, Paid and Delivered orders; cancelled orders, unpaid card attempts and test payments are left out. Cost of goods is the cost price of the bottles sold, including free minis. Profit = sales − cost of goods − expenses. In ' . $year . ': discounts given ' . money($T['discount'] ?? 0) . ', COD fees ' . money($T['fees'] ?? 0) . ', stock bought ' . money($T['stock_bought'] ?? 0) . ' (not taken off profit).</p>', true);
}

/* one order */
if (isset($_GET['o'])) {
  $s = $pdo->prepare('SELECT * FROM fx_orders WHERE order_no = ?'); $s->execute([(string)$_GET['o']]); $o = $s->fetch();
  if (!$o) page('Not found', '<h1>Order not found</h1><p><a href="./?orders=1">Back to orders</a></p>');
  $items = array_filter(array_map('trim', explode('|', (string)$o['items'])));
  $opts = ''; foreach (FX_STATUSES as $x) $opts .= '<option' . ($x === $o['status'] ? ' selected' : '') . '>' . h($x) . '</option>';
  $wa = preg_replace('/\D/', '', $o['phone']); if (str_starts_with($wa, '05')) $wa = '971' . substr($wa, 1);
  $row = fn($k, $v) => $v === '' || $v === null ? '' : '<dt>' . h($k) . '</dt><dd>' . $v . '</dd>';
  page($o['order_no'], '<p class="small"><a href="./?orders=1">← All orders</a></p>'
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

/* ---- dashboard: the home screen — today, this month, what needs doing, stock alerts, latest orders, last 30 days ---- */
if (!isset($_GET['orders']) && !array_intersect_key($_GET, array_flip(['q', 'status', 'pay', 'from', 'to', 'p', 'export']))) {
  require_once dirname(__DIR__) . '/store-lib.php';
  $real = "status IN ('New', 'Paid', 'Delivered') AND test = 0";
  $s = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(total), 0) sales FROM fx_orders WHERE $real AND created_at >= ?");
  $s->execute([date('Y-m-d') . ' 00:00:00']); $today = $s->fetch();
  $month = fomaxo_report($pdo, (int)date('Y'))[date('Y-m')];
  $todo = $pdo->query("SELECT SUM(status = 'New') cod, SUM(status = 'Paid') card FROM fx_orders WHERE test = 0")->fetch();
  /* last 30 days, one bar per day */
  $days = []; for ($i = 29; $i >= 0; $i--) $days[date('Y-m-d', strtotime("-$i day"))] = [0.0, 0];
  $s = $pdo->prepare("SELECT DATE(created_at) d, SUM(total) t, COUNT(*) n FROM fx_orders WHERE $real AND created_at >= ? GROUP BY DATE(created_at)");
  $s->execute([array_key_first($days) . ' 00:00:00']);
  foreach ($s as $r) if (isset($days[$r['d']])) $days[$r['d']] = [(float)$r['t'], (int)$r['n']];
  $max = max(array_map(fn($d) => $d[0], $days)); $sum30 = array_sum(array_map(fn($d) => $d[0], $days));
  $W = 600; $H = 170; $top = 18; $base = 146; $slot = $W / 30; $bw = 12;
  $bars = ''; $i = 0;
  foreach ($days as $d => [$t, $n]) {
    $x = round($i * $slot + ($slot - $bw) / 2, 1); $h = $max > 0 ? round(($base - $top) * $t / $max, 1) : 0;
    $tip = date('D j M', strtotime($d)) . ': ' . ($n ? money($t) . ' · ' . $n . ' order' . ($n > 1 ? 's' : '') : 'no sales');
    if ($h > 0) { $r = min(4, $h, $bw / 2); $y = $base - $h;
      $bars .= '<path class="bar" d="M' . $x . ' ' . $base . 'V' . ($y + $r) . 'Q' . $x . ' ' . $y . ' ' . ($x + $r) . ' ' . $y . 'H' . ($x + $bw - $r) . 'Q' . ($x + $bw) . ' ' . $y . ' ' . ($x + $bw) . ' ' . ($y + $r) . 'V' . $base . 'Z"/>'; }
    $bars .= '<rect class="hit" x="' . round($i * $slot, 1) . '" y="0" width="' . round($slot, 1) . '" height="' . $H . '" data-t="' . h($tip) . '"><title>' . h($tip) . '</title></rect>';
    $i++;
  }
  $lbl = fn($k, $anchor, $x) => '<text x="' . $x . '" y="164" text-anchor="' . $anchor . '">' . h(date('j M', strtotime($k))) . '</text>';
  $keys = array_keys($days);
  $chart = '<svg class="chart" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="Sales per day for the last 30 days, ' . h(money($sum30)) . ' in total">'
    . '<line class="grid" x1="0" x2="' . $W . '" y1="' . $top . '" y2="' . $top . '"/><text x="0" y="12">' . h($max > 0 ? money($max) : 'AED 0') . '</text>'
    . '<line class="axis" x1="0" x2="' . $W . '" y1="' . $base . '" y2="' . $base . '"/>' . $bars
    . $lbl($keys[0], 'start', 0) . $lbl($keys[15], 'middle', round(15.5 * $slot)) . $lbl($keys[29], 'end', $W) . '</svg>';
  /* stock running low (counted sizes at or below the warning level) */
  $low = fomaxo_low_stock(); $alerts = '';
  foreach ($pdo->query('SELECT product, size, qty FROM fx_stock WHERE qty IS NOT NULL ORDER BY qty, product') as $r) {
    if ((int)$r['qty'] > $low || !isset($CATALOG[$r['product']]['prices'][$r['size']])) continue;
    $p = $CATALOG[$r['product']];
    $alerts .= '<li><span>' . h($p['name']) . ' <span class="muted">' . h($p['kind'] === 'set' ? 'Set of ' . $r['size'] : $r['size'] . 'ml') . '</span></span>'
             . ((int)$r['qty'] <= 0 ? '<b class="lvl-out">Sold out</b>' : '<b class="lvl-low">' . (int)$r['qty'] . ' left</b>') . '</li>';
  }
  $latest = '';
  foreach ($pdo->query("SELECT order_no, created_at, status, total, name, payment FROM fx_orders WHERE status <> 'Awaiting payment' ORDER BY created_at DESC, id DESC LIMIT 5") as $o) {
    $latest .= '<li><a href="' . h(self_url(['o' => $o['order_no']])) . '"><span><b>' . h($o['order_no']) . '</b> ' . h($o['name'])
             . '<small class="muted">' . h(date('d M, H:i', strtotime($o['created_at']))) . ' · ' . h($o['payment'] === 'Cash on delivery' ? 'Cash' : 'Card') . '</small></span>'
             . '<span class="r"><span class="tag s-' . h(strtok($o['status'], ' ')) . '">' . h($o['status']) . '</span><b>' . money($o['total']) . '</b></span></a></li>';
  }
  $profit = (float)$month['profit'];
  $todoBox = fn($n, $label, $st) => '<a class="todo' . ($n ? ' hot' : '') . '" href="' . h(self_url(['orders' => 1, 'status' => $st])) . '"><b>' . (int)$n . '</b><span>' . $label . '</span></a>';
  page('Dashboard', '<h1>Dashboard</h1>' . flash()
    . '<div class="todos">' . $todoBox($todo['cod'], 'Cash orders to deliver', 'New') . $todoBox($todo['card'], 'Paid card orders to deliver', 'Paid') . '</div>'
    . '<div class="stats">'
    . '<div class="stat"><b>' . money($today['sales']) . '</b><span>Sales today · ' . (int)$today['n'] . ' order' . ((int)$today['n'] === 1 ? '' : 's') . '</span></div>'
    . '<div class="stat"><b>' . money($month['sales']) . '</b><span>Sales ' . date('F') . ' · ' . (int)$month['orders'] . ' order' . ((int)$month['orders'] === 1 ? '' : 's') . '</span></div>'
    . '<div class="stat"><b class="' . ($profit < 0 ? 'lvl-out' : 'lvl-ok') . '">' . ($profit < 0 ? '−' : '') . money(abs($profit)) . '</b><span>' . ($profit < 0 ? 'Loss' : 'Profit') . ' ' . date('F') . '</span></div></div>'
    . ($month['no_cost'] ? '<p class="muted small">' . (int)$month['no_cost'] . ' order' . ($month['no_cost'] > 1 ? 's' : '') . ' this month ha' . ($month['no_cost'] > 1 ? 've' : 's') . ' no cost price, so profit is too high. Add costs on the <a href="./?stock=1">Stock</a> page.</p>' : '')
    . '<div class="dash"><section class="card"><h2 style="margin-top:0">Sales, last 30 days</h2><p class="muted small" style="margin:-6px 0 8px">' . h(money($sum30)) . ' in total · tap a day</p>' . $chart
    . '<p class="small" id="tip" aria-live="polite">&nbsp;</p></section>'
    . '<section class="card"><h2 style="margin-top:0">Stock alerts</h2>' . ($alerts ? '<ul class="list">' . $alerts . '</ul>' : '<p class="muted small">No size is running low. Sizes with an empty stock box are not counted.</p>')
    . '<p class="small" style="margin:10px 0 0"><a href="./?stock=1">Open Stock</a></p></section></div>'
    . '<section class="card" style="margin-top:14px"><h2 style="margin-top:0">Latest orders</h2>' . ($latest ? '<ul class="list orders">' . $latest . '</ul>' : '<p class="muted small">No orders yet.</p>')
    . '<p class="small" style="margin:10px 0 0"><a href="./?orders=1">All orders</a> · <a href="./?reports=1">Reports</a></p></section>'
    . '<script>document.querySelectorAll(".chart .hit").forEach(function(r){var s=function(){document.getElementById("tip").textContent=r.dataset.t;document.querySelectorAll(".chart .hit.on").forEach(function(x){x.classList.remove("on")});r.classList.add("on")};r.addEventListener("mouseenter",s);r.addEventListener("click",s)});</script>', true);
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
$qs = ['orders' => 1] + array_filter($f, fn($v) => $v !== '');
$pager = ($pg > 1 ? '<a class="btn line" href="' . h(self_url($qs + ['p' => $pg - 1])) . '">Newer</a>' : '')
       . ($sum['n'] > $pg * $per ? '<a class="btn line" href="' . h(self_url($qs + ['p' => $pg + 1])) . '">Older</a>' : '');

page('Orders', '<h1>Orders</h1>' . flash()
  . '<form class="filters card" method="get"><input type="hidden" name="orders" value="1">'
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
