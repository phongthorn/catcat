# BoxPhone — Web App Workflow

## สถานะปัจจุบัน (2026-06-24)

### สิ่งที่ทำงานแล้ว ✅
| สิ่ง | หลักฐาน / commit |
|------|---------|
| HTTP + WebSocket server รันที่ port 3000 | `BoxPhone server running at http://localhost:3000` |
| Web UI (grid + focus view) | preview screenshot แสดงผลถูกต้อง |
| `/api/devices` ส่งรายการ device | SM G990E — RFCT60M26MY |
| WebSocket signaling browser ↔ server | `WS open` ใน console |
| scrcpy v3.3 JAR push + launch บน device | `d2b5f32` — รองรับ Android 16 / API 36 |
| TCP video + control socket protocol แก้แล้ว | `d2b5f32` — single port, video accept ก่อน control |
| Auto-discover devices เมื่อ page load | `d2b5f32` — ไม่ต้อง Connect เอง |
| Screencap thumbnail ใน device card | `d2b5f32` — `GET /api/screenshot/:serial` |
| USB hot-plug detection (poll 3s) | `bc02fe5` — broadcast via WebSocket |
| Admin page `/admin.html` | `bc02fe5` — device table, ADB connect, Scan Network, Activity Log |
| Per-card dropdown (Vol, Mute, Screenshot, Restart ADB, Reboot) | `bc02fe5` |
| Layout presets 1/2/3 col ← localStorage | `bc02fe5` |
| Thumbnail auto-refresh ทุก 10s (skip ถ้า streaming) | `bc02fe5` |
| Focus view fullscreen overlay + side panel (≥1024px) | `bc02fe5` |
| Startup concurrency limiter (MAX_CONCURRENT_STARTS = 6) | `bc02fe5` |
| Skip JAR push ถ้าอยู่บน device แล้ว (size check) | `bc02fe5` |
| Reconnect jitter ±1s | `bc02fe5` |
| ADB path เปลี่ยนเป็น `C:\LDPlayer\LDPlayer9\adb.exe` | `bc02fe5` |

### ปัญหาที่ยังค้างอยู่ ❌
| ปัญหา | อาการ | ไฟล์ที่เกี่ยวข้อง |
|-------|-------|-----------------|
| Video stream ยังไม่ได้ยืนยันใน browser | ต้องทดสอบ WebCodecs decode กับ scrcpy v3.3 protocol ที่แก้แล้ว | `public/app.js:decodeNal()` |
| Touch / Key control ยังไม่ได้ทดสอบ | ต้องรอ video stream ก่อน | `server.js:sendControl()` |
| Multi-device ยังไม่ได้ทดสอบกับ device จริง ≥ 2 เครื่อง | ขึ้นกับ hardware | `server.js:DeviceAgent` |

---

# 📐 แผนสเกล 100–200 ADB + Dashboard (วางแผน / คำแนะนำ — 2026-06-24)

> ส่วนนี้เป็น **แผนและคำแนะนำเท่านั้น** (ยังไม่แก้โค้ด) ตอบโจทย์:
> ควบคุม ADB ~100 ตัว (อิสระต่อกัน, เชื่อมต่อทันที, เลือกความละเอียด, สเกล 100–200)
> และฝั่ง Web (Front N / Back N, แสดงสถานะ Server + ADB แต่ละตัว, card view, กดเพื่อเต็มจอ)

## A. Gap Analysis — โค้ดปัจจุบัน vs เป้าหมาย 100 ตัว

| ความต้องการ | โค้ดปัจจุบัน | ปัญหาเมื่อสเกล 100 ตัว | ไฟล์ / จุด |
|------------|-------------|------------------------|-----------|
| 1 browser เห็น 100 ตัว | เปิด **WebSocket แยกต่อ device** | 100 WS + 100 `VideoDecoder` ต่อ tab → เบราว์เซอร์ crash / GPU หมด | `public/app.js:52` `watchDevice()` |
| แยก stream ของแต่ละ device บน WS เดียว | `_broadcastBinary()` ส่ง payload ดิบ (ไม่มี serial-tag) แม้ comment บอกจะ prepend 4-byte | ทำ multiplex ไม่ได้ ต้องพึ่ง 1-WS-ต่อ-device | `server.js:303-315` |
| สตรีมเฉพาะเท่าที่ต้องดู | `pollAdbDevices` **auto-start ทุก device** ที่เจอ (8Mbps/1080p) | 100 scrcpy encoder รันตลอดเวลาแม้ไม่มีคนดู → CPU/USB/แบนด์วิดท์ระเบิด | `server.js:764-775`, `767`, `774` |
| เลือกความละเอียด streaming | `max_size=1080`, `video_bit_rate=8000000` **hardcode** | เปลี่ยน res/bitrate รายเครื่องหรือ global ไม่ได้ | `server.js:147-148` |
| grid 100 ตัวลื่น | ทุก card สร้าง `VideoDecoder` เต็มเฟรม | 100 decoder ฮาร์ดแวร์พร้อมกัน ไม่ไหว | `public/app.js:356`, `299` |
| thumbnail เบา | `screencap -p` เขียนไฟล์ลง /sdcard แล้ว `pull` | 100 ตัว × ทุก 10s = I/O หนัก, เขียนไฟล์ดิสก์ฝั่ง PC | `server.js:448-460` |
| จัดกลุ่ม Front/Back | ไม่มี | ต้องเพิ่ม model กลุ่ม + UI | ใหม่ |
| Dashboard สถานะรวม | มี `/api/admin/status` + admin.html | ยังไม่มีหน้า overview รวม server health + ทุก ADB | `server.js:520`, `public/admin.html` |
| ควบคุมพร้อมกันหลายคน | viewer ทุกคน control ได้ | ชนกันบน farm — ต้องมี lock/owner | `server.js:669` |
| ความปลอดภัย | ไม่มี auth บน control/admin | farm 100 เครื่องเปิดโล่ง | ทั้ง server |

