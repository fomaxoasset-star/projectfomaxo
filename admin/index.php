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
.oacts{display:flex;gap:5px;flex-wrap:wrap;margin:0}.oacts form{margin:0}.oacts button{font:inherit;font-size:11px;font-weight:600;letter-spacing:.03em;padding:4px 9px;border-radius:999px;border:1px solid var(--line);background:transparent;color:var(--ink);cursor:pointer;white-space:nowrap}.oacts button:hover{border-color:var(--gold)}.oacts .a-paid{border-color:var(--gold);color:var(--gold)}.oacts .a-delivered{border-color:var(--ok);color:var(--ok)}.oacts .a-cancel,.oacts .a-refund{color:var(--bad)}.tag.p-Unpaid{color:var(--warn);border-style:dashed}.wait{display:block;font-size:11px;color:var(--muted);margin-top:3px}.wait.late{color:var(--bad);font-weight:600}.track{list-style:none;display:flex;margin:0 0 14px;padding:0;max-width:640px}.track li{flex:1;position:relative;display:flex;flex-direction:column;align-items:center;text-align:center;gap:2px;font-size:12px;color:var(--muted)}.track li+li::before{content:'';position:absolute;top:13px;right:calc(50% + 16px);left:calc(-50% + 16px);height:2px;background:var(--line)}.track li.done+li.done::before{background:var(--gold)}.track li.bad::before{background:var(--bad)!important}.track i{font-style:normal;width:26px;height:26px;border-radius:50%;border:2px solid var(--line);display:grid;place-items:center;font-weight:700;font-size:12px;color:var(--muted)}.track .done i{background:var(--gold);border-color:var(--gold);color:var(--gold-ink)}.track .bad i{background:var(--bad);border-color:var(--bad);color:#fff}.track b{color:var(--ink);font-size:12.5px;font-weight:600}.track .done span{color:var(--ink)}@media (min-width:760px){.olist.acts td:nth-child(8){white-space:nowrap;width:1%}.olist.acts .oacts{flex-wrap:nowrap}.olist.acts td:nth-child(6) .tags{flex-wrap:nowrap}}.tag.p-Paid{color:var(--gold)}.tags{display:inline-flex;gap:4px;flex-wrap:wrap;justify-content:flex-end}.order-acts{margin:0 0 14px}.order-acts button{font-size:12.5px;padding:7px 14px}
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
.chart .hit{fill:transparent;cursor:pointer}.chart .hit.on{fill:rgba(143,107,55,.16)}
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
/* analytics */
.seg a{font-size:11.5px;font-weight:600;color:var(--muted);padding:3px 9px;border-radius:20px;text-decoration:none;white-space:nowrap}.seg a.on{background:var(--gold);color:var(--gold-ink)}
.an .pagehead{flex-wrap:wrap;margin-bottom:8px;gap:6px 10px}.arange{display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin:0}.arange input[type=date]{width:auto;padding:4px 7px;font-size:12.5px}
.apick{display:none}
.agrid{grid-template-rows:minmax(0,1fr)}
.fun li{display:block;padding:5px 0;border-bottom:0}.fl{display:flex;justify-content:space-between;gap:8px;font-size:13px}.fb{height:7px;background:var(--line);border-radius:4px;margin:3px 0 0;overflow:hidden}.fb i{display:block;height:100%;background:var(--gold);border-radius:4px}
.fd{font-size:11.5px;color:var(--bad);margin-left:4px;white-space:nowrap}.fun li.fx{padding-top:8px;line-height:1.5}
.leads li{display:block}.leads .lt{display:flex;gap:8px;align-items:baseline;flex-wrap:wrap}.leads .lt .r{margin-left:auto}.items1{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.leads .tag{font-size:11px;padding:1px 8px}
.stage-details{color:var(--muted)}.stage-payment{color:var(--warn)}.stage-card{color:var(--bad)}
table.mini{border:0;border-radius:0;background:none;font-size:13px;overflow:visible}table.mini th,table.mini td{padding:5px 6px}table.mini th:first-child,table.mini td:first-child{padding-left:0}table.mini th:last-child,table.mini td:last-child{padding-right:0}
table.mini th{position:sticky;top:0;background:var(--panel)}
@media (max-width:759px){
  table.mini{display:table}table.mini thead{display:table-header-group}table.mini tbody{display:table-row-group}table.mini tr{display:table-row}table.mini td{display:table-cell;border-bottom:1px solid var(--line)}table.mini td.num{text-align:right}
  .tiles.at{grid-template-columns:repeat(4,1fr)}.tiles.at .tile{grid-column:auto}.tiles.at .tile b{font-size:14px}.tiles.at .tile span{font-size:9.5px;white-space:normal;line-height:1.2}
  .apick{display:flex;gap:6px;overflow-x:auto;scrollbar-width:none;margin:0 0 8px}.apick::-webkit-scrollbar{display:none}
  .apick button{flex:none;font:inherit;font-size:12px;font-weight:600;border:1px solid var(--line);background:var(--panel);color:var(--muted);border-radius:20px;padding:5px 11px;cursor:pointer}.apick button.on{background:var(--gold);border-color:var(--gold);color:var(--gold-ink)}
  .agrid>.card{display:none}.agrid>.card.on{display:flex}
  .an .pagehead{display:grid;grid-template-columns:auto minmax(0,1fr) minmax(0,1fr) auto;align-items:center;gap:6px}.an .pagehead h1{margin:0}.arange{display:contents}
  .arange .seg{grid-column:2/5;justify-self:end}.arange .seg a{padding:3px 8px}.arange input[name=d1]{grid-column:1/3;width:100%}.arange input[name=d2]{width:100%}
  .tiles.at .tile:last-child{grid-column:span 2}
}
@media (min-width:760px){.tiles.at{grid-template-columns:repeat(8,1fr)}.tiles.at .tile b{font-size:19px}
  .agrid{grid-template-columns:minmax(0,1.05fr) minmax(0,1.3fr) minmax(0,.9fr) minmax(0,.9fr);grid-template-rows:minmax(0,1fr) minmax(0,1fr);grid-template-areas:"funnel visitors countries left" "products sources emirates left"}
  .a-funnel{grid-area:funnel}.a-visitors{grid-area:visitors}.a-left{grid-area:left}.a-sources{grid-area:sources}.a-products{grid-area:products}.a-countries{grid-area:countries}.a-emirates{grid-area:emirates}.agrid>.a-returning{display:none}}
.agrid .list[hidden]{display:none!important}.gbar{width:28%}.gbar .fb{margin:0}.gnote{margin:6px 0 0;flex:none}.a-countries .ch,.a-emirates .ch{flex-wrap:wrap;gap:6px}.gseg button{padding:3px 7px}.a-left .ch{gap:8px}.a-left .ch .btn{flex:none}.a-left .ch h2{min-width:0;flex:1 1 0;white-space:normal;overflow:visible}.a-countries .ch h2,.a-emirates .ch h2{flex:1 1 100%}@media (min-width:760px) and (max-width:1199px){.agrid table.mini{font-size:11.5px}.agrid table.mini th,.agrid table.mini td{padding:5px 3px;letter-spacing:0}}@media (min-width:760px){.gtab{table-layout:fixed;width:100%}.gtab .gbar{display:none}.gtab th:nth-child(2){width:64px}.gtab th:nth-child(4){width:52px}.gtab td:first-child{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}}
/* phone: no zooming by itself (iPhone zooms into boxes under 16px, double-tap zooms); two-finger pinch still works */
html{-webkit-text-size-adjust:100%;text-size-adjust:100%}html,body{touch-action:manipulation;overflow-x:hidden}
@media (max-width:759px){input,select,textarea{font-size:16px!important}
  .an .pagehead{display:flex;flex-wrap:wrap}.an .pagehead h1{flex:none}.arange .seg{margin-left:auto}.arange input[type=date]{flex:1 1 35%;min-width:0;width:auto!important;padding:4px 6px}.arange .btn{flex:none}}
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
  .an .pagehead h1{display:none}.an .pagehead{gap:6px;margin-bottom:6px}.arange{display:flex;width:100%;gap:6px}
  .arange .seg{display:flex;width:100%;margin:0;padding:0;gap:0;border-radius:8px;overflow:hidden}.arange .seg a{flex:1;text-align:center;font-size:13px;font-weight:500;padding:7px 0;border-radius:0;color:var(--ink)}
  .arange .seg a.on{background:rgba(201,169,97,.16);color:var(--gold)}.arange .seg a+a{border-left:1px solid var(--line)}
  .arange input[type=date]{flex:1 1 0!important;padding:5px 8px!important;border-radius:8px}.arange .btn{border-radius:8px;padding:6px 12px}
  .tiles.at{grid-template-columns:repeat(3,1fr);gap:6px;margin-bottom:6px}.tiles.at .tile{display:flex;flex-direction:column;padding:6px 9px;border-radius:10px}
  .tiles.at .tile span{order:-1;font-size:9.5px;letter-spacing:.07em;white-space:normal;line-height:1.25}.tiles.at .tile b{font-size:17px;margin:1px 0}
  .tiles.at .tile .tn{display:block;font-size:11px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .tiles.at .tile span .dk{display:none}.tiles.at .tile span .mo{display:inline;font:inherit;letter-spacing:inherit;color:inherit}
  .tiles.at .tile.hot b{color:var(--ok,#6fbf73)}.tiles.at .tile.ret{display:none}.tiles.at .tile.rev{grid-column:1/-1}
  .rsum{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;text-align:center;margin:2px 0 8px;flex:none}.rsum b{display:block;font-size:20px;font-weight:600}.rsum span{font-size:10.5px;letter-spacing:.06em;text-transform:uppercase;color:var(--muted)}
  .rtab td>b,.rtab td>span{display:block}.rtab td{vertical-align:top}.rtab td span{font-size:12px}
  .apick{display:grid;grid-template-columns:repeat(4,1fr);gap:0;border:1px solid var(--line);border-radius:10px;overflow:hidden;margin-bottom:8px}
  .apick button{border:0;border-radius:0;border-right:1px solid var(--line);border-bottom:1px solid var(--line);background:none;color:var(--ink);font-weight:500;font-size:12.5px;padding:8px 2px;white-space:nowrap}
  .apick button:nth-child(4n){border-right:0}.apick button:nth-child(n+5){border-bottom:0}.apick button[data-p=left]{white-space:normal;line-height:1.15}
  .apick button.on{background:rgba(201,169,97,.16);border-color:var(--line);color:var(--gold)}
  .a-left .ch .btn{background:var(--gold);border-color:var(--gold);color:var(--gold-ink)}
  .a-left .ldesk{display:none}.a-left .lmob{display:block}
  .ltab td{vertical-align:top;padding:9px 6px 9px 0;line-height:1.35}.ltab td:first-child{width:46%}.ltab td>*{display:block;overflow-wrap:anywhere}.ltab td b{font-size:13.5px}.ltab td a{color:var(--ink);text-decoration:none}
  .tiles.at .tile{align-items:center;text-align:center}.tiles.at .tile .tn{max-width:100%}
  .a-left .ch{justify-content:center}.a-left .ch h2{flex:none}.a-left>p.small{text-align:center}.ltab th,.ltab td{text-align:center}.ltab td{padding:9px 4px}.ltab th:first-child,.ltab td:first-child{padding-left:0}.ltab th:last-child,.ltab td:last-child{padding-right:0}
  .ltab td span{font-size:12px}.ltab .lm-s{margin-top:3px}.ltab .lm-s .tag{display:inline;font-size:10.5px;padding:0 6px}.ltab th{font-size:10.5px;letter-spacing:.08em;text-transform:uppercase}}
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
          ['Analytics', './?analytics=1', isset($_GET['analytics'])], ['Expenses', './?expenses=1', isset($_GET['expenses'])], ['Reports', './?reports=1', isset($_GET['reports'])],
          ['Members', './?members=1', isset($_GET['members'])], ['Reviews', './?reviews=1', isset($_GET['reviews'])], ['Settings', './?settings=1', isset($_GET['settings'])]])) . '</nav><a class="tnav" data-d="1" aria-label="Next page" hidden>›</a></div>' : '')
     . '<main class="wrap' . ($wide ? '' : ' narrow') . ($fit ? ' fit' : '') . '">' . $body . '</main>'
     . '<script>' . (!empty($_SESSION['admin']) ? 'try{localStorage.setItem("fomaxo_notrack","1")}catch(e){}' : '') . 'document.querySelectorAll(".tsearch").forEach(function(i){i.addEventListener("input",function(){var q=i.value.trim().toLowerCase();document.querySelectorAll("main table tr.row").forEach(function(r){r.hidden=q!==""&&r.innerText.toLowerCase().indexOf(q)<0})})});var t=document.querySelector(".tabs a.on");if(t&&t.parentNode.scrollWidth>t.parentNode.clientWidth)t.parentNode.scrollLeft=t.offsetLeft-(t.parentNode.clientWidth-t.offsetWidth)/2;if(t){var go=function(d){var a=d<0?t.previousElementSibling:t.nextElementSibling;return a&&a.href};document.querySelectorAll(".tnav").forEach(function(b){var u=go(+b.dataset.d);if(u){b.href=u;b.hidden=false}});var sx=null,sy,st;function hscroll(e){for(;e&&e!==document.body;e=e.parentElement){var o=getComputedStyle(e).overflowX;if((o=="auto"||o=="scroll")&&e.scrollWidth>e.clientWidth+2)return true}return false}document.addEventListener("touchstart",function(e){var g=e.target;sx=null;if(e.touches.length!==1||innerWidth>759||g.closest("input,textarea,select,.tabsw,[contenteditable]")||document.body.classList.contains("zoomed")||hscroll(g))return;sx=e.touches[0].clientX;sy=e.touches[0].clientY;st=Date.now()},{passive:true});document.addEventListener("touchend",function(e){if(sx===null)return;var c=e.changedTouches[0],dx=c.clientX-sx,dy=c.clientY-sy;sx=null;if(Math.abs(dx)<70||Math.abs(dx)<2*Math.abs(dy)||Date.now()-st>800)return;var u=go(dx<0?1:-1);if(!u)return;var m=document.querySelector("main");if(m){m.style.transition="transform .16s,opacity .16s";m.style.transform="translateX("+(dx<0?-40:40)+"px)";m.style.opacity=".35"}location.href=u},{passive:true});addEventListener("pageshow",function(){var m=document.querySelector("main");if(m){m.style.transform="";m.style.opacity=""}})}</script></body></html>';
  exit;
}
/* bar graph for the Dashboard and Analytics: bars stretch to fill the box; the amounts and dates are normal text so they stay readable */
function fx_graph($pts, $aria, $hidden = false, $fmt = 'money') {
  $W = 600; $H = 100; $n = count($pts); $slot = $W / $n; $bw = min(44, max(6, round($slot * .6, 1)));
  $max = max(array_column($pts, 'v')); $out = ''; $lab = '';
  foreach ($pts as $i => $p) {
    $h = $max > 0 ? round(($H - 2) * $p['v'] / $max, 1) : 0;
    if ($h > 0) $out .= '<rect class="bar" x="' . round($i * $slot + ($slot - $bw) / 2, 1) . '" y="' . ($H - $h) . '" width="' . $bw . '" height="' . $h . '"/>';
    $out .= '<rect class="hit" x="' . round($i * $slot, 1) . '" y="0" width="' . round($slot, 1) . '" height="' . $H . '" data-t="' . h($p['t']) . '"><title>' . h($p['t']) . '</title></rect>';
    if ($n <= 12 || in_array($i, [0, intdiv($n, 2), $n - 1], true)) $lab .= '<span>' . h($p['l']) . '</span>';
  }
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
  return ['New' => $cod ? ['paid', 'delivered', 'cancel'] : ['delivered', 'cancel'], 'Paid' => ['delivered', 'cancel']][$o['status']] ?? [];
}
function fx_order_buttons($o, $back = '', $cls = '') {
  $label = ['paid' => 'Paid', 'delivered' => '✓ Mark delivered', 'pending' => '↺ Not delivered', 'cancel' => 'Cancel order', 'refund' => 'Refund'];
  $ask = ['cancel' => 'Cancel order ' . $o['order_no'] . '? The items go back into stock.',
          'refund' => 'Mark order ' . $o['order_no'] . ' as refunded? The items go back into stock. This only records the refund here; it does not send money back. Card refunds are done in Ziina.'];
  $ok = fx_order_actions($o);
  $btn = fn($a) => '<form method="post"' . (isset($ask[$a]) ? ' onsubmit="return confirm(' . h(json_encode($ask[$a], JSON_UNESCAPED_UNICODE)) . ')"' : '') . '>' . csrf_field()
          . '<input type="hidden" name="order" value="' . h($o['order_no']) . '"><input type="hidden" name="quick" value="' . $a . '">'
          . ($back !== '' ? '<input type="hidden" name="back" value="' . h($back) . '">' : '') . '<button class="a-' . $a . '">' . h($label[$a]) . '</button></form>';
  $out = '';
  foreach ($ok as $a) $out .= $btn($a);
  return $out ? '<div class="oacts' . ($cls ? ' ' . $cls : '') . '" onclick="event.stopPropagation()">' . $out . '</div>' : '';
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
    flash('Saved.', true); go(['stock' => 1]);
  }
  $have = []; $cost = [];
  foreach ($pdo->query('SELECT product, size, qty, cost FROM fx_stock') as $r) { $have[$r['product'] . '|' . $r['size']] = $r['qty']; $cost[$r['product'] . '|' . $r['size']] = $r['cost']; }
  $pic = [];   // first photo of each product, from the Products tab
  try { foreach ($pdo->query('SELECT id, data FROM fx_products') as $r) { $d = json_decode($r['data'], true); if (!empty($d['images'][0])) $pic[$r['id']] = $d['images'][0]; } } catch (Throwable $e) {}
  $sold = [];   // sold in the last 30 days (orders that are not cancelled), to help decide when to restock
  $s = $pdo->query("SELECT lines_json FROM fx_orders WHERE stock_taken = 1 AND created_at > NOW() - INTERVAL 30 DAY");
  foreach ($s as $r) foreach (fomaxo_stock_lines(json_decode((string)$r['lines_json'], true) ?: []) as $k => $n) $sold[$k] = ($sold[$k] ?? 0) + $n;
  $tr = '';
  foreach ($CATALOG as $id => $p) foreach (array_keys($p['prices']) as $opt) {
    $k = "$id|$opt"; $q = $have[$k] ?? null; $c = $cost[$k] ?? null;
    $lvl = $q === null ? '<span class="muted">Not counted</span>' : ((int)$q <= 0 ? '<span class="lvl-out">Sold out</span>' : ((int)$q <= fomaxo_low_stock() ? '<span class="lvl-low">Only ' . (int)$q . ' left</span>' : '<span class="lvl-ok">In stock</span>'));
    $margin = ' · <span class="muted">sells at AED ' . h(number_format($p['prices'][$opt], 0)) . '</span>';
    $img = $pic[$id] ?? ($p['images'][0] ?? '');
    $tr .= '<tr class="row"><td class="sp">' . ($img ? '<img class="sth" src="../assets/img/' . h($img) . '.webp" alt="" loading="lazy">' : '<span class="sth"></span>') . '<div><b>' . h($p['name']) . '</b> <span class="muted">' . h($p['kind'] === 'set' ? "Set of $opt" : "{$opt}ml") . '</span>'
         . '<div class="small">' . $lvl . $margin . (!empty($sold[$k]) ? ' <span class="muted">· ' . (int)$sold[$k] . ' sold in 30 days</span>' : '') . '</div></div></td>'
         . '<td class="num"><label class="mini">Stock</label><input type="number" min="0" inputmode="numeric" name="q[' . h($id) . '][' . h($opt) . ']" value="' . ($q === null ? '' : (int)$q) . '" placeholder="—" aria-label="' . h($p['name'] . ' ' . $opt) . ' stock"></td>'
         . '<td class="num"><label class="mini">Cost AED</label><input type="number" min="0" step="0.01" inputmode="decimal" name="c[' . h($id) . '][' . h($opt) . ']" value="' . ($c === null ? '' : h(rtrim(rtrim($c, '0'), '.'))) . '" placeholder="—" aria-label="' . h($p['name'] . ' ' . $opt) . ' cost price"></td></tr>';
  }
  page('Stock', '<div class="pagehead"><h1>Stock &amp; cost</h1><input type="search" class="tsearch" placeholder="Search product" aria-label="Search product"></div>' . flash()
    . '<form class="fitform" method="post">' . csrf_field()
    . '<div class="card lowlvl"><label for="low_stock">Show "Only X left" on the website when stock is at or below</label><input id="low_stock" type="number" min="0" max="100" inputmode="numeric" name="low_stock" required value="' . fomaxo_low_stock() . '">'
    . '<p class="muted small" style="margin:6px 0 0">One level for every product and size. 0 turns it off (Sold out still shows).</p></div>'
    . '<div class="fill"><table class="stock"><thead><tr><th>Product</th><th class="num">Stock</th><th class="num">Cost (AED)</th></tr></thead><tbody>' . $tr . '</tbody></table></div>'
    . '<div class="savebar">' . '<p class="muted small stock-help"><b>Stock:</b> how many bottles you have. It goes down by itself with every cash order and every paid card order (the free mini counts as one 10ml) and goes back up if you cancel an order. Leave it empty to not count that size. The website shows "Only X left" at ' . fomaxo_low_stock() . ' or fewer, and "Out of Stock — Restocking Soon" at 0.<br>'
    . '<b>Cost:</b> what one bottle costs you. Reports use it to work out your profit.</p>' . '<button class="btn big">Save</button></div></form>', true, true);
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
    . '<p style="margin:12px 0 0"><button class="btn">Add expense</button></p></form>'
    . '<div class="monthnav"><a class="btn line" href="' . h(self_url(['expenses' => 1, 'm' => $prev])) . '">←</a><h2>' . h(date('F Y', strtotime("$month-01"))) . '<small>' . money($sum) . '</small></h2><a class="btn line" href="' . h(self_url(['expenses' => 1, 'm' => $next])) . '">→</a></div>'
    . '<div class="fill">' . ($list ? '<table class="exp"><tbody>' . $tr . '</tbody></table>' : '<p class="card muted" style="margin:0">No expenses this month.</p>')
    . '<p class="muted small after">"Stock purchase" is shown in reports but not taken off profit, because the cost of each bottle is already counted when it sells.</p></div>', true, true);
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
  $chart = fx_graph($pts, 'Visitors per ' . $mode, false, fn($v) => number_format($v) . ' visitors');

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

  /* where visitors are: country of each visit, and the emirate for the UAE (looked up from the IP address when the visit starts; the address is not kept) */
  require_once dirname(__DIR__) . '/geo-lib.php';
  $gr = ['today' => 'Today', '7' => '7 days', '30' => '30 days', 'year' => 'Year'];
  $gFrom = ['today' => date('Y-m-d 00:00:00'), '7' => date('Y-m-d 00:00:00', strtotime('-6 day')), '30' => date('Y-m-d 00:00:00', strtotime('-29 day')), 'year' => date('Y-m-d 00:00:00', strtotime('-1 year +1 day'))];
  $gOn = isset($gr[$r]) ? $r : '7';
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
      foreach ($q("SELECT country, COUNT(DISTINCT vid) n FROM fx_events WHERE country IS NOT NULL AND at >= ? GROUP BY country ORDER BY n DESC, country LIMIT 30", [$gFrom[$k]]) as $row)
        $cRows[] = ['name' => fomaxo_flag($row['country']) . ' ' . h(fomaxo_country_name($row['country'])), 'n' => (int)$row['n']];
      $em = array_fill_keys(FOMAXO_EMIRATES, 0); $unk = 0;
      foreach ($q("SELECT region, COUNT(DISTINCT vid) n FROM fx_events WHERE country = 'AE' AND at >= ? GROUP BY region", [$gFrom[$k]]) as $row)
        if (isset($em[$row['region']])) $em[$row['region']] = (int)$row['n']; else $unk += (int)$row['n'];
      arsort($em); foreach ($em as $name => $n) $eRows[] = ['name' => h($name), 'n' => $n];
      if ($unk) $eRows[] = ['name' => '<span class="muted">Not known</span>', 'n' => $unk];
    } catch (Throwable $e) {}
    $hid = (string)$k === $gOn ? '' : ' hidden';
    $gCountries .= '<div class="list" data-r="' . $k . '"' . $hid . '>' . ($cRows ? $geoTable($cRows, 'Country') : '<p class="muted empty">No visitors yet.</p>') . '</div>';
    $gEmirates .= '<div class="list" data-r="' . $k . '"' . $hid . '>' . (array_sum(array_column($eRows, 'n')) ? $geoTable($eRows, 'Emirate') : '<p class="muted empty">No visitors from the UAE yet.</p>') . '</div>';
  }
  $gSeg = '<div class="seg gseg" role="group" aria-label="Period">' . implode('', array_map(fn($k, $l) => '<button type="button" data-r="' . $k . '"' . ((string)$k === $gOn ? ' class="on"' : '') . '>' . $l . '</button>', array_keys($gr), $gr)) . '</div>';
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
    $left .= '<li><div class="lt"><b>' . h($l['name'] ?: 'No name') . '</b>' . ($l['phone'] !== '' ? ' <a href="https://wa.me/' . h($wa) . '" target="_blank" rel="noopener">' . h($l['phone']) . '</a>' : '')
           . '<span class="r">' . ($l['total'] !== null ? '<b>' . money($l['total']) . '</b>' : '') . '</span></div>'
           . '<div class="small"><span class="tag stage-' . h($l['stage']) . '">Left at ' . h($stageName[$l['stage']] ?? $l['stage']) . '</span> <span class="muted">' . h(date('d M, H:i', strtotime($l['updated_at']))) . ($recent ? ' · may still be checking out' : '') . ($l['emirate'] !== '' ? ' · ' . h($l['emirate']) : '') . '</span></div>'
           . (($l['address'] ?? '') !== '' ? '<div class="muted small items1">' . h($l['address']) . '</div>' : '')
           . ($l['items'] !== '' ? '<div class="muted small items1">' . h($l['items']) . '</div>' : '') . '</li>';
    $leftM .= '<tr><td><b>' . h($l['name'] ?: 'No name') . '</b>' . ($l['phone'] !== '' ? '<a href="https://wa.me/' . h($wa) . '" target="_blank" rel="noopener">' . h($l['phone']) . '</a>' : '')
            . ($l['email'] !== '' ? '<span class="muted">' . h($l['email']) . '</span>' : '')
            . '<span class="lm-s"><span class="tag stage-' . h($l['stage']) . '">' . h($stageName[$l['stage']] ?? $l['stage']) . '</span></span></td>'
            . '<td><b>' . h($l['emirate'] !== '' ? $l['emirate'] : '—') . '</b>' . (($l['address'] ?? '') !== '' ? '<span>' . h($l['address']) . '</span>' : '')
            . '<span class="muted">' . h(date('d M, H:i', strtotime($l['updated_at']))) . ($l['total'] !== null ? ' · ' . money($l['total']) : '') . ($recent ? ' · may still be checking out' : '') . '</span>'
            . ($l['items'] !== '' ? '<span class="muted">' . h($l['items']) . '</span>' : '') . '</td></tr>';
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
  $panes = ['funnel' => 'Funnel', 'visitors' => 'Visitors', 'sources' => 'Sources', 'products' => 'Products', 'countries' => 'Countries', 'emirates' => 'Emirates', 'returning' => 'Returning', 'left' => 'Left at checkout'];
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
    . '<div class="pagehead"><h1>Analytics</h1><form class="arange" method="get"><input type="hidden" name="analytics" value="1"><div class="seg">'
    . implode('', array_map(fn($k, $l) => '<a href="' . h(self_url(['analytics' => 1, 'r' => $k])) . '"' . ($r === (string)$k ? ' class="on"' : '') . '>' . $l . '</a>', array_keys($rl), $rl)) . '</div>'
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
    . '<nav class="apick" role="tablist">' . implode('', array_map(fn($k, $l) => '<button type="button" data-p="' . $k . '"' . ($k === 'funnel' ? ' class="on"' : '') . '>' . $l . ($k === 'left' && array_sum($leftN) ? ' (' . array_sum($leftN) . ')' : '') . '</button>', array_keys($panes), $panes)) . '</nav>'
    . '<div class="dgrid agrid">'
    . '<section class="card a-funnel on" data-p="funnel"><div class="ch"><h2>Where sales are lost</h2></div><ul class="list fun">' . $fun . '</ul></section>'
    . '<section class="card a-visitors" data-p="visitors"><div class="ch"><h2>Visitors</h2><span class="val">' . h(date('j M', strtotime($d1)) . ($d1 !== $d2 ? ' – ' . date('j M', strtotime($d2)) : '')) . '</span></div><div class="chartbox">' . $chart . '</div><p class="sub"><span class="tip">Tap a bar to see its visitors</span><b>' . number_format($f['visitors']) . '</b></p></section>'
    . $retCard
    . '<section class="card a-left" data-p="left"><div class="ch"><h2>Left at checkout</h2><a class="btn line sm" href="./?analytics=1&amp;leads=1">Excel</a></div>' . (array_sum($leftN) ? '<p class="muted small" style="margin:0 0 4px">' . implode(' · ', array_map(fn($k) => $leftN[$k] . ' at ' . strtolower($stageName[$k]), array_keys(array_filter($leftN)))) . '</p>' : '')
    . ($left ? '<ul class="list leads ldesk">' . $left . '</ul><div class="list lmob"><table class="mini ltab"><thead><tr><th>Name</th><th>Emirate · Address</th></tr></thead><tbody>' . $leftM . '</tbody></table></div>' : '<p class="muted empty">Nobody left checkout after typing their details.</p>') . '</section>'
    . '<section class="card a-sources" data-p="sources"><div class="ch"><h2>Where visitors come from</h2></div>'
    . ($src ? '<div class="list"><table class="mini"><thead><tr><th>Source</th><th class="num">Visitors</th><th class="num">Visits</th><th class="num">Bought</th><th class="num">Conv.</th></tr></thead><tbody>' . $src . '</tbody></table></div>' : '<p class="muted empty">No visits yet.</p>') . '</section>'
    . '<section class="card a-products" data-p="products"><div class="ch"><h2>Products</h2></div>'
    . ($prod ? '<div class="list"><table class="mini"><thead><tr><th>Product</th><th class="num">Viewed</th><th class="num">To bag</th><th class="num">Rate</th></tr></thead><tbody>' . $prod . '</tbody></table></div>' : '<p class="muted empty">No product views yet.</p>') . '</section>'
    . '<section class="card a-countries" data-p="countries"><div class="ch"><h2>Top countries</h2>' . $gSeg . '</div>' . $gCountries . '<p class="muted small gnote">' . $gNote . ' Location data by <a href="https://db-ip.com" target="_blank" rel="noopener">DB-IP</a>.</p></section>'
    . '<section class="card a-emirates" data-p="emirates"><div class="ch"><h2>UAE visitors by emirate</h2>' . $gSeg . '</div>' . $gEmirates . '<p class="muted small gnote">Approximate: phone networks often show Dubai or Abu Dhabi. ' . $gNote . '</p></section>'
    . '</div></div>'
    . '<script>document.querySelectorAll(".gseg button").forEach(function(b){b.addEventListener("click",function(){var c=b.closest(".card");c.querySelectorAll(".gseg button").forEach(function(x){x.classList.toggle("on",x===b)});c.querySelectorAll(".list[data-r]").forEach(function(x){x.hidden=x.dataset.r!==b.dataset.r})})});'
    . '(function(){var open=null,back=null;function shut(){if(!open)return;open.classList.remove("big");open.querySelector(".zoom").textContent="⤢";open.querySelector(".zoom").setAttribute("aria-label","Open bigger");back.remove();open=null;document.body.classList.remove("zoomed")}'
    . 'function show(c){shut();open=c;c.classList.add("big");var z=c.querySelector(".zoom");z.textContent="×";z.setAttribute("aria-label","Close");back=document.createElement("div");back.className="zback";back.onclick=shut;document.body.appendChild(back);document.body.classList.add("zoomed");c.scrollTop=0}'
    . 'document.querySelectorAll(".agrid>.card").forEach(function(c){var h=c.querySelector(".ch");if(!h)return;var b=document.createElement("button");b.type="button";b.className="zoom";b.textContent="⤢";b.setAttribute("aria-label","Open bigger");b.onclick=function(e){e.stopPropagation();open===c?shut():show(c)};h.appendChild(b);var t=h.querySelector("h2");if(t){t.style.cursor="pointer";t.onclick=function(){open===c?shut():show(c)}}});'
    . 'var map=["visitors","visitors","funnel","funnel","funnel","funnel","returning","funnel"];document.querySelectorAll(".tiles.at .tile").forEach(function(t,i){t.onclick=function(){var c=document.querySelector(".agrid>.card[data-p="+(t.classList.contains("ret")?"returning":t.classList.contains("rev")?"funnel":map[i])+"]");if(c)show(c)}});'
    . 'document.addEventListener("keydown",function(e){if(e.key==="Escape")shut()})})();'
    . 'document.querySelectorAll(".apick button").forEach(function(b){b.addEventListener("click",function(){document.querySelectorAll(".apick button,.agrid>.card").forEach(function(x){x.classList.toggle("on",x.dataset.p===b.dataset.p)})})});'
    . 'document.querySelectorAll(".chart .hit").forEach(function(r){var s=function(){var c=r.closest(".card");c.querySelector(".tip").textContent=r.dataset.t;c.querySelectorAll(".hit.on").forEach(function(x){x.classList.remove("on")});r.classList.add("on")};r.addEventListener("mouseenter",s);r.addEventListener("click",s)});</script>', true, true);
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
    . ($thisM ? '<div class="stats rstats"><div class="stat"><b>' . money($thisM['sales']) . '</b><span>Sales this month</span></div><div class="stat"><b class="' . ($thisM['profit'] < 0 ? 'lvl-out' : 'lvl-ok') . '">' . ($thisM['profit'] < 0 ? '−' : '') . money(abs($thisM['profit'])) . '</b><span>Profit this month</span></div>'
             . '<div class="stat"><b>' . money($T['sales'] ?? 0) . '</b><span>Sales ' . $year . '</span></div><div class="stat"><b class="' . (($T['profit'] ?? 0) < 0 ? 'lvl-out' : 'lvl-ok') . '">' . (($T['profit'] ?? 0) < 0 ? '−' : '') . money(abs($T['profit'] ?? 0)) . '</b><span>Profit ' . $year . '</span></div></div>' : '')
    . '<form class="yearbar" method="get"><input type="hidden" name="reports" value="1"><label for="y">Year</label><select id="y" name="y" onchange="this.form.submit()">' . $yOpts . '</select>'
    . '<a class="btn line" href="' . h(self_url(['reports' => 1, 'y' => $year, 'export' => 1])) . '">Download Excel</a></form>'
    . ($missing ? '<p class="msg bad">' . $missing . ' order' . ($missing > 1 ? 's' : '') . ' in ' . $year . ' ha' . ($missing > 1 ? 've' : 's') . ' no cost price, so profit is too high. Type the cost of each bottle on the <a href="./?stock=1">Stock</a> page.</p>' : '')
    . '<div class="fill rfill"><h2>' . $year . ' by month</h2>' . $table($rows, fn($k) => date('M Y', strtotime("$k-01")), ["Total $year", $T])
    . '<h2>By year</h2>' . $table($all, fn($k) => (string)$k)
    . '<p class="muted small after">Sales are what customers paid (VAT included, COD fee included) for New, Paid and Delivered orders; cancelled orders, unpaid card attempts and test payments are left out. Cost of goods is the cost price of the bottles sold, including free minis. Profit = sales − cost of goods − expenses. In ' . $year . ': discounts given ' . money($T['discount'] ?? 0) . ', COD fees ' . money($T['fees'] ?? 0) . ', stock bought ' . money($T['stock_bought'] ?? 0) . ' (not taken off profit).</p></div>', true, true);
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
    $id = (string)($_POST['id'] ?? ''); $hide = ($_POST['hide'] ?? '') === '1';
    $ok = rv_change(function (&$list) use ($id, $hide) { foreach ($list as &$r) if ($r['id'] === $id) { $r['hidden'] = $hide; return true; } return false; });
    flash($ok ? ($hide ? 'Review removed from the website.' : 'Review is back on the website.') : 'That review could not be changed. Please try again.', (bool)$ok);
    go($back);
  }
  $all = rv_all(); usort($all, fn($a, $b) => strcmp($b['created'], $a['created']));
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
        . '<p>' . nl2br(h($r['text'])) . '</p>' . ($ph ? '<div class="rph">' . $ph . '</div>' : '')
        . '<form method="post" onsubmit="return ' . ($off ? 'true' : 'confirm(\'Remove this review from the website?\')') . '">' . csrf_field() . $hidden('id', $r['id']) . $hidden('hide', $off ? '0' : '1') . ($rp !== '' ? $hidden('rp', $rp) : '') . ($rq !== '' ? $hidden('rq', $rq) : '') . ($vf !== '' ? $hidden('vf', $vf) : '')
        . '<button class="btn sm' . ($off ? ' line' : ' danger') . '">' . ($off ? 'Put back on website' : 'Remove') . '</button></form></li>';
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
  $mob = '<div class="pagehead"><h1>Reviews</h1>' . $seg . '</div>'
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
        . '<p>' . nl2br(h($r['text'])) . '</p>' . ($ph ? '<div class="rph">' . $ph . '</div>' : '')
        . '<form method="post" onsubmit="return ' . ($off ? 'true' : 'confirm(\'Remove this review from the website?\')') . '">' . csrf_field() . $hidden('id', $r['id']) . $hidden('hide', $off ? '0' : '1') . ($rp !== '' ? $hidden('rp', $rp) : '') . ($rq !== '' ? $hidden('rq', $rq) : '') . ($vf !== '' ? $hidden('vf', $vf) : '')
        . '<button class="btn sm' . ($off ? ' line' : ' danger') . '">' . ($off ? 'Put back on website' : 'Remove') . '</button></form></li>';
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
  page('Reviews', flash() . '<div class="rmob">' . $mob . '</div><div class="rdesk">' . $body . '</div>', true, true);
}

