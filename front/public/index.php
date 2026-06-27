<?php
require_once __DIR__ . '/../../back/lib/auth.php';
$user = current_user();
if ($user && $user['role'] === 'admin') {
    header('Location: /admin_dashboard.php');
} else {
    header('Location: /cloud_dashboard.php');
}
exit;
