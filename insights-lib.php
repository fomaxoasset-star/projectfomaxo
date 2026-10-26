<?php
/* FOMAXO — "our own Clarity": what visits did on the website, read from fx_events (pages) and fx_trail (taps, how far down each page).
   fomaxo.com/admin → Analytics → Visits shows it; insights/visits.txt is the same summary as plain text (rewritten at most once an hour by track.php)
   so it can be read from the hosting file manager without logging in to the admin. Anonymous only: no names, mobiles or anything typed. */

/* a page address as one name: no ?size=…, home is "/" */
function fx_pg_sql($col = 'page') { return "CASE WHEN SUBSTRING_INDEX($col, '?', 1) IN ('', '/', 'home') THEN '/' ELSE SUBSTRING_INDEX($col, '?', 1) END"; }
function fx_pg_name($pg, $names = []) {
  if ($pg === '/') return 'Home';
  if (preg_match('~^/product/([a-z0-9-]+)~', $pg, $m)) return $names[$m[1]] ?? ucfirst($m[1]);
  return ucfirst(str_replace(['-', '/'], [' ', ' / '], trim($pg, '/')));
}

/* everything Analytics → Visits shows, for one date range. $not: the admin's filter that leaves out trashed test orders (fx_not_trash) */
/* the recorder names a tap by its words; parts with no words get a code name (#mbuy, div.rv-sum), shown here in plain words */
function fx_tap_name($l) {
  static $map = ['#mbuy' => 'Bottom Add to bag bar', 'div.n' => 'Bottom Add to bag bar', '#toast' => 'Added to bag message', 'div.rv-sum' => 'Reviews summary', 'article.rv' => 'A review', 'p.rw-note' => 'Review note', 'div.empty' => 'Empty bag', 'video' => 'Video'];
  $l = (string)$l;
  return $map[$l] ?? (preg_match('/^(#[\w-]+|[a-z]+\.[\w-]+)$/', $l) ? 'Part of the page (' . $l . ')' : $l);
}
/* taps on things that were fixed later: their Not buttons and Angry taps from before the fix leave What people tap (Dubai time the fix went live) */
const FX_TAP_FIXED = ['#mbuy' => '2026-10-26 20:06:00', 'div.n' => '2026-10-26 20:06:00',   // bottom Add to bag bar: whole bar adds to bag
  'Photo: FOMAXO %' => '2026-10-27 00:00:00'];   // product page big photo: one tap zooms in on phones (a label ending in % = every label that starts so)