/* ---- members: customers who keep coming back. Orders are grouped by mobile number (last 9 digits), or by email when there is no mobile ---- */
if (isset($_GET['members'])) {
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
  page('Members', '<div class="pagehead"><h1>Members</h1><a class="btn line sm" href="' . h(self_url(['members' => 1] + ($mq !== '' ? ['mq' => $mq] : []) + ['export' => 1])) . '">Download Excel</a></div>' . flash()
    . '<div class="mtop"><form class="card mmin" method="post">' . csrf_field() . '<label for="member_min">Orders: at least</label>'
    . '<input id="member_min" type="number" min="0" max="1000" inputmode="numeric" name="member_min" value="' . ($min ?: '') . '" placeholder="any"><span>orders</span><button class="btn sm">Save</button></form>'
    . '<form class="card mmin" method="post">' . csrf_field() . '<label for="member_spend">Spent: at least AED</label><input id="member_spend" class="amt" type="number" min="0" step="1" inputmode="numeric" name="member_spend" value="' . ($spend ?: '') . '" placeholder="any"><button class="btn sm">Save</button></form>'
    . '<form class="mq" method="get"><input type="hidden" name="members" value="1"><input name="mq" value="' . h($mq) . '" placeholder="Search name, mobile, email or area" aria-label="Search members"><button class="btn line sm">Search</button></form></div>'
    . '<div class="stats up"><div class="stat"><span>Members</span><b>' . count($mem) . '</b></div><div class="stat"><span>Members spent</span><b>' . money($memSpent) . '</b></div>'
    . '<div class="stat"><span>Share of all sales</span><b>' . ($allSales > 0 ? round($memSpent / $allSales * 100) : 0) . '%</b></div>'
    . '<div class="stat"><span>Average per member</span><b>' . money($mem ? $memSpent / count($mem) : 0) . '</b></div></div>'
    . '<div class="fill">' . ($list ? '<table class="mlist"><thead><tr><th>Member</th><th>Latest address</th><th class="num">Orders</th><th class="num">Spent</th><th class="num">Average</th><th>First – last order</th></tr></thead><tbody>' . $tr . '</tbody></table>'
         : '<p class="card muted" style="margin:0">' . ($mq !== '' ? 'No members match.' : 'No customer matches ' . ($min ? $min . ' or more orders' : '') . ($min && $spend ? ' and ' : '') . ($spend ? 'AED ' . number_format($spend) . ' or more spent' : '') . ($min || $spend ? '' : 'yet') . '.') . '</p>')
    . '<p class="muted small after">Orders from the same mobile number (or the same email when there is no mobile) count as one customer. Cancelled orders, unpaid card attempts and test payments are left out. Tap a member to see every order.</p></div>', true, true);
}

