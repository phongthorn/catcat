mod adb;
mod scrcpy;
mod stream;

pub fn find_adb() -> String {
    // 1. ADB_PATH env var
    if let Ok(p) = std::env::var("ADB_PATH") {
        if !p.is_empty() { return p; }
    }
    // 2. adb on PATH
    if let Ok(path) = which::which("adb") {
        return path.to_string_lossy().to_string();
    }
    // 3. tools/ next to the running binary (works for both debug and release builds)
    if let Ok(exe) = std::env::current_exe() {
        let bundled = exe.parent().unwrap_or(std::path::Path::new("."))
            .join("tools")
            .join("adb.exe");
        if bundled.exists() {
            return bundled.to_string_lossy().to_string();
        }
    }
    // 4. xiaowei bundled adb (legacy fallback)
    "C:\\Program Files (x86)\\xiaowei_android\\tools\\adb.exe".to_string()
}

use std::sync::Arc;
use std::collections::HashMap;
use std::time::Instant;
use axum::{
    Router,
    routing::get,
    extract::{State, WebSocketUpgrade, Path, Request},
    extract::ws::{WebSocket, Message},
    response::{IntoResponse, Response},
    middleware::{self, Next},
    http::StatusCode,
    Json,
};
use tracing::{info, warn};
use dashmap::DashMap;
use uuid::Uuid;

use adb::AdbClient;
use scrcpy::ScrcpySession;

#[derive(Clone)]
pub struct AppState {
    pub adb: Arc<AdbClient>,
    pub sessions: Arc<DashMap<String, Arc<ScrcpySession>>>,
    pub ws_secret: Arc<String>,
    // sysinfo System kept alive so CPU delta is meaningful across calls
    pub sys: Arc<tokio::sync::Mutex<sysinfo::System>>,
    // Previous network sample: interface → (total_rx, total_tx, sampled_at)
    pub net_prev: Arc<tokio::sync::Mutex<HashMap<String, (u64, u64, Instant)>>>,
}

// Reject any request whose `X-Catcat-Auth` header doesn't match the shared
// secret. This stops a LAN peer from reaching the headless server directly on
// 0.0.0.0:8080 and bypassing the nginx ownership gate. Skipped when no secret
// is configured so local dev keeps working without setup.
async fn require_secret(State(state): State<AppState>, req: Request, next: Next) -> Response {
    let expected = state.ws_secret.as_str();
    if !expected.is_empty() {
        let got = req.headers()
            .get("x-catcat-auth")
            .and_then(|v| v.to_str().ok())
            .unwrap_or("");
        if got != expected {
            return (StatusCode::FORBIDDEN, "forbidden").into_response();
        }
    }
    next.run(req).await
}

#[tokio::main]
async fn main() -> anyhow::Result<()> {
    // Load .env (e.g. ADB_PATH) before anything reads the environment.
    // Missing file is fine — vars may come from the real environment instead.
    dotenvy::dotenv().ok();

    tracing_subscriber::fmt()
        .with_max_level(tracing::Level::INFO)
        .init();

    let adb = Arc::new(AdbClient::new("127.0.0.1:5037"));
    let devices = adb.list_devices().await?;
    info!("Found {} devices", devices.len());
    for d in &devices {
        info!("  Device: {}", d);
    }

    let ws_secret = std::env::var("CATCAT_WS_SECRET").unwrap_or_default();
    if ws_secret.is_empty() {
        warn!("CATCAT_WS_SECRET is unset — X-Catcat-Auth check DISABLED (dev mode). \
               Set it (matching docker/.env) before exposing this host on a network.");
    }

    let mut sys_init = sysinfo::System::new_all();
    sys_init.refresh_all(); // prime CPU baseline so first metrics call is meaningful

    let state = AppState {
        adb,
        sessions: Arc::new(DashMap::new()),
        ws_secret: Arc::new(ws_secret),
        sys: Arc::new(tokio::sync::Mutex::new(sys_init)),
        net_prev: Arc::new(tokio::sync::Mutex::new(HashMap::new())),
    };

    // Frontend (login, device grid, focus/fullscreen) is served by the PHP
    // portal via nginx; nginx proxies only /api and /ws here. This server is
    // headless: session API + video/touch WebSocket. Every route is gated by
    // the shared-secret header injected by nginx (/ws) and PHP (/api).
    let app = Router::new()
        .route("/api/devices", get(list_devices))
        .route("/api/session/:serial", get(create_session))
        .route("/api/metrics", get(get_metrics))
        .route("/ws/:session_id", get(ws_handler))
        .layer(middleware::from_fn_with_state(state.clone(), require_secret))
        .with_state(state);

    let addr = "0.0.0.0:8080";
    info!("Server running on http://{}", addr);
    let listener = tokio::net::TcpListener::bind(addr).await?;
    axum::serve(listener, app).await?;
    Ok(())
}

