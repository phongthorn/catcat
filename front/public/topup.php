<?php
require_once __DIR__ . '/../../back/lib/auth.php';
$user    = require_login();
$initial = strtoupper(substr($user['username'], 0, 1));
$isAdmin = ($user['role'] === 'admin');

start_session();
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf'];

$pdo = db();

// ── Fetch user credits ────────────────────────────────────────────────────────
$stCredit = $pdo->prepare('SELECT credits FROM users WHERE id = ?');
$stCredit->execute([$user['id']]);
$userCredits = (float) $stCredit->fetchColumn();

// ── Handle form submission ────────────────────────────────────────────────────
$flash     = '';
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['csrf'] ?? '') !== $csrf) {
        http_response_code(403); echo 'CSRF mismatch'; exit;
    }

    $amount = (float)($_POST['amount'] ?? 0);
    $validAmounts = [100, 200, 500, 1000, 2000];

    if (!in_array((int)$amount, $validAmounts)) {
        $flash = 'กรุณาเลือกจำนวนเงินที่กำหนด'; $flashType = 'error';
    } elseif (empty($_FILES['slip']['tmp_name'])) {
        $flash = 'กรุณาแนบสลิปการโอนเงิน'; $flashType = 'error';
    } else {
        $file    = $_FILES['slip'];
        $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'webp'];

        if (!in_array($ext, $allowed)) {
            $flash = 'ไฟล์ต้องเป็น JPG, PNG หรือ WEBP เท่านั้น'; $flashType = 'error';
        } elseif ($file['size'] > 5 * 1024 * 1024) {
            $flash = 'ขนาดไฟล์ต้องไม่เกิน 5MB'; $flashType = 'error';
        } else {
            $uploadDir = __DIR__ . '/uploads/slips/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            $filename  = date('Ymd_His') . '_u' . $user['id'] . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $destPath  = $uploadDir . $filename;

            if (move_uploaded_file($file['tmp_name'], $destPath)) {
                $pdo->prepare(
                    'INSERT INTO topup_requests (user_id, amount, slip_path) VALUES (?, ?, ?)'
                )->execute([$user['id'], $amount, 'uploads/slips/' . $filename]);
                header('Location: /cloud_dashboard.php?topup=ok');
                exit;
            } else {
                $flash = 'อัปโหลดไฟล์ล้มเหลว กรุณาลองใหม่'; $flashType = 'error';
            }
        }
    }
}

// ── Fetch user's topup history ────────────────────────────────────────────────
$history = $pdo->prepare(
    'SELECT id, amount, slip_path, status, note, created_at, reviewed_at
       FROM topup_requests WHERE user_id = ? ORDER BY created_at DESC LIMIT 20'
);
$history->execute([$user['id']]);
$history = $history->fetchAll();

