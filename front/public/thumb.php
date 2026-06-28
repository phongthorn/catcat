<?php
// Grid thumbnail: capture device screen via raw adb-TCP, downscale + JPEG, cache.
// On-demand with a short file cache is fine for MVP scale (a handful of devices).
// At 100+ devices, move capture into a background worker writing the same cache
// dir and have nginx serve /thumbs/*.jpg as static files (see roadmap).
require_once __DIR__ . '/../../back/lib/auth.php';
require_once __DIR__ . '/../../back/lib/adb.php';

$user = require_login();
$serial = $_GET['serial'] ?? '';
if ($serial === '' || !user_owns_serial((int) $user['id'], $serial)) {
    http_response_code(403);
    exit;
}

$cacheDir = sys_get_temp_dir() . '/catcat-thumbs';
@mkdir($cacheDir, 0777, true);
$cacheFile = $cacheDir . '/' . preg_replace('/[^A-Za-z0-9_-]/', '_', $serial) . '.jpg';
$maxAgeMs  = (int) config()['thumb_refresh_ms'];

// Serve fresh cache without touching adb.
if (is_file($cacheFile) && (microtime(true) - filemtime($cacheFile)) * 1000 < $maxAgeMs) {
    serve_jpeg(file_get_contents($cacheFile));
}

try {
    $png = adb_screencap($serial);
    $src = imagecreatefromstring($png);
    if (!$src) throw new RuntimeException('decode failed');
    // Card thumbnails are always portrait: rotate landscape captures upright.
    if (imagesx($src) > imagesy($src)) {
        $rot = imagerotate($src, -90, 0);
        imagedestroy($src);
        $src = $rot;
    }
    $w = imagesx($src); $h = imagesy($src);
    $tw = 360; $th = (int) round($h * $tw / $w);
    $thumb = imagecreatetruecolor($tw, $th);
    imagecopyresampled($thumb, $src, 0, 0, 0, 0, $tw, $th, $w, $h);
    ob_start();
    imagejpeg($thumb, null, 55);
    $jpeg = ob_get_clean();
    imagedestroy($src); imagedestroy($thumb);
    file_put_contents($cacheFile, $jpeg, LOCK_EX);
    serve_jpeg($jpeg);
} catch (Throwable $e) {
    // Fall back to stale cache if a live capture fails.
    if (is_file($cacheFile)) serve_jpeg(file_get_contents($cacheFile));
    http_response_code(503);
    exit;
}

function serve_jpeg(string $bytes): void {
    header('Content-Type: image/jpeg');
    header('Cache-Control: no-store');
    echo $bytes;
    exit;
}