## B. สถาปัตยกรรมเป้าหมาย (100–200 ADB)

หลักการสำคัญ **2 โหมดสตรีม** (สิ่งนี้คือกุญแจของการสเกล):

```
GRID MODE (ดู 100 ตัวพร้อมกัน)        FOCUS MODE (กดดูตัวเดียวเต็มจอ)
─ low-res thumbnail (JPEG ~2–5 fps)   ─ full H.264 stream (เลือก res ได้)
─ ไม่มี VideoDecoder ต่อ card          ─ 1 VideoDecoder เท่านั้น
─ ใช้ <img> รูปนิ่ง                     ─ bitrate สูง, low-latency
─ เซิร์ฟเวอร์: screencap หรือ           ─ เซิร์ฟเวอร์: scrcpy เปิดเฉพาะตอน focus
  scrcpy low-profile ที่ downscale
```

> เหตุผล: เบราว์เซอร์รับ `VideoDecoder` พร้อมกันได้จริงประมาณ 8–16 ตัวเท่านั้น และ GPU decode 100 สตรีม 1080p เป็นไปไม่ได้ การแยกโหมดทำให้ grid เบาและ focus คมชัด

### WebSocket แบบ multiplex (1 เส้นต่อ browser)
- เปลี่ยนจาก 1-WS-ต่อ-device → **WS เดียว** ส่ง/รับทุก device
- binary frame ต้อง **prepend header**: `[u32 serial-id][payload]` (เติมให้ตรงกับ comment ที่ค้างไว้ที่ `server.js:310`)
- client ทำ map `serial-id → device` แล้ว route เข้า decoder ที่ถูกตัว
- ลด overhead จาก 100+ handshake เหลือ 1, ส่ง JSON signaling + binary บนเส้นเดียว

### Lazy streaming (เปิดเท่าที่ดู)
- `pollAdbDevices` ควร **ลงทะเบียน device เป็น `idle` เท่านั้น** ไม่ auto-`start()` (`server.js:767,774`)
- เริ่ม full stream เมื่อมี viewer focus จริง; grid ใช้ thumbnail
- ปิด stream อัตโนมัติเมื่อไม่มี viewer เกิน N วินาที (มี `viewers.size===0` guard อยู่แล้วที่ `server.js:249` แต่ต้องถึงขั้น stop agent)

## C. Backlog เรียงตามลำดับความสำคัญ

### P0 — บล็อกการสเกล (ต้องทำก่อน)
1. **แยก Grid/Focus mode** — grid ใช้ thumbnail, focus เท่านั้นที่ใช้ H.264 decoder
   - แก้ `app.js`: ไม่สร้าง `cardDecoder` (`app.js:356`); card แสดง `<img>` thumbnail refresh
   - focus เปิด stream on-demand, ปิดเมื่อ closeFocus
2. **Lazy start streaming** — เอา auto-`start()` ออกจาก hotplug/discover; start เมื่อ focus
   - `server.js:767,774` (hotplug), `app.js:102` (`watchDevice` ใน discover)
3. **WS multiplex + serial-tag binary** — `server.js:303-315` เติม 4-byte serial-id; `app.js` ใช้ WS เส้นเดียว route ตาม id
4. **เลือกความละเอียด** — param `max_size`/`video_bit_rate` รับค่าจาก request (ดูหัวข้อ D)

### P1 — เสถียรภาพ / ทรัพยากร
5. **Thumbnail แบบไม่เขียนไฟล์** — เปลี่ยนเป็น `adb -s S exec-out screencap -p` pipe ตรง (เลิกเขียน /sdcard + pull + temp file) `server.js:448-460`
6. **Thumbnail หลายตัวพร้อมกัน throttle** — มี `_screencapInFlight` แล้ว แต่ 100 ตัวต้องมี global semaphore (เช่น 8–10 พร้อมกัน) กัน adb daemon ตัน
7. **Auto-stop agent เมื่อไม่มี viewer** — คืน USB bandwidth/CPU
8. **Reconnect backoff แบบ exponential** — ปัจจุบัน fix 3s + jitter (`server.js:334-342`); 100 ตัวหลุดพร้อมกันควร backoff 3→6→12s

### P2 — multi-user / farm
9. **Control lock ต่อ device** — owner คนเดียว control, คนอื่น view อย่างเดียว (`server.js:669`)
10. **Auth ขั้นต่ำ** — token/password บน admin + control endpoints
11. **per-device health metric** — fps, last-frame-time, restart count ใน dashboard

## D. ฟีเจอร์: เลือกความละเอียด streaming

แนวคิด: profile สำเร็จรูป + ส่งค่าผ่าน `watch`/focus message
| Profile | max_size | bitrate | ใช้เมื่อ |
|---------|----------|---------|---------|
| Low (grid) | 360–480 | 1 Mbps | ดูหลายตัว |
| Medium | 720 | 3 Mbps | focus ทั่วไป |
| High | 1080 | 8 Mbps | focus งานละเอียด |

- เพิ่ม field ใน `watch` message: `{ type:'watch', serial, maxSize, bitRate }`
- `DeviceAgent._launch()` อ่านค่าจาก agent state แทน hardcode (`server.js:147-148`)
- เปลี่ยน res ต้อง **relaunch scrcpy** (parameter ตั้งตอน start) → ใส่ debounce กันสลับถี่
- UI: dropdown ใน focus header (ข้างปุ่ม zoom) + ค่า default global ใน toolbar

## E. ฟีเจอร์: "Front N / Back N" = Frontend N / Backend N (สเกลแนวนอน)

