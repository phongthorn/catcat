<?php
// Provision a Rust stream session for a device the user owns, and record
// session_id -> user_id so nginx auth_request can authorise the /ws upgrade.
require_once __DIR__ . '/../../back/lib/auth.php';
header('Content-Type: application/json');

$user = require_login();
$serial = $_GET['serial'] ?? '';
if ($serial === '' || ($user['role'] !== 'admin' && !user_owns_serial((int) $user['id'], $serial))) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

$rust = rtrim(config()['rust_http'], '/');
// The headless Rust server gates every route on this shared secret so a LAN
// peer can't reach :8080 directly. Must match CATCAT_WS_SECRET on the Rust host.
$secret = getenv('CATCAT_WS_SECRET') ?: '';
$ctx = stream_context_create(['http' => [
    'header'  => "X-Catcat-Auth: {$secret}\r\n",
    'timeout' => 5,
]]);
// ?size=480|720|1080|1440 — passed to Rust as max_size; Rust clamps to allowed values.
$size = (int)($_GET['size'] ?? 0);
$qs = $size > 0 ? '?max_size=' . $size : '';
$resp = @file_get_contents($rust . '/api/session/' . rawurlencode($serial) . $qs, false, $ctx);
$data = $resp ? json_decode($resp, true) : null;
if (!$data || empty($data['session_id'])) {
    http_response_code(502);
    echo json_encode(['error' => 'rust session failed']);
    exit;
}

$sid = $data['session_id'];
$st = db()->prepare('INSERT INTO sessions (session_id, user_id, serial) VALUES (?, ?, ?)');
$st->execute([$sid, $user['id'], $serial]);
$isAudit = ($user['role'] === 'admin' && !user_owns_serial((int) $user['id'], $serial));
log_activity($isAudit ? 'admin_audit_stream' : 'stream_start', $serial, $sid);

// Browser builds the WebSocket URL same-origin from window.location; we only
// return the session_id (never Rust's hardcoded ws://localhost:8080 ws_url).
echo json_encode(['session_id' => $sid, 'serial' => $serial]);
