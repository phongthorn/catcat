# Catcat Migration — Continue on New Machine (10.10.2.181)

## Status: สิ่งที่ทำไปแล้ว
- repo อยู่ที่ `D:\app\catcat\`
- `catcat.exe` + `server\target\debug\tools\adb.exe` copy มาแล้ว
- cloudflared creds อยู่ที่ `C:\Users\mmc-phong\.cloudflared\2f22f096-0106-41ac-b02f-88ce0eaa94b8.json`
- Docker: mysql + php + cloudflared รันอยู่ — nginx ยังไม่ได้ขึ้น (port 80/443 ชนกับ mmc-proxy ที่เป็น Traefik v3.1)
- DB ยังไม่ได้ import — backup อยู่ที่ `D:\app\catcat\db_backup.sql`
- Rust ไม่ได้ install แต่มี binary แล้ว ไม่ต้อง build

## DB credentials (ของเก่า)
- user: `catcat`  password: `catcat-pw`  db: `catcat`
- root password ใน .env: `change-me-root` (อาจไม่ตรง ให้ใช้ user catcat แทน)

## สิ่งที่ต้องทำต่อ (เรียงตามลำดับ)

### 1. Import DB
```powershell
docker exec -i catcat-mysql mysql -ucatcat -pcatcat-pw catcat < D:\app\catcat\db_backup.sql
```

### 2. ดู Traefik config เพื่อ integrate catcat
```powershell
# หา docker-compose ของ mmc-proxy
docker inspect mmc-proxy --format "{{json .HostConfig.Binds}}"
docker network ls
# ดู network ที่ traefik ใช้
docker inspect mmc-proxy --format "{{json .NetworkSettings.Networks}}"
```

### 3. แก้ docker-compose.yml — เอา nginx ออก ใช้ Traefik แทน
- ไฟล์อยู่ที่ `D:\app\catcat\docker\docker-compose.yml`
- เอา `nginx` service ออก
- เพิ่ม catcat-php เข้า network เดียวกับ traefik
- เพิ่ม Traefik labels บน php service

### 4. Start Rust server
```powershell
cd D:\app\catcat
.\startserver.ps1
```
> startserver.ps1 จะ start docker compose + catcat.exe อัตโนมัติ
> แต่ถ้า nginx ยังอยู่ใน compose จะ error port — ต้องเอาออกก่อน (ข้อ 3)

### 5. ตรวจสอบหลัง setup
```powershell
# Docker containers ทุกตัวต้อง running
docker ps

# Rust server ต้องตอบ
curl http://localhost:8080/api/devices

# Traefik routing ต้อง forward มาถูก
curl http://localhost/api/devices
```

## Context เพิ่มเติม
- เครื่องนี้มี `mmc-proxy` (Traefik v3.1) รัน port 80/443 อยู่แล้ว
- catcat ต้องเข้า network เดียวกับ Traefik จึงจะ route ได้
- Cloudflare tunnel ของ catcat อยู่ใน cloudflared container (UUID: 2f22f096-0106-41ac-b02f-88ce0eaa94b8)
- catcat.exe รันบน port 8080 (host) — nginx/Traefik proxy ไปที่นี่
