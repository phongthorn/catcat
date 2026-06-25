use anyhow::{anyhow, Result};
use std::sync::Arc;
use tokio::io::{AsyncReadExt, AsyncWriteExt};
use tokio::net::TcpStream;
use tokio::sync::{mpsc, Mutex};
use tracing::info;
use serde::Deserialize;

use crate::adb::AdbClient;

// Use xiaowei's pre-installed scrcpy server (already on device)
const DEVICE_SERVER_PATH: &str = "/data/local/tmp/XWCaptureScreen.jar";
const SCRCPY_VERSION: &str = "2.4";
const SCRCPY_PORT: u16 = 27183;
const VIDEO_SOCKET: &str = "scrcpy";

#[derive(Deserialize)]
pub struct TouchInput {
    pub action: u8,  // 0=down, 1=up, 2=move
    pub x: f32,      // 0.0-1.0 normalized
    pub y: f32,
}

pub struct ScrcpySession {
    serial: String,
    adb: Arc<AdbClient>,
    control_tx: Mutex<Option<mpsc::Sender<Vec<u8>>>>,
    stop_tx: Mutex<Option<tokio::sync::oneshot::Sender<()>>>,
}

impl ScrcpySession {
    pub fn new(serial: String, adb: Arc<AdbClient>) -> Self {
        Self {
            serial,
            adb,
            control_tx: Mutex::new(None),
            stop_tx: Mutex::new(None),
        }
    }

    pub async fn start(&self) -> Result<mpsc::Receiver<Vec<u8>>> {
        let serial = &self.serial;
        info!("Starting scrcpy session for {}", serial);

        let adb_path = crate::find_adb();

        // Kill any lingering scrcpy server on the device before starting fresh
        let _ = tokio::process::Command::new(&adb_path)
            .args(["-s", serial, "shell", "pkill -f 'app_process.*scrcpy\\|XWCaptureScreen' 2>/dev/null; true"])
            .output().await;
        tokio::time::sleep(tokio::time::Duration::from_millis(500)).await;

        // Forward TCP port — scid=32 creates socket @scrcpy_00000032
        tokio::process::Command::new(&adb_path)
            .args(["-s", serial, "forward",
                   &format!("tcp:{}", SCRCPY_PORT),
                   "localabstract:scrcpy_00000032"])
            .output().await?;
        info!("Port forwarded tcp:{} -> localabstract:{}", SCRCPY_PORT, VIDEO_SOCKET);

        // Start scrcpy server — exact args xiaowei uses (reverse-engineered from logcat)
        let server_cmd = format!(
            "CLASSPATH={} app_process / com.genymobile.scrcpy.Server {} log_level=INFO scid=32 audio=false video_encoder=OMX.google.h264.encoder max_size=720 max_fps=60 video_bit_rate=4000000 tunnel_forward=true cleanup=false clipboard_autosync=false stay_awake=true",
            DEVICE_SERVER_PATH, SCRCPY_VERSION
        );
        let adb_path2 = adb_path.clone();
        let serial2 = serial.clone();
        tokio::spawn(async move {
            let _ = tokio::process::Command::new(&adb_path2)
                .args(["-s", &serial2, "shell", &server_cmd])
                .spawn();
        });

        // Wait for server to start listening
        tokio::time::sleep(tokio::time::Duration::from_millis(1500)).await;

        // Connect video socket first, then control socket.
        // scrcpy tunnel_forward mode waits for BOTH connections before sending the handshake,
        // so we must open the control socket before reading the video handshake.
        let mut video_stream = Self::connect_with_retry(SCRCPY_PORT, 5).await?;
        info!("Connected to scrcpy video socket");

        // Connect control socket before reading handshake (server needs both before it proceeds)
        let mut control_stream = Self::connect_with_retry(SCRCPY_PORT, 3).await?;
        info!("Connected to scrcpy control socket");

        // scrcpy v2 tunnel_forward handshake on video socket:
        // 1 dummy byte + device_name(64) + codec_id(4) + width(4) + height(4) = 77 bytes total
        // The dummy byte (0x00) signals that the abstract socket forward is alive.

        // Consume the 1-byte dummy
        let mut dummy = [0u8; 1];
        video_stream.read_exact(&mut dummy).await?;

        // Read device name (64 bytes)
        let mut device_name = [0u8; 64];
        video_stream.read_exact(&mut device_name).await?;
        let name = String::from_utf8_lossy(&device_name).trim_end_matches('\0').to_string();

        // Read codec_id(4) + width(4) + height(4)
        let mut meta = [0u8; 12];
        video_stream.read_exact(&mut meta).await?;
        let codec  = u32::from_be_bytes([meta[0], meta[1], meta[2], meta[3]]);
        let width  = u32::from_be_bytes([meta[4], meta[5], meta[6], meta[7]]);
        let height = u32::from_be_bytes([meta[8], meta[9], meta[10], meta[11]]);
        info!("Device: {} codec=0x{:08x} {}x{}", name, codec, width, height);

        if width == 0 || height == 0 || width > 7680 || height > 7680 {
            return Err(anyhow!("Invalid handshake dimensions {}x{} — scrcpy may have crashed", width, height));
        }

        // Channel: scrcpy video frames → WebSocket
        let (frame_tx, frame_rx) = mpsc::channel::<Vec<u8>>(32);
        // Channel: WebSocket input → scrcpy control
        let (ctrl_tx, mut ctrl_rx) = mpsc::channel::<Vec<u8>>(64);
        // Stop signal
        let (stop_tx, mut stop_rx) = tokio::sync::oneshot::channel::<()>();

        *self.control_tx.lock().await = Some(ctrl_tx);
        *self.stop_tx.lock().await = Some(stop_tx);

        // Spawn video reader task
        // scrcpy frame meta: 8-byte PTS (ignored) + 4-byte packet size, then that many bytes of H.264
        tokio::spawn(async move {
            let mut meta = [0u8; 12];
            loop {
                // Read the 12-byte frame header, honouring stop signal
                let header_result = tokio::select! {
                    _ = &mut stop_rx => break,
                    r = video_stream.read_exact(&mut meta) => r,
                };
                if header_result.is_err() { break; }

                let pkt_size = u32::from_be_bytes([meta[8], meta[9], meta[10], meta[11]]) as usize;
                if pkt_size == 0 || pkt_size > 4 * 1024 * 1024 { break; }

                // Read exactly pkt_size bytes (one complete access unit)
                let mut payload = vec![0u8; pkt_size];
                if video_stream.read_exact(&mut payload).await.is_err() { break; }

                if frame_tx.send(payload).await.is_err() { break; }
            }
            info!("Video reader task ended");
        });

        // Spawn control writer task
        tokio::spawn(async move {
            while let Some(data) = ctrl_rx.recv().await {
                if control_stream.write_all(&data).await.is_err() {
                    break;
                }
            }
            info!("Control writer task ended");
        });

        Ok(frame_rx)
    }

