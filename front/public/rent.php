<?php
require_once __DIR__ . '/../../back/lib/auth.php';
$user    = require_login();
$initial = strtoupper(substr($user['username'], 0, 1));
$isAdmin = ($user['role'] === 'admin');

// ── Catalog data (static) ────────────────────────────────────────────────────
$tiers = [
    'VIP'  => [
        'color' => '#4a9eff',
        'specs' => [
            ['icon' => '⚙️', 'label' => '4 core cpu'],
            ['icon' => '🤖', 'label' => 'Android 12'],
            ['icon' => '💾', 'label' => '64G ROM'],
            ['icon' => '🔢', 'label' => '32 BIT'],
            ['icon' => '📱', 'label' => 'MediaTek'],
            ['icon' => '🧠', 'label' => '4G RAM'],
        ],
        'packages' => [
            ['days' => 30, 'price' => 199, 'orig' => 299],
            ['days' => 7,  'price' => 59,  'orig' => 89],
            ['days' => 90, 'price' => 499, 'orig' => 799],
        ],
    ],
    'KVIP' => [
        'color' => '#a855f7',
        'specs' => [
            ['icon' => '⚙️', 'label' => '6 core cpu'],
            ['icon' => '🤖', 'label' => 'Android 12'],
            ['icon' => '💾', 'label' => '96G ROM'],
            ['icon' => '🔢', 'label' => '64 BIT'],
            ['icon' => '📱', 'label' => 'Qualcomm'],
            ['icon' => '🧠', 'label' => '6G RAM'],
        ],
        'packages' => [
            ['days' => 30, 'price' => 269, 'orig' => 369],
            ['days' => 7,  'price' => 79,  'orig' => 119],
            ['days' => 90, 'price' => 649, 'orig' => 999],
        ],
    ],
    'SVIP' => [
        'color' => '#0a84ff',
        'specs' => [
            ['icon' => '⚙️', 'label' => '8 core cpu'],
            ['icon' => '🤖', 'label' => 'Android 12'],
            ['icon' => '💾', 'label' => '128G ROM'],
            ['icon' => '🔢', 'label' => '64 BIT'],
            ['icon' => '📱', 'label' => 'Qualcomm'],
            ['icon' => '🧠', 'label' => '8G RAM'],
        ],
        'packages' => [
            ['days' => 30, 'price' => 350, 'orig' => 462],
            ['days' => 7,  'price' => 110, 'orig' => 169],
            ['days' => 90, 'price' => 800, 'orig' => 1290],
        ],
    ],
    'XVIP' => [
        'color' => '#ff9f0a',
        'specs' => [
            ['icon' => '⚙️', 'label' => '12 core cpu'],
            ['icon' => '🤖', 'label' => 'Android 14'],
            ['icon' => '💾', 'label' => '256G ROM'],
            ['icon' => '🔢', 'label' => '64 BIT'],
            ['icon' => '📱', 'label' => 'Snapdragon'],
            ['icon' => '🧠', 'label' => '12G RAM'],
        ],
        'packages' => [
            ['days' => 30, 'price' => 490, 'orig' => 650],
            ['days' => 7,  'price' => 149, 'orig' => 219],
            ['days' => 90, 'price' => 1200,'orig' => 1790],
        ],
    ],
];

$servers = [
    ['id' => 'th', 'name' => 'Thailand',  'ms' => 9],
    ['id' => 'sg', 'name' => 'Singapore', 'ms' => 41],
    ['id' => 'hk', 'name' => 'Hongkong',  'ms' => 72],
];

// ── CSRF ──────────────────────────────────────────────────────────────────────
start_session();
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf'];

// ── User credits ──────────────────────────────────────────────────────────────
$pdo = db();
$stCredit = $pdo->prepare('SELECT credits FROM users WHERE id = ?');
$stCredit->execute([$user['id']]);
$userCredits = (float) $stCredit->fetchColumn();

