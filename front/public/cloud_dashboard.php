<?php
require_once __DIR__ . '/../../back/lib/auth.php';
require_once __DIR__ . '/../../back/lib/adb.php';
$user = require_login();

$st = db()->prepare(
    'SELECT d.serial, d.label, l.expires_at
       FROM leases l JOIN devices d ON d.serial = l.serial
      WHERE l.user_id = ? AND (l.expires_at IS NULL OR l.expires_at > NOW())
      ORDER BY d.label, d.serial'
);
$st->execute([$user['id']]);
$myDevices = $st->fetchAll();

try { $online = adb_list_devices(); } catch (Throwable $e) { $online = []; }

$refresh = (int) config()['thumb_refresh_ms'];
$initial = strtoupper(substr($user['username'], 0, 1));
$isAdmin = ($user['role'] === 'admin');
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
  <title>Catcat — เครื่องของฉัน</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      --bg:          #1c1c1e;
      --bg-elevated: rgba(28,28,30,.9);
      --bg-card:     rgba(44,44,46,.7);
      --bg-hover:    rgba(255,255,255,.06);
      --border:      rgba(255,255,255,.08);
      --border-soft: rgba(255,255,255,.05);
      --text:        #f5f5f7;
      --text-2:      rgba(235,235,245,.6);
      --text-3:      rgba(235,235,245,.35);
      --accent:      #0a84ff;
      --green:       #30d158;
      --red:         #ff453a;
      --sidebar-w:   220px;
      --header-h:    52px;
    }

    html, body { height: 100%; }

    body {
      background: var(--bg);
      color: var(--text);
      font-family: -apple-system, BlinkMacSystemFont, "SF Pro Display", "Helvetica Neue", Arial, sans-serif;
      -webkit-font-smoothing: antialiased;
      display: flex;
      flex-direction: column;
      overflow: hidden;
    }

    /* ── Header ─────────────────────────────────────── */
    header {
      height: var(--header-h);
      flex-shrink: 0;
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 0 16px;
      background: rgba(28,28,30,.85);
      backdrop-filter: blur(24px) saturate(180%);
      -webkit-backdrop-filter: blur(24px) saturate(180%);
      border-bottom: 1px solid var(--border);
      position: relative;
      z-index: 100;
    }

    .header-left {
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .hamburger {
      display: none;
      background: none;
      border: none;
      color: var(--text-2);
      cursor: pointer;
      padding: 6px;
      border-radius: 6px;
    }
    .hamburger:hover { background: var(--bg-hover); color: var(--text); }
    .hamburger svg { display: block; }

    .logo {
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 16px;
      font-weight: 600;
      color: var(--text);
      letter-spacing: -.2px;
    }

    .logo-icon {
      width: 28px; height: 28px;
      border-radius: 7px;
      background: linear-gradient(145deg,#2a2a2e,#1a1a1e);
      border: 1px solid rgba(255,255,255,.12);
      display: flex; align-items: center; justify-content: center;
      font-size: 15px;
    }

    .header-actions {
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .hdr-btn {
      display: flex; align-items: center; justify-content: center;
      width: 32px; height: 32px;
      border: none; border-radius: 8px;
      background: var(--bg-hover);
      color: var(--text-2);
      cursor: pointer;
      text-decoration: none;
      transition: background .15s, color .15s;
    }
    .hdr-btn:hover { background: rgba(255,255,255,.1); color: var(--text); }

    .badge {
      font-size: 11px;
      font-weight: 600;
      color: var(--text-3);
      letter-spacing: .4px;
    }

    /* ── Layout ──────────────────────────────────────── */
    .layout {
      display: flex;
      flex: 1;
      overflow: hidden;
    }

    /* ── Sidebar ─────────────────────────────────────── */
    .sidebar {
      width: var(--sidebar-w);
      flex-shrink: 0;
      background: rgba(28,28,30,.75);
      backdrop-filter: blur(40px) saturate(160%);
      -webkit-backdrop-filter: blur(40px) saturate(160%);
      border-right: 1px solid var(--border);
      display: flex;
      flex-direction: column;
      padding: 16px 10px;
      gap: 4px;
      overflow-y: auto;
      transition: transform .28s cubic-bezier(.4,0,.2,1);
      z-index: 90;
    }

    /* Profile block */
    .profile-block {
      padding: 12px;
      background: var(--bg-card);
      border: 1px solid var(--border);
      border-radius: 12px;
      margin-bottom: 8px;
    }

    .profile-row {
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .avatar {
      width: 36px; height: 36px;
      border-radius: 50%;
      background: rgba(10,132,255,.22);
      color: var(--accent);
      font-size: 15px;
      font-weight: 700;
      display: flex; align-items: center; justify-content: center;
      flex-shrink: 0;
    }

    .profile-name { font-size: 14px; font-weight: 600; color: var(--text); }
    .profile-role { font-size: 11px; color: var(--text-3); margin-top: 1px; }

    .profile-stat {
      display: flex;
      align-items: baseline;
      gap: 5px;
      margin-top: 10px;
      padding-top: 10px;
      border-top: 1px solid var(--border-soft);
    }

    .stat-num { font-size: 20px; font-weight: 700; color: var(--text); }
    .stat-label { font-size: 12px; color: var(--text-3); }

    .btn-rent {
      display: block;
      width: 100%;
      margin-top: 10px;
      padding: 8px;
      border: none;
      border-radius: 8px;
      background: rgba(10,132,255,.18);
      color: var(--accent);
      font-size: 13px;
      font-weight: 600;
      font-family: inherit;
      cursor: pointer;
      text-align: center;
      text-decoration: none;
      transition: background .15s;
    }
    .btn-rent:hover { background: rgba(10,132,255,.28); }

    /* Nav */
    .nav-section-label {
      font-size: 11px;
      font-weight: 600;
      color: var(--text-3);
      letter-spacing: .5px;
      text-transform: uppercase;
      padding: 10px 6px 4px;
    }

    .nav-item {
      display: flex;
      align-items: center;
      gap: 9px;
      padding: 9px 10px;
      border-radius: 8px;
      font-size: 14px;
      font-weight: 500;
      color: var(--text-2);
      text-decoration: none;
      cursor: pointer;
      transition: background .12s, color .12s;
    }
    .nav-item:hover { background: var(--bg-hover); color: var(--text); }
    .nav-item.active { background: rgba(10,132,255,.18); color: var(--accent); font-weight: 600; }

    .nav-item svg { width: 16px; height: 16px; flex-shrink: 0; opacity: .8; }
    .nav-item.active svg { opacity: 1; }

    .nav-spacer { flex: 1; min-height: 16px; }

    /* ── Main ────────────────────────────────────────── */
    main {
      flex: 1;
      overflow-y: auto;
      padding: 24px;
      background: var(--bg);
    }

    .page-title {
      font-size: 22px;
      font-weight: 700;
      color: var(--text);
      letter-spacing: -.4px;
      margin-bottom: 20px;
    }

    /* Device grid */
    #cards {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
      gap: 16px;
    }

    #empty {
      grid-column: 1/-1;
      padding: 80px 20px;
      text-align: center;
      color: var(--text-3);
      font-size: 14px;
    }
    #empty strong { display: block; font-size: 17px; color: var(--text-2); margin-bottom: 8px; }

    /* Device card */
    .device-card {
      display: flex;
      flex-direction: column;
      background: var(--bg-card);
      border: 1px solid var(--border);
      border-radius: 16px;
      overflow: hidden;
      text-decoration: none;
      color: inherit;
      transition: border-color .18s, box-shadow .18s, transform .12s;
    }
    .device-card.online:hover {
      border-color: rgba(10,132,255,.4);
      box-shadow: 0 8px 32px rgba(10,132,255,.12);
      transform: translateY(-1px);
    }
    .device-card.offline { opacity: .45; pointer-events: none; }

    .card-info {
      padding: 14px 16px 12px;
    }

    .card-top {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 6px;
    }

    .card-conn {
      font-size: 11px;
      font-weight: 600;
      color: var(--text-3);
      letter-spacing: .5px;
      text-transform: uppercase;
    }

    .card-timer {
      font-size: 12px;
      font-weight: 700;
      color: var(--accent);
      font-variant-numeric: tabular-nums;
      letter-spacing: .3px;
      transition: color .3s;
    }
    .card-timer.warn   { color: var(--orange); }
    .card-timer.urgent { color: var(--red); animation: pulse-timer 1s ease-in-out infinite; }
    @keyframes pulse-timer {
      0%, 100% { opacity: 1; }
      50%       { opacity: .5; }
    }

    .card-label {
      font-size: 15px;
      font-weight: 600;
      color: var(--text);
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .card-serial {
      font-size: 10px;
      color: var(--text-3);
      font-family: ui-monospace, "SF Mono", monospace;
      letter-spacing: .4px;
      margin-top: 2px;
      user-select: all;
    }

    .card-status {
      display: flex;
      align-items: center;
      gap: 6px;
      margin-top: 5px;
    }

    .status-dot {
      width: 7px; height: 7px;
      border-radius: 50%;
      flex-shrink: 0;
    }
    .status-dot.on  { background: var(--green); box-shadow: 0 0 6px rgba(48,209,88,.5); }
    .status-dot.off { background: rgba(235,235,245,.2); }

    .status-text { font-size: 12px; color: var(--text-3); }

    /* Screen preview */
    .card-screen {
      position: relative;
      width: 100%;
      aspect-ratio: 9/16;
      background: #000;
      flex-shrink: 0;
    }

    .card-screen img.thumb {
      position: absolute; inset: 0; z-index: 2;
      width: 100%; height: 100%;
      object-fit: contain;
      background: #000;
    }
    .card-screen img.thumb:not([src]) { display: none; }

    .mockup {
      position: absolute; inset: 0; z-index: 1;
      display: flex; flex-direction: column;
      align-items: center; justify-content: center;
      gap: 14px;
      background: #0a0a0a;
    }

    .spinner {
      width: 20px; height: 20px;
      border-radius: 50%;
      border: 2px solid rgba(255,255,255,.08);
      border-top-color: rgba(255,255,255,.4);
      animation: spin .8s linear infinite;
    }
    @keyframes spin { to { transform: rotate(360deg); } }

    .mockup-label { font-size: 11px; color: rgba(255,255,255,.25); }

    .offline-overlay {
      position: absolute; inset: 0; z-index: 2;
      display: flex; align-items: center; justify-content: center;
      background: #0a0a0a;
      font-size: 13px;
      color: rgba(255,255,255,.2);
    }

    /* ── Mobile overlay ──────────────────────────────── */
    .sidebar-overlay {
      display: none;
      position: fixed; inset: 0;
      background: rgba(0,0,0,.5);
      z-index: 85;
      backdrop-filter: blur(4px);
    }

    @media (max-width: 768px) {
      .hamburger { display: flex; }
      .sidebar {
        position: fixed;
        top: 0; left: 0; bottom: 0;
        transform: translateX(-100%);
      }
      .sidebar.open { transform: translateX(0); }
      .sidebar-overlay.open { display: block; }
      main { padding: 16px; }
      #cards { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>

<!-- Header -->
<header>
  <div class="header-left">
    <button class="hamburger" onclick="toggleSidebar()" aria-label="เมนู">
      <svg width="18" height="18" viewBox="0 0 18 18" fill="none">
        <rect y="3" width="18" height="1.5" rx=".75" fill="currentColor"/>
        <rect y="8.25" width="18" height="1.5" rx=".75" fill="currentColor"/>
        <rect y="13.5" width="18" height="1.5" rx=".75" fill="currentColor"/>
      </svg>
    </button>
    <div class="logo">
      <div class="logo-icon">🐱</div>
      Catcat
    </div>
  </div>

  <span class="badge"><?= $isAdmin ? 'ADMIN' : 'MEMBER' ?></span>

  <div class="header-actions">
    <button class="hdr-btn" onclick="location.reload()" title="รีเฟรช">
      <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
        <path d="M13.6 2.4A7 7 0 1 0 14.5 8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
        <path d="M14.5 2v3.5H11" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
    </button>
    <a class="hdr-btn" href="/logout.php" title="ออกจากระบบ">
      <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
        <path d="M6 2H3a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
        <path d="M11 11l3-3-3-3M14 8H6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
    </a>
  </div>
</header>

<div class="sidebar-overlay" onclick="closeSidebar()"></div>

<div class="layout">

  <!-- Sidebar -->
  <aside class="sidebar" id="sidebar">

    <div class="profile-block">
      <div class="profile-row">
        <div class="avatar"><?= htmlspecialchars($initial) ?></div>
        <div>
          <div class="profile-name"><?= htmlspecialchars($user['username']) ?></div>
          <div class="profile-role"><?= $isAdmin ? 'ผู้ดูแลระบบ' : 'สมาชิก' ?> · #<?= (int)$user['id'] ?></div>
        </div>
      </div>
      <div class="profile-stat">
        <span class="stat-num"><?= count($myDevices) ?></span>
        <span class="stat-label">เครื่องที่เช่าอยู่</span>
      </div>
      <a href="/rent.php" class="btn-rent">+ เช่าเครื่องเพิ่ม</a>
    </div>

    <div class="nav-section-label">เมนู</div>

    <a class="nav-item active" href="/cloud_dashboard.php">
      <svg viewBox="0 0 16 16" fill="none"><rect x="1" y="2" width="14" height="10" rx="2" stroke="currentColor" stroke-width="1.4"/><path d="M5 14h6M8 12v2" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
      เครื่องของฉัน
    </a>
    <a class="nav-item" href="/rent.php">
      <svg viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="6.25" stroke="currentColor" stroke-width="1.4"/><path d="M8 5v3l2 2" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
      เช่าเครื่อง
    </a>
    <a class="nav-item" href="/topup.php">
      <svg viewBox="0 0 16 16" fill="none"><path d="M8 2v12M3 7l5-5 5 5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
      เติมเครดิต
    </a>
    <?php if ($isAdmin): ?>
    <a class="nav-item" href="/admin_dashboard.php">
      <svg viewBox="0 0 16 16" fill="none"><path d="M2 12V9a6 6 0 1 1 12 0v3" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/><rect x="1" y="11" width="4" height="3" rx="1" stroke="currentColor" stroke-width="1.4"/><rect x="11" y="11" width="4" height="3" rx="1" stroke="currentColor" stroke-width="1.4"/></svg>
      แอดมิน
    </a>
    <?php endif; ?>

    <div class="nav-section-label" style="margin-top:4px;">เร็ว ๆ นี้</div>
    <div class="nav-item" style="opacity:.4;pointer-events:none;">
      <svg viewBox="0 0 16 16" fill="none"><path d="M8 2l1.5 3.5L13 6l-2.5 2.5.5 3.5L8 10.5 5 12l.5-3.5L3 6l3.5-.5Z" stroke="currentColor" stroke-width="1.3"/></svg>
      รางวัล
    </div>
    <div class="nav-item" style="opacity:.4;pointer-events:none;">
      <svg viewBox="0 0 16 16" fill="none"><rect x="2" y="5" width="12" height="8" rx="1.5" stroke="currentColor" stroke-width="1.4"/><path d="M5 5V4a3 3 0 0 1 6 0v1" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
      โค้ดแลกรับ
    </div>
    <div class="nav-item" style="opacity:.4;pointer-events:none;">
      <svg viewBox="0 0 16 16" fill="none"><path d="M2 3h12v8a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V3Z" stroke="currentColor" stroke-width="1.4"/><path d="M2 3l6 5 6-5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
      ประวัติคำสั่งซื้อ
    </div>

    <div class="nav-spacer"></div>

    <a class="nav-item" href="/logout.php">
      <svg viewBox="0 0 16 16" fill="none"><path d="M6 2H3a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h3" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/><path d="M11 11l3-3-3-3M14 8H6" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
      ออกจากระบบ
    </a>
  </aside>

  <!-- Main -->
  <main>
    <?php if (($_GET['topup'] ?? '') === 'ok'): ?>
    <div style="padding:12px 16px;border-radius:12px;font-size:13px;margin-bottom:20px;background:rgba(48,209,88,.12);border:1px solid rgba(48,209,88,.25);color:#34c759;">
      ส่งคำขอเติมเครดิตสำเร็จ รอ admin อนุมัติภายใน 24 ชั่วโมง
    </div>
    <?php endif; ?>
    <div class="page-title">เครื่องของฉัน</div>

    <div id="cards">
      <?php if (!$myDevices): ?>
        <div id="empty">
          <strong>ยังไม่มีเครื่อง</strong>
          แตะ "เช่าเครื่องเพิ่ม" เพื่อเริ่มต้น
        </div>
      <?php endif; ?>

      <?php foreach ($myDevices as $i => $d):
        $isOnline = (($online[$d['serial']] ?? '') === 'device');
        $label    = $d['label'] ?: $d['serial'];
        $conn     = str_contains($d['serial'], ':') ? 'WiFi' : 'USB';
        $leftTxt  = '';
        if ($d['expires_at']) {
            $expiresTs = strtotime($d['expires_at']);
            $secs      = $expiresTs - time();
            $leftTxt   = $secs > 0 ? '...' : 'หมดเวลา';
        }
      ?>
        <a class="device-card <?= $isOnline ? 'online' : 'offline' ?>"
           href="<?= $isOnline ? '/focus.php?serial='.urlencode($d['serial']) : '#' ?>">
          <div class="card-info">
            <div class="card-top">
              <span class="card-conn"><?= $conn ?></span>
              <?php if ($leftTxt): ?>
                <span class="card-timer" data-expires="<?= $expiresTs ?>"><?= htmlspecialchars($leftTxt) ?></span>
              <?php endif; ?>
            </div>
            <div class="card-label" title="<?= htmlspecialchars($label) ?>"><?= htmlspecialchars($label) ?></div>
            <div class="card-serial"><?= htmlspecialchars($d['serial']) ?></div>
            <div class="card-status">
              <span class="status-dot <?= $isOnline ? 'on' : 'off' ?>"></span>
              <span class="status-text"><?= $isOnline ? 'ออนไลน์' : 'ออฟไลน์' ?></span>
            </div>
          </div>
          <div class="card-screen">
            <?php if ($isOnline): ?>
              <div class="mockup">
                <div class="spinner"></div>
                <div class="mockup-label">กำลังโหลด… <span class="cd">10</span></div>
              </div>
              <img class="thumb" data-serial="<?= htmlspecialchars($d['serial']) ?>" alt="<?= htmlspecialchars($label) ?>">
            <?php else: ?>
              <div class="offline-overlay">ออฟไลน์</div>
            <?php endif; ?>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  </main>

</div>

<script>
  function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.querySelector('.sidebar-overlay').classList.toggle('open');
  }
  function closeSidebar() {
    document.getElementById('sidebar').classList.remove('open');
    document.querySelector('.sidebar-overlay').classList.remove('open');
  }

  // Live thumbnails
  const REFRESH    = <?= $refresh ?>;
  const LS_PREFIX  = 'catcat_thumb_';
  const COUNT_FROM = 10;

  function hideMockup(img) {
    const mk = img.parentElement.querySelector('.mockup');
    if (!mk) return;
    if (mk._cd) { clearInterval(mk._cd); mk._cd = null; }
    mk.style.display = 'none';
  }

  function startCountdown(img) {
    const mk = img.parentElement.querySelector('.mockup');
    const cd = mk && mk.querySelector('.cd');
    if (!cd) return;
    let n = COUNT_FROM;
    cd.textContent = n;
    mk._cd = setInterval(() => {
      n--;
      cd.textContent = n > 0 ? n : '…';
      if (n <= 0) { clearInterval(mk._cd); mk._cd = null; }
    }, 1000);
  }

  function loadThumb(img) {
    const serial = img.dataset.serial;
    fetch('/thumb.php?serial=' + encodeURIComponent(serial) + '&t=' + Date.now())
      .then(r => r.ok ? r.blob() : Promise.reject(r.status))
      .then(blob => new Promise(res => {
        const fr = new FileReader();
        fr.onload = () => res(fr.result);
        fr.readAsDataURL(blob);
      }))
      .then(dataUrl => {
        img.src = dataUrl;
        hideMockup(img);
        try { localStorage.setItem(LS_PREFIX + serial, dataUrl); } catch (e) {}
      })
      .catch(() => {});
  }

  document.querySelectorAll('img.thumb').forEach(img => {
    const cached = localStorage.getItem(LS_PREFIX + img.dataset.serial);
    if (cached) { img.src = cached; hideMockup(img); }
    else { startCountdown(img); }
    loadThumb(img);
  });

  setInterval(() => document.querySelectorAll('img.thumb').forEach(loadThumb), REFRESH);

  // Live countdown timers
  function tickCountdowns() {
    const now = Math.floor(Date.now() / 1000);
    document.querySelectorAll('.card-timer[data-expires]').forEach(el => {
      const exp  = parseInt(el.dataset.expires, 10);
      const secs = exp - now;
      if (secs <= 0) {
        el.textContent = 'หมดเวลา';
        el.className   = 'card-timer urgent';
        return;
      }
      const d  = Math.floor(secs / 86400);
      const h  = Math.floor((secs % 86400) / 3600);
      const m  = Math.floor((secs % 3600) / 60);
      const s  = secs % 60;
      el.textContent = d > 0
        ? `${d}ว ${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}`
        : `${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`;
      el.className = secs < 3600 ? 'card-timer urgent' : secs < 86400 ? 'card-timer warn' : 'card-timer';
    });
  }
  tickCountdowns();
  setInterval(tickCountdowns, 1000);
</script>
</body>
</html>