    async fn connect_with_retry(port: u16, retries: u32) -> Result<TcpStream> {
        for i in 0..retries {
            match TcpStream::connect(format!("127.0.0.1:{}", port)).await {
                Ok(s) => return Ok(s),
                Err(e) => {
                    if i + 1 < retries {
                        tokio::time::sleep(tokio::time::Duration::from_millis(500)).await;
                    } else {
                        return Err(anyhow!("Failed to connect after {} retries: {}", retries, e));
                    }
                }
            }
        }
        unreachable!()
    }

    // Send touch input via scrcpy binary control protocol
    pub async fn send_input(&self, json: &str) -> Result<()> {
        let input: TouchInput = serde_json::from_str(json)
            .map_err(|e| anyhow!("Invalid input JSON: {}", e))?;

        // scrcpy v2 inject touch event: 32 bytes
        // [type(1)][action(1)][pointerId(8)][x(4)][y(4)][screenW(2)][screenH(2)][pressure(2)][actionButton(4)][buttons(4)]
        let abs_x = (input.x * 720.0) as u32;
        let abs_y = (input.y * 336.0) as u32;

        let mut msg = Vec::with_capacity(32);
        msg.push(0x02u8);                              // type: INJECT_TOUCH_EVENT
        msg.push(input.action);                        // action: 0=down,1=up,2=move
        msg.extend_from_slice(&0u64.to_be_bytes());    // pointerId
        msg.extend_from_slice(&abs_x.to_be_bytes());   // x (absolute pixels)
        msg.extend_from_slice(&abs_y.to_be_bytes());   // y (absolute pixels)
        msg.extend_from_slice(&720u16.to_be_bytes());  // screenWidth
        msg.extend_from_slice(&336u16.to_be_bytes());  // screenHeight
        msg.extend_from_slice(&0xFFFFu16.to_be_bytes()); // pressure (max)
        msg.extend_from_slice(&0u32.to_be_bytes());    // actionButton
        msg.extend_from_slice(&0u32.to_be_bytes());    // buttons

        if let Some(tx) = self.control_tx.lock().await.as_ref() {
            tx.send(msg).await.ok();
        }
        Ok(())
    }

    pub async fn stop(&self) {
        if let Some(tx) = self.stop_tx.lock().await.take() {
            let _ = tx.send(());
        }
        let adb_path = crate::find_adb();
        // Kill scrcpy server on device so it stops encoding immediately
        let _ = tokio::process::Command::new(&adb_path)
            .args(["-s", &self.serial, "shell", "pkill -f app_process 2>/dev/null; true"])
            .output().await;
        // Remove adb forward
        let _ = tokio::process::Command::new(&adb_path)
            .args(["-s", &self.serial, "forward", "--remove",
                   &format!("tcp:{}", SCRCPY_PORT)])
            .output().await;
        info!("Session stopped for {}", self.serial);
    }
}
