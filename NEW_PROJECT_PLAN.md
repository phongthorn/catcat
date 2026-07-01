# Catcat v2 — Architecture & Flow Plan

## Decisions (confirmed)

| คำถาม | คำตอบ |
|---|---|
| Scope | Rewrite ทั้งระบบ (ไม่ patch ทีละจุดบน codebase เดิม) |
| Max concurrent devices | 100 |
| WebRTC | จำเป็น (ไม่ใช่ทางเลือก) |
| Frontend | SPA — SvelteKit |
| Backend API (auth/billing/session) | รวมเข้า Rust เดียวกับ capture server — stack เดียวทั้งระบบ |
| WebRTC library | **webrtc-rs** (pure Rust, ไม่ spawn process แยก, ไม่เพิ่ม runtime ภาษาอื่น) |
| coturn | Public IP/port range พร้อมแล้ว |
| Database schema | ใช้ schema เดิมจาก MySQL ไม่ออกแบบใหม่ |
| ลำดับงาน | ทำ WebRTC (capture + signaling) ก่อน — เสี่ยงสุด ทำก่อนเพื่อพิสูจน์ความเป็นไปได้ |

---

## บริบท (ทำไมต้อง rewrite)

ระบบเดิม (monolith: front PHP + back lib + Rust scrcpy server + nginx + docker) เจอปัญหาจริงระหว่างใช้งาน:

1. **php-fpm worker starvation** — `thumb.php` เรียก `adb screencap` แบบ synchronous ต่อ request, ทุก client poll ทุก 2s ต่อ device. ที่ 100 devices จะยิ่งชนเพดานเร็วกว่าเดิมมาก
2. **Blocking I/O bug class** — raw-socket PHP ไม่เช็ค stream timeout ใน read loop → ค้างไม่จบจนกว่า nginx ตัด
3. **Headless Rust server ปนกับ auth gate** — เข้าใจผิดง่ายตอน debug, ไม่มี route แยกสำหรับ healthcheck
4. **Debug build เป็น default** — จับ stutter/lag ยากเพราะ build เองช้าอยู่แล้ว
5. **ไม่มี WebRTC จริง** — relay ผ่าน WebSocket เดียว, ที่ 100 device พร้อมกันจะกิน bandwidth server มหาศาล (เทียบ P2P ที่ไม่ผ่าน server หลัง handshake)
6. **PHP portal ไม่ realtime** — full page reload, ไม่เหมาะกับ grid 100 live thumbnail/status

---

## เป้าหมาย v2

- รองรับ 100 device สตรีมพร้อมกันโดยไม่ block กันเอง
- ใช้ WebRTC P2P เป็น transport หลักของวิดีโอ — server ทำหน้าที่ signaling + TURN relay เป็น fallback เท่านั้น ไม่ proxy media เต็มทุก stream
- แยก thumbnail capture เป็น background worker, ไม่ผ่าน request path
- ทุก I/O ที่ block ได้ต้องมี timeout enforce จริง
- Frontend เป็น SvelteKit SPA, อัปเดต device grid/status แบบ realtime ผ่าน WebSocket/SSE
- Service แยกชัดเจน deploy/scale ทีละตัวได้

---

## Tech Stack