$pendingCount = count(array_filter($history, fn($r) => $r['status'] === 'pending'));
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
  <title>Catcat — เติมเครดิต</title>
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
      --yellow:      #ffd60a;
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

    /* ── Header ── */
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
    .hdr-btn {
      display: flex; align-items: center; justify-content: center;
      width: 32px; height: 32px; border-radius: 8px;
      background: transparent; border: none; color: var(--text-2);
      cursor: pointer; transition: background .15s; text-decoration: none;
    }
    .hdr-btn:hover { background: var(--bg-hover); color: var(--text); }
    .hamburger { display: none; }

    /* ── Layout ── */
    .layout { display: flex; flex: 1; overflow: hidden; }

    /* ── Sidebar ── */
    aside.sidebar {
      width: var(--sidebar-w);
      flex-shrink: 0;
      background: rgba(28,28,30,.95);
      border-right: 1px solid var(--border);
      display: flex; flex-direction: column;
      overflow-y: auto; overflow-x: hidden;
      z-index: 90; transition: transform .25s ease;
    }
    .profile-block { padding: 16px 14px 12px; border-bottom: 1px solid var(--border-soft); }
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
    .btn-topup {
      display: block; width: 100%; padding: 8px 12px; border-radius: 10px;
      background: rgba(48,209,88,.14); border: 1px solid rgba(48,209,88,.25);
      color: var(--green); font-size: 13px; font-weight: 600;
      text-align: center; text-decoration: none; transition: background .15s;
    }
    .btn-topup:hover { background: rgba(48,209,88,.22); }
    .nav-section-label {
      padding: 14px 14px 4px;
      font-size: 10px; font-weight: 600; letter-spacing: .8px; text-transform: uppercase;
      color: var(--text-3);
    }
    .nav-item {
      display: flex; align-items: center; gap: 10px;
      padding: 9px 14px; font-size: 13px; font-weight: 500; color: var(--text-2);
      text-decoration: none; border-radius: 10px; margin: 1px 6px;
      transition: background .12s, color .12s;
    }
    .nav-item svg { width: 16px; height: 16px; flex-shrink: 0; }
    .nav-item:hover { background: var(--bg-hover); color: var(--text); }
    .nav-item.active { background: rgba(10,132,255,.15); color: var(--accent); }
    .nav-spacer { flex: 1; min-height: 12px; }
    .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.5); z-index: 85; backdrop-filter: blur(4px); }

    /* ── Main ── */
    main { flex: 1; overflow-y: auto; padding: 24px 28px 40px; }
    .page-title { font-size: 22px; font-weight: 700; color: var(--text); margin-bottom: 6px; letter-spacing: -.3px; }
    .page-sub   { font-size: 13px; color: var(--text-3); margin-bottom: 24px; }

    /* ── Flash ── */
    .flash {
      padding: 12px 16px; border-radius: 12px; font-size: 13px; margin-bottom: 20px;
    }
    .flash.success { background: rgba(48,209,88,.12); border: 1px solid rgba(48,209,88,.25); color: #34c759; }
    .flash.error   { background: rgba(255,69,58,.12);  border: 1px solid rgba(255,69,58,.25);  color: #ff6961; }

    /* ── Form card ── */
    .card {
      background: rgba(28,28,30,.9);
      border: 1px solid var(--border);
      border-radius: 18px;
      padding: 24px;
      max-width: 520px;
      margin-bottom: 24px;
    }
    .card-title { font-size: 15px; font-weight: 700; color: var(--text); margin-bottom: 16px; }

    /* ── Amount presets ── */
    .amount-grid {
      display: grid;
      grid-template-columns: repeat(5, 1fr);
      gap: 8px;
      margin-bottom: 20px;
    }
    .amount-btn {
      padding: 12px 6px; border-radius: 12px;
      border: 1px solid var(--border);
      background: rgba(255,255,255,.04);
      color: var(--text-2); font-size: 13px; font-weight: 700;
      cursor: pointer; transition: all .15s; text-align: center;
    }
    .amount-btn:hover { border-color: rgba(255,255,255,.2); color: var(--text); }
    .amount-btn.active {
      background: rgba(48,209,88,.13);
      border-color: rgba(48,209,88,.4);
      color: var(--green);
    }
    .amount-label { font-size: 10px; color: var(--text-3); margin-top: 2px; font-weight: 500; }

    /* ── Upload zone ── */
    .upload-zone {
      border: 2px dashed var(--border);
      border-radius: 14px;
      padding: 28px 20px;
      text-align: center;
      cursor: pointer;
      transition: border-color .2s, background .2s;
      position: relative;
      margin-bottom: 20px;
    }
    .upload-zone:hover, .upload-zone.drag { border-color: var(--green); background: rgba(48,209,88,.05); }
    .upload-zone input[type=file] {
      position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%;
    }
    .upload-icon { font-size: 32px; margin-bottom: 8px; }
    .upload-text { font-size: 13px; color: var(--text-2); }
    .upload-hint { font-size: 11px; color: var(--text-3); margin-top: 4px; }
    .preview-img { max-width: 100%; max-height: 200px; border-radius: 10px; margin-top: 12px; object-fit: contain; }

    /* ── Submit btn ── */
    .submit-btn {
      width: 100%; padding: 14px; border-radius: 14px;
      background: var(--green); border: none;
      color: #fff; font-size: 15px; font-weight: 700;
      cursor: pointer; transition: opacity .15s;
      font-family: inherit;
    }
    .submit-btn:hover   { opacity: .88; }
    .submit-btn:disabled { opacity: .4; cursor: not-allowed; }

    /* ── History table ── */
    .history-card { max-width: 520px; }
    .history-title { font-size: 15px; font-weight: 700; color: var(--text); margin-bottom: 14px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 10px 12px; text-align: left; font-size: 13px; border-bottom: 1px solid var(--border-soft); }
    th { color: var(--text-3); font-weight: 600; font-size: 11px; letter-spacing: .5px; text-transform: uppercase; }
    td { color: var(--text-2); }
    td.amount { color: var(--text); font-weight: 700; }
    .status-badge {
      display: inline-block; padding: 3px 9px; border-radius: 6px;
      font-size: 11px; font-weight: 700; letter-spacing: .3px;
    }
    .status-badge.pending  { background: rgba(255,214,10,.12); color: var(--yellow); }
    .status-badge.approved { background: rgba(48,209,88,.12);  color: var(--green); }
    .status-badge.rejected { background: rgba(255,69,58,.12);  color: var(--red); }
    .slip-link { color: var(--accent); text-decoration: none; font-size: 12px; }
    .slip-link:hover { text-decoration: underline; }
    .empty-row { color: var(--text-3); font-size: 13px; padding: 20px 12px; }

    /* ── Responsive ── */
    @media (max-width: 768px) {
      .hamburger { display: flex; }
      aside.sidebar { position: fixed; top: 0; left: 0; bottom: 0; transform: translateX(-100%); }
      aside.sidebar.open { transform: translateX(0); }
      .sidebar-overlay.open { display: block; }
      main { padding: 16px 16px 40px; }
      .amount-grid { grid-template-columns: repeat(3, 1fr); }
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
  <div style="display:flex;gap:6px;">
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
      <a href="/topup.php" class="btn-topup">+ เติมเครดิต</a>
    </div>

    <div class="nav-section-label">เมนู</div>
    <a class="nav-item" href="/cloud_dashboard.php">
      <svg viewBox="0 0 16 16" fill="none"><rect x="1" y="2" width="14" height="10" rx="2" stroke="currentColor" stroke-width="1.4"/><path d="M5 14h6M8 12v2" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
      เครื่องของฉัน
    </a>
    <a class="nav-item" href="/rent.php">
      <svg viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="6.25" stroke="currentColor" stroke-width="1.4"/><path d="M8 5v3l2 2" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
      เช่าเครื่อง
    </a>
    <a class="nav-item active" href="/topup.php">
      <svg viewBox="0 0 16 16" fill="none"><path d="M8 2v12M3 7l5-5 5 5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
      เติมเครดิต
    </a>
    <?php if ($isAdmin): ?>
    <a class="nav-item" href="/admin_dashboard.php">
      <svg viewBox="0 0 16 16" fill="none"><path d="M2 12V9a6 6 0 1 1 12 0v3" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/><rect x="1" y="11" width="4" height="3" rx="1" stroke="currentColor" stroke-width="1.4"/><rect x="11" y="11" width="4" height="3" rx="1" stroke="currentColor" stroke-width="1.4"/></svg>
      แอดมิน
    </a>
    <?php endif; ?>

    <div class="nav-spacer"></div>
    <a class="nav-item" href="/logout.php">
      <svg viewBox="0 0 16 16" fill="none"><path d="M6 2H3a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h3" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/><path d="M11 11l3-3-3-3M14 8H6" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
      ออกจากระบบ
    </a>
  </aside>

  <main>
    <div class="page-title">เติมเครดิต</div>
    <div class="page-sub">โอนเงินแล้วแนบสลิป — admin จะอนุมัติภายใน 24 ชั่วโมง</div>

    <?php if ($flash): ?>
    <div class="flash <?= $flashType ?>"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>

    <!-- Top-up form -->
    <div class="card">
      <div class="card-title">เลือกจำนวนเงิน</div>
      <form method="post" enctype="multipart/form-data" id="topupForm">
        <input type="hidden" name="csrf"   value="<?= $csrf ?>">
        <input type="hidden" name="amount" id="f_amount" value="">

        <div class="amount-grid">
          <?php foreach ([100, 200, 500, 1000, 2000] as $amt): ?>
          <div class="amount-btn" onclick="selectAmount(<?= $amt ?>)">
            <div>฿<?= number_format($amt) ?></div>
            <div class="amount-label"><?= $amt >= 1000 ? number_format($amt/1000, 1).'K' : $amt.' บาท' ?></div>
          </div>
          <?php endforeach; ?>
        </div>

        <div class="card-title">แนบสลิปโอนเงิน</div>
        <div class="upload-zone" id="uploadZone">
          <input type="file" name="slip" id="slipInput" accept="image/jpeg,image/png,image/webp"
                 onchange="previewSlip(this)">
          <div id="uploadContent">
            <div class="upload-icon">📎</div>
            <div class="upload-text">คลิกหรือลากไฟล์มาวางที่นี่</div>
            <div class="upload-hint">JPG, PNG, WEBP · ไม่เกิน 5MB</div>
          </div>
        </div>

        <button type="submit" class="submit-btn" id="submitBtn" disabled
                onclick="this.disabled=true;this.textContent='กำลังส่ง…';this.form.submit();">ส่งคำขอเติมเครดิต</button>
      </form>
    </div>

    <!-- History -->
    <div class="card history-card">
      <div class="history-title">ประวัติคำขอ</div>
      <?php if (empty($history)): ?>
        <div class="empty-row">ยังไม่มีประวัติการเติมเครดิต</div>
      <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>วันที่</th>
            <th>จำนวน</th>
            <th>สลิป</th>
            <th>สถานะ</th>
            <th>หมายเหตุ</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($history as $r): ?>
          <tr>
            <td><?= date('d/m/y H:i', strtotime($r['created_at'])) ?></td>
            <td class="amount">฿<?= number_format((float)$r['amount']) ?></td>
            <td><a class="slip-link" href="/<?= htmlspecialchars($r['slip_path']) ?>" target="_blank">ดูสลิป</a></td>
            <td><span class="status-badge <?= $r['status'] ?>">
              <?= $r['status'] === 'pending' ? 'รอดำเนินการ' : ($r['status'] === 'approved' ? 'อนุมัติแล้ว' : 'ปฏิเสธ') ?>
            </span></td>
            <td><?= htmlspecialchars($r['note'] ?? '') ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </main>
</div>

<script>
let selectedAmount = 0;
let hasSlip = false;

function selectAmount(amt) {
  selectedAmount = amt;
  document.getElementById('f_amount').value = amt;
  document.querySelectorAll('.amount-btn').forEach((el, i) => {
    el.classList.toggle('active', [100,200,500,1000,2000][i] === amt);
  });
  checkReady();
}

function previewSlip(input) {
  if (!input.files[0]) return;
  hasSlip = true;
  const url = URL.createObjectURL(input.files[0]);
  document.getElementById('uploadContent').innerHTML =
    `<img src="${url}" class="preview-img" alt="สลิป">`;
  checkReady();
}

function checkReady() {
  document.getElementById('submitBtn').disabled = !(selectedAmount > 0 && hasSlip);
}

// Drag-and-drop highlight
const zone = document.getElementById('uploadZone');
zone.addEventListener('dragover',  e => { e.preventDefault(); zone.classList.add('drag'); });
zone.addEventListener('dragleave', () => zone.classList.remove('drag'));
zone.addEventListener('drop',      e => { e.preventDefault(); zone.classList.remove('drag'); });

function toggleSidebar() {
  document.getElementById('sidebar').classList.toggle('open');
  document.querySelector('.sidebar-overlay').classList.toggle('open');
}
function closeSidebar() {
  document.getElementById('sidebar').classList.remove('open');
  document.querySelector('.sidebar-overlay').classList.remove('open');
}
</script>
</body>
</html>
