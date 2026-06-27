# Panda — Roadmap (พอร์ตจาก workflow.md มาที่ Rust/Axum)

> ที่มา: `workflow.md` เป็นแผนของโปรเจกต์ **BoxPhone (Node.js)** ทุก `server.js:NNN` / `app.js:NNN`
> ในไฟล์นั้น **ไม่มีอยู่จริงใน panda** ไฟล์นี้คือแผนเดียวกันแต่แม็ปกับโค้ด Rust จริง
> หลักการ: **ทำฐานให้มั่นก่อน แล้วค่อยสเกล** (อย่า over-engineer farm/auth ตอนนี้)

เป้าหมายปลายทางยังเหมือนเดิม: cloud-phone rental — 1 user คุมหลาย ADB ผ่านเว็บ, สเกล 100–200 เครื่อง

---

## A. Gap Analysis — panda วันนี้ vs แผน

panda **ดิบกว่า** BoxPhone โดยรวม แต่ **ล้ำกว่า 1 จุด** (lazy start ฝั่ง server)

| ความต้องการ (จาก workflow.md) | panda วันนี้ | สถานะ | จุดในโค้ด |
|---|---|---|---|
| สตรีม + decode 1 เครื่องในเบราว์เซอร์ | WebCodecs ครบ: SPS/PPS, rotate, focus overlay | ✅ wired (ยังไม่ verify บน hardware) | `static/index.html` |
| Touch / key control | ส่ง normalized coord → server inject 32-byte event | ✅ wired (มีบั๊ก geometry ด้านล่าง) | `index.html:413`, `scrcpy/mod.rs:176` |
| Lazy streaming (เปิดเท่าที่ดู) | scrcpy เริ่มตอน WS connect เท่านั้น | ✅ ฝั่ง server (แต่ client เปิดทุก card) | `main.rs:129` / `index.html:207` |
| **หลายเครื่องพร้อมกัน** | `SCRCPY_PORT=27183` + `scid=32` เป็น const | ❌ **ชน host port** สองเครื่องสตรีมพร้อมกันไม่ได้ | `scrcpy/mod.rs:14,57,63` |
| Touch ใช้ res จริงของเครื่อง | handshake อ่าน w/h แต่ `send_input` hardcode 720×336 | ❌ บั๊ก + บล็อก feature เลือก res | `scrcpy/mod.rs:104-106` vs `182-192` |
| Grid = thumbnail (ไม่ decode) | ทุก card สร้าง `VideoDecoder` เอง | ❌ anti-pattern เดียวกับที่ workflow.md เตือน | `index.html:333`, `renderCards 207` |
| เลือกความละเอียด streaming | `max_size=720` `video_bit_rate=4000000` hardcode | ❌ เปลี่ยนรายเครื่องไม่ได้ | `scrcpy/mod.rs:63` |
| Thumbnail / screenshot | ไม่มี endpoint | ❌ | — |
| WS multiplex (1 เส้น/browser) | 1 session = 1 WS = 1 device (UUID-keyed) | ❌ (ยังไม่จำเป็นจนกว่าจะทำ grid) | `main.rs:63,109` |
| Hot-plug detection | ต้องกด Refresh เอง | ❌ | `index.html:155` |
| Admin / provider dashboard | ไม่มี | ❌ | — |
| Auth / multi-tenant / control-lock | ไม่มี (ใครก็ control ได้) | ❌ (P0 ตอนเริ่มให้เช่าจริง) | ทั้ง server |

**สรุป:** ฐาน 1-เครื่องเกือบครบ แต่ "หลายเครื่อง" ถูกบล็อกด้วย const 2 ตัว ที่เหลือ (grid/thumbnail,
res, dashboard, auth) คือ feature ใหม่จริง ๆ

---

## B. สถาปัตยกรรมเป้าหมาย (ยกมาจาก workflow.md §B — ยังถูกต้องสำหรับ panda)

**2 โหมดสตรีม** คือกุญแจการสเกล:

