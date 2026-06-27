<?php
// Cloud dashboard — post-login landing. Visual shell adopted from the CloudEmu
// example (header + sidebar + main view + right toolbar, responsive hamburger);
// wired to Panda's real data: the user's leased devices render as a live
// thumbnail grid in .main-view. Sidebar/toolbar items without backing data yet
// are Phase 2 placeholders (marked below).
require_once __DIR__ . '/../../back/lib/auth.php';
require_once __DIR__ . '/../../back/lib/adb.php';
$user = require_login();

// Devices this user holds a live lease on.
$st = db()->prepare(
    'SELECT d.serial, d.label, l.expires_at
       FROM leases l JOIN devices d ON d.serial = l.serial
      WHERE l.user_id = ? AND (l.expires_at IS NULL OR l.expires_at > NOW())
      ORDER BY d.label, d.serial'
);
$st->execute([$user['id']]);
$myDevices = $st->fetchAll();

// Which of them are actually online right now (best-effort).
try { $online = adb_list_devices(); } catch (Throwable $e) { $online = []; }

$refresh   = (int) config()['thumb_refresh_ms'];
$initial   = strtoupper(substr($user['username'], 0, 1));
$isAdmin   = ($user['role'] === 'admin');
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panda — เครื่องของฉัน</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"
          integrity="sha512-iecdLmaskl7CVkqkXNQ/ZH/XLlvWZOJyj7Yy7tcenmpD1ypASozpmT/E0iPtmFIB46ZmdtAc9eNBvH0H/ZpiBw=="
          crossorigin="anonymous">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }

        body { height: 100vh; display: flex; flex-direction: column;
               background-color: #f0f2f5; overflow: hidden; }

        /* ── Header ─────────────────────────────────────────── */
        header { height: 50px; background-color: #fff; border-bottom: 1px solid #ddd;
                 display: flex; align-items: center; justify-content: space-between;
                 padding: 0 15px; flex-shrink: 0; z-index: 1000; }
        .header-left { display: flex; align-items: center; gap: 15px; }
        .hamburger-btn { display: none; background: none; border: none;
                         font-size: 1.2rem; color: #666; cursor: pointer; padding: 5px; }
        .logo-area { display: flex; align-items: center; gap: 10px;
                     font-weight: bold; color: #3b82f6; font-size: 1.1rem; }
        .controls { display: flex; align-items: center; gap: 15px; color: #666; }
        .controls i { cursor: pointer; }

        /* ── Layout ─────────────────────────────────────────── */
        .container { display: flex; flex: 1; position: relative; overflow: hidden; }

        .sidebar { width: 240px; background-color: #fff; border-right: 1px solid #ddd;
                   display: flex; flex-direction: column; padding: 15px; gap: 15px;
                   overflow-y: auto; flex-shrink: 0; transition: transform 0.3s ease-in-out;
                   position: relative; z-index: 999; }

        .profile-card { background: #f9f9f9; padding: 12px; border-radius: 8px; border: 1px solid #eee; }
        .user-header { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; }
        .avatar { width: 36px; height: 36px; background-color: #e0f2fe; color: #3b82f6;
                  border-radius: 50%; display: flex; align-items: center; justify-content: center;
                  font-weight: bold; font-size: 1rem; }
        .user-details h4 { font-size: 13px; color: #333; line-height: 1.2; }
        .user-details span { font-size: 11px; color: #888; }
        .wallet-info { margin-top: 8px; padding-top: 8px; border-top: 1px solid #eee; }
        .balance { font-size: 16px; font-weight: bold; color: #333; margin: 4px 0; }
        .btn-promo { display: block; width: 100%; margin-top: 8px; padding: 8px;
                     background-color: #eff6ff; color: #3b82f6; border: none; border-radius: 4px;
                     cursor: pointer; text-align: center; font-size: 12px; }

        .nav-menu { list-style: none; }
        .nav-item { padding: 8px 10px; color: #555; cursor: pointer; border-radius: 6px;
                    display: flex; align-items: center; gap: 10px; transition: 0.2s;
                    font-size: 14px; text-decoration: none; }
        .nav-item:hover { background-color: #f0f0f0; color: #333; }
        .nav-item.active { background-color: #eff6ff; color: #3b82f6; font-weight: 600; }
        .nav-item i { width: 20px; text-align: center; font-size: 1.1rem; }
        .nav-spacer { flex: 1; }

        /* ── Main view: device grid ─────────────────────────── */
        .main-view { flex: 1; padding: 20px; background-color: #f0f2f5; overflow-y: auto; }
        #cards { display: flex; flex-wrap: wrap; gap: 16px;
                 align-items: flex-start; justify-content: center; }
        #empty { padding: 60px 20px; color: #888; font-size: 14px; text-align: center; width: 100%; }

        .device-card { display: flex; flex-direction: column; width: 300px;
                       background: #fff; border: 1px solid #e5e7eb; border-radius: 14px;
                       overflow: hidden; text-decoration: none; color: inherit;
                       box-shadow: 0 2px 8px rgba(0,0,0,0.06); transition: 0.2s; }
        .device-card.online:hover { border-color: #3b82f6; box-shadow: 0 4px 14px rgba(59,130,246,0.18); }
        .device-card.offline { opacity: .6; pointer-events: none; }

        .card-header { padding: 10px 14px 8px; }
        .card-conn { font-size: 11px; font-weight: bold; color: #999;
                     letter-spacing: .04em; margin-bottom: 2px; }
        .card-num { font-size: 28px; font-weight: bold; color: #222; line-height: 1.1; }
        .card-serial { font-size: 14px; color: #555; font-weight: bold; margin-top: 2px;
                       overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .card-meta { display: flex; align-items: center; gap: 8px; margin-top: 4px;
                     font-size: 11px; min-height: 14px; color: #666; }
        .dot { width: 8px; height: 8px; border-radius: 50%; }
        .dot.on  { background: #22c55e; }
        .dot.off { background: #bbb; }
        .card-time { margin-left: auto; color: #3b82f6; font-weight: 600; }

        .card-screen { position: relative; width: 300px; height: 512px;
                       background: #000; flex-shrink: 0; }
        .card-screen img.thumb { position: absolute; inset: 0; z-index: 2;
                                 width: 100%; height: 100%; object-fit: contain;
                                 display: block; background: #000; }
        .card-screen img.thumb:not([src]) { display: none; }
        .card-screen .overlay { position: absolute; inset: 0; display: flex;
                                align-items: center; justify-content: center;
                                color: #888; font-size: 12px; background: #1a1a1a; }
        .mockup { position: absolute; inset: 0; z-index: 1; display: flex; flex-direction: column;
                  align-items: center; justify-content: center; gap: 16px; background: #0c0c0c; }
        .spinner { width: 22px; height: 22px; border-radius: 50%;
                   border: 3px solid #222; border-top-color: #4af; animation: spin .8s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .mockup .label { font-size: 11px; color: #777; }

        /* ── Right toolbar ──────────────────────────────────── */
        .toolbar { width: 60px; background-color: #fff; border-left: 1px solid #ddd;
                   display: flex; flex-direction: column; align-items: center;
                   padding-top: 15px; gap: 12px; flex-shrink: 0; }
        .tool-btn { display: flex; flex-direction: column; align-items: center;
                    color: #666; font-size: 9px; cursor: pointer; gap: 4px;
                    text-decoration: none; }
        .tool-btn i { font-size: 14px; padding: 6px; background-color: #f5f5f5; border-radius: 4px; }
        .tool-btn:hover i { background-color: #e0e0e0; }
        .wifi-status { color: #4caf50; font-size: 9px; margin-bottom: 8px;
                       display: flex; flex-direction: column; align-items: center; }

        /* ── Mobile ─────────────────────────────────────────── */
        .overlay-menu { display: none; position: fixed; inset: 0;
                        background-color: rgba(0,0,0,0.5); z-index: 998; }
        @media (max-width: 768px) {
            .hamburger-btn { display: block; }
            .toolbar { display: none; }
            .sidebar { position: absolute; top: 0; left: 0; height: 100%; width: 260px;
                       box-shadow: 2px 0 10px rgba(0,0,0,0.1); transform: translateX(-100%); }
            .sidebar.active { transform: translateX(0); }
            .overlay-menu.active { display: block; }
            .main-view { padding: 12px; }
            .controls span { display: none; }
        }
        @media (max-width: 480px) {
            .sidebar { width: 240px; }
            .device-card, .card-screen { width: 100%; }
        }
    </style>
</head>
<body>

    <!-- ── Header ── -->
    <header>
        <div class="header-left">
            <button class="hamburger-btn" onclick="toggleSidebar()"><i class="fa-solid fa-bars"></i></button>
            <div class="logo-area"><i class="fa-solid fa-cloud"></i> Panda</div>
        </div>
        <div style="color: #666; font-size: 13px;"><?= $isAdmin ? 'ADMIN' : 'MEMBER' ?> · TH</div>
        <div class="controls">
            <!-- Phase 2: global quality / refresh controls -->
            <select disabled title="Phase 2"><option>720P</option><option>1080P</option></select>
            <i class="fa-solid fa-rotate" title="รีเฟรช" onclick="location.reload()"></i>
            <i class="fa-solid fa-circle-question"></i>
            <i class="fa-solid fa-headset"></i>
        </div>
    </header>

    <div class="overlay-menu" onclick="closeSidebar()"></div>

    <div class="container">

        <!-- ── Sidebar ── -->
        <aside class="sidebar" id="sidebar">
            <div class="profile-card">
                <div class="user-header">
                    <div class="avatar"><?= htmlspecialchars($initial) ?></div>
                    <div class="user-details">
                        <h4><?= htmlspecialchars($user['username']) ?></h4>
                        <span><?= $isAdmin ? 'ผู้ดูแลระบบ' : 'สมาชิก' ?> · ID: <?= (int) $user['id'] ?></span>
                    </div>
                </div>
                <div class="wallet-info">
                    <div style="font-size: 11px; color: #888;">เครื่องที่เช่าอยู่</div>
                    <div class="balance"><?= count($myDevices) ?> เครื่อง</div>
                    <!-- Phase 2: link to rental / order history -->
                    <a href="#" style="font-size: 11px; color: #3b82f6; text-decoration: none;">ประวัติการเช่า ></a>
                </div>
                <button class="btn-promo" onclick="location.href='/rent.php'">+ เช่าเครื่องเพิ่ม</button>
            </div>

            <ul class="nav-menu">
                <a class="nav-item active" href="/cloud_dashboard.php"><i class="fa-solid fa-mobile-screen"></i> เครื่องของฉัน</a>
                <a class="nav-item" href="/rent.php"><i class="fa-solid fa-cart-plus"></i> เช่าเครื่อง</a>
                <?php if ($isAdmin): ?>
                <a class="nav-item" href="/admin_dashboard.php"><i class="fa-solid fa-screwdriver-wrench"></i> แอดมิน</a>
                <?php endif; ?>
                <!-- Phase 2 placeholders -->
                <li class="nav-item"><i class="fa-solid fa-gift"></i> รางวัล</li>
                <li class="nav-item"><i class="fa-solid fa-ticket"></i> โค้ดแลกรับ</li>
                <li class="nav-item"><i class="fa-solid fa-clock-rotate-left"></i> ประวัติคำสั่งซื้อ</li>
                <li class="nav-item"><i class="fa-solid fa-headset"></i> ช่วยเหลือ</li>
                <div class="nav-spacer"></div>
                <a class="nav-item" href="/logout.php"><i class="fa-solid fa-power-off"></i> ออกจากระบบ</a>
            </ul>
        </aside>

        <!-- ── Main view: device grid ── -->
        <main class="main-view">
            <div id="cards">
                <?php if (!$myDevices): ?>
                    <div id="empty">ยังไม่มีเครื่องที่เช่า — แตะ “เช่าเครื่อง” เพื่อเริ่ม</div>
                <?php endif; ?>

                <?php foreach ($myDevices as $i => $d):
                    $isOnline = (($online[$d['serial']] ?? '') === 'device');
                    $label = $d['label'] ?: $d['serial'];
                    $conn  = str_contains($d['serial'], ':') ? 'WiFi' : 'USB';
                    $leftTxt = '';
                    if ($d['expires_at']) {
                        $secs = strtotime($d['expires_at']) - time();
                        $leftTxt = $secs > 0 ? sprintf('%02d:%02d', intdiv($secs, 3600), intdiv($secs % 3600, 60)) : 'หมดเวลา';
                    }
                ?>
                    <a class="device-card <?= $isOnline ? 'online' : 'offline' ?>"
                       href="<?= $isOnline ? '/focus.php?serial=' . urlencode($d['serial']) : '#' ?>">
                        <div class="card-header">
                            <div class="card-conn"><?= $conn ?></div>
                            <div class="card-num"><?= str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) ?></div>
                            <div class="card-serial" title="<?= htmlspecialchars($label) ?>"><?= htmlspecialchars($label) ?></div>
                            <div class="card-meta">
                                <span class="dot <?= $isOnline ? 'on' : 'off' ?>"></span>
                                <span><?= $isOnline ? 'ออนไลน์' : 'ออฟไลน์' ?></span>
                                <?php if ($leftTxt): ?><span class="card-time"><i class="fa-regular fa-clock"></i> <?= $leftTxt ?></span><?php endif; ?>
                            </div>
                        </div>
                        <div class="card-screen">
                            <?php if ($isOnline): ?>
                                <div class="mockup">
                                    <div class="spinner"></div>
                                    <div class="label">Loading… <span class="cd">10</span></div>
                                </div>
                                <img class="thumb" data-serial="<?= htmlspecialchars($d['serial']) ?>" alt="<?= htmlspecialchars($label) ?>">
                            <?php else: ?>
                                <div class="overlay">ออฟไลน์</div>
                            <?php endif; ?>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </main>

        <!-- ── Right toolbar (Phase 2 placeholders, except Exit) ── -->
        <aside class="toolbar">
            <div class="wifi-status"><i class="fa-solid fa-wifi"></i><span>—</span></div>
            <div class="tool-btn"><i class="fa-solid fa-upload"></i>Upload</div>
            <div class="tool-btn"><i class="fa-solid fa-clipboard"></i>Clipboard</div>
            <div class="tool-btn" onclick="location.reload()"><i class="fa-solid fa-arrows-rotate"></i>Refresh</div>
            <div style="flex: 1;"></div>
            <a class="tool-btn" href="/logout.php"><i class="fa-solid fa-power-off"></i>Exit</a>
        </aside>

    </div>

    <script>
        // ── Sidebar (mobile) ──────────────────────────────────
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('active');
            document.querySelector('.overlay-menu').classList.toggle('active');
        }
        function closeSidebar() {
            document.getElementById('sidebar').classList.remove('active');
            document.querySelector('.overlay-menu').classList.remove('active');
        }

        // ── Live thumbnails (unchanged Panda logic) ───────────
        const REFRESH = <?= $refresh ?>;
        const LS = 'panda_thumb_';   // localStorage key prefix: last frame per serial
        const COUNT_FROM = 10;       // "Loading…" countdown start (seconds)

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
                    try { localStorage.setItem(LS + serial, dataUrl); } catch (e) {}
                })
                .catch(() => {});
        }

        document.querySelectorAll('img.thumb').forEach(img => {
            const cached = localStorage.getItem(LS + img.dataset.serial);
            if (cached) { img.src = cached; hideMockup(img); }
            else { startCountdown(img); }
            loadThumb(img);
        });
        setInterval(() => document.querySelectorAll('img.thumb').forEach(loadThumb), REFRESH);
    </script>
</body>
</html>
