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

$STORE_EMAIL = 'fomaxoasset@gmail.com';   // first set-up; after that password links go to the address in Settings
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
/* the period on Home, Expenses, Reviews and Members, like Analytics: Today / 7 days / 30 days (/ Year / All) or From–To dates; each page remembers its last choice */
function adm_range($pg, $def, $presets) {
  $day = fn($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v);
  $in = isset($_GET['r']) ? ['r' => (string)$_GET['r'], 'd1' => $_GET['d1'] ?? null, 'd2' => $_GET['d2'] ?? null] : ($_SESSION['rng'][$pg] ?? ['r' => $def]);
  $r = $in['r'];
  if ($r === 'custom' && $day($in['d1'] ?? null) && $day($in['d2'] ?? null)) { $d1 = min($in['d1'], $in['d2']); $d2 = max($in['d1'], $in['d2']); }
  else {
    if (!isset($presets[$r])) $r = $def;
    $d2 = date('Y-m-d');
    $d1 = $r === 'all' ? null : ($r === 'year' ? date('Y-m-01', strtotime(date('Y-m-01') . ' -11 month')) : date('Y-m-d', strtotime('-' . ($r === 'today' ? 0 : (int)$r - 1) . ' day')));
  }
  $_SESSION['rng'][$pg] = ['r' => $r, 'd1' => $d1, 'd2' => $d2];
  $f = fn($d) => date(substr($d, 0, 4) === date('Y') ? 'j M' : 'j M Y', strtotime($d));
  return ['r' => $r, 'd1' => $d1, 'd2' => $d2, 'presets' => $presets, 'span' => $d1 === null ? null : [$d1 . ' 00:00:00', $d2 . ' 23:59:59'],
          'label' => $r === 'custom' ? $f($d1) . ($d1 === $d2 ? '' : ' – ' . $f($d2)) : $presets[$r]];
}
function adm_range_form($rg, $base) {
  $hid = ''; foreach ($base as $k => $v) $hid .= '<input type="hidden" name="' . h($k) . '" value="' . h($v) . '">';
  return '<form class="arange" method="get">' . $hid . '<div class="seg">'
    . implode('', array_map(fn($k, $l) => '<a href="' . h(self_url($base + ['r' => (string)$k])) . '"' . ($rg['r'] === (string)$k ? ' class="on"' : '') . '>' . $l . '</a>', array_keys($rg['presets']), $rg['presets'])) . '</div>'
    . '<input type="hidden" name="r" value="custom"><input type="date" name="d1" value="' . h($rg['d1'] ?? '') . '" aria-label="From" required><input type="date" name="d2" value="' . h($rg['d2']) . '" aria-label="To" required>'
    . '<button class="btn sm' . ($rg['r'] === 'custom' ? '' : ' line') . '">Show</button></form>';
}

