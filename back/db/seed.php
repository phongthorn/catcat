<?php
// One-time seed for the Phase 1 thin slice. Run inside the php container:
//   docker exec catcat-php php /var/www/portal/db/seed.php
require_once __DIR__ . '/../lib/db.php';

$pdo = db();

// Demo accounts (passwords: demo1234 / admin1234 — change after testing).
$users = [
    ['demo',  'demo1234',  'customer'],
    ['admin', 'admin1234', 'admin'],
];
foreach ($users as [$u, $p, $role]) {
    $hash = password_hash($p, PASSWORD_DEFAULT);
    $pdo->prepare(
        'INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)
           ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), role = VALUES(role)'
    )->execute([$u, $hash, $role]);
}

// Real devices proven in Phase 0.
$devices = [
    ['RFCT60M26MY', 'Galaxy A'],
    ['R5CRC3DAKAL', 'Galaxy B'],
];
foreach ($devices as [$serial, $label]) {
    $pdo->prepare(
        'INSERT INTO devices (serial, label, is_rentable) VALUES (?, ?, 1)
           ON DUPLICATE KEY UPDATE label = VALUES(label)'
    )->execute([$serial, $label]);
}

// Give demo a 6-hour lease on the first device so the grid has something to show.
$demoId = (int) $pdo->query("SELECT id FROM users WHERE username='demo'")->fetchColumn();
$pdo->prepare(
    'INSERT INTO leases (user_id, serial, expires_at)
       VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 6 HOUR))
       ON DUPLICATE KEY UPDATE expires_at = DATE_ADD(NOW(), INTERVAL 6 HOUR)'
)->execute([$demoId, 'RFCT60M26MY']);

echo "Seed complete: users(demo/admin), 2 devices, 1 lease.\n";