function fx_tap_fixed_sql() {
  return implode('', array_map(fn($l, $at) => " AND NOT ((dead = 1 OR rage = 1) AND label " . (str_ends_with($l, '%') ? 'LIKE' : '=') . " '" . addslashes($l) . "' AND at < '$at')", array_keys(FX_TAP_FIXED), FX_TAP_FIXED));
}
function fx_visits_data($pdo, $from, $to, $not = '', $paths = 40) {
  $q = function ($sql, $args = null) use ($pdo, $from, $to) { $s = $pdo->prepare($sql); $s->execute($args ?? [$from, $to]); return $s->fetchAll(PDO::FETCH_ASSOC); };
  $pg = fx_pg_sql();
  $out = ['leave' => [], 'taps' => [], 'paths' => [], 'visits' => 0, 'has_trail' => false, 'speed' => [], 'errors' => []];
  /* where visits end: the last page of each visit; time on a page = until the next page, or the last moment it was seen open (at most 30 minutes) */
  try {
    $out['leave'] = $q("SELECT pg, COUNT(DISTINCT sid) seen, SUM(last1) left_n, ROUND(AVG(d)) secs FROM (
                          SELECT sid, $pg pg, ROW_NUMBER() OVER (PARTITION BY sid ORDER BY at DESC, id DESC) = 1 last1,
                            LEAST(TIMESTAMPDIFF(SECOND, at, COALESCE(LEAD(at) OVER (PARTITION BY sid ORDER BY at, id), left_at)), 1800) d
                          FROM fx_events WHERE ev = 'view' AND at BETWEEN ? AND ?$not) x
                        GROUP BY pg ORDER BY left_n DESC, seen DESC LIMIT 40");
    $out['visits'] = (int)($q("SELECT COUNT(DISTINCT sid) n FROM fx_events WHERE ev = 'view' AND at BETWEEN ? AND ?$not")[0]['n'] ?? 0);
  } catch (Throwable $e) {}
  try {
    $sc = [];   // how far down each page was seen (the furthest point of each visit, averaged)
    foreach ($q("SELECT pg, ROUND(AVG(m)) pc, SUM(m >= 90) bottom, COUNT(*) n FROM (SELECT sid, $pg pg, MAX(pc) m FROM fx_trail WHERE k = 's' AND at BETWEEN ? AND ?$not GROUP BY sid, pg) x GROUP BY pg") as $r) $sc[$r['pg']] = $r;
    foreach ($out['leave'] as &$r) { $r['pc'] = $sc[$r['pg']]['pc'] ?? null; $r['bottom'] = isset($sc[$r['pg']]) ? round(100 * $sc[$r['pg']]['bottom'] / max(1, $sc[$r['pg']]['n'])) : null; } unset($r);
    /* what was tapped: buttons and links, things that are not buttons (people expected something to happen), and the same spot tapped 3 times in a second */
    $out['taps'] = $q("SELECT $pg pg, label, dead, COUNT(*) taps, COUNT(DISTINCT sid) people, SUM(rage) rage, COUNT(DISTINCT CASE WHEN rage = 1 THEN sid END) rpeople FROM fx_trail WHERE k = 't' AND at BETWEEN ? AND ?$not" . fx_tap_fixed_sql() . " GROUP BY pg, label, dead ORDER BY taps DESC LIMIT 300");
    foreach ($out['taps'] as &$r) $r['name'] = fx_tap_name($r['label']); unset($r);
    $out['has_trail'] = (bool)$q("SELECT 1 FROM fx_trail LIMIT 1", []);
    /* how fast the website opened (seconds until the page was ready), by device, and errors on the page */
    foreach ($q("SELECT device, COUNT(*) n, ROUND(AVG(ms)) avg_ms, SUM(ms > 3000) slow FROM fx_trail WHERE k = 'l' AND ms > 0 AND at BETWEEN ? AND ?$not GROUP BY device ORDER BY n DESC") as $r) $out['speed'][$r['device']] = $r;
    $out['errors'] = $q("SELECT $pg pg, label, COUNT(DISTINCT sid) people, COUNT(*) n FROM fx_trail WHERE k = 'e' AND at BETWEEN ? AND ?$not GROUP BY pg, label ORDER BY people DESC, n DESC LIMIT 20");
  } catch (Throwable $e) {}   // before the first tap makes fx_trail
  /* the newest visits, step by step */
  try {
    $sids = array_column($q("SELECT sid, MAX(at) last FROM fx_events WHERE ev = 'view' AND at BETWEEN ? AND ?$not GROUP BY sid ORDER BY last DESC LIMIT " . (int)$paths), 'sid');
    if ($sids) {
      $in = implode(',', array_fill(0, count($sids), '?'));
      $V = []; foreach ($q("SELECT id, sid, at, ev, $pg pg, product, source, device, country, left_at FROM fx_events WHERE sid IN ($in) ORDER BY at, id", $sids) as $r) $V[$r['sid']][] = $r;
      $T = []; try { foreach ($q("SELECT sid, at, $pg pg, k, label, pc, dead, rage, ms FROM fx_trail WHERE sid IN ($in) ORDER BY at, id", $sids) as $r) $T[$r['sid']][] = $r; } catch (Throwable $e) {}
      foreach ($sids as $sid) {
        $ev = $V[$sid] ?? []; if (!$ev) continue;
        $visit = ['sid' => $sid, 'start' => $ev[0]['at'], 'device' => '', 'country' => '', 'source' => '', 'steps' => [], 'end' => 'left', 'load' => null];
        $done = [];
        foreach ($ev as $r) {
          if ($r['device'] && !$visit['device']) $visit['device'] = $r['device'];
          if ($r['country'] && !$visit['country']) $visit['country'] = $r['country'];
          if ($r['source'] && !$visit['source']) $visit['source'] = $r['source'];
          if ($r['ev'] === 'view') $visit['steps'][] = ['pg' => $r['pg'], 'at' => $r['at'], 'until' => $r['left_at'], 'pc' => null, 'taps' => []];
          else $done[$r['ev']] = 1;
        }
        $st = &$visit['steps'];
        for ($i = 0; $i < count($st); $i++) { $end = $st[$i + 1]['at'] ?? $st[$i]['until']; $st[$i]['secs'] = $end ? min(1800, max(0, strtotime($end) - strtotime($st[$i]['at']))) : null; }
        foreach ($T[$sid] ?? [] as $t) {   // each tap, scroll and error goes to the last step on that page that started before it
          if ($t['k'] === 'l') { $visit['load'] ??= (int)$t['ms']; continue; }
          for ($i = count($st) - 1; $i >= 0; $i--) if ($st[$i]['pg'] === $t['pg'] && $st[$i]['at'] <= $t['at']) break;
          if ($i < 0) for ($i = 0; $i < count($st); $i++) if ($st[$i]['pg'] === $t['pg']) break;
          if ($i >= count($st) || $i < 0) continue;
          if ($t['k'] === 's') $st[$i]['pc'] = max((int)$st[$i]['pc'], (int)$t['pc']);
          else $st[$i]['taps'][] = ['l' => $t['k'] === 'e' ? 'Error: ' . $t['label'] : fx_tap_name($t['label']), 'dead' => (int)$t['dead'], 'rage' => (int)$t['rage'], 'err' => (int)($t['k'] === 'e')];
        }
        unset($st);
        $visit['end'] = isset($done['buy']) ? 'bought' : (isset($done['card']) || isset($done['pay']) ? 'payment' : (isset($done['checkout']) ? 'checkout' : (isset($done['cart']) ? 'bag' : (count($visit['steps']) === 1 && !array_filter($visit['steps'][0]['taps'], fn($x) => !$x['dead'] && !$x['err']) ? 'bounce' : 'left'))));
        $out['paths'][] = $visit;
      }
    }
  } catch (Throwable $e) {}
  return $out;
}

