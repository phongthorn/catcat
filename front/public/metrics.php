<?php
// Admin-only JSON endpoint for the monitor tab.
// Proxies Rust /api/metrics (CPU/RAM/network) and adds DB data
// (active sessions, expiring leases, device counts).
require_once __DIR__ . '/../../back/lib/auth.php';
header('Content-Type: application/json');

$user = current_user();
if (!$user || $user['role'] !== 'admin') {
    http_response_code(403); echo '{"error":"forbidden"}'; exit;
}

// ── Rust system metrics ──────────────────────────────────────────────────────
$cfg    = config();
$rust   = rtrim($cfg['rust_http'], '/');
$secret = getenv('CATCAT_WS_SECRET') ?: '';
$ctx    = stream_context_create(['http' => [
    'header'  => "X-Catcat-Auth: {$secret}\r\n",
    'timeout' => 3,
]]);
$raw  = @file_get_contents($rust . '/api/metrics', false, $ctx);
$sys  = $raw ? (json_decode($raw, true) ?? []) : [];

// ── DB data ──────────────────────────────────────────────────────────────────
$pdo = db();

// All recent sessions — filtered below by Rust active_session_ids
$sessions = $pdo->query(
    'SELECT s.session_id, s.serial, s.created_at, u.username, d.label AS device_label
       FROM sessions s
       JOIN users u ON u.id = s.user_id
       LEFT JOIN devices d ON d.serial = s.serial
      WHERE s.created_at >= DATE_SUB(NOW(), INTERVAL 12 HOUR)
      ORDER BY s.created_at DESC'
)->fetchAll();

// Leases expiring within 48 h
$expiring = $pdo->query(
    'SELECT l.expires_at, u.username, d.serial, d.label,
            TIMESTAMPDIFF(MINUTE, NOW(), l.expires_at) AS mins_left
       FROM leases l
       JOIN users u ON u.id = l.user_id
       JOIN devices d ON d.serial = l.serial
      WHERE l.expires_at IS NOT NULL
        AND l.expires_at > NOW()
        AND l.expires_at < DATE_ADD(NOW(), INTERVAL 48 HOUR)
      ORDER BY l.expires_at ASC'
)->fetchAll();

// Device counts
$totalDevices = (int) $pdo->query('SELECT COUNT(*) FROM devices')->fetchColumn();
try {
    require_once __DIR__ . '/../../back/lib/adb.php';
    $online = adb_list_devices();
    $onlineCount = count(array_filter($online, fn($s) => $s === 'device'));
} catch (Throwable) {
    $onlineCount = null; // ADB unavailable
}

// ── Active session IDs: Rust API first, log file as fallback ────────────────
$activeIds = $sys['active_session_ids'] ?? null;

if ($activeIds === null) {
    // Rust API unreachable — parse catcat.log to derive connected sessions
    // Logic: session is active if "WebSocket connected" exists without a
    // corresponding "WebSocket disconnected" for the same session ID.
    $logFile = __DIR__ . '/../../server/catcat.log';
    if (is_readable($logFile)) {
        $connected    = [];
        $disconnected = [];
        // Read only the last 5000 lines to avoid huge file scans
        $lines = array_slice(file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), -5000);
        foreach ($lines as $line) {
            if (preg_match('/WebSocket connected for session ([0-9a-f-]{36})/i', $line, $m)) {
                $connected[$m[1]] = true;
            } elseif (preg_match('/WebSocket disconnected for session ([0-9a-f-]{36})/i', $line, $m)) {
                $disconnected[$m[1]] = true;
            }
        }
        $activeIds = array_values(array_keys(array_diff_key($connected, $disconnected)));
    }
}

$activeSessions = $activeIds !== null
    ? array_filter($sessions, fn($s) => in_array($s['session_id'], $activeIds, true))
    : $sessions; // last resort: show recent DB sessions

echo json_encode([
    'cpu_percent'    => $sys['cpu_percent']    ?? null,
    'ram_used_mb'    => $sys['ram_used_mb']    ?? null,
    'ram_total_mb'   => $sys['ram_total_mb']   ?? null,
    'network'        => $sys['network']        ?? [],
    'active_sessions'=> count($activeSessions),
    'sessions'       => array_map(fn($s) => [
        'username'     => $s['username'],
        'serial'       => $s['serial'],
        'device_label' => $s['device_label'] ?: $s['serial'],
        'age_s'        => time() - strtotime($s['created_at']),
    ], array_values($activeSessions)),
    'expiring_leases'=> array_map(fn($l) => [
        'username'  => $l['username'],
        'serial'    => $l['serial'],
        'label'     => $l['label'] ?: $l['serial'],
        'expires_at'=> $l['expires_at'],
        'mins_left' => (int) $l['mins_left'],
    ], $expiring),
    'devices_total'  => $totalDevices,
    'devices_online' => $onlineCount,
]);
