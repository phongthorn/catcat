<?php
// Raw adb-protocol-over-TCP client. No adb binary needed (sidesteps the
// client/server version-kill problem). Proven against real devices in Phase 0.
//
// Protocol: connect TCP -> send {:04x}{cmd} -> read 4-byte OKAY/FAIL status.

function adb_connect() {
    $cfg = config();
    $sock = @fsockopen($cfg['adb_host'], $cfg['adb_port'], $errno, $errstr, 5);
    if (!$sock) throw new RuntimeException("adb connect failed: $errstr ($errno)");
    stream_set_timeout($sock, 10);
    return $sock;
}

function adb_send($sock, string $cmd): void {
    $payload = sprintf("%04x%s", strlen($cmd), $cmd);
    fwrite($sock, $payload);
}

function adb_status($sock): string {
    return (string) fread($sock, 4);
}

// Capture a full-resolution PNG screenshot for one device serial.
// Returns raw PNG bytes, or throws on failure.
function adb_screencap(string $serial): string {
    $sock = adb_connect();
    try {
        adb_send($sock, "host:transport:$serial");
        if (adb_status($sock) !== 'OKAY') throw new RuntimeException("transport rejected for $serial");
        adb_send($sock, "exec:screencap -p");
        if (adb_status($sock) !== 'OKAY') throw new RuntimeException("screencap rejected for $serial");
        $png = '';
        while (!feof($sock)) {
            $chunk = fread($sock, 65536);
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($sock);
                if ($meta['timed_out']) throw new RuntimeException("screencap read timed out for $serial");
                break;
            }
            $png .= $chunk;
        }
        return $png;
    } finally {
        fclose($sock);
    }
}

// Inject an Android navigation keyevent (BACK/HOME/APP_SWITCH) into a device.
// $code MUST be a trusted integer keycode — callers whitelist it; never pass
// raw user input, since exec: runs through the device's sh -c.
function adb_keyevent(string $serial, int $code): void {
    $sock = adb_connect();
    try {
        adb_send($sock, "host:transport:$serial");
        if (adb_status($sock) !== 'OKAY') throw new RuntimeException("transport rejected for $serial");
        adb_send($sock, "exec:input keyevent $code");
        if (adb_status($sock) !== 'OKAY') throw new RuntimeException("keyevent rejected for $serial");
    } finally {
        fclose($sock);
    }
}

// Live device serials reported by the host adb server (host:devices).
// Format: "<status>OKAY<4-hex-len><payload>"; payload lines are "serial\tstate".
function adb_list_devices(): array {
    $sock = adb_connect();
    try {
        adb_send($sock, "host:devices");
        if (adb_status($sock) !== 'OKAY') throw new RuntimeException("host:devices rejected");
        $lenHex = fread($sock, 4);
        $len = hexdec($lenHex);
        $body = $len > 0 ? fread($sock, $len) : '';
        $out = [];
        foreach (explode("\n", trim($body)) as $line) {
            if ($line === '') continue;
            [$serial, $state] = array_pad(explode("\t", $line), 2, '');
            $out[$serial] = $state;
        }
        return $out;
    } finally {
        fclose($sock);
    }
}