// ── Available device count per tier ──────────────────────────────────────────
$stAvail = $pdo->query(
    'SELECT tier, COUNT(*) AS cnt FROM devices
      WHERE is_rentable = 1
        AND serial NOT IN (SELECT serial FROM leases WHERE expires_at IS NULL OR expires_at > NOW())
      GROUP BY tier'
);
$availCount = [];
foreach ($stAvail->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $availCount[$row['tier']] = (int)$row['cnt'];
}

// ── Handle NEXT submission ───────────────────────────────────────────────────
$confirmed = false;
$order     = [];
$error     = '';
$formState = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['csrf'] ?? '') !== $csrf) {
        http_response_code(403); echo 'CSRF mismatch'; exit;
    }

    $tier    = $_POST['tier']   ?? '';
    $server  = $_POST['server'] ?? '';
    $qty     = max(1, min(10, (int)($_POST['qty'] ?? 1)));
    $pkgIdx  = (int)($_POST['pkg'] ?? 0);
    $formState = ['tier' => $tier, 'server' => $server, 'qty' => $qty, 'pkg' => $pkgIdx];

    if (isset($tiers[$tier]) && $pkgIdx >= 0 && $pkgIdx < 3) {
        $pkg   = $tiers[$tier]['packages'][$pkgIdx];
        $total = (float)($pkg['price'] * $qty);
        $days  = (int)$pkg['days'];

        $stCredit->execute([$user['id']]);
        $freshCredits = (float) $stCredit->fetchColumn();

        if ($freshCredits < $total) {
            $error = 'เครดิตไม่เพียงพอ (มี ฿' . number_format($freshCredits, 2) . ' ต้องการ ฿' . number_format($total) . ')';
        } else {
            $stDev = $pdo->prepare(
                'SELECT serial FROM devices
                  WHERE tier = ? AND is_rentable = 1
                    AND serial NOT IN (SELECT serial FROM leases WHERE expires_at IS NULL OR expires_at > NOW())
                  LIMIT ' . (int)$qty
            );
            $stDev->execute([$tier]);
            $available = $stDev->fetchAll(PDO::FETCH_COLUMN);

            if (count($available) < $qty) {
                $error = "ไม่มีเครื่อง {$tier} ว่างเพียงพอ (ว่าง " . count($available) . " เครื่อง ต้องการ {$qty})";
            } else {
                $pdo->beginTransaction();
                try {
                    $stDeduct = $pdo->prepare('UPDATE users SET credits = credits - ? WHERE id = ? AND credits >= ?');
                    $stDeduct->execute([$total, $user['id'], $total]);
                    if ($stDeduct->rowCount() === 0) {
                        throw new RuntimeException('credits_insufficient');
                    }
                    $stLease = $pdo->prepare(
                        'INSERT INTO leases (user_id, serial, expires_at, tier)
                           VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? DAY), ?)
                           ON DUPLICATE KEY UPDATE expires_at = DATE_ADD(NOW(), INTERVAL ? DAY), tier = ?'
                    );
                    foreach ($available as $serial) {
                        $stLease->execute([$user['id'], $serial, $days, $tier, $days, $tier]);
                    }
                    $pdo->commit();
                    $userCredits = $freshCredits - $total;
                    $order = [
                        'tier'         => $tier,
                        'server'       => $server,
                        'qty'          => $qty,
                        'days'         => $days,
                        'price'        => $total,
                        'serials'      => $available,
                        'credits_left' => $userCredits,
                    ];
                    $confirmed = true;
                    $formState = null;
                } catch (RuntimeException $e) {
                    $pdo->rollBack();
                    $error = 'เครดิตไม่เพียงพอ';
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    $error = 'เกิดข้อผิดพลาด กรุณาลองใหม่';
                }
            }
        }
    } else {
        $error = 'ข้อมูลไม่ถูกต้อง';
    }
}
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
  <title>Catcat — เช่าเครื่อง</title>
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
      --orange:      #ff9f0a;
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

    /* ── Header ──────────────────────────────────────── */
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
    .header-left { display: flex; align-items: center; gap: 12px; }
    .logo { display: flex; align-items: center; gap: 8px; font-size: 16px; font-weight: 700; color: var(--text); }
    .logo-icon { font-size: 20px; }
    .badge {
      font-size: 10px; font-weight: 700; letter-spacing: 1px;
      padding: 3px 8px; border-radius: 6px;
      background: rgba(10,132,255,.18); color: var(--accent); border: 1px solid rgba(10,132,255,.3);
    }
    .badge.admin-badge { background: rgba(255,159,10,.15); color: var(--orange); border-color: rgba(255,159,10,.3); }
    .header-actions { display: flex; gap: 6px; }
    .hdr-btn {
      display: flex; align-items: center; justify-content: center;
      width: 32px; height: 32px; border-radius: 8px;
      background: transparent; border: none; color: var(--text-2);
      cursor: pointer; transition: background .15s;
      text-decoration: none;
    }
    .hdr-btn:hover { background: var(--bg-hover); color: var(--text); }
    .hamburger { display: none; }

    /* ── Layout ──────────────────────────────────────── */
    .layout { display: flex; flex: 1; overflow: hidden; }

    /* ── Sidebar ─────────────────────────────────────── */
    aside.sidebar {
      width: var(--sidebar-w);
      flex-shrink: 0;
      background: rgba(28,28,30,.95);
      border-right: 1px solid var(--border);
      display: flex;
      flex-direction: column;
      overflow-y: auto;
      overflow-x: hidden;
      z-index: 90;
      transition: transform .25s ease;
    }
    .profile-block {
      padding: 16px 14px 12px;
      border-bottom: 1px solid var(--border-soft);
    }
    .profile-row { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
    .avatar {
      width: 36px; height: 36px; border-radius: 10px;
      background: linear-gradient(135deg, #0a84ff, #5e5ce6);
      display: flex; align-items: center; justify-content: center;
      font-size: 15px; font-weight: 700; color: #fff; flex-shrink: 0;
    }
    .profile-name  { font-size: 13px; font-weight: 600; color: var(--text); }
    .profile-role  { font-size: 11px; color: var(--text-3); margin-top: 1px; }
    .profile-stat  { display: flex; align-items: baseline; gap: 5px; margin-bottom: 10px; }
    .stat-num      { font-size: 22px; font-weight: 700; color: var(--accent); }
    .stat-label    { font-size: 11px; color: var(--text-3); }
    .btn-rent {
      display: block; width: 100%; padding: 8px 12px; border-radius: 10px;
      background: rgba(10,132,255,.16); border: 1px solid rgba(10,132,255,.25);
      color: var(--accent); font-size: 13px; font-weight: 600;
      text-align: center; text-decoration: none; transition: background .15s;
    }
    .btn-rent:hover { background: rgba(10,132,255,.24); }
    .nav-section-label {
      padding: 14px 14px 4px;
      font-size: 10px; font-weight: 600; letter-spacing: .8px; text-transform: uppercase;
      color: var(--text-3);
    }
    .nav-item {
      display: flex; align-items: center; gap: 10px;
      padding: 9px 14px; font-size: 13px; font-weight: 500; color: var(--text-2);
      text-decoration: none; border-radius: 10px; margin: 1px 6px;
      cursor: pointer; transition: background .12s, color .12s;
    }
    .nav-item svg { width: 16px; height: 16px; flex-shrink: 0; }
    .nav-item:hover { background: var(--bg-hover); color: var(--text); }
    .nav-item.active { background: rgba(10,132,255,.15); color: var(--accent); }
    .nav-spacer { flex: 1; min-height: 12px; }
    .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.5); z-index: 85; backdrop-filter: blur(4px); }

    /* ── Main ────────────────────────────────────────── */
    main {
      flex: 1;
      overflow-y: auto;
      padding: 24px 28px 120px;
    }
    .page-title {
      font-size: 22px; font-weight: 700; color: var(--text);
      margin-bottom: 20px; letter-spacing: -.3px;
    }

    /* ── Rent panel ───────────────────────────────────── */
    .rent-panel {
      max-width: 600px;
      background: rgba(28,28,30,.9);
      border: 1px solid var(--border);
      border-radius: 18px;
      overflow: hidden;
    }

    /* Tier tabs */
    .tier-tabs {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      border-bottom: 1px solid var(--border);
    }
    .tier-tab {
      padding: 12px 8px;
      text-align: center;
      font-size: 13px;
      font-weight: 700;
      letter-spacing: .5px;
      color: var(--text-3);
      cursor: pointer;
      border: none;
      background: transparent;
      transition: color .15s, background .15s;
      position: relative;
    }
    .tier-tab + .tier-tab { border-left: 1px solid var(--border); }
    .tier-tab:hover { color: var(--text-2); background: var(--bg-hover); }
    .tier-tab.active { color: var(--text); }
    .tier-tab.active::after {
      content: '';
      position: absolute;
      bottom: -1px; left: 0; right: 0;
      height: 2px;
      background: var(--tier-color, var(--accent));
      border-radius: 2px 2px 0 0;
    }
    .tab-avail {
      display: block;
      font-size: 10px; font-weight: 600; letter-spacing: 0;
      color: var(--text-3); margin-top: 2px;
    }
    .tier-tab.active .tab-avail { color: var(--tier-color, var(--accent)); opacity: .7; }

    /* Specs */
    .specs-row {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 16px;
      padding: 16px 20px;
      border-bottom: 1px solid var(--border);
      flex-wrap: wrap;
    }
    .spec-item {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 4px;
      min-width: 52px;
    }
    .spec-icon {
      width: 36px; height: 36px; border-radius: 10px;
      background: rgba(255,255,255,.06);
      display: flex; align-items: center; justify-content: center;
      font-size: 17px;
    }
    .spec-label { font-size: 10px; color: var(--text-3); text-align: center; white-space: nowrap; }

    /* Sections */
    .rent-section { padding: 16px 20px; border-bottom: 1px solid var(--border); }
    .rent-section:last-of-type { border-bottom: none; }
    .section-title {
      font-size: 13px; font-weight: 600; color: var(--text-2);
      margin-bottom: 12px;
    }

    /* Version pills */
    .pill-group { display: flex; gap: 8px; flex-wrap: wrap; }
    .pill {
      padding: 6px 16px; border-radius: 8px;
      font-size: 13px; font-weight: 600;
      border: 1px solid var(--border);
      background: rgba(255,255,255,.04);
      color: var(--text-2);
      cursor: pointer; transition: all .15s;
    }
    .pill:hover { border-color: rgba(255,255,255,.2); color: var(--text); }
    .pill.active {
      background: var(--tier-color-bg, rgba(10,132,255,.15));
      border-color: var(--tier-color-border, rgba(10,132,255,.4));
      color: var(--tier-color, var(--accent));
    }

    /* Server chips */
    .server-group { display: flex; gap: 8px; flex-wrap: wrap; }
    .server-chip {
      display: flex; flex-direction: column; align-items: center;
      gap: 4px; padding: 8px 14px; border-radius: 10px;
      border: 1px solid var(--border);
      background: rgba(255,255,255,.04);
      cursor: pointer; transition: all .15s; min-width: 72px;
    }
    .server-chip:hover { border-color: rgba(255,255,255,.2); }
    .server-chip.active {
      background: var(--tier-color-bg, rgba(10,132,255,.15));
      border-color: var(--tier-color-border, rgba(10,132,255,.4));
    }
    .server-ping {
      font-size: 10px; font-weight: 700; letter-spacing: .3px;
      padding: 2px 6px; border-radius: 4px;
      background: rgba(48,209,88,.15); color: var(--green);
    }
    .server-ping.slow  { background: rgba(255,159,10,.15); color: var(--orange); }
    .server-ping.vslow { background: rgba(255,69,58,.12);  color: var(--red); }
    .server-name { font-size: 11px; color: var(--text-2); }
    .server-chip.active .server-name { color: var(--tier-color, var(--accent)); }

    /* Quantity */
    .qty-row { display: flex; align-items: center; justify-content: space-between; }
    .qty-label { font-size: 13px; font-weight: 600; color: var(--text-2); }
    .qty-ctrl  { display: flex; align-items: center; gap: 0; border: 1px solid var(--border); border-radius: 10px; overflow: hidden; }
    .qty-btn {
      width: 36px; height: 36px; background: transparent;
      border: none; color: var(--text-2); font-size: 18px; font-weight: 400;
      cursor: pointer; transition: background .15s;
      display: flex; align-items: center; justify-content: center;
    }
    .qty-btn:hover { background: var(--bg-hover); color: var(--text); }
    .qty-val {
      width: 40px; text-align: center; font-size: 15px; font-weight: 700;
      color: var(--text); border-left: 1px solid var(--border); border-right: 1px solid var(--border);
      line-height: 36px;
    }

    /* Package cards */
    .pkg-list { display: flex; flex-direction: column; gap: 8px; }
    .pkg-card {
      display: flex; align-items: center; justify-content: space-between;
      padding: 14px 16px; border-radius: 12px;
      border: 1px solid var(--border);
      background: rgba(255,255,255,.03);
      cursor: pointer; transition: all .15s;
    }
    .pkg-card:hover { border-color: rgba(255,255,255,.18); background: rgba(255,255,255,.05); }
    .pkg-card.active {
      background: rgba(255,159,10,.08);
      border-color: rgba(255,159,10,.4);
    }
    .pkg-days { font-size: 15px; font-weight: 600; color: var(--text); }
    .pkg-right { text-align: right; }
    .pkg-price { font-size: 18px; font-weight: 700; color: var(--orange); }
    .pkg-orig  { font-size: 11px; color: var(--text-3); }
    .pkg-orig s { margin-right: 4px; }

    /* Bottom bar */
    .bottom-bar {
      position: fixed;
      bottom: 0;
      left: var(--sidebar-w);
      right: 0;
      height: 64px;
      background: rgba(28,28,30,.95);
      backdrop-filter: blur(24px);
      -webkit-backdrop-filter: blur(24px);
      border-top: 1px solid var(--border);
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 0 28px;
      z-index: 50;
    }
    .total-price { font-size: 22px; font-weight: 700; color: var(--orange); }
    .next-btn {
      padding: 12px 36px; border-radius: 14px;
      background: var(--accent); border: none;
      color: #fff; font-size: 16px; font-weight: 700;
      cursor: pointer; transition: opacity .15s, transform .1s;
      font-family: inherit;
    }
    .next-btn:hover  { opacity: .88; }
    .next-btn:active { opacity: .75; transform: scale(.97); }

    /* Confirmation screen */
    .confirm-box {
      max-width: 480px;
      margin: 40px auto;
      text-align: center;
      padding: 40px 32px;
      background: rgba(28,28,30,.9);
      border: 1px solid var(--border);
      border-radius: 20px;
    }
    .confirm-icon { font-size: 56px; margin-bottom: 16px; }
    .confirm-title { font-size: 22px; font-weight: 700; color: var(--text); margin-bottom: 8px; }
    .confirm-sub { font-size: 14px; color: var(--text-2); line-height: 1.6; margin-bottom: 24px; }
    .confirm-table { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
    .confirm-table td { padding: 8px 0; font-size: 14px; border-bottom: 1px solid var(--border-soft); }
    .confirm-table td:first-child { color: var(--text-3); text-align: left; }
    .confirm-table td:last-child  { color: var(--text); text-align: right; font-weight: 600; }
    .confirm-total { font-size: 24px; font-weight: 700; color: var(--orange); margin-bottom: 24px; }
    .btn-back {
      display: inline-block; padding: 12px 28px; border-radius: 12px;
      background: rgba(255,255,255,.08); color: var(--text);
      text-decoration: none; font-size: 14px; font-weight: 600;
      transition: background .15s;
    }
    .btn-back:hover { background: rgba(255,255,255,.14); }

    /* Responsive */
    @media (max-width: 768px) {
      .hamburger { display: flex; }
      aside.sidebar { position: fixed; top: 0; left: 0; bottom: 0; transform: translateX(-100%); }
      aside.sidebar.open { transform: translateX(0); }
      .sidebar-overlay.open { display: block; }
      main { padding: 16px 16px 100px; }
      .bottom-bar { left: 0; padding: 0 16px; }
    }
  </style>
</head>
<body>

<header>
  <div class="header-left">
    <button class="hdr-btn hamburger" onclick="toggleSidebar()" aria-label="เมนู">
      <svg width="18" height="18" viewBox="0 0 18 18" fill="none">
        <rect y="3" width="18" height="1.5" rx=".75" fill="currentColor"/>
        <rect y="8.25" width="18" height="1.5" rx=".75" fill="currentColor"/>
        <rect y="13.5" width="18" height="1.5" rx=".75" fill="currentColor"/>
      </svg>
    </button>
    <div class="logo"><div class="logo-icon">🐱</div>Catcat</div>
  </div>
  <span class="badge <?= $isAdmin ? 'admin-badge' : '' ?>"><?= $isAdmin ? 'ADMIN' : 'MEMBER' ?></span>
  <div class="header-actions">
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
        <div class="stat-num">฿<?= number_format($userCredits, 0) ?></div>
        <div class="stat-label">เครดิตคงเหลือ</div>
      </div>
      <a href="/rent.php" class="btn-rent">+ เช่าเครื่องเพิ่ม</a>
    </div>

    <div class="nav-section-label">เมนู</div>

    <a class="nav-item" href="/cloud_dashboard.php">
      <svg viewBox="0 0 16 16" fill="none"><rect x="1" y="2" width="14" height="10" rx="2" stroke="currentColor" stroke-width="1.4"/><path d="M5 14h6M8 12v2" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
      เครื่องของฉัน
    </a>
    <a class="nav-item active" href="/rent.php">
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

    <?php if ($confirmed): ?>
    <!-- ── Confirmation screen ────────────────────────── -->
    <div class="confirm-box">
      <div class="confirm-icon">✅</div>
      <div class="confirm-title">เช่าเครื่องสำเร็จ!</div>
      <div class="confirm-sub">เครื่องพร้อมใช้งานทันที ไปที่หน้าหลักเพื่อเริ่มใช้งาน</div>
      <table class="confirm-table">
        <tr><td>แพ็กเกจ</td><td><?= htmlspecialchars($order['tier']) ?> · <?= (int)$order['days'] ?> วัน</td></tr>
        <tr><td>เซิร์ฟเวอร์</td><td><?= htmlspecialchars($order['server']) ?></td></tr>
        <tr><td>จำนวน</td><td><?= (int)$order['qty'] ?> เครื่อง</td></tr>
        <tr><td>เครื่องที่ได้รับ</td><td><?= htmlspecialchars(implode(', ', $order['serials'])) ?></td></tr>
      </table>
      <div class="confirm-total">฿<?= number_format($order['price']) ?></div>
      <div style="font-size:12px;color:var(--text-3);margin-top:-16px;margin-bottom:20px;">เครดิตคงเหลือ ฿<?= number_format($order['credits_left'], 2) ?></div>
      <a href="/cloud_dashboard.php" class="btn-back">← ไปหน้าหลัก</a>
    </div>

    <?php else: ?>
    <!-- ── Rental form ─────────────────────────────────── -->
    <div class="page-title">เช่าเครื่อง</div>

    <?php if ($error): ?>
    <div style="background:rgba(255,69,58,.12);border:1px solid rgba(255,69,58,.25);border-radius:12px;padding:12px 16px;font-size:13px;color:#ff6961;margin-bottom:16px;">
      <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <form method="post" id="rentForm">

      <div class="rent-panel">

        <!-- Tier tabs -->
        <div class="tier-tabs" id="tierTabs">
          <?php foreach (array_keys($tiers) as $t): ?>
          <button type="button" class="tier-tab<?= $t === 'SVIP' ? ' active' : '' ?>"
                  data-tier="<?= $t ?>" onclick="selectTier('<?= $t ?>')">
            <?= $t ?>
            <span class="tab-avail"><?= $availCount[$t] ?? 0 ?></span>
          </button>
          <?php endforeach; ?>
        </div>

        <!-- Specs -->
        <div class="specs-row" id="specsRow"></div>

        <!-- Server -->
        <div class="rent-section">
          <div class="section-title">เลือกเซิร์ฟเวอร์</div>
          <div class="server-group" id="serverGroup">
            <?php foreach ($servers as $i => $s):
              $cls = $s['ms'] <= 20 ? '' : ($s['ms'] <= 60 ? '' : ($s['ms'] <= 80 ? 'slow' : 'vslow'));
            ?>
            <div class="server-chip<?= $i === 0 ? ' active' : '' ?>"
                 data-server="<?= $s['id'] ?>" onclick="selectServer('<?= $s['id'] ?>')">
              <span class="server-ping <?= $cls ?>"><?= $s['ms'] ?> ms</span>
              <span class="server-name"><?= $s['name'] ?></span>
            </div>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Quantity -->
        <div class="rent-section">
          <div class="qty-row">
            <span class="qty-label">จำนวนเครื่อง</span>
            <div class="qty-ctrl">
              <button type="button" class="qty-btn" onclick="changeQty(-1)">−</button>
              <div class="qty-val" id="qtyVal">1</div>
              <button type="button" class="qty-btn" onclick="changeQty(1)">+</button>
            </div>
          </div>
        </div>

        <!-- Packages -->
        <div class="rent-section">
          <div class="section-title">เลือกแพ็กเกจ</div>
          <div class="pkg-list" id="pkgList"></div>
        </div>

      </div><!-- .rent-panel -->

      <!-- Hidden fields -->
      <input type="hidden" name="csrf"   value="<?= $csrf ?>">
      <input type="hidden" name="tier"   id="f_tier"   value="SVIP">
      <input type="hidden" name="server" id="f_server" value="th">
      <input type="hidden" name="qty"    id="f_qty"    value="1">
      <input type="hidden" name="pkg"    id="f_pkg"    value="0">

    </form><!-- #rentForm -->

    <!-- Bottom bar -->
    <div class="bottom-bar">
      <span class="total-price" id="totalPrice">฿350</span>
      <button class="next-btn" onclick="document.getElementById('rentForm').submit()">NEXT</button>
    </div>

    <?php endif; ?>

  </main>

</div><!-- .layout -->

<script>
const CATALOG = <?= json_encode($tiers, JSON_UNESCAPED_UNICODE) ?>;

let state = {
  tier:   'SVIP',
  server: 'th',
  qty:    1,
  pkg:    0,
};

// ── Tier colors ───────────────────────────────────────────────────────────────
const TIER_COLORS = {
  VIP:  { c: '#4a9eff', bg: 'rgba(74,158,255,.15)', border: 'rgba(74,158,255,.4)' },
  KVIP: { c: '#a855f7', bg: 'rgba(168,85,247,.15)', border: 'rgba(168,85,247,.4)' },
  SVIP: { c: '#0a84ff', bg: 'rgba(10,132,255,.15)', border: 'rgba(10,132,255,.4)' },
  XVIP: { c: '#ff9f0a', bg: 'rgba(255,159,10,.15)', border: 'rgba(255,159,10,.4)' },
};

function applyTierColors(tier) {
  const t = TIER_COLORS[tier] || TIER_COLORS.SVIP;
  document.documentElement.style.setProperty('--tier-color',        t.c);
  document.documentElement.style.setProperty('--tier-color-bg',     t.bg);
  document.documentElement.style.setProperty('--tier-color-border', t.border);
  document.querySelectorAll('.tier-tab.active').forEach(el => el.style.setProperty('--tier-color', t.c));
}

// ── Render specs ──────────────────────────────────────────────────────────────
function renderSpecs(tier) {
  const specs = CATALOG[tier].specs;
  const row = document.getElementById('specsRow');
  row.innerHTML = specs.map(s => `
    <div class="spec-item">
      <div class="spec-icon">${s.icon}</div>
      <div class="spec-label">${s.label}</div>
    </div>
  `).join('');
}

// ── Render packages ───────────────────────────────────────────────────────────
function renderPackages(tier, qty, selectedPkg) {
  const pkgs = CATALOG[tier].packages;
  const list = document.getElementById('pkgList');
  list.innerHTML = pkgs.map((p, i) => {
    const perDay = (p.price / p.days).toFixed(2);
    const total  = p.price * qty;
    return `
      <div class="pkg-card${i === selectedPkg ? ' active' : ''}" onclick="selectPkg(${i})">
        <div class="pkg-days">${p.days}-Day</div>
        <div class="pkg-right">
          <div class="pkg-price">฿${total.toLocaleString()}</div>
          <div class="pkg-orig"><s>฿${(p.orig * qty).toLocaleString()}</s> ≈${perDay}/day</div>
        </div>
      </div>
    `;
  }).join('');
}

// ── Update total price ────────────────────────────────────────────────────────
function updateTotal() {
  const pkg = CATALOG[state.tier].packages[state.pkg];
  const total = pkg.price * state.qty;
  document.getElementById('totalPrice').textContent = '฿' + total.toLocaleString();
}

// ── Selection handlers ────────────────────────────────────────────────────────
function selectTier(tier) {
  state.tier = tier;
  state.pkg  = 0;
  document.querySelectorAll('.tier-tab').forEach(el => el.classList.toggle('active', el.dataset.tier === tier));
  document.getElementById('f_tier').value = tier;
  applyTierColors(tier);
  renderSpecs(tier);
  renderPackages(tier, state.qty, 0);
  updateTotal();
}

function selectServer(id) {
  state.server = id;
  document.querySelectorAll('.server-chip').forEach(el => el.classList.toggle('active', el.dataset.server === id));
  document.getElementById('f_server').value = id;
}

function changeQty(delta) {
  state.qty = Math.max(1, Math.min(10, state.qty + delta));
  document.getElementById('qtyVal').textContent = state.qty;
  document.getElementById('f_qty').value = state.qty;
  renderPackages(state.tier, state.qty, state.pkg);
  updateTotal();
}

function selectPkg(i) {
  state.pkg = i;
  document.querySelectorAll('.pkg-card').forEach((el, idx) => el.classList.toggle('active', idx === i));
  document.getElementById('f_pkg').value = i;
  updateTotal();
}

// ── Sidebar toggle ────────────────────────────────────────────────────────────
function toggleSidebar() {
  document.getElementById('sidebar').classList.toggle('open');
  document.querySelector('.sidebar-overlay').classList.toggle('open');
}
function closeSidebar() {
  document.getElementById('sidebar').classList.remove('open');
  document.querySelector('.sidebar-overlay').classList.remove('open');
}

// ── Init ──────────────────────────────────────────────────────────────────────
const INIT = <?= json_encode($formState ?? ['tier'=>'SVIP','server'=>'th','qty'=>1,'pkg'=>0]) ?>;
state.tier   = INIT.tier;
state.server = INIT.server;
state.qty    = INIT.qty;
state.pkg    = INIT.pkg;
document.querySelectorAll('.tier-tab').forEach(el => el.classList.toggle('active', el.dataset.tier === INIT.tier));
document.querySelectorAll('.server-chip').forEach(el => el.classList.toggle('active', el.dataset.server === INIT.server));
document.getElementById('qtyVal').textContent = INIT.qty;
document.getElementById('f_tier').value   = INIT.tier;
document.getElementById('f_server').value = INIT.server;
document.getElementById('f_qty').value    = INIT.qty;
document.getElementById('f_pkg').value    = INIT.pkg;
applyTierColors(INIT.tier);
renderSpecs(INIT.tier);
renderPackages(INIT.tier, INIT.qty, INIT.pkg);
</script>

</body>
</html>
