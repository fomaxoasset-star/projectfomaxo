<?php
/* FOMAXO — the shop page without its code notes. index.html keeps every note (the slash-star and double-slash notes in its <style> and <script>
   parts) for whoever works on it; the page sent to shoppers leaves them out, about 25 KB less to download on every open.
   Nothing else changes: words, code and styles stay exactly as written. If anything looks unusual (an unfinished string,
   comment or pattern) the page goes out exactly as index.html is, so a mistake here can never break the shop. */

/* the notes of one <style> (css) or <script> (js) part; null when the part doesn't read cleanly (then nothing is changed) */
function fx_lean_part(string $s, bool $js): ?string {
  $n = strlen($s); $o = ''; $i = 0; $tpl = []; $prev = '';   // $tpl: open `${` depths of template strings; $prev: last code character
  $stop = $js ? "/'\"`{}" : "/'\"";
  while ($i < $n) {
    $k = strcspn($s, $stop, $i);
    if ($k) { $chunk = substr($s, $i, $k); $o .= $chunk; $t = rtrim($chunk); if ($t !== '') $prev = substr($t, -12); $i += $k; if ($i >= $n) break; }
    $c = $s[$i];
    if ($c === '"' || $c === "'") {   // a string: copy as it is
      $j = $i + 1;
      while (true) { $j += strcspn($s, $c . "\\\n", $j); if ($j >= $n || $s[$j] === "\n") return null; if ($s[$j] === '\\') { $j += 2; continue; } break; }
      $o .= substr($s, $i, $j + 1 - $i); $prev = $c; $i = $j + 1; continue;
    }
    if ($js && $c === '`') { $r = fx_lean_tpl($s, $i + 1, $n); if ($r === null) return null; [$j, $open] = $r; $o .= substr($s, $i, $j - $i); $i = $j; if ($open) $tpl[] = 0; else $prev = '`'; continue; }
    if ($js && $c === '{') { if ($tpl) $tpl[count($tpl) - 1]++; $o .= $c; $prev = $c; $i++; continue; }
    if ($js && $c === '}') {
      if ($tpl && $tpl[count($tpl) - 1] === 0) {   // end of a `${…}` inside a template string: back into the string
        array_pop($tpl); $r = fx_lean_tpl($s, $i + 1, $n); if ($r === null) return null; [$j, $open] = $r; $o .= substr($s, $i, $j - $i); $i = $j; if ($open) $tpl[] = 0; else $prev = '`'; continue;
      }
      if ($tpl) $tpl[count($tpl) - 1]--; $o .= $c; $prev = $c; $i++; continue;
    }
    // $c === '/'
    $d = $s[$i + 1] ?? '';
    if ($d === '*' || ($js && $d === '/')) {
      $e = $d === '*' ? strpos($s, '*/', $i + 2) : strcspn($s, "\n", $i) + $i;
      if ($e === false) return null;
      if ($d === '*') $e += 2;
      $body = substr($s, $i, $e - $i);
      $ls = strrpos($o, "\n"); $lead = $ls === false ? $o : substr($o, $ls + 1);
      $whole = trim($lead) === '' && ($e >= $n || $s[$e] === "\n" || ($d === '*' && trim(substr($s, $e, strcspn($s, "\n", $e))) === ''));
      if ($whole) {   // a note on its own line(s): the line goes
        $o = $ls === false ? '' : substr($o, 0, $ls + 1);
        $e += strspn($s, " \t\r", $e); if ($e < $n && $s[$e] === "\n" && $ls !== false) $e++;
      } elseif (!$js) {   // a note inside a line of styles goes only where a space is already beside it (else it stays)
        $o .= ctype_space(substr($o, -1)) || ctype_space($s[$e] ?? ' ') ? '' : $body;
      } else {
        $o = rtrim($o, " \t");
        if ($d === '*') $o .= str_contains($body, "\n") ? "\n" : ' ';   // keeps line breaks where JavaScript needs them
      }
      $i = $e; continue;
    }
    if ($js && fx_lean_regex_ok($prev)) {   // a /pattern/: copy as it is
      $j = $i + 1; $cls = false;
      while (true) {
        if ($j >= $n || $s[$j] === "\n") return null;
        $ch = $s[$j];
        if ($ch === '\\') { $j += 2; continue; }
        if ($ch === '[') $cls = true; elseif ($ch === ']') $cls = false; elseif ($ch === '/' && !$cls) break;
        $j++;
      }
      $j++; $j += strspn($s, 'dgimsuyv', $j);
      $o .= substr($s, $i, $j - $i); $prev = 'a'; $i = $j; continue;
    }
    $o .= '/'; $prev = '/'; $i++;
  }
  return $tpl ? null : $o;
}
/* the rest of a `template string` from $i: [where it stops, true when it stops at ${ ] */
function fx_lean_tpl(string $s, int $i, int $n): ?array {
  while (true) {
    $i += strcspn($s, "`\\$", $i);
    if ($i >= $n) return null;
    if ($s[$i] === '\\') { $i += 2; continue; }
    if ($s[$i] === '`') return [$i + 1, false];
    if (($s[$i + 1] ?? '') === '{') return [$i + 2, true];
    $i++;
  }
}
/* is a / after this a /pattern/ (not a division)? */
function fx_lean_regex_ok(string $prev): bool {
  if ($prev === '') return true;
  $c = substr($prev, -1);
  if (str_contains('(,=:[!&|?{};+-*%<>~^', $c)) return true;
  return (bool)preg_match('/(?:^|[^\w$])(?:return|typeof|case|do|else|in|of|new|delete|void|throw|yield|await)$/', $prev);
}
/* the whole page: only the insides of <style> and <script> change */
function fx_lean_html(string $html): string {
  $out = preg_replace_callback('#(<(style|script)\b([^>]*)>)(.*?)(</\2>)#si', function ($m) use (&$bad) {
    if ($m[4] === '' || (strtolower($m[2]) === 'script' && preg_match('#\bsrc=#i', $m[3]))) return $m[0];
    $r = fx_lean_part($m[4], strtolower($m[2]) === 'script');
    if ($r === null) { $bad = true; return $m[0]; }
    return $m[1] . $r . $m[5];
  }, $html);
  if (!is_string($out) || !empty($bad) || strlen($out) < strlen($html) * 0.75) return $html;
  return $out;
}

