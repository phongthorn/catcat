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
    // 3. xiaowei bundled adb
    "C:\\Program Files (x86)\\xiaowei_android\\tools\\adb.exe".to_string()
}

use std::sync::Arc;
use axum::{
    Router,
    routing::get,
    extract::{State, WebSocketUpgrade, Path},
    extract::ws::{WebSocket, Message},
    response::{IntoResponse, Html},
    Json,
};
use tower_http::cors::CorsLayer;
use tracing::info;
use dashmap::DashMap;
use uuid::Uuid;

use adb::AdbClient;
use scrcpy::ScrcpySession;

#[derive(Clone)]
pub struct AppState {
    pub adb: Arc<AdbClient>,
    pub sessions: Arc<DashMap<String, Arc<ScrcpySession>>>,
}

#[tokio::main]
async fn main() -> anyhow::Result<()> {
    tracing_subscriber::fmt()
        .with_max_level(tracing::Level::INFO)
        .init();

    let adb = Arc::new(AdbClient::new("127.0.0.1:5037"));
    let devices = adb.list_devices().await?;
    info!("Found {} devices", devices.len());
    for d in &devices {
        info!("  Device: {}", d);
    }

    let state = AppState {
        adb,
        sessions: Arc::new(DashMap::new()),
    };

    let app = Router::new()
        .route("/", get(serve_index))
        .route("/api/devices", get(list_devices))
        .route("/api/session/:serial", get(create_session))
        .route("/ws/:session_id", get(ws_handler))
        .layer(CorsLayer::permissive())
        .with_state(state);

    let addr = "0.0.0.0:8080";
    info!("Server running on http://{}", addr);
    let listener = tokio::net::TcpListener::bind(addr).await?;
    axum::serve(listener, app).await?;
    Ok(())
}

async fn serve_index() -> Html<String> {
    let html = tokio::fs::read_to_string("static/index.html").await
        .unwrap_or_else(|_| "<h1>static/index.html not found</h1>".to_string());
    Html(html)
}

async fn list_devices(State(state): State<AppState>) -> impl IntoResponse {
    match state.adb.list_devices().await {
        Ok(devices) => Json(serde_json::json!({ "devices": devices })),
        Err(e) => Json(serde_json::json!({ "error": e.to_string() })),
    }
}

async fn create_session(
    State(state): State<AppState>,
    Path(serial): Path<String>,
) -> impl IntoResponse {
    let session_id = Uuid::new_v4().to_string();
    let session = Arc::new(ScrcpySession::new(serial.clone(), state.adb.clone()));
    state.sessions.insert(session_id.clone(), session);
    info!("Created session {} for device {}", session_id, serial);
    Json(serde_json::json!({
        "session_id": session_id,
        "serial": serial,
        "ws_url": format!("ws://localhost:8080/ws/{}", session_id)
    }))
}

async fn ws_handler(
    ws: WebSocketUpgrade,
    State(state): State<AppState>,
    Path(session_id): Path<String>,
) -> impl IntoResponse {
    ws.on_upgrade(move |socket| handle_ws(socket, state, session_id))
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
