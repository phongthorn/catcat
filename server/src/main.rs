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
    // Shared secret the nginx proxy / PHP layer must present in the
    // `X-Panda-Auth` header. Empty string disables the check (dev only).
    pub ws_secret: Arc<String>,
}

// Reject any request whose `X-Panda-Auth` header doesn't match the shared
// secret. This stops a LAN peer from reaching the headless server directly on
// 0.0.0.0:8080 and bypassing the nginx ownership gate. Skipped when no secret
// is configured so local dev keeps working without setup.
async fn require_secret(State(state): State<AppState>, req: Request, next: Next) -> Response {
    let expected = state.ws_secret.as_str();
    if !expected.is_empty() {
        let got = req.headers()
            .get("x-panda-auth")
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

    let ws_secret = std::env::var("PANDA_WS_SECRET").unwrap_or_default();
    if ws_secret.is_empty() {
        warn!("PANDA_WS_SECRET is unset — X-Panda-Auth check DISABLED (dev mode). \
               Set it (matching docker/.env) before exposing this host on a network.");
    }

    let state = AppState {
        adb,
        sessions: Arc::new(DashMap::new()),
        ws_secret: Arc::new(ws_secret),
    };

    // Frontend (login, device grid, focus/fullscreen) is served by the PHP
    // portal via nginx; nginx proxies only /api and /ws here. This server is
    // headless: session API + video/touch WebSocket. Every route is gated by
    // the shared-secret header injected by nginx (/ws) and PHP (/api).
    let app = Router::new()
        .route("/api/devices", get(list_devices))
        .route("/api/session/:serial", get(create_session))
        .route("/ws/:session_id", get(ws_handler))
        .layer(middleware::from_fn_with_state(state.clone(), require_secret))
        .with_state(state);

    let addr = "0.0.0.0:8080";
    info!("Server running on http://{}", addr);
    let listener = tokio::net::TcpListener::bind(addr).await?;
    axum::serve(listener, app).await?;
    Ok(())
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
        s if s <= 600 => 480,
        _             => 720,   // default and max
    }
}

fn bitrate_for_size(max_size: u32) -> u32 {
    match max_size {
        0..=480 => 3_000_000,
        _       => 5_000_000,
    }
}

async fn create_session(
    State(state): State<AppState>,
    Path(serial): Path<String>,
    axum::extract::Query(params): axum::extract::Query<SessionParams>,
) -> impl IntoResponse {
    let max_size = clamp_size(params.max_size);
    let bitrate  = bitrate_for_size(max_size);
    let session_id = Uuid::new_v4().to_string();
    let session = Arc::new(ScrcpySession::new(serial.clone(), max_size, bitrate));
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
    let allowed = std::env::var("PANDA_ALLOWED_ORIGIN")
        .unwrap_or_else(|_| "https://localhost".to_string());

    let origin = req.headers()
        .get(axum::http::header::ORIGIN)
        .and_then(|v| v.to_str().ok())
        .unwrap_or("");

    if origin != allowed {
        warn!("WebSocket blocked — Origin {:?} not in allowed list", origin);
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
            // Video frame from scrcpy → send to browser
            frame = rx.recv() => {
                match frame {
                    Some(data) => {
                        if socket.send(Message::Binary(data.into())).await.is_err() {
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
                    Some(Ok(Message::Close(_))) | None => break,
                    _ => {}
                }
            }
        }
    }

    info!("WebSocket disconnected for session {}", session_id);
    session.stop().await;
}