| Layer | เทคโนโลยี | เหตุผล |
|---|---|---|
| Video/control capture server | Rust + Axum (คงแนวเดิม, เขียนใหม่) | เร็ว, async I/O ดี, จัดการ ADB/scrcpy session ได้มั่นคง |
| Signaling server (WebRTC) | Rust + Axum, WebSocket สำหรับ SDP/ICE exchange | ใช้ stack เดียวกับ capture server ลดความซับซ้อน |
| TURN/STUN | coturn (มีฐานอยู่แล้วใน `docker/coturn`) | จำเป็นเพราะ WebRTC ต้อง NAT traversal, relay fallback เมื่อ P2P ตรงไม่ได้ |
| Media transport | WebRTC (datachannel หรือ video track ผ่าน custom H.264 RTP packetizer) | ลด latency, ลดโหลด server เทียบ WebSocket relay ทุก byte |
| Thumbnail worker | Rust async worker (แยก binary/service จาก capture server) | เขียนไฟล์ลง shared volume, nginx serve ตรง ไม่ผ่าน backend ใดๆ ต่อ request |
| Frontend | SvelteKit (SPA/SSR hybrid) | bundle เล็ก, reactive store เหมาะกับ grid 100 การ์ดอัปเดตพร้อมกัน |
| Backend API (auth, billing, session metadata) | คงเป็น PHP ได้ถ้าจะลดงาน หรือย้ายเป็น Rust เดียวกับ capture server เพื่อ stack เดียว — **แนะนำ: รวมเข้า Rust** ลดจำนวนภาษาที่ต้อง maintain | ดูหัวข้อ "สิ่งที่ต้องตัดสินใจเพิ่ม" |
| DB | MySQL (คงเดิม) | ไม่ใช่คอขวด |
| Reverse proxy | nginx | TLS terminate ผ่าน cloudflared, serve static thumbnail/SPA build |
| Tunnel | cloudflared (คงเดิม) | ทำงานดีอยู่แล้ว |
| Device registry/session state | Rust in-memory (DashMap) + Redis ถ้า scale หลาย instance ของ capture server | ที่ 100 device อาจต้องมากกว่า 1 instance capture server ข้างหลัง load balancer |

---

## High-level Architecture

```
                         ┌─────────────────────────┐
                         │   SvelteKit SPA (build)  │
                         │   served static by nginx │
                         └────────────┬─────────────┘
                                      │ HTTPS (cloudflared tunnel)
                         ┌────────────▼─────────────┐
                         │          nginx            │
                         └───┬───────────┬───────────┘
                  /api,/ws/* │           │ /thumbs/*.jpg (static)
                             ▼           ▲
                  ┌──────────────────┐   │
                  │  Rust API +       │   │
                  │  Signaling server │   │
                  │  (Axum)           │   │
                  └─────┬─────────┬───┘   │
                        │         │       │
            session mgmt│         │WebRTC SDP/ICE
                        ▼         ▼
              ┌──────────────┐ ┌──────────────┐
              │ ADB/scrcpy   │ │ Browser peer │
              │ capture      │◄┤ WebRTC P2P   │
              │ (per device) │ │ (video+touch)│
              └──────┬───────┘ └──────────────┘
                     │ ICE relay fallback
                     ▼
              ┌──────────────┐
              │   coturn     │
              │ (TURN/STUN)  │
              └──────────────┘

      ┌──────────────────────────┐
      │ Thumbnail worker (Rust)  │  ── loop ทุก N วิ ต่อ device active
      │ adb screencap → resize  │      เขียนไฟล์ /cache/thumbs/{serial}.jpg
      │ → JPEG → cache file      │      nginx serve ตรง ไม่ผ่าน backend
      └──────────────────────────┘
```

---

## Flow หลัก

### 1. WebRTC live stream (ใหม่ทั้งหมด)

```
Browser → POST /api/session/:serial → server สร้าง session, เตรียม scrcpy capture
Browser → WS /signal/:session_id → ส่ง SDP offer
Server  → ตอบ SDP answer + ICE candidates (เริ่มจาก STUN, fallback coturn relay)
Server  → spawn scrcpy capture → encode/packetize H.264 → ส่งเข้า WebRTC video track
Browser → WebRTC PeerConnection รับ video track ตรง (ผ่าน coturn เฉพาะตอน NAT ขวาง)
Browser → touch event → WebRTC datachannel → server → adb control socket → device
```

จุดสำคัญที่ต่างจากเดิม: server **ไม่ proxy ทุก video frame ผ่าน WebSocket อีกต่อไป** — ทำหน้าที่แค่ signaling + encode/relay เท่าที่จำเป็น ลด server bandwidth/CPU ต่อ stream ลงมาก โดยเฉพาะที่ 100 device พร้อมกัน

### 2. Thumbnail (แยกออกจาก request path เด็ดขาด)

```
[Thumbnail worker — service แยก]
  loop ต่อ device ที่ online:
    adb screencap → resize → JPEG → atomic write /cache/thumbs/{serial}.jpg

[Browser]
  <img src="/thumbs/{serial}.jpg?t=...">  → nginx serve ไฟล์ตรง, ไม่แตะ backend
```

### 3. Device status realtime (SvelteKit grid)