if (isset($_GET['o'])) {
  $s = $pdo->prepare('SELECT * FROM fx_orders WHERE order_no = ?'); $s->execute([(string)$_GET['o']]); $o = $s->fetch();
  if (!$o) page('Not found', '<h1>Order not found</h1><p><a href="./?orders=1">Back to orders</a></p>');
  $items = array_filter(array_map('trim', explode('|', (string)$o['items'])));
  $opts = ''; foreach (FX_STATUSES as $x) $opts .= '<option' . ($x === $o['status'] ? ' selected' : '') . '>' . h($x) . '</option>';
  $wa = preg_replace('/\D/', '', $o['phone']); if (str_starts_with($wa, '05')) $wa = '971' . substr($wa, 1);
  $row = fn($k, $v) => $v === '' || $v === null ? '' : '<dt>' . h($k) . '</dt><dd>' . $v . '</dd>';
  page($o['order_no'], '<p class="small"><a href="./?orders=1">← All orders</a></p>'
    . '<h1>' . h($o['order_no']) . ' ' . fx_tags($o) . '</h1>' . flash()
    . fx_tracker($o) . fx_order_buttons($o, '', 'order-acts')
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
  $yr = fomaxo_report($pdo, (int)date('Y')); $month = $yr[date('Y-m')];
  $todo = $pdo->query("SELECT SUM(status = 'New' OR (status = 'Paid' AND payment = 'Cash on delivery')) cod, SUM(status = 'Paid' AND payment <> 'Cash on delivery') card FROM fx_orders WHERE test = 0")->fetch();
  /* sales graphs: one with a Today / 7 days / 30 days switch, one by year (by month while there is only one year of sales) */
  $orders = fn($n) => $n ? money($n[0]) . ' · ' . $n[1] . ' order' . ($n[1] > 1 ? 's' : '') : 'no sales';
  $hours = array_fill(0, 24, [0.0, 0]);
  $s = $pdo->prepare("SELECT HOUR(created_at) h, SUM(total) t, COUNT(*) n FROM fx_orders WHERE $real AND created_at >= ? GROUP BY HOUR(created_at)");
  $s->execute([date('Y-m-d') . ' 00:00:00']);
  foreach ($s as $r) $hours[(int)$r['h']] = [(float)$r['t'], (int)$r['n']];
  $days = []; for ($i = 29; $i >= 0; $i--) $days[date('Y-m-d', strtotime("-$i day"))] = [0.0, 0];
  $s = $pdo->prepare("SELECT DATE(created_at) d, SUM(total) t, COUNT(*) n FROM fx_orders WHERE $real AND created_at >= ? GROUP BY DATE(created_at)");
  $s->execute([array_key_first($days) . ' 00:00:00']);
  foreach ($s as $r) if (isset($days[$r['d']])) $days[$r['d']] = [(float)$r['t'], (int)$r['n']];
  $months = []; for ($i = 11; $i >= 0; $i--) $months[date('Y-m', strtotime(date('Y-m-01') . " -$i month"))] = [0.0, 0];
  $s = $pdo->prepare("SELECT DATE_FORMAT(created_at, '%Y-%m') m, SUM(total) t, COUNT(*) n FROM fx_orders WHERE $real AND created_at >= ? GROUP BY m");
  $s->execute([array_key_first($months) . '-01 00:00:00']);
  foreach ($s as $r) if (isset($months[$r['m']])) $months[$r['m']] = [(float)$r['t'], (int)$r['n']];
  $pt = fn($v, $label, $tip) => ['v' => $v[0], 'l' => $label, 't' => $tip . ': ' . $orders($v[1] ? $v : null)];
  $series = [
    'day' => array_map(fn($h, $v) => $pt($v, date('ga', mktime($h, 0)), date('ga', mktime($h, 0)) . '–' . date('ga', mktime($h + 1, 0))), array_keys($hours), $hours),
    'week' => array_map(fn($d, $v) => $pt($v, date('D', strtotime($d)), date('D j M', strtotime($d))), array_keys($days), $days),
    'month' => array_map(fn($d, $v) => $pt($v, date('j M', strtotime($d)), date('D j M', strtotime($d))), array_keys($days), $days),
  ];
  $series['week'] = array_slice($series['week'], -7);
  $series['year'] = array_map(fn($m, $v) => $pt($v, date('M', strtotime("$m-01")), date('F Y', strtotime("$m-01"))), array_keys($months), $months);   // last 12 months
  $sum = fn($k) => money(array_sum(array_column($series[$k], 'v')));
  $ranges = ['day' => 'Today', 'week' => '7 days', 'month' => '30 days', 'year' => 'Year'];
  /* visitors (from track.php) for the same periods: different people per hour, day or month */
  $vis = ['day' => [], 'week' => [], 'month' => [], 'year' => []];
  $count = function ($fmt, $from) use ($pdo) { $o = []; $s = $pdo->prepare("SELECT DATE_FORMAT(at, '$fmt') k, COUNT(DISTINCT vid) n FROM fx_events WHERE at >= ? GROUP BY k"); $s->execute([$from]); foreach ($s as $r) $o[$r['k']] = (int)$r['n']; return $o; };
  $vt = []; $people = fn($n) => $n . ' visitor' . ($n === 1 ? '' : 's');
  try {
    $c = $count('%H', date('Y-m-d') . ' 00:00:00');
    foreach (array_keys($hours) as $h) $vis['day'][] = ['v' => $c[sprintf('%02d', $h)] ?? 0, 'l' => date('ga', mktime($h, 0)), 't' => date('ga', mktime($h, 0)) . '–' . date('ga', mktime($h + 1, 0)) . ': ' . $people($c[sprintf('%02d', $h)] ?? 0)];
    $c = $count('%Y-%m-%d', array_key_first($days) . ' 00:00:00');
    foreach (array_keys($days) as $d) $vis['month'][] = ['v' => $c[$d] ?? 0, 'l' => date('j M', strtotime($d)), 't' => date('D j M', strtotime($d)) . ': ' . $people($c[$d] ?? 0)];
    foreach (array_slice(array_keys($days), -7) as $d) $vis['week'][] = ['v' => $c[$d] ?? 0, 'l' => date('D', strtotime($d)), 't' => date('D j M', strtotime($d)) . ': ' . $people($c[$d] ?? 0)];
    $c = $count('%Y-%m', array_key_first($months) . '-01 00:00:00');
    foreach (array_keys($months) as $m) $vis['year'][] = ['v' => $c[$m] ?? 0, 'l' => date('M', strtotime("$m-01")), 't' => date('F Y', strtotime("$m-01")) . ': ' . $people($c[$m] ?? 0)];
    /* totals count each person once per period, not once per bar */
    foreach (['day' => date('Y-m-d') . ' 00:00:00', 'week' => date('Y-m-d', strtotime('-6 day')) . ' 00:00:00', 'month' => array_key_first($days) . ' 00:00:00', 'year' => array_key_first($months) . '-01 00:00:00'] as $k => $from) {
      $s = $pdo->prepare('SELECT COUNT(DISTINCT vid) FROM fx_events WHERE at >= ?'); $s->execute([$from]); $vt[$k] = $people((int)$s->fetchColumn()); }
  } catch (Throwable $e) { foreach ($vis as $k => $v) { $vis[$k] = $series[$k]; foreach ($vis[$k] as &$p) { $p['v'] = 0; $p['t'] = 'No visitor data yet'; } unset($p); $vt[$k] = $people(0); } }
  /* laptop: a Sales graph and a Visitors graph; phone: one graph with a Sales / Visitors switch (the Visitors card is hidden) */
  $graphs = ['sales' => '', 'visitors' => '']; $tots = ['sales' => '', 'visitors' => ''];
  foreach (['sales' => $series, 'visitors' => $vis] as $m => $set) foreach ($ranges as $k => $l) {
    $graphs[$m] .= str_replace('<div class="graph"', '<div class="graph" data-k="' . $m . '-' . $k . '"', fx_graph($set[$k], ucfirst($m) . ' ' . strtolower($l), $k !== 'month', $m === 'sales' ? 'money' : fn($v) => $people((int)$v)));
    $tots[$m] .= '<span data-k="' . $m . '-' . $k . '"' . ($k === 'month' ? '' : ' hidden') . '>' . h($m === 'sales' ? $sum($k) : $vt[$k]) . '</span>';
  }
  $rangeSeg = '<div class="seg" role="group" aria-label="Period">' . implode('', array_map(fn($k, $l) => '<button type="button" data-r="' . $k . '"' . ($k === 'month' ? ' class="on"' : '') . '>' . $l . '</button>', array_keys($ranges), $ranges)) . '</div>';
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
    . '<div class="tiles">'
    . $tile((int)$todo['cod'], 'Cash to deliver', 'todo' . ($todo['cod'] ? ' hot' : ''), self_url(['orders' => 1, 'status' => 'New']))
    . $tile((int)$todo['card'], 'Card to deliver', 'todo' . ($todo['card'] ? ' hot' : ''), self_url(['orders' => 1, 'status' => 'Paid']))
    . $tile($aed($today['sales']), 'Today · ' . $plural($today['n'], 'order'))
    . $tile($aed($month['sales']), date('M') . ' · ' . $plural($month['orders'], 'order'))
    . $tile(($profit < 0 ? '−' : '') . $aed(abs($profit)), ($profit < 0 ? 'Loss ' : 'Profit ') . date('M'), '', self_url(['reports' => 1]), $profit < 0 ? 'lvl-out' : 'lvl-ok')
    . '</div>'
    . ($month['no_cost'] ? '<p class="muted note">' . $plural($month['no_cost'], 'order') . ' this month ha' . ($month['no_cost'] > 1 ? 've' : 's') . ' no cost price, so profit shows too high. <a href="./?stock=1">Add costs</a></p>' : '')
    . '<div class="dgrid">'
    . '<section class="card c-sales" data-m="sales" data-r="month"><div class="ch wrap2"><h2 class="dt">Sales</h2><div class="seg met" role="group" aria-label="Show"><button type="button" data-m="sales" class="on">Sales</button><button type="button" data-m="visitors">Visitors</button></div>' . $rangeSeg . '</div>'
    . '<div class="chartbox">' . $graphs['sales'] . $hideV($graphs['visitors']) . '</div>'
    . '<p class="sub"><span class="tip">Tap a bar to see the details</span><b class="tot">' . $tots['sales'] . $hideV($tots['visitors']) . '</b></p></section>'
    . '<section class="card c-visits" data-m="visitors" data-r="month"><div class="ch wrap2"><h2>Visitors</h2>' . $rangeSeg . '</div>'
    . '<div class="chartbox">' . $graphs['visitors'] . '</div>'
    . '<p class="sub"><span class="tip">Tap a bar to see the details</span><b class="tot">' . $tots['visitors'] . '</b></p></section>'
    . '<div class="lists"><section class="card c-orders"><div class="ch"><h2>Latest orders</h2><a href="./?orders=1">All orders</a></div>' . ($latest ? '<ul class="list orders">' . $latest . '</ul>' : '<p class="muted empty">No orders yet.</p>') . '</section>'
    . '<section class="card c-stock"><div class="ch"><h2>Stock alerts</h2><a href="./?stock=1">Stock</a></div>' . ($alerts ? '<ul class="list">' . $alerts . '</ul>' : '<p class="muted empty">No size is running low.</p>') . '</section></div>'
    . '</div>'
    . '<nav class="quick"><a class="btn line sm" href="./?expenses=1">+ Add an expense</a><a class="btn line sm" href="' . h(self_url(['products' => 1, 'p' => 'new'])) . '">+ Add a product</a>'
    . '<a class="btn line sm" href="' . h(self_url(['orders' => 1, 'export' => 1])) . '">Download all orders (Excel)</a><a class="btn line sm" href="' . h(self_url(['reports' => 1, 'y' => date('Y'), 'export' => 1])) . '">Download ' . date('Y') . ' profit &amp; loss (Excel)</a></nav>'
    . '</div>'
    . '<script>document.querySelectorAll(".chart .hit").forEach(function(r){var s=function(){var c=r.closest(".card");c.querySelector(".tip").textContent=r.dataset.t;c.querySelectorAll(".hit.on").forEach(function(x){x.classList.remove("on")});r.classList.add("on")};r.addEventListener("mouseenter",s);r.addEventListener("click",s)});'
    . 'document.querySelectorAll(".c-sales .seg button,.c-visits .seg button").forEach(function(b){b.addEventListener("click",function(){var c=b.closest(".card"),g=b.parentNode;g.querySelectorAll("button").forEach(function(x){x.classList.toggle("on",x===b)});if(b.dataset.m)c.dataset.m=b.dataset.m;if(b.dataset.r)c.dataset.r=b.dataset.r;var k=c.dataset.m+"-"+c.dataset.r;'
    . 'c.querySelectorAll(".chartbox .graph,.tot span").forEach(function(x){x.hidden=x.dataset.k!==k});c.querySelector(".tip").textContent="Tap a bar to see the details";c.querySelectorAll(".hit.on").forEach(function(x){x.classList.remove("on")})})});</script>', true, true);
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
  $tr .= '<tr class="row" onclick="location.href=\'' . $u . '\'"><td class="no" data-l=""><a href="' . $u . '">' . h($o['order_no']) . '</a>' . ($o['test'] ? ' <span class="muted small">test</span>' : '') . '</td>'
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

page('Orders', '<h1>Orders</h1>' . flash()
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
