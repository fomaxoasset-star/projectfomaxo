<?php
// Small product photos for the Sell screen. Makes a light copy of the fomaxo.com photo once,
// keeps it on the server, and lets phones keep it too - so tiles show instantly.
// image.php?k=gold-main2&w=360
require __DIR__ . '/lib/core.php';

$key = (string) ($_GET['k'] ?? '');
$w = (int) ($_GET['w'] ?? 360);
$w = in_array($w, [120, 360, 600], true) ? $w : 360;
if (!preg_match('#^[a-z0-9_-]+(/[a-z0-9_-]+)?$#i', $key)) {
    http_response_code(404);
    exit;
}

$cacheDir = __DIR__ . '/data/thumbs';
$cacheFile = $cacheDir . '/' . str_replace('/', '__', $key) . '-' . $w . '.webp';

function send_image($file, $type = 'image/webp')
{
    header('Content-Type: ' . $type);
    header('Cache-Control: public, max-age=604800');
    header('Content-Length: ' . filesize($file));
    readfile($file);
    exit;
}

if (is_file($cacheFile) && filemtime($cacheFile) > time() - 7 * 86400) {
    send_image($cacheFile);
}

// The original: read straight from the website folder on the same hosting account, else download it.
$data = false;
$local = dirname(__DIR__, 2) . '/fomaxo.com/public_html/assets/img/' . $key . '.webp';
if (@is_readable($local)) {
    $data = @file_get_contents($local);
}
if ($data === false) {
    $ch = curl_init('https://fomaxo.com/assets/img/' . $key . '.webp');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => true, CURLOPT_USERAGENT => 'FOMAXO-Store-System']);
    $data = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) {
        $data = false;
    }
}
if ($data === false || $data === '') {
    http_response_code(404);
    exit;
}

$src = function_exists('imagecreatefromstring') ? @imagecreatefromstring($data) : false;
if (!$src || !function_exists('imagewebp')) {
    // No resizing available: pass the original through.
    header('Content-Type: image/webp');
    header('Cache-Control: public, max-age=604800');
    echo $data;
    exit;
}
$sw = imagesx($src);
$sh = imagesy($src);
$side = min($sw, $sh);                         // square crop from the centre, like the tiles
$dst = imagecreatetruecolor($w, $w);
imagecopyresampled($dst, $src, 0, 0, (int) (($sw - $side) / 2), (int) (($sh - $side) / 2), $w, $w, $side, $side);
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0755, true);
}
$tmp = $cacheFile . '.' . getmypid() . '.tmp';
if (@imagewebp($dst, $tmp, 78) && @rename($tmp, $cacheFile)) {
    send_image($cacheFile);
}
@unlink($tmp);
header('Content-Type: image/webp');
header('Cache-Control: public, max-age=604800');
imagewebp($dst, null, 78);
