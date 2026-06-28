use anyhow::{anyhow, Result};
use tokio::io::{AsyncReadExt, AsyncWriteExt};
use tokio::net::TcpStream;
use tracing::info;

pub struct AdbClient {
    addr: String,
}

impl AdbClient {
    pub fn new(addr: &str) -> Self {
        Self { addr: addr.to_string() }
    }

    async fn connect(&self) -> Result<TcpStream> {
        Ok(TcpStream::connect(&self.addr).await?)
    }

    async fn send_request(&self, stream: &mut TcpStream, cmd: &str) -> Result<()> {
        let msg = format!("{:04x}{}", cmd.len(), cmd);
        stream.write_all(msg.as_bytes()).await?;
        Ok(())
    }

    async fn read_response(&self, stream: &mut TcpStream) -> Result<String> {
        let mut status = [0u8; 4];
        stream.read_exact(&mut status).await?;
        let status = std::str::from_utf8(&status)?;
        if status != "OKAY" {
            let mut len_buf = [0u8; 4];
            stream.read_exact(&mut len_buf).await?;
            let len = usize::from_str_radix(std::str::from_utf8(&len_buf)?, 16)?;
            let mut err = vec![0u8; len];
            stream.read_exact(&mut err).await?;
            return Err(anyhow!("ADB error: {}", String::from_utf8_lossy(&err)));
        }
        Ok("OKAY".to_string())
    }

    async fn read_length_prefixed(&self, stream: &mut TcpStream) -> Result<String> {
        let mut len_buf = [0u8; 4];
        stream.read_exact(&mut len_buf).await?;
        let len = usize::from_str_radix(std::str::from_utf8(&len_buf)?, 16)?;
        let mut data = vec![0u8; len];
        stream.read_exact(&mut data).await?;
        Ok(String::from_utf8_lossy(&data).to_string())
    }

    pub async fn list_devices(&self) -> Result<Vec<String>> {
        let mut stream = self.connect().await?;
        self.send_request(&mut stream, "host:devices").await?;
        self.read_response(&mut stream).await?;
        let data = self.read_length_prefixed(&mut stream).await?;
        let devices = data
            .lines()
            .filter(|l| l.contains('\t'))
            .map(|l| l.split('\t').next().unwrap_or("").to_string())
            .filter(|s| !s.is_empty())
            .collect();
        Ok(devices)
    }

    // Open a transport to a specific device and run a shell command
    // Returns the raw output stream as a TcpStream (caller manages it)
    pub async fn open_shell(&self, serial: &str, cmd: &str) -> Result<TcpStream> {
        let mut stream = self.connect().await?;
        let transport = format!("host:transport:{}", serial);
        self.send_request(&mut stream, &transport).await?;
        self.read_response(&mut stream).await?;
        let shell_cmd = format!("shell:{}", cmd);
        self.send_request(&mut stream, &shell_cmd).await?;
        self.read_response(&mut stream).await?;
        info!("Opened shell '{}' on {}", cmd, serial);
        Ok(stream)
    }

    // Push a file to device via ADB sync protocol
    pub async fn push_file(&self, serial: &str, local: &[u8], remote: &str) -> Result<()> {
        // Use adb.exe CLI for push (sync protocol is complex to implement raw)
        let adb_path = crate::find_adb();
        let tmp = std::env::temp_dir().join("catcat_push_tmp");
        tokio::fs::write(&tmp, local).await?;
        let output = tokio::process::Command::new(adb_path)
            .args(["-s", serial, "push", tmp.to_str().unwrap(), remote])
            .output()
            .await?;
        if !output.status.success() {
            return Err(anyhow!("push failed: {}", String::from_utf8_lossy(&output.stderr)));
        }
        info!("Pushed file to {}:{}", serial, remote);
        Ok(())
    }

    pub async fn shell_command(&self, serial: &str, cmd: &str) -> Result<String> {
        let adb_path = crate::find_adb();
        let output = tokio::process::Command::new(adb_path)
            .args(["-s", serial, "shell", cmd])
            .output()
            .await?;
        Ok(String::from_utf8_lossy(&output.stdout).to_string())
    }
}
