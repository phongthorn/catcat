# CLAUDE.md

Guidance for Claude Code when working in this repository.

## Commands

```powershell
# Build
cargo build

# Run (serves on http://0.0.0.0:8080)
cargo run

# Quick check without building
cargo check

# Shortcut scripts (after setup)
.\start.ps1   # stop old + start fresh
.\stop.ps1    # stop server
```

The server reads `static/index.html` from the working directory at runtime — always run from the project root.

## Setup on a new machine

### 1. Prerequisites

- **Rust** — https://rustup.rs (stable toolchain)
- **ADB** — one of:
  - Install Android SDK Platform Tools and add to PATH
  - Or set env var: `$env:ADB_PATH = "C:\path\to\adb.exe"`
  - Or install xiaowei_android (legacy path: `C:\Program Files (x86)\xiaowei_android\tools\adb.exe`)
- **ADB daemon running** on `127.0.0.1:5037` before starting the server

`find_adb()` in `src/main.rs` searches in order: `ADB_PATH` env var → PATH → xiaowei fallback.

### 2. Android device requirement

The scrcpy server JAR must already be installed on the device at:
```
/data/local/tmp/XWCaptureScreen.jar
```
This is a scrcpy v2.4 server pre-installed by the xiaowei_android app.  
**If the JAR is missing**, push it manually:
```powershell
adb push XWCaptureScreen.jar /data/local/tmp/
adb shell chmod 755 /data/local/tmp/XWCaptureScreen.jar
```

### 3. PowerShell shortcuts (optional)

Add to PowerShell profile (`$PROFILE`):
```powershell
function serverstart { Stop-Process -Name "panda" -Force -ErrorAction SilentlyContinue; Set-Location D:\app\panda; .\target\debug\panda.exe }
function serverstop  { Stop-Process -Name "panda" -Force -ErrorAction SilentlyContinue; Write-Host "Server stopped" }
```

### 4. First run

```powershell
cargo build
cargo run
# Open http://localhost:8080 in Chrome
```

---

## Architecture

Panda is a Rust/Axum web server that streams an Android device's screen to a browser via WebSocket, using the scrcpy protocol over ADB.

### Data flow

```
Android device
  └─ XWCaptureScreen.jar  (scrcpy v2.4 server, scid=32)
       └─ localabstract:scrcpy_00000032
            └─ adb forward tcp:27183
                 └─ ScrcpySession (src/scrcpy/mod.rs)
                      ├─ video socket → frame parser → mpsc → WebSocket → browser VideoDecoder
                      └─ control socket ← touch binary events ← WebSocket ← browser
```

### Modules

- **`src/main.rs`** — Axum router, `AppState` (`AdbClient` + session `DashMap`), WebSocket upgrade handler, `find_adb()`. Sessions keyed by UUID. WebSocket handler retries scrcpy start up to 3×, and auto-restarts if stream drops mid-session (e.g. device fold/unfold).
- **`src/adb/mod.rs`** — Raw ADB protocol client (TCP to `127.0.0.1:5037`). `{:04x}{cmd}` framing for `host:devices` and shell. Push/shell fall back to `adb.exe` CLI via `find_adb()`.
- **`src/scrcpy/mod.rs`** — `ScrcpySession::start()` flow:
  1. Kill lingering scrcpy processes on device
  2. `adb forward tcp:27183 localabstract:scrcpy_00000032`
  3. Launch `XWCaptureScreen.jar` via `adb shell`
  4. Connect **video socket first**, then **control socket** (server needs both before sending handshake)
  5. Read 1-byte dummy (`0x00`) + 64-byte device name + 12-byte codec/dimensions = 77 bytes total
  6. Parse frame meta headers (8-byte PTS + 4-byte payload size) and send each complete access unit as one WebSocket message
  7. Spawn video-reader task and control-writer task
- **`src/stream/mod.rs`** — Placeholder for Phase 2 (WebRTC).
- **`static/index.html`** — Single-page frontend. Fetches `/api/devices`, opens `/ws/{session_id}`, decodes H.264 via WebCodecs `VideoDecoder`. On keyframe packets, prepends stored SPS+PPS before decoding. Forwards mouse/touch as `{action, x, y}` JSON.

### Key constraints and known behaviours

- **Handshake is 77 bytes** (not 76): 1 dummy byte + 64 device name + 4 codec + 4 width + 4 height. The dummy byte must be consumed before reading device name.
- **Two sockets required before handshake**: scrcpy `tunnel_forward` mode waits for both video and control connections. Connect video first, then control, *then* read the handshake.
- **Frame meta headers**: scrcpy sends an 8-byte PTS + 4-byte size header before every video packet. The Rust reader parses these and sends exactly `size` bytes per WebSocket message (one complete H.264 access unit).
- **SPS/PPS in separate packet**: scrcpy sends SPS+PPS as one WebSocket message, then IDR as the next. The frontend stores SPS+PPS and prepends them to every IDR chunk before calling `decoder.decode()`.
- **scid=32 is fixed**: the abstract socket `scrcpy_00000032` is derived from it.
- **Touch events are 32 bytes** (scrcpy v2 format): `type(1) + action(1) + pointerId(8) + x(4) + y(4) + screenW(2) + screenH(2) + pressure(2) + actionButton(4) + buttons(4)`.
- **Device dimensions**: handshake reports 720×336 (landscape) for SM-G990E. Touch coordinates in `send_input` use these values — update if targeting a different device.
- **Fold/unfold crash**: `XWCaptureScreen.jar` crashes on Samsung fold events (`onDisplayFoldChanged`). The WebSocket handler detects stream drop and auto-restarts scrcpy after 2s.
- **Sessions are single-use**: `stop_rx` oneshot is moved into the video-reader task. Do not call `start()` twice on the same `ScrcpySession`.
- **ADB daemon** must be running on `127.0.0.1:5037` before `cargo run`.