function page($title, $body, $wide = false, $fit = false) {   // $fit: fill the screen, lists scroll inside .fill
  $css = <<<'CSS'
:root{--bg:#f7f3ec;--panel:#fff;--ink:#16130f;--muted:#5d554a;--line:#e3d9c8;--gold:#8f6b37;--gold-ink:#fff;--ok:#2f6b3a;--warn:#9a5b12;--bad:#9b2c2c}
@media (prefers-color-scheme:dark){:root{--bg:#050505;--panel:#0e0d0b;--ink:#f2ebdd;--muted:#b5aa98;--line:#2a251e;--gold:#d7aa69;--gold-ink:#000;--ok:#7fc48b;--warn:#e0a85a;--bad:#ef8a8a}}
*{box-sizing:border-box}html{color-scheme:light dark}
body{margin:0;background:var(--bg);color:var(--ink);font:14.5px/1.45 Manrope,system-ui,sans-serif;font-style:normal;-webkit-font-smoothing:antialiased}
em,i{font-style:normal}
a{color:var(--gold)}
.top{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:9px 16px;border-bottom:1px solid var(--line);background:var(--panel)}
.brand{font-family:Cinzel,Georgia,serif;font-weight:600;font-size:20px;letter-spacing:.2em;color:var(--gold);text-decoration:none}
.brand small{font-family:Manrope,sans-serif;font-size:11px;letter-spacing:.3em;color:var(--muted);margin-left:8px}
.wrap{max-width:1180px;margin:0 auto;padding:14px 16px 16px}
.narrow{max-width:420px}
h1{font-family:Cinzel,Georgia,serif;font-weight:600;font-size:21px;letter-spacing:.06em;margin:0 0 10px}
h2{font-family:Cinzel,Georgia,serif;font-weight:600;font-size:16px;letter-spacing:.06em;margin:14px 0 8px}
.card{background:var(--panel);border:1px solid var(--line);border-radius:10px;padding:14px 16px}
label{display:block;font-size:11.5px;font-weight:500;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);margin:10px 0 4px}
input,select,textarea{width:100%;font:inherit;color:var(--ink);background:var(--bg);border:1px solid var(--line);border-radius:7px;padding:8px 10px}
textarea{min-height:80px}
.btn{display:inline-block;font:inherit;font-size:12.5px;font-weight:600;letter-spacing:.12em;text-transform:uppercase;background:var(--gold);color:var(--gold-ink);border:0;border-radius:7px;padding:9px 16px;cursor:pointer;text-decoration:none;white-space:nowrap}
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
.stats{display:flex;gap:8px;flex-wrap:wrap;margin:10px 0}
.stat{flex:1 1 140px;background:var(--panel);border:1px solid var(--line);border-radius:10px;padding:8px 12px}
.stat b{display:block;font-size:18px;font-weight:600}
.stat span{font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:var(--muted)}
table{width:100%;border-collapse:separate;border-spacing:0;background:var(--panel);border:1px solid var(--line);border-radius:10px;overflow:hidden}
th,td{text-align:left;padding:8px 12px;border-bottom:1px solid var(--line);vertical-align:top}
th{font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);font-weight:600}
td.num,th.num{text-align:right;white-space:nowrap}
tr.row{cursor:pointer}table:not(.stock):not(.exp) tr.row:hover td{background:rgba(143,107,55,.08)}.stock tr.row,.exp tr.row{cursor:default}
.no{font-weight:500;white-space:nowrap}
.tag{display:inline-block;font-size:12px;padding:2px 9px;border-radius:20px;border:1px solid currentColor;white-space:nowrap}
.s-New{color:var(--warn)}.s-Paid{color:var(--gold)}.s-Delivered{color:var(--ok)}.s-Cancelled{color:var(--bad)}.s-Refunded{color:var(--bad)}.s-Awaiting{color:var(--muted)}
.oacts{display:flex;gap:5px;flex-wrap:wrap;margin:0}.oacts form{margin:0}.oacts button{font:inherit;font-size:11px;font-weight:600;letter-spacing:.03em;padding:4px 9px;border-radius:999px;border:1px solid var(--line);background:transparent;color:var(--ink);cursor:pointer;white-space:nowrap}.oacts button:hover{border-color:var(--gold)}.oacts .a-paid{border-color:var(--gold);color:var(--gold)}.oacts .a-delivered{border-color:var(--ok);color:var(--ok)}.oacts .a-cancel,.oacts .a-refund{color:var(--bad)}.tag.p-Unpaid{color:var(--warn);border-style:dashed}.wait{display:block;font-size:11px;color:var(--muted);margin-top:3px}.wait.late{color:var(--bad);font-weight:600}.track{list-style:none;display:flex;margin:0 0 14px;padding:0;max-width:640px}.track li{flex:1;position:relative;display:flex;flex-direction:column;align-items:center;text-align:center;gap:2px;font-size:12px;color:var(--muted)}.track li+li::before{content:'';position:absolute;top:13px;right:calc(50% + 16px);left:calc(-50% + 16px);height:2px;background:var(--line)}.track li.done+li.done::before{background:var(--gold)}.track li.bad::before{background:var(--bad)!important}.track i{font-style:normal;width:26px;height:26px;border-radius:50%;border:2px solid var(--line);display:grid;place-items:center;font-weight:700;font-size:12px;color:var(--muted)}.track .done i{background:var(--gold);border-color:var(--gold);color:var(--gold-ink)}.track .bad i{background:var(--bad);border-color:var(--bad);color:#fff}.track b{color:var(--ink);font-size:12.5px;font-weight:600}.track .done span{color:var(--ink)}@media (min-width:760px){.olist.acts td:nth-child(8){white-space:nowrap;width:1%}.olist.acts .oacts{flex-wrap:nowrap}.olist.acts td:nth-child(6) .tags{flex-wrap:nowrap}}.tag.p-Paid{color:var(--gold)}.tags{display:inline-flex;gap:4px;flex-wrap:wrap;justify-content:flex-end}.oacts button:disabled{opacity:.35;cursor:default}.oacts button.done:disabled{opacity:1}.oacts .a-paid.done{background:var(--gold);color:var(--gold-ink)}.oacts .a-delivered.done{background:var(--ok);border-color:var(--ok);color:var(--gold-ink)}.order-acts{margin:0 0 14px}.order-acts button{font-size:12.5px;padding:7px 14px}
/* order buttons: three equal square buttons (Paid or Refund, Mark delivered, Cancel order); small on laptop, big enough to tap on phone */
.oacts{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.45fr) minmax(0,1fr);gap:6px;min-width:330px}.oacts form{display:flex}.oacts button{width:100%;border-radius:6px;padding:5px 8px;font-size:11px;font-weight:700;letter-spacing:.02em;border:1px solid var(--line);background:transparent;color:var(--ink);text-align:center;overflow:hidden;text-overflow:ellipsis}.oacts .a-paid{border-color:var(--gold);color:var(--ink)}.oacts .a-refund,.oacts .a-cancel{border-color:var(--bad);color:var(--bad)}.oacts .a-delivered{background:var(--gold);border-color:var(--gold);color:var(--gold-ink)}.oacts .a-paid.done{background:var(--gold);border-color:var(--gold);color:var(--gold-ink)}.oacts .a-delivered.done{background:var(--ok);border-color:var(--ok);color:var(--gold-ink)}.oacts button:disabled{opacity:1;border-color:var(--line);color:var(--muted);background:transparent;opacity:.55;cursor:default}.oacts button.done:disabled{opacity:1}.oacts button:not(:disabled):hover{filter:brightness(1.12)}.stsel{font-weight:700;border-width:2px}.stsel.s-New,.stsel.s-Paid{border-color:var(--gold)}.stsel.s-Delivered{border-color:var(--ok)}.stsel.s-Cancelled,.stsel.s-Refunded{border-color:var(--bad)}/* a coloured edge on each order row (gold pending, green delivered, red cancelled/refunded) */
.olist.acts tr.row>td:first-child{border-left:4px solid transparent}.olist.acts tr.st-New>td:first-child,.olist.acts tr.st-Paid>td:first-child{border-left-color:var(--gold)}.olist.acts tr.st-Delivered>td:first-child{border-left-color:var(--ok)}.olist.acts tr.st-Cancelled>td:first-child,.olist.acts tr.st-Refunded>td:first-child{border-left-color:var(--bad)}.olist.acts tr.st-Delivered{background:color-mix(in srgb,var(--ok) 7%,transparent)}@media (max-width:759px){.olist.acts tr.row>td:first-child{border-left:0}.olist.acts tr.row{border-left:4px solid transparent}.olist.acts tr.st-New,.olist.acts tr.st-Paid{border-left-color:var(--gold)}.olist.acts tr.st-Delivered{border-left-color:var(--ok)}.olist.acts tr.st-Cancelled,.olist.acts tr.st-Refunded{border-left-color:var(--bad)}}.order-acts{max-width:460px}.order-acts button{font-size:13px;padding:9px 10px}
/* status tags in the same colours */
.tags .tag{font-size:10.5px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;padding:2px 8px}.tags .s-New{color:var(--gold)}.tags .s-Delivered{color:#3f78b5}.tags .p-Paid{color:var(--ok);background:color-mix(in srgb,var(--ok) 12%,transparent);border-color:color-mix(in srgb,var(--ok) 35%,transparent)}.tags .p-Unpaid{color:var(--warn);background:color-mix(in srgb,var(--warn) 12%,transparent);border-style:solid;border-color:color-mix(in srgb,var(--warn) 35%,transparent)}.tags .s-Refunded{border-style:dashed}@media (prefers-color-scheme:dark){.tags .s-Delivered{color:#7fb0ea}}@media (max-width:759px){.oacts{min-width:0;gap:8px}.oacts button{min-height:42px;font-size:12.5px;letter-spacing:0;padding:8px 4px}.olist td:nth-child(8) .oacts{margin-top:8px}}
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
.btn.sm{padding:7px 11px;font-size:11px}.btn.big{padding:12px 24px;font-size:13px;min-width:180px}
.tabs{display:flex;overflow-x:auto;scrollbar-width:none;background:var(--panel);border-bottom:1px solid var(--line)}
.tabs a{text-align:center;text-decoration:none;font-size:12.5px;font-weight:600;letter-spacing:.1em;text-transform:uppercase;padding:11px 4px;color:var(--muted);border-bottom:2px solid transparent}
.tabs a.on{color:var(--gold);border-bottom-color:var(--gold)}
.tabsw{display:flex;flex:none;background:var(--panel);border-bottom:1px solid var(--line)}.tabsw .tabs{flex:1 1 auto;min-width:0;border-bottom:0}.tnav{display:none}
@media (max-width:759px){.tnav:not([hidden]){display:flex;align-items:center;justify-content:center;flex:none;width:38px;font-size:24px;line-height:1;color:var(--gold);text-decoration:none;background:var(--panel)}.tnav[data-d="-1"]{border-right:1px solid var(--line)}.tnav[data-d="1"]{border-left:1px solid var(--line)}}
@media (max-width:759px){.tabs a{flex:0 0 auto;padding:11px 13px;font-size:12px;letter-spacing:.08em}}.tabs::-webkit-scrollbar{display:none}
.todos{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin:0 0 4px}
.todo{display:block;background:var(--panel);border:1px solid var(--line);border-radius:10px;padding:12px 14px;text-decoration:none;color:var(--ink)}
.todo b{display:block;font-size:26px;font-weight:500;color:var(--muted)}.todo span{font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:var(--muted)}
.todo.hot{border-color:var(--gold)}.todo.hot b{color:var(--gold)}
.dash{display:grid;gap:14px}@media (min-width:860px){.dash{grid-template-columns:3fr 2fr}}.dash>*{min-width:0}
.chart{width:100%;display:block;flex:1 1 auto;min-height:0;height:100%;overflow:visible}.chart .bar{fill:var(--gold)}.chart .grid,.chart .axis{stroke:var(--line);stroke-width:1;vector-effect:non-scaling-stroke}
.chart .ln{fill:none;stroke:var(--gold);stroke-width:2;stroke-linejoin:round;vector-effect:non-scaling-stroke}.chart .dots{fill:none;stroke:var(--gold);stroke-width:6;stroke-linecap:round;vector-effect:non-scaling-stroke}.chart .hit{fill:transparent;cursor:pointer}.chart .hit.on{fill:rgba(143,107,55,.16)}
.graph{flex:1 1 auto;min-height:0;display:flex;flex-direction:column;width:100%}.graph[hidden]{display:none}
.cmax{font-size:11.5px;color:var(--muted);line-height:1.2;margin:0 0 3px;font-variant-numeric:tabular-nums}
.clab{display:grid;font-size:11.5px;color:var(--muted);margin-top:4px;text-align:center;line-height:1.2}.clab span{white-space:nowrap;overflow:hidden}.clab.ends{display:flex;justify-content:space-between}
.db{display:flex;flex-direction:column;flex:1 1 auto;min-height:0}.db>*{flex:none}
.db h2{font-size:14.5px;margin:0;white-space:nowrap}.db .card{padding:10px 12px;display:flex;flex-direction:column;min-height:0;overflow:hidden}
.db .ch{display:flex;justify-content:space-between;align-items:center;gap:8px;margin:0 0 6px;flex:none}.db .ch a,.db .ch .val{font-size:12.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;min-width:0}.db .ch .val{color:var(--muted);text-align:right}
.seg{display:inline-flex;border:1px solid var(--line);border-radius:20px;padding:2px;gap:2px;flex:none}.seg button{font:inherit;font-size:11.5px;font-weight:600;border:0;background:none;color:var(--muted);padding:3px 9px;border-radius:20px;cursor:pointer;white-space:nowrap}.seg button.on{background:var(--gold);color:var(--gold-ink)}
.db .chartbox{flex:1 1 auto;min-height:40px;display:flex}
.db .sub{display:flex;justify-content:space-between;gap:8px;font-size:12px;color:var(--muted);margin:4px 0 0;flex:none;min-height:1.45em}
.tiles{display:grid;grid-template-columns:repeat(6,1fr);gap:8px;margin:0 0 8px}.tile{grid-column:span 2;display:block;min-width:0;background:var(--panel);border:1px solid var(--line);border-radius:10px;padding:8px 10px;text-decoration:none;color:var(--ink)}
.tile.todo{grid-column:span 3}.tile b{display:block;font-size:16px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-variant-numeric:tabular-nums}.tile span{display:block;font-size:10.5px;font-weight:500;letter-spacing:.05em;text-transform:uppercase;color:var(--muted);line-height:1.35}
.tile.todo b{font-size:20px;color:var(--muted)}.tile.hot{border-color:var(--gold)}.tile.hot b{color:var(--gold)}
.db .note{font-size:11.5px;margin:0 0 8px}
.dgrid{display:grid;gap:8px;flex:1 1 auto;min-height:0;grid-template-columns:minmax(0,1fr);grid-template-rows:minmax(0,1.15fr) minmax(0,1fr)}.dgrid>*{min-width:0}
.db .list{overflow:auto;flex:1 1 auto;min-height:0;overscroll-behavior:contain}.db .list li{padding:5px 0;font-size:13.5px}.db .list.orders li{padding:0}.db .list.orders a{padding:5px 0}.db .list .r{flex-direction:row;align-items:center;gap:8px}.db .list small{font-size:11.5px}
.db .empty{margin:0;font-size:13px}
@media (max-width:759px){.lists{overflow:auto;overscroll-behavior:contain;display:flex;flex-direction:column;gap:8px;min-height:0}.lists>.card{flex:none;overflow:visible}.lists .c-stock{order:-1}.lists .list{overflow:visible}
  .tile{padding:6px 9px}.tile b{font-size:15px}.tile.todo b{font-size:18px}.tile span{font-size:10px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.db .note{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .db .list.orders small{display:none}.db .list.orders a>span:first-child{min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.db .seg button{padding:3px 7px}}
@media (min-width:760px){.lists{display:contents}.tiles{grid-template-columns:repeat(5,1fr);gap:10px;margin-bottom:10px}.tile,.tile.todo{grid-column:auto}.tile{padding:10px 14px}.tile b,.tile.todo b{font-size:21px}.tile span{font-size:11px}
  .dgrid{grid-template-columns:minmax(0,3fr) minmax(0,2fr);grid-template-rows:minmax(0,1fr) minmax(0,1fr);grid-template-areas:"sales orders" "visits stock";gap:10px}
  .dgrid .c-sales{grid-area:sales}.dgrid .c-visits{grid-area:visits}.seg.met{display:none}.dgrid .c-orders{grid-area:orders}.dgrid .c-stock{grid-area:stock}.db .card{padding:12px 16px}.db h2{font-size:15.5px}}
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
.pimgw{position:relative}.pno{position:absolute;left:6px;top:6px;font-size:10.5px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;background:rgba(0,0,0,.7);color:#fff;padding:2px 7px;border-radius:10px}.ph.first .pno{background:var(--gold);color:var(--gold-ink)}.ph.first img{border:2px solid var(--gold)}.pmv{display:grid;grid-template-columns:36px 1fr 36px;gap:4px;margin:6px 0 2px}.pmv button{height:34px;border:1px solid var(--line);background:transparent;color:var(--ink);border-radius:6px;font:inherit;font-size:12px;font-weight:600;cursor:pointer;padding:0}.pmv button[data-mv='-1'],.pmv button[data-mv='1']{font-size:20px;line-height:1;color:var(--gold)}.pmv button[data-mv='0']{border-color:var(--gold);color:var(--gold)}.pmv button:disabled{opacity:.3;cursor:default}
@media (min-width:760px){.tabs{display:flex;justify-content:center;gap:10px}.tabs a{padding:11px 22px}}
.lvl-out{color:var(--bad)}.lvl-low{color:var(--warn)}.lvl-ok{color:var(--ok)}
.lowlvl{margin:0 0 10px;display:flex;align-items:center;gap:6px 12px;flex-wrap:wrap;padding:10px 14px}.lowlvl label{margin:0;text-transform:none;letter-spacing:0;font-size:14px;color:var(--ink)}.lowlvl input{max-width:90px}.lowlvl p{flex:1 1 240px;margin:0 !important}
.stock td,.exp td{vertical-align:middle}.stock input{width:110px;text-align:right}
label.mini{display:none}
.savebar{padding:10px 0 0}
.g3{display:grid;gap:0 12px}@media (min-width:760px){.g3{grid-template-columns:1fr 1fr 1fr}}
.monthnav{display:flex;align-items:center;justify-content:space-between;gap:10px;margin:12px 0 8px}.monthnav h2{margin:0;text-align:center}.monthnav h2 small{display:block;font-family:Manrope,sans-serif;font-size:15px;color:var(--muted)}
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
.ch.wrap2{flex-wrap:wrap;gap:6px}@media (max-width:759px){.db .dgrid .c-visits,.ch .dt{display:none}}.seg.met button{font-size:12.5px}
.quick{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px}.quick .btn{font-size:11px;letter-spacing:.08em}
@media (max-width:759px){.quick{display:grid;grid-template-columns:1fr 1fr;gap:6px}.quick .btn{padding:7px 6px;font-size:10px;letter-spacing:.04em;text-align:center;white-space:normal;line-height:1.25}}
.ems{display:flex;flex-wrap:wrap;gap:6px;margin:0 0 10px;flex:none}.ems::-webkit-scrollbar{display:none}
.ems .em{flex:none;font-size:12px;color:var(--ink);text-decoration:none;border:1px solid var(--line);background:var(--panel);border-radius:20px;padding:3px 10px;white-space:nowrap}.ems .em b{color:var(--gold);font-weight:600;margin-left:2px}.ems .em.on{border-color:var(--gold);background:var(--gold);color:var(--gold-ink)}.ems .em.on b{color:var(--gold-ink)}.ems.track .t-pending b,.ems.track .t-unpaid b{color:var(--warn)}.ems.track .t-delivered b{color:var(--ok)}.ems.track .t-cancelled b,.ems.track .t-refunded b{color:var(--bad)}.ems.track .em.on b{color:var(--gold-ink)}
.ems .em.on{background:var(--gold);border-color:var(--gold);color:var(--gold-ink)}.ems .em.on b{color:var(--gold-ink)}
@media (max-width:759px){.ems{margin-bottom:8px;gap:5px}.ems .em{font-size:11.5px;padding:2px 9px}.stats{margin-bottom:6px}}
/* order step chips fill the row as equal, evenly spaced boxes */
.ems.track.swarn{grid-template-columns:repeat(2,minmax(0,1fr))}.ems.track.swarn .t-low b{color:var(--warn)}.ems.track.swarn .t-out b{color:var(--bad)}.ems.track.swarn .t-low:not(.on){border-color:color-mix(in srgb,var(--warn) 55%,var(--line))}.ems.track.swarn .t-out:not(.on){border-color:color-mix(in srgb,var(--bad) 55%,var(--line))}.ems.track.swarn .em.on b{color:var(--gold-ink)}.swnote{margin:-4px 0 8px;flex:none}tr.row.wf{display:none!important}
.ems.track{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;width:100%;max-width:none;box-sizing:border-box}.ems.track .em{display:flex;justify-content:center;align-items:baseline;gap:6px;border-radius:10px;padding:8px 10px;font-size:12.5px}.ems.track .em b{margin:0;font-size:15px}
@media (max-width:759px){.ems.track{gap:6px}.ems.track .em{flex-direction:column;align-items:center;gap:1px;padding:6px 2px;font-size:10.5px;white-space:normal;text-align:center;line-height:1.2}.ems.track .em b{font-size:16px}}
/* analytics */
.seg a{font-size:11.5px;font-weight:600;color:var(--muted);padding:3px 9px;border-radius:20px;text-decoration:none;white-space:nowrap}.seg a.on{background:var(--gold);color:var(--gold-ink)}
.an .pagehead,.pagehead.rg{flex-wrap:wrap;margin-bottom:8px;gap:6px 10px}.arange{display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin:0}.arange input[type=date]{width:auto;padding:4px 7px;font-size:12.5px}
.apick{display:none}
.agrid{grid-template-rows:minmax(0,1fr)}
.fun li{display:block;padding:5px 0;border-bottom:0}.fl{display:flex;justify-content:space-between;gap:8px;font-size:13px}.fb{height:7px;background:var(--line);border-radius:4px;margin:3px 0 0;overflow:hidden}.fb i{display:block;height:100%;background:var(--gold);border-radius:4px}
.fd{font-size:11.5px;color:var(--bad);margin-left:4px;white-space:nowrap}.fun li.fx{padding-top:8px;line-height:1.5}
.leads li{display:block}
.lxl li.lx{padding:0;border-bottom:1px solid var(--line)}.lx summary{list-style:none;display:flex;align-items:center;gap:8px;padding:7px 2px;cursor:pointer;min-height:34px}.lx summary::-webkit-details-marker{display:none}.lx summary::before{content:'›';color:var(--gold);font-size:16px;line-height:1;transition:transform .15s;flex:none;width:10px}.lx details[open] summary::before{transform:rotate(90deg)}.lx .ln{flex:1 1 auto;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.lx .ln .muted{font-size:12px;margin-left:4px}.lx .lv{flex:none;font-weight:600;font-variant-numeric:tabular-nums}.lx .wa{flex:none;font-size:11px;font-weight:700;letter-spacing:.03em;text-decoration:none;color:#25d366;border:1px solid #25d366;border-radius:14px;padding:3px 9px}.lx .wa.none{border:0;padding:0;width:0}.lx .wa svg{display:none;width:15px;height:15px}.lxl{container-type:inline-size}@container (max-width:340px){.lx summary{gap:6px}.lx .ln .muted{display:none}.lx .lv{font-size:13px}.lx .wa{width:26px;height:26px;padding:0;border-radius:50%;display:grid;place-items:center}.lx .wa svg{display:block}.lx .wa span{display:none}}.lx .ld{display:grid;gap:3px;padding:0 4px 9px 20px}.lx .ld .tag{font-size:11px;padding:1px 8px}.leads .lt{display:flex;gap:8px;align-items:baseline;flex-wrap:wrap}.leads .lt .r{margin-left:auto}.items1{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.leads .tag{font-size:11px;padding:1px 8px}
.stage-details{color:var(--muted)}.stage-payment{color:var(--warn)}.stage-card{color:var(--bad)}
table.mini{border:0;border-radius:0;background:none;font-size:13px;overflow:visible}table.mini th,table.mini td{padding:5px 6px}table.mini th:first-child,table.mini td:first-child{padding-left:0}table.mini th:last-child,table.mini td:last-child{padding-right:0}
table.mini th{position:sticky;top:0;background:var(--panel)}
@media (max-width:759px){
  table.mini{display:table}table.mini thead{display:table-header-group}table.mini tbody{display:table-row-group}table.mini tr{display:table-row}table.mini td{display:table-cell;border-bottom:1px solid var(--line)}table.mini td.num{text-align:right}
  .tiles.at{grid-template-columns:repeat(4,1fr)}.tiles.at .tile{grid-column:auto}.tiles.at .tile b{font-size:14px}.tiles.at .tile span{font-size:9.5px;white-space:normal;line-height:1.2}
  .apick{display:flex;gap:6px;overflow-x:auto;scrollbar-width:none;margin:0 0 8px}.apick::-webkit-scrollbar{display:none}
  .apick button{flex:none;font:inherit;font-size:12px;font-weight:600;border:1px solid var(--line);background:var(--panel);color:var(--muted);border-radius:20px;padding:5px 11px;cursor:pointer}.apick button.on{background:var(--gold);border-color:var(--gold);color:var(--gold-ink)}
  .agrid>.card{display:none}.agrid>.card.on{display:flex}
  .an .pagehead,.pagehead.rg{display:grid;grid-template-columns:auto minmax(0,1fr) minmax(0,1fr) auto;align-items:center;gap:6px}.an .pagehead h1,.pagehead.rg h1{margin:0}.arange{display:contents}
  .arange .seg{grid-column:2/5;justify-self:end}.arange .seg a{padding:3px 8px}.arange input[name=d1]{grid-column:1/3;width:100%}.arange .dmy:has(input[name=d1]){grid-column:1/3}.arange input[name=d2]{width:100%}
  .tiles.at .tile:last-child{grid-column:span 2}
}
@media (min-width:760px){.tiles.at{grid-template-columns:repeat(8,1fr)}.tiles.at .tile b{font-size:19px}
  .agrid{grid-template-columns:minmax(0,1.05fr) minmax(0,1.3fr) minmax(0,1fr) minmax(0,1fr);grid-template-rows:minmax(0,1fr) minmax(0,1fr);grid-template-areas:"funnel visitors countries emirates" "products sources left left"}
  .a-funnel{grid-area:funnel}.a-visitors{grid-area:visitors}.a-left{grid-area:left}.a-sources{grid-area:sources}.a-products{grid-area:products}.a-countries{grid-area:countries}.a-emirates{grid-area:emirates}.a-devices{grid-area:devices}.a-pages{grid-area:products}.agrid:not(.show-pages)>.a-pages,.agrid.show-pages>.a-products{display:none}.agrid>.a-returning,.agrid>.a-devices{display:none}}
.agrid .list[hidden]{display:none!important}.gbar{width:28%}.gbar .fb{margin:0}.gnote{margin:6px 0 0;flex:none}.a-countries .ch,.a-emirates .ch{flex-wrap:wrap;gap:6px}.gseg button{padding:3px 7px}.gseg{max-width:100%;overflow-x:auto;scrollbar-width:none;flex-wrap:nowrap}.gseg::-webkit-scrollbar{display:none}.gseg button{flex:none;white-space:nowrap}.a-left .ch{gap:8px}.a-left .ch .btn{flex:none}.a-left .ch h2{min-width:0;flex:1 1 0;white-space:normal;overflow:visible}.a-countries .ch h2,.a-emirates .ch h2{flex:1 1 100%}@media (min-width:760px) and (max-width:1199px){.agrid table.mini{font-size:11.5px}.agrid table.mini th,.agrid table.mini td{padding:5px 3px;letter-spacing:0}}@media (min-width:760px){.gtab{table-layout:fixed;width:100%}.gtab .gbar{display:none}.gtab th:nth-child(2){width:64px}.gtab th:nth-child(4){width:52px}.gtab td:first-child{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}}
.dtab td b{font-weight:600}.dtab .fb{margin:4px 0 0;height:5px;max-width:140px}.a-devices{container-type:inline-size}.ptab td b{font-weight:600}.ptab .fb{margin:4px 0 0;height:5px;max-width:140px}.a-pages{container-type:inline-size}@container (max-width:380px){.ptab .fb{display:none}.ptab td{padding-top:5px;padding-bottom:5px}}.pswap{display:none}@media (min-width:760px){.pswap{display:flex;margin-left:auto;margin-right:6px}.a-products .ch,.a-pages .ch{gap:6px}.ptab td:first-child{max-width:0;width:50%}.ptab td b{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}}.card.big .pswap{display:none}.dstrip{display:none}@media (min-width:760px){.dstrip{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:6px;margin-top:8px;flex:none}.dstrip button{font:inherit;text-align:left;background:none;border:1px solid var(--line);border-radius:8px;padding:5px 8px;color:var(--ink);cursor:pointer;line-height:1.25}.dstrip button:hover{border-color:var(--gold)}.dstrip b{display:block;font-size:15px;font-variant-numeric:tabular-nums}.dstrip span{font-size:10.5px;letter-spacing:.06em;text-transform:uppercase;color:var(--muted)}}@media (min-width:760px) and (max-height:540px){.dstrip{display:none}}@container (max-width:380px){.dtab th:nth-child(2),.dtab td:nth-child(2),.dtab th:nth-child(3),.dtab td:nth-child(3),.dtab .fb{display:none}.dtab td{padding-top:4px;padding-bottom:4px}}
/* phone turned sideways (short screen): Analytics scrolls as a page and each section keeps a readable height */
@media (min-width:760px) and (max-height:540px){main.fit:has(>.db.an){display:block;overflow:auto}main.fit>.db.an{height:auto;min-height:0}.an .agrid{flex:none;height:auto;grid-template-columns:minmax(0,1fr) minmax(0,1fr);grid-template-rows:290px 290px 290px 230px 320px 360px;grid-template-areas:"funnel visitors" "products sources" "countries emirates" "devices devices" "pages pages" "left left"}.agrid>.a-devices{display:flex}.an .agrid>.a-pages,.an .agrid>.a-products{display:flex!important}.an .agrid .a-pages{grid-area:pages}.pswap{display:none}.tiles.at{grid-template-columns:repeat(4,1fr)}.tiles.at .tile:last-child{grid-column:auto}}
/* Analytics → Conversion: five reports, same look as the overview (laptop: one screen, 3 columns; phone: one section at a time) */
.aview a{cursor:pointer}.ctab td b,.mtab td b{font-weight:600}.c-prod,.c-multi,.c-camp{container-type:inline-size}.c-drop .fun li.fx table{margin-top:2px}.c-scroll .fun li{padding:3px 0}.c-camp .cn{display:block;font-size:11.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:180px}
@media (min-width:760px){.agrid.cv{grid-template-columns:minmax(0,1.3fr) minmax(0,1fr) minmax(0,1.15fr);grid-template-areas:"cprod cdrop ccamp" "cmulti cmulti cscroll"}
  .c-prod{grid-area:cprod}.c-drop{grid-area:cdrop}.c-camp{grid-area:ccamp}.c-multi{grid-area:cmulti}.c-scroll{grid-area:cscroll}.agrid.cv>.card{display:flex}}
@container (max-width:420px){.ctab th:nth-child(5),.ctab td:nth-child(5),.mtab th:nth-child(3),.mtab td:nth-child(3),.mtab th:nth-child(5),.mtab td:nth-child(5){display:none}}
@media (max-width:759px){.aview{display:flex;width:100%;padding:0;gap:0;border-radius:8px;overflow:hidden}.aview a{flex:1;text-align:center;font-size:13px;font-weight:600;padding:7px 0;border-radius:0;color:var(--ink)}.aview a.on{background:var(--gold);color:var(--gold-ink)}.aview a+a{border-left:1px solid var(--line)}
  .cvpick button:nth-child(n+4){border-bottom:0}.cvpick button:last-child{grid-column:span 2;border-right:0}}
@media (min-width:760px) and (max-height:540px){.an .agrid.cv{grid-template-columns:minmax(0,1fr) minmax(0,1fr);grid-template-rows:330px 330px 300px;grid-template-areas:"cprod cdrop" "ccamp cscroll" "cmulti cmulti"}}
/* phone: no zooming by itself (iPhone zooms into boxes under 16px, double-tap zooms); two-finger pinch still works */
html{-webkit-text-size-adjust:100%;text-size-adjust:100%}html,body{touch-action:manipulation;overflow-x:hidden}
@media (max-width:759px){input,select,textarea{font-size:16px!important}
  .an .pagehead,.pagehead.rg{display:flex;flex-wrap:wrap}.an .pagehead h1,.pagehead.rg h1{flex:none}.arange .seg{margin-left:auto}.arange input[type=date]{flex:1 1 35%;min-width:0;width:auto!important;padding:4px 6px}.arange .dmy{flex:1 1 35%;min-width:0}.arange .btn{flex:none}}
.tile .tn,.tile .mo,.lmob{display:none}.tile span .dk{display:inline;font:inherit;letter-spacing:inherit;color:inherit}
/* analytics: tap a box or section to open it bigger */
.zoom{flex:none;font:inherit;font-size:15px;line-height:1;border:1px solid var(--line);background:none;color:var(--muted);border-radius:6px;width:26px;height:26px;padding:0;cursor:pointer;order:9}.zoom:hover{color:var(--gold);border-color:var(--gold)}
.agrid>.card{position:relative}.agrid>.card>.ch{padding-right:32px}.agrid>.card>.ch h2{white-space:normal}.agrid>.card .zoom{position:absolute;top:12px;right:12px}.agrid>.card.big .zoom{top:16px;right:16px}.agrid>.card.big>.ch{padding-right:44px}
.an .tiles.at .tile{cursor:pointer}.an .tiles.at .tile:hover{border-color:var(--gold)}
.zback{position:fixed;inset:0;background:rgba(0,0,0,.62);z-index:59}
.agrid>.card.big{position:fixed!important;inset:max(12px,4vh) max(12px,6vw);z-index:60;display:flex!important;flex-direction:column;overflow:auto;font-size:15px;padding:18px 20px;max-width:1100px;margin:0 auto;box-shadow:0 10px 40px rgba(0,0,0,.5)}
.agrid>.card.big .ch h2{font-size:20px}.agrid>.card.big .zoom{color:var(--gold);border-color:var(--gold);font-size:18px;width:32px;height:32px}
.agrid>.card.big table.mini{font-size:15px!important}.agrid>.card.big table.mini th,.agrid>.card.big table.mini td{padding:8px 6px!important}.agrid>.card.big .gtab .gbar{display:table-cell!important}
.agrid>.card.big .list{overflow:visible;max-height:none;flex:none}.agrid>.card.big .chartbox{min-height:55vh}.agrid>.card.big .fl{font-size:15px}.agrid>.card.big .fb{height:10px}
.agrid>.card.big .leads .small,.agrid>.card.big .ltab td span{font-size:13.5px}.agrid>.card.big .rsum b{font-size:28px}
/* phone analytics: same layout as fomaxo.in */
@media (max-width:759px){
  .an .pagehead h1,.pagehead.rg h1{display:none}.an .pagehead,.pagehead.rg{gap:6px;margin-bottom:6px}.arange{display:flex;width:100%;gap:6px}
  .arange .seg{display:flex;width:100%;margin:0;padding:0;gap:0;border-radius:8px;overflow:hidden}.arange .seg a{flex:1;text-align:center;font-size:13px;font-weight:500;padding:7px 0;border-radius:0;color:var(--ink)}
  .arange .seg a.on{background:rgba(201,169,97,.16);color:var(--gold)}.arange .seg a+a{border-left:1px solid var(--line)}
  .arange input[type=date]{flex:1 1 0!important;padding:5px 8px!important;border-radius:8px}.arange .dmy{flex:1 1 0!important}.arange .btn{border-radius:8px;padding:6px 12px}
  .tiles.at{grid-template-columns:repeat(3,1fr);gap:6px;margin-bottom:6px}.tiles.at .tile{display:flex;flex-direction:column;padding:6px 9px;border-radius:10px}
  .tiles.at .tile span{order:-1;font-size:9.5px;letter-spacing:.07em;white-space:normal;line-height:1.25}.tiles.at .tile b{font-size:17px;margin:1px 0}
  .tiles.at .tile .tn{display:block;font-size:11px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .tiles.at .tile span .dk{display:none}.tiles.at .tile span .mo{display:inline;font:inherit;letter-spacing:inherit;color:inherit}
  .tiles.at .tile.hot b{color:var(--ok,#6fbf73)}.tiles.at .tile.ret{display:none}.tiles.at .tile.rev{grid-column:1/-1}
  .rsum{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;text-align:center;margin:2px 0 8px;flex:none}.rsum b{display:block;font-size:20px;font-weight:600}.rsum span{font-size:10.5px;letter-spacing:.06em;text-transform:uppercase;color:var(--muted)}
  .rtab td>b,.rtab td>span{display:block}.rtab td{vertical-align:top}.rtab td span{font-size:12px}
  .apick{display:grid;grid-template-columns:repeat(3,1fr);gap:0;border:1px solid var(--line);border-radius:10px;overflow:hidden;margin-bottom:8px}
  .apick button{border:0;border-radius:0;border-right:1px solid var(--line);border-bottom:1px solid var(--line);background:none;color:var(--ink);font-weight:500;font-size:12.5px;padding:8px 2px;white-space:nowrap}
  .apick button:nth-child(3n){border-right:0}.apick button:nth-child(n+10){border-bottom:0}.apick button[data-p=left]{grid-column:1/-1;border-right:0}.apick button[data-p=left]{white-space:normal;line-height:1.15}
  .apick button.on{background:rgba(201,169,97,.16);border-color:var(--line);color:var(--gold)}
  .a-left .ch .btn{background:var(--gold);border-color:var(--gold);color:var(--gold-ink)}
  .a-left .ldesk{display:none}.a-left .lmob{display:block}
  .ltab td{vertical-align:top;padding:9px 6px 9px 0;line-height:1.35}.ltab td:first-child{width:46%}.ltab td>*{display:block;overflow-wrap:anywhere}.ltab td b{font-size:13.5px}.ltab td a{color:var(--ink);text-decoration:none}
  .tiles.at .tile{align-items:center;text-align:center}.tiles.at .tile .tn{max-width:100%}
  .a-left .ch{justify-content:center}.a-left .ch h2{flex:none}.a-left>p.small{text-align:center}.ltab th,.ltab td{text-align:center}.ltab td{padding:9px 4px}.ltab th:first-child,.ltab td:first-child{padding-left:0}.ltab th:last-child,.ltab td:last-child{padding-right:0}
  .ltab td span{font-size:12px}.ltab .lm-s{margin-top:3px}.ltab .lm-s .tag{display:inline;font-size:10.5px;padding:0 6px}.ltab th{font-size:10.5px;letter-spacing:.08em;text-transform:uppercase}}
/* date boxes always read and type as day/month/year (the browser's own date box follows the computer's language, e.g. month first on a US Mac, so typing 01/10 would mean 10 January):
   people type into a plain dd/mm/yyyy box; the calendar button still opens the browser's own date picker, and the real date box behind it is what the form sends */
.dmy{position:relative;display:block;min-width:0}.arange .dmy{display:inline-block}.dmy>input.dt{width:100%!important;padding-right:30px!important;font-variant-numeric:tabular-nums}
.dmy>input[type=date]{position:absolute!important;right:0;top:0;bottom:0;width:30px!important;min-width:0!important;height:100%;padding:0!important;margin:0;border:0!important;opacity:0;cursor:pointer;flex:none!important}.dmy>input[type=date]::-webkit-calendar-picker-indicator{position:absolute;inset:0;width:auto;height:auto;opacity:0;cursor:pointer}
.arange .dmy>input.dt{padding:4px 7px;font-size:12.5px;width:118px!important}@media (max-width:759px){.arange .dmy>input.dt{width:100%!important;padding:5px 30px 5px 8px!important;border-radius:8px}}.dmy>svg{position:absolute;right:9px;top:50%;width:14px;height:14px;transform:translateY(-50%);pointer-events:none;color:var(--muted)}
.exsum{margin:14px 0 8px;display:flex;align-items:baseline;gap:10px;flex-wrap:wrap}.exsum small{font-family:Manrope,sans-serif;font-size:14px;letter-spacing:0;text-transform:none;color:var(--muted)}@media (max-width:759px){.tile.wr span{white-space:normal;line-height:1.25}}.rep tr[data-href]{cursor:pointer}.rep tr[data-href]:hover td{background:rgba(201,169,97,.08)}a.stat{color:inherit;text-decoration:none}a.stat.on{border-color:var(--gold)}@media (hover:hover){a.stat:hover{border-color:var(--gold)}}.rgraph{margin:0 0 12px;padding:12px 16px}.rgraph .chartbox{display:flex;height:180px}.rgraph .sub{display:flex;justify-content:space-between;gap:10px;margin:6px 0 0;font-size:12.5px;color:var(--muted)}.rgraph .tot{color:var(--ink)}.rgraph .ch{display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap}.gdates{font-size:13px;color:var(--muted)}.gx{margin-left:10px;font-size:18px;line-height:1;text-decoration:none;color:var(--muted)}.gdates b{color:var(--ink);font-weight:600;font-variant-numeric:tabular-nums}.rgbar{display:flex;justify-content:flex-end;margin:0 0 10px}.rgbar .arange,.pagehead.rg .arange{justify-content:flex-end}@media (max-width:759px){.rgbar{margin-bottom:8px}.rgbar .arange{width:100%}}
/* app layout: header and tabs stay put, the page never scrolls, long lists scroll inside their own panel */
html,body{height:100%}
body{display:flex;flex-direction:column;height:100vh;height:100dvh;overflow:hidden}
.top,.tabs{flex:none}
main.wrap{flex:1 1 auto;min-height:0;overflow:auto;width:100%;overscroll-behavior:contain}
main.fit{display:flex;flex-direction:column;overflow:hidden}
main.fit>*,.fitform>*{flex:none;min-width:0}
main.fit>.fill,main.fit>.fitform,main.fit>.db,main.fit>.cgrid,main.fit>.rmob,main.fit>.rdesk,.rmob>.fill,.rdesk>.rgrid,.fitform>.fill{flex:1 1 auto;min-height:0}
.fitform{display:flex;flex-direction:column;margin:0}
.fill{overflow:auto;overscroll-behavior:contain;border:1px solid var(--line);border-radius:10px;background:var(--panel)}
.fill>table{border:0;border-radius:0;overflow:visible}.fill>table tr:last-child td{border-bottom:0}
.fill th{position:sticky;top:0;background:var(--panel);z-index:1}
.fill>.after{padding:8px 12px 10px;margin:0}.fill .pager{margin:8px 12px 0}
.pagehead{display:flex;align-items:center;justify-content:space-between;gap:10px;margin:0 0 10px}.pagehead h1{margin:0}
.num,.stat b{font-variant-numeric:tabular-nums}
@media (max-width:759px){
  .wrap{padding:10px 12px 12px}h1{font-size:18px;margin-bottom:8px}
  .fill{border:0;background:none;border-radius:0}.fill>table tr.row:last-child{margin-bottom:0}.fill>.after{padding:6px 2px 4px}.fill .pager{margin:6px 0 0}
  .filters{gap:6px 8px;padding:10px 12px}.filters label{font-size:10.5px;margin-bottom:2px}
  .stats{display:grid;grid-template-columns:repeat(4,1fr);gap:6px;margin:8px 0}.stat{padding:6px 8px}.stat b{font-size:14.5px}.stat span{font-size:9.5px;letter-spacing:.04em;display:block;line-height:1.25}
  .g3{grid-template-columns:1fr 1fr}.g3>div:last-child{grid-column:1/-1}
  .rstats{grid-template-columns:1fr 1fr}.rstats .stat{min-width:0}.rstats .stat b{font-size:16px;white-space:nowrap}.rstats .stat span{font-size:10px}
  .settings{grid-template-columns:1fr}
  .top{padding:7px 12px}.brand{font-size:18px}
  .filters input,.filters select{padding:6px 9px}.filters .acts .btn{padding:8px 12px;font-size:11.5px}
  .filters label.phx{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)}
  .stat b{white-space:nowrap;font-size:13px}
  .olist tr.row{display:grid;grid-template-columns:minmax(0,1fr) auto;grid-template-areas:"no tot" "date st" "cust pay" "items items";gap:1px 10px;padding:8px 12px;margin-bottom:8px}
  .olist td{padding:0}.olist td:nth-child(1){grid-area:no}.olist td:nth-child(2){grid-area:date;color:var(--muted);font-size:12.5px}.olist td:nth-child(3){grid-area:cust;font-size:13.5px}.olist td:nth-child(3) div{display:inline;margin-left:6px}
  .olist td:nth-child(4){grid-area:items;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--muted);font-size:12px}.olist td:nth-child(5){grid-area:pay;text-align:right;font-size:13px}.olist td:nth-child(6){grid-area:st;text-align:right}.olist td:nth-child(7){grid-area:tot;font-weight:600}
  .olist.acts tr.row{grid-template-areas:"no tot" "date st" "cust pay" "items items" "acts acts"}.olist td:nth-child(8){grid-area:acts}.olist td:nth-child(8) .oacts{margin-top:5px}
  .stock-help{font-size:12px}.lowlvl{padding:8px 12px}.lowlvl label{font-size:13px}.lowlvl input{padding:6px 9px;max-width:72px}
  .stock tr.row{padding:8px 4px 10px;margin-bottom:8px;gap:2px 10px}.stock td{padding:0 10px}.stock input{padding:6px 9px}label.mini{font-size:10.5px;margin:2px 0}
}
.mtop .mmin input{text-align:center}.mtop{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:0 0 4px}.mmin{display:flex;align-items:center;gap:8px;padding:8px 12px;margin:0;border-color:var(--gold)}.mmin label{margin:0;font-size:13.5px;font-weight:600;text-transform:none;letter-spacing:0;color:var(--ink)}.mmin input{width:72px;padding:6px 9px;font-weight:700;text-align:center}.mmin span{font-size:13.5px;font-weight:600}
.mq{display:flex;gap:6px;flex:1 1 260px;margin:0}.mq input{flex:1;min-width:0;padding:7px 10px}.mlist td.mo span,.mlist td.mv span{display:none}.mlist .md{white-space:nowrap;color:var(--muted)}.mtag{background:var(--gold);color:var(--gold-ink);border-color:var(--gold)}.mcard{padding:10px 14px;margin:0 0 10px}.mcard dl{margin:0}
@media (max-width:759px){.mmin{flex:1 1 100%;padding:7px 10px;gap:6px}.mmin label,.mmin span{font-size:12.5px}.mmin input{width:58px;padding:5px 6px}.mmin .btn{margin-left:auto}.mq{flex-basis:100%}
  .mlist tr.row{display:grid;grid-template-columns:minmax(0,1fr) auto;grid-template-areas:"mn ms" "ma mo" "md mv";gap:2px 10px;padding:8px 12px;margin-bottom:8px}.mlist td{padding:0}
  .mlist .mn{grid-area:mn}.mlist .ms{grid-area:ms}.mlist .ma{grid-area:ma;color:var(--muted)}.mlist .ma div{display:inline;margin-left:4px}.mlist .ma div::before{content:"· "}.mlist .mo,.mlist .mv{align-self:end}.mlist .mo{grid-area:mo}.mlist .mo b{font-weight:600}.mlist .md{grid-area:md;font-size:11.5px}.mlist .mv{grid-area:mv;font-size:12px;color:var(--muted)}.mlist td.mo span,.mlist td.mv span{display:inline}
  .mcard{padding:8px 12px}}
tr.row[hidden]{display:none!important}.hacts{display:flex;gap:8px;align-items:center}.tsearch{flex:1 1 auto;max-width:320px;margin-left:auto;padding:7px 11px}.pagehead .tsearch+.btn{flex:none}.mmin{flex-wrap:wrap}.mmin input.amt{width:96px}
.sp{display:flex;align-items:center;gap:10px}.sth{width:40px;height:40px;flex:none;border-radius:7px;object-fit:cover;background:var(--line)}
.savebar{display:flex;align-items:center;gap:14px}.savebar .stock-help{flex:1;margin:0;font-size:12px;line-height:1.45}.savebar .btn{flex:none}
.rseg{flex-wrap:wrap}.stars{color:var(--gold);letter-spacing:.06em;white-space:nowrap}.stars i{font-style:normal;color:var(--line)}
.rfilter .vcount{margin:0}.rfilter .vcount .em{font-size:12.5px;padding:4px 11px}.rfilter{margin:0 0 8px;display:flex;gap:8px;align-items:center;flex-wrap:wrap}.rfilter select{max-width:240px;width:auto}.rfilter input{flex:1 1 220px;min-width:0;max-width:420px;padding:7px 10px}.rvlist{list-style:none;margin:0;padding:0}.rv{padding:10px 14px;border-bottom:1px solid var(--line);display:grid;gap:3px}.rv:last-child{border-bottom:0}.rv.off{opacity:.55}
.emo{display:flex;flex-wrap:wrap;gap:4px;margin:6px 0 2px}.emo button{font:18px/1 "Apple Color Emoji","Segoe UI Emoji","Noto Color Emoji",sans-serif;background:var(--panel);border:1px solid var(--line);border-radius:8px;padding:5px 6px;cursor:pointer;min-width:34px}.emo button:hover{border-color:var(--gold)}.rrep{border-left:3px solid var(--gold);background:color-mix(in srgb,var(--gold) 8%,transparent);padding:6px 10px;border-radius:0 6px 6px 0;margin:2px 0 4px}.rrep b{display:block;font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:var(--gold)}.rrep p{margin:2px 0 0;font-size:13px}.racts{display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin-top:2px}.racts>form{margin:0 !important;display:flex}.racts .btn.sm,.racts summary.btn{margin:0 !important;padding:7px 12px;line-height:1.2;box-sizing:border-box;height:32px;display:inline-flex;align-items:center}.rrf{margin:0}.rrf[open]{flex:1 1 100%;order:2}.rrf>summary{list-style:none;display:inline-block;cursor:pointer}.rrf>summary::-webkit-details-marker{display:none}.rrf[open]>summary{display:none}.rrf textarea{width:100%;box-sizing:border-box;font:inherit;font-size:14px;padding:8px 10px;border:1px solid var(--line);border-radius:8px;background:var(--bg);color:var(--ink);resize:vertical}.rrb{display:flex;gap:8px;margin-top:6px}

.rv p{margin:2px 0;white-space:normal}.rv form{margin:4px 0 0}.rph{display:flex;gap:6px}.rph img{width:56px;height:56px;object-fit:cover;border-radius:6px;display:block}
.btn.danger{background:#a33a2c;border-color:#a33a2c;color:#fff}.dist span{margin-right:8px;white-space:nowrap}.dist b{color:var(--ink);font-weight:600}.rprod .ph,.rppl .ph{display:none}
@media (max-width:759px){.hacts{gap:6px}.hacts .btn{padding:6px 9px;font-size:10.5px}.hacts .vw{display:none}.pagehead{flex-wrap:wrap}.tsearch{order:3;flex-basis:100%;max-width:none;margin:0}
  .savebar{flex-direction:column;align-items:stretch;gap:6px;padding-top:6px}.savebar .stock-help{font-size:11px;line-height:1.35}.sth{width:34px;height:34px}
  .rseg{order:3;flex-basis:100%}.rv{padding:10px 12px;border:1px solid var(--line);border-radius:10px;background:var(--panel);margin-bottom:8px}
  .rprod tr.row,.rppl tr.row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:2px 10px;padding:8px 12px}.rprod td,.rppl td{padding:0}.rprod td.dist,.rppl td:nth-child(4){grid-column:1/-1}.rprod .ph,.rppl .ph{display:inline}}
.cgrid{display:grid;gap:10px;flex:1 1 auto;min-height:0;grid-template-columns:minmax(260px,1fr) 2fr;grid-template-rows:minmax(0,3fr) minmax(0,2fr);grid-template-areas:"det ord" "det rev"}
.cgrid .card{margin:0;display:flex;flex-direction:column;min-height:0;padding:12px 14px}.cgrid h2{font-size:14px;margin:0 0 8px}.cdet{grid-area:det;overflow:auto}.cord{grid-area:ord}.crev{grid-area:rev}.cdet h2+dl{margin-bottom:14px}.cdet dl{margin:0}
.cscroll{overflow:auto;flex:1 1 auto;min-height:0;margin:0 -14px -12px}.cscroll table{border:0;border-radius:0}.cscroll .rvlist{padding:0}.cscroll>p{padding:0 14px}
.vlist{list-style:none;margin:8px 0 0;padding:0;font-size:12.5px}.vlist li{display:flex;justify-content:space-between;gap:8px;padding:4px 0;border-top:1px solid var(--line)}.cstats{grid-template-columns:repeat(6,1fr)}.cstats .stat{flex:1}
@media (max-width:759px){main.fit .cgrid{display:block;overflow:auto}.cgrid .card{margin-bottom:10px}.cscroll{overflow:visible;margin:0 -14px -12px}.cstats{grid-template-columns:repeat(3,1fr)}.cdet{overflow:visible}}
.rgrid{display:grid;gap:12px;grid-template-columns:minmax(0,2fr) minmax(0,1.25fr);grid-template-rows:minmax(0,1fr) minmax(0,1fr);grid-template-areas:"list prod" "list ppl"}
.rgrid .card{margin:0;display:flex;flex-direction:column;min-height:0;padding:0;overflow:hidden}.rgrid h2{font-size:13px;letter-spacing:.12em;margin:0;color:var(--gold)}.rgrid .card>h2,.rgrid .ch{padding:12px 16px;border-bottom:1px solid var(--line);margin:0}
.rgrid .cscroll{margin:0}.rlist{grid-area:list}.rprodc{grid-area:prod}.rpplc{grid-area:ppl}.rnote{padding:10px 16px;margin:0;border-bottom:1px solid var(--line)}.rgrid .empty{text-align:center;padding:24px 12px;margin:0}
.rgrid table{border:0;border-radius:0}.rgrid tr.on td{background:rgba(143,107,55,.12)}.rmin{display:flex;align-items:center;gap:6px;margin:0;font-size:13px}.rmin label{margin:0;font-size:13px;text-transform:none;letter-spacing:0;color:var(--muted)}.rmin input{width:58px;padding:5px 8px}.rpplc .ch{display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap}
.rdesk .rfilter input{max-width:none}.rmob,.rdesk{display:flex;flex-direction:column}.rmob>*,.rdesk>*{flex:none;min-width:0}
.rmob .rseg{display:flex;justify-content:center}.rmob .rseg a{flex:1 1 0;text-align:center}
@media (min-width:760px){.rmob{display:none!important}}@media (max-width:759px){.rdesk{display:none!important}}
.stats.up .stat{display:flex;flex-direction:column}.stats.up .stat span{display:block;margin-bottom:2px}.stats.up .stat b{margin-top:auto}.settings{display:grid;gap:14px;max-width:900px}.fill.rfill{border:0;background:none;border-radius:0}.fill.rfill>table{border:1px solid var(--line);border-radius:10px}.rfill>h2:first-child{margin-top:0}@media (min-width:860px){.settings{grid-template-columns:1fr 1fr;align-items:start}}
CSS;
  echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">'
     . '<title>' . h($title) . ' — FOMAXO Admin</title>'
     . '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cinzel:wght@500;600&family=Manrope:wght@400;500;600;700&display=swap">'
     . "<style>$css</style></head><body>"
     . '<header class="top"><a class="brand" href="./">FOMAXO<small>ADMIN</small></a>'
     . (!empty($_SESSION['admin']) ? '<div class="hacts"><a class="btn line sm" href="../" target="_blank" rel="noopener"><span class="vw">View </span>website ↗</a><form method="post" action="./?logout=1" style="margin:0"><input type="hidden" name="csrf" value="' . h($_SESSION['csrf']) . '"><button class="btn line sm">Sign out</button></form></div>' : '')
     . '</header>'
     . (!empty($_SESSION['admin']) ? '<div class="tabsw"><a class="tnav" data-d="-1" aria-label="Previous page" hidden>‹</a><nav class="tabs">' . implode('', array_map(fn($t) => '<a href="' . $t[1] . '"' . ($t[2] ? ' class="on"' : '') . '>' . $t[0] . '</a>',
         [['Home', './', !$_GET], ['Products', './?products=1', isset($_GET['products'])], ['Stock', './?stock=1', isset($_GET['stock'])],
          ['Orders', './?orders=1', !isset($_GET['products']) && (bool)array_intersect_key($_GET, array_flip(['orders', 'o', 'q', 'status', 'pay', 'from', 'to', 'p']))],
          ['Coupons', './?coupons=1', isset($_GET['coupons'])], ['Offer', './?offer=1', isset($_GET['offer'])],
          ['Reviews', './?reviews=1', isset($_GET['reviews'])], ['Analytics', './?analytics=1', isset($_GET['analytics'])], ['Expenses', './?expenses=1', isset($_GET['expenses'])], ['Reports', './?reports=1', isset($_GET['reports'])],
          ['Members', './?members=1', isset($_GET['members'])], ['Settings', './?settings=1', isset($_GET['settings'])]])) . '</nav><a class="tnav" data-d="1" aria-label="Next page" hidden>›</a></div>' : '')
     . '<main class="wrap' . ($wide ? '' : ' narrow') . ($fit ? ' fit' : '') . '">' . $body . '</main>'
     . '<script>document.querySelectorAll("input[type=date]").forEach(function(i){var w=document.createElement("span");w.className="dmy";i.parentNode.insertBefore(w,i);var t=document.createElement("input");t.type="text";t.className="dt";t.inputMode="numeric";t.autocomplete="off";t.placeholder="dd/mm/yyyy";t.maxLength=10;if(i.id){t.id=i.id;i.removeAttribute("id")}if(i.required){t.required=true;i.required=false}var l=i.getAttribute("aria-label");if(l)t.setAttribute("aria-label",l);i.tabIndex=-1;i.setAttribute("aria-hidden","true");w.appendChild(t);w.appendChild(i);w.insertAdjacentHTML("beforeend","<svg viewBox=\\"0 0 24 24\\" aria-hidden=\\"true\\"><path fill=\\"none\\" stroke=\\"currentColor\\" stroke-width=\\"2\\" d=\\"M4 6h16v14H4zM4 10h16M8 3v4M16 3v4\\"/></svg>");var show=function(){var v=(i.value||"").split("-");t.value=v.length===3?v[2]+"/"+v[1]+"/"+v[0]:""};show();i.addEventListener("change",show);i.addEventListener("input",show);i.addEventListener("click",function(){try{i.showPicker()}catch(e){}});t.addEventListener("input",function(e){if(!/^delete/.test(e.inputType||"")&&!/^\\d{1,2}\\/\\d{1,2}\\/\\d{0,4}$/.test(t.value)){var d=t.value.replace(/[^0-9]/g,"").slice(0,8),o=d.slice(0,2);if(d.length>2)o+="/"+d.slice(2,4);if(d.length>4)o+="/"+d.slice(4);t.value=o}var m=t.value.match(/^(\\d{1,2})\\/(\\d{1,2})\\/(\\d{4})$/),ok=false;if(m){var dd=+m[1],mm=+m[2],yy=+m[3],x=new Date(yy,mm-1,dd);ok=x.getFullYear()===yy&&x.getMonth()===mm-1&&x.getDate()===dd}i.value=ok?m[3]+"-"+("0"+m[2]).slice(-2)+"-"+("0"+m[1]).slice(-2):"";t.setCustomValidity(t.value===""||ok?"":"Type the date as dd/mm/yyyy")});t.addEventListener("blur",function(){if(i.value)show()})});' . (!empty($_SESSION['admin']) ? 'try{localStorage.setItem("fomaxo_notrack","1")}catch(e){}' : '') . 'document.querySelectorAll(".tsearch").forEach(function(i){i.addEventListener("input",function(){var q=i.value.trim().toLowerCase();document.querySelectorAll("main table tr.row").forEach(function(r){r.hidden=q!==""&&r.innerText.toLowerCase().indexOf(q)<0})})});var t=document.querySelector(".tabs a.on");if(t&&t.parentNode.scrollWidth>t.parentNode.clientWidth)t.parentNode.scrollLeft=t.offsetLeft-(t.parentNode.clientWidth-t.offsetWidth)/2;if(t){var go=function(d){var a=d<0?t.previousElementSibling:t.nextElementSibling;return a&&a.href};document.querySelectorAll(".tnav").forEach(function(b){var u=go(+b.dataset.d);if(u){b.href=u;b.hidden=false}});var sx=null,sy,st;function hscroll(e){for(;e&&e!==document.body;e=e.parentElement){var o=getComputedStyle(e).overflowX;if((o=="auto"||o=="scroll")&&e.scrollWidth>e.clientWidth+2)return true}return false}document.addEventListener("touchstart",function(e){var g=e.target;sx=null;if(e.touches.length!==1||innerWidth>759||g.closest("input,textarea,select,.tabsw,[contenteditable]")||document.body.classList.contains("zoomed")||hscroll(g))return;sx=e.touches[0].clientX;sy=e.touches[0].clientY;st=Date.now()},{passive:true});document.addEventListener("touchend",function(e){if(sx===null)return;var c=e.changedTouches[0],dx=c.clientX-sx,dy=c.clientY-sy;sx=null;if(Math.abs(dx)<70||Math.abs(dx)<2*Math.abs(dy)||Date.now()-st>800)return;var u=go(dx<0?1:-1);if(!u)return;var m=document.querySelector("main");if(m){m.style.transition="transform .16s,opacity .16s";m.style.transform="translateX("+(dx<0?-40:40)+"px)";m.style.opacity=".35"}location.href=u},{passive:true});addEventListener("pageshow",function(){var m=document.querySelector("main");if(m){m.style.transform="";m.style.opacity=""}})}</script></body></html>';
  exit;
}
/* bar graph for the Dashboard and Analytics: bars stretch to fill the box; the amounts and dates are normal text so they stay readable */
function fx_graph($pts, $aria, $hidden = false, $fmt = 'money', $line = false) {   // $line: a line with a dot per point instead of bars
  $W = 600; $H = 100; $n = count($pts); $slot = $W / $n; $bw = min(44, max(6, round($slot * .6, 1)));
  $max = max(array_column($pts, 'v')); $out = ''; $lab = ''; $path = ''; $dots = '';
  foreach ($pts as $i => $p) {
    $h = $max > 0 ? round(($H - 2) * $p['v'] / $max, 1) : 0;
    $cx = round($i * $slot + $slot / 2, 1);
    if ($line) { $path .= ($i ? ' L' : 'M') . $cx . ' ' . ($H - $h); $dots .= 'M' . $cx . ' ' . ($H - $h) . 'h0'; }
    elseif ($h > 0) $out .= '<rect class="bar" x="' . round($i * $slot + ($slot - $bw) / 2, 1) . '" y="' . ($H - $h) . '" width="' . $bw . '" height="' . $h . '"/>';
    $out .= '<rect class="hit" x="' . round($i * $slot, 1) . '" y="0" width="' . round($slot, 1) . '" height="' . $H . '" data-t="' . h($p['t']) . '"><title>' . h($p['t']) . '</title></rect>';
    if ($n <= 12 || in_array($i, [0, intdiv($n, 2), $n - 1], true)) $lab .= '<span>' . h($p['l']) . '</span>';
  }
  if ($line) $out = '<path class="ln" d="' . $path . '"/><path class="dots" d="' . $dots . '"/>' . $out;
  return '<div class="graph"' . ($hidden ? ' hidden' : '') . '><div class="cmax">' . h($fmt($max)) . '</div>'
    . '<svg class="chart" viewBox="0 0 ' . $W . ' ' . $H . '" preserveAspectRatio="none" role="img" aria-label="' . h($aria) . '">'
    . '<line class="grid" x1="0" x2="' . $W . '" y1="1" y2="1"/>' . $out . '<line class="axis" x1="0" x2="' . $W . '" y1="' . $H . '" y2="' . $H . '"/></svg>'
    . '<div class="clab' . ($n <= 12 ? '' : ' ends') . '"' . ($n <= 12 ? ' style="grid-template-columns:repeat(' . $n . ',1fr)"' : '') . '>' . $lab . '</div></div>';
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
      fomaxo_mail(fomaxo_orders_email($STORE_EMAIL), 'FOMAXO admin password link', "Someone asked to set the password for the FOMAXO back office.\n\nOpen this link within one hour to choose a new password:\n$link\n\nIf this was not you, ignore this email. Your password stays the same.",
            "From: FOMAXO <mail@fomaxo.com>\r\nContent-Type: text/plain; charset=UTF-8");
    }
    $sent = true;
  }
  page('Forgot password', '<h1>Forgot password</h1>'
    . ($sent ? '<p class="msg ok">A link has been sent to the store email. It works for one hour.</p>'
             : '<form class="card" method="post">' . csrf_field() . '<p style="margin-top:0">We will email a link to set a new password to the store inbox (' . h(preg_replace('/(?<=.).(?=[^@]*@)/', '•', fomaxo_orders_email($STORE_EMAIL))) . ').</p><button class="btn">Email me a link</button></form>')
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
  /* one-tap buttons send "quick" and go back to the list they came from */
  $quick = (string)($_POST['quick'] ?? '');
  parse_str((string)($_POST['back'] ?? ''), $back);
  $back = $quick !== '' && $back ? array_intersect_key($back, array_flip(['orders', 'q', 'status', 'pay', 'from', 'to', 'em', 'p'])) : ['o' => $_POST['order']];
  if (!csrf_ok()) { flash('Please try again.'); go($back); }
  if ($quick !== '') {
    $s = $pdo->prepare('SELECT status, payment, admin_note FROM fx_orders WHERE order_no = ?'); $s->execute([(string)$_POST['order']]); $cur = $s->fetch();
    $to = ['paid' => 'Paid', 'delivered' => 'Delivered', 'pending' => 'Paid', 'cancel' => 'Cancelled', 'refund' => 'Refunded'][$quick] ?? '';
    if (!$cur || $to === '' || !in_array($quick, fx_order_actions($cur), true)) { flash('That order has already changed. Please check it again.'); go($back); }
    $_POST['status'] = $to; $_POST['admin_note'] = (string)$cur['admin_note'];
  }
  $st = (string)($_POST['status'] ?? '');
  /* "Undelivered" puts the order back to pending: Paid if the money is in (card, or cash already marked paid), else Unpaid */
  if ($st === 'Undelivered') {
    $s = $pdo->prepare('SELECT payment, paid_at, delivered_at, status FROM fx_orders WHERE order_no = ?'); $s->execute([(string)$_POST['order']]); $u = $s->fetch();
    /* a cash order counts as paid at delivery, so it goes back to Unpaid unless it was marked paid before it was delivered */
    $paidBefore = $u && $u['paid_at'] && ($u['status'] !== 'Delivered' || ($u['delivered_at'] && strtotime($u['paid_at']) < strtotime($u['delivered_at'])));
    $st = $u && ($u['payment'] !== 'Cash on delivery' || $paidBefore) ? 'Paid' : 'New';
    if ($st === 'New') $pdo->prepare('UPDATE fx_orders SET paid_at = NULL WHERE order_no = ?')->execute([(string)$_POST['order']]);
  }
  if (!in_array($st, FX_STATUSES, true)) { flash('Unknown status.'); go(['o' => $_POST['order']]); }
  $s = $pdo->prepare('SELECT status FROM fx_orders WHERE order_no = ?'); $s->execute([(string)$_POST['order']]); $was = $s->fetchColumn();
  $pdo->prepare("UPDATE fx_orders SET status = ?, admin_note = ?, updated_at = NOW(),
                 paid_at = CASE WHEN ? IN ('Paid', 'Delivered') AND paid_at IS NULL THEN NOW() ELSE paid_at END WHERE order_no = ?")
      ->execute([$st, mb_substr(trim((string)($_POST['admin_note'] ?? '')), 0, 2000), $st, (string)$_POST['order']]);
  if ($st !== $was) try { $pdo->prepare("UPDATE fx_orders SET delivered_at = " . ($st === 'Delivered' ? 'NOW()' : 'NULL') . " WHERE order_no = ?")->execute([(string)$_POST['order']]); } catch (Throwable $e) {}
  /* stock: a cancelled or refunded order goes back into stock; taking it out of Cancelled or Refunded takes it out again */
  $msg = $quick !== '' ? $_POST['order'] . ' is now ' . ['paid' => 'paid', 'delivered' => 'delivered', 'pending' => 'pending again', 'cancel' => 'cancelled', 'refund' => 'refunded'][$quick] . '.' : 'Saved.';
  if (in_array($st, ['Cancelled', 'Refunded'], true) && !in_array($was, ['Cancelled', 'Refunded'], true) && fomaxo_stock_move((string)$_POST['order'], true)) $msg .= ' The items are back in stock.';
  if (in_array($st, ['New', 'Paid', 'Delivered'], true) && in_array($was, ['Cancelled', 'Refunded', 'Awaiting payment'], true) && fomaxo_stock_move((string)$_POST['order'])) $msg .= ' The items were taken out of stock.';
  flash($msg, true); go($back);
}

/* an order is Pending until it is delivered; cash orders are Unpaid (status New) until marked paid or delivered */
function fx_tags($o) {
  $st = $o['status'];
  $d = in_array($st, ['New', 'Paid'], true) ? '<span class="tag s-New">Pending</span>' : '<span class="tag s-' . h(strtok($st, ' ')) . '">' . h($st) . '</span>';
  $p = in_array($st, ['New', 'Paid', 'Delivered'], true) ? ($st === 'New' ? '<span class="tag p-Unpaid">Unpaid</span>' : '<span class="tag p-Paid">Paid</span>') : '';
  $days = in_array($st, ['New', 'Paid'], true) ? (int)floor((time() - strtotime($o['created_at'])) / 86400) : 0;
  return '<span class="tags">' . $d . $p . '</span>' . ($days >= 1 ? '<small class="wait' . ($days >= 3 ? ' late' : '') . '">Waiting ' . $days . ' day' . ($days > 1 ? 's' : '') . '</small>' : '');
}
/* Ordered → Paid → Delivered, with the date of each step (cancelled and refunded orders end in red) */
function fx_tracker($o) {
  $d = fn($t) => $t ? h(date('d M, H:i', strtotime($t))) : '';
  $steps = [['Ordered', $o['created_at']], ['Paid', $o['paid_at'] ?? null], ['Delivered', $o['delivered_at'] ?? null]];
  if (in_array($o['status'], ['Cancelled', 'Refunded'], true)) $steps[] = [$o['status'], $o['updated_at'] ?? null, 'bad'];
  if ($o['status'] === 'Awaiting payment') $steps[1][0] = 'Card not paid';
  $out = '';
  foreach ($steps as $i => $s) {
    $done = $s[1] || $i === 0 || isset($s[2]) || ($i === 1 && in_array($o['status'], ['Paid', 'Delivered'], true)) || ($i === 2 && $o['status'] === 'Delivered');
    $out .= '<li class="' . ($done ? 'done' : '') . (isset($s[2]) ? ' bad' : '') . '"><i>' . ($done ? (isset($s[2]) ? '×' : '✓') : $i + 1) . '</i><b>' . h($s[0]) . '</b><span>' . ($done ? $d($s[1]) : 'Not yet') . '</span></li>';
  }
  return '<ol class="track">' . $out . '</ol>';
}
/* which one-tap buttons an order gets: Paid only for cash orders not yet paid */
function fx_order_actions($o) {
  $cod = $o['payment'] === 'Cash on delivery';
  return ['New' => $cod ? ['paid', 'delivered', 'cancel'] : ['delivered', 'cancel'], 'Paid' => $cod ? ['delivered', 'cancel'] : ['refund', 'delivered', 'cancel'],
          'Delivered' => $cod ? [] : ['refund']][$o['status']] ?? [];
}
/* always three buttons: Paid (cash) or Refund (card), Mark delivered, Cancel order; steps already done stay lit, the rest are greyed out */
function fx_order_buttons($o, $back = '', $cls = '') {
  if (!in_array($o['status'], ['New', 'Paid', 'Delivered'], true)) return '';
  $cod = $o['payment'] === 'Cash on delivery';
  $label = ['paid' => 'Paid', 'delivered' => '✓ Mark delivered', 'cancel' => 'Cancel order', 'refund' => 'Refund'];
  $doneLabel = ['paid' => '✓ Paid', 'delivered' => '✓ Delivered'];
  $done = ['paid' => $o['status'] !== 'New', 'delivered' => $o['status'] === 'Delivered'];
  $ask = ['cancel' => 'Cancel order ' . $o['order_no'] . '? The items go back into stock.',
          'refund' => 'Mark order ' . $o['order_no'] . ' as refunded? The items go back into stock. This only records the refund here; it does not send money back. Card refunds are done in Ziina.'];
  $ok = fx_order_actions($o);
  $out = '';
  foreach ([$cod ? 'paid' : 'refund', 'delivered', 'cancel'] as $a) {
    $on = in_array($a, $ok, true); $d = !$on && !empty($done[$a]);
    $out .= '<form method="post"' . ($on && isset($ask[$a]) ? ' onsubmit="return confirm(' . h(json_encode($ask[$a], JSON_UNESCAPED_UNICODE)) . ')"' : '') . '>' . csrf_field()
          . '<input type="hidden" name="order" value="' . h($o['order_no']) . '"><input type="hidden" name="quick" value="' . $a . '">'
          . ($back !== '' ? '<input type="hidden" name="back" value="' . h($back) . '">' : '')
          . '<button class="a-' . $a . ($d ? ' done' : '') . '"' . ($on ? '' : ' disabled') . '>' . h($d ? $doneLabel[$a] : $label[$a]) . '</button></form>';
  }
  return '<div class="oacts' . ($cls ? ' ' . $cls : '') . '" onclick="event.stopPropagation()">' . $out . '</div>';
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
    /* photos: put them in the chosen order, remove ticked ones, add new uploads at the end */
    $imgs = array_values(array_filter((array)($p['images'] ?? []), 'is_string'));
    $ord = array_values(array_intersect(array_map('strval', (array)($_POST['photo_order'] ?? [])), $imgs));   // the order set with the ‹ › buttons
    if ($ord) $imgs = array_values(array_unique(array_merge($ord, $imgs)));
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
    page('Products', '<div class="pagehead"><h1>Products</h1><input type="search" class="tsearch" placeholder="Search product" aria-label="Search product"><a class="btn" href="' . h(self_url(['products' => 1, 'p' => 'new'])) . '">Add product</a></div>' . flash()
      . '<div class="fill">' . ($rows ? '<table class="prods"><thead><tr><th></th><th>Product</th><th>Website</th><th></th></tr></thead><tbody>' . $tr . '</tbody></table>'
               : '<p class="msg bad">The product list could not be loaded from the database.</p>')
      . '<p class="muted small after">New sizes show up on the Stock page by themselves. Changing a price does not change old orders or reports.</p></div>', true, true);
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
    $photos .= '<div class="ph"><div class="pimgw"><img src="../assets/img/' . h($k) . '.webp" alt=""><span class="pno">' . ($i === 0 ? 'Main photo' : 'Photo ' . ($i + 1)) . '</span></div><input type="hidden" name="photo_order[]" value="' . h($k) . '">'
      . '<div class="pmv"><button type="button" data-mv="-1" aria-label="Move left">‹</button><button type="button" data-mv="0">Make main</button><button type="button" data-mv="1" aria-label="Move right">›</button></div>'
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
    . '<h2>Photos</h2><div class="card">' . ($photos ? '<p class="muted small" style="margin:0 0 8px">The first photo is the main one on the shop. Use ‹ › to change the order, then Save. The product page shows them in this order.</p><div class="phs">' . $photos . '</div>'
        . '<script>(function(){var g=document.querySelector(".phs");function fix(){var a=g.querySelectorAll(".ph");a.forEach(function(p,i){p.querySelector(".pno").textContent=i?"Photo "+(i+1):"Main photo";p.classList.toggle("first",!i);p.querySelector("[data-mv=\'-1\']").disabled=!i;p.querySelector("[data-mv=\'1\']").disabled=i==a.length-1;p.querySelector("[data-mv=\'0\']").disabled=!i})}g.addEventListener("click",function(e){var b=e.target.closest("[data-mv]");if(!b)return;var p=b.closest(".ph"),d=+b.dataset.mv;if(d===0)g.prepend(p);else if(d<0&&p.previousElementSibling)g.insertBefore(p,p.previousElementSibling);else if(d>0&&p.nextElementSibling)g.insertBefore(p.nextElementSibling,p);fix()});fix()})()</script>' : '')
    . '<label for="photos">' . ($photos ? 'Add more photos' : 'Add photos') . '</label><input id="photos" type="file" name="photos[]" accept="image/*" multiple>'
    . '<p class="muted small">Square or portrait photos look best. They are made smaller automatically.</p></div>'
    . '<div class="savebar"><button class="btn big">' . ($isNew ? 'Add product' : 'Save') . '</button></div></form>', true);
}

/* ---- offer: the website's offer popup, its timer and the "Limited time offer" line by sale prices (prices themselves are set on Products) ---- */
if (isset($_GET['offer'])) {
  $realMax = 0;   // the biggest real saving on the website: old price vs price, shown products only
  foreach (fomaxo_product_rows($pdo) ?: [] as $r) { if ($r['hidden']) continue; $p = json_decode($r['data'], true); if (!is_array($p)) continue;
    foreach ((array)($p['prices'] ?? []) as $z => $v) { $c = $p['compareAt'][$z] ?? null; if (is_numeric($v) && is_numeric($c) && $c > $v) $realMax = max($realMax, (int)round(($c - $v) / $c * 100)); } }
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) { flash('Please try again.'); go(['offer' => 1]); }
    if (isset($_POST['np_save'])) {   // new product popup: "Coming soon" / "Just arrived" + the product name
      $on = isset($_POST['np_on']); $name = trim(preg_replace('/\s+/', ' ', (string)($_POST['np_name'] ?? ''))); $pid = preg_replace('/[^a-z0-9_-]/i', '', (string)($_POST['np_product'] ?? ''));
      if ($on && $name === '') { flash('Please type the product name.'); go(['offer' => 1]); }
      fomaxo_setting($pdo, 'np', fomaxo_json(['on' => $on, 'status' => ($_POST['np_status'] ?? '') === 'arrived' ? 'arrived' : 'soon', 'name' => mb_substr($name, 0, 40),
        'line' => mb_substr(trim((string)($_POST['np_line'] ?? '')), 0, 90), 'product' => $pid]));
      flash($on ? 'New product popup saved.' : 'New product popup is off.', true); go(['offer' => 1]);
    }
    $mode = (string)($_POST['mode'] ?? 'off');
    if (isset($_POST['all_off'])) $mode = 'off';
    if ($mode === 'date') {   // a real end date (UAE time); the popup and line hide by themselves when it passes
      $d = (string)($_POST['sale_end_d'] ?? ''); $t = (string)($_POST['sale_end_t'] ?? '');
      if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || ($t !== '' && !preg_match('/^\d{2}:\d{2}$/', $t))) { flash('Please type the end date as dd/mm/yyyy.'); go(['offer' => 1]); }
      $end = strtotime("$d " . ($t !== '' ? "$t:00" : '23:59:59'));
      if (!$end || $end <= time()) { flash('The end must be in the future.'); go(['offer' => 1]); }
      fomaxo_setting($pdo, 'sale_ends', (string)$end); fomaxo_setting($pdo, 'sale_always', '');
    } elseif ($mode === 'always') { fomaxo_setting($pdo, 'sale_ends', ''); fomaxo_setting($pdo, 'sale_always', '1'); }
    else { fomaxo_setting($pdo, 'sale_ends', ''); fomaxo_setting($pdo, 'sale_always', ''); }
    $capped = false;
    if (!isset($_POST['all_off'])) {   // the % in the popup: empty = the biggest real saving; never more than that
      $pc = trim((string)($_POST['pct'] ?? ''));
      if ($pc !== '' && (!ctype_digit($pc) || (int)$pc < 1 || (int)$pc > 99)) { flash('Please type a % between 1 and 99, or leave it empty.'); go(['offer' => 1]); }
      if ($pc !== '' && $realMax && (int)$pc > $realMax) { $pc = (string)$realMax; $capped = true; }
      fomaxo_setting($pdo, 'sale_pct', $pc);
    }
    if (!isset($_POST['all_off'])) { fomaxo_setting($pdo, 'sale_popup', isset($_POST['popup']) ? '1' : '0'); fomaxo_setting($pdo, 'sale_line', isset($_POST['line']) ? '1' : '0'); }
    flash($mode === 'off' ? 'The offer is off: no popup and no line on the website.' : 'Offer saved.' . ($capped ? " The % was set to $realMax%, the biggest real saving on your products (old price vs price)." : ''), true); go(['offer' => 1]);
  }
  $e = (int)fomaxo_setting($pdo, 'sale_ends'); $on = $e > time(); $l = $e - time(); $al = (string)fomaxo_setting($pdo, 'sale_always') === '1';
  $mode = $on ? 'date' : ($al ? 'always' : 'off');
  $pop = (string)fomaxo_setting($pdo, 'sale_popup') !== '0'; $line = (string)fomaxo_setting($pdo, 'sale_line') !== '0';
  $pct = (string)fomaxo_setting($pdo, 'sale_pct');
  $np = json_decode((string)fomaxo_setting($pdo, 'np'), true) ?: ['on' => false, 'status' => 'soon', 'name' => '', 'line' => '', 'product' => ''];
  $plist = [];
  foreach (fomaxo_product_rows($pdo) ?: [] as $r) { $p = json_decode($r['data'], true); if (is_array($p) && !empty($p['id'])) $plist[$p['id']] = ($p['name'] ?? $p['id']) . ($r['hidden'] ? ' (hidden)' : ''); }
  $what = array_filter([$pop ? 'popup' : '', $line ? 'line by prices' : '']);
  $state = $mode === 'off' ? '<span class="lvl-low">●</span> Off: nothing shows on the website.'
    : '<span class="lvl-ok">●</span> On' . ($mode === 'date' ? ': ends ' . date('d/m/Y, g:i a', $e) . ' (' . ($l >= 86400 ? floor($l / 86400) . 'd ' : '') . floor($l % 86400 / 3600) . 'h ' . floor($l % 3600 / 60) . 'm left)' : ': always on, no timer') . '. Showing: ' . ($what ? implode(' + ', $what) : 'nothing (both switches off)') . '.';
  $radio = fn($v, $t, $sub) => '<label class="om"><input type="radio" name="mode" value="' . $v . '"' . ($mode === $v ? ' checked' : '') . '><span><b>' . $t . '</b><small>' . $sub . '</small></span></label>';
  $box = fn($n, $c, $t, $sub) => '<label class="om"><input type="checkbox" name="' . $n . '" value="1"' . ($c ? ' checked' : '') . '><span><b>' . $t . '</b><small>' . $sub . '</small></span></label>';
  page('Offer', '<h1>Offer</h1>' . flash()
    . '<style>.om{text-transform:none;letter-spacing:normal;font-size:14px;color:var(--ink);display:flex;gap:10px;align-items:flex-start;padding:10px 12px;margin:0 0 8px;border:1px solid var(--line);border-radius:10px;cursor:pointer}.om input{margin:3px 0 0;width:auto;flex:none;accent-color:var(--gold)}.om b{display:block;font-size:12.5px;letter-spacing:.08em;text-transform:uppercase}.om small{display:block;color:var(--muted);font-size:13px;line-height:1.4;margin-top:3px}.om:has(input:checked){border-color:var(--gold)}'
    . '.cpq{display:flex;gap:6px;flex-wrap:wrap;margin:6px 0 4px}.cpq button{font:inherit;font-size:12.5px;font-weight:600;padding:6px 12px;border-radius:999px;border:1px solid var(--line);background:transparent;color:var(--ink);cursor:pointer}.cpq button.on,.cpq button:hover{border-color:var(--gold);color:var(--gold)}.sg2{display:grid;gap:0 12px;grid-template-columns:1fr 1fr}.odate{margin:-2px 0 10px 34px}</style>'
    . '<form class="card" method="post" style="max-width:640px">' . csrf_field()
    . '<p class="small" style="margin:0 0 14px">' . $state . '</p>'
    . '<h2 style="margin-top:0">Timer</h2>'
    . $radio('date', 'Countdown to an end date', 'Days / hours / minutes / seconds count down; everything hides by itself at the end.')
    . '<div class="odate"><div class="cpq" role="group" aria-label="Quick end"><button type="button" data-h="24">24 hours</button><button type="button" data-h="48">48 hours</button><button type="button" data-h="72">3 days</button><button type="button" data-h="168">7 days</button></div>'
    . '<div class="sg2"><div><label for="sale_end_d">Ends</label><input id="sale_end_d" type="date" name="sale_end_d" value="' . ($on ? date('Y-m-d', $e) : '') . '"></div><div><label for="sale_end_t">End time (UAE)</label><input id="sale_end_t" type="time" name="sale_end_t" value="' . ($on ? date('H:i', $e) : '') . '"></div></div></div>'
    . $radio('always', 'Always on (no timer)', '"Limited time offer · HURRY UP!!!" with no clock, until you turn it off.')
    . $radio('off', 'Off', 'Nothing shows on the website.')
    . '<h2>Discount %</h2>'
    . '<label class="om" style="cursor:default;align-items:center"><span style="flex:1"><b>% shown in the popup</b><small>Leave empty to show the biggest real saving' . ($realMax ? ' (now ' . $realMax . '%)' : '') . '. It can\'t be more than that: set the old prices on Products first.</small></span>'
    . '<span style="display:flex;align-items:center;gap:6px;flex:none"><input name="pct" type="number" min="1" max="' . ($realMax ?: 99) . '" step="1" inputmode="numeric" value="' . h($pct) . '" placeholder="' . ($realMax ?: '') . '" style="width:84px;margin:0;text-align:center">%</span></label>'
    . '<h2>What shows</h2>'
    . $box('popup', $pop, 'Popup', 'The box with × that opens a few seconds after someone arrives (once per visit, never on checkout).')
    . $box('line', $line, 'Line by sale prices', '"Limited time offer · Ends in …" on the product page and shop cards of items with an old price.')
    . '<p style="margin:14px 0 0;display:flex;gap:8px;flex-wrap:wrap"><button class="btn">Save</button>' . ($mode !== 'off' ? '<button class="btn line" name="all_off" value="1">Turn everything off</button>' : '') . '</p>'
    . '<p class="muted small" style="margin:10px 0 0">Old prices are set on Products. Prices are not changed here.</p></form>'
    . '<form class="card" method="post" style="max-width:640px;margin-top:14px">' . csrf_field() . '<input type="hidden" name="np_save" value="1">'
    . '<h2 style="margin-top:0">New product popup</h2>'
    . '<p class="small" style="margin:0 0 12px">' . ($np['on'] ? '<span class="lvl-ok">●</span> On: "' . ($np['status'] === 'arrived' ? 'Just arrived' : 'Coming soon') . ' · ' . h($np['name']) . '".' : '<span class="lvl-low">●</span> Off.') . '</p>'
    . $box('np_on', !empty($np['on']), 'Show the new product popup', 'Shown once to each visitor (the sale popup then shows on their next visit). Never on checkout.')
    . '<label class="om"><input type="radio" name="np_status" value="soon"' . ($np['status'] !== 'arrived' ? ' checked' : '') . '><span><b>Coming soon</b><small>No button to buy; "Explore FOMAXO" goes to the fragrances.</small></span></label>'
    . '<label class="om"><input type="radio" name="np_status" value="arrived"' . ($np['status'] === 'arrived' ? ' checked' : '') . '><span><b>Just arrived</b><small>"Shop now" opens the product page.</small></span></label>'
    . '<label for="np_name">Product name</label><input id="np_name" name="np_name" maxlength="40" value="' . h($np['name']) . '" placeholder="e.g. Oud Royale">'
    . '<label for="np_line">Short line (optional)</label><input id="np_line" name="np_line" maxlength="90" value="' . h($np['line']) . '" placeholder="e.g. A new Elite fragrance, launching this month">'
    . '<label for="np_product">Product page and photo (optional)</label><select id="np_product" name="np_product"><option value="">None (no photo)</option>'
    . implode('', array_map(fn($id, $n) => '<option value="' . h($id) . '"' . ($np['product'] === $id ? ' selected' : '') . '>' . h($n) . '</option>', array_keys($plist), $plist)) . '</select>'
    . '<p class="muted small" style="margin:6px 0 0">Pick the product once it is added on Products (it can stay hidden until launch). Its first photo is shown in the popup.</p>'
    . '<p style="margin:14px 0 0"><button class="btn">Save</button></p></form>'
    . '<script>document.querySelectorAll(".cpq button").forEach(function(b){b.onclick=function(){var f=b.form,p=function(n){return ("0"+n).slice(-2)},set=function(n,v){var i=f.querySelector("input[name="+n+"]");i.value=v;i.dispatchEvent(new Event("change"))},e=new Date(Date.now()+(new Date().getTimezoneOffset()+240)*60000+b.dataset.h*3600000);'
    . 'set("sale_end_d",e.getFullYear()+"-"+p(e.getMonth()+1)+"-"+p(e.getDate()));set("sale_end_t",p(e.getHours())+":"+p(e.getMinutes()));f.querySelector("input[name=mode][value=date]").checked=true;document.querySelectorAll(".cpq button").forEach(function(x){x.classList.toggle("on",x===b)})}});'
    . 'document.querySelectorAll(".odate input").forEach(function(i){i.addEventListener("input",function(){document.querySelector("input[name=mode][value=date]").checked=true})})</script>');
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
    if (isset($_POST['mail_test'])) {
      $err = null; $ok = fomaxo_mail(fomaxo_orders_email(), 'FOMAXO test email', "This is a test from fomaxo.com/admin.", "From: FOMAXO <" . FX_MAIL_FROM . ">\r\nContent-Type: text/plain; charset=UTF-8", $err);
      flash($ok && $err === null ? 'A test email was sent to ' . fomaxo_orders_email() . '.' : ($err !== null ? 'Hostinger did not accept it: ' . $err . '.' : 'The email could not be sent.'), $ok && $err === null);
      go(['settings' => 1]);
    }
    if (isset($_POST['mail_pass'])) {   // mail@fomaxo.com password: kept above public_html, never shown again
      $mp = (string)$_POST['mail_pass'];
      if ($mp === '') { flash('Please type the mail@fomaxo.com password.'); go(['settings' => 1]); }
      if (!fomaxo_mail_save_password($mp)) { flash('The password could not be saved. Please try again.'); go(['settings' => 1]); }
      $err = fomaxo_smtp_send(fomaxo_mail_config(), fomaxo_orders_email(), 'FOMAXO test email', "This is a test from fomaxo.com/admin.\n\nStore emails are now sent from mail@fomaxo.com.", "From: FOMAXO <" . FX_MAIL_FROM . ">\r\nContent-Type: text/plain; charset=UTF-8");
      if ($err !== null) flash('Saved, but Hostinger did not accept it: ' . $err . '. Check the password of mail@fomaxo.com in Hostinger → Emails.');
      else flash('Saved. A test email was sent to ' . fomaxo_orders_email() . '.', true);
      go(['settings' => 1]);
    }
    $email = trim((string)($_POST['orders_email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { flash('Please type a valid email address.'); go(['settings' => 1]); }
    fomaxo_setting($pdo, 'orders_email', $email);   // the "Only X left" level is set on the Stock page
    flash('Settings saved.', true); go(['settings' => 1]);
  }
  page('Settings', '<h1>Settings</h1>' . flash()
    . '<div class="settings"><form class="card" method="post">' . csrf_field()
    . '<h2 style="margin-top:0">Store</h2>'
    . '<label for="orders_email">Store emails go to</label><input id="orders_email" type="email" name="orders_email" required value="' . h(fomaxo_orders_email()) . '">'
    . '<p class="muted small" style="margin:6px 0 0">New cash and card orders, new reviews and admin password reset links are all emailed here.</p>'
    . '<p style="margin:14px 0 0"><button class="btn">Save</button></p></form>'
    . '<form class="card" method="post">' . csrf_field()
    . '<h2 style="margin-top:0">Email sending</h2>'
    . (fomaxo_mail_config()
        ? '<p class="small" style="margin:0 0 8px"><span class="lvl-ok">●</span> Emails are sent from ' . FX_MAIL_FROM . ' through Hostinger, so they do not land in spam.</p>'
        : '<p class="small" style="margin:0 0 8px"><span class="lvl-low">●</span> Not set up yet: emails may land in spam. Type the password of the ' . FX_MAIL_FROM . ' mailbox (the one you made in Hostinger → Emails).</p>')
    . '<label for="mail_pass">' . FX_MAIL_FROM . ' password</label><input id="mail_pass" name="mail_pass" type="password" autocomplete="new-password" placeholder="' . (fomaxo_mail_config() ? 'Saved, type again only to change it' : '') . '">'
    . '<p class="muted small" style="margin:6px 0 0">Kept on your Hostinger server only, never shown again.</p>'
    . '<p style="margin:14px 0 0;display:flex;gap:8px;flex-wrap:wrap"><button class="btn">Save and send a test</button>'
    . (fomaxo_mail_config() ? '<button class="btn line" name="mail_test" value="1" formnovalidate>Send a test email</button>' : '') . '</p></form>'
    . '<form class="card" method="post">' . csrf_field()
    . '<h2 style="margin-top:0">Admin password</h2>'
    . '<label for="pw_now">Current password</label><input id="pw_now" name="pw_now" type="password" required autocomplete="current-password">'
    . '<label for="pw1">New password</label><input id="pw1" name="pw1" type="password" minlength="8" required autocomplete="new-password">'
    . '<label for="pw2">New password again</label><input id="pw2" name="pw2" type="password" minlength="8" required autocomplete="new-password">'
    . '<p style="margin:14px 0 0"><button class="btn">Change password</button></p></form></div>', true);
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
    flash('Saved.', true); go(['stock' => 1] + (in_array($_POST['w'] ?? '', ['low', 'out'], true) ? ['w' => $_POST['w']] : []));
  }
  $have = []; $cost = [];
  foreach ($pdo->query('SELECT product, size, qty, cost FROM fx_stock') as $r) { $have[$r['product'] . '|' . $r['size']] = $r['qty']; $cost[$r['product'] . '|' . $r['size']] = $r['cost']; }
  $pic = [];   // first photo of each product, from the Products tab
  try { foreach ($pdo->query('SELECT id, data FROM fx_products') as $r) { $d = json_decode($r['data'], true); if (!empty($d['images'][0])) $pic[$r['id']] = $d['images'][0]; } } catch (Throwable $e) {}
  $sold = [];   // sold in the last 30 days (orders that are not cancelled), to help decide when to restock
  $s = $pdo->query("SELECT lines_json FROM fx_orders WHERE stock_taken = 1 AND created_at > NOW() - INTERVAL 30 DAY");
  foreach ($s as $r) foreach (fomaxo_stock_lines(json_decode((string)$r['lines_json'], true) ?: []) as $k => $n) $sold[$k] = ($sold[$k] ?? 0) + $n;
  $tr = ''; $w = in_array($_GET['w'] ?? '', ['low', 'out'], true) ? $_GET['w'] : ''; $nw = ['low' => 0, 'out' => 0];
  foreach ($CATALOG as $id => $p) foreach (array_keys($p['prices']) as $opt) {
    $k = "$id|$opt"; $q = $have[$k] ?? null; $c = $cost[$k] ?? null;
    $st = $q === null ? '' : ((int)$q <= 0 ? 'out' : ((int)$q <= fomaxo_low_stock() ? 'low' : '')); if ($st !== '') $nw[$st]++;
    $lvl = $q === null ? '<span class="muted">Not counted</span>' : ((int)$q <= 0 ? '<span class="lvl-out">Sold out</span>' : ((int)$q <= fomaxo_low_stock() ? '<span class="lvl-low">Only ' . (int)$q . ' left</span>' : '<span class="lvl-ok">In stock</span>'));
    $margin = ' · <span class="muted">sells at AED ' . h(number_format($p['prices'][$opt], 0)) . '</span>';
    $img = $pic[$id] ?? ($p['images'][0] ?? '');
    $tr .= '<tr class="row' . ($w !== '' && $st !== $w ? ' wf' : '') . '"><td class="sp">' . ($img ? '<img class="sth" src="../assets/img/' . h($img) . '.webp" alt="" loading="lazy">' : '<span class="sth"></span>') . '<div><b>' . h($p['name']) . '</b> <span class="muted">' . h($p['kind'] === 'set' ? "Set of $opt" : "{$opt}ml") . '</span>'
         . '<div class="small">' . $lvl . $margin . (!empty($sold[$k]) ? ' <span class="muted">· ' . (int)$sold[$k] . ' sold in 30 days</span>' : '') . '</div></div></td>'
         . '<td class="num"><label class="mini">Stock</label><input type="number" min="0" inputmode="numeric" name="q[' . h($id) . '][' . h($opt) . ']" value="' . ($q === null ? '' : (int)$q) . '" placeholder="—" aria-label="' . h($p['name'] . ' ' . $opt) . ' stock"></td>'
         . '<td class="num"><label class="mini">Cost AED</label><input type="number" min="0" step="0.01" inputmode="decimal" name="c[' . h($id) . '][' . h($opt) . ']" value="' . ($c === null ? '' : h(rtrim(rtrim($c, '0'), '.'))) . '" placeholder="—" aria-label="' . h($p['name'] . ' ' . $opt) . ' cost price"></td></tr>';
  }
  page('Stock', '<div class="pagehead"><h1>Stock &amp; cost</h1><input type="search" class="tsearch" placeholder="Search product" aria-label="Search product"></div>' . flash()
    . '<nav class="ems track swarn" aria-label="Stock warnings">' . implode('', array_map(fn($k, $l) => '<a class="em t-' . $k . ($w === $k ? ' on' : '') . '" href="' . h(self_url(['stock' => 1] + ($w === $k ? [] : ['w' => $k]))) . '">' . $l . ' <b>' . $nw[$k] . '</b></a>', ['low', 'out'], ['⚠ Running low', 'Out of stock'])) . '</nav>'
    . ($w !== '' ? '<p class="small muted swnote">Showing only ' . ($w === 'low' ? 'sizes running low (' . fomaxo_low_stock() . ' or fewer left)' : 'sizes out of stock') . ' · <a href="./?stock=1">Show all</a></p>' : '')
    . '<form class="fitform" method="post">' . csrf_field() . ($w !== '' ? '<input type="hidden" name="w" value="' . $w . '">' : '')
    . '<div class="card lowlvl"><label for="low_stock">Show "Only X left" on the website when stock is at or below</label><input id="low_stock" type="number" min="0" max="100" inputmode="numeric" name="low_stock" required value="' . fomaxo_low_stock() . '">'
    . '<p class="muted small" style="margin:6px 0 0">One level for every product and size. 0 turns it off (Sold out still shows).</p></div>'
    . '<div class="fill"><table class="stock"><thead><tr><th>Product</th><th class="num">Stock</th><th class="num">Cost (AED)</th></tr></thead><tbody>' . $tr . '</tbody></table></div>'
    . '<div class="savebar">' . '<p class="muted small stock-help"><b>Stock:</b> how many bottles you have. It goes down by itself with every cash order and every paid card order (the free mini counts as one 10ml) and goes back up if you cancel an order. Leave it empty to not count that size. The website shows "Only X left" at ' . fomaxo_low_stock() . ' or fewer, and "Out of Stock — Restocking Soon" at 0.<br>'
    . '<b>Cost:</b> what one bottle costs you. Reports use it to work out your profit.</p>' . '<button class="btn big">Save</button></div></form>', true, true);
}

/* ---- expenses: money going out that is not a bottle sold (ads, delivery, packaging, rent …) ---- */
if (isset($_GET['expenses'])) {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) { flash('Please try again.'); go(['expenses' => 1]); }
    if (isset($_POST['delete'])) {
      $pdo->prepare('DELETE FROM fx_expenses WHERE id = ?')->execute([(int)$_POST['delete']]);
      flash('Expense deleted.', true); go(['expenses' => 1]);
    }
    $day = (string)($_POST['day'] ?? ''); $cat = (string)($_POST['category'] ?? ''); $amt = (float)str_replace(',', '.', (string)($_POST['amount'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || !in_array($cat, FX_EXPENSE_TYPES, true) || $amt <= 0) { flash('Please fill in the date, type and amount.'); go(['expenses' => 1]); }
    $pdo->prepare('INSERT INTO fx_expenses (day, category, amount, note, created_at) VALUES (?, ?, ?, ?, NOW())')->execute([$day, $cat, round($amt, 2), mb_substr(trim((string)($_POST['note'] ?? '')), 0, 200)]);
    flash('Expense added.', true); go(['expenses' => 1]);
  }
  $rg = adm_range('expenses', '30', ['today' => 'Today', '7' => '7 days', '30' => '30 days', 'all' => 'All']);
  $s = $pdo->prepare('SELECT * FROM fx_expenses' . ($rg['span'] ? ' WHERE day BETWEEN ? AND ?' : '') . ' ORDER BY day DESC, id DESC');
  $s->execute($rg['span'] ? [$rg['d1'], $rg['d2']] : []); $list = $s->fetchAll();
  $sum = array_sum(array_map(fn($e) => (float)$e['amount'], $list));
  $opts = implode('', array_map(fn($c) => '<option>' . h($c) . '</option>', FX_EXPENSE_TYPES));
  $tr = '';
  foreach ($list as $e) $tr .= '<tr class="row"><td><b>' . h($e['category']) . '</b><div class="muted small">' . h(date(substr($e['day'], 0, 4) === date('Y') ? 'd M' : 'd M Y', strtotime($e['day']))) . ($e['note'] !== '' ? ' · ' . h($e['note']) : '') . '</div></td>'
    . '<td class="num">' . money($e['amount']) . '</td><td class="num"><form method="post" style="margin:0" onsubmit="return confirm(\'Delete this expense?\')">' . csrf_field()
    . '<input type="hidden" name="delete" value="' . (int)$e['id'] . '"><button class="btn line sm">Delete</button></form></td></tr>';
  page('Expenses', '<div class="pagehead rg"><h1>Expenses</h1>' . adm_range_form($rg, ['expenses' => 1]) . '</div>' . flash()
    . '<form class="card add" method="post">' . csrf_field() . '<h2 style="margin-top:0">Add an expense</h2>'
    . '<div class="g3"><div><label for="day">Date</label><input id="day" type="date" name="day" value="' . h(date('Y-m-d')) . '" required></div>'
    . '<div><label for="category">Type</label><select id="category" name="category">' . $opts . '</select></div>'
    . '<div><label for="amount">Amount (AED)</label><input id="amount" type="number" min="0.01" step="0.01" inputmode="decimal" name="amount" required></div></div>'
    . '<label for="note">Note (optional)</label><input id="note" name="note" maxlength="200" placeholder="e.g. Instagram ads, Aramex invoice">'
    . '<p style="margin:12px 0 0"><button class="btn">Add expense</button></p></form>'
    . '<h2 class="exsum">' . h($rg['r'] === 'all' ? 'All time' : $rg['label']) . '<small>' . money($sum) . ' · ' . count($list) . ' expense' . (count($list) === 1 ? '' : 's') . '</small></h2>'
    . '<div class="fill">' . ($list ? '<table class="exp"><tbody>' . $tr . '</tbody></table>' : '<p class="card muted" style="margin:0">No expenses in this period.</p>')
    . '<p class="muted small after">"Stock purchase" is shown in reports but not taken off profit, because the cost of each bottle is already counted when it sells.</p></div>', true, true);
}

/* ---- coupons: codes customers type at checkout (% off or AED off, optional minimum, expiry date and number of uses).
   A coupon never adds to the multi-buy discount: the customer gets whichever saving is bigger. checkout.php and ziina.php check the code on the server. ---- */
if (isset($_GET['coupons'])) {
  fomaxo_coupons_table($pdo);
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) { flash('Please try again.'); go(['coupons' => 1]); }
    $code = fomaxo_coupon_norm($_POST['code'] ?? '');
    if (isset($_POST['delete'])) {
      $pdo->prepare('DELETE FROM fx_coupons WHERE code = ?')->execute([$code]);
      flash("Coupon $code deleted.", true); go(['coupons' => 1]);
    }
    if (isset($_POST['toggle'])) {
      $pdo->prepare('UPDATE fx_coupons SET active = 1 - active WHERE code = ?')->execute([$code]);
      flash("Coupon $code switched " . ($_POST['toggle'] === 'on' ? 'on' : 'off') . '.', true); go(['coupons' => 1]);
    }
    $kind = ($_POST['kind'] ?? '') === 'aed' ? 'aed' : 'pct';
    $num = fn($k) => trim((string)($_POST[$k] ?? '')) === '' ? null : (float)str_replace(',', '.', (string)$_POST[$k]);
    $amt = $num('amount'); $min = $num('min_order'); $uses = trim((string)($_POST['max_uses'] ?? '')) === '' ? null : (int)$_POST['max_uses'];
    /* time limit: start and end date + time (Dubai); a date without a time starts at 00:00 and ends at 23:59 */
    $when = function ($d, $t, $def) { $d = (string)($_POST[$d] ?? ''); $t = (string)($_POST[$t] ?? '');
      if ($d === '') return ''; if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || ($t !== '' && !preg_match('/^\d{2}:\d{2}$/', $t))) return false;
      return "$d " . ($t !== '' ? "$t:00" : $def); };
    $st = $when('start_d', 'start_t', '00:00:00'); $en = $when('end_d', 'end_t', '23:59:59');
    if (strlen($code) < 3) { flash('Please type a code of at least 3 letters or numbers (no spaces).'); go(['coupons' => 1]); }
    if ($amt === null || $amt <= 0 || ($kind === 'pct' && $amt > 100)) { flash($kind === 'pct' ? 'Please type a % between 1 and 100.' : 'Please type the AED amount.'); go(['coupons' => 1]); }
    if ($st === false || $en === false) { flash('Please type the dates as dd/mm/yyyy.'); go(['coupons' => 1]); }
    if ($st !== '' && $en !== '' && $en <= $st) { flash('The end must be after the start.'); go(['coupons' => 1]); }
    $had = $pdo->prepare('SELECT 1 FROM fx_coupons WHERE code = ?'); $had->execute([$code]); $had = (bool)$had->fetchColumn();
    $pdo->prepare('INSERT INTO fx_coupons (code, kind, amount, min_order, expires, starts, ends, max_uses, active, created_at) VALUES (?, ?, ?, ?, NULL, ?, ?, ?, 1, NOW())
                   ON DUPLICATE KEY UPDATE kind = VALUES(kind), amount = VALUES(amount), min_order = VALUES(min_order), expires = NULL, starts = VALUES(starts), ends = VALUES(ends), max_uses = VALUES(max_uses), active = 1')
        ->execute([$code, $kind, round($amt, 2), $min !== null && $min > 0 ? round($min, 2) : null, $st !== '' ? $st : null, $en !== '' ? $en : null, $uses !== null && $uses > 0 ? $uses : null]);
    flash("Coupon $code " . ($had ? 'updated' : 'saved') . '. ' . ($st !== '' && $st > date('Y-m-d H:i:s') ? 'It starts ' . date('d/m/Y, g:i a', strtotime($st)) . '.' : 'Customers can use it now.'), true); go(['coupons' => 1]);
  }
  $list = $pdo->query('SELECT * FROM fx_coupons ORDER BY active DESC, created_at DESC')->fetchAll();
  /* how often each code was used (placed orders, not cancelled or refunded) and what it saved customers */
  $used = [];
  foreach ($pdo->query("SELECT coupon, COUNT(*) n, COALESCE(SUM(discount), 0) d, COALESCE(SUM(total), 0) t FROM fx_orders WHERE coupon IS NOT NULL AND test = 0
                        AND status NOT IN ('Awaiting payment', 'Cancelled', 'Refunded') GROUP BY coupon") as $r) $used[$r['coupon']] = $r;
  $now = date('Y-m-d H:i:s'); $tr = '';
  $dt = fn($v) => date('d/m/Y, g:i a', strtotime($v));
  $left = function ($v) { $m = (int)floor((strtotime($v) - time()) / 60); $d = intdiv($m, 1440); $h = intdiv($m % 1440, 60);
    return $d ? "$d day" . ($d > 1 ? 's' : '') . ($h ? " {$h}h" : '') . ' left' : ($h ? "{$h}h " : '') . ($m % 60) . 'm left'; };
  foreach ($list as $c) {
    $u = $used[$c['code']] ?? ['n' => 0, 'd' => 0, 't' => 0]; $n = (int)$u['n'];
    $ends = $c['ends'] ?? ($c['expires'] ? $c['expires'] . ' 23:59:59' : null); $starts = $c['starts'] ?? null;
    $state = !(int)$c['active'] ? ['Off', 's-Cancelled'] : ($ends && $ends < $now ? ['Expired', 's-Cancelled'] : ($c['max_uses'] !== null && $n >= (int)$c['max_uses'] ? ['Used up', 's-Cancelled']
           : ($starts && $starts > $now ? ['Scheduled', 's-New'] : ['On', 'p-Paid'])));
    $time = $starts && $starts > $now ? 'Starts ' . $dt($starts) . ($ends ? ' · ends ' . $dt($ends) : '') : ($ends ? 'Ends ' . $dt($ends) . ($state[0] === 'On' ? ' · ' . $left($ends) : '') : '');
    $rules = array_filter([(float)$c['min_order'] > 0 ? 'Min. order ' . money($c['min_order']) : '', $c['max_uses'] !== null ? 'Max ' . (int)$c['max_uses'] . ' use' . ((int)$c['max_uses'] === 1 ? '' : 's') : '']);
    $btn = fn($name, $val, $label, $ask = '') => '<form method="post" style="margin:0"' . ($ask ? ' onsubmit="return confirm(\'' . h($ask) . '\')"' : '') . '>' . csrf_field()
      . '<input type="hidden" name="code" value="' . h($c['code']) . '"><button class="btn line sm" name="' . $name . '" value="' . $val . '">' . $label . '</button></form>';
    $tr .= '<div class="cprow"><div><b class="cpcode">' . h($c['code']) . '</b> <span class="tag ' . $state[1] . '">' . $state[0] . '</span>'
      . '<div class="muted small">' . h(fomaxo_coupon_label($c)) . ($rules ? ' · ' . h(implode(' · ', $rules)) : '') . '</div>' . ($time ? '<div class="small cptime">' . h($time) . '</div>' : '') . '</div>'
      . '<div class="cpused"><b>Used ' . $n . ' time' . ($n === 1 ? '' : 's') . '</b>' . ($n ? '<div class="muted small">Saved customers ' . money($u['d']) . ' · sales ' . money($u['t']) . '</div>' : '') . '</div>'
      . '<div class="cpacts">' . ((int)$c['active'] ? $btn('toggle', 'off', 'Turn off') : $btn('toggle', 'on', 'Turn on'))
      . $btn('delete', '1', 'Delete', 'Delete coupon ' . $c['code'] . '? Orders that used it keep the code.') . '</div></div>';
  }
  page('Coupons', '<div class="pagehead"><h1>Coupons</h1></div>' . flash()
    . '<style>.cpcode{letter-spacing:.06em}.cpin{text-transform:uppercase;letter-spacing:.06em}.cpin::placeholder{text-transform:none;letter-spacing:0}'
    . '.cplist{background:var(--panel);border:1px solid var(--line);border-radius:10px}.cprow{display:grid;grid-template-columns:1fr auto auto;gap:6px 24px;align-items:center;padding:10px 12px}.cprow+.cprow{border-top:1px solid var(--line)}'
    . '.cpused{text-align:right}.cptime{color:var(--gold);margin-top:2px}.cpq{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 4px}.cpq button{font:inherit;font-size:12.5px;font-weight:600;padding:6px 12px;border-radius:999px;border:1px solid var(--line);background:transparent;color:var(--ink);cursor:pointer}.cpq button.on,.cpq button:hover{border-color:var(--gold);color:var(--gold)}.g4{display:grid;gap:0 12px;grid-template-columns:1fr 1fr}@media (min-width:760px){.g4{grid-template-columns:1.3fr 1fr 1.3fr 1fr}}.cpacts{display:flex;gap:6px;justify-content:flex-end;flex-wrap:wrap}@media (max-width:759px){.cprow{grid-template-columns:1fr;padding:12px 14px}.cpused{text-align:left}.cpacts{grid-column:1/-1;justify-content:flex-start}}'
    /* laptop: the form is a tall card on the left, the coupons made so far are listed beside it */
    . '@media (min-width:900px){.cpgrid{display:grid;grid-template-columns:380px minmax(0,1fr);gap:18px;align-items:start}.cpgrid .card.add{margin:0;position:sticky;top:0}.cpgrid .g3,.cpgrid .g4{grid-template-columns:1fr 1fr}.cpgrid .g4{grid-template-columns:1.25fr 1fr}.cpgrid .cpg1>div:first-child{grid-column:1/-1}.cpgrid .exsum{margin-top:0}'
    . '.cpr .cprow{grid-template-columns:1fr auto;gap:6px 16px}.cpr .cpacts{grid-column:2;grid-row:1/3;flex-direction:column;align-items:stretch}.cpr .cpacts .btn{width:100%}.cpr .cpused{text-align:left}}</style>'
    . '<div class="cpgrid"><div class="cpl"><form class="card add" method="post">' . csrf_field() . '<h2 style="margin-top:0">Make a coupon</h2>'
    . '<div class="g3 cpg1"><div><label for="code">Code</label><input id="code" class="cpin" name="code" maxlength="30" placeholder="e.g. WELCOME10" autocapitalize="characters" autocomplete="off" required></div>'
    . '<div><label for="kind">Type</label><select id="kind" name="kind"><option value="pct">% off</option><option value="aed">AED off</option></select></div>'
    . '<div><label for="amount">Amount</label><input id="amount" type="number" min="0.01" step="0.01" inputmode="decimal" name="amount" placeholder="e.g. 10" required></div></div>'
    . '<div class="g3"><div><label for="min_order">Minimum order AED (optional)</label><input id="min_order" type="number" min="0" step="0.01" inputmode="decimal" name="min_order"></div>'
    . '<div><label for="max_uses">Max uses (optional)</label><input id="max_uses" type="number" min="1" step="1" inputmode="numeric" name="max_uses"></div></div>'
    . '<label>Time limit (optional)</label><div class="cpq" role="group" aria-label="Quick time limit"><button type="button" data-h="24">24 hours</button><button type="button" data-h="48">48 hours</button><button type="button" data-h="72">3 days</button><button type="button" data-h="168">7 days</button><button type="button" data-h="0">No limit</button></div>'
    . '<div class="g4"><div><label for="start_d">Starts</label><input id="start_d" type="date" name="start_d"></div><div><label for="start_t">Start time</label><input id="start_t" type="time" name="start_t"></div>'
    . '<div><label for="end_d">Ends</label><input id="end_d" type="date" name="end_d"></div><div><label for="end_t">End time</label><input id="end_t" type="time" name="end_t"></div></div>'
    . '<p style="margin:12px 0 0"><button class="btn">Save coupon</button></p>'
    . '<p class="muted small" style="margin:10px 0 0">Saving a code that already exists updates it. A coupon does not add to the multi-buy discount: the customer gets whichever saves more. The free 10ml mini still applies. Times are UAE time. Leave the time limit empty for a code with no end.</p></form>'
    . '<script>document.querySelectorAll(".cpq button").forEach(function(b){b.onclick=function(){var h=+b.dataset.h,f=b.form,p=function(n){return ("0"+n).slice(-2)},set=function(n,v){var i=f.querySelector("input[name="+n+"]");i.value=v;i.dispatchEvent(new Event("change"))},d=function(x){return x.getFullYear()+"-"+p(x.getMonth()+1)+"-"+p(x.getDate())},t=function(x){return p(x.getHours())+":"+p(x.getMinutes())};'
    . 'var n=new Date(Date.now()+(new Date().getTimezoneOffset()+240)*60000),e=new Date(n.getTime()+h*3600000);if(!h){["start_d","start_t","end_d","end_t"].forEach(function(k){set(k,"")});}else{set("start_d",d(n));set("start_t",t(n));set("end_d",d(e));set("end_t",t(e));}document.querySelectorAll(".cpq button").forEach(function(x){x.classList.toggle("on",x===b)})}})</script></div>'
    . '<div class="cpr"><h2 class="exsum">Your coupons<small>' . count($list) . ' code' . (count($list) === 1 ? '' : 's') . '</small></h2>'
    . '<div class="fill">' . ($list ? '<div class="cplist">' . $tr . '</div>' : '<p class="card muted" style="margin:0">No coupons yet. Make one with the form.</p>')
    . '<p class="muted small after">"Used" counts placed orders; cancelled, refunded and unpaid card attempts are not counted.</p></div></div></div>', true);
}

/* ---- analytics: visitors, where they come from, and where sales are lost (filled by track.php on the website) ---- */
if (isset($_GET['analytics'])) {
  if (isset($_GET['leads'])) {   // Excel: everyone who typed their details at checkout, from the start, and whether they ordered later
    $ordered = "(l.order_no IS NOT NULL OR EXISTS (SELECT 1 FROM fx_orders o WHERE o.status IN ('New', 'Paid', 'Delivered') AND o.created_at >= l.created_at - INTERVAL 1 HOUR
                 AND l.phone <> '' AND RIGHT(REGEXP_REPLACE(o.phone, '[^0-9]', ''), 9) = RIGHT(REGEXP_REPLACE(l.phone, '[^0-9]', ''), 9)))";
    $addr = $pdo->query("SHOW COLUMNS FROM fx_leads LIKE 'address'")->fetch() ? 'l.address' : "''";
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="fomaxo-checkout-details-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Started', 'Last change', 'Name', 'Mobile', 'Email', 'Emirate', 'Address', 'Products in bag', 'Bag value (AED)', 'Left at', 'Ordered later']);
    $stageName = ['details' => 'Delivery details', 'payment' => 'Payment choice', 'card' => 'Card payment page'];
    $cell = fn($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) && !preg_match('/^\+?[\d\s()\-]+$/', $v) ? "'" . $v : $v;   // typed by visitors: never let Excel run it as a formula
    foreach ($pdo->query("SELECT l.*, $addr addr, $ordered ordered FROM fx_leads l ORDER BY l.updated_at DESC") as $l)
      fputcsv($out, array_map($cell, [$l['created_at'], $l['updated_at'], $l['name'], $l['phone'], $l['email'], $l['emirate'], $l['addr'], $l['items'],
                     $l['total'] === null ? '' : number_format((float)$l['total'], 2, '.', ''), $stageName[$l['stage']] ?? $l['stage'], $l['ordered'] ? 'Yes' . ($l['order_no'] ? ' (' . $l['order_no'] . ')' : '') : 'No']));
    exit;
  }
  $isDay = fn($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v) && strtotime($v);
  $r = (string)($_GET['r'] ?? '7');
  if ($r === 'custom' && $isDay($_GET['d1'] ?? '') && $isDay($_GET['d2'] ?? '')) { $d1 = min($_GET['d1'], $_GET['d2']); $d2 = max($_GET['d1'], $_GET['d2']); }
  else { if (!in_array($r, ['today', '7', '30'], true)) $r = '7'; $d2 = date('Y-m-d'); $d1 = date('Y-m-d', strtotime('-' . ($r === 'today' ? 0 : (int)$r - 1) . ' day')); }
  $span = [$d1 . ' 00:00:00', $d2 . ' 23:59:59'];
  $nDays = (int)round((strtotime($d2) - strtotime($d1)) / 86400) + 1;
  $q = function ($sql, $args = []) use ($pdo, $span) { $s = $pdo->prepare($sql); $s->execute($args ?: $span); return $s; };

  $f = $q("SELECT COUNT(DISTINCT vid) visitors, COUNT(DISTINCT sid) visits, SUM(ev = 'view') views,
                  COUNT(DISTINCT CASE WHEN ev = 'product' THEN sid END) product, COUNT(DISTINCT CASE WHEN ev = 'cart' THEN sid END) cart,
                  COUNT(DISTINCT CASE WHEN ev = 'checkout' THEN sid END) checkout, COUNT(DISTINCT CASE WHEN ev = 'pay' THEN sid END) pay,
                  COUNT(DISTINCT CASE WHEN ev = 'card' THEN sid END) card, COUNT(DISTINCT CASE WHEN ev = 'buy' THEN sid END) buy
           FROM fx_events WHERE at BETWEEN ? AND ?")->fetch();
  $f = array_map('intval', $f);
  $live = (int)$pdo->query('SELECT COUNT(*) FROM fx_live WHERE seen > NOW() - INTERVAL 5 MINUTE')->fetchColumn();
  $ord = $q("SELECT COUNT(*) n, COALESCE(SUM(total), 0) t FROM fx_orders WHERE status IN ('New', 'Paid', 'Delivered') AND test = 0 AND created_at BETWEEN ? AND ?")->fetch();
  /* returning customers: orders in the range from a mobile (last 9 digits, as in Members) that had ordered before, and the days since that earlier order */
  $ret = ['orders' => 0, 'people' => [], 'days' => []];
  try {
    foreach ($q("SELECT o.p9, o.name, o.phone, o.total, o.created_at, (SELECT MAX(x.created_at) FROM fx_orders x WHERE x.status IN ('New', 'Paid', 'Delivered') AND x.test = 0 AND x.created_at < o.created_at
                   AND RIGHT(REGEXP_REPLACE(x.phone, '[^0-9]', ''), 9) = o.p9) prev
                 FROM (SELECT RIGHT(REGEXP_REPLACE(phone, '[^0-9]', ''), 9) p9, name, phone, total, created_at FROM fx_orders
                       WHERE status IN ('New', 'Paid', 'Delivered') AND test = 0 AND phone <> '' AND created_at BETWEEN ? AND ?) o") as $row)
      if ($row['prev'] !== null && strlen($row['p9']) >= 7) {
        $gap = (strtotime($row['created_at']) - strtotime($row['prev'])) / 86400;
        $ret['orders']++; $ret['days'][] = $gap;
        $pp = $ret['people'][$row['p9']] ?? ['name' => $row['name'], 'phone' => $row['phone'], 'n' => 0, 't' => 0, 'last' => '', 'gap' => 0];
        $pp['n']++; $pp['t'] += (float)$row['total'];
        if ($row['created_at'] > $pp['last']) { $pp['last'] = $row['created_at']; $pp['gap'] = $gap; $pp['name'] = $row['name'] ?: $pp['name']; }
        $ret['people'][$row['p9']] = $pp;
      }
  } catch (Throwable $e) {}
  $retN = count($ret['people']); $retD = $ret['days'] ? (int)round(array_sum($ret['days']) / count($ret['days'])) : null;
  $back = (int)$q('SELECT COUNT(DISTINCT e.vid) FROM fx_events e WHERE e.at BETWEEN ? AND ? AND EXISTS (SELECT 1 FROM fx_events o WHERE o.vid = e.vid AND o.at < ?)', [$span[0], $span[1], $span[0]])->fetchColumn();
  $pct = fn($a, $b) => $b > 0 ? round(100 * $a / $b, 1) : 0;
  $pctT = fn($v) => rtrim(rtrim(number_format($v, 1), '0'), '.') . '%';

  /* visitors over time: by hour for one day, by day up to three months, by month for longer */
  $mode = $nDays === 1 ? 'hour' : ($nDays <= 92 ? 'day' : 'month');
  $fmtKey = ['hour' => '%H', 'day' => '%Y-%m-%d', 'month' => '%Y-%m'][$mode];
  $per = []; foreach ($q("SELECT DATE_FORMAT(at, '$fmtKey') k, COUNT(DISTINCT vid) n FROM fx_events WHERE at BETWEEN ? AND ? GROUP BY k") as $row) $per[$row['k']] = (int)$row['n'];
  $pts = [];
  if ($mode === 'hour') for ($h = 0; $h < 24; $h++) { $k = sprintf('%02d', $h); $pts[] = ['v' => $per[$k] ?? 0, 'l' => date('ga', mktime($h, 0)), 't' => date('ga', mktime($h, 0)) . '–' . date('ga', mktime($h + 1, 0)) . ': ' . ($per[$k] ?? 0) . ' visitors']; }
  else for ($t = strtotime($d1); $t <= strtotime($d2); $t = strtotime($mode === 'day' ? '+1 day' : 'first day of next month', $t)) {
    $k = date($mode === 'day' ? 'Y-m-d' : 'Y-m', $t);
    $pts[] = ['v' => $per[$k] ?? 0, 'l' => date($mode === 'day' ? ($nDays <= 7 ? 'D' : 'j M') : 'M y', $t), 't' => date($mode === 'day' ? 'D j M' : 'F Y', $t) . ': ' . ($per[$k] ?? 0) . ' visitors'];
  }
  $chart = fx_graph($pts, 'Visitors per ' . $mode, false, fn($v) => number_format($v) . ' visitors', true);

  /* where visits come from (the first page of each visit), and how many of them bought */
  $src = '';
  foreach ($q("SELECT x.source, COUNT(*) visits, COUNT(DISTINCT x.vid) visitors, SUM(EXISTS (SELECT 1 FROM fx_events b WHERE b.sid = x.sid AND b.ev = 'buy')) bought
               FROM (SELECT sid, MIN(vid) vid, MIN(source) source FROM fx_events WHERE ev = 'view' AND source IS NOT NULL AND at BETWEEN ? AND ? GROUP BY sid) x
               GROUP BY x.source ORDER BY visits DESC") as $row)
    $src .= '<tr><td>' . h($row['source']) . '</td><td class="num">' . (int)$row['visitors'] . '</td><td class="num">' . (int)$row['visits'] . '</td><td class="num">' . (int)$row['bought'] . '</td><td class="num">' . $pctT($pct($row['bought'], $row['visits'])) . '</td></tr>';

  /* products: views and adds to bag; devices */
  $names = []; foreach (fomaxo_product_rows($pdo) ?: [] as $row) { $pd = json_decode($row['data'], true); $names[$row['id']] = $pd['name'] ?? $row['id']; }
  $prod = '';
  foreach ($q("SELECT product, COUNT(DISTINCT CASE WHEN ev = 'product' THEN sid END) views, COUNT(DISTINCT CASE WHEN ev = 'cart' THEN sid END) adds
               FROM fx_events WHERE ev IN ('product', 'cart') AND product IS NOT NULL AND at BETWEEN ? AND ? GROUP BY product ORDER BY views DESC, adds DESC") as $row)
    $prod .= '<tr><td>' . h($names[$row['product']] ?? $row['product']) . '</td><td class="num">' . (int)$row['views'] . '</td><td class="num">' . (int)$row['adds'] . '</td><td class="num">' . $pctT($pct($row['adds'], $row['views'])) . '</td></tr>';
  $dev = [];
  foreach ($q("SELECT device, COUNT(DISTINCT sid) n FROM fx_events WHERE ev = 'view' AND at BETWEEN ? AND ? GROUP BY device ORDER BY n DESC") as $row) $dev[] = h(ucfirst($row['device'] ?: 'other')) . ' ' . $pctT($pct($row['n'], $f['visits']));
  /* pages: views, visitors and the average time on each page (time = until the next page of the visit, or the last moment the page was seen open; tabs left open count at most 30 minutes) */
  $pgName = function ($pg) use ($names) {
    if ($pg === '/' ) return 'Home';
    if (preg_match('~^/product/([a-z0-9-]+)~', $pg, $m)) return $names[$m[1]] ?? ucfirst($m[1]);
    return ucfirst(str_replace(['-', '/'], [' ', ' / '], trim($pg, '/')));
  };
  $pgT = ''; $pgRows = [];
  try {
    $pgRows = $q("SELECT pg, COUNT(*) views, COUNT(DISTINCT vid) visitors, ROUND(AVG(d)) secs FROM (
                    SELECT vid, CASE WHEN SUBSTRING_INDEX(page, '?', 1) IN ('', '/', 'home') THEN '/' ELSE SUBSTRING_INDEX(page, '?', 1) END pg,
                      LEAST(TIMESTAMPDIFF(SECOND, at, COALESCE(LEAD(at) OVER (PARTITION BY sid ORDER BY at, id), left_at)), 1800) d
                    FROM fx_events WHERE ev = 'view' AND at BETWEEN ? AND ?) x
                  GROUP BY pg ORDER BY views DESC LIMIT 60")->fetchAll();
  } catch (Throwable $e) {
    try { $pgRows = $q("SELECT CASE WHEN SUBSTRING_INDEX(page, '?', 1) IN ('', '/', 'home') THEN '/' ELSE SUBSTRING_INDEX(page, '?', 1) END pg, COUNT(*) views, COUNT(DISTINCT vid) visitors, NULL secs
                         FROM fx_events WHERE ev = 'view' AND at BETWEEN ? AND ? GROUP BY pg ORDER BY views DESC LIMIT 60")->fetchAll(); } catch (Throwable $e) {}
  }
  $dur = fn($t) => $t === null ? '–' : ($t >= 60 ? intdiv((int)$t, 60) . 'm ' . str_pad((string)((int)$t % 60), 2, '0', STR_PAD_LEFT) . 's' : (int)$t . 's');
  $pgMax = $pgRows ? max(array_column($pgRows, 'views')) : 0;
  foreach ($pgRows as $row) $pgT .= '<tr><td><b>' . h($pgName($row['pg'])) . '</b><div class="fb"><i style="width:' . ($pgMax ? round($row['views'] / $pgMax * 100, 1) : 0) . '%"></i></div></td><td class="num">' . number_format($row['views']) . '</td><td class="num">' . number_format($row['visitors']) . '</td><td class="num">' . $dur($row['secs']) . '</td></tr>';
  /* devices: phone, tablet or computer, read from the browser when the visit starts (the browser details themselves are not kept) */
  $devN = ['phone' => 'Phone', 'computer' => 'Desktop', 'tablet' => 'Tablet'];
  $devR = []; foreach ($devN as $k => $l) $devR[$k] = ['visitors' => 0, 'visits' => 0, 'bought' => 0];
  foreach ($q("SELECT x.device, COUNT(*) visits, COUNT(DISTINCT x.vid) visitors, SUM(EXISTS (SELECT 1 FROM fx_events b WHERE b.sid = x.sid AND b.ev = 'buy')) bought
               FROM (SELECT sid, MIN(vid) vid, MIN(device) device FROM fx_events WHERE ev = 'view' AND at BETWEEN ? AND ? GROUP BY sid) x
               WHERE x.device IN ('phone', 'tablet', 'computer') GROUP BY x.device") as $row)
    $devR[$row['device']] = ['visitors' => (int)$row['visitors'], 'visits' => (int)$row['visits'], 'bought' => (int)$row['bought']];
  $devAll = array_sum(array_column($devR, 'visits'));
  $devT = '';
  foreach ($devN as $k => $l) { $r0 = $devR[$k]; $sh = $pct($r0['visits'], $devAll);
    $devT .= '<tr><td><b>' . $l . '</b><div class="fb"><i style="width:' . min(100, round($sh, 1)) . '%"></i></div></td><td class="num">' . number_format($r0['visitors']) . '</td><td class="num">' . number_format($r0['visits']) . '</td><td class="num">' . number_format($r0['bought']) . '</td><td class="num">' . $pctT($pct($r0['bought'], $r0['visits'])) . '</td><td class="num"><b>' . $pctT($sh) . '</b></td></tr>'; }

  /* ---- Conversion view (?analytics=1&cv=1): five reports on what turns visits into orders ---- */
  $cv = isset($_GET['cv']);
  $cvq = $cv ? ['cv' => 1] : [];
  $cvGrid = '';
  if ($cv) {
    $cvSince = fomaxo_setting($pdo, 'conv_since');
    $cvNote = 'Counting since ' . ($cvSince ? h(date('j M Y', strtotime($cvSince))) : 'today') . '.';
    $ok = "status IN ('New', 'Paid', 'Delivered') AND test = 0";
    $orders = $q("SELECT order_no, subtotal, discount, total, lines_json FROM fx_orders WHERE $ok AND created_at BETWEEN ? AND ?")->fetchAll();
    $bar = fn($n, $max) => '<div class="fb"><i style="width:' . ($max ? max(1, round($n / $max * 100, 1)) : 0) . '%"></i></div>';

    /* 1. per product: visits that viewed it → added it to the bag → orders that bought it (free minis not counted) */
    $pf = [];
    foreach ($q("SELECT product, COUNT(DISTINCT CASE WHEN ev = 'product' THEN sid END) views, COUNT(DISTINCT CASE WHEN ev = 'cart' THEN sid END) adds
                 FROM fx_events WHERE ev IN ('product', 'cart') AND product IS NOT NULL AND at BETWEEN ? AND ? GROUP BY product") as $row)
      $pf[$row['product']] = ['v' => (int)$row['views'], 'a' => (int)$row['adds'], 'o' => 0, 'u' => 0];
    foreach ($orders as $o) {
      $seen = [];
      foreach (json_decode((string)$o['lines_json'], true) ?: [] as $l) {
        if (!is_array($l) || !empty($l['free']) || empty($l['id'])) continue;
        $pf[$l['id']] ??= ['v' => 0, 'a' => 0, 'o' => 0, 'u' => 0];
        $pf[$l['id']]['u'] += max(1, (int)($l['qty'] ?? 1));
        if (!isset($seen[$l['id']])) { $seen[$l['id']] = 1; $pf[$l['id']]['o']++; }
      }
    }
    uasort($pf, fn($a, $b) => [$b['v'], $b['o']] <=> [$a['v'], $a['o']]);
    $pfT = '';
    foreach ($pf as $id => $x)
      $pfT .= '<tr><td><b>' . h($names[$id] ?? $id) . '</b></td><td class="num">' . number_format($x['v']) . '</td><td class="num">' . number_format($x['a']) . '</td><td class="num">' . number_format($x['o'])
            . '</td><td class="num">' . number_format($x['u']) . '</td><td class="num"><b>' . ($x['v'] ? $pctT($pct($x['o'], $x['v'])) : '–') . '</b></td></tr>';

    /* 2. checkout drop-off: the last step each checkout reached, the boxes left empty by people who did not order, and card payments not finished */
    $typed = (int)$q("SELECT COUNT(DISTINCT sid) FROM fx_leads WHERE created_at BETWEEN ? AND ?")->fetchColumn();
    $cs = [['Opened checkout', $f['checkout']], ['Typed name or mobile', $typed], ['Reached payment choice', $f['pay']], ['Opened card page', $f['card']], ['Bought', $f['buy']]];
    $csMax = max(array_column($cs, 1)) ?: 0; $drop = ''; $prev = null;
    foreach ($cs as [$label, $n]) {
      $drop .= '<li><div class="fl"><span>' . $label . ($prev !== null && $prev > $n && $label !== 'Opened card page' ? ' <span class="fd">' . number_format($prev - $n) . ' stopped</span>' : '') . '</span><span><b>' . number_format($n) . '</b></span></div>' . $bar($n, $csMax) . '</li>';
      if ($label !== 'Opened card page') $prev = $n;
    }
    $empty = $q("SELECT COUNT(*) n, SUM(name = '') name, SUM(phone = '') phone, SUM(emirate = '') emirate, SUM(" . ($pdo->query("SHOW COLUMNS FROM fx_leads LIKE 'address'")->fetch() ? "address = ''" : '0') . ") address, SUM(email = '') email
                 FROM fx_leads l WHERE l.updated_at BETWEEN ? AND ? AND l.order_no IS NULL
                 AND NOT EXISTS (SELECT 1 FROM fx_orders o WHERE o.status IN ('New', 'Paid', 'Delivered') AND o.created_at >= l.created_at - INTERVAL 1 HOUR
                                 AND l.phone <> '' AND RIGHT(REGEXP_REPLACE(o.phone, '[^0-9]', ''), 9) = RIGHT(REGEXP_REPLACE(l.phone, '[^0-9]', ''), 9))")->fetch();
    $eT = '';
    foreach (['name' => 'Name', 'phone' => 'Mobile', 'emirate' => 'Emirate', 'address' => 'Address', 'email' => 'Email (optional)'] as $k => $l)
      $eT .= '<tr><td>' . $l . '</td><td class="num">' . number_format((int)$empty[$k]) . '</td><td class="num muted">' . $pctT($pct((int)$empty[$k], (int)$empty['n'])) . '</td></tr>';
    $unpaid = $q("SELECT COUNT(*) n, COALESCE(SUM(total), 0) t FROM fx_orders WHERE status = 'Awaiting payment' AND test = 0 AND created_at BETWEEN ? AND ?")->fetch();

    /* 3. campaigns: where each visit came from (utm_source / utm_campaign in the link, else the app or website it came from), and the orders it made */
    $hasCamp = (bool)$pdo->query("SHOW COLUMNS FROM fx_events LIKE 'campaign'")->fetch();
    $camp = '';
    foreach ($q("SELECT x.source, x.campaign, COUNT(*) visits, SUM(EXISTS (SELECT 1 FROM fx_events b WHERE b.sid = x.sid AND b.ev = 'buy')) bought,
                   COALESCE(SUM((SELECT SUM(o.total) FROM fx_leads l JOIN fx_orders o ON o.order_no = l.order_no WHERE l.sid = x.sid AND o.$ok)), 0) rev
                 FROM (SELECT sid, MIN(source) source, " . ($hasCamp ? 'MIN(campaign)' : 'NULL') . " campaign FROM fx_events WHERE ev = 'view' AND source IS NOT NULL AND at BETWEEN ? AND ? GROUP BY sid) x
                 GROUP BY x.source, x.campaign ORDER BY bought DESC, visits DESC LIMIT 60") as $row)
      $camp .= '<tr><td><b>' . h($row['source']) . '</b>' . ($row['campaign'] !== null ? '<span class="muted cn">' . h($row['campaign']) . '</span>' : '') . '</td><td class="num">' . number_format($row['visits']) . '</td><td class="num">' . number_format($row['bought'])
             . '</td><td class="num">' . $pctT($pct($row['bought'], $row['visits'])) . '</td><td class="num">' . ((float)$row['rev'] ? 'AED ' . number_format(round((float)$row['rev'])) : '–') . '</td></tr>';

    /* 4. multi-buy offer: orders by the discount they got (2 items 5%, 3-4 items 10%, 5+ items 15%) and what each level adds per order */
    $mb = [];
    foreach ($orders as $o) {
      $sub = (float)$o['subtotal']; $lvl = $sub > 0 && (float)$o['discount'] > 0 ? (int)round((float)$o['discount'] / $sub * 100) : 0;
      $lvl = in_array($lvl, [0, 5, 10, 15], true) ? $lvl : -1;
      $items = 0; $minis = 0;
      foreach (json_decode((string)$o['lines_json'], true) ?: [] as $l) if (is_array($l)) { if (!empty($l['free'])) $minis++; else $items += max(1, (int)($l['qty'] ?? 1)); }
      $mb[$lvl] ??= ['n' => 0, 'items' => 0, 't' => 0, 'd' => 0, 'm' => 0];
      $mb[$lvl]['n']++; $mb[$lvl]['items'] += $items; $mb[$lvl]['t'] += (float)$o['total']; $mb[$lvl]['d'] += (float)$o['discount']; $mb[$lvl]['m'] += $minis;
    }
    $base = isset($mb[0]) && $mb[0]['n'] ? $mb[0]['t'] / $mb[0]['n'] : null;
    $mbT = ''; $extra = 0;
    foreach ([0 => 'No offer (1 item)', 5 => '5% (2 items)', 10 => '10% + mini (3-4)', 15 => '15% + mini (5+)', -1 => 'Other'] as $k => $l) {
      if (!isset($mb[$k]) && $k === -1) continue;
      $x = $mb[$k] ?? ['n' => 0, 'items' => 0, 't' => 0, 'd' => 0, 'm' => 0]; $avg = $x['n'] ? $x['t'] / $x['n'] : 0;
      $more = $k > 0 && $base !== null && $x['n'] ? ($avg - $base) * $x['n'] : null; if ($more) $extra += $more;
      $mbT .= '<tr><td><b>' . $l . '</b></td><td class="num">' . number_format($x['n']) . '</td><td class="num">' . ($x['n'] ? number_format($x['items'] / $x['n'], 1) : '–') . '</td><td class="num">' . ($x['n'] ? 'AED ' . number_format(round($avg)) : '–')
            . '</td><td class="num">' . ($x['d'] ? 'AED ' . number_format(round($x['d'])) : '–') . '</td><td class="num">' . ($more !== null ? ($more < 0 ? '−' : '+') . 'AED ' . number_format(abs(round($more))) : '–') . '</td></tr>';
    }
    $mbN = array_sum(array_column(array_filter($mb, fn($k) => $k > 0, ARRAY_FILTER_USE_KEY), 'n'));

    /* 5. homepage scroll: of the visits that opened the homepage, how many scrolled to 25 / 50 / 75 / 100 % of it */
    $sc = ''; $home = 0;
    try {
      $from = max($span[0], ($cvSince ?: date('Y-m-d')) . ' 00:00:00');
      $home = (int)$q("SELECT COUNT(DISTINCT sid) FROM fx_events WHERE ev = 'view' AND page IN ('', '/') AND at BETWEEN ? AND ?", [$from, $span[1]])->fetchColumn();
      $dd = []; foreach ($q("SELECT depth, COUNT(DISTINCT sid) n FROM fx_events WHERE ev = 'scroll' AND at BETWEEN ? AND ? GROUP BY depth", [$from, $span[1]]) as $row) $dd[(int)$row['depth']] = (int)$row['n'];
      foreach ([0 => 'Opened the homepage', 25 => 'Scrolled 25%', 50 => 'Scrolled 50%', 75 => 'Scrolled 75%', 100 => 'Reached the bottom'] as $m => $l) {
        $n = $m ? min($home, $dd[$m] ?? 0) : $home;
        $sc .= '<li><div class="fl"><span>' . $l . '</span><span><b>' . number_format($n) . '</b> <span class="muted">' . $pctT($pct($n, $home)) . '</span></span></div>' . $bar($n, $home) . '</li>';
      }
    } catch (Throwable $e) {}

    $cvPanes = ['cprod' => 'Products', 'cdrop' => 'Checkout', 'ccamp' => 'Campaigns', 'cmulti' => 'Multi-buy', 'cscroll' => 'Homepage scroll'];
    $cvGrid = '<nav class="apick cvpick" role="tablist">' . implode('', array_map(fn($k, $l) => '<button type="button" data-p="' . $k . '"' . ($k === 'cprod' ? ' class="on"' : '') . '>' . $l . '</button>', array_keys($cvPanes), $cvPanes)) . '</nav>'
      . '<div class="dgrid agrid cv">'
      . '<section class="card c-prod on" data-p="cprod"><div class="ch"><h2>Products: viewed → bag → bought</h2></div>'
      . ($pfT ? '<div class="list"><table class="mini ctab"><thead><tr><th>Product</th><th class="num">Viewed</th><th class="num">To bag</th><th class="num">Orders</th><th class="num">Units</th><th class="num">Buy rate</th></tr></thead><tbody>' . $pfT . '</tbody></table></div><p class="muted small gnote">Visits that viewed or added each product, and the orders that bought it. Buy rate: orders ÷ views.</p>' : '<p class="muted empty">No product views in these dates.</p>') . '</section>'
      . '<section class="card c-drop" data-p="cdrop"><div class="ch"><h2>Checkout drop-off</h2></div><ul class="list fun">' . $drop
      . '<li class="fx"><table class="mini"><thead><tr><th>Box left empty</th><th class="num">People</th><th class="num">Share</th></tr></thead><tbody>' . $eT . '</tbody></table>'
      . '<p class="muted small" style="margin:6px 0 0">Of ' . number_format((int)$empty['n']) . ' ' . ((int)$empty['n'] === 1 ? 'person' : 'people') . ' who typed details and did not order. Card payments not finished: <b>' . number_format((int)$unpaid['n']) . '</b>' . ((int)$unpaid['n'] ? ' (AED ' . number_format(round((float)$unpaid['t'])) . ')' : '') . '.</p></li></ul></section>'
      . '<section class="card c-camp" data-p="ccamp"><div class="ch"><h2>Campaigns</h2></div>'
      . ($camp ? '<div class="list"><table class="mini"><thead><tr><th>Source / campaign</th><th class="num">Visits</th><th class="num">Bought</th><th class="num">Conv.</th><th class="num">Revenue</th></tr></thead><tbody>' . $camp . '</tbody></table></div>' : '<p class="muted empty">No visits in these dates.</p>')
      . '<p class="muted small gnote">Tag a post or ad link: fomaxo.com/?utm_source=instagram&amp;utm_campaign=eid-post. Campaign names ' . lcfirst($cvNote) . '</p></section>'
      . '<section class="card c-multi" data-p="cmulti"><div class="ch"><h2>Multi-buy offer</h2><span class="val">' . ($extra > 0 ? '+AED ' . number_format(round($extra)) : '') . '</span></div>'
      . ($orders ? '<div class="list"><table class="mini mtab"><thead><tr><th>Offer</th><th class="num">Orders</th><th class="num">Items</th><th class="num">Avg. order</th><th class="num">Discount</th><th class="num">Extra</th></tr></thead><tbody>' . $mbT . '</tbody></table></div>'
                 . '<p class="muted small gnote">' . $pctT($pct($mbN, count($orders))) . ' of orders used the offer. Extra: how much more those orders brought than the same number of one-item orders' . ($base !== null ? ' (AED ' . number_format(round($base)) . ' each)' : '') . '.</p>' : '<p class="muted empty">No orders in these dates.</p>') . '</section>'
      . '<section class="card c-scroll" data-p="cscroll"><div class="ch"><h2>Homepage scroll</h2></div>'
      . ($home ? '<ul class="list fun">' . $sc . '</ul>' : '<p class="muted empty">No homepage visits counted yet.</p>') . '<p class="muted small gnote">' . $cvNote . '</p></section>'
      . '</div>';
  }

  /* where visitors are: country of each visit, and the emirate for the UAE (looked up from the IP address when the visit starts; the address is not kept) */
  require_once dirname(__DIR__) . '/geo-lib.php';
  $gr = ['today' => 'Today', '7' => '7 days', '30' => '30 days', 'year' => 'Year'];
  $gFrom = ['today' => date('Y-m-d 00:00:00'), '7' => date('Y-m-d 00:00:00', strtotime('-6 day')), '30' => date('Y-m-d 00:00:00', strtotime('-29 day')), 'year' => date('Y-m-d 00:00:00', strtotime('-1 year +1 day'))];
  $gTo = array_fill_keys(array_keys($gFrom), date('Y-m-d 23:59:59'));
  if ($r === 'custom') {   // dates picked with Show: these lists follow them too (their own buttons still switch)
    $gr = ['custom' => $d1 === $d2 ? date('j M', strtotime($d1)) : (substr($d1, 0, 7) === substr($d2, 0, 7) ? date('j', strtotime($d1)) : date('j M', strtotime($d1))) . '–' . date('j M', strtotime($d2))] + $gr;
    $gFrom['custom'] = $span[0]; $gTo['custom'] = $span[1];
  }
  $gOn = isset($gr[$r]) ? $r : '7';
  /* countries and emirates follow the dates at the top of the page, like every other section (no separate period buttons) */
  $gr = [$gOn => $gr[$gOn]]; $gFrom[$gOn] = $span[0]; $gTo[$gOn] = $span[1];
  $gSince = fomaxo_setting($pdo, 'geo_since');
  $geoTable = function ($rows, $label) use ($pct, $pctT) {
    $tot = array_sum(array_column($rows, 'n')); $tr = '';
    foreach ($rows as $row) $tr .= '<tr><td>' . $row['name'] . '</td><td class="num">' . number_format($row['n']) . '</td><td class="gbar"><div class="fb"><i style="width:' . max(1, $pct($row['n'], $tot)) . '%"></i></div></td><td class="num muted">' . $pctT($pct($row['n'], $tot)) . '</td></tr>';
    return '<table class="mini gtab"><thead><tr><th>' . $label . '</th><th class="num">Visitors</th><th class="gbar"></th><th class="num">Share</th></tr></thead><tbody>' . $tr . '</tbody></table>';
  };
  $gCountries = $gEmirates = '';
  foreach ($gr as $k => $l) {
    $cRows = $eRows = [];
    try {
      foreach ($q("SELECT country, COUNT(DISTINCT vid) n FROM fx_events WHERE country IS NOT NULL AND at BETWEEN ? AND ? GROUP BY country ORDER BY n DESC, country LIMIT 30", [$gFrom[$k], $gTo[$k]]) as $row)
        $cRows[] = ['name' => fomaxo_flag($row['country']) . ' ' . h(fomaxo_country_name($row['country'])), 'n' => (int)$row['n']];
      $em = array_fill_keys(FOMAXO_EMIRATES, 0); $unk = 0;
      foreach ($q("SELECT region, COUNT(DISTINCT vid) n FROM fx_events WHERE country = 'AE' AND at BETWEEN ? AND ? GROUP BY region", [$gFrom[$k], $gTo[$k]]) as $row)
        if (isset($em[$row['region']])) $em[$row['region']] = (int)$row['n']; else $unk += (int)$row['n'];
      arsort($em); foreach ($em as $name => $n) $eRows[] = ['name' => h($name), 'n' => $n];
      if ($unk) $eRows[] = ['name' => '<span class="muted">Not known</span>', 'n' => $unk];
    } catch (Throwable $e) {}
    $hid = (string)$k === $gOn ? '' : ' hidden';
    $gCountries .= '<div class="list" data-r="' . $k . '"' . $hid . '>' . ($cRows ? $geoTable($cRows, 'Country') : '<p class="muted empty">No visitors yet.</p>') . '</div>';
    $gEmirates .= '<div class="list" data-r="' . $k . '"' . $hid . '>' . (array_sum(array_column($eRows, 'n')) ? $geoTable($eRows, 'Emirate') : '<p class="muted empty">No visitors from the UAE yet.</p>') . '</div>';
  }
  $gSeg = '<div class="seg gseg" role="group" aria-label="Period">' . implode('', array_map(fn($k, $l) => '<button type="button" data-r="' . $k . '"' . ((string)$k === $gOn ? ' class="on"' : '') . '>' . $l . '</button>', array_keys($gr), $gr)) . '</div>';
  $gSeg = '';
  $gNote = 'Counting since ' . ($gSince ? h(date('j M Y', strtotime($gSince))) : 'today') . '.';

  /* checkouts where the customer typed their name or mobile but did not buy, and the step they left at */
  $stageName = ['details' => 'Delivery details', 'payment' => 'Payment choice', 'card' => 'Card payment page'];
  $left = $leftM = ''; $leftN = ['details' => 0, 'payment' => 0, 'card' => 0];
  foreach ($q("SELECT l.* FROM fx_leads l WHERE l.updated_at BETWEEN ? AND ? AND l.order_no IS NULL
               AND NOT EXISTS (SELECT 1 FROM fx_orders o WHERE o.status IN ('New', 'Paid', 'Delivered') AND o.created_at >= l.created_at - INTERVAL 1 HOUR
                               AND l.phone <> '' AND RIGHT(REGEXP_REPLACE(o.phone, '[^0-9]', ''), 9) = RIGHT(REGEXP_REPLACE(l.phone, '[^0-9]', ''), 9))
               ORDER BY l.updated_at DESC LIMIT 200") as $l) {
    $leftN[$l['stage']] = ($leftN[$l['stage']] ?? 0) + 1;
    $wa = preg_replace('/\D/', '', $l['phone']); if (str_starts_with($wa, '05')) $wa = '971' . substr($wa, 1);
    $recent = strtotime($l['updated_at']) > time() - 900;
    /* one line per person (name, emirate, bag value, WhatsApp); tap the line for everything else */
    $left .= '<li class="lx"><details><summary><span class="ln"><b>' . h($l['name'] ?: 'No name') . '</b>' . ($l['emirate'] !== '' ? ' <span class="muted">' . h($l['emirate']) . '</span>' : '') . '</span>'
           . '<span class="lv">' . ($l['total'] !== null ? money($l['total']) : '') . '</span>'
           . ($l['phone'] !== '' ? '<a class="wa" href="https://wa.me/' . h($wa) . '" target="_blank" rel="noopener" aria-label="WhatsApp ' . h($l['name'] ?: $l['phone']) . '"><svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2zm0 18.2a8.2 8.2 0 0 1-4.2-1.2l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 1 1 12 20.2zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8s-.4-.1-.6.1-.7.8-.8 1-.3.2-.5.1a6.7 6.7 0 0 1-3.3-2.9c-.3-.4.2-.4.7-1.3.1-.2 0-.3 0-.4l-.8-1.8c-.2-.5-.4-.4-.6-.4h-.5a1 1 0 0 0-.7.3 3 3 0 0 0-.9 2.2 5.2 5.2 0 0 0 1.1 2.7 11.8 11.8 0 0 0 4.5 4c1.7.7 2.3.8 3.2.6a2.7 2.7 0 0 0 1.8-1.3 2.2 2.2 0 0 0 .2-1.3c-.1-.1-.3-.2-.5-.3z"/></svg><span>WhatsApp</span></a>' : '<span class="wa none"></span>') . '</summary>'
           . '<div class="ld small"><div><span class="tag stage-' . h($l['stage']) . '">Left at ' . h($stageName[$l['stage']] ?? $l['stage']) . '</span> <span class="muted">' . h(date('d M, H:i', strtotime($l['updated_at']))) . ($recent ? ' · may still be checking out' : '') . '</span></div>'
           . '<div>' . h($l['phone']) . ($l['email'] !== '' ? ' · ' . h($l['email']) : '') . '</div>'
           . (($l['address'] ?? '') !== '' ? '<div>' . h($l['address']) . '</div>' : '')
           . ($l['items'] !== '' ? '<div class="muted">' . h($l['items']) . '</div>' : '') . '</div></details></li>';
  }

  /* funnel: each step counts visits (one person can visit more than once) */
  $steps = [['Visits', $f['visits']], ['Viewed a product', $f['product']], ['Added to bag', $f['cart']], ['Started checkout', $f['checkout']], ['Reached payment', $f['pay']], ['Purchased', $f['buy']]];
  $fun = ''; $prev = null;
  foreach ($steps as [$label, $n]) {
    $fun .= '<li><div class="fl"><span>' . $label . ($prev !== null && $prev > $n ? ' <span class="fd">' . number_format($prev - $n) . ' left here (' . $pctT($pct($prev - $n, $prev)) . ')</span>' : '') . '</span>'
          . '<span><b>' . number_format($n) . '</b> <span class="muted">' . $pctT($pct($n, $f['visits'])) . '</span></span></div>'
          . '<div class="fb"><i style="width:' . ($f['visits'] ? max(1, $pct($n, $f['visits'])) : 0) . '%"></i></div></li>';
    $prev = $n;
  }
  $fun .= '<li class="fx muted small">Opened the card payment page: ' . number_format($f['card']) . ' · Pages per visit: ' . ($f['visits'] ? number_format($f['views'] / $f['visits'], 1) : '0') . ' · Returning visitors: ' . number_format($back) . ($dev ? ' · ' . implode(', ', $dev) : '') . '</li>';

  /* on a phone the label sits on top with a short note under the number; laptop shows label under the number as before */
  $tile = fn($val, $label, $cls = '', $note = '') => '<div class="tile ' . $cls . '"><b>' . $val . '</b><span>' . $label . '</span>' . ($note !== '' ? '<small class="tn">' . $note . '</small>' : '') . '</div>';
  $dk = fn($desk, $mob) => '<span class="dk">' . $desk . '</span><span class="mo">' . $mob . '</span>';
  $rl = ['today' => 'Today', '7' => '7 days', '30' => '30 days'];
  $panes = ['funnel' => 'Funnel', 'visitors' => 'Visitors', 'sources' => 'Sources', 'products' => 'Products', 'countries' => 'Countries', 'emirates' => 'Emirates', 'devices' => 'Devices', 'pages' => 'Pages', 'returning' => 'Returning', 'left' => 'Left at checkout'];
  /* phone only: the Returning section (laptop shows the Returning box in the top row) */
  $retRows = '';
  uasort($ret['people'], fn($a, $b) => strcmp($b['last'], $a['last']));
  foreach ($ret['people'] as $pp) {
    $g = (int)round($pp['gap']);
    $retRows .= '<tr><td><b>' . h($pp['name'] ?: 'No name') . '</b><span class="muted">' . h($pp['phone']) . '</span></td><td class="num">' . $pp['n'] . '</td><td class="num">' . money($pp['t']) . '</td><td class="num">' . $g . ($g === 1 ? ' day' : ' days') . '</td></tr>';
  }
  $retCard = '<section class="card a-returning" data-p="returning"><div class="ch"><h2>Returning customers</h2></div>'
    . '<div class="rsum"><div><b>' . $pctT($pct($ret['orders'], (int)$ord['n'])) . '</b><span>of orders</span></div><div><b>' . $retN . '</b><span>' . ($retN === 1 ? 'person' : 'people') . '</span></div><div><b>' . ($retD ?? '—') . '</b><span>days to reorder</span></div></div>'
    . ($retRows ? '<div class="list"><table class="mini rtab"><thead><tr><th>Customer</th><th class="num">Orders</th><th class="num">Spent</th><th class="num">Since last</th></tr></thead><tbody>' . $retRows . '</tbody></table></div>'
               : '<p class="muted empty">No repeat orders in these dates.</p>')
    . '<p class="muted small gnote">Orders in these dates from a mobile that had ordered before.</p></section>';
  page('Analytics', '<div class="db an">'
    . '<div class="pagehead"><h1>Analytics</h1><nav class="seg aview"><a href="' . h(self_url(['analytics' => 1] + ($r !== '7' ? ['r' => $r] + ($r === 'custom' ? ['d1' => $d1, 'd2' => $d2] : []) : []))) . '"' . ($cv ? '' : ' class="on"') . '>Overview</a><a href="' . h(self_url(['analytics' => 1, 'cv' => 1] + ($r !== '7' ? ['r' => $r] + ($r === 'custom' ? ['d1' => $d1, 'd2' => $d2] : []) : []))) . '"' . ($cv ? ' class="on"' : '') . '>Conversion</a></nav><form class="arange" method="get"><input type="hidden" name="analytics" value="1">' . ($cv ? '<input type="hidden" name="cv" value="1">' : '') . '<div class="seg">'
    . implode('', array_map(fn($k, $l) => '<a href="' . h(self_url(['analytics' => 1, 'r' => $k] + $cvq)) . '"' . ($r === (string)$k ? ' class="on"' : '') . '>' . $l . '</a>', array_keys($rl), $rl)) . '</div>'
    . '<input type="hidden" name="r" value="custom"><input type="date" name="d1" value="' . h($d1) . '" aria-label="From"><input type="date" name="d2" value="' . h($d2) . '" aria-label="To"><button class="btn sm' . ($r === 'custom' ? '' : ' line') . '">Show</button></form></div>'
    . '<div class="tiles at">'
    . $tile(number_format($f['visitors']), 'Visitors<span class="dk"> · ' . number_format($f['visits']) . ' visits</span>', '', number_format($f['visits']) . ' visits')
    . $tile(number_format($live), 'On the site now', $live ? 'hot' : '', 'last 5 minutes')
    . $tile($pctT($pct($f['buy'], $f['visits'])), 'Conversion rate', '', 'visits that bought')
    . $tile($pctT($f['cart'] ? 100 - $pct($f['buy'], $f['cart']) : 0), 'Cart abandonment', '', 'added, did not buy')
    . $tile($pctT($f['checkout'] ? 100 - $pct($f['buy'], $f['checkout']) : 0), 'Checkout abandonment', '', 'at checkout, did not buy')
    . $tile(number_format((int)$ord['n']), $dk('Orders', 'Purchases'), '', 'orders')
    . $tile($pctT($pct($ret['orders'], (int)$ord['n'])), 'Returning<span class="dk"> · ' . $retN . ($retN === 1 ? ' person' : ' people') . ($retD !== null ? ', ' . $retD . ' days apart' : '') . '</span>', 'ret',
            $retN . ($retN === 1 ? ' person' : ' people') . ($retD !== null ? ' · reorder in ' . $retD . ($retD === 1 ? ' day' : ' days') : ''))
    . $tile('AED ' . number_format(round((float)$ord['t'])), 'Revenue', 'rev', 'AED ' . number_format((int)$ord['n'] ? round((float)$ord['t'] / (int)$ord['n']) : 0) . ' per order')
    . '</div>'
    . ($cv ? $cvGrid : '<nav class="apick" role="tablist">' . implode('', array_map(fn($k, $l) => '<button type="button" data-p="' . $k . '"' . ($k === 'funnel' ? ' class="on"' : '') . '>' . $l . ($k === 'left' && array_sum($leftN) ? ' (' . array_sum($leftN) . ')' : '') . '</button>', array_keys($panes), $panes)) . '</nav>'
    . '<div class="dgrid agrid">'
    . '<section class="card a-funnel on" data-p="funnel"><div class="ch"><h2>Where sales are lost</h2></div><ul class="list fun">' . $fun . '</ul></section>'
    . '<section class="card a-visitors" data-p="visitors"><div class="ch"><h2>Visitors</h2><span class="val">' . h(date('j M', strtotime($d1)) . ($d1 !== $d2 ? ' – ' . date('j M', strtotime($d2)) : '')) . '</span></div><div class="chartbox">' . $chart . '</div><p class="sub"><span class="tip">Tap a point to see its visitors</span><b>' . number_format($f['visitors']) . '</b></p>' . ($devAll ? '<div class="dstrip" title="Tap for the full devices table">' . implode('', array_map(fn($k, $l) => '<button type="button" data-open="devices"><span>' . $l . '</span><b>' . $pctT($pct($devR[$k]['visits'], $devAll)) . '</b><span>' . number_format($devR[$k]['bought']) . ' bought</span></button>', array_keys($devN), $devN)) . '</div>' : '') . '</section>'
    . $retCard
    . '<section class="card a-left" data-p="left"><div class="ch"><h2>Left at checkout</h2><a class="btn line sm" href="./?analytics=1&amp;leads=1">Excel</a></div>' . (array_sum($leftN) ? '<p class="muted small" style="margin:0 0 4px">' . implode(' · ', array_map(fn($k) => $leftN[$k] . ' at ' . strtolower($stageName[$k]), array_keys(array_filter($leftN)))) . '</p>' : '')
    . ($left ? '<ul class="list leads lxl">' . $left . '</ul>' : '<p class="muted empty">Nobody left checkout after typing their details.</p>') . '</section>'
    . '<section class="card a-sources" data-p="sources"><div class="ch"><h2>Where visitors come from</h2></div>'
    . ($src ? '<div class="list"><table class="mini"><thead><tr><th>Source</th><th class="num">Visitors</th><th class="num">Visits</th><th class="num">Bought</th><th class="num">Conv.</th></tr></thead><tbody>' . $src . '</tbody></table></div>' : '<p class="muted empty">No visits yet.</p>') . '</section>'
    . '<section class="card a-products" data-p="products"><div class="ch"><h2>Products</h2><div class="seg pswap" role="group"><button type="button" data-sw="products"' . " class=\"on\"" . '>Products</button><button type="button" data-sw="pages"' . "" . '>Pages</button></div></div>'
    . ($prod ? '<div class="list"><table class="mini"><thead><tr><th>Product</th><th class="num">Viewed</th><th class="num">To bag</th><th class="num">Rate</th></tr></thead><tbody>' . $prod . '</tbody></table></div>' : '<p class="muted empty">No product views yet.</p>') . '</section>'
    . '<section class="card a-pages" data-p="pages"><div class="ch"><h2>Pages</h2><div class="seg pswap" role="group"><button type="button" data-sw="products"' . "" . '>Products</button><button type="button" data-sw="pages"' . " class=\"on\"" . '>Pages</button></div></div>'
    . ($pgT ? '<div class="list"><table class="mini ptab"><thead><tr><th>Page</th><th class="num">Views</th><th class="num">Visitors</th><th class="num">Avg. time</th></tr></thead><tbody>' . $pgT . '</tbody></table></div><p class="muted small gnote">Avg. time: how long people stayed on the page.</p>' : '<p class="muted empty">No visits in these dates.</p>') . '</section>'
    . '<section class="card a-countries" data-p="countries"><div class="ch"><h2>Top countries</h2>' . $gSeg . '</div>' . $gCountries . '<p class="muted small gnote">' . $gNote . ' Location data by <a href="https://db-ip.com" target="_blank" rel="noopener">DB-IP</a>.</p></section>'
    . '<section class="card a-emirates" data-p="emirates"><div class="ch"><h2>UAE visitors by emirate</h2>' . $gSeg . '</div>' . $gEmirates . '<p class="muted small gnote">Approximate: phone networks often show Dubai or Abu Dhabi. ' . $gNote . '</p></section>'
    . '<section class="card a-devices" data-p="devices"><div class="ch"><h2>Devices</h2></div>'
    . ($devAll ? '<div class="list"><table class="mini dtab"><thead><tr><th>Device</th><th class="num">Visitors</th><th class="num">Visits</th><th class="num">Bought</th><th class="num">Conv.</th><th class="num">Share</th></tr></thead><tbody>' . $devT . '</tbody></table></div>' : '<p class="muted empty">No visits in these dates.</p>') . '</section>'
    . '</div>') . '</div>'
    . '<script>document.querySelectorAll(".gseg button").forEach(function(b){b.addEventListener("click",function(){var c=b.closest(".card");c.querySelectorAll(".gseg button").forEach(function(x){x.classList.toggle("on",x===b)});c.querySelectorAll(".list[data-r]").forEach(function(x){x.hidden=x.dataset.r!==b.dataset.r})})});'
    . '(function(){var open=null,back=null;function shut(){if(!open)return;open.classList.remove("big");open.querySelector(".zoom").textContent="⤢";open.querySelector(".zoom").setAttribute("aria-label","Open bigger");back.remove();open=null;document.body.classList.remove("zoomed")}'
    . 'function show(c){shut();open=c;c.classList.add("big");var z=c.querySelector(".zoom");z.textContent="×";z.setAttribute("aria-label","Close");back=document.createElement("div");back.className="zback";back.onclick=shut;document.body.appendChild(back);document.body.classList.add("zoomed");c.scrollTop=0}'
    . 'document.querySelectorAll(".agrid>.card").forEach(function(c){var h=c.querySelector(".ch");if(!h)return;var b=document.createElement("button");b.type="button";b.className="zoom";b.textContent="⤢";b.setAttribute("aria-label","Open bigger");b.onclick=function(e){e.stopPropagation();open===c?shut():show(c)};h.appendChild(b);var t=h.querySelector("h2");if(t){t.style.cursor="pointer";t.onclick=function(){open===c?shut():show(c)}}});'
    . 'var map=["visitors","visitors","funnel","funnel","funnel","funnel","returning","funnel"];document.querySelectorAll(".tiles.at .tile").forEach(function(t,i){t.onclick=function(){var c=document.querySelector(".agrid>.card[data-p="+(t.classList.contains("ret")?"returning":t.classList.contains("rev")?"funnel":map[i])+"]");if(c)show(c)}});'
    . 'document.querySelectorAll("[data-sw]").forEach(function(b){b.onclick=function(e){e.stopPropagation();document.querySelector(".agrid").classList.toggle("show-pages",b.dataset.sw==="pages")}});document.querySelectorAll("[data-open]").forEach(function(b){b.onclick=function(e){e.stopPropagation();var c=document.querySelector(".agrid>.card[data-p="+b.dataset.open+"]");if(c)show(c)}});document.addEventListener("keydown",function(e){if(e.key==="Escape")shut()})})();'
    . 'document.querySelectorAll(".apick button").forEach(function(b){b.addEventListener("click",function(){document.querySelectorAll(".apick button,.agrid>.card").forEach(function(x){x.classList.toggle("on",x.dataset.p===b.dataset.p)})})});'
    . 'document.querySelectorAll(".chart .hit").forEach(function(r){var s=function(){var c=r.closest(".card");c.querySelector(".tip").textContent=r.dataset.t;c.querySelectorAll(".hit.on").forEach(function(x){x.classList.remove("on")});r.classList.add("on")};r.addEventListener("mouseenter",s);r.addEventListener("click",s)});</script>', true, true);
}

/* ---- reports: sales, costs and profit or loss by month and by year ---- */
if (isset($_GET['reports'])) {
  $years = $pdo->query("SELECT DISTINCT YEAR(created_at) y FROM fx_orders UNION SELECT DISTINCT YEAR(day) FROM fx_expenses ORDER BY y DESC")->fetchAll(PDO::FETCH_COLUMN);
  if (!in_array((int)date('Y'), array_map('intval', $years), true)) array_unshift($years, date('Y'));
  $rg = adm_range('reports', '30', ['today' => 'Today', '7' => '7 days', '30' => '30 days', 'all' => 'All']);
  $year = (int)($_GET['y'] ?? ($rg['r'] === 'custom' ? substr($rg['d2'], 0, 4) : date('Y'))); if ($year < 2000 || $year > 2100) $year = (int)date('Y');
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
  $table = function ($rs, $labelFn, $total = null, $hrefFn = null) use ($cols, $main, $cell) {
    $h = '<table class="rep"><thead><tr><th></th>' . implode('', array_map(fn($k) => '<th class="num">' . h($cols[$k]) . '</th>', $main)) . '</tr></thead><tbody>';
    foreach ($rs as $k => $r) { $empty = !$r['orders'] && !$r['expenses'] && !$r['stock_bought'];
      $h .= '<tr class="' . ($empty ? 'dim' : '') . '"' . ($hrefFn ? ' data-href="' . h($hrefFn($k)) . '" onclick="location.href=this.dataset.href"' : '') . '><td class="per">' . h($labelFn($k)) . '</td>' . implode('', array_map(fn($c) => '<td class="num" data-l="' . h($cols[$c]) . '">' . $cell($c, $r[$c]) . '</td>', $main)) . '</tr>'; }
    if ($total) $h .= '<tr class="tot"><td class="per">' . h($total[0]) . '</td>' . implode('', array_map(fn($c) => '<td class="num" data-l="' . h($cols[$c]) . '">' . $cell($c, $total[1][$c] ?? 0) . '</td>', $main)) . '</tr>';
    return $h . '</tbody></table>';
  };
  /* tap a month, a year or a box to see those dates in the period table */
  $go = fn($d1, $d2, $g = null) => self_url(['reports' => 1, 'y' => (int)substr($d1, 0, 4), 'r' => 'custom', 'd1' => $d1, 'd2' => $d2] + ($g ? ['g' => $g] : []));
  $mHref = fn($k, $g = null) => $go("$k-01", date('Y-m-t', strtotime("$k-01")), $g);
  $yHref = fn($k, $g = null) => $go("$k-01-01", "$k-12-31", $g);
  if ($rg['span']) { $nDays = (int)round((strtotime($rg['d2']) - strtotime($rg['d1'])) / 86400) + 1; $byM = $nDays > 92; $prd = fomaxo_report_span($pdo, $rg['d1'], $rg['d2'], $byM); }
  else { $byM = false; $prd = $all; }
  $P = $tot($prd);
  $prdLabel = fn($k) => $rg['span'] ? date($byM ? 'M Y' : 'D j M', strtotime($byM ? "$k-01" : $k)) : (string)$k;
  $prdHref = $rg['span'] ? ($byM ? $mHref : null) : $yHref;
  $thisM = $rows[date('Y-m')] ?? null;
  $box = fn($val, $label, $href, $on, $cls = '') => '<a class="stat' . ($on ? ' on' : '') . '" href="' . h($href) . '"><b class="' . $cls . '">' . $val . '</b><span>' . $label . '</span></a>';
  $pf = fn($v) => ($v < 0 ? '−' : '') . money(abs($v));
  $isM = $rg['r'] === 'custom' && $rg['d1'] === date('Y-m-01') && $rg['d2'] === date('Y-m-t');
  $isY = $rg['r'] === 'custom' && $rg['d1'] === "$year-01-01" && $rg['d2'] === "$year-12-31";
  /* graph of the period: Sales, Profit, Orders or Expenses (a box opens the graph it names) */
  $g = in_array($_GET['g'] ?? '', ['sales', 'profit', 'orders', 'expenses'], true) ? $_GET['g'] : 'sales';
  $gl = ['sales' => 'Sales', 'profit' => 'Profit', 'orders' => 'Orders', 'expenses' => 'Expenses'];
  $gv = fn($m, $v) => $m === 'orders' ? (int)$v . ' order' . ((int)$v === 1 ? '' : 's') : $pf((float)$v);
  $graphs = ''; $gtot = '';
  foreach ($gl as $m => $l) {
    $pts = [];
    foreach ($prd as $k => $r) $pts[] = ['v' => max(0, (float)$r[$m]), 'l' => $rg['span'] ? date($byM ? 'M' : ($nDays <= 7 ? 'D' : 'j M'), strtotime($byM ? "$k-01" : $k)) : (string)$k, 't' => $prdLabel($k) . ': ' . $gv($m, $r[$m])];
    $graphs .= str_replace('<div class="graph"', '<div class="graph" data-g="' . $m . '"', fx_graph($pts, $l . ' ' . $rg['label'], $m !== $g, $m === 'orders' ? fn($v) => (int)$v . ' orders' : 'money', true));
    $gtot .= '<span data-g="' . $m . '"' . ($m === $g ? '' : ' hidden') . '>' . $gv($m, $P[$m] ?? 0) . '</span>';
  }
  $dmy = fn($d) => date('d/m/Y', strtotime($d));
  $gfrom = $rg['span'] ? $rg['d1'] : ($prd ? array_key_first($prd) . '-01-01' : date('Y-m-d')); $gto = $rg['span'] ? $rg['d2'] : date('Y-m-d');
  $gcard = '<section class="card rgraph"><div class="ch"><div class="seg" role="group" aria-label="Graph">' . implode('', array_map(fn($m, $l) => '<button type="button" data-g="' . $m . '"' . ($m === $g ? ' class="on"' : '') . '>' . $l . '</button>', array_keys($gl), $gl)) . '</div>'
    . '<span class="gdates">From <b>' . $dmy($gfrom) . '</b> to <b>' . $dmy($gto) . '</b><a class="gx" href="' . h(self_url(['reports' => 1, 'y' => $year])) . '" aria-label="Close graph">×</a></span></div>'
    . '<div class="chartbox">' . $graphs . '</div><p class="sub"><span class="tip">Tap a point to see the details</span><b class="tot">' . $gtot . '</b></p></section>';
  $yOpts = implode('', array_map(fn($y) => '<option' . ((int)$y === $year ? ' selected' : '') . '>' . (int)$y . '</option>', $years));
  page('Reports', '<div class="pagehead rg"><h1>Profit &amp; loss</h1>' . adm_range_form($rg, ['reports' => 1]) . '</div>'
    . ($thisM ? '<div class="stats rstats">' . $box(money($thisM['sales']), 'Sales this month', $mHref(date('Y-m'), 'sales'), $isM && $g === 'sales') . $box($pf($thisM['profit']), 'Profit this month', $mHref(date('Y-m'), 'profit'), $isM && $g === 'profit', $thisM['profit'] < 0 ? 'lvl-out' : 'lvl-ok')
             . $box(money($T['sales'] ?? 0), 'Sales ' . $year, $yHref($year, 'sales'), $isY && $g === 'sales') . $box($pf($T['profit'] ?? 0), 'Profit ' . $year, $yHref($year, 'profit'), $isY && $g === 'profit', ($T['profit'] ?? 0) < 0 ? 'lvl-out' : 'lvl-ok') . '</div>' : '')
    . '<form class="yearbar" method="get"><input type="hidden" name="reports" value="1"><label for="y">Year</label><select id="y" name="y" onchange="this.form.submit()">' . $yOpts . '</select>'
    . '<a class="btn line" href="' . h(self_url(['reports' => 1, 'y' => $year, 'export' => 1])) . '">Download Excel</a></form>'
    . ($missing ? '<p class="msg bad">' . $missing . ' order' . ($missing > 1 ? 's' : '') . ' in ' . $year . ' ha' . ($missing > 1 ? 've' : 's') . ' no cost price, so profit is too high. Type the cost of each bottle on the <a href="./?stock=1">Stock</a> page.</p>' : '')
    . '<div class="fill rfill"><h2>' . h($rg['r'] === 'all' ? 'All time by year' : $rg['label'] . ($byM ? ' by month' : ' by day')) . '</h2>' . (isset($_GET['g']) ? $gcard : '') . $table($prd, $prdLabel, ['Total', $P], $prdHref)
    . '<h2>' . $year . ' by month</h2>' . $table($rows, fn($k) => date('M Y', strtotime("$k-01")), ["Total $year", $T], $mHref)
    . ($rg['r'] === 'all' ? '' : '<h2>By year</h2>' . $table($all, fn($k) => (string)$k, null, $yHref))
    . '<p class="muted small after">Sales are what customers paid (VAT included, COD fee included) for New, Paid and Delivered orders; cancelled orders, unpaid card attempts and test payments are left out. Cost of goods is the cost price of the bottles sold, including free minis. Profit = sales − cost of goods − expenses. In ' . $year . ': discounts given ' . money($T['discount'] ?? 0) . ', COD fees ' . money($T['fees'] ?? 0) . ', stock bought ' . money($T['stock_bought'] ?? 0) . ' (not taken off profit).</p></div>'
    . '<script>document.querySelectorAll(".rgraph").forEach(function(c){var tip=c.querySelector(".tip");c.querySelectorAll(".hit").forEach(function(r){var s=function(){tip.textContent=r.dataset.t;c.querySelectorAll(".hit.on").forEach(function(x){x.classList.remove("on")});r.classList.add("on")};r.addEventListener("mouseenter",s);r.addEventListener("click",s)});'
    . 'c.querySelectorAll(".seg button").forEach(function(b){b.addEventListener("click",function(){c.querySelectorAll(".seg button").forEach(function(x){x.classList.toggle("on",x===b)});c.querySelectorAll(".graph,.tot span").forEach(function(x){x.hidden=x.dataset.g!==b.dataset.g});tip.textContent="Tap a point to see the details"})})});</script>', true, true);
}

/* one order */
/* ---- reviews: remove bad ones, star rating per product, customers who review the most ---- */
if (isset($_GET['reviews'])) {
  require_once dirname(__DIR__) . '/store-lib.php';
  require_once dirname(__DIR__) . '/reviews-lib.php';
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) { flash('Please try again.'); go(['reviews' => 1]); }
    $back = array_filter(['reviews' => 1, 'v' => (string)($_POST['v'] ?? ''), 'rp' => (string)($_POST['rp'] ?? ''), 'rq' => (string)($_POST['rq'] ?? ''), 'vf' => (string)($_POST['vf'] ?? '')], fn($x) => $x !== '');
    if (isset($_POST['reviewer_min'])) {
      $n = trim((string)$_POST['reviewer_min']);
      if (ctype_digit($n) && (int)$n >= 1 && (int)$n <= 1000) { fomaxo_setting($pdo, 'reviewer_min', (string)(int)$n); flash('Saved.', true); }
      go($back);
    }
    if (isset($_POST['reply'])) {   // the shop's public answer under a review; empty or Delete removes it
      $id = (string)($_POST['id'] ?? ''); $txt = isset($_POST['del']) ? '' : trim(str_replace("\r", '', mb_substr((string)$_POST['reply'], 0, 2000)));
      $ok = rv_change(function (&$list) use ($id, $txt) { foreach ($list as &$r) if ($r['id'] === $id) { if ($txt === '') unset($r['reply'], $r['reply_at']); else { $r['reply'] = $txt; $r['reply_at'] = date('c'); } return true; } return false; });
      flash($ok ? ($txt === '' ? 'Reply deleted.' : 'Reply saved. It shows under the review on the website.') : 'That reply could not be saved. Please try again.', (bool)$ok);
      go($back);
    }
    $id = (string)($_POST['id'] ?? ''); $hide = ($_POST['hide'] ?? '') === '1';
    $ok = rv_change(function (&$list) use ($id, $hide) { foreach ($list as &$r) if ($r['id'] === $id) { $r['hidden'] = $hide; return true; } return false; });
    flash($ok ? ($hide ? 'Review removed from the website.' : 'Review is back on the website.') : 'That review could not be changed. Please try again.', (bool)$ok);
    go($back);
  }
  $all = rv_all(); usort($all, fn($a, $b) => strcmp($b['created'], $a['created']));
  $rg = adm_range('reviews', 'all', ['today' => 'Today', '7' => '7 days', '30' => '30 days', 'all' => 'All']);
  if ($rg['span']) $all = array_values(array_filter($all, fn($r) => substr((string)$r['created'], 0, 10) >= $rg['d1'] && substr((string)$r['created'], 0, 10) <= $rg['d2']));
  $rbar = '<div class="rgbar">' . adm_range_form($rg, ['reviews' => 1] + array_filter(['v' => (string)($_GET['v'] ?? ''), 'rp' => (string)($_GET['rp'] ?? ''), 'rq' => (string)($_GET['rq'] ?? ''), 'vf' => (string)($_GET['vf'] ?? '')], fn($x) => $x !== '')) . '</div>';
  $v = in_array($_GET['v'] ?? '', ['products', 'people'], true) ? $_GET['v'] : 'all';
  $rp = (string)($_GET['rp'] ?? ''); if ($rp !== '' && !isset($CATALOG[$rp])) $rp = '';
  $live = array_filter($all, fn($r) => empty($r['hidden']));
  $rq = trim((string)($_GET['rq'] ?? ''));
  $pname = fn($id) => $CATALOG[$id]['name'] ?? $id;
  $stars = fn($n) => '<span class="stars" aria-label="' . (int)$n . ' stars">' . str_repeat('★', (int)$n) . '<i>' . str_repeat('★', 5 - (int)$n) . '</i></span>';
  $avg = $live ? round(array_sum(array_column($live, 'rating')) / count($live), 1) : 0;
  $rmin = (int)(fomaxo_setting($pdo, 'reviewer_min') ?? 2) ?: 2;
  $seg = '<nav class="seg rseg">' . implode('', array_map(fn($k, $l) => '<a href="' . h(self_url(['reviews' => 1] + ($k !== 'all' ? ['v' => $k] : []))) . '"' . ($v === $k ? ' class="on"' : '') . '>' . $l . '</a>',
         ['all', 'products', 'people'], ['Reviews', 'Stars By Product', 'Top Reviewers'])) . '</nav>';
  $hidden = fn($k, $val) => '<input type="hidden" name="' . $k . '" value="' . h($val) . '">';
  $replyBox = function ($r) use ($hidden) {   // your reply under a review: shown, then a box to write, change or delete it
    $rep = (string)($r['reply'] ?? ''); $keep = '';
    foreach (['rp', 'rq', 'vf', 'v'] as $k) if (($_GET[$k] ?? '') !== '') $keep .= $hidden($k, (string)$_GET[$k]);
    return ($rep !== '' ? '<div class="rrep"><b>Reply from FOMAXO</b><p>' . nl2br(h($rep)) . '</p></div>' : '')
      . '<div class="racts"><details class="rrf"><summary class="btn line sm">' . ($rep !== '' ? 'Edit reply' : 'Reply') . '</summary><form method="post">' . csrf_field() . $hidden('id', $r['id']) . $keep
      . '<textarea name="reply" rows="3" maxlength="2000" placeholder="Write your reply. It shows under this review on the website.">' . h($rep) . '</textarea>'
      . '<div class="emo" aria-label="Add an emoji" onclick="var b=event.target.closest(\'button\');if(!b)return;var t=this.closest(\'form\').querySelector(\'textarea\'),s=t.selectionStart,e=t.selectionEnd,x=b.textContent;t.value=t.value.slice(0,s)+x+t.value.slice(e);t.focus();t.selectionStart=t.selectionEnd=s+x.length">'
      . implode('', array_map(fn($e) => '<button type="button">' . $e . '</button>', ['🙏', '❤️', '😊', '✨', '🎁', '👍', '😍', '🥰', '🌸', '💐', '🤗', '😢'])) . '</div>'
      . '<div class="rrb"><button class="btn sm">Save reply</button>' . ($rep !== '' ? '<button class="btn line sm danger" name="del" value="1" onclick="return confirm(\'Delete your reply?\')">Delete reply</button>' : '') . '</div></form></details>';
  };

  if ($v === 'products') {
    $tr = '';
    foreach ($CATALOG as $id => $p) {
      $l = array_filter($live, fn($r) => $r['product'] === $id); $n = count($l);
      $a = $n ? round(array_sum(array_column($l, 'rating')) / $n, 1) : 0;
      $dist = ''; foreach ([5, 4, 3, 2, 1] as $s) { $c = count(array_filter($l, fn($r) => (int)$r['rating'] === $s)); $dist .= '<span>' . $s . '★ <b>' . $c . '</b></span>'; }
      $tr .= '<tr class="row" onclick="location.href=this.dataset.href" data-href="' . h(self_url(['reviews' => 1, 'rp' => $id])) . '"><td><b>' . h($p['name']) . '</b></td>'
           . '<td>' . ($n ? $stars(round($a)) . ' <b>' . number_format($a, 1) . '</b>' : '<span class="muted">No reviews yet</span>') . '</td>'
           . '<td class="num">' . $n . '<span class="ph"> reviews</span></td><td class="dist small muted">' . $dist . '</td></tr>';
    }
    $body = '<div class="fill"><table class="rprod"><thead><tr><th>Product</th><th>Stars</th><th class="num">Reviews</th><th>Breakdown</th></tr></thead><tbody>' . $tr . '</tbody></table>'
          . '<p class="muted small after">Only reviews on the website count. Tap a product to see its reviews.</p></div>';
  } elseif ($v === 'people') {
    $ppl = [];
    foreach ($live as $r) {
      $real = trim((string)(!empty($r['anon']) ? ($r['real'] ?? $r['name']) : $r['name'])); if ($real === '') continue;
      $k = mb_strtolower($real); $c = &$ppl[$k];
      $c['name'] ??= $real; $c['n'] = ($c['n'] ?? 0) + 1; $c['sum'] = ($c['sum'] ?? 0) + (int)$r['rating'];
      $c['verified'] = ($c['verified'] ?? 0) + (!empty($r['verified']) ? 1 : 0); $c['last'] ??= $r['created'];
      $c['products'][$r['product']] = true; unset($c);
    }
    $ppl = array_filter($ppl, fn($c) => $c['n'] >= $rmin);
    uasort($ppl, fn($a, $b) => $b['n'] <=> $a['n'] ?: strcmp($b['last'], $a['last']));
    $tr = '';
    foreach ($ppl as $c) $tr .= '<tr class="row"><td><b>' . h($c['name']) . '</b><div class="small muted">' . h(implode(', ', array_map($pname, array_keys($c['products'])))) . '</div></td>'
      . '<td class="num"><b>' . $c['n'] . '</b><span class="ph"> reviews</span></td><td>' . $stars(round($c['sum'] / $c['n'])) . ' <span class="small">' . number_format($c['sum'] / $c['n'], 1) . '</span></td>'
      . '<td class="small muted">' . ($c['verified'] ? $c['verified'] . ' verified · ' : '') . 'last ' . h(date('d M Y', strtotime($c['last']))) . '</td></tr>';
    $body = '<form class="card mmin" method="post">' . csrf_field() . $hidden('v', 'people') . '<label for="reviewer_min">Show customers with at least</label>'
          . '<input id="reviewer_min" type="number" min="1" max="1000" inputmode="numeric" name="reviewer_min" required value="' . $rmin . '"><span>reviews</span><button class="btn sm">Save</button></form>'
          . '<div class="fill">' . ($tr ? '<table class="rppl"><thead><tr><th>Customer</th><th class="num">Reviews</th><th>Average stars</th><th>Details</th></tr></thead><tbody>' . $tr . '</tbody></table>'
              : '<p class="card muted" style="margin:0">No customer has ' . $rmin . ' or more reviews yet.</p>')
          . '<p class="muted small after">Reviews with the same name count as one customer. Anonymous reviews use the real name the customer typed. Removed reviews are left out.</p></div>';
  } else {
    $list = $rp !== '' ? array_filter($all, fn($r) => $r['product'] === $rp) : $all;
    $vf = in_array($_GET['vf'] ?? '', ['1', '0'], true) ? $_GET['vf'] : '';   // Verified purchaser / Unverified
    $nv = count(array_filter($list, fn($r) => !empty($r['verified']))); $nu = count($list) - $nv;
    if ($vf !== '') $list = array_filter($list, fn($r) => !empty($r['verified']) === ($vf === '1'));
    $tel = [];   // mobile and email of verified reviews, from their order
    $nos = array_values(array_unique(array_filter(array_column($list, 'order'))));
    if ($nos) { $s = $pdo->prepare('SELECT order_no, phone, email FROM fx_orders WHERE order_no IN (' . implode(',', array_fill(0, count($nos), '?')) . ')'); $s->execute($nos); foreach ($s as $o) $tel[$o['order_no']] = $o; }
    if ($rq !== '') {   // words, name, mobile, email, city, product or order no
      $d = preg_replace('/\D/', '', $rq); $q = mb_strtolower($rq);
      $list = array_filter($list, function ($r) use ($q, $d, $tel, $pname) {
        $o = $tel[$r['order'] ?? ''] ?? ['phone' => '', 'email' => ''];
        $hay = mb_strtolower(implode(' ', [$r['text'], $r['name'], $r['real'] ?? '', $r['city'] ?? '', $pname($r['product']), $r['order'] ?? '', $o['phone'], $o['email']]));
        return str_contains($hay, $q) || (strlen($d) >= 4 && str_contains(preg_replace('/\D/', '', $o['phone']), $d));
      });
    }
    $items = '';
    foreach ($list as $r) {
      $off = !empty($r['hidden']); $where = trim(($r['city'] ?? '') . ' ' . ($r['country'] ?? ''));
      $ph = ''; foreach ($r['photos'] ?? [] as $p) $ph .= '<a href="../reviews.php?photo=' . h(rawurlencode($p)) . '" target="_blank" rel="noopener"><img src="../reviews.php?photo=' . h(rawurlencode($p)) . '" alt="" loading="lazy"></a>';
      $items .= '<li class="rv' . ($off ? ' off' : '') . '"><div class="rh"><b>' . h($pname($r['product'])) . '</b> ' . $stars($r['rating']) . ($off ? ' <span class="tag s-Cancelled">Removed</span>' : '') . '</div>'
        . '<div class="small muted">' . h($r['name']) . (!empty($r['anon']) ? ' (real name: ' . h($r['real'] ?? '') . ')' : '') . ($where !== '' ? ' · ' . h($where) : '')
        . (!empty($tel[$r['order'] ?? '']['phone']) ? ' · ' . h($tel[$r['order']]['phone']) : '')
        . ' · ' . (!empty($r['verified']) ? 'Verified Purchaser' . (!empty($r['order']) ? ', ' . h($r['order']) : '') : 'not verified') . ' · ' . h(date('d M Y', strtotime($r['created']))) . '</div>'
        . '<p>' . nl2br(h($r['text'])) . '</p>' . ($ph ? '<div class="rph">' . $ph . '</div>' : '') . $replyBox($r)
        . '<form method="post" onsubmit="return ' . ($off ? 'true' : 'confirm(\'Remove this review from the website?\')') . '">' . csrf_field() . $hidden('id', $r['id']) . $hidden('hide', $off ? '0' : '1') . ($rp !== '' ? $hidden('rp', $rp) : '') . ($rq !== '' ? $hidden('rq', $rq) : '') . ($vf !== '' ? $hidden('vf', $vf) : '')
        . '<button class="btn sm' . ($off ? ' line' : ' danger') . '">' . ($off ? 'Put back on website' : 'Remove') . '</button></form></div></li>';
    }
    $opts = '<option value="">All products</option>'; foreach ($CATALOG as $id => $p) $opts .= '<option value="' . h($id) . '"' . ($rp === $id ? ' selected' : '') . '>' . h($p['name']) . '</option>';
    $vq = ['reviews' => 1] + ($rp !== '' ? ['rp' => $rp] : []) + ($rq !== '' ? ['rq' => $rq] : []);
    $chip = fn($v, $label, $n) => '<a class="em' . ($vf === $v ? ' on' : '') . '" href="' . h(self_url($vf === $v ? $vq : $vq + ['vf' => $v])) . '">' . $label . ' <b>' . $n . '</b></a>';
    $body = '<form class="rfilter" method="get"><input type="hidden" name="reviews" value="1">' . ($vf !== '' ? '<input type="hidden" name="vf" value="' . $vf . '">' : '') . '<select name="rp" aria-label="Product" onchange="this.form.submit()">' . $opts . '</select>'
          . '<input name="rq" value="' . h($rq) . '" placeholder="Search words, name or mobile" aria-label="Search reviews"><button class="btn line sm">Search</button>' . ($rq !== '' ? '<a class="small" href="' . h(self_url(['reviews' => 1] + ($rp !== '' ? ['rp' => $rp] : []))) . '">Clear</a>' : '')
          . '<nav class="ems vcount" aria-label="Verified or not">' . $chip('1', 'Verified purchaser', $nv) . $chip('0', 'Unverified', $nu) . '</nav></form>'
          . '<div class="fill">' . ($items ? '<ul class="rvlist">' . $items . '</ul>' : '<p class="card muted" style="margin:0">' . ($rq !== '' ? 'No reviews match.' : 'No reviews yet.') . '</p>')
          . '<p class="muted small after">Remove takes a review off the website and out of the star rating. It stays here, so you can put it back.</p></div>';
  }
  $mob = '<div class="pagehead"><h1>Reviews</h1>' . $seg . '</div>' . $rbar
    . '<div class="stats up"><div class="stat"><span>On website</span><b>' . count($live) . '</b></div><div class="stat"><span>Average stars</span><b>' . number_format($avg, 1) . ' ★</b></div>'
    . '<div class="stat"><span>Verified</span><b>' . count(array_filter($live, fn($r) => !empty($r['verified']))) . '</b></div><div class="stat"><span>Removed</span><b>' . (count($all) - count($live)) . '</b></div></div>'
    . $body;

  // laptop: one screen with the list, Stars by product and Top reviewers side by side (phone keeps the page above)
  {   // stars by product
    $tr = '';
    foreach ($CATALOG as $id => $p) {
      $l = array_filter($live, fn($r) => $r['product'] === $id); $n = count($l);
      $a = $n ? round(array_sum(array_column($l, 'rating')) / $n, 1) : 0;
      $dist = ''; foreach ([5, 4, 3, 2, 1] as $s) { $c = count(array_filter($l, fn($r) => (int)$r['rating'] === $s)); $dist .= '<span>' . $s . '★ <b>' . $c . '</b></span>'; }
      if (!$n) continue;
      $tr .= '<tr class="row' . ($rp === $id ? ' on' : '') . '" onclick="location.href=this.dataset.href" data-href="' . h(self_url(['reviews' => 1] + ($rp === $id ? [] : ['rp' => $id]))) . '"><td><b>' . h($p['name']) . '</b><div class="dist small muted">' . $dist . '</div></td>'
           . '<td class="num">' . $stars(round($a)) . ' <b>' . number_format($a, 1) . '</b><div class="small muted">' . $n . ' review' . ($n > 1 ? 's' : '') . '</div></td></tr>';
    }
    $bodyP = $tr ? '<table class="rprod"><tbody>' . $tr . '</tbody></table>' : '<p class="muted empty">No live reviews yet.</p>';
  }
  {   // top reviewers
    $ppl = [];
    foreach ($live as $r) {
      $real = trim((string)(!empty($r['anon']) ? ($r['real'] ?? $r['name']) : $r['name'])); if ($real === '') continue;
      $k = mb_strtolower($real); $c = &$ppl[$k];
      $c['name'] ??= $real; $c['n'] = ($c['n'] ?? 0) + 1; $c['sum'] = ($c['sum'] ?? 0) + (int)$r['rating'];
      $c['verified'] = ($c['verified'] ?? 0) + (!empty($r['verified']) ? 1 : 0); $c['last'] ??= $r['created'];
      $c['products'][$r['product']] = true; unset($c);
    }
    $ppl = array_filter($ppl, fn($c) => $c['n'] >= $rmin);
    uasort($ppl, fn($a, $b) => $b['n'] <=> $a['n'] ?: strcmp($b['last'], $a['last']));
    $tr = '';
    foreach ($ppl as $c) $tr .= '<tr class="row"><td><b>' . h($c['name']) . '</b><div class="small muted">' . h(implode(', ', array_map($pname, array_keys($c['products'])))) . '</div></td>'
      . '<td class="num"><b>' . $c['n'] . '</b><span class="ph"> reviews</span></td><td>' . $stars(round($c['sum'] / $c['n'])) . ' <span class="small">' . number_format($c['sum'] / $c['n'], 1) . '</span></td>'
      . '<td class="small muted">' . ($c['verified'] ? $c['verified'] . ' verified · ' : '') . 'last ' . h(date('d M Y', strtotime($c['last']))) . '</td></tr>';
    $formR = '<form class="rmin" method="post">' . csrf_field() . '<label for="reviewer_min_d">at least</label>'
          . '<input id="reviewer_min_d" type="number" min="1" max="1000" inputmode="numeric" name="reviewer_min" required value="' . $rmin . '"><span>reviews</span><button class="btn line sm">Save</button></form>';
    $bodyR = $tr ? '<table class="rppl"><tbody>' . $tr . '</tbody></table>' : '<p class="muted empty">Nobody has ' . $rmin . ' or more reviews yet.</p>';
  }
  {   // the reviews
    $list = $rp !== '' ? array_filter($all, fn($r) => $r['product'] === $rp) : $all;
    $vf = in_array($_GET['vf'] ?? '', ['1', '0'], true) ? $_GET['vf'] : '';   // Verified purchaser / Unverified
    $nv = count(array_filter($list, fn($r) => !empty($r['verified']))); $nu = count($list) - $nv;
    if ($vf !== '') $list = array_filter($list, fn($r) => !empty($r['verified']) === ($vf === '1'));
    $tel = [];   // mobile and email of verified reviews, from their order
    $nos = array_values(array_unique(array_filter(array_column($list, 'order'))));
    if ($nos) { $s = $pdo->prepare('SELECT order_no, phone, email FROM fx_orders WHERE order_no IN (' . implode(',', array_fill(0, count($nos), '?')) . ')'); $s->execute($nos); foreach ($s as $o) $tel[$o['order_no']] = $o; }
    if ($rq !== '') {   // words, name, mobile, email, city, product or order no
      $d = preg_replace('/\D/', '', $rq); $q = mb_strtolower($rq);
      $list = array_filter($list, function ($r) use ($q, $d, $tel, $pname) {
        $o = $tel[$r['order'] ?? ''] ?? ['phone' => '', 'email' => ''];
        $hay = mb_strtolower(implode(' ', [$r['text'], $r['name'], $r['real'] ?? '', $r['city'] ?? '', $pname($r['product']), $r['order'] ?? '', $o['phone'], $o['email']]));
        return str_contains($hay, $q) || (strlen($d) >= 4 && str_contains(preg_replace('/\D/', '', $o['phone']), $d));
      });
    }
    $items = '';
    foreach ($list as $r) {
      $off = !empty($r['hidden']); $where = trim(($r['city'] ?? '') . ' ' . ($r['country'] ?? ''));
      $ph = ''; foreach ($r['photos'] ?? [] as $p) $ph .= '<a href="../reviews.php?photo=' . h(rawurlencode($p)) . '" target="_blank" rel="noopener"><img src="../reviews.php?photo=' . h(rawurlencode($p)) . '" alt="" loading="lazy"></a>';
      $items .= '<li class="rv' . ($off ? ' off' : '') . '"><div class="rh"><b>' . h($pname($r['product'])) . '</b> ' . $stars($r['rating']) . ($off ? ' <span class="tag s-Cancelled">Removed</span>' : '') . '</div>'
        . '<div class="small muted">' . h($r['name']) . (!empty($r['anon']) ? ' (real name: ' . h($r['real'] ?? '') . ')' : '') . ($where !== '' ? ' · ' . h($where) : '')
        . (!empty($tel[$r['order'] ?? '']['phone']) ? ' · ' . h($tel[$r['order']]['phone']) : '')
        . ' · ' . (!empty($r['verified']) ? 'Verified Purchaser' . (!empty($r['order']) ? ', ' . h($r['order']) : '') : 'not verified') . ' · ' . h(date('d M Y', strtotime($r['created']))) . '</div>'
        . '<p>' . nl2br(h($r['text'])) . '</p>' . ($ph ? '<div class="rph">' . $ph . '</div>' : '') . $replyBox($r)
        . '<form method="post" onsubmit="return ' . ($off ? 'true' : 'confirm(\'Remove this review from the website?\')') . '">' . csrf_field() . $hidden('id', $r['id']) . $hidden('hide', $off ? '0' : '1') . ($rp !== '' ? $hidden('rp', $rp) : '') . ($rq !== '' ? $hidden('rq', $rq) : '') . ($vf !== '' ? $hidden('vf', $vf) : '')
        . '<button class="btn sm' . ($off ? ' line' : ' danger') . '">' . ($off ? 'Put back on website' : 'Remove') . '</button></form></div></li>';
    }
    $opts = '<option value="">All products</option>'; foreach ($CATALOG as $id => $p) $opts .= '<option value="' . h($id) . '"' . ($rp === $id ? ' selected' : '') . '>' . h($p['name']) . '</option>';
    $vq = ['reviews' => 1] + ($rp !== '' ? ['rp' => $rp] : []) + ($rq !== '' ? ['rq' => $rq] : []);
    $chip = fn($v, $label, $n) => '<a class="em' . ($vf === $v ? ' on' : '') . '" href="' . h(self_url($vf === $v ? $vq : $vq + ['vf' => $v])) . '">' . $label . ' <b>' . $n . '</b></a>';
    $body = '<form class="rfilter" method="get"><input type="hidden" name="reviews" value="1">' . ($vf !== '' ? '<input type="hidden" name="vf" value="' . $vf . '">' : '') . ($rp !== '' ? '<input type="hidden" name="rp" value="' . h($rp) . '">' : '')
          . '<input name="rq" value="' . h($rq) . '" placeholder="Words, name or mobile" aria-label="Search reviews"><button class="btn line sm">Search</button>' . ($rq !== '' ? '<a class="small" href="' . h(self_url(['reviews' => 1] + ($rp !== '' ? ['rp' => $rp] : []))) . '">Clear</a>' : '')
          . '<nav class="ems vcount" aria-label="Verified or not">' . $chip('1', 'Verified purchaser', $nv) . $chip('0', 'Unverified', $nu) . '</nav></form>'
          . '<div class="rgrid"><section class="card rlist"><p class="rnote small muted">' . count($list) . ' review' . (count($list) === 1 ? '' : 's') . ($rp !== '' ? ' of ' . h($pname($rp)) . ' · <a href="' . h(self_url(['reviews' => 1] + ($rq !== '' ? ['rq' => $rq] : []) + ($vf !== '' ? ['vf' => $vf] : []))) . '">all products</a>' : '')
          . '. Removed reviews leave the website and the star rating; Put back shows them again.</p><div class="cscroll">' . ($items ? '<ul class="rvlist">' . $items . '</ul>' : '<p class="muted empty">' . ($rq !== '' ? 'No reviews match.' : 'No reviews yet.') . '</p>') . '</div></section>'
          . '<section class="card rprodc"><h2>Stars By Product</h2><div class="cscroll">' . $bodyP . '</div></section>'
          . '<section class="card rpplc"><div class="ch"><h2>Top Reviewers</h2>' . $formR . '</div><div class="cscroll">' . $bodyR . '</div></section></div>';
  }
  page('Reviews', flash() . '<div class="rmob">' . $mob . '</div><div class="rdesk">' . $rbar . $body . '</div>', true, true);
}

/* ---- members: customers who keep coming back. Orders are grouped by mobile number (last 9 digits), or by email when there is no mobile ---- */
if (isset($_GET['members'])) {
  if (isset($_GET['subs'])) {   // email list from the website sign-up forms (subscribe.php), as a CSV that opens straight in Excel
    $rows = [];
    try { fomaxo_subscribers_table($pdo); $rows = $pdo->query('SELECT email, interest, page, created_at, updated_at FROM fx_subscribers ORDER BY created_at DESC')->fetchAll(); } catch (Throwable $e) {}
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="fomaxo-email-list-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
    $cell = fn($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v;
    fputcsv($out, ['Email', 'Signed up for', 'Page', 'First signed up', 'Last signed up']);
    foreach ($rows as $s) fputcsv($out, array_map($cell, [$s['email'], $s['interest'], $s['page'], $s['created_at'], $s['updated_at']]));
    exit;
  }
  $subsN = 0; try { fomaxo_subscribers_table($pdo); $subsN = (int)$pdo->query('SELECT COUNT(*) FROM fx_subscribers')->fetchColumn(); } catch (Throwable $e) {}
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) { flash('Please try again.'); go(['members' => 1]); }
    /* two separate boxes, each saved on its own; an empty box means "no limit" */
    if (isset($_POST['member_min'])) {
      $n = trim((string)$_POST['member_min']);
      if ($n === '' || (ctype_digit($n) && (int)$n <= 1000)) { fomaxo_setting($pdo, 'member_min', $n === '' ? '0' : (string)(int)$n); flash('Saved.', true); }
    }
    if (isset($_POST['member_spend'])) {
      $a = trim(str_replace(',', '', (string)$_POST['member_spend']));
      if ($a === '' || (is_numeric($a) && (float)$a >= 0 && (float)$a <= 10000000)) { fomaxo_setting($pdo, 'member_spend', $a === '' ? '0' : (string)round((float)$a)); flash('Saved.', true); }
    }
    go(['members' => 1]);
  }
  $min = (int)(fomaxo_setting($pdo, 'member_min') ?? 5);
  $spend = (int)(fomaxo_setting($pdo, 'member_spend') ?? 0);
  $key = function ($o) { $d = preg_replace('/\D/', '', (string)$o['phone']); return strlen($d) >= 7 ? 'm' . substr($d, -9) : ($o['email'] !== '' ? 'e' . mb_strtolower(trim($o['email'])) : ''); };
  $addr = fn($o) => $o['address'] !== '' ? $o['address'] : implode(', ', array_filter([$o['building'], $o['room'], $o['street'], $o['area']], fn($x) => $x !== ''));
  $waLink = function ($phone) { $wa = preg_replace('/\D/', '', (string)$phone); if (str_starts_with($wa, '05')) $wa = '971' . substr($wa, 1); return $wa; };
  /* every real order (not cancelled, not unpaid card, not a test), oldest first so the latest name and address win */
  $all = $pdo->query("SELECT order_no, created_at, payment, status, total, name, phone, email, emirate, building, room, street, area, address, items, test
                      FROM fx_orders WHERE status IN ('New','Paid','Delivered') AND test = 0 ORDER BY created_at, id")->fetchAll();
  $rg = isset($_GET['c']) ? null : adm_range('members', 'all', ['today' => 'Today', '7' => '7 days', '30' => '30 days', 'all' => 'All']);
  if ($rg && $rg['span']) $all = array_values(array_filter($all, fn($o) => $o['created_at'] >= $rg['span'][0] && $o['created_at'] <= $rg['span'][1]));   // the list counts only orders in the period
  $cust = []; $allSales = 0;
  foreach ($all as $o) {
    $allSales += (float)$o['total'];
    if (($k = $key($o)) === '') continue;
    $c = &$cust[$k];
    $c['n'] = ($c['n'] ?? 0) + 1; $c['spent'] = ($c['spent'] ?? 0) + (float)$o['total'];
    $c['first'] ??= $o['created_at']; $c['last'] = $o['created_at'];
    foreach (['name', 'phone', 'email', 'emirate'] as $f2) if ($o[$f2] !== '') $c[$f2] = $o[$f2];
    if ($addr($o) !== '') $c['addr'] = $addr($o);
    $c['orders'][] = $o;
    unset($c);
  }

  if (isset($_GET['c'])) {   // one customer: everything we know — details, every order, reviews, visits to the website
    $ck = (string)$_GET['c']; $c = $cust[$ck] ?? null;
    /* every order of this customer, also cancelled and unpaid ones (the totals above count only real orders) */
    $allO = array_values(array_filter($pdo->query("SELECT order_no, created_at, payment, status, total, discount, name, phone, email, emirate, building, room, street, area, address, items, note, test FROM fx_orders WHERE test = 0 ORDER BY created_at DESC, id DESC")->fetchAll(), fn($o) => $key($o) === $ck));
    if (!$c && !$allO) page('Not found', '<h1>Customer not found</h1><p><a href="./?members=1">Back to members</a></p>');
    if (!$c) { $o = $allO[0]; $c = ['n' => 0, 'spent' => 0, 'first' => end($allO)['created_at'], 'last' => $o['created_at'], 'name' => $o['name'], 'phone' => $o['phone'] ?: null, 'email' => $o['email'] ?: null, 'emirate' => $o['emirate'] ?: null, 'addr' => $addr($o)]; }
    $wa = $waLink($c['phone'] ?? '');
    $nos = array_column($allO, 'order_no');
    $d9 = substr(preg_replace('/\D/', '', (string)($c['phone'] ?? '')), -9);
    $row = fn($k, $v) => $v === '' || $v === null ? '' : '<dt>' . h($k) . '</dt><dd>' . $v . '</dd>';

    /* reviews: the ones written from this customer's order links, plus any with the same name */
    require_once dirname(__DIR__) . '/store-lib.php';
    require_once dirname(__DIR__) . '/reviews-lib.php';
    $nm = mb_strtolower(trim((string)($c['name'] ?? '')));
    $revs = array_filter(rv_all(), fn($r) => (!empty($r['order']) && in_array($r['order'], $nos, true))
      || ($nm !== '' && mb_strtolower(trim((string)(!empty($r['anon']) ? ($r['real'] ?? '') : $r['name']))) === $nm));
    usort($revs, fn($a, $b) => strcmp($b['created'], $a['created']));

    /* website visits: the browsers that checked out with this mobile number or placed one of these orders */
    $web = null;
    try {
      $w = []; $args = [];
      if (strlen($d9) >= 7) { $w[] = "RIGHT(REGEXP_REPLACE(phone, '[^0-9]', ''), 9) = ?"; $args[] = $d9; }
      if ($nos) { $w[] = 'order_no IN (' . implode(',', array_fill(0, count($nos), '?')) . ')'; $args = array_merge($args, $nos); }
      if ($w) {
        $s = $pdo->prepare('SELECT vid, sid, stage, order_no, updated_at, total FROM fx_leads WHERE ' . implode(' OR ', $w)); $s->execute($args); $leads = $s->fetchAll();
        $vids = array_values(array_unique(array_column($leads, 'vid')));
        if ($vids) {
          $in = implode(',', array_fill(0, count($vids), '?'));
          $s = $pdo->prepare("SELECT sid, MIN(at) a, MAX(at) b, COUNT(*) n, SUM(ev = 'view') pv, SUM(ev = 'product') prod, SUM(ev = 'cart') cart,
                              MAX(source) src, MAX(device) dev FROM fx_events WHERE vid IN ($in) GROUP BY sid ORDER BY a DESC"); $s->execute($vids); $ses = $s->fetchAll();
          $s = $pdo->prepare("SELECT product, COUNT(*) n FROM fx_events WHERE vid IN ($in) AND ev = 'product' AND product IS NOT NULL GROUP BY product ORDER BY n DESC LIMIT 5"); $s->execute($vids); $viewed = $s->fetchAll();
          $secs = 0; foreach ($ses as $x) $secs += min(3 * 3600, strtotime($x['b']) - strtotime($x['a']));   // a visit's time = first to last step (capped at 3 hours)
          $web = ['ses' => $ses, 'secs' => $secs, 'viewed' => $viewed, 'left' => array_filter($leads, fn($l) => empty($l['order_no']) && !in_array($l['order_no'], $nos, true)),
                  'pv' => array_sum(array_column($ses, 'pv')), 'devices' => array_unique(array_filter(array_column($ses, 'dev'))),
                  'source' => $ses ? (end($ses)['src'] ?: 'Direct') : ''];
        }
      }
    } catch (Throwable $e) { $web = null; }
    $dur = function ($s) { $s = (int)$s; return $s >= 3600 ? floor($s / 3600) . ' h ' . floor($s % 3600 / 60) . ' min' : ($s >= 60 ? floor($s / 60) . ' min' : $s . ' sec'); };

    $tr = '';
    foreach ($allO as $o) {
      $u = h(self_url(['o' => $o['order_no']]));
      $tr .= '<tr class="row" onclick="location.href=\'' . $u . '\'"><td class="no"><a href="' . $u . '">' . h($o['order_no']) . '</a></td>'
           . '<td>' . h(date('d M Y, H:i', strtotime($o['created_at']))) . '</td>'
           . '<td>' . h($o['name']) . '<div class="muted small">' . h($o['emirate']) . '</div></td>'
           . '<td class="small">' . h(str_replace(' | ', ', ', (string)$o['items'])) . '</td>'
           . '<td>' . h($o['payment'] === 'Cash on delivery' ? 'Cash' : 'Card') . '</td>'
           . '<td><span class="tag s-' . h(strtok($o['status'], ' ')) . '">' . h($o['status']) . '</span></td>'
           . '<td class="num">' . money($o['total']) . '</td></tr>';
    }
    $stars = fn($n) => '<span class="stars">' . str_repeat('★', (int)$n) . '<i>' . str_repeat('★', 5 - (int)$n) . '</i></span>';
    $rv = '';
    foreach ($revs as $r) $rv .= '<li class="rv' . (!empty($r['hidden']) ? ' off' : '') . '"><div class="rh"><b>' . h($CATALOG[$r['product']]['name'] ?? $r['product']) . '</b> ' . $stars($r['rating'])
      . (!empty($r['hidden']) ? ' <span class="tag s-Cancelled">Removed</span>' : '') . '</div><div class="small muted">' . h(date('d M Y', strtotime($r['created']))) . ' · ' . (!empty($r['verified']) ? 'Verified Purchaser' : 'not verified') . '</div><p>' . nl2br(h($r['text'])) . '</p></li>';
    $act = '';
    if ($web) {
      $act .= '<dl>' . $row('Visits', count($web['ses'])) . $row('Time on site', 'about ' . $dur($web['secs'])) . $row('Pages viewed', (int)$web['pv'])
        . $row('First visit', h(date('d M Y', strtotime(end($web['ses'])['a'])))) . $row('Last visit', h(date('d M Y, H:i', strtotime($web['ses'][0]['b']))))
        . $row('Came from', h($web['source'])) . $row('Device', h(implode(', ', $web['devices'])))
        . $row('Products looked at', h(implode(', ', array_map(fn($v) => ($CATALOG[$v['product']]['name'] ?? $v['product']) . ' (' . $v['n'] . ')', $web['viewed']))))
        . $row('Left at checkout', count($web['left']) ? count($web['left']) . ' time' . (count($web['left']) > 1 ? 's' : '') : '') . '</dl>';
      $act .= '<ul class="vlist">' . implode('', array_map(fn($x) => '<li><span>' . h(date('d M Y, H:i', strtotime($x['a']))) . '</span><span class="muted">' . $dur(strtotime($x['b']) - strtotime($x['a'])) . ' · ' . (int)$x['pv'] . ' pages'
        . ($x['cart'] ? ' · added to bag' : '') . '</span></li>', array_slice($web['ses'], 0, 30))) . '</ul>';
      $act .= '<p class="muted small" style="margin:8px 0 0">Visits are linked through the phone or computer this customer used to check out. Visits from other devices, and visits before 5 Oct 2026, cannot be linked. Time on site is approximate.</p>';
    } else $act = '<p class="muted small" style="margin:0">No website visits can be linked to this customer yet. Visits are linked once they check out on the website with this mobile number (counting started 5 Oct 2026, and orders placed on WhatsApp have no visits).</p>';

    page($c['name'] ?? 'Customer', '<div class="pagehead"><div><p class="small" style="margin:0 0 2px"><a href="./?members=1">← All members</a></p>'
      . '<h1>' . h($c['name'] ?? 'No name') . ($c['n'] && $c['n'] >= $min && $c['spent'] >= $spend ? ' <span class="tag mtag">Member</span>' : '') . '</h1></div>'
      . ($wa ? '<a class="btn line sm" href="https://wa.me/' . h($wa) . '" target="_blank" rel="noopener">WhatsApp</a>' : '') . '</div>'
      . '<div class="stats up cstats"><div class="stat"><span>Orders</span><b>' . (int)$c['n'] . '</b></div><div class="stat"><span>Total spent</span><b>' . money($c['spent']) . '</b></div>'
      . '<div class="stat"><span>Average order</span><b>' . money($c['n'] ? $c['spent'] / $c['n'] : 0) . '</b></div><div class="stat"><span>Reviews</span><b>' . count($revs) . '</b></div>'
      . '<div class="stat"><span>Visits</span><b>' . ($web ? count($web['ses']) : '—') . '</b></div><div class="stat"><span>Time on site</span><b>' . ($web ? $dur($web['secs']) : '—') . '</b></div></div>'
      . '<div class="cgrid"><section class="card cdet"><h2>Details</h2><dl>' . $row('Mobile', isset($c['phone']) ? h($c['phone']) . ($wa ? ' · <a href="https://wa.me/' . h($wa) . '" target="_blank" rel="noopener">WhatsApp</a>' : '') : '')
      . $row('Email', isset($c['email']) ? '<a href="mailto:' . h($c['email']) . '">' . h($c['email']) . '</a>' : '')
      . $row('Address', h($c['addr'] ?? '')) . $row('Emirate', h($c['emirate'] ?? '')) . $row('First order', h(date('d M Y', strtotime($c['first'])))) . $row('Last order', h(date('d M Y', strtotime($c['last']))))
      . $row('All orders', count($allO) . (count($allO) > $c['n'] ? ' <span class="muted">(' . (count($allO) - $c['n']) . ' cancelled or unpaid)</span>' : '')) . '</dl>'
      . '<h2>On the website</h2>' . $act . '</section>'
      . '<section class="card cord"><h2>Orders <span class="muted small">' . count($allO) . '</span></h2><div class="cscroll"><table class="olist"><thead><tr><th>Order</th><th>Date</th><th>Name</th><th>Items</th><th>Pay</th><th>Status</th><th class="num">Total</th></tr></thead><tbody>' . $tr . '</tbody></table></div></section>'
      . '<section class="card crev"><h2>Reviews <span class="muted small">' . count($revs) . '</span></h2><div class="cscroll">' . ($rv ? '<ul class="rvlist">' . $rv . '</ul>' : '<p class="muted small" style="margin:0">No reviews yet.</p>') . '</div></section></div>', true, true);
  }

  $mem = array_filter($cust, fn($c) => $c['n'] >= $min && $c['spent'] >= $spend);
  uasort($mem, fn($a, $b) => $b['spent'] <=> $a['spent'] ?: $b['n'] <=> $a['n']);
  $mq = trim((string)($_GET['mq'] ?? ''));
  $list = $mq === '' ? $mem : array_filter($mem, function ($c) use ($mq) {
    $hay = mb_strtolower(implode(' ', [$c['name'] ?? '', $c['phone'] ?? '', $c['email'] ?? '', $c['emirate'] ?? '', $c['addr'] ?? '']));
    $d = preg_replace('/\D/', '', $mq);
    return str_contains($hay, mb_strtolower($mq)) || (strlen($d) >= 4 && str_contains(preg_replace('/\D/', '', $c['phone'] ?? ''), $d));
  });

  if (isset($_GET['export'])) {   // CSV that opens straight in Excel
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="fomaxo-members-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
    $cell = fn($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) && !preg_match('/^\+?[\d\s()\-]+$/', $v) ? "'" . $v : $v;
    fputcsv($out, ['Name', 'Mobile', 'Email', 'Emirate', 'Address', 'Orders', 'Total spent (AED)', 'Average order (AED)', 'First order', 'Last order']);
    foreach ($list as $c) fputcsv($out, array_map($cell, [$c['name'] ?? '', $c['phone'] ?? '', $c['email'] ?? '', $c['emirate'] ?? '', $c['addr'] ?? '', $c['n'],
      number_format($c['spent'], 2, '.', ''), number_format($c['spent'] / $c['n'], 2, '.', ''), date('Y-m-d', strtotime($c['first'])), date('Y-m-d', strtotime($c['last']))]));
    exit;
  }

  $memSpent = array_sum(array_column($mem, 'spent'));
  $tr = '';
  foreach ($list as $k => $c) {
    $u = h(self_url(['members' => 1, 'c' => $k])); $wa = $waLink($c['phone'] ?? '');
    $tr .= '<tr class="row" onclick="location.href=\'' . $u . '\'"><td class="mn"><a href="' . $u . '"><b>' . h($c['name'] ?? 'No name') . '</b></a>'
         . '<div class="muted small">' . (isset($c['phone']) ? ($wa ? '<a href="https://wa.me/' . h($wa) . '" target="_blank" rel="noopener" onclick="event.stopPropagation()">' . h($c['phone']) . '</a>' : h($c['phone'])) : '')
         . (isset($c['email']) ? (isset($c['phone']) ? ' · ' : '') . h($c['email']) : '') . '</div></td>'
         . '<td class="ma small">' . h($c['addr'] ?? '') . (isset($c['emirate']) ? '<div class="muted">' . h($c['emirate']) . '</div>' : '') . '</td>'
         . '<td class="num mo"><b>' . (int)$c['n'] . '</b><span> orders</span></td>'
         . '<td class="num ms"><b>' . money($c['spent']) . '</b></td>'
         . '<td class="num mv">' . money($c['spent'] / $c['n']) . '<span> avg</span></td>'
         . '<td class="md small">' . h(date('d M Y', strtotime($c['first']))) . ' – ' . h(date('d M Y', strtotime($c['last']))) . '</td></tr>';
  }
  page('Members', '<div class="pagehead"><h1>Members</h1><span style="display:flex;gap:8px;flex-wrap:wrap"><a class="btn line sm" href="' . h(self_url(['members' => 1, 'subs' => 1])) . '">Download email list (' . number_format($subsN) . ')</a><a class="btn line sm" href="' . h(self_url(['members' => 1] + ($mq !== '' ? ['mq' => $mq] : []) + ['export' => 1])) . '">Download Excel</a></span></div>' . flash()
    . '<div class="rgbar">' . adm_range_form($rg, ['members' => 1] + ($mq !== '' ? ['mq' => $mq] : [])) . '</div>'
    . '<div class="mtop"><form class="card mmin" method="post">' . csrf_field() . '<label for="member_min">Orders: at least</label>'
    . '<input id="member_min" type="number" min="0" max="1000" inputmode="numeric" name="member_min" value="' . ($min ?: '') . '" placeholder="any"><span>orders</span><button class="btn sm">Save</button></form>'
    . '<form class="card mmin" method="post">' . csrf_field() . '<label for="member_spend">Spent: at least AED</label><input id="member_spend" class="amt" type="number" min="0" step="1" inputmode="numeric" name="member_spend" value="' . ($spend ?: '') . '" placeholder="any"><button class="btn sm">Save</button></form>'
    . '<form class="mq" method="get"><input type="hidden" name="members" value="1"><input name="mq" value="' . h($mq) . '" placeholder="Search name, mobile, email or area" aria-label="Search members"><button class="btn line sm">Search</button></form></div>'
    . '<div class="stats up"><div class="stat"><span>Members</span><b>' . count($mem) . '</b></div><div class="stat"><span>Members spent</span><b>' . money($memSpent) . '</b></div>'
    . '<div class="stat"><span>Share of ' . ($rg['span'] ? 'sales' : 'all sales') . '</span><b>' . ($allSales > 0 ? round($memSpent / $allSales * 100) : 0) . '%</b></div>'
    . '<div class="stat"><span>Average per member</span><b>' . money($mem ? $memSpent / count($mem) : 0) . '</b></div></div>'
    . '<div class="fill">' . ($list ? '<table class="mlist"><thead><tr><th>Member</th><th>Latest address</th><th class="num">Orders</th><th class="num">Spent</th><th class="num">Average</th><th>First – last order</th></tr></thead><tbody>' . $tr . '</tbody></table>'
         : '<p class="card muted" style="margin:0">' . ($mq !== '' ? 'No members match.' : 'No customer matches ' . ($min ? $min . ' or more orders' : '') . ($min && $spend ? ' and ' : '') . ($spend ? 'AED ' . number_format($spend) . ' or more spent' : '') . ($min || $spend ? '' : 'yet') . ($rg['span'] ? ' in this period' : '') . '.') . '</p>')
    . '<p class="muted small after">Orders from the same mobile number (or the same email when there is no mobile) count as one customer. Cancelled orders, unpaid card attempts and test payments are left out. Tap a member to see every order.</p></div>', true, true);
}

if (isset($_GET['o'])) {
  $s = $pdo->prepare('SELECT * FROM fx_orders WHERE order_no = ?'); $s->execute([(string)$_GET['o']]); $o = $s->fetch();
  if (!$o) page('Not found', '<h1>Order not found</h1><p><a href="./?orders=1">Back to orders</a></p>');
  $items = array_filter(array_map('trim', explode('|', (string)$o['items'])));
  /* every status in the dropdown with a clear name; picking one saves at once (with your note) */
  $stLabel = ['New' => 'Pending · Unpaid', 'Paid' => 'Pending · Paid', 'Delivered' => 'Delivered', 'Undelivered' => 'Undelivered', 'Cancelled' => 'Cancelled', 'Refunded' => 'Refunded', 'Awaiting payment' => 'Card not paid'];
  $stAsk = ['Cancelled' => 'Cancel this order? The items go back into stock.', 'Refunded' => 'Mark this order as refunded? The items go back into stock. This only records the refund here; card money is refunded in Ziina.'];
  $opts = '';
  foreach ($stLabel as $x => $lbl) $opts .= '<option value="' . h($x) . '"' . ($x === $o['status'] ? ' selected' : '') . (isset($stAsk[$x]) ? ' data-ask="' . h($stAsk[$x]) . '"' : '') . '>' . h($lbl) . '' . '</option>';
  $wa = preg_replace('/\D/', '', $o['phone']); if (str_starts_with($wa, '05')) $wa = '971' . substr($wa, 1);
  $row = fn($k, $v) => $v === '' || $v === null ? '' : '<dt>' . h($k) . '</dt><dd>' . $v . '</dd>';
  page($o['order_no'], '<p class="small"><a href="./?orders=1">← All orders</a></p>'
    . '<h1>' . h($o['order_no']) . ' ' . fx_tags($o) . '</h1>' . flash()
    . fx_tracker($o)
    . '<div class="grid2"><div>'
    . '<div class="card"><h2 style="margin-top:0">Items</h2><ul class="items">' . implode('', array_map(fn($i) => '<li>' . h($i) . '</li>', $items)) . '</ul>'
    . '<dl style="margin-top:14px">' . $row('Subtotal', $o['subtotal'] !== null ? money($o['subtotal']) : '') . $row(!empty($o['coupon']) ? 'Coupon ' . $o['coupon'] : 'Discount', $o['discount'] > 0 ? '-' . money($o['discount']) : '')
    . $row('COD fee', $o['fee'] > 0 ? money($o['fee']) : '') . $row('Total', '<b>' . money($o['total']) . '</b>') . $row('Payment', h($o['payment']) . ($o['test'] ? ' (test)' : ''))
    . $row('Ordered', h(date('d M Y, H:i', strtotime($o['created_at'])))) . $row('Paid', $o['paid_at'] ? h(date('d M Y, H:i', strtotime($o['paid_at']))) : '')
    . $row('Card ref', h($o['ref'] ?? '')) . '</dl></div>'
    . '<div class="card" style="margin-top:14px"><h2 style="margin-top:0">Customer</h2><dl>'
    . $row('Name', h($o['name'])) . $row('Mobile', h($o['phone']) . ($wa ? ' · <a href="https://wa.me/' . h($wa) . '" target="_blank" rel="noopener">WhatsApp</a>' : ''))
    . $row('Email', $o['email'] !== '' ? '<a href="mailto:' . h($o['email']) . '">' . h($o['email']) . '</a>' : '')
    . $row('Address', h($o['address'])) . $row('Emirate', h($o['emirate'])) . $row('Customer note', h($o['note'])) . '</dl></div>'
    . '</div><form class="card" method="post" style="align-self:start">' . csrf_field() . '<input type="hidden" name="order" value="' . h($o['order_no']) . '">'
    . '<h2 style="margin-top:0">Status</h2><label for="status">Order status <span class="muted">(saves when you pick)</span></label>'
    . '<select id="status" name="status" class="stsel s-' . h(strtok($o['status'], ' ')) . '" data-was="' . h($o['status']) . '" onchange="var a=this.selectedOptions[0].dataset.ask;if(a&&!confirm(a)){this.value=this.dataset.was;return}this.form.submit()">' . $opts . '</select>'
    . '<label for="admin_note">Your note (only you see this)</label><textarea id="admin_note" name="admin_note">' . h($o['admin_note'] ?? '') . '</textarea>'
    . '<p style="margin:16px 0 0"><button class="btn">Save</button></p></form></div>', true);
}

/* ---- dashboard: the home screen — today, this month, what needs doing, stock alerts, latest orders, last 30 days ---- */
if (!isset($_GET['orders']) && !array_intersect_key($_GET, array_flip(['q', 'status', 'pay', 'from', 'to', 'p', 'export']))) {
  require_once dirname(__DIR__) . '/store-lib.php';
  $real = "status IN ('New', 'Paid', 'Delivered') AND test = 0";
  $yr = fomaxo_report($pdo, (int)date('Y')); $month = $yr[date('Y-m')];
  $todo = $pdo->query("SELECT SUM(status = 'New' OR (status = 'Paid' AND payment = 'Cash on delivery')) cod, SUM(status = 'Paid' AND payment <> 'Cash on delivery') card FROM fx_orders WHERE test = 0")->fetch();
  /* Sales and Visitors graphs for the period picked at the top: by hour for one day, by day up to three months, by month for longer */
  $orders = fn($n) => $n ? money($n[0]) . ' · ' . $n[1] . ' order' . ($n[1] > 1 ? 's' : '') : 'no sales';
  $rg = adm_range('home', '30', ['today' => 'Today', '7' => '7 days', '30' => '30 days', 'year' => 'Year']);
  $d1 = $rg['d1']; $d2 = $rg['d2']; $nDays = (int)round((strtotime($d2) - strtotime($d1)) / 86400) + 1;
  $mode = $nDays === 1 ? 'hour' : ($nDays <= 92 ? 'day' : 'month');
  $fmt = ['hour' => '%H', 'day' => '%Y-%m-%d', 'month' => '%Y-%m'][$mode];
  $slots = [];   // bar key => [bar label, label in the tip]
  if ($mode === 'hour') for ($h = 0; $h < 24; $h++) $slots[sprintf('%02d', $h)] = [date('ga', mktime($h, 0)), date('ga', mktime($h, 0)) . '–' . date('ga', mktime($h + 1, 0))];
  else for ($t = strtotime($d1); $t <= strtotime($d2); $t = strtotime($mode === 'day' ? '+1 day' : 'first day of next month', $t))
    $slots[date($mode === 'day' ? 'Y-m-d' : 'Y-m', $t)] = [date($mode === 'day' ? ($nDays <= 7 ? 'D' : 'j M') : ($nDays > 366 ? 'M y' : 'M'), $t), date($mode === 'day' ? 'D j M' : 'F Y', $t)];
  $sales = [];
  $s = $pdo->prepare("SELECT DATE_FORMAT(created_at, '$fmt') k, SUM(total) t, COUNT(*) n FROM fx_orders WHERE $real AND created_at BETWEEN ? AND ? GROUP BY k");
  $s->execute($rg['span']); foreach ($s as $r) $sales[$r['k']] = [(float)$r['t'], (int)$r['n']];
  $sumT = array_sum(array_column($sales, 0)); $sumN = array_sum(array_column($sales, 1));
  /* visitors (from track.php): different people per bar; the total counts each person once */
  $people = fn($n) => $n . ' visitor' . ($n === 1 ? '' : 's');
  $vc = []; $tot = ['sales' => money($sumT), 'visitors' => $people(0)]; $hasV = true;
  try {
    $s = $pdo->prepare("SELECT DATE_FORMAT(at, '$fmt') k, COUNT(DISTINCT vid) n FROM fx_events WHERE at BETWEEN ? AND ? GROUP BY k"); $s->execute($rg['span']);
    foreach ($s as $r) $vc[$r['k']] = (int)$r['n'];
    $s = $pdo->prepare('SELECT COUNT(DISTINCT vid) FROM fx_events WHERE at BETWEEN ? AND ?'); $s->execute($rg['span']); $tot['visitors'] = $people((int)$s->fetchColumn());
  } catch (Throwable $e) { $hasV = false; }
  $pts = ['sales' => [], 'visitors' => []];
  foreach ($slots as $k => [$l, $t]) {
    $v = $sales[$k] ?? [0.0, 0];
    $pts['sales'][] = ['v' => $v[0], 'l' => $l, 't' => $t . ': ' . $orders($v[1] ? $v : null)];
    $pts['visitors'][] = ['v' => $vc[$k] ?? 0, 'l' => $l, 't' => $t . ': ' . ($hasV ? $people($vc[$k] ?? 0) : 'No visitor data yet')];
  }
  /* laptop: a Sales graph and a Visitors graph; phone: one graph with a Sales / Visitors switch (the Visitors card is hidden) */
  $graphs = []; $tots = [];
  foreach ($pts as $m => $set) {
    $graphs[$m] = str_replace('<div class="graph"', '<div class="graph" data-k="' . $m . '-x"', fx_graph($set, ucfirst($m) . ' ' . strtolower($rg['label']), false, $m === 'sales' ? 'money' : fn($v) => $people((int)$v), $m === 'visitors'));
    $tots[$m] = '<span data-k="' . $m . '-x">' . h($tot[$m]) . '</span>';
  }
  $hideV = fn($html) => preg_replace('/(<(?:div class="graph"|span) data-k="visitors-[a-z]+")(?: hidden)?/', '$1 hidden', $html);
  /* stock running low (counted sizes at or below the warning level) */
  $low = fomaxo_low_stock(); $alerts = '';
  foreach ($pdo->query('SELECT product, size, qty FROM fx_stock WHERE qty IS NOT NULL ORDER BY qty, product') as $r) {
    if ((int)$r['qty'] > $low || !isset($CATALOG[$r['product']]['prices'][$r['size']])) continue;
    $p = $CATALOG[$r['product']];
    $alerts .= '<li><span>' . h($p['name']) . ' <span class="muted">' . h($p['kind'] === 'set' ? 'Set of ' . $r['size'] : $r['size'] . 'ml') . '</span></span>'
             . ((int)$r['qty'] <= 0 ? '<b class="lvl-out">Sold out</b>' : '<b class="lvl-low">' . (int)$r['qty'] . ' left</b>') . '</li>';
  }
  $latest = '';
  foreach ($pdo->query("SELECT order_no, created_at, status, total, name, payment FROM fx_orders WHERE status <> 'Awaiting payment' ORDER BY created_at DESC, id DESC LIMIT 20") as $o) {
    $latest .= '<li><a href="' . h(self_url(['o' => $o['order_no']])) . '"><span><b>' . h($o['order_no']) . '</b> ' . h($o['name'])
             . '<small class="muted">' . h(date('d M, H:i', strtotime($o['created_at']))) . ' · ' . h($o['payment'] === 'Cash on delivery' ? 'Cash' : 'Card') . '</small></span>'
             . '<span class="r"><span class="tag s-' . h(strtok($o['status'], ' ')) . '">' . h($o['status']) . '</span><b>' . money($o['total']) . '</b></span></a></li>';
  }
  $profit = (float)$month['profit'];
  $todoBox = fn($n, $label, $st) => '<a class="todo' . ($n ? ' hot' : '') . '" href="' . h(self_url(['orders' => 1, 'status' => $st])) . '"><b>' . (int)$n . '</b><span>' . $label . '</span></a>';
  $tile = fn($val, $label, $cls = '', $href = null, $vcls = '') => ($href ? '<a class="tile ' . $cls . '" href="' . h($href) . '">' : '<div class="tile ' . $cls . '">') . '<b class="' . $vcls . '">' . $val . '</b><span>' . $label . '</span>' . ($href ? '</a>' : '</div>');
  $aed = fn($v) => 'AED ' . number_format(round((float)$v));   // whole dirhams on the tiles; exact amounts are on Orders and Reports
  $plural = fn($n, $w) => (int)$n . ' ' . $w . ((int)$n === 1 ? '' : 's');
  page('Home', '<div class="db">' . flash()
    . '<div class="rgbar">' . adm_range_form($rg, []) . '</div>'
    . '<div class="tiles">'
    . $tile((int)$todo['cod'], 'Cash to deliver', 'todo' . ($todo['cod'] ? ' hot' : ''), self_url(['orders' => 1, 'status' => 'New']))
    . $tile((int)$todo['card'], 'Card to deliver', 'todo' . ($todo['card'] ? ' hot' : ''), self_url(['orders' => 1, 'status' => 'Paid']))
    . $tile($aed($sumT), h($rg['label']) . ' · ' . $plural($sumN, 'order'), 'wr')
    . $tile($aed($month['sales']), date('M') . ' · ' . $plural($month['orders'], 'order'))
    . $tile(($profit < 0 ? '−' : '') . $aed(abs($profit)), ($profit < 0 ? 'Loss ' : 'Profit ') . date('M'), '', self_url(['reports' => 1]), $profit < 0 ? 'lvl-out' : 'lvl-ok')
    . '</div>'
    . ($month['no_cost'] ? '<p class="muted note">' . $plural($month['no_cost'], 'order') . ' this month ha' . ($month['no_cost'] > 1 ? 've' : 's') . ' no cost price, so profit shows too high. <a href="./?stock=1">Add costs</a></p>' : '')
    . '<div class="dgrid">'
    . '<section class="card c-sales" data-m="sales" data-r="x"><div class="ch wrap2"><h2 class="dt">Sales</h2><div class="seg met" role="group" aria-label="Show"><button type="button" data-m="sales" class="on">Sales</button><button type="button" data-m="visitors">Visitors</button></div></div>'
    . '<div class="chartbox">' . $graphs['sales'] . $hideV($graphs['visitors']) . '</div>'
    . '<p class="sub"><span class="tip">Tap a bar to see the details</span><b class="tot">' . $tots['sales'] . $hideV($tots['visitors']) . '</b></p></section>'
    . '<section class="card c-visits" data-m="visitors" data-r="x"><div class="ch wrap2"><h2>Visitors</h2></div>'
    . '<div class="chartbox">' . $graphs['visitors'] . '</div>'
    . '<p class="sub"><span class="tip">Tap a point to see the details</span><b class="tot">' . $tots['visitors'] . '</b></p></section>'
    . '<div class="lists"><section class="card c-orders"><div class="ch"><h2>Latest orders</h2><a href="./?orders=1">All orders</a></div>' . ($latest ? '<ul class="list orders">' . $latest . '</ul>' : '<p class="muted empty">No orders yet.</p>') . '</section>'
    . '<section class="card c-stock"><div class="ch"><h2>Stock alerts</h2><a href="./?stock=1">Stock</a></div>' . ($alerts ? '<ul class="list">' . $alerts . '</ul>' : '<p class="muted empty">No size is running low.</p>') . '</section></div>'
    . '</div>'
    . '<nav class="quick"><a class="btn line sm" href="./?expenses=1">+ Add an expense</a><a class="btn line sm" href="' . h(self_url(['products' => 1, 'p' => 'new'])) . '">+ Add a product</a>'
    . '<a class="btn line sm" href="' . h(self_url(['orders' => 1, 'export' => 1])) . '">Download all orders (Excel)</a><a class="btn line sm" href="' . h(self_url(['reports' => 1, 'y' => date('Y'), 'export' => 1])) . '">Download ' . date('Y') . ' profit &amp; loss (Excel)</a></nav>'
    . '</div>'
    . '<script>document.querySelectorAll(".chart .hit").forEach(function(r){var s=function(){var c=r.closest(".card");c.querySelector(".tip").textContent=r.dataset.t;c.querySelectorAll(".hit.on").forEach(function(x){x.classList.remove("on")});r.classList.add("on")};r.addEventListener("mouseenter",s);r.addEventListener("click",s)});'
    . 'document.querySelectorAll(".c-sales .seg button,.c-visits .seg button").forEach(function(b){b.addEventListener("click",function(){var c=b.closest(".card"),g=b.parentNode;g.querySelectorAll("button").forEach(function(x){x.classList.toggle("on",x===b)});if(b.dataset.m)c.dataset.m=b.dataset.m;if(b.dataset.r)c.dataset.r=b.dataset.r;var k=c.dataset.m+"-"+c.dataset.r;'
    . 'c.querySelectorAll(".chartbox .graph,.tot span").forEach(function(x){x.hidden=x.dataset.k!==k});c.querySelector(".tip").textContent="Tap a "+(k==="visitors-x"?"point":"bar")+" to see the details";c.querySelectorAll(".hit.on").forEach(function(x){x.classList.remove("on")})})});</script>', true, true);
}

/* list + filters (the same filters are used for the Excel download) */
$f = ['q' => trim((string)($_GET['q'] ?? '')), 'status' => (string)($_GET['status'] ?? ''), 'pay' => (string)($_GET['pay'] ?? ''),
      'from' => (string)($_GET['from'] ?? ''), 'to' => (string)($_GET['to'] ?? ''), 'em' => mb_substr(trim((string)($_GET['em'] ?? '')), 0, 30)];
$where = []; $args = [];
if ($f['status'] === '') $where[] = "status <> 'Awaiting payment'";            // default: every real order
elseif ($f['status'] === 'pending') $where[] = "status IN ('New', 'Paid')";
elseif ($f['status'] === 'unpaid') $where[] = "status = 'New' AND payment = 'Cash on delivery'";
elseif ($f['status'] !== 'all' && in_array($f['status'], FX_STATUSES, true)) { $where[] = 'status = ?'; $args[] = $f['status']; }
if ($f['pay'] === 'cod') $where[] = "payment = 'Cash on delivery'"; elseif ($f['pay'] === 'card') $where[] = "payment LIKE 'Card%'";
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['from'])) { $where[] = 'created_at >= ?'; $args[] = $f['from'] . ' 00:00:00'; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['to']))   { $where[] = 'created_at <= ?'; $args[] = $f['to'] . ' 23:59:59'; }
if ($f['q'] !== '') { $where[] = '(order_no LIKE ? OR name LIKE ? OR phone LIKE ? OR email LIKE ? OR items LIKE ?)'; $like = '%' . addcslashes($f['q'], '%_\\') . '%'; array_push($args, $like, $like, $like, $like, $like); }
/* orders per emirate: same filters, but not the emirate itself, so every emirate stays visible */
$s = $pdo->prepare("SELECT emirate, COUNT(*) n FROM fx_orders WHERE status IN ('New','Paid','Delivered') AND test = 0" . ($where ? ' AND ' . implode(' AND ', $where) : '') . " GROUP BY emirate ORDER BY n DESC, emirate");
$s->execute($args); $byEmirate = $s->fetchAll();
if ($f['em'] !== '') { $where[] = 'emirate = ?'; $args[] = $f['em']; }
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

/* the four boxes follow the filters; cancelled orders, unpaid card attempts and test payments are left out */
$real = "status IN ('New','Paid','Delivered') AND test = 0";
$s = $pdo->prepare("SELECT COUNT(*) n,
                    COALESCE(SUM($real AND payment = 'Cash on delivery'), 0) cod_n, COALESCE(SUM(CASE WHEN $real AND payment = 'Cash on delivery' THEN total END), 0) cod_t,
                    COALESCE(SUM($real AND payment LIKE 'Card%'), 0) card_n, COALESCE(SUM(CASE WHEN $real AND payment LIKE 'Card%' THEN total END), 0) card_t FROM fx_orders $W");
$s->execute($args); $sum = $s->fetch();
$per = 100; $pg = max(1, (int)($_GET['p'] ?? 1));
$s = $pdo->prepare("SELECT order_no, created_at, payment, status, total, name, phone, emirate, items, test FROM fx_orders $W ORDER BY created_at DESC, id DESC LIMIT $per OFFSET " . (($pg - 1) * $per));
$s->execute($args); $rows = $s->fetchAll();

$sel = fn($name, $opts) => '<select id="' . $name . '" name="' . $name . '">' . implode('', array_map(fn($k, $v) => '<option value="' . h($k) . '"' . ((string)$f[$name] === (string)$k ? ' selected' : '') . '>' . h($v) . '</option>', array_keys($opts), $opts)) . '</select>';
$stOpts = ['' => 'All orders', 'pending' => 'Pending (not delivered)', 'unpaid' => 'Unpaid cash', 'New' => 'Pending · unpaid cash', 'Paid' => 'Pending · paid',
           'Delivered' => 'Delivered', 'Cancelled' => 'Cancelled', 'Refunded' => 'Refunded', 'Awaiting payment' => 'Card not paid', 'all' => 'Everything (incl. unpaid card)'];
/* tracking chips: how many orders sit in each step (search, payment and dates apply; the status filter does not) */
$tw = array_values(array_filter($where, fn($w) => !str_starts_with($w, 'status')));
$s = $pdo->prepare("SELECT COALESCE(SUM(status IN ('New','Paid')), 0) pending, COALESCE(SUM(status = 'New' AND payment = 'Cash on delivery'), 0) unpaid,
                    COALESCE(SUM(status = 'Delivered'), 0) delivered, COALESCE(SUM(status = 'Cancelled'), 0) cancelled, COALESCE(SUM(status = 'Refunded'), 0) refunded
                    FROM fx_orders WHERE test = 0" . ($tw ? ' AND ' . implode(' AND ', $tw) : ''));
$s->execute(array_slice($args, count($args) - substr_count(implode(' ', $tw), '?'))); $track = $s->fetch();   // only the status filter has its value first
$chips = '';
foreach (['pending' => 'Pending', 'unpaid' => 'Unpaid cash', 'delivered' => 'Delivered', 'cancelled' => 'Cancelled', 'refunded' => 'Refunded'] as $k => $lbl) {
  $v = in_array($k, ['pending', 'unpaid'], true) ? $k : ucfirst($k); $on = $f['status'] === $v; $q = ['orders' => 1] + array_filter($f, fn($x) => $x !== ''); unset($q['status'], $q['p']);
  $chips .= '<a class="em t-' . $k . ($on ? ' on' : '') . '" href="' . h(self_url($on ? $q : $q + ['status' => $v])) . '">' . $lbl . ' <b>' . (int)$track[$k] . '</b></a>';
}
$tr = ''; $backQ = http_build_query(['orders' => 1] + array_filter($f, fn($v) => $v !== '') + ($pg > 1 ? ['p' => $pg] : []));
foreach ($rows as $o) {
  $u = h(self_url(['o' => $o['order_no']]));
  $tr .= '<tr class="row st-' . h(strtok($o['status'], ' ')) . '" onclick="location.href=\'' . $u . '\'"><td class="no" data-l=""><a href="' . $u . '">' . h($o['order_no']) . '</a>' . ($o['test'] ? ' <span class="muted small">test</span>' : '') . '</td>'
       . '<td data-l="">' . h(date('d M Y, H:i', strtotime($o['created_at']))) . '</td>'
       . '<td data-l="">' . h($o['name']) . '<div class="muted small">' . h($o['phone']) . ($o['emirate'] !== '' ? ' · ' . h($o['emirate']) : '') . '</div></td>'
       . '<td class="small" data-l="">' . h(mb_strimwidth(str_replace(' | ', ', ', (string)$o['items']), 0, 90, '…')) . '</td>'
       . '<td data-l="">' . h($o['payment'] === 'Cash on delivery' ? 'Cash' : 'Card') . '</td>'
       . '<td data-l="">' . fx_tags($o) . '</td>'
       . '<td class="num" data-l="">' . money($o['total']) . '</td><td data-l="">' . fx_order_buttons($o, $backQ) . '</td></tr>';
}
$qs = ['orders' => 1] + array_filter($f, fn($v) => $v !== '');
$pager = ($pg > 1 ? '<a class="btn line" href="' . h(self_url($qs + ['p' => $pg - 1])) . '">Newer</a>' : '')
       . ($sum['n'] > $pg * $per ? '<a class="btn line" href="' . h(self_url($qs + ['p' => $pg + 1])) . '">Older</a>' : '');

$oq = $qs; unset($oq['from'], $oq['to'], $oq['p']);   // Today / 7 days / 30 days / All fill the From and To dates below
$oseg = '';
foreach (['today' => 0, '7' => 6, '30' => 29, 'all' => null] as $k => $back) {
  $fr = $back === null ? '' : date('Y-m-d', strtotime("-$back day")); $to = $back === null ? '' : date('Y-m-d');
  $oseg .= '<a href="' . h(self_url($oq + ($back === null ? [] : ['from' => $fr, 'to' => $to]))) . '"' . ($f['from'] === $fr && $f['to'] === $to ? ' class="on"' : '') . '>' . ['today' => 'Today', '7' => '7 days', '30' => '30 days', 'all' => 'All'][$k] . '</a>';
}
page('Orders', '<div class="pagehead rg"><h1>Orders</h1><div class="arange"><div class="seg">' . $oseg . '</div></div></div>' . flash()
  . '<form class="filters card" method="get"><input type="hidden" name="orders" value="1">' . ($f['em'] !== '' ? '<input type="hidden" name="em" value="' . h($f['em']) . '">' : '')
  . '<div class="q"><label for="q">Search</label><input id="q" name="q" value="' . h($f['q']) . '" placeholder="Order no, name, mobile, email or product"></div>'
  . '<div><label for="status" class="phx">Status</label>' . $sel('status', $stOpts) . '</div>'
  . '<div><label for="pay" class="phx">Payment</label>' . $sel('pay', ['' => 'All', 'cod' => 'Cash on delivery', 'card' => 'Card']) . '</div>'
  . '<div><label for="from">From</label><input id="from" type="date" name="from" value="' . h($f['from']) . '"></div>'
  . '<div><label for="to">To</label><input id="to" type="date" name="to" value="' . h($f['to']) . '"></div>'
  . '<div class="acts"><button class="btn">Show</button><a class="btn line" href="' . h(self_url($qs + ['export' => 1])) . '">Download Excel</a></div></form>'
  . '<div class="stats up"><div class="stat"><span>COD orders</span><b>' . (int)$sum['cod_n'] . '</b></div>'
  . '<div class="stat"><span>Online orders</span><b>' . (int)$sum['card_n'] . '</b></div>'
  . '<div class="stat"><span>COD amount</span><b>' . money($sum['cod_t']) . '</b></div>'
  . '<div class="stat"><span>Card amount</span><b>' . money($sum['card_t']) . '</b></div></div>'
  . '<nav class="ems track" aria-label="Orders by step">' . $chips . '</nav>'
  . ($byEmirate ? '<nav class="ems" aria-label="Orders by emirate">' . implode('', array_map(function ($r) use ($f, $qs) {
        $e = (string)$r['emirate']; $on = $f['em'] !== '' && $f['em'] === $e; $q = $qs; unset($q['em'], $q['p']);
        return '<a class="em' . ($on ? ' on' : '') . '" href="' . h(self_url($on ? $q : $q + ['em' => $e])) . '">' . h($e !== '' ? $e : 'No emirate') . ' <b>' . (int)$r['n'] . '</b></a>';
      }, $byEmirate)) . '</nav>' : '')
  . '<div class="fill">' . ($rows ? '<table class="olist acts"><thead><tr><th>Order</th><th>Date</th><th>Customer</th><th>Items</th><th>Pay</th><th>Status</th><th class="num">Total</th><th></th></tr></thead><tbody>' . $tr . '</tbody></table>'
           : '<p class="card muted" style="margin:0">No orders match.</p>')
  . ($pager ? '<div class="pager">' . $pager . '</div>' : '')
  . '<p class="muted small after">The boxes count pending and delivered orders and leave out cancelled and refunded orders and test payments. "Waiting" shows how many days a pending order has not been delivered (red from 3 days). Unpaid card attempts are hidden unless you pick "Everything".</p></div>', true, true);
