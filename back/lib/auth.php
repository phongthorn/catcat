<?php
// Session-cookie auth helpers. The portal's whole security model rests on this:
// every page and the nginx auth_request endpoint call require_login() / ownership.
require_once __DIR__ . '/db.php';

function start_session(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => true,
        ]);
        session_start();
    }
}

function current_user(): ?array {
    start_session();
    if (empty($_SESSION['uid'])) return null;
    $st = db()->prepare('SELECT id, username, role FROM users WHERE id = ?');
    $st->execute([$_SESSION['uid']]);
    return $st->fetch() ?: null;
}

function require_login(): array {
    $u = current_user();
    if (!$u) { header('Location: /login.php'); exit; }
    return $u;
}

function require_admin(): array {
    $u = require_login();
    if ($u['role'] !== 'admin') { header('Location: /cloud_dashboard.php'); exit; }
    return $u;
}

function login(string $username, string $password): bool {
    start_session();
    $st = db()->prepare('SELECT id, password_hash FROM users WHERE username = ?');
    $st->execute([$username]);
    $row = $st->fetch();
    if (!$row || !password_verify($password, $row['password_hash'])) {
        log_activity('login_fail', null, $username);
        return false;
    }
    session_regenerate_id(true);
    $_SESSION['uid'] = (int) $row['id'];
    log_activity('login', null, null, (int) $row['id'], $username);
    return true;
}

function logout(): void {
    start_session();
    log_activity('logout');
    $_SESSION = [];
    session_destroy();
}

function log_activity(string $action, ?string $serial = null, ?string $detail = null, ?int $userId = null, ?string $username = null): void {
    if ($userId === null || $username === null) {
        $u = current_user();
        if ($u) { $userId = (int) $u['id']; $username = $u['username']; }
    }
    $ip = $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
    if ($ip) $ip = trim(explode(',', $ip)[0]);
    try {
        db()->prepare('INSERT INTO activity_logs (user_id, username, action, serial, detail, ip) VALUES (?,?,?,?,?,?)')
            ->execute([$userId, $username, $action, $serial, $detail, $ip]);
    } catch (Throwable) {}
}

// Does this user currently hold a non-expired lease on this serial?
function user_owns_serial(int $userId, string $serial): bool {
    $st = db()->prepare(
        'SELECT 1 FROM leases WHERE user_id = ? AND serial = ?
           AND (expires_at IS NULL OR expires_at > NOW()) LIMIT 1'
    );
    $st->execute([$userId, $serial]);
    return (bool) $st->fetchColumn();
}
