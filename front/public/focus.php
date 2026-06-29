<?php
require_once __DIR__ . '/../../back/lib/auth.php';
$user = require_login();
$serial = $_GET['serial'] ?? '';
$isAudit = ($user['role'] === 'admin' && !user_owns_serial((int) $user['id'], $serial));
if ($serial === '' || ($user['role'] !== 'admin' && !user_owns_serial((int) $user['id'], $serial))) {
    http_response_code(403); echo 'Forbidden'; exit;
}
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
  <title>Catcat · <?= htmlspecialchars($serial) ?></title>
  <style>
    *{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
    html,body{margin:0;padding:0;width:100%;height:100%;overflow:hidden;background:#000;touch-action:none;font-family:system-ui,sans-serif}

    /* ── Loading spinner ── */
    .spinner{width:28px;height:28px;border-radius:50%;border:2.5px solid rgba(255,255,255,.12);border-top-color:#38bdf8;animation:spin .7s linear infinite}
    @keyframes spin{to{transform:rotate(360deg)}}

    /* ── Stats bar — bandwidth + latency, top-center ── */
    #stats-bar{
      position:fixed;top:8px;left:50%;transform:translateX(-50%);z-index:30;
      display:flex;align-items:center;gap:1px;
      pointer-events:none;user-select:none;
    }
    /* shared pill base */
    .stat-pill{
      display:flex;align-items:center;gap:5px;
      background:rgba(2,6,23,.6);border:1px solid rgba(255,255,255,.1);
      backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);
      padding:3px 10px 3px 8px;
      color:#94a3b8;font-size:10px;font-weight:500;white-space:nowrap;
    }
    #bw-pill{ border-radius:20px 4px 4px 20px; }
    #lat-pill{ border-radius:4px 20px 20px 4px; border-left:none; }

    /* status dot */
    .dot{width:6px;height:6px;border-radius:50%;flex-shrink:0;animation:pulse 2s ease-in-out infinite}
    #bw-pill  .dot{background:#22c55e}
    #bw-pill.warn  .dot{background:#f59e0b}
    #bw-pill.idle  .dot{background:#475569;animation:none}

    /* network RTT color states */
    #lat-val{font-weight:600;color:#22c55e;transition:color .3s}
    #lat-pill.med  #lat-val{color:#f59e0b}
    #lat-pill.high #lat-val{color:#ef4444}
    #lat-pill.idle #lat-val{color:#475569}
    /* label suffix */
    .pill-suffix{color:#475569;font-size:9px;margin-left:1px}

    @keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}
    #bw-val{color:#e2e8f0;font-weight:600}

    /* ── Nav toggle button — always visible, top-right ── */
    #nav-toggle{
      position:fixed;top:8px;right:10px;z-index:31;
      width:34px;height:34px;display:flex;align-items:center;justify-content:center;
      background:rgba(2,6,23,.65);border:1px solid rgba(255,255,255,.12);
      backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);
      border-radius:10px;cursor:pointer;color:#94a3b8;
      transition:background .15s,color .15s;
    }
    #nav-toggle:hover{background:rgba(255,255,255,.12);color:#f1f5f9}
    #nav-toggle svg{width:16px;height:16px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round}

    /* ── Nav panel — slides in from top-right ── */
    #nav-panel{
      position:fixed;top:50px;right:10px;z-index:30;
      width:220px;
      background:rgba(2,6,23,.92);border:1px solid rgba(255,255,255,.1);
      backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);
      border-radius:14px;padding:10px;
      display:flex;flex-direction:column;gap:6px;
      transform:translateY(-8px) scale(.97);opacity:0;pointer-events:none;
      transition:transform .2s ease,opacity .2s ease;
      user-select:none;
    }
    #nav-panel.open{transform:translateY(0) scale(1);opacity:1;pointer-events:all}

    /* panel sections */
    .np-row{display:flex;align-items:center;gap:6px}
    .np-label{font-size:10px;color:#475569;font-weight:600;text-transform:uppercase;letter-spacing:.06em;padding:4px 4px 2px;border-bottom:1px solid rgba(255,255,255,.06);margin-bottom:2px}
    .np-divider{height:1px;background:rgba(255,255,255,.06);margin:2px 0}

    /* icon buttons inside panel */
    .pbtn{
      flex:1;height:34px;display:flex;align-items:center;justify-content:center;gap:5px;
      border:none;background:rgba(255,255,255,.05);border-radius:8px;
      color:#94a3b8;cursor:pointer;font-size:11px;font-weight:500;
      transition:background .15s,color .15s;text-decoration:none;
    }
    .pbtn:hover{background:rgba(255,255,255,.12);color:#f1f5f9}
    .pbtn:active{background:rgba(255,255,255,.18)}
    .pbtn svg{width:14px;height:14px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;flex-shrink:0}
    .pbtn.danger:hover{background:rgba(239,68,68,.2);color:#fca5a5}

    /* device info in panel */
    #panel-serial{font-size:12px;font-weight:600;color:#e2e8f0;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    #panel-badge{font-size:10px;font-weight:700;color:#38bdf8;background:rgba(56,189,248,.12);border:1px solid rgba(56,189,248,.2);border-radius:4px;padding:1px 6px;flex-shrink:0}

    /* resolution select */
    #sel-size{
      flex:1;height:32px;appearance:none;-webkit-appearance:none;
      background:rgba(255,255,255,.07)
        url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M1 1l4 4 4-4' stroke='%2394a3b8' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E")
        no-repeat right 8px center;
      border:1px solid rgba(255,255,255,.1);border-radius:8px;
      color:#e2e8f0;font-size:12px;font-weight:500;padding:0 28px 0 10px;cursor:pointer;
    }
    #sel-size:focus{outline:none;border-color:#38bdf8}
    #sel-size option{background:#0f172a;color:#e2e8f0}

    /* Android nav buttons in panel */
    .anav-btn{
      flex:1;height:38px;display:flex;align-items:center;justify-content:center;
      border:none;background:rgba(255,255,255,.05);border-radius:8px;
      color:rgba(203,213,225,.8);cursor:pointer;
      transition:background .15s,color .15s;
    }
    .anav-btn:hover{background:rgba(255,255,255,.12);color:#f1f5f9}
    .anav-btn:active{background:rgba(255,255,255,.2)}
    .anav-btn svg{width:18px;height:18px;stroke:currentColor;fill:none;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
  </style>
</head>
<body>

  <?php if ($isAudit): ?>
  <div style="position:fixed;top:0;left:0;right:0;z-index:100;background:rgba(255,159,10,.18);border-bottom:1px solid rgba(255,159,10,.4);padding:5px 12px;font-size:11px;font-weight:700;color:#ffb340;letter-spacing:.3px;text-align:center;">
    <span style="pointer-events:none;">AUDIT MODE — <?= htmlspecialchars($serial) ?> — </span><a href="/admin_dashboard.php?tab=sessions" style="color:#ffb340;text-decoration:underline;">กลับ Admin</a>
  </div>
  <?php endif; ?>

  <!-- Stats bar: bandwidth + latency — always on screen, top-center -->
  <div id="stats-bar" style="<?= $isAudit ? 'top:38px;' : '' ?>">
    <div id="bw-pill" class="stat-pill idle">
      <span class="dot"></span>
      <span id="bw-val">—</span>
    </div>
    <div id="lat-pill" class="stat-pill idle">
      <span id="lat-val">—</span>
      <span class="pill-suffix">ms  net</span>
    </div>
  </div>

  <!-- Nav toggle — top-right corner -->
  <button id="nav-toggle" aria-label="เมนู">
    <svg id="icon-menu" viewBox="0 0 24 24"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
    <svg id="icon-close" viewBox="0 0 24 24" style="display:none"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
  </button>

  <!-- Nav panel -->
  <nav id="nav-panel">
    <!-- Device info -->
    <div class="np-label">อุปกรณ์</div>
    <div class="np-row">
      <span id="panel-serial">Loading…</span>
      <span id="panel-badge">—</span>
    </div>

    <div class="np-divider"></div>

    <!-- Resolution -->
    <div class="np-label">ความละเอียด</div>
    <div class="np-row">
      <select id="sel-size" title="ความละเอียด" aria-label="ความละเอียด">
        <option value="480">480</option>
        <option value="720" selected>720</option>
        <option value="1080">1080</option>
      </select>
    </div>

    <div class="np-divider"></div>

    <!-- Controls -->
    <div class="np-label">ควบคุม</div>
    <div class="np-row">
      <button id="btn-reconnect" class="pbtn" title="เชื่อมต่อใหม่">
        <svg viewBox="0 0 24 24"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
        เชื่อมใหม่
      </button>
      <button id="btn-fs" class="pbtn" title="เต็มจอ">
        <svg id="fs-icon-expand" viewBox="0 0 24 24"><polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/><line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/></svg>
        <svg id="fs-icon-shrink" viewBox="0 0 24 24" style="display:none"><polyline points="4 14 10 14 10 20"/><polyline points="20 10 14 10 14 4"/><line x1="10" y1="14" x2="3" y2="21"/><line x1="21" y1="3" x2="14" y2="10"/></svg>
        เต็มจอ
      </button>
    </div>

    <div class="np-divider"></div>

    <!-- Android nav -->
    <div class="np-label">Android</div>
    <div class="np-row">
      <button class="anav-btn" data-key="back" title="ย้อนกลับ">
        <svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
      </button>
      <button class="anav-btn" data-key="home" title="หน้าหลัก">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8"/></svg>
      </button>
      <button class="anav-btn" data-key="recent" title="แอปล่าสุด">
        <svg viewBox="0 0 24 24"><rect x="4" y="4" width="16" height="16" rx="2"/></svg>
      </button>
    </div>

    <div class="np-divider"></div>

    <!-- Navigate away -->
    <div class="np-row">
      <a id="btn-grid" href="/" class="pbtn">
        <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
        อุปกรณ์อื่น
      </a>
      <a id="btn-back" href="/" class="pbtn danger">
        <svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
        กลับ
      </a>
    </div>
  </nav>

  <!-- Canvas (full viewport) -->
  <main id="stage" style="position:fixed;inset:0;display:flex;align-items:center;justify-content:center;background:#000">
    <canvas id="screen" width="720" height="1600" style="display:block;margin:auto;max-width:100%;max-height:100%"></canvas>
    <div id="msg" style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px;color:#64748b;font-size:13px">
      <div class="spinner"></div>
      <span id="msg-text">Loading…</span>
    </div>
  </main>

  <span id="fps" style="display:none"></span>

  <script>
    const SERIAL = <?= json_encode($serial) ?>;
    const canvas  = document.getElementById('screen');
    const ctx     = canvas.getContext('2d');
    const stage   = document.getElementById('stage');
    const msg     = document.getElementById('msg');
    const msgText = document.getElementById('msg-text');
    const fpsEl   = document.getElementById('fps');
    const panelSerial = document.getElementById('panel-serial');
    const panelBadge  = document.getElementById('panel-badge');

    // ── Nav panel toggle ────────────────────────────────────────────
    const navPanel  = document.getElementById('nav-panel');
    const navToggle = document.getElementById('nav-toggle');
    const iconMenu  = document.getElementById('icon-menu');
    const iconClose = document.getElementById('icon-close');
    function setPanelOpen(open){
      navPanel.classList.toggle('open', open);
      iconMenu.style.display  = open ? 'none' : '';
      iconClose.style.display = open ? ''     : 'none';
    }
    navToggle.addEventListener('click', ()=> setPanelOpen(!navPanel.classList.contains('open')));
    // Close panel when clicking outside
    document.addEventListener('pointerdown', e=>{
      if(!navPanel.contains(e.target) && e.target !== navToggle) setPanelOpen(false);
    });

    // ── Bandwidth meter ─────────────────────────────────────────────
    const bwPill  = document.getElementById('bw-pill');
    const bwVal   = document.getElementById('bw-val');
    const latPill = document.getElementById('lat-pill');
    const latVal  = document.getElementById('lat-val');

    let bwBytes = 0, bwLast = performance.now();
    // Network RTT: EMA of HTTP ping round-trip to /ping.php
    let rttEma = 0;
    const EMA_A = 0.2;

    // ── Bandwidth interval ──
    setInterval(()=>{
      const elapsed = (performance.now() - bwLast) / 1000;
      const kbps = bwBytes / elapsed / 1024;
      bwLast = performance.now(); bwBytes = 0;
      if(kbps < 1){
        bwVal.textContent='—'; bwPill.className='stat-pill idle';
      } else {
        bwVal.textContent = kbps >= 1024
          ? (kbps/1024).toFixed(1)+' Mbps'
          : kbps.toFixed(0)+' KB/s';
        bwPill.className = 'stat-pill' + (kbps < 200 ? ' warn' : '');
      }
    }, 1000);

    // ── Network RTT ping loop ──
    async function pingLoop(){
      while(true){
        try{
          const t0 = performance.now();
          await fetch('/ping.php', {cache:'no-store'});
          const rtt = performance.now() - t0;
          rttEma = rttEma < 1 ? rtt : rttEma + EMA_A * (rtt - rttEma);
          latVal.textContent = Math.round(rttEma);
          latPill.className  = 'stat-pill' + (rttEma>100?' high':rttEma>50?' med':'');
        }catch(_){
          latVal.textContent='—'; latPill.className='stat-pill idle';
        }
        await new Promise(r=>setTimeout(r, 1000));
      }
    }
    pingLoop();

    // ── Video decoder ───────────────────────────────────────────────
    let ws = null, decoder = null, gotKey = false;
    let firstFrame = false, curSize = 720;
    // Parameter sets: h264 uses sps+pps; h265 uses vps+sps+pps
    let paramSets = [];       // array of raw NALU Uint8Arrays (with start codes)
    let streamCodec = 'h264'; // updated from meta message

    // Codec IDs from scrcpy handshake
    const CODEC_H264 = 0x68323634;
    const CODEC_H265 = 0x68323635;

    function stripStart(n){
      if(n[0]===0&&n[1]===0&&n[2]===0&&n[3]===1) return n.slice(4);
      if(n[0]===0&&n[1]===0&&n[2]===1) return n.slice(3);
      return n;
    }

    function configureDecoder(){
      if(!paramSets.length) return;
      if(decoder&&decoder.state!=='closed') decoder.close();
      gotKey = false;

      let codec;
      if(streamCodec === 'h265'){
        // H.265: use standard HEVC codec string (Main profile, Level 4)
        codec = 'hev1.1.6.L120.B0';
      } else {
        // H.264: derive codec string from SPS bytes
        const sps = paramSets.find(p => (stripStart(p)[0] & 0x1f) === 7);
        if(!sps) return;
        const s = stripStart(sps);
        codec = `avc1.${s[1].toString(16).padStart(2,'0')}${s[2].toString(16).padStart(2,'0')}${s[3].toString(16).padStart(2,'0')}`;
      }

      decoder = new VideoDecoder({
        output: (frame)=>{
          if(canvas.width!==frame.displayWidth || canvas.height!==frame.displayHeight){
            canvas.width=frame.displayWidth; canvas.height=frame.displayHeight;
            applySize();
          }
          ctx.drawImage(frame, 0, 0);
          frame.close();
          if(!firstFrame){ firstFrame=true; hideLoading(); applySize(); }
        },
        error: (e)=>{ console.warn('decoder error', e); gotKey=false; }
      });
      decoder.configure({codec, optimizeForLatency:true});
    }

    function splitNALUs(data){
      const nalus=[]; let start=-1;
      for(let i=0;i<data.length-2;i++){
        const is3=data[i]===0&&data[i+1]===0&&data[i+2]===1;
        const is4=i<data.length-3&&data[i]===0&&data[i+1]===0&&data[i+2]===0&&data[i+3]===1;
        if(is3||is4){ if(start>=0) nalus.push(data.slice(start,i)); start=i; i+=is4?3:2; }
      }
      if(start>=0) nalus.push(data.slice(start));
      return nalus;
    }

    // Get NAL unit type — H.264 uses 1-byte header, H.265 uses 2-byte header
    function nalType(raw){
      if(streamCodec === 'h265') return (raw[0] >> 1) & 0x3f;
      return raw[0] & 0x1f;
    }

    // H.264: SPS=7, PPS=8, IDR=5
    // H.265: VPS=32, SPS=33, PPS=34, IDR_W_RADL=19, IDR_N_LP=20
    function isParamSet(t){ return streamCodec==='h265' ? t>=32&&t<=34 : t===7||t===8; }
    function isIDR(t){       return streamCodec==='h265' ? t===19||t===20 : t===5; }

    function feed(data){
      let needReconf=false, hasIDR=false;
      for(const nalu of splitNALUs(data)){
        const raw=stripStart(nalu); if(!raw.length) continue;
        const t=nalType(raw);
        if(isParamSet(t)){
          // Replace or add param set (keyed by type)
          const idx = paramSets.findIndex(p => nalType(stripStart(p)) === t);
          if(idx>=0) paramSets[idx]=nalu; else paramSets.push(nalu);
          needReconf=true;
        } else if(isIDR(t)) hasIDR=true;
      }
      if(needReconf) configureDecoder();
      if(!decoder||decoder.state!=='configured') return;
      if(hasIDR) gotKey=true;
      if(!gotKey) return;
      if(decoder.decodeQueueSize>1) return;
      // Prepend param sets before every IDR so decoder never loses context
      let chunk=data;
      if(hasIDR&&paramSets.length){
        const total=paramSets.reduce((s,p)=>s+p.length,0)+data.length;
        const c=new Uint8Array(total); let off=0;
        for(const p of paramSets){ c.set(p,off); off+=p.length; }
        c.set(data,off); chunk=c;
      }
      try{ decoder.decode(new EncodedVideoChunk({type:hasIDR?'key':'delta',timestamp:performance.now()*1000,data:chunk})); }
      catch(e){ console.warn('decode',e.message); }
    }

    // ── Touch / mouse → device (binary scrcpy protocol) ─────────────
    // Builds a 32-byte scrcpy v2 INJECT_TOUCH_EVENT message and sends it
    // as a binary WebSocket frame. No JSON parse needed on the Rust side.
    function buildTouchMsg(action, pointerId, absX, absY, sw, sh, isDown) {
      const buf = new ArrayBuffer(32);
      const v = new DataView(buf);
      v.setUint8(0, 0x02);                        // type: INJECT_TOUCH_EVENT
      v.setUint8(1, action);                      // 0=down,1=up,2=move
      v.setBigUint64(2, BigInt(pointerId), false);// pointerId (8 bytes)
      v.setUint32(10, absX, false);               // x absolute pixels
      v.setUint32(14, absY, false);               // y absolute pixels
      v.setUint16(18, sw, false);                 // screenWidth
      v.setUint16(20, sh, false);                 // screenHeight
      v.setUint16(22, isDown ? 0xFFFF : 0, false);// pressure
      v.setUint32(24, 0, false);                  // actionButton
      v.setUint32(28, 0, false);                  // buttons
      return buf;
    }

    function sendTouchPoint(action, clientX, clientY, pointerId) {
      if(!ws || ws.readyState !== WebSocket.OPEN) return;
      // Drop input if WebSocket is backlogged — prevents stale event queue
      if(ws.bufferedAmount > 8192) return;
      const r = canvas.getBoundingClientRect();
      const nx = (clientX - r.left) / r.width;
      const ny = (clientY - r.top) / r.height;
      if(nx < 0 || nx > 1 || ny < 0 || ny > 1) return;
      const sw = canvas.width, sh = canvas.height;
      const buf = buildTouchMsg(action, pointerId & 0xF, Math.round(nx*sw), Math.round(ny*sh), sw, sh, action !== 1);
      ws.send(buf);
    }

    // Throttle move events to 60fps (16ms) per pointer to avoid flooding WS
    const moveTimers = {};
    function onMove(clientX, clientY, pointerId) {
      const now = performance.now();
      if(moveTimers[pointerId] && now - moveTimers[pointerId] < 16) return;
      moveTimers[pointerId] = now;
      sendTouchPoint(2, clientX, clientY, pointerId);
    }

    // Pointer events cover both mouse and touch with multi-touch support
    canvas.addEventListener('pointerdown', e => {
      canvas.setPointerCapture(e.pointerId);
      e.preventDefault();
      sendTouchPoint(0, e.clientX, e.clientY, e.pointerId);
    }, {passive:false});
    canvas.addEventListener('pointerup', e => {
      e.preventDefault();
      sendTouchPoint(1, e.clientX, e.clientY, e.pointerId);
      delete moveTimers[e.pointerId];
    }, {passive:false});
    canvas.addEventListener('pointermove', e => {
      if(e.buttons === 0) return;
      e.preventDefault();
      // Use coalesced events for smoother swipe paths
      const evts = e.getCoalescedEvents ? e.getCoalescedEvents() : [e];
      for(const ce of evts) onMove(ce.clientX, ce.clientY, e.pointerId);
    }, {passive:false});
    canvas.addEventListener('pointercancel', e => {
      sendTouchPoint(1, e.clientX, e.clientY, e.pointerId);
      delete moveTimers[e.pointerId];
    });

    // ── Loading overlay ─────────────────────────────────────────────
    function showLoading(text){ msgText.textContent=text||'Loading…'; msg.style.display='flex'; }
    function hideLoading(){ msg.style.display='none'; }

    // ── Canvas sizing ───────────────────────────────────────────────
    function applySize(){
      const aspect = canvas.width / canvas.height;
      const vw = stage.clientWidth, vh = stage.clientHeight;
      let w, h;
      if(aspect > vw/vh){ w=vw; h=vw/aspect; } else { h=vh; w=vh*aspect; }
      const shortFit = Math.min(w,h);
      if(shortFit > curSize){ const s=curSize/shortFit; w*=s; h*=s; }
      canvas.style.width=w+'px'; canvas.style.height=h+'px'; canvas.style.margin='auto';
      canvas.classList.remove('max-w-full','max-h-full');
      stage.style.overflow = (w>vw||h>vh)?'auto':'hidden';
    }
    window.addEventListener('resize', applySize);

    // ── WebSocket session ───────────────────────────────────────────
    async function start(size=curSize){
      if(typeof VideoDecoder==='undefined'){ showLoading('เบราว์เซอร์ไม่รองรับ WebCodecs'); return; }
      curSize=size; firstFrame=false; showLoading('Loading…');
      try{
        // Always encode at max quality (1080p). curSize controls only display size.
        const res  = await fetch('/session.php?serial='+encodeURIComponent(SERIAL)+'&size=1080');
        const data = await res.json();
        if(!data.session_id) throw new Error(data.error||'no session');
        const proto = location.protocol==='https:'?'wss:':'ws:';
        ws = new WebSocket(`${proto}//${location.host}/ws/${data.session_id}`);
        ws.binaryType = 'arraybuffer';
        ws.onopen  = ()=>{ panelSerial.textContent=SERIAL; panelBadge.textContent=curSize+'p'; paramSets=[]; gotKey=false; };
        ws.onclose = ()=>{
          panelBadge.textContent='–';
          bwVal.textContent='—'; bwPill.className='stat-pill idle';
          firstFrame=false; showLoading('กำลังเชื่อมต่อใหม่…');
          setTimeout(()=>start(curSize), 2000);
        };
        ws.onerror = ()=>{ panelBadge.textContent='!'; };
        ws.onmessage = (e)=>{
          if(typeof e.data === 'string'){
            // Meta frame: {"type":"meta","codec":1751476789,"width":...,"height":...}
            try{
              const m = JSON.parse(e.data);
              if(m.type === 'meta'){
                streamCodec = m.codec === 0x68323635 ? 'h265' : 'h264';
                console.log('[catcat] stream codec:', streamCodec, m.width+'x'+m.height);
              }
            }catch(_){}
            return;
          }
          bwBytes += e.data.byteLength;
          feed(new Uint8Array(e.data));
        };
      }catch(e){ showLoading('เชื่อมต่อไม่สำเร็จ: '+e.message); }
    }

    // ── Event wiring ────────────────────────────────────────────────
    document.getElementById('sel-size').addEventListener('change', function(){
      const s=parseInt(this.value,10); if(s===curSize) return;
      if(ws){ ws.onclose=null; try{ws.close()}catch(_){} }
      start(s);
    });

    document.querySelectorAll('.anav-btn').forEach(b=>{
      b.addEventListener('click', ()=>{
        fetch('/key.php?serial='+encodeURIComponent(SERIAL)+'&key='+b.dataset.key,{method:'POST'}).catch(()=>{});
      });
    });

    document.getElementById('btn-reconnect').addEventListener('click',()=>{
      if(ws){ ws.onclose=null; try{ws.close()}catch(_){} }
      start(curSize);
    });

    const fsExpand = document.getElementById('fs-icon-expand');
    const fsShrink = document.getElementById('fs-icon-shrink');
    document.getElementById('btn-fs').addEventListener('click',()=>{
      if(!document.fullscreenElement) document.documentElement.requestFullscreen?.();
      else document.exitFullscreen?.();
    });
    document.addEventListener('fullscreenchange',()=>{
      const fs=!!document.fullscreenElement;
      fsExpand.style.display=fs?'none':''; fsShrink.style.display=fs?'':'none';
    });

    function goHome(e){ e.preventDefault(); showLoading('Loading…'); location.href='/'; }
    document.getElementById('btn-grid').addEventListener('click', goHome);
    document.getElementById('btn-back').addEventListener('click', goHome);

    start();
  </script>
</body>
</html>