```
GRID MODE (ดูหลายเครื่อง)            FOCUS MODE (กดดูตัวเดียว)
─ JPEG thumbnail ~2–5 fps, <img>     ─ full H.264 + 1 VideoDecoder
─ ไม่มี decoder ต่อ card              ─ เลือก res ได้, low-latency
─ server: screencap pipe             ─ scrcpy เปิดเฉพาะตอน focus
```

เหตุผล: เบราว์เซอร์รัน `VideoDecoder` พร้อมกันได้จริง ~8–16 ตัว → grid 100 เครื่องต้องเป็นภาพนิ่ง

panda ได้เปรียบ: ฝั่ง server lazy อยู่แล้ว เหลือแค่ทำให้ **client** ไม่เปิด decoder ใน grid

---

## C. Backlog ของ panda (เรียงใหม่ — foundation-first)

### P0 — ฐาน (ปลดบล็อกก่อนทำอย่างอื่น)
1. **Verify ฐาน 1 เครื่อง** — รัน panda จริง, ยืนยัน video decode + tap ทำงานในเบราว์เซอร์
   (workflow.md STEP 2–3) ทุกอย่างหลังจากนี้ตั้งอยู่บนข้อนี้
2. **Per-session port + scid** — เลิกใช้ `SCRCPY_PORT`/`scid=32` const → จัดสรร ephemeral port +
   scid ต่อ session ปลดบล็อกหลายเครื่อง (`scrcpy/mod.rs`) **เล็กแต่เป็นรากของทุกอย่าง**
3. **Touch ใช้ geometry จริง** — เก็บ width/height จาก handshake ใส่ `ScrcpySession`,
   ให้ `send_input` ใช้ค่านั้นแทน 720/336 hardcode (`scrcpy/mod.rs:182-192`)

### P1 — สเกล grid
4. **Screenshot endpoint** — `GET /api/screenshot/:serial` ผ่าน `adb exec-out screencap -p` (pipe ตรง ไม่เขียนไฟล์)
5. **Grid = thumbnail** — `renderCards` ไม่ auto-`connect()`; card แสดง `<img>` refresh; เปิด decoder เฉพาะ focus
6. **เลือกความละเอียด** — `start()` รับ `max_size`/`bit_rate` เป็น param (default Low/Med/High ตาม workflow.md §D); เปลี่ยน res = relaunch + debounce
7. **Auto-stop agent เมื่อไม่มี viewer** — คืน USB/CPU

### P2 — farm / rental (ทำเมื่อจะให้เช่าจริง)
8. **WS multiplex** — `[u32 serial-id][payload]` 1 WS ต่อ browser (จำเป็นเมื่อ grid โต)
9. **Auth + device ownership/lease + control-lock** — กันผู้เช่าแย่งเครื่อง (workflow.md §E2/§I.2)
10. **Provider dashboard** — ตาราง status ล้วน ไม่เปิด decoder (workflow.md §I)
11. **แยก Frontend/Backend หลาย process** — เมื่อชนเพดาน USB/CPU ของ 1 PC (workflow.md §E/§G)

> ของที่ workflow.md ขึ้น ✅ (admin page, hot-plug, MCP server, layout preset) **ยังไม่มีใน panda**
> ถ้าต้องการให้ย้ายเข้า backlog แยก — ไม่ใช่ทางวิกฤตของการสเกล

---

## D. ก้าวแรกที่แนะนำ

เริ่มที่ **P0 #1 (verify ฐาน)** เพราะถ้า decode/touch ยังไม่ทำงานจริง การไปทำ per-session port
ก็ยังพิสูจน์ไม่ได้ ลำดับ:

```
P0#1 verify 1 เครื่อง   → check: เห็นภาพ + tap ติด ในเบราว์เซอร์
P0#2 per-session port   → check: 2 เครื่องสตรีมพร้อมกัน ไม่ชน 27183
P0#3 touch geometry     → check: tap มุมขวาล่างไปตรงมุมขวาล่างของเครื่องจริง
```

ทำ P0 ครบ = "หลายเครื่อง + control แม่นยำ" ซึ่งเป็นรากของ grid/focus/rental ทั้งหมด
