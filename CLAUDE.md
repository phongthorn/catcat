# CLAUDE.md

Guidance for Claude Code when working in this repository.

## Commands

The repo is split by role:
- `front/` — customer-facing PHP portal (`front/public/*.php`).
- `back/` — backend code shared by the portal + admin dashboard (`back/lib/`, `back/config.php`, `back/db/`, `back/public/` for admin Phase 2).
- `server/` — the Rust/Axum scrcpy server (`server/src/`, `server/static/`) and ADB management.
- `docker/` — nginx + php-fpm + mysql compose stack.

```powershell
# Build / run the Rust server (from server/)
cd server
cargo build
cargo run    # serves on http://0.0.0.0:8080

# Quick check without building
cargo check

# Shortcut scripts from repo root (after setup)
.\start.ps1   # stop old + start fresh (cd server + run)
.\stop.ps1    # stop server
```

The server reads `static/index.html` from the working directory at runtime — always run it from `server/` (the `start.ps1` script does this).

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
function serverstart { Stop-Process -Name "catcat" -Force -ErrorAction SilentlyContinue; Set-Location D:\app\catcat\server; .\target\debug\catcat.exe }
function serverstop  { Stop-Process -Name "catcat" -Force -ErrorAction SilentlyContinue; Write-Host "Server stopped" }
```

### 4. First run

```powershell
cargo build
cargo run
# Open http://localhost:8080 in Chrome
```

---

## Architecture

Catcat is a Rust/Axum web server that streams an Android device's screen to a browser via WebSocket, using the scrcpy protocol over ADB.

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

---

## Coding Behavior Guidelines (Karpathy)

Behavioral guidelines to reduce common LLM coding mistakes.

**Tradeoff:** These guidelines bias toward caution over speed. For trivial tasks, use judgment.

### 1. Think Before Coding

**Don't assume. Don't hide confusion. Surface tradeoffs.**

Before implementing:
- State your assumptions explicitly. If uncertain, ask.
- If multiple interpretations exist, present them - don't pick silently.
- If a simpler approach exists, say so. Push back when warranted.
- If something is unclear, stop. Name what's confusing. Ask.

### 2. Simplicity First

**Minimum code that solves the problem. Nothing speculative.**

- No features beyond what was asked.
- No abstractions for single-use code.
- No "flexibility" or "configurability" that wasn't requested.
- No error handling for impossible scenarios.
- If you write 200 lines and it could be 50, rewrite it.

Ask yourself: "Would a senior engineer say this is overcomplicated?" If yes, simplify.

### 3. Surgical Changes

**Touch only what you must. Clean up only your own mess.**

When editing existing code:
- Don't "improve" adjacent code, comments, or formatting.
- Don't refactor things that aren't broken.
- Match existing style, even if you'd do it differently.
- If you notice unrelated dead code, mention it - don't delete it.

When your changes create orphans:
- Remove imports/variables/functions that YOUR changes made unused.
- Don't remove pre-existing dead code unless asked.

The test: Every changed line should trace directly to the user's request.

### 4. Goal-Driven Execution

**Define success criteria. Loop until verified.**

Transform tasks into verifiable goals:
- "Add validation" → "Write tests for invalid inputs, then make them pass"
- "Fix the bug" → "Write a test that reproduces it, then make it pass"
- "Refactor X" → "Ensure tests pass before and after"

For multi-step tasks, state a brief plan:
```
1. [Step] → verify: [check]
2. [Step] → verify: [check]
3. [Step] → verify: [check]
```

Strong success criteria let you loop independently. Weak criteria ("make it work") require constant clarification.