async fn get_metrics(State(state): State<AppState>) -> impl IntoResponse {
    use sysinfo::{Networks, CpuRefreshKind, RefreshKind, MemoryRefreshKind};

    // Refresh CPU + RAM
    let (cpu, ram_used_mb, ram_total_mb) = {
        let mut sys = state.sys.lock().await;
        sys.refresh_specifics(
            RefreshKind::new()
                .with_cpu(CpuRefreshKind::new().with_cpu_usage())
                .with_memory(MemoryRefreshKind::new().with_ram()),
        );
        let cpu = (sys.global_cpu_usage() * 10.0).round() / 10.0;
        let ram_used  = sys.used_memory()  / 1_048_576;
        let ram_total = sys.total_memory() / 1_048_576;
        (cpu, ram_used, ram_total)
    };

    // Network bandwidth: delta since last call
    let networks = Networks::new_with_refreshed_list();
    let now = Instant::now();
    let mut net_prev = state.net_prev.lock().await;
    let mut net_out = serde_json::Map::new();
    for (name, data) in &networks {
        let rx = data.total_received();
        let tx = data.total_transmitted();
        let (rx_bps, tx_bps) = match net_prev.get(name.as_str()) {
            Some((pr, pt, pi)) => {
                let secs = now.duration_since(*pi).as_secs_f64().max(0.1);
                ((rx.saturating_sub(*pr) as f64 / secs) as u64,
                 (tx.saturating_sub(*pt) as f64 / secs) as u64)
            }
            None => (0, 0),
        };
        net_prev.insert(name.to_string(), (rx, tx, now));
        net_out.insert(name.to_string(), serde_json::json!({
            "rx_bps": rx_bps,
            "tx_bps": tx_bps,
        }));
    }

    let active_ids: Vec<String> = state.sessions.iter()
        .map(|e| e.key().clone())
        .collect();

    Json(serde_json::json!({
        "cpu_percent":   cpu,
        "ram_used_mb":   ram_used_mb,
        "ram_total_mb":  ram_total_mb,
        "network":       net_out,
        "active_sessions": active_ids.len(),
        "active_session_ids": active_ids,
    }))
}

async fn list_devices(State(state): State<AppState>) -> impl IntoResponse {
    match state.adb.list_devices().await {
        Ok(devices) => Json(serde_json::json!({ "devices": devices })),
        Err(e) => Json(serde_json::json!({ "error": e.to_string() })),
    }
}

#[derive(serde::Deserialize)]
struct SessionParams {
    // Requested longest-side resolution (px). Clamped to [480, 1440].
    // Defaults to 1080 if omitted or out of range.
    #[serde(default)]
    max_size: u32,
}

fn clamp_size(requested: u32) -> u32 {
    match requested {
        s if s <= 600  => 480,
        s if s <= 900  => 720,
        _              => 1080,
    }
}

fn bitrate_for_size(max_size: u32) -> u32 {
    match max_size {
        0..=480  =>  5_000_000,
        0..=720  => 10_000_000,
        _        => 15_000_000,
    }
}

pub fn fps_for_size(max_size: u32) -> u32 {
    match max_size {
        0..=480 => 60,
        0..=720 => 30,
        _       => 15,
    }
}

async fn create_session(
    State(state): State<AppState>,
    Path(serial): Path<String>,
    axum::extract::Query(params): axum::extract::Query<SessionParams>,
) -> impl IntoResponse {
    let max_size = clamp_size(params.max_size);
    let bitrate  = bitrate_for_size(max_size);
    let fps      = fps_for_size(max_size);
    let session_id = Uuid::new_v4().to_string();
    let session = Arc::new(ScrcpySession::new(serial.clone(), max_size, bitrate, fps, "h265".into()));
    state.sessions.insert(session_id.clone(), session);
    info!("Created session {} for device {} (max_size={} bitrate={})", session_id, serial, max_size, bitrate);
    Json(serde_json::json!({
        "session_id": session_id,
        "serial": serial,
        "max_size": max_size,
    }))
}