> ✅ ยืนยันแล้ว (2026-06-24): หมายถึง **สร้าง process Frontend N ตัว + Backend N ตัว** เพื่อกระจายโหลด ไม่ใช่จัดกลุ่ม UI หรือ emulator
> Use case: **ให้เช่ามือถือจริงผ่านเว็บ** (cloud phone rental) — 1 user ใช้ได้หลาย ADB

นี่คือ feature เดียวกับ sharding ในหัวข้อ G แต่ทำให้เป็น **คำสั่งสร้าง/ลด instance ได้จาก dashboard**:

- **Backend N (`สร้าง Back N`)** = spawn worker process N ตัว แต่ละตัวถือ device pool ส่วนหนึ่ง (เช่น 50 ตัว/worker)
  - แต่ละ Backend = `server.js` ที่รัน DeviceAgent เฉพาะ serial ที่ได้รับมอบหมาย + ฟัง port ภายในของตัวเอง
  - ลงทะเบียนตัวเองกับ Gateway (serial → worker map)
- **Frontend N (`สร้าง Front N`)** = spawn gateway/web-serving process N ตัว (เสิร์ฟ UI + รวม WS จาก browser)
  - หลาย Frontend อยู่หลัง load balancer/round-robin → รองรับผู้เช่าหลายคนพร้อมกัน
  - route WS ของแต่ละ serial ไปยัง Backend ที่ถือ device นั้น (sticky by serial)
- **Control plane:** ตัวจัดการกลางสั่ง `สร้าง/ลด Front N, Back N`, ทำ device→worker assignment, health-check, restart worker ที่ตาย

```
สร้าง Front N ──► [FE-1][FE-2]...[FE-N]  (web + WS aggregation, หลาย tenant)
                       │ route by serial (sticky)
สร้าง Back N  ──► [BE-1: 50 ADB][BE-2: 50 ADB]...[BE-N]  (DeviceAgent pool)
                       │ USB
                  [มือถือจริง × 100-200]
```

> เริ่มจาก **monolith เดิม (1 FE + 1 BE ใน process เดียว)** ก่อน แล้วค่อยแยกเมื่อชนเพดาน USB/CPU; ออกแบบ `DeviceAgent` ให้ stateless ต่อ serial เพื่อให้ assign ข้าม worker ได้

## E2. Multi-tenant / Rental (use case ให้เช่า)

เพราะนำไปให้เช่า — เพิ่มชั้น tenant ที่เดิมยังไม่มีเลย (**เลื่อน auth/lock เป็น P0**):
- **User/Session:** login, แต่ละ user เห็น/คุมเฉพาะ ADB ที่ตัวเองเช่า (`server.js:669` ตอนนี้ใครก็ control ได้ → อันตราย)
- **Device assignment:** mapping `user → [serial...]`, lease/หมดอายุ, คืน device อัตโนมัติ
- **Isolation:** Frontend filter `/api/devices` + WS `watch` ตาม ownership (ตอนนี้เปิดทุกตัวให้ทุกคน)
- **Reset ระหว่างผู้เช่า:** ก่อนปล่อยเช่ารอบใหม่ → factory-clean / logout app / clear data (script ผ่าน adb)
- **Audit log + quota:** ชั่วโมงใช้งานต่อ user สำหรับคิดเงิน

## F. Dashboard สถานะ (Server + ADB แต่ละตัว)

ต่อยอดจาก `/api/admin/status` (`server.js:520`) ที่มีอยู่:
- **Server health:** uptime, จำนวน agent ตามสถานะ, CPU/mem (`process.memoryUsage`, `os.loadavg`), จำนวน WS clients, scrcpy process count
- **ต่อ ADB:** serial, group, state, res ปัจจุบัน, viewers, fps, last-frame age, restart count, battery (`adb shell dumpsys battery`)
- **Realtime:** push ผ่าน WS broadcast ทุก 1–2s แทน polling REST
- **การ์ด:** มี state badge อยู่แล้ว (`app.js:210 updateCardState`) — เพิ่ม group section + แถบสถานะรวมด้านบน

## G. แผนสเกล 100 → 200 (Hardware + Sharding)

ข้อจำกัดจริง (ขยายจาก STEP 7 เดิม):
- **USB:** 1 host controller รับ ~15–20 ตัว → 100 ตัวต้อง PCIe USB card หลายใบ หรือหลาย PC; ถ้าเป็น **LDPlayer emulator** ไม่ติด USB แต่ติด CPU/RAM/GPU ของ host (1 instance ≈ 1.5–2 GB RAM) → 100 instance ต้อง 128–256 GB RAM + หลาย host
- **ADB daemon:** 1 adb server จัดการ 100+ device ได้แต่ `adb devices` poll ช้าลง → เพิ่ม poll interval หรือใช้ `track-devices`
- **Node process เดียว:** demux H.264 (ไม่ decode) เบา แต่ socket/agent 100–200 ควรแยก worker

สถาปัตยกรรม sharding ที่แนะนำ:
```
                ┌─ Gateway (Node) ── เสิร์ฟ UI + รวม WS ─┐
Browser ───────►│  routing serial → worker               │
                └───┬───────────────┬───────────────┬────┘
            ┌───────▼──┐     ┌───────▼──┐     ┌──────▼───┐
            │ Worker A │     │ Worker B │     │ Worker C │   (แต่ละตัว = 1 process/PC)
            │ 50 ADB   │     │ 50 ADB   │     │ 50 ADB   │
            └──────────┘     └──────────┘     └──────────┘
```
- เริ่มจาก **1 host จนกว่าจะถึงเพดาน** แล้วค่อยแตก worker (อย่า over-engineer ตอนนี้)
- ออกแบบ `DeviceAgent` ให้ stateless ต่อ serial เพื่อย้าย worker ได้ง่าย (ตอนนี้ใกล้เคียงแล้ว)

