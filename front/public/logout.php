<?php
require_once __DIR__ . '/../../back/lib/auth.php';
logout();
header('Location: /login.php');
