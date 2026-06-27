<?php
// Android navigation keyevent endpoint. Sends BACK/HOME/RECENT to a device the
// caller leases, via adb-TCP `input keyevent` — completely separate from the
// Rust control socket (which only carries touch). Frontend-only by design.
require_once __DIR__ . '/../../back/lib/auth.php';
require_once __DIR__ . '/../../back/lib/adb.php';

$user   = require_login();
$serial = $_GET['serial'] ?? '';
if ($serial === '' || !user_owns_serial((int) $user['id'], $serial)) {
    http_response_code(403); exit;
}

// Whitelist: only these three keys map to fixed integer keycodes. The raw key
// string never reaches the shell — only 3 | 4 | 187 can.
$codes = ['back' => 4, 'home' => 3, 'recent' => 187];
$code  = $codes[$_GET['key'] ?? ''] ?? null;
if ($code === null) { http_response_code(400); exit; }

try {
    adb_keyevent($serial, $code);
    http_response_code(204);
} catch (Throwable $e) {
    http_response_code(502);
}
