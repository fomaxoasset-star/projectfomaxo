<?php
// Product photos for the app. Each fomaxo.com photo is shrunk ONCE into photos/<name>-<version>-<size>.webp.
// After that the web server sends that plain file straight away (see .htaccess), and phones keep it for good.
// <version> is the date of the website photo, so when a photo changes on fomaxo.com it gets a new name and shows at once.
//   photos/gold-main2-1729500000-360.webp   (normal address; this script only runs the first time)
//   image.php?k=gold-main2&w=360            (older address, still works)
require __DIR__ . '/lib/core.php';

$key = str_replace('__', '/', (string) ($_GET['k'] ?? ''));
$w = (int) ($_GET['w'] ?? 360);
$w = in_array($w, [120, 360, 600], true) ? $w : 360;
if (!preg_match('#^[a-z0-9_-]+(/[a-z0-9_-]+)?$#i', $key)) {
    http_response_code(404);
    exit;
}

function send_image($file, $immutable)
{
    header('Content-Type: image/webp');
    header('Cache-Control: public, ' . ($immutable ? 'max-age=31536000, immutable' : 'max-age=86400'));
    header('Content-Length: ' . filesize($file));
    readfile($file);
    exit;
}

$ver = photo_version($key);
$asked = (string) ($_GET['v'] ?? '');
$file = photo_file($key, $ver, $w);
if (is_file($file)) {
    send_image($file, $asked === $ver);
}

// The original: straight from the website folder on the same hosting account, else download it.
$data = false;
$local = photo_original($key);
if ($local) {
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
    // No resizing available on this server: pass the original through.
    header('Content-Type: image/webp');
    header('Cache-Control: public, max-age=86400');
    echo $data;
    exit;
}
$sw = imagesx($src);
$sh = imagesy($src);
$side = min($sw, $sh);                         // square crop from the centre, like the tiles
$dst = imagecreatetruecolor($w, $w);
imagecopyresampled($dst, $src, 0, 0, (int) (($sw - $side) / 2), (int) (($sh - $side) / 2), $w, $w, $side, $side);

$dir = dirname($file);
if (!is_dir($dir)) {
    @mkdir($dir, 0755, true);
}
$tmp = $file . '.' . getmypid() . '.tmp';
if (@imagewebp($dst, $tmp, 78) && @rename($tmp, $file)) {
    // older copies of this photo (a previous version) are not needed any more
    foreach ((array) glob($dir . '/' . photo_slug($key) . '-*-' . $w . '.webp') as $old) {
        if ($old !== $file) {
            @unlink($old);
        }
    }
    send_image($file, $asked === $ver);
}
@unlink($tmp);
header('Content-Type: image/webp');
header('Cache-Control: public, max-age=86400');
imagewebp($dst, null, 78);