/* plain words for the end of a visit */
function fx_visit_end($k) { return ['bought' => 'Bought', 'payment' => 'Left at payment', 'checkout' => 'Left at checkout', 'bag' => 'Added to bag, left', 'bounce' => 'Left at once', 'left' => 'Looked, left'][$k] ?? $k; }
function fx_secs($t) { return $t === null ? '–' : ($t >= 60 ? intdiv((int)$t, 60) . 'm ' . str_pad((string)((int)$t % 60), 2, '0', STR_PAD_LEFT) . 's' : (int)$t . 's'); }

/* insights/visits.txt: rewritten at most once an hour, while visitors are on the site */
function fx_insights_file() { return __DIR__ . '/insights/visits.txt'; }
function fx_insights_due($pdo) { $f = fx_insights_file(); return is_dir(dirname($f)) && (!is_file($f) || filemtime($f) < time() - 3600); }
function fx_insights_write($pdo) {
  date_default_timezone_set('Asia/Dubai');
  $f = fx_insights_file();
  @touch($f);   // another visit does not start the same work meanwhile
  try {
    $names = []; try { foreach ($pdo->query('SELECT id, data FROM fx_products') as $r) { $d = json_decode((string)$r['data'], true); $names[$r['id']] = $d['name'] ?? $r['id']; } } catch (Throwable $e) {}
    $L = ["FOMAXO visits summary (anonymous). Written " . date('d/m/Y H:i') . " Dubai time. Same as fomaxo.com/admin → Analytics → Visits.", ''];
    foreach (['Last 7 days' => 6, 'Today' => 0] as $title => $back) {
      $D = fx_visits_data($pdo, date('Y-m-d 00:00:00', strtotime("-$back day")), date('Y-m-d 23:59:59'), '', $back ? 60 : 30);
      $L[] = "==== $title: {$D['visits']} visits ===="; $L[] = '';
      $L[] = 'WHERE PEOPLE LEAVE (page | visits that saw it | left from it | % left | avg time | avg seen down the page | % reached the bottom)';
      foreach ($D['leave'] as $r) $L[] = sprintf('%s | %d | %d | %d%% | %s | %s | %s', fx_pg_name($r['pg'], $names), $r['seen'], $r['left_n'], $r['seen'] ? round(100 * $r['left_n'] / $r['seen']) : 0, fx_secs($r['secs']), $r['pc'] !== null ? $r['pc'] . '%' : '–', $r['bottom'] !== null ? $r['bottom'] . '%' : '–');
      $L[] = ''; $L[] = 'HOW FAST THE SITE OPENED (device | page loads | average | slower than 3 s)';
      foreach ($D['speed'] as $dv => $r) $L[] = sprintf('%s | %d | %.1fs | %d%%', $dv, $r['n'], $r['avg_ms'] / 1000, round(100 * $r['slow'] / max(1, $r['n'])));
      if (!$D['speed']) $L[] = '(none)';
      $L[] = ''; $L[] = 'ERRORS ON THE PAGE (page | error | people | times)';
      foreach ($D['errors'] as $r) $L[] = sprintf('%s | %s | %d | %d', fx_pg_name($r['pg'], $names), $r['label'], $r['people'], $r['n']);
      if (!$D['errors']) $L[] = '(none)';
      foreach (['TAPPED BUTTONS AND LINKS' => fn($r) => !$r['dead'], 'TAPS ON THINGS THAT ARE NOT BUTTONS' => fn($r) => $r['dead'], 'ANGRY TAPS (same spot 3 times in a second)' => fn($r) => $r['rage'] > 0] as $h => $keep) {
        $L[] = ''; $L[] = "$h (page | thing | taps | people)"; $n = 0;
        foreach ($D['taps'] as $r) if ($keep($r) && $n++ < 40) $L[] = sprintf('%s | %s | %d | %d', fx_pg_name($r['pg'], $names), $r['name'], $h[0] === 'A' ? $r['rage'] : $r['taps'], $h[0] === 'A' ? $r['rpeople'] : $r['people']);
        if (!$n) $L[] = '(none)';
      }
      $L[] = ''; $L[] = 'NEWEST VISITS, STEP BY STEP';
      foreach ($D['paths'] as $v) {
        $L[] = date('d/m H:i', strtotime($v['start'])) . ' · ' . ($v['device'] ?: '?') . ' · ' . ($v['country'] ?: '?') . ' · from ' . ($v['source'] ?: '?') . ($v['load'] ? ' · opened in ' . round($v['load'] / 1000, 1) . 's' : '') . ' · ' . fx_visit_end($v['end']);
        foreach ($v['steps'] as $s) {
          $taps = array_map(fn($t) => ($t['dead'] ? '(not a button) ' : '') . $t['l'] . ($t['rage'] ? ' [angry]' : ''), $s['taps']);
          $L[] = '   ' . fx_pg_name($s['pg'], $names) . ' · ' . fx_secs($s['secs']) . ($s['pc'] !== null ? ' · seen ' . $s['pc'] . '%' : '') . ($taps ? ' · tapped: ' . implode(', ', array_slice($taps, 0, 12)) . (count($taps) > 12 ? ' …' : '') : '');
        }
      }
      $L[] = ''; $L[] = '';
    }
    file_put_contents($f . '.tmp', implode("\n", $L)); rename($f . '.tmp', $f);
  } catch (Throwable $e) { error_log('FOMAXO insights: ' . $e->getMessage()); }
}