```
SvelteKit store ↔ WS /status (server push: online/offline, session state)
ไม่ poll ทุก 2s แบบเดิมอีกต่อไป — server push event เมื่อสถานะเปลี่ยนจริงเท่านั้น
```

### 4. Auth

```
Login → JWT/session cookie (httpOnly) ออกจาก Rust API
ทุก request ไป signaling/API ต้องมี cookie ที่ valid
ภายใน Docker network: service-to-service ใช้ shared secret header เหมือนเดิม (ไม่เปลี่ยน pattern นี้ เพราะทำงานถูกอยู่แล้ว — แค่ผูก scope ให้ชัดว่าใครเรียกใครได้)
```

---

## Capacity planning (100 devices)

| ทรัพยากร | ประเมิน |
|---|---|
| Capture server instance | เริ่ม 1 instance รองรับ ~30-50 concurrent active stream (ต้อง benchmark จริงกับ hardware ที่มี) ถ้าเกินแยก horizontal scale หลัง load balancer + Redis session registry |
| Thumbnail worker | 1 instance พอ ถ้า interval ต่อ device ≥ 3-5s (ไม่ใช่ 2s ทุกตัวพร้อมกันแบบเดิม) — stagger schedule กระจายไม่ให้ adb daemon โดนยิงพร้อมกันหมด |
| coturn | ต้องมี public IP จริง + เปิด UDP relay port range — วางแผน bandwidth relay สำหรับ worst-case (ทุก peer ใช้ relay พร้อมกัน) |
| adb daemon | คอขวดร่วม — ทุก capture/thumbnail ต้องผ่าน adb host:transport เดียว ต้อง throttle/queue ระดับ adb client ไม่ให้ยิง parallel เกินที่ adb server รับไหว |

---

## Service breakdown (deploy แยกได้)

1. **rust-api-signaling** — auth, session API, WebRTC signaling, ADB session orchestration
2. **rust-thumbnail-worker** — background capture loop, เขียน cache file
3. **sveltekit-frontend** — build เป็น static, serve ผ่าน nginx (หรือ Node SSR ถ้าต้องการ)
4. **coturn** — TURN/STUN relay
5. **mysql** — data
6. **nginx** — reverse proxy + static file serving (SPA build + thumbnail cache)
7. **cloudflared** — tunnel เข้า public domain

---

## Migration ลำดับที่แนะนำ

1. ตั้ง repo ใหม่/branch ใหม่ ไม่แตะ production เดิมจนกว่า parity จะครบ
2. เริ่มจาก **rust-api-signaling** + **WebRTC capture path** กับ device จำลอง 2-3 ตัว ให้ video+touch ทำงานจริงก่อน (ความเสี่ยงสูงสุดของทั้งโปรเจกต์ — ทำก่อน)
3. ทำ **thumbnail worker** แยก (ความเสี่ยงต่ำ, งานชัดเจน, ใช้ pattern เดิมที่เข้าใจแล้วจากวันนี้)
4. ทำ **SvelteKit frontend** คู่ขนาน เริ่มจาก dashboard grid + login
5. Load test ที่ scale ใกล้ 100 device (หรือ simulate ด้วย mock capture) ก่อนตัดสินใจ scale-out capture server
6. Cutover: รัน v2 คู่ขนานกับ v1, ย้าย traffic ทีละ batch, เก็บ v1 ไว้เป็น rollback path จนมั่นใจ

---

## WebRTC library: ทำไม webrtc-rs

| ตัวเลือก | ข้อดี | ข้อเสีย |
|---|---|---|
| **webrtc-rs** (เลือก) | Pure Rust, stack เดียวกับ backend ทั้งหมด, deploy/debug ง่าย ไม่ต้อง spawn process แยก | ต้องเขียน RTP packetizer เองมากกว่า, ecosystem เล็กกว่า C++ |
| gstreamer | Codec support เยอะสุด, mature | ต้องติดตั้ง gstreamer แยกทุก host, เพิ่มความซับซ้อน deploy, debug ข้าม process |
| mediasoup | เหมาะ many-to-many SFU | เพิ่ม Node.js runtime, overkill — เคสนี้เป็น 1 device : 1 viewer ไม่ใช่ conference |

