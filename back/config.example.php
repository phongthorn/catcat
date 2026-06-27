<?php
// Panda Portal — config template.
// Copy to config.php and fill in real values. config.php is gitignored.
// Values below are written from the perspective of the php-fpm CONTAINER.
return [
    // MySQL connection (service name on the compose network)
    'db' => [
        'host' => 'mysql',
        'port' => 3306,
        'name' => 'panda',
        'user' => 'panda',
        'pass' => 'panda-pw',
    ],

    // Host adb server, reached over raw TCP (no adb binary in the container).
    // Host adb must listen on all interfaces: `adb -a nodaemon server start`.
    'adb_host' => 'host.docker.internal',
    'adb_port' => 5037,

    // Base URL of the UNTOUCHED Rust panda server, from inside the container.
    // Used server-side by PHP to provision sessions (/api/session/:serial).
    // The browser's WebSocket URL is derived client-side from window.location
    // (same-origin via nginx), NOT from Rust's hardcoded ws_url.
    'rust_http' => 'http://host.docker.internal:8080',

    // Max concurrent adb screencap captures (protects the adb daemon).
    'thumb_concurrency' => 6,

    // Grid thumbnail refresh interval (ms) — client-side.
    'thumb_refresh_ms'  => 4000,
];