## H. ข้อสรุปจากคำตอบผู้ใช้ (2026-06-24)

| ประเด็น | คำตอบ | ผลต่อแผน |
|--------|-------|---------|
| ชนิดอุปกรณ์ | **มือถือจริงผ่าน USB** | ใช้ USB hardware planning (หัวข้อ G): ~15–20 ตัว/USB controller → 100–200 ตัวต้อง PCIe USB card หลายใบ หรือหลาย PC + powered hub; ไม่ติด RAM แบบ emulator |
| Front N / Back N | **Frontend N / Backend N** | = horizontal scaling (หัวข้อ E), ไม่ใช่ UI group |
| การใช้งาน | **ให้เช่ามือถือจริง, 1 user คุมหลาย ADB, ควบคุมผ่านเว็บ** | ต้อง **multi-tenant + auth + control-lock** (หัวข้อ E2) → เลื่อนขึ้นเป็น P0; control socket ต้องเปิด (มี interact จริง) |

### ลำดับความสำคัญที่ปรับใหม่ (หลังได้คำตอบ)
- **P0 (rental-blocking):** Auth + user session, device ownership/lease, control-lock ต่อ device — *ของเหล่านี้ขยับขึ้นจาก P2 เพราะเป็นบริการให้เช่า ห้ามให้คนอื่นแย่งคุมเครื่องที่ผู้อื่นเช่า*
- **P0 (scale-blocking):** Grid/Focus 2-mode, lazy start, WS multiplex, เลือกความละเอียด (เดิม)
- **P1:** thumbnail ไม่เขียนไฟล์, auto-stop, backoff, health metric
- **P2:** แยก Frontend/Backend จริงเป็นหลาย process (ทำเมื่อชนเพดาน 1 PC)

### คำถามที่ยังเหลือ (ไม่บล็อก เริ่มออกแบบได้)
1. จำนวน **ผู้เช่าพร้อมกัน** สูงสุดที่คาดไว้? (กระทบจำนวน Frontend N)
2. มือถือทั้งหมดอยู่ที่ **PC เดียว** หรือกระจายหลาย PC ตั้งแต่แรก?
3. ต้องการ **billing/quota** ในระบบเลย หรือทำ access-control ก่อน?
4. ผู้เช่าต้องการ **reset เครื่องอัตโนมัติ** ระหว่างรอบเช่าไหม?

## I. มุมมองผู้ให้บริการ (Provider Console) — 2026-06-24

> เป้าหมาย: เห็น ADB **ทุกตัว**ที่รันอยู่ **โดยไม่ต้องแสดง card/ภาพจริง** — เน้นสถานะล้วน + กัน multi-tenant ชนกัน

### I.1 หน้า monitor แบบ table (ไม่ใช่ video)
- หน้าใหม่แยกจาก customer (เช่น `/provider.html`) หรือต่อยอด `public/admin.html`
- ใช้ `GET /api/admin/status` ที่มีอยู่ ([server.js:520](server.js:520)) เป็นฐาน — **ไม่เปิด video/decoder เลย** → เบามาก รองรับ 200 แถวสบาย
- แสดงเป็น **ตารางสถานะ** ต่อ ADB:

| คอลัมน์ | ที่มา | หมายเหตุ |
|--------|-------|---------|
| serial | `agents` keys / `adb devices -l` | |
| state | `agent.state` | idle/connecting/streaming/reconnecting/error |
| online? | `adbDevices[].status === 'device'` | จุดเขียว/แดง |
| worker/tenant | mapping ใหม่ | เครื่องนี้ถูก worker ไหนถือ / ใครเช่า |
| viewers | `agent.viewers.size` | |
| res ปัจจุบัน | `width`×`height` | |
| last-frame age | metric ใหม่ | ตรวจว่า "ทำงานจริง" ไม่ใช่ค้าง |
| restart count | counter ใหม่ใน DeviceAgent | เครื่องที่ flaky |
| battery / temp | `adb shell dumpsys battery` (poll ห่างๆ) | สุขภาพเครื่องจริง |

- **สรุปด้านบน:** total / online / streaming / error, server uptime, mem (`process.memoryUsage`), จำนวน WS clients, scrcpy process count
- **realtime:** broadcast สถานะทุก 2–3s ผ่าน WS (มี `broadcastAll` อยู่แล้ว [server.js:723](server.js:723)) แทน polling REST
- **action ต่อแถว:** restart ADB, reboot, force-release lease, kick viewer (REST มีบางส่วนแล้ว: restart [server.js:575](server.js:575), reboot [server.js:590](server.js:590))

### I.2 Multi-tenant — กัน ADB ทำงานชนกัน (สำคัญ)
ปัญหาปัจจุบัน: ใครก็ `watch`/`control` ทุก serial ได้ ([server.js:633](server.js:633), [server.js:669](server.js:669)) → ผู้เช่าแย่งเครื่องกันได้
- **Single-owner lock ต่อ device:** 1 serial → ผูกกับ 1 tenant/session ในเวลาหนึ่ง; คนอื่น `watch` เครื่องนั้น = ปฏิเสธ
- **Control ผูก owner เท่านั้น:** ใส่เช็ค ownership ใน `case 'control'` ([server.js:665](server.js:665)) และ `case 'watch'` ([server.js:633](server.js:633))
- **Lease model:** assign serial → tenant ตอนเริ่มเช่า, ปลดเมื่อหมดเวลา/logout; provider override ได้
- **กัน start ซ้อน:** มี `_queued`/state guard อยู่แล้ว ([server.js:87](server.js:87)) — เพิ่ม guard ว่า device ที่มี owner อยู่ ห้าม agent ตัวอื่นยึด socket

## J. มุมมองลูกค้า (Customer Portal) — 2026-06-24