/* the page as shoppers get it: the same page without its notes, kept ready in fomaxo-lean/ (above the website folder) so the
   work is done once for each version of the page, not on every visit */
function fomaxo_lean_page(string $html): string {
  $dir = dirname(__DIR__) . '/fomaxo-lean'; $f = $dir . '/' . md5($html) . '.html';
  if (is_file($f) && ($s = (string)@file_get_contents($f)) !== '') { if (@filemtime($f) < time() - 86400) @touch($f); return $s; }
  $s = fx_lean_html($html);
  if ((is_dir($dir) || @mkdir($dir, 0755)) && @file_put_contents($f . '.tmp', $s) === strlen($s)) {
    @rename($f . '.tmp', $f);
    $all = glob($dir . '/*.html') ?: [];
    if (count($all) > 200) { usort($all, fn($a, $b) => @filemtime($a) <=> @filemtime($b)); foreach (array_slice($all, 0, 100) as $x) @unlink($x); }   // old versions
  }
  return $s;
}
/* send a page: without its notes, and "not modified" (a few bytes) when the browser already has this same page */
function fomaxo_send_page($html) {
  $html = fomaxo_lean_page((string)$html);
  $tag = substr(md5($html), 0, 16);
  header('ETag: "' . $tag . '"');
  if (str_contains((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''), $tag)) { http_response_code(304); return; }   // also matches "tag-gzip" from the server's compression
  echo $html;
}