เคสนี้คือ 1-to-1 ต่อ session ไม่ใช่ many-to-many จึงไม่ต้อง SFU เต็มรูปแบบ — webrtc-rs สอดคล้องกับ decision "stack เดียว" ที่สุด

## Progress

- [x] **Milestone 1 (PoC)** — scrcpy H.264 stream → webrtc-rs → browser `<video>` decode live. ทำที่ `D:\app\littlecat`, scrcpy module lift มาจาก catcat แทบทั้งดุ้น, codec เปลี่ยนเป็น h264. ทดสอบกับ device จริง (`ce0317130aec9a3102`) ผ่าน localhost, host ICE candidates เท่านั้น (ไม่ใช้ coturn) — **ภาพขึ้นจริง ใช้งานได้**
- [x] **Milestone 2** — touch input ผ่าน WebRTC datachannel `"touch"`, forward เข้า scrcpy control socket (`TouchInput`/`send_input` lift กลับมาจาก catcat) แก้ปัญหาระหว่างทาง: เครื่อง dev มีหลาย virtual adapter (Docker/WSL bridge `172.17.192.1`, APIPA `169.254.*`) ทำให้ ICE connect แล้วหลุดใน ~30s — ใช้ `SettingEngine::set_ip_filter` จำกัดเหลือ loopback + adapter จริง (`10.10.2.181`) เท่านั้น เพิ่ม cleanup `on_peer_connection_state_change` เรียก `session.stop()` กัน orphan scrcpy session ค้าง (`Address already in use`) — **video + touch ใช้งานได้จริงแล้ว**
- [~] **Milestone 3 (ค้าง)** — coturn รันจริงแล้ว (`docker/coturn`, static long-term credential, ตอบ public IP `171.103.43.209` ถูกต้องผ่าน STUN test), router port-forward ครบ (9090 TCP, 3478 UDP+TCP, 49160-49200 UDP → `10.10.2.181`), server bind เปลี่ยนเป็น `0.0.0.0:9090`, ICE config wire ชี้ coturn ผ่าน `.env` (ไม่ hardcode credential) — **ค้างที่ Windows Firewall inbound rule ยังไม่เพิ่ม (ต้องสิทธิ์ admin, รันจาก session นี้ไม่ได้)** ผู้ใช้พักขั้นตอนนี้ไว้ก่อน ยังไม่ได้ทดสอบจากมือถือจริงข้าม NAT
- [x] **Milestone 4 (early)** — session registry (`DashMap<Uuid, Arc<ScrcpySession>>`), `/offer` รับ `serial`+`quality` ต่อ request, ทดสอบ 4 session พร้อมกันจริง เจอ+แก้ race condition: spawn หลาย scrcpy พร้อมกันทำให้อ่าน handshake ไม่ครบ ("early eof") — retry ทั้ง handshake (ไม่ใช่แค่ TCP connect) แก้ได้
  - **Mobile bandwidth fix**: เปลี่ยนกริดจาก live-stream-ทุก-device เป็น thumbnail นิ่ง refresh 10s (background worker, ไม่ผ่าน request path — บทเรียนตรงจาก catcat thumb.php ที่ทำให้ php-fpm starve) คลิก device เพื่อเปิด live session เดียว
  - **Quality tier จริง**: `q240`/`q480`/`q720` ปรับ scrcpy `max_size`+`bitrate` จริง (ไม่ใช่ CSS scale — CSS scale ไม่ลด bandwidth เพราะ encode ขนาดเท่าเดิม)
  - ยังไม่ทำ: scid/port allocation ที่ scale ระดับ 100 จริง (ยังทดสอบแค่ 4 พร้อมกัน), auth/ownership ต่อ session
- [ ] Milestone 5 — auth + DB integration (schema เดิมจาก MySQL)
- [ ] Milestone 6 — SvelteKit frontend

repo: https://github.com/phongthorn/littlecat (private, ยัง push ไม่ได้ — รอ `gh auth login` ให้เสร็จ)

## Next step

Milestone 3: ทดสอบ coturn relay ข้าม network จริง (ไม่ใช่ localhost) — ต้องเปลี่ยนจาก `ice_servers: vec![RTCIceServer::default()]` (ว่าง) เป็นชี้ไปที่ coturn จริงที่เตรียมไว้แล้ว (`docker/coturn`), และทดสอบจาก network คนละวงกับ server
