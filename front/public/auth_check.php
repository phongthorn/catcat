<?php
// nginx auth_request endpoint. Called as a subrequest before proxying /ws.
// nginx passes the original URI in X-Original-URI. We extract the session_id,
// then confirm the logged-in user owns that session. 204 = allow, 403 = deny.
require_once __DIR__ . '/../../back/lib/auth.php';

$user = current_user();
if (!$user) { http_response_code(403); exit; }

$uri = $_SERVER['HTTP_X_ORIGINAL_URI'] ?? '';
if (!preg_match('#/ws/([A-Za-z0-9-]+)#', $uri, $m)) { http_response_code(403); exit; }
$sid = $m[1];

$st = db()->prepare('SELECT 1 FROM sessions WHERE session_id = ? AND user_id = ? LIMIT 1');
$st->execute([$sid, $user['id']]);
if (!$st->fetchColumn()) { http_response_code(403); exit; }

http_response_code(204);