async fn ws_handler(
    ws: WebSocketUpgrade,
    State(state): State<AppState>,
    Path(session_id): Path<String>,
    req: Request,
) -> impl IntoResponse {
    let allowed_raw = std::env::var("CATCAT_ALLOWED_ORIGIN")
        .unwrap_or_else(|_| "https://localhost".to_string());
    let allowed: Vec<&str> = allowed_raw.split(',').map(str::trim).collect();

    let origin = req.headers()
        .get(axum::http::header::ORIGIN)
        .and_then(|v| v.to_str().ok())
        .unwrap_or("");

    if !allowed.contains(&origin) {
        warn!("WebSocket blocked — Origin {:?} not in allowed list {:?}", origin, allowed);
        return (StatusCode::FORBIDDEN, "forbidden").into_response();
    }

    ws.on_upgrade(move |socket| handle_ws(socket, state, session_id))
        .into_response()
}

async fn handle_ws(mut socket: WebSocket, state: AppState, session_id: String) {
    let Some(session) = state.sessions.get(&session_id) else {
        info!("Session {} not found", session_id);
        return;
    };
    let session = session.clone();

    info!("WebSocket connected for session {}", session_id);

    // Start scrcpy, retry up to 3 times (handles fold/unfold crash)
    let mut rx = None;
    for attempt in 1..=3 {
        match session.start().await {
            Ok(r) => { rx = Some(r); break; }
            Err(e) => {
                info!("scrcpy start attempt {}/3 failed: {}", attempt, e);
                if attempt < 3 {
                    tokio::time::sleep(tokio::time::Duration::from_secs(2)).await;
                }
            }
        }
    }
    let mut rx = match rx {
        Some(r) => r,
        None => {
            info!("Failed to start scrcpy after 3 attempts, closing WebSocket");
            return;
        }
    };

    loop {
        tokio::select! {
            // Video frame from scrcpy → send to browser.
            // First frame is always a JSON meta message (starts with '{').
            frame = rx.recv() => {
                match frame {
                    Some(data) => {
                        let msg = if data.first() == Some(&b'{') {
                            Message::Text(String::from_utf8_lossy(&data).into_owned().into())
                        } else {
                            Message::Binary(data.into())
                        };
                        if socket.send(msg).await.is_err() {
                            break;
                        }
                    }
                    None => {
                        // scrcpy died (e.g. fold event) — retry once
                        info!("scrcpy stream ended, retrying...");
                        session.stop().await;
                        tokio::time::sleep(tokio::time::Duration::from_secs(2)).await;
                        match session.start().await {
                            Ok(new_rx) => { rx = new_rx; continue; }
                            Err(e) => { info!("Retry failed: {}", e); break; }
                        }
                    }
                }
            }
            // Input from browser → send to device
            msg = socket.recv() => {
                match msg {
                    Some(Ok(Message::Text(text))) => {
                        if let Err(e) = session.send_input(&text).await {
                            info!("Input error: {}", e);
                        }
                    }
                    Some(Ok(Message::Binary(data))) => {
                        // Only accept INJECT_TOUCH_EVENT (0x02), exactly 32 bytes.
                        // Validate x/y are within screen bounds before forwarding
                        // so the client cannot smuggle other scrcpy opcodes.
                        if data.len() == 32 && data[0] == 0x02 {
                            let x  = u32::from_be_bytes([data[10],data[11],data[12],data[13]]);
                            let y  = u32::from_be_bytes([data[14],data[15],data[16],data[17]]);
                            let sw = u16::from_be_bytes([data[18],data[19]]) as u32;
                            let sh = u16::from_be_bytes([data[20],data[21]]) as u32;
                            if sw > 0 && sh > 0 && x <= sw && y <= sh {
                                session.send_raw_input(data).await;
                            }
                        }
                    }
                    Some(Ok(Message::Close(_))) | None => break,
                    _ => {}
                }
            }
        }
    }

    info!("WebSocket disconnected for session {}", session_id);
    session.stop().await;
}