> เป้าหมาย: ลูกค้าเห็นเฉพาะจอที่ตัวเองเช่า (1 / 2 / X) เป็น thumbnail แล้วกดเข้าใช้งานเต็มจอ

- **กรองตาม ownership:** `/api/devices` + auto-discover ([app.js:88](public/app.js:88)) ต้อง return เฉพาะ serial ของลูกค้าคนนั้น (ตอนนี้คืนทุกตัว)
- **Grid = thumbnail เท่านั้น** (ตามหัวข้อ B): เช่า 1 จอ = 1 การ์ดใหญ่, 2 จอ = 2 การ์ด, X จอ = grid — **ไม่รัน decoder ใน grid**
- **กดการ์ด → Focus mode** เปิด H.264 stream ตัวเดียว + เลือกความละเอียด (โครงมีแล้ว [app.js:259](public/app.js:259) `openFocus`)
- **layout ปรับตามจำนวนจอเช่า** อัตโนมัติ (มี layout preset อยู่แล้ว [app.js:626](public/app.js:626))

## K. ฟีเจอร์เทียบเท่า cloudemulator.net (Redfinger) — สร้างใหม่เอง

> หมายเหตุลิขสิทธิ์: วางแผน **สร้างฟังก์ชันที่ทำงานเทียบเท่า** ด้วยโค้ดเราเอง (ผ่าน adb/scrcpy) — ไม่คัดลอกโค้ด/asset ของเขา

ฟีเจอร์ที่ Redfinger มีใน Tools panel → แมปวิธีทำกับ adb ของเรา:

| ฟีเจอร์ Redfinger | ทำด้วยอะไรในระบบเรา | ลำดับ |
|------------------|---------------------|------|
| Virtual buttons (Home/Back/Recents/Vol) | มีแล้ว — scrcpy keycode ([app.js:542](public/app.js:542)) | ✅ |
| File / APK upload | `adb push` + `adb install` ผ่าน endpoint ใหม่ + ปุ่ม upload ใน focus | P1 |
| Clipboard sync (copy/paste) | scrcpy control message set/get clipboard | P2 |
| Screenshot | มีแล้ว ([server.js:437](server.js:437)) | ✅ |
| Reboot / Batch reboot | reboot มีแล้ว ([server.js:590](server.js:590)); batch = วน serial ที่เลือก | P1 |
| Scheduled reboot | cron ฝั่ง server ต่อ serial | P2 |
| Batch install / uninstall / clear-data | `adb install/uninstall/pm clear` วน serial pool | P1 |
| GPS / location mock | `adb shell appops` + mock location หรือ scrcpy | P3 |
| Keyboard (พิมพ์ไทย/อังกฤษ) | มีบางส่วน ([app.js:549](public/app.js:549)) — ปรับ inject text | P1 |
| Multi/parallel account ops | batch action layer (เลือกหลาย serial → สั่งพร้อมกัน) | P2 |
| Customize device props (IMEI/model ฯลฯ) | ระวัง: ของเขาใช้ 900+ params; ของเราทำได้จำกัดบนเครื่องจริง (ต้อง root) | P3 / ขึ้นกับ root |

> คำแนะนำลำดับ: ทำ **File/APK upload + batch reboot/install + keyboard** (P1) ก่อนเพราะลูกค้าเช่าใช้บ่อยสุด; clipboard/scheduled/multi-account (P2) ตามมา; GPS/customize props (P3) ขึ้นกับว่า root ได้ไหม

