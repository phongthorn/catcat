# Catcat — Cloud Phone Portal Workflow

## สถาปัตยกรรม (ณ 2026-06-28)

```
Browser (HTTPS/WSS)
  └─ Cloudflare CDN
       └─ nginx (Docker) :443
            ├─ PHP portal (front/public/*.php)  → php-fpm (Docker)
            ├─ /ws/{session_id}  →  auth_request → auth_check.php → proxy Rust :8080
            └─ /ping.php, /session.php, /thumb.php, ...
MySQL (Docker)
  └─ sessions, devices, users tables

Rust/Axum server (Windows host :8080)
  └─ /api/session/:serial  → สร้าง session_id, launch ScrcpySession
  └─ /ws/:session_id       → WebSocket → H.264 frames + touch binary
       └─ ADB forward → XWCaptureScreen.jar (scrcpy v2.4) บน Android

Android device (USB)
  └─ /data/local/tmp/XWCaptureScreen.jar
```

### โฟลเดอร์
| โฟลเดอร์ | บทบาท |
|---------|-------|
| `front/public/` | PHP portal (login, dashboard, focus, thumb) |
| `back/lib/` | shared PHP lib (auth, db, config) |
| `server/src/` | Rust/Axum server |
| `docker/` | nginx + php-fpm + mysql compose stack |

---

## วิธีรันระบบ (Windows host)

```powershell
# 1. เริ่ม Docker stack (nginx + php + mysql)
cd D:\app\catcat\docker
docker compose up -d

# 2. เริ่ม Rust server
cd D:\app\catcat\server
cargo run
# หรือใช้ binary ที่ build แล้ว
.\target\debug\catcat.exe

# ตรวจสอบ Docker
docker ps

# ตรวจสอบ Rust
Get-Process catcat
```

---

## Known Issues & Fixes

| ปัญหา | อาการ | Root Cause | วิธีแก้ | สถานะ |
|-------|-------|-----------|---------|--------|
| WebSocket ไม่ขึ้นภาพ | จอดำ, "กำลังเชื่อมต่อใหม่..." วนไม่จบ, WS close code 1006 | `CATCAT_ALLOWED_ORIGIN` ไม่ได้ set → Rust ใช้ default `"https://localhost"` แล้ว reject WebSocket upgrade เพราะ Origin `"https://littlecat.net"` ไม่ตรง | เพิ่ม `CATCAT_ALLOWED_ORIGIN=https://littlecat.net` ใน `server/.env` แล้ว restart Rust server | ✅ แก้แล้ว (2026-06-28) |
| Cloudflare 502 | เข้าเว็บไม่ได้เลย | Docker containers (nginx/php/mysql) หยุดทำงาน | `docker compose up -d` ใน `docker/` | ✅ แก้แล้ว (2026-06-28) |

---

## Troubleshooting Checklist

### ภาพไม่ขึ้นใน focus.php
1. เปิด DevTools → Network → ดู `/session.php` response
   - ถ้า 502: Docker containers ดาวน์ → `docker compose up -d`
   - ถ้า 200: ได้ `session_id` แล้ว ไปขั้นตอนถัดไป
2. ดู WebSocket ใน Network tab → ถ้า close code 1006 ทันที:
   - ตรวจ `server/.env` ว่ามี `CATCAT_ALLOWED_ORIGIN=https://littlecat.net`
   - ถ้าเพิ่งแก้ `.env`: restart Rust server (`Stop-Process -Name catcat -Force` แล้ว run ใหม่)
3. ถ้า WS connect แต่ไม่มีภาพ:
   - ตรวจ `CATCAT_WS_SECRET` ใน `server/.env` ต้องตรงกับ `docker/.env`
   - ตรวจ ADB daemon รันอยู่ไหม: `adb devices`
   - ตรวจ port forward: `adb forward --list`

### Debug Commands
```powershell
# ตรวจสอบ Docker
docker ps
docker compose logs nginx
docker compose logs php

# ตรวจสอบ Rust server
Get-Process catcat
netstat -ano | findstr ":8080"

# ทดสอบ Rust API โดยตรง (ต้องมี X-Catcat-Auth header)
curl -s -H "X-Catcat-Auth: change-me-ws-secret" http://127.0.0.1:8080/api/session/RFCT60M26MY

# ADB
adb devices
adb forward --list
adb -s RFCT60M26MY shell "ps | grep scrcpy"
```

---

## Environment Variables

### `server/.env` (Rust server, Windows host)
```env
CATCAT_WS_SECRET=<ต้องตรงกับ docker/.env>
CATCAT_ALLOWED_ORIGIN=https://littlecat.net   # ← ขาดอันนี้ทำให้ WS 403
# ADB_PATH=C:\path\to\adb.exe                 # ถ้า adb ไม่อยู่ใน PATH
```

### `docker/.env` (nginx + php containers)
```env
MYSQL_ROOT_PASSWORD=...
MYSQL_DATABASE=catcat
MYSQL_USER=catcat
MYSQL_PASSWORD=...
CATCAT_WS_SECRET=<ต้องตรงกับ server/.env>
```