**อ้างอิงฟีเจอร์:** [Redfinger setup](https://www.cloudemulator.net/setup/) · [Userbook](https://www.cloudemulator.net/userbook/) · [File upload tool](https://www.cloudemulator.net/app/tool/upload-file/upload?channelCode=web)

---

## ขั้นตอนที่ต้องทำ (เรียงตามลำดับ)

---

### STEP 1 — แก้ scrcpy tunnel_forward protocol ✅ DONE (commit d2b5f32)

**ปัญหาเดิม:** video socket ได้รับแค่ `0x00` (dummy byte) แล้วไม่มี data ต่อ

**วิธีที่แก้ได้:** upgrade จาก scrcpy v3.2 → v3.3 และเปลี่ยน protocol เป็น single port:
- video socket accept ก่อน, control socket accept หลัง (ไม่ใช่ port แยกกัน 2 port)
- `server.js:_launch()` ใช้ `localabstract:scrcpy` ชื่อเดิม (ไม่มี suffix)

**ผลลัพธ์:**
```
server log: [RFCT60M26MY] INFO: Device: samsung SM-G990E (Android 16)
video socket: รับ H.264 data ต่อจาก dummy byte สำเร็จ
```

---

### STEP 2 — ทดสอบ Video Decode ในเบราว์เซอร์

**ข้อกำหนด:**
- Chrome ≥ 94 (WebCodecs API)
- H.264 codec: `avc1.42E01E` (Baseline profile)

**ขั้นตอนทดสอบ:**
1. เปิด Chrome → `http://localhost:3000`
2. กด "เปิด Stream" → เลือก RFCT60M26MY
3. เปิด DevTools → Console → ดูว่ามี error ไหม
4. device card ต้องเปลี่ยนจาก `connecting` → `streaming`
5. canvas ต้องแสดงภาพหน้าจอโทรศัพท์

**Debug Console ที่ต้องไม่เห็น:**
```
VideoDecoder: Unsupported codec
EncodedVideoChunk: Invalid timestamp
```

**Debug Console ที่ควรเห็น:**
```
[RFCT60M26MY] device WS connecting
MSG: {"type":"device-info","serial":"RFCT60M26MY","width":720,"height":1600,...}
```

**วัด FPS (วาง JS นี้ใน console):**
```js
let frames = 0;
const orig = CanvasRenderingContext2D.prototype.drawImage;
CanvasRenderingContext2D.prototype.drawImage = function(...a) { frames++; return orig.apply(this, a); };
setInterval(() => { console.log('FPS:', frames); frames = 0; }, 1000);
```
เงื่อนไขผ่าน: FPS ≥ 15

---

### STEP 3 — ทดสอบ Touch / Key Control

**ขั้นตอนทดสอบ:**

| Action | วิธีทดสอบ | ผลที่คาด |
|--------|-----------|---------|
| Tap | คลิกที่ canvas ใน focus panel | โทรศัพท์ตอบสนอง เช่น เปิด app |
| Swipe | ลากบน canvas | หน้าจอ scroll |
| Scroll wheel | scroll บน canvas | scroll บนโทรศัพท์ |
| Back | กดปุ่ม Back | กลับหน้าก่อน |
| Home | กดปุ่ม Home | กลับ home screen |
| Volume | กดปุ่ม Vol+/Vol- | ระดับเสียงเปลี่ยน |
| Keyboard | พิมพ์ตัวอักษรขณะ focus | text ปรากฏในช่อง input |

**ตรวจสอบ control message format:**
scrcpy 3.2 control message type 2 (inject touch):
```
offset 0:  type=2 (1 byte)
offset 1:  action: 0=down,1=up,2=move (1 byte)
offset 2:  pointer_id (8 bytes, BigInt64)
offset 10: x (4 bytes, int32)
offset 14: y (4 bytes, int32)
offset 18: screen_width (2 bytes)
offset 20: screen_height (2 bytes)
offset 22: pressure (2 bytes, 0–65535)
offset 24: action_button (4 bytes)
total: 28 bytes
```
ถ้า control ไม่ทำงาน: ดู `server.js:sendControl()` ว่าเขียนลง `_controlSock` ถูกไหม

---

### STEP 4 — Multi-device (ต้องมี USB device ≥ 2 เครื่อง)

**ข้อกำหนดเบื้องต้น:**
- USB hub พร้อม power adapter แยก (ไม่ใช่ bus-powered)
- device แต่ละเครื่องเปิด USB Debugging แล้ว

**ขั้นตอนทดสอบ:**
1. `adb devices -l` → ต้องเห็น ≥ 2 serials
2. เปิด `http://localhost:3000`
3. เลือก device 1 → กด "เปิด Stream" → เห็น card #1
4. เลือก device 2 → กด "เปิด Stream" → เห็น card #2
5. ตรวจ server log ว่ามี 2 DeviceAgent คนละ port:
   ```
   [SERIAL1] adb process started, ports video=XXXXX ctrl=XXXXX
   [SERIAL2] adb process started, ports video=YYYYY ctrl=YYYYY
   ```
6. คลิก card 1 → focus panel แสดง device 1
7. คลิก card 2 → focus panel สลับไป device 2
8. ส่ง touch บน focus panel ของแต่ละ device → ต้องส่งไปถูก device

---

### STEP 5 — Auto-reconnect / Stability

**ทดสอบ USB disconnect:**
1. กด Watch device → รอ streaming
2. ถอด USB cable
3. ดู device card → ต้องเปลี่ยนเป็น `reconnecting` ภายใน 5 วิ
4. เสียบ USB กลับ
5. รอ ≤ 10 วิ → card ต้องกลับเป็น `streaming`

**ทดสอบ reconnect loop (10 รอบ):**
```bash
# ทำ 10 ครั้ง: ถอด USB → 3 วิ → เสียบ USB → 5 วิ
# สังเกต: server process ต้องยังทำงานอยู่, ไม่มี memory leak
```
ตรวจ memory หลัง 10 รอบ:
```bash
# Windows Task Manager → node.exe → ต้องไม่เพิ่มขึ้นเกิน 50MB ต่อรอบ
```

**ทดสอบ port exhaustion:**
- รัน reconnect 50 รอบ
- ตรวจว่า `getFreePort()` ยังหา port ได้ (ไม่ติด EADDRINUSE)

---

### STEP 6 — Multi-user (50+ concurrent)

**ทดสอบ 5 users (simulate):**
```js
// รัน script นี้ใน Node.js (project dir)
// test_multiuser.js
const { WebSocket } = require('ws');
const N = 5;
const serial = 'RFCT60M26MY';
let received = new Array(N).fill(0);

for (let i = 0; i < N; i++) {
  const ws = new WebSocket('ws://localhost:3000');
  ws.on('open', () => ws.send(JSON.stringify({ type: 'watch', serial })));
  ws.on('message', (d, isBin) => {
    if (isBin) received[i]++;
  });
}

setInterval(() => {
  console.log('frames received per client:', received.join(', '));
  received.fill(0);
}, 1000);
```
เงื่อนไขผ่าน: ทุก client ได้ frames > 0 ต่อวินาที (fan-out ทำงาน)

**ทดสอบ 50 users (load test):**
- เปิด script นี้ด้วย N = 50
- monitor CPU / memory ของ node.exe
- เงื่อนไขผ่าน: CPU < 50%, no crash ใน 5 นาที

---

### STEP 7 — Scalability Hardware Planning (100 devices)

**ข้อจำกัดทางกายภาพ:**
- USB 3.0 host controller 1 chip รองรับ ~15–20 devices (bandwidth + power)
- PC ทั่วไปมี 2–4 USB controllers → รองรับ 30–80 devices ต่อเครื่อง
- 100 devices จริงๆ ต้องใช้ **2 PC** หรือ PCIe USB expansion card

**การคำนวณ bandwidth:**
- 1 device ที่ 720p@30fps @ 3Mbps = 3 Mbps
- 100 devices = 300 Mbps (เฉพาะ video out ไป browser)
- Network card ที่ใช้ต้องเป็น Gigabit Ethernet ขึ้นไป

**Plan สำหรับ 100 devices:**
```
[PC1: 50 devices] ─┐
                   ├─ [Switch GbE] ─ [Browser clients]
[PC2: 50 devices] ─┘
```
ถ้ายังต้องการ single server: ใช้ distributed agent (PC1/PC2 เป็น provider, PC อื่นเป็น gateway)

---

### STEP 8 — Performance Measurement & Tuning

**วัด latency (end-to-end):**
1. เปิด stopwatch app บนโทรศัพท์
2. screenshot canvas บน browser
3. วัด offset ระหว่างเวลาบน stopwatch กับเวลาใน canvas ภาพ
4. เงื่อนไขผ่าน: latency ≤ 200ms

**วัด server CPU:**
```bash
# Windows: Task Manager → node.exe → CPU %
# หรือ: ใช้ clinic.js
npx clinic doctor -- node server.js
```

**วัด memory per device:**
```bash
# ก่อน watch: process.memoryUsage().heapUsed
# หลัง watch 10 devices: ต้องเพิ่มไม่เกิน 50MB ต่อ device
```

**Parameter tuning:**
| Parameter | Default | เมื่อ CPU สูง | เมื่อ latency สูง |
|-----------|---------|--------------|-----------------|
| `video_bit_rate` | 3000000 | ลดเป็น 1500000 | เพิ่มเป็น 4000000 |
| `max_size` | 720 | ลดเป็น 540 | คงไว้ 720 |
| `video_codec` | h264 | h264 (ไม่เปลี่ยน) | h264 |
| reconnect delay | 3000ms | — | ลดเป็น 1500ms |

---

## Rollback Plan

ถ้า scrcpy 3.2 ไม่ทำงาน:

### Fallback 1: scrcpy 1.25 (ทดสอบแล้วทำงานได้กับ Android 11–13)
- ดาวน์โหลด `scrcpy-server-v1.25` (ยังรองรับ Android 16 หรือเปล่าต้องทดสอบ)
- เปลี่ยน `SCRCPY_VERSION = '1.25'` และ header parsing กลับเป็น 72 bytes

### Fallback 2: `adb exec-out screenrecord` (ไม่ต้องใช้ scrcpy)
```js
adbProc = spawn(ADB_PATH, [
  '-s', serial, 'exec-out',
  'screenrecord', '--output-format=h264',
  '--bit-rate=2M', '--time-limit=180', '-'
]);
// pipe stdout โดยตรงเป็น H.264 stream ไปที่ browser
// ต้อง restart ทุก 3 นาที → ใส่ loop reconnect
```
ข้อเสีย: ไม่มี control (touch/key) ผ่าน channel เดียวกัน → ต้องใช้ `adb shell input` แทน (latency สูงขึ้น ~50ms)

### Fallback 3: screencap polling (MJPEG mode)
ถ้าต้องการเพียง "แสดงภาพ" ไม่ต้อง real-time:
```js
// poll `adb exec-out screencap -p` ทุก 500ms → ส่ง JPEG ไปยัง browser
// FPS ≈ 2 — พอใช้สำหรับ monitoring ไม่ใช่ interaction
```

---

## โครงสร้างไฟล์

```
D:\app\boxphone\
├── server.js           ← Node.js HTTP + WebSocket server (MAIN)
├── public\
│   ├── index.html      ← Web UI (grid view + focus panel + USB hot-plug)
│   ├── app.js          ← Frontend: WebCodecs decoder, control events, admin
│   └── admin.html      ← Admin page: device table, ADB connect, network scan
├── main.js             ← Electron main process (single-device path)
├── renderer.js         ← Electron renderer: WebCodecs decode
├── preload.js          ← Electron preload bridge
├── scrcpy-server.jar   ← scrcpy binary v3.3 (push ถ้าขนาดต่างกัน)
├── package.json        ← dependencies: ws, electron
├── ws_test.js          ← ทดสอบ WebSocket จาก Node client
├── .claude\
│   └── launch.json     ← preview server config
└── workflow.md         ← (ไฟล์นี้)
```

---

## Architecture Diagram

```
┌──────────────────────────────────────┐
│  Browser (50+ users)                 │
│  ┌─────────┐  ┌─────────┐           │
│  │ Canvas  │  │ Canvas  │  ...       │
│  │ device1 │  │ device2 │           │
│  └─────────┘  └─────────┘           │
│    WebSocket per device (ws://)      │
└───────────────┬──────────────────────┘
                │ HTTP + WebSocket port 3000
┌───────────────▼──────────────────────┐
│  Node.js server.js                   │
│  ┌────────────┐  ┌────────────┐      │
│  │DeviceAgent │  │DeviceAgent │ ...  │
│  │ serial=AAA │  │ serial=BBB │      │
│  │ port 54321 │  │ port 54323 │      │
│  └─────┬──────┘  └─────┬──────┘      │
└────────┼───────────────┼─────────────┘
         │ execFile/spawn adb
         │ TCP forward (ephemeral ports)
┌────────▼───────────────▼─────────────┐
│  ADB server (C:\platform-tools\)     │
└────────┬───────────────┬─────────────┘
         │ USB           │ USB
┌────────▼────┐   ┌──────▼──────┐
│ Android #1  │   │ Android #2  │  ...
│ scrcpy-svr  │   │ scrcpy-svr  │
│ H.264 enc   │   │ H.264 enc   │
└─────────────┘   └─────────────┘
```

**Fan-out:** DeviceAgent._broadcastBinary() → viewers Set → ทุก WS connection  
**Isolation:** แต่ละ DeviceAgent ได้ ephemeral port คนละคู่ → port ไม่ชน  
**Reconnect:** watchdog auto-restart 3s หลัง socket close/error  
**Control:** browser → WS → DeviceAgent._controlSock.write() → scrcpy control protocol

---

## MCP Server (boxphone-mcp-server)

ไฟล์: [`mcp-server.mjs`](mcp-server.mjs) — เชื่อม BoxPhone กับ Claude/AI agent ผ่าน Model Context Protocol

### วิธีรัน

```bash
# รันจาก project directory
node mcp-server.mjs

# กำหนด URL ถ้า server ไม่ได้รันที่ localhost:3000
BOXPHONE_URL=http://192.168.1.10:3000 node mcp-server.mjs
```

> ต้องรัน `node server.js` ก่อนเสมอ — MCP server คือ proxy ไปยัง REST API ของ BoxPhone

### ตั้งค่าใน Claude Code

เพิ่มใน `.claude/settings.json`:
```json
{
  "mcpServers": {
    "boxphone": {
      "command": "node",
      "args": ["D:\\app\\boxphone\\mcp-server.mjs"]
    }
  }
}
```

### Tools ที่ใช้ได้ทั้งหมด

#### Read-only
| Tool | Parameter | ทำอะไร |
|------|-----------|--------|
| `boxphone_list_devices` | — | ดูรายชื่ออุปกรณ์ทั้งหมด (serial, model, status) |
| `boxphone_get_status` | — | ดู server overview: uptime, memory, device agents |
| `boxphone_get_device_info` | `serial` | ดูรายละเอียด device: state, resolution, viewers, restart count |
| `boxphone_take_screenshot` | `serial` | ถ่าย screenshot → ได้ภาพ PNG |

#### Interaction (สั่งงานหน้าจอ)
| Tool | Parameter | ทำอะไร |
|------|-----------|--------|
| `boxphone_tap` | `serial, x, y` | แตะหน้าจอที่พิกัด pixel |
| `boxphone_swipe` | `serial, x1, y1, x2, y2, [duration]` | ปัดหน้าจอ (duration หน่วย ms ค่า default 300) |
| `boxphone_press_key` | `serial, keycode` | กดปุ่ม Android: HOME=3, BACK=4, MENU=82, POWER=26, VOL_UP=24, VOL_DOWN=25, ENTER=66, APP_SWITCH=187 |
| `boxphone_type_text` | `serial, text` | พิมพ์ข้อความ ASCII (non-ASCII ใช้ `boxphone_run_shell` แทน) |
| `boxphone_volume` | `serial, action` | ปรับเสียง: `up` / `down` / `mute` |

#### Shell & Package
| Tool | Parameter | ทำอะไร |
|------|-----------|--------|
| `boxphone_run_shell` | `serial, command` | รัน adb shell command อิสระ เช่น `pm list packages`, `dumpsys battery` |
| `boxphone_install_apk` | `serial, apkPath` | ติดตั้ง APK (path ต้องเข้าถึงได้จาก BoxPhone server) |

#### Device Management
| Tool | Parameter | ทำอะไร |
|------|-----------|--------|
| `boxphone_adb_connect` | `address` | เชื่อมต่อผ่าน WiFi รูปแบบ `ip:port` เช่น `192.168.1.100:5555` |
| `boxphone_adb_disconnect` | `serial` | ตัดการเชื่อมต่อ (destructive) |
| `boxphone_restart_agent` | `serial` | restart scrcpy agent เมื่อ stream ค้าง |
| `boxphone_reboot` | `serial` | reboot อุปกรณ์ — ไม่ตอบสนอง ~30–60 วิ (destructive) |
| `boxphone_scan_network` | — | สแกน subnet หา Android ที่เปิด ADB WiFi port 5555/5556 แล้ว connect อัตโนมัติ |

---

## Debug Commands

```bash
# รัน server
cd D:\app\boxphone
node server.js

# ทดสอบ WebSocket (watch + รับ frames)
node ws_test.js

# ทดสอบ multi-user
node test_multiuser.js

# ดู ADB devices
C:\platform-tools\adb.exe devices -l

# ดู abstract sockets บน device (ต้อง forward + launch ก่อน)
C:\platform-tools\adb.exe -s RFCT60M26MY shell "cat /proc/net/unix" | findstr scrcpy

# Kill scrcpy server บน device
C:\platform-tools\adb.exe -s RFCT60M26MY shell "pkill -f scrcpy"

# ตรวจ port ที่ถูก forward
C:\platform-tools\adb.exe -s RFCT60M26MY forward --list

# ลบ port forward ทั้งหมด
C:\platform-tools\adb.exe -s RFCT60M26MY forward --remove-all
```

---

## Known Issues

| ปัญหา | อาการ | Root cause | วิธีแก้ | สถานะ |
|-------|-------|-----------|---------|--------|
| Video socket ส่งแค่ dummy byte | `header buf len=1 hex=00` แล้วหยุด | scrcpy 3.2 protocol handshake ผิด | upgrade v3.3 + single-socket accept | ✅ แก้แล้ว (d2b5f32) |
| Abstract socket name ผิด | `check_sockets.js` ได้ "Aborted" | ขาด `adb forward` ก่อน launch | protocol fix ใน d2b5f32 | ✅ แก้แล้ว |
| `ws_test.js` ไม่ได้ module 'ws' | `Cannot find module 'ws'` | รัน script จาก dir ที่ไม่มี node_modules | รันจาก `D:\app\boxphone\` เสมอ | ℹ️ ข้อควรระวัง |
| ADB path ชี้ไปที่ platform-tools เดิม | spawn error | เปลี่ยน device setup | อัปเดตเป็น `C:\LDPlayer\LDPlayer9\adb.exe` | ✅ แก้แล้ว (bc02fe5) |
| Browser video decode ยังไม่ได้ยืนยัน | ยังไม่ได้ทดสอบ scrcpy v3.3 กับ WebCodecs | — | ทำ STEP 2 | ⏳ รอทดสอบ |
