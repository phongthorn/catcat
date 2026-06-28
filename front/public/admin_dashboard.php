<?php
require_once __DIR__ . '/../../back/lib/auth.php';
require_once __DIR__ . '/../../back/lib/adb.php';
$admin = require_admin();

start_session();
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf'];

function csrf_verify(): void {
    if (($_POST['csrf'] ?? '') !== ($_SESSION['csrf'] ?? '')) {
        http_response_code(403); echo 'CSRF mismatch'; exit;
    }
}

$flash      = '';
$flashType  = 'success';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    $pdo    = db();

    if ($action === 'add_device') {
        $serial = trim($_POST['serial'] ?? '');
        $label  = trim($_POST['label'] ?? '');
        if ($serial !== '') {
            $pdo->prepare('INSERT IGNORE INTO devices (serial, label, is_rentable) VALUES (?,?,1)')
                ->execute([$serial, $label ?: $serial]);
            $flash = 'เพิ่มอุปกรณ์สำเร็จ';
        }
    } elseif ($action === 'del_device') {
        $pdo->prepare('DELETE FROM devices WHERE serial = ?')->execute([$_POST['serial'] ?? '']);
        $flash = 'ลบอุปกรณ์สำเร็จ';
    } elseif ($action === 'toggle_rentable') {
        $pdo->prepare('UPDATE devices SET is_rentable = 1 - is_rentable WHERE serial = ?')
            ->execute([$_POST['serial'] ?? '']);
        $flash = 'อัปเดตสถานะการเช่าสำเร็จ';
    } elseif ($action === 'add_user') {
        $uname = trim($_POST['username'] ?? '');
        $pass  = $_POST['password'] ?? '';
        $role  = in_array($_POST['role'] ?? '', ['customer','admin']) ? $_POST['role'] : 'customer';
        if ($uname !== '' && strlen($pass) >= 6) {
            $pdo->prepare('INSERT INTO users (username, password_hash, role) VALUES (?,?,?)')
                ->execute([$uname, password_hash($pass, PASSWORD_DEFAULT), $role]);
            $flash = 'เพิ่มผู้ใช้สำเร็จ';
        } else {
            $flash = 'กรุณากรอกชื่อผู้ใช้และรหัสผ่าน (≥6 ตัว)'; $flashType = 'error';
        }
    } elseif ($action === 'del_user') {
        $uid = (int) ($_POST['uid'] ?? 0);
        if ($uid !== (int) $admin['id']) {
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
            $flash = 'ลบผู้ใช้สำเร็จ';
        } else {
            $flash = 'ไม่สามารถลบบัญชีของตัวเองได้'; $flashType = 'error';
        }
    } elseif ($action === 'set_role') {
        $uid  = (int) ($_POST['uid'] ?? 0);
        $role = in_array($_POST['role'] ?? '', ['customer','admin']) ? $_POST['role'] : 'customer';
        $pdo->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $uid]);
        $flash = 'เปลี่ยน role สำเร็จ';
    } elseif ($action === 'add_lease') {
        $uid    = (int) ($_POST['uid'] ?? 0);
        $serial = $_POST['serial'] ?? '';
        $hours  = max(1, (int) ($_POST['hours'] ?? 24));
        if ($uid && $serial !== '') {
            $pdo->prepare(
                'INSERT INTO leases (user_id, serial, expires_at)
                   VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? HOUR))
                   ON DUPLICATE KEY UPDATE expires_at = DATE_ADD(NOW(), INTERVAL ? HOUR)'
            )->execute([$uid, $serial, $hours, $hours]);
            $flash = "เพิ่ม lease {$hours} ชั่วโมงสำเร็จ";
        }
    } elseif ($action === 'terminate_lease') {
        $pdo->prepare('DELETE FROM leases WHERE id = ?')->execute([(int)($_POST['lid'] ?? 0)]);
        $flash = 'ยกเลิก lease สำเร็จ';
    } elseif ($action === 'extend_lease') {
        $hours = max(1, (int) ($_POST['hours'] ?? 24));
        $pdo->prepare(
            'UPDATE leases SET expires_at = DATE_ADD(GREATEST(NOW(), IFNULL(expires_at, NOW())), INTERVAL ? HOUR) WHERE id = ?'
        )->execute([$hours, (int)($_POST['lid'] ?? 0)]);
        $flash = "ต่ออายุ {$hours} ชั่วโมงสำเร็จ";
    } elseif ($action === 'add_credits') {
        $uid    = (int)($_POST['uid'] ?? 0);
        $amount = (float)($_POST['amount'] ?? 0);
        if ($uid > 0 && $amount > 0) {
            $pdo->prepare('UPDATE users SET credits = credits + ? WHERE id = ?')->execute([$amount, $uid]);
            $flash = 'เติมเครดิต ฿' . number_format($amount, 2) . ' สำเร็จ';
        } else {
            $flash = 'กรุณาระบุจำนวนเครดิตที่ถูกต้อง'; $flashType = 'error';
        }
    } elseif ($action === 'approve_topup') {
        $rid = (int)($_POST['rid'] ?? 0);
        if ($rid > 0) {
            $pdo->beginTransaction();
            try {
                // Lock the row and flip status atomically — prevents double-approve under concurrent requests
                $upd = $pdo->prepare('UPDATE topup_requests SET status="approved", reviewed_at=NOW(), reviewed_by=? WHERE id=? AND status="pending"');
                $upd->execute([$admin['id'], $rid]);
                if ($upd->rowCount() === 1) {
                    $req = $pdo->prepare('SELECT user_id, amount FROM topup_requests WHERE id=?');
                    $req->execute([$rid]);
                    $req = $req->fetch();
                    $pdo->prepare('UPDATE users SET credits = credits + ? WHERE id = ?')
                        ->execute([$req['amount'], $req['user_id']]);
                    $pdo->commit();
                    $flash = 'อนุมัติเติมเครดิต ฿' . number_format($req['amount'], 2) . ' สำเร็จ';
                } else {
                    $pdo->rollBack();
                    $flash = 'ไม่พบคำขอหรืออนุมัติแล้ว'; $flashType = 'error';
                }
            } catch (Throwable $e) {
                $pdo->rollBack();
                $flash = 'เกิดข้อผิดพลาด'; $flashType = 'error';
            }
        }
    } elseif ($action === 'reject_topup') {
        $rid  = (int)($_POST['rid'] ?? 0);
        $note = trim($_POST['note'] ?? '');
        if ($rid > 0) {
            $pdo->prepare('UPDATE topup_requests SET status="rejected", note=?, reviewed_at=NOW(), reviewed_by=? WHERE id=? AND status="pending"')
                ->execute([$note ?: null, $admin['id'], $rid]);
            $flash = 'ปฏิเสธคำขอแล้ว';
        }
    } elseif ($action === 'kill_session') {
        $sid = trim($_POST['sid'] ?? '');
        if ($sid !== '') {
            $row = $pdo->prepare('SELECT serial FROM sessions WHERE session_id = ?');
            $row->execute([$sid]);
            $row = $row->fetch();
            $pdo->prepare('DELETE FROM sessions WHERE session_id = ?')->execute([$sid]);
            log_activity('admin_kill_session', $row['serial'] ?? null, $sid);
            $flash = 'ยกเลิก session สำเร็จ';
        }
    } elseif ($action === 'set_device_tier') {
        $serial = trim($_POST['serial'] ?? '');
        $tier   = $_POST['tier'] ?? '';
        if ($serial !== '' && in_array($tier, ['VIP','KVIP','SVIP','XVIP',''])) {
            $pdo->prepare('UPDATE devices SET tier = ? WHERE serial = ?')->execute([$tier ?: null, $serial]);
            $flash = 'อัปเดตประเภทการเช่าสำเร็จ';
        }
    }

    header('Location: /admin_dashboard.php?tab=' . urlencode($_POST['tab'] ?? 'devices') . '&flash=' . urlencode($flash) . '&ft=' . $flashType);
    exit;
}

$tab       = $_GET['tab']   ?? 'monitor';
$flash     = $flash ?: ($_GET['flash'] ?? '');
$flashType = $flashType !== 'success' ? $flashType : ($_GET['ft'] ?? 'success');

$pdo = db();
try { $online = adb_list_devices(); } catch (Throwable $e) { $online = []; }

$devices = $pdo->query(
    'SELECT d.serial, d.label, d.is_rentable, d.tier, d.created_at,
            COUNT(l.id) AS lease_count,
            (SELECT u.username FROM leases al JOIN users u ON u.id = al.user_id
              WHERE al.serial = d.serial AND (al.expires_at IS NULL OR al.expires_at > NOW()) LIMIT 1) AS current_lessee
       FROM devices d LEFT JOIN leases l ON l.serial = d.serial AND (l.expires_at IS NULL OR l.expires_at > NOW())
      GROUP BY d.serial, d.label, d.is_rentable, d.tier, d.created_at ORDER BY d.label, d.serial'
)->fetchAll();

$users = $pdo->query(
    'SELECT u.id, u.username, u.role, u.credits, u.created_at, COUNT(l.id) AS lease_count
       FROM users u LEFT JOIN leases l ON l.user_id = u.id AND (l.expires_at IS NULL OR l.expires_at > NOW())
      GROUP BY u.id ORDER BY u.username'
)->fetchAll();

$leases = $pdo->query(
    'SELECT l.id, l.tier, l.expires_at, l.created_at, u.username, d.serial, d.label
       FROM leases l JOIN users u ON u.id = l.user_id JOIN devices d ON d.serial = l.serial
      ORDER BY l.expires_at IS NULL DESC, l.expires_at ASC'
)->fetchAll();

$sessions = $pdo->query(
    'SELECT s.session_id, s.serial, s.created_at, u.username, d.label AS device_label
       FROM sessions s
       JOIN users u ON u.id = s.user_id
       LEFT JOIN devices d ON d.serial = s.serial
      ORDER BY s.created_at DESC LIMIT 100'
)->fetchAll();

$logs = $pdo->query(
    'SELECT al.id, al.username, al.action, al.serial, al.detail, al.ip, al.created_at,
            d.label AS device_label
       FROM activity_logs al
       LEFT JOIN devices d ON d.serial = al.serial
      ORDER BY al.created_at DESC LIMIT 300'
)->fetchAll();

$topups = $pdo->query(
    'SELECT t.id, t.amount, t.slip_path, t.status, t.note, t.created_at, t.reviewed_at,
            u.username, u.id AS user_id,
            r.username AS reviewer
       FROM topup_requests t
       JOIN users u ON u.id = t.user_id
       LEFT JOIN users r ON r.id = t.reviewed_by
      ORDER BY (t.status = "pending") DESC, t.created_at DESC
      LIMIT 100'
)->fetchAll();
$pendingTopups = count(array_filter($topups, fn($t) => $t['status'] === 'pending'));

$totalDevices  = count($devices);
$onlineDevices = count(array_filter($devices, fn($d) => ($online[$d['serial']] ?? '') === 'device'));
$totalUsers    = count($users);
$activeLeases  = count($leases);
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Catcat — Admin</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      --bg:          #1c1c1e;
      --bg-2:        #2c2c2e;
      --bg-card:     rgba(44,44,46,.75);
      --bg-input:    rgba(58,58,60,.8);
      --bg-hover:    rgba(255,255,255,.055);
      --border:      rgba(255,255,255,.08);
      --border-soft: rgba(255,255,255,.05);
      --text:        #f5f5f7;
      --text-2:      rgba(235,235,245,.6);
      --text-3:      rgba(235,235,245,.32);
      --accent:      #0a84ff;
      --green:       #30d158;
      --yellow:      #ffd60a;
      --red:         #ff453a;
      --orange:      #ff9f0a;
    }

    html, body { height: 100%; }
    body {
      background: var(--bg);
      color: var(--text);
      font-family: -apple-system, BlinkMacSystemFont, "SF Pro Display", "Helvetica Neue", Arial, sans-serif;
      -webkit-font-smoothing: antialiased;
      font-size: 14px;
    }

    /* ── Header ── */
    header {
      height: 52px;
      background: rgba(28,28,30,.88);
      backdrop-filter: blur(24px) saturate(180%);
      -webkit-backdrop-filter: blur(24px) saturate(180%);
      border-bottom: 1px solid var(--border);
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 0 20px;
      position: sticky;
      top: 0;
      z-index: 50;
    }

    .logo {
      display: flex; align-items: center; gap: 8px;
      font-size: 15px; font-weight: 700; color: var(--text);
      letter-spacing: -.2px;
    }

    .logo-icon {
      width: 26px; height: 26px; border-radius: 6px;
      background: linear-gradient(145deg,#2a2a2e,#1a1a1e);
      border: 1px solid rgba(255,255,255,.12);
      display: flex; align-items: center; justify-content: center;
      font-size: 14px;
    }

    .admin-badge {
      font-size: 10px; font-weight: 700; letter-spacing: .8px;
      color: var(--orange); background: rgba(255,159,10,.15);
      border: 1px solid rgba(255,159,10,.25);
      padding: 2px 7px; border-radius: 99px;
    }

    .header-right {
      display: flex; align-items: center; gap: 6px;
    }

    .hdr-link {
      display: flex; align-items: center; gap: 6px;
      padding: 6px 12px; border-radius: 8px;
      font-size: 13px; font-weight: 500;
      color: var(--text-2); text-decoration: none;
      transition: background .12s, color .12s;
    }
    .hdr-link:hover { background: var(--bg-hover); color: var(--text); }

    /* ── Main ── */
    .page { max-width: 1100px; margin: 0 auto; padding: 24px 20px 48px; }

    /* ── Stats grid ── */
    .stats {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 12px;
      margin-bottom: 24px;
    }

    .stat-card {
      background: var(--bg-card);
      border: 1px solid var(--border);
      border-radius: 14px;
      padding: 18px 20px;
      backdrop-filter: blur(20px);
    }

    .stat-val {
      font-size: 30px; font-weight: 700;
      line-height: 1; letter-spacing: -.5px;
      margin-bottom: 4px;
    }
    .stat-val.green  { color: var(--green); }
    .stat-val.blue   { color: var(--accent); }
    .stat-val.yellow { color: var(--yellow); }
    .stat-lbl { font-size: 12px; color: var(--text-3); }

    /* ── Flash ── */
    .flash {
      padding: 10px 16px;
      border-radius: 10px;
      font-size: 13px;
      margin-bottom: 16px;
    }
    .flash.success { background: rgba(48,209,88,.12); border: 1px solid rgba(48,209,88,.25); color: #34d45f; }
    .flash.error   { background: rgba(255,69,58,.12);  border: 1px solid rgba(255,69,58,.25);  color: #ff6961; }

    /* ── Segment control (tabs) ── */
    .seg-wrap {
      display: flex;
      background: rgba(44,44,46,.7);
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 3px;
      margin-bottom: 20px;
      width: fit-content;
    }

    .seg-btn {
      padding: 7px 20px;
      border: none; border-radius: 8px;
      background: transparent;
      color: var(--text-2);
      font-size: 13px; font-weight: 500;
      font-family: inherit;
      cursor: pointer;
      transition: background .15s, color .15s, box-shadow .15s;
    }
    .seg-btn.active {
      background: rgba(255,255,255,.12);
      color: var(--text);
      font-weight: 600;
      box-shadow: 0 1px 4px rgba(0,0,0,.3);
    }

    /* ── Panel ── */
    .tab-pane { display: none; }
    .tab-pane.active { display: block; }

    /* ── Section card ── */
    .section {
      background: var(--bg-card);
      border: 1px solid var(--border);
      border-radius: 14px;
      overflow: hidden;
      margin-bottom: 16px;
      backdrop-filter: blur(20px);
    }

    .section-head {
      padding: 14px 18px;
      border-bottom: 1px solid var(--border-soft);
      font-size: 13px;
      font-weight: 600;
      color: var(--text-2);
      letter-spacing: .1px;
    }

    /* ── Table ── */
    table { width: 100%; border-collapse: collapse; }
    th {
      padding: 9px 16px;
      text-align: left;
      font-size: 11px;
      font-weight: 600;
      letter-spacing: .5px;
      text-transform: uppercase;
      color: var(--text-3);
      border-bottom: 1px solid var(--border-soft);
    }
    td {
      padding: 11px 16px;
      border-bottom: 1px solid var(--border-soft);
      vertical-align: middle;
    }
    tr:last-child td { border-bottom: none; }
    tbody tr:hover td { background: var(--bg-hover); }

    .mono { font-family: "SF Mono", "Fira Code", monospace; font-size: 12px; color: var(--text-2); }
    .muted { color: var(--text-3); }
    .nowrap { white-space: nowrap; }

    /* Status dot */
    .dot {
      display: inline-block; width: 7px; height: 7px;
      border-radius: 50%; margin-right: 5px;
    }
    .dot.on  { background: var(--green); box-shadow: 0 0 5px rgba(48,209,88,.5); }
    .dot.off { background: rgba(235,235,245,.2); }

    /* Inline badge */
    .pill {
      display: inline-block;
      padding: 2px 9px; border-radius: 99px;
      font-size: 11px; font-weight: 600;
    }
    .pill.admin    { background: rgba(255,159,10,.15); color: var(--orange); }
    .pill.customer { background: rgba(10,132,255,.15); color: var(--accent); }
    .pill.rentable { background: rgba(48,209,88,.15);  color: var(--green); }
    .pill.no       { background: rgba(255,69,58,.15);  color: var(--red); }

    /* ── Buttons ── */
    .btn {
      display: inline-flex; align-items: center; gap: 5px;
      padding: 6px 14px;
      border: none; border-radius: 8px;
      font-size: 13px; font-weight: 600;
      font-family: inherit; cursor: pointer;
      text-decoration: none; line-height: 1.4;
      transition: opacity .12s, transform .08s;
    }
    .btn:active { transform: scale(.97); }

    .btn-primary { background: var(--accent); color: #fff; }
    .btn-primary:hover { opacity: .85; }

    .btn-danger  { background: rgba(255,69,58,.18); color: var(--red); }
    .btn-danger:hover  { background: rgba(255,69,58,.28); }

    .btn-neutral { background: rgba(255,255,255,.08); color: var(--text-2); }
    .btn-neutral:hover { background: rgba(255,255,255,.13); color: var(--text); }

    .btn-sm { padding: 4px 10px; font-size: 12px; border-radius: 6px; }

    /* ── Add forms ── */
    .add-form {
      padding: 14px 18px;
      border-top: 1px solid var(--border-soft);
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
      align-items: flex-end;
    }

    .fg { display: flex; flex-direction: column; gap: 5px; }
    .fg label {
      font-size: 11px; font-weight: 600;
      letter-spacing: .4px; text-transform: uppercase;
      color: var(--text-3);
    }

    input[type=text], input[type=password], input[type=number], select {
      background: var(--bg-input);
      border: 1px solid var(--border);
      border-radius: 8px;
      padding: 8px 12px;
      font-size: 13px; color: var(--text);
      font-family: inherit;
      outline: none;
      min-width: 140px;
      transition: border-color .15s, box-shadow .15s;
    }
    input::placeholder { color: var(--text-3); }
    input:focus, select:focus {
      border-color: rgba(10,132,255,.5);
      box-shadow: 0 0 0 3px rgba(10,132,255,.12);
    }
    select option { background: #2c2c2e; }

    /* toggle-rentable inline button */
    .toggle-btn {
      border: none; border-radius: 99px; cursor: pointer;
      font-family: inherit; font-size: 11px; font-weight: 600;
      padding: 2px 9px;
    }

    /* ── Monitor widgets ── */
    .mon-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 12px;
      margin-bottom: 16px;
    }
    .mon-card {
      background: var(--bg-card);
      border: 1px solid var(--border);
      border-radius: 14px;
      padding: 18px 20px;
      backdrop-filter: blur(20px);
    }
    .mon-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; }
    .mon-label { font-size: 11px; color: var(--text-3); font-weight: 600; letter-spacing: .5px; text-transform: uppercase; }
    .mon-icon  { font-size: 16px; opacity: .6; }
    .mon-val   { font-size: 28px; font-weight: 700; line-height: 1; letter-spacing: -.5px; margin-bottom: 10px; }
    .mon-val.green { color: var(--green); }
    .mon-val.blue  { color: var(--accent); }
    .mon-val.warn  { color: var(--yellow); }
    .mon-val.crit  { color: var(--red); }
    .mon-sub  { font-size: 11px; color: var(--text-3); margin-top: 4px; }
    .mon-bar-wrap { height: 3px; background: rgba(255,255,255,.07); border-radius: 99px; overflow: hidden; }
    .mon-bar      { height: 100%; border-radius: 99px; background: var(--accent); transition: width .5s ease; width: 0%; }
    .mon-bar.warn { background: var(--yellow); }
    .mon-bar.crit { background: var(--red); }
    .mon-net-row  { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 4px; }
    .mon-net-val  { font-size: 20px; font-weight: 700; letter-spacing: -.3px; }
    .mon-net-lbl  { font-size: 10px; color: var(--text-3); font-weight: 600; letter-spacing: .4px; text-transform: uppercase; }

    @media (max-width: 900px) { .mon-grid { grid-template-columns: repeat(2, 1fr); } }
    @media (max-width: 700px) {
      .stats { grid-template-columns: repeat(2, 1fr); }
      .seg-wrap { width: 100%; }
      .seg-btn { flex: 1; text-align: center; }
      .mon-grid { grid-template-columns: 1fr 1fr; }
    }
  </style>
</head>
<body>

<header>
  <div class="logo">
    <div class="logo-icon">🐱</div>
    Catcat
    <span class="admin-badge">ADMIN</span>
  </div>
  <div class="header-right">
    <a class="hdr-link" href="/cloud_dashboard.php">
      <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><rect x=".75" y="1.75" width="12.5" height="8.5" rx="1.5" stroke="currentColor" stroke-width="1.3"/><path d="M4.5 12.25h5M7 10.25v2" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></svg>
      Dashboard
    </a>
    <a class="hdr-link" href="/logout.php">
      <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M5.5 2H3a1 1 0 0 0-1 1v8a1 1 0 0 0 1 1h2.5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/><path d="M9.5 9.5l3-3-3-3M12.5 6.5H5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
      ออกจากระบบ
    </a>
  </div>
</header>

<div class="page">

  <!-- Stats -->
  <div class="stats">
    <div class="stat-card">
      <div class="stat-val green"><?= $onlineDevices ?><span style="font-size:16px;opacity:.5"> / <?= $totalDevices ?></span></div>
      <div class="stat-lbl">อุปกรณ์ออนไลน์</div>
    </div>
    <div class="stat-card">
      <div class="stat-val blue"><?= $totalUsers ?></div>
      <div class="stat-lbl">ผู้ใช้ทั้งหมด</div>
    </div>
    <div class="stat-card">
      <div class="stat-val yellow"><?= $activeLeases ?></div>
      <div class="stat-lbl">Lease ที่ใช้งาน</div>
    </div>
    <div class="stat-card">
      <div class="stat-val"><?= count($sessions) ?></div>
      <div class="stat-lbl">Sessions ล่าสุด</div>
    </div>
  </div>

  <?php if ($flash): ?>
  <div class="flash <?= htmlspecialchars($flashType) ?>"><?= htmlspecialchars($flash) ?></div>
  <?php endif; ?>

  <!-- Segment control -->
  <div class="seg-wrap">
    <?php
    $tabDefs = ['monitor' => '📊 Monitor', 'devices' => 'อุปกรณ์', 'users' => 'ผู้ใช้', 'leases' => 'Leases', 'sessions' => 'Sessions', 'topup' => 'เติมเครดิต' . ($pendingTopups > 0 ? " ({$pendingTopups})" : ''), 'logs' => 'Logs'];
    foreach ($tabDefs as $k => $label): ?>
      <button class="seg-btn <?= $tab === $k ? 'active' : '' ?>"
              onclick="switchTab('<?= $k ?>')" id="tab-<?= $k ?>">
        <?= $label ?>
      </button>
    <?php endforeach; ?>
  </div>

  <!-- ── Monitor ── -->
  <div class="tab-pane <?= $tab === 'monitor' ? 'active' : '' ?>" id="pane-monitor">

    <!-- System metrics row -->
    <div class="mon-grid" id="mon-sys">
      <div class="mon-card" id="mc-cpu">
        <div class="mon-header">
          <div class="mon-label">CPU Usage</div>
          <div class="mon-icon">⚡</div>
        </div>
        <div class="mon-val" id="mv-cpu">—</div>
        <div class="mon-bar-wrap"><div class="mon-bar" id="mb-cpu"></div></div>
        <div class="mon-sub" id="ms-cpu">กำลังโหลด…</div>
      </div>
      <div class="mon-card" id="mc-ram">
        <div class="mon-header">
          <div class="mon-label">Memory</div>
          <div class="mon-icon">🧠</div>
        </div>
        <div class="mon-val" id="mv-ram">—</div>
        <div class="mon-bar-wrap"><div class="mon-bar" id="mb-ram"></div></div>
        <div class="mon-sub" id="ms-ram">กำลังโหลด…</div>
      </div>
      <div class="mon-card">
        <div class="mon-header">
          <div class="mon-label">Network</div>
          <div class="mon-icon">📡</div>
        </div>
        <div class="mon-net-row">
          <div>
            <div class="mon-net-lbl">↑ Upload</div>
            <div class="mon-net-val" id="mv-tx">—</div>
          </div>
          <div style="text-align:right">
            <div class="mon-net-lbl">↓ Download</div>
            <div class="mon-net-val" id="mv-rx">—</div>
          </div>
        </div>
        <div class="mon-sub" id="ms-tx">กำลังโหลด…</div>
      </div>
      <div class="mon-card">
        <div class="mon-header">
          <div class="mon-label">อุปกรณ์ Online</div>
          <div class="mon-icon">📱</div>
        </div>
        <div class="mon-val green" id="mv-dev">—</div>
        <div class="mon-sub" id="ms-dev">กำลังโหลด…</div>
      </div>
      <div class="mon-card">
        <div class="mon-header">
          <div class="mon-label">Active Streams</div>
          <div class="mon-icon">🎥</div>
        </div>
        <div class="mon-val blue" id="mv-sess">—</div>
        <div class="mon-sub" id="ms-sess">WebSocket ที่เชื่อมต่ออยู่</div>
      </div>
    </div>

    <!-- Active sessions -->
    <div class="section">
      <div class="section-head" style="display:flex;justify-content:space-between;align-items:center;">
        <span>🎥 Active Streams <span id="mon-sess-count" class="muted" style="font-weight:400;font-size:12px;"></span></span>
        <span class="muted" style="font-size:11px;" id="mon-updated">กำลังโหลด…</span>
      </div>
      <table>
        <thead><tr><th>ผู้ใช้</th><th>อุปกรณ์</th><th>เปิดมาแล้ว</th><th></th></tr></thead>
        <tbody id="mon-sessions-body">
          <tr><td colspan="4" style="text-align:center;padding:24px;color:var(--text-3);">กำลังโหลด…</td></tr>
        </tbody>
      </table>
    </div>

    <!-- Expiring leases -->
    <div class="section">
      <div class="section-head">Leases หมดอายุใน 48 ชั่วโมง</div>
      <table>
        <thead><tr><th>ผู้ใช้</th><th>อุปกรณ์</th><th>หมดใน</th><th>หมดเวลา</th></tr></thead>
        <tbody id="mon-leases-body">
          <tr><td colspan="4" style="text-align:center;padding:20px;color:var(--text-3);">กำลังโหลด…</td></tr>
        </tbody>
      </table>
    </div>

  </div>

  <!-- ── Devices ── -->
  <div class="tab-pane <?= $tab === 'devices' ? 'active' : '' ?>" id="pane-devices">
    <div class="section">
      <div class="section-head">อุปกรณ์ทั้งหมด · <?= $totalDevices ?> เครื่อง</div>
      <table>
        <thead>
          <tr><th>Serial</th><th>Label</th><th>ประเภท</th><th>สถานะ</th><th>เช่าได้</th><th>ผู้เช่าปัจจุบัน</th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($devices as $d):
            $isOn = ($online[$d['serial']] ?? '') === 'device'; ?>
          <tr>
            <td class="mono"><?= htmlspecialchars($d['serial']) ?></td>
            <td><?= htmlspecialchars($d['label'] ?: '—') ?></td>
            <td>
              <form method="post" style="display:inline">
                <input type="hidden" name="csrf"   value="<?= $csrf ?>">
                <input type="hidden" name="action" value="set_device_tier">
                <input type="hidden" name="serial" value="<?= htmlspecialchars($d['serial']) ?>">
                <input type="hidden" name="tab"    value="devices">
                <select name="tier" onchange="this.form.submit()" style="min-width:auto;padding:4px 8px;font-size:12px;border-radius:6px;">
                  <option value="" <?= !$d['tier'] ? 'selected' : '' ?>>—</option>
                  <?php foreach (['VIP','KVIP','SVIP','XVIP'] as $t): ?>
                  <option value="<?= $t ?>" <?= $d['tier'] === $t ? 'selected' : '' ?>><?= $t ?></option>
                  <?php endforeach; ?>
                </select>
              </form>
            </td>
            <td><span class="dot <?= $isOn ? 'on' : 'off' ?>"></span><?= $isOn ? 'ออนไลน์' : 'ออฟไลน์' ?></td>
            <td>
              <form method="post" style="display:inline">
                <input type="hidden" name="csrf"   value="<?= $csrf ?>">
                <input type="hidden" name="action" value="toggle_rentable">
                <input type="hidden" name="serial" value="<?= htmlspecialchars($d['serial']) ?>">
                <input type="hidden" name="tab"    value="devices">
                <button type="submit" class="toggle-btn pill <?= $d['is_rentable'] ? 'rentable' : 'no' ?>">
                  <?= $d['is_rentable'] ? 'เปิด' : 'ปิด' ?>
                </button>
              </form>
            </td>
            <td class="muted"><?= htmlspecialchars($d['current_lessee'] ?? '—') ?></td>
            <td>
              <form method="post" style="display:inline" onsubmit="return confirm('ลบอุปกรณ์นี้?')">
                <input type="hidden" name="csrf"   value="<?= $csrf ?>">
                <input type="hidden" name="action" value="del_device">
                <input type="hidden" name="serial" value="<?= htmlspecialchars($d['serial']) ?>">
                <input type="hidden" name="tab"    value="devices">
                <button type="submit" class="btn btn-sm btn-danger">ลบ</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <form method="post">
        <div class="add-form">
          <input type="hidden" name="csrf"   value="<?= $csrf ?>">
          <input type="hidden" name="action" value="add_device">
          <input type="hidden" name="tab"    value="devices">
          <div class="fg"><label>Serial</label><input type="text" name="serial" placeholder="RFCT60M26MY" required></div>
          <div class="fg"><label>Label</label><input type="text" name="label" placeholder="Galaxy A"></div>
          <button type="submit" class="btn btn-primary">+ เพิ่มอุปกรณ์</button>
        </div>
      </form>
    </div>
  </div>

  <!-- ── Users ── -->
  <div class="tab-pane <?= $tab === 'users' ? 'active' : '' ?>" id="pane-users">
    <div class="section">
      <div class="section-head">ผู้ใช้ทั้งหมด · <?= $totalUsers ?> คน</div>
      <table>
        <thead>
          <tr><th>#</th><th>Username</th><th>Role</th><th>เครดิต</th><th>Leases</th><th>สร้างเมื่อ</th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($users as $u): ?>
          <tr>
            <td class="muted"><?= (int)$u['id'] ?></td>
            <td><?= htmlspecialchars($u['username']) ?></td>
            <td>
              <form method="post" style="display:inline">
                <input type="hidden" name="csrf"   value="<?= $csrf ?>">
                <input type="hidden" name="action" value="set_role">
                <input type="hidden" name="uid"    value="<?= (int)$u['id'] ?>">
                <input type="hidden" name="tab"    value="users">
                <select name="role" onchange="this.form.submit()" style="min-width:auto;padding:4px 8px;font-size:12px;border-radius:6px;">
                  <option value="customer" <?= $u['role']==='customer'?'selected':'' ?>>customer</option>
                  <option value="admin"    <?= $u['role']==='admin'   ?'selected':'' ?>>admin</option>
                </select>
              </form>
            </td>
            <td style="font-weight:600;color:var(--green);">฿<?= number_format((float)$u['credits'], 2) ?></td>
            <td class="muted"><?= (int)$u['lease_count'] ?></td>
            <td class="muted"><?= date('d/m/y', strtotime($u['created_at'])) ?></td>
            <td>
              <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                <form method="post" style="display:flex;gap:4px;align-items:center;">
                  <input type="hidden" name="csrf"   value="<?= $csrf ?>">
                  <input type="hidden" name="action" value="add_credits">
                  <input type="hidden" name="uid"    value="<?= (int)$u['id'] ?>">
                  <input type="hidden" name="tab"    value="users">
                  <input type="number" name="amount" value="100" min="1" max="99999" step="1"
                         style="width:68px;min-width:unset;padding:4px 6px;font-size:12px;">
                  <button type="submit" class="btn btn-sm btn-neutral">+฿</button>
                </form>
                <?php if ((int)$u['id'] !== (int)$admin['id']): ?>
                <form method="post" style="display:inline" onsubmit="return confirm('ลบผู้ใช้ <?= htmlspecialchars($u['username'], ENT_QUOTES) ?>?')">
                  <input type="hidden" name="csrf"   value="<?= $csrf ?>">
                  <input type="hidden" name="action" value="del_user">
                  <input type="hidden" name="uid"    value="<?= (int)$u['id'] ?>">
                  <input type="hidden" name="tab"    value="users">
                  <button type="submit" class="btn btn-sm btn-danger">ลบ</button>
                </form>
                <?php else: ?>
                <span class="muted" style="font-size:12px;">ตัวเอง</span>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <form method="post">
        <div class="add-form">
          <input type="hidden" name="csrf"   value="<?= $csrf ?>">
          <input type="hidden" name="action" value="add_user">
          <input type="hidden" name="tab"    value="users">
          <div class="fg"><label>Username</label><input type="text" name="username" placeholder="johndoe" required></div>
          <div class="fg"><label>Password</label><input type="password" name="password" placeholder="••••••••" required minlength="6"></div>
          <div class="fg">
            <label>Role</label>
            <select name="role" style="min-width:100px;">
              <option value="customer">customer</option>
              <option value="admin">admin</option>
            </select>
          </div>
          <button type="submit" class="btn btn-primary">+ เพิ่มผู้ใช้</button>
        </div>
      </form>
    </div>
  </div>

  <!-- ── Leases ── -->
  <div class="tab-pane <?= $tab === 'leases' ? 'active' : '' ?>" id="pane-leases">
    <div class="section">
      <div class="section-head">Leases ที่ใช้งาน · <?= $activeLeases ?> รายการ</div>
      <table>
        <thead>
          <tr><th>ผู้ใช้</th><th>อุปกรณ์</th><th>ประเภท</th><th>หมดเวลา</th><th>สร้างเมื่อ</th><th class="nowrap">Actions</th></tr>
        </thead>
        <tbody>
          <?php foreach ($leases as $l):
            $exp     = $l['expires_at'];
            $expired = $exp && strtotime($exp) < time();
            $expTxt  = $exp ? date('d/m/y H:i', strtotime($exp)) : 'ไม่มีกำหนด';
          ?>
          <tr>
            <td><?= htmlspecialchars($l['username']) ?></td>
            <td>
              <div><?= htmlspecialchars($l['label'] ?: $l['serial']) ?></div>
              <div class="mono muted" style="font-size:11px;margin-top:1px;"><?= htmlspecialchars($l['serial']) ?></div>
            </td>
            <td><?= $l['tier'] ? '<span class="pill rentable" style="font-size:10px;">' . htmlspecialchars($l['tier']) . '</span>' : '<span class="muted">—</span>' ?></td>
            <td class="<?= $expired ? 'muted' : '' ?>"><?= $expTxt ?><?= $expired ? ' ⚠' : '' ?></td>
            <td class="muted"><?= date('d/m/y', strtotime($l['created_at'])) ?></td>
            <td class="nowrap">
              <div style="display:flex;gap:6px;align-items:center;">
                <form method="post" style="display:flex;gap:5px;align-items:center;">
                  <input type="hidden" name="csrf"   value="<?= $csrf ?>">
                  <input type="hidden" name="action" value="extend_lease">
                  <input type="hidden" name="lid"    value="<?= (int)$l['id'] ?>">
                  <input type="hidden" name="tab"    value="leases">
                  <input type="number" name="hours" value="24" min="1" max="720" style="width:62px;min-width:unset;padding:5px 8px;">
                  <button type="submit" class="btn btn-sm btn-neutral">+ชม.</button>
                </form>
                <form method="post" style="display:inline" onsubmit="return confirm('ยกเลิก lease นี้?')">
                  <input type="hidden" name="csrf"   value="<?= $csrf ?>">
                  <input type="hidden" name="action" value="terminate_lease">
                  <input type="hidden" name="lid"    value="<?= (int)$l['id'] ?>">
                  <input type="hidden" name="tab"    value="leases">
                  <button type="submit" class="btn btn-sm btn-danger">ยกเลิก</button>
                </form>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$leases): ?>
          <tr><td colspan="5" style="text-align:center;padding:32px;color:var(--text-3);">ยังไม่มี lease</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
      <form method="post">
        <div class="add-form">
          <input type="hidden" name="csrf"   value="<?= $csrf ?>">
          <input type="hidden" name="action" value="add_lease">
          <input type="hidden" name="tab"    value="leases">
          <div class="fg">
            <label>ผู้ใช้</label>
            <select name="uid">
              <?php foreach ($users as $u): ?>
              <option value="<?= (int)$u['id'] ?>"><?= htmlspecialchars($u['username']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="fg">
            <label>อุปกรณ์</label>
            <select name="serial">
              <?php foreach ($devices as $d): ?>
              <option value="<?= htmlspecialchars($d['serial']) ?>"><?= htmlspecialchars($d['label'] ?: $d['serial']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="fg"><label>ชั่วโมง</label><input type="number" name="hours" value="24" min="1" max="8760" style="width:80px;min-width:unset;"></div>
          <button type="submit" class="btn btn-primary">+ เพิ่ม Lease</button>
        </div>
      </form>
    </div>
  </div>

  <!-- ── Sessions ── -->
  <div class="tab-pane <?= $tab === 'sessions' ? 'active' : '' ?>" id="pane-sessions">
    <div class="section">
      <div class="section-head">Stream Sessions · 100 ล่าสุด</div>
      <table>
        <thead>
          <tr><th>ผู้ใช้</th><th>อุปกรณ์</th><th>เริ่มเมื่อ</th><th>สถานะ</th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($sessions as $s):
            $age     = time() - strtotime($s['created_at']);
            $active  = $age < 14400; // < 4 hours = possibly active
            $ageTxt  = $age < 60 ? 'เมื่อกี้'
                     : ($age < 3600 ? floor($age/60).'น.'
                     : ($age < 86400 ? floor($age/3600).'ชม.'
                     : floor($age/86400).'ว.'));
          ?>
          <tr>
            <td><?= htmlspecialchars($s['username']) ?></td>
            <td>
              <div><?= htmlspecialchars($s['device_label'] ?: $s['serial']) ?></div>
              <div class="mono muted" style="font-size:11px;margin-top:1px;"><?= htmlspecialchars($s['serial']) ?></div>
            </td>
            <td class="muted nowrap"><?= date('d/m/y H:i', strtotime($s['created_at'])) ?></td>
            <td>
              <?php if ($active): ?>
                <span class="dot on" style="margin-right:4px;"></span><span style="font-size:12px;color:var(--green);">Active</span>
                <span class="muted" style="font-size:11px;"> · <?= $ageTxt ?>ที่แล้ว</span>
              <?php else: ?>
                <span class="dot off" style="margin-right:4px;"></span><span class="muted" style="font-size:12px;"><?= $ageTxt ?>ที่แล้ว</span>
              <?php endif; ?>
            </td>
            <td class="nowrap">
              <div style="display:flex;gap:6px;">
                <a href="/focus.php?serial=<?= urlencode($s['serial']) ?>"
                   class="btn btn-sm btn-neutral" target="_blank"
                   title="เปิดดูจอ (Audit)">🔍 Audit</a>
                <form method="post" style="display:inline">
                  <input type="hidden" name="csrf"   value="<?= $csrf ?>">
                  <input type="hidden" name="action" value="kill_session">
                  <input type="hidden" name="sid"    value="<?= htmlspecialchars($s['session_id']) ?>">
                  <input type="hidden" name="tab"    value="sessions">
                  <button type="submit" class="btn btn-sm btn-danger"
                          onclick="return confirm('ยกเลิก session นี้?')">Kill</button>
                </form>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$sessions): ?>
          <tr><td colspan="5" style="text-align:center;padding:32px;color:var(--text-3);">ยังไม่มี session</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ── Activity Logs ── -->
  <div class="tab-pane <?= $tab === 'logs' ? 'active' : '' ?>" id="pane-logs">
    <div class="section">
      <div class="section-head">Activity Logs · 300 ล่าสุด</div>
      <table>
        <thead>
          <tr><th>เวลา</th><th>ผู้ใช้</th><th>Action</th><th>อุปกรณ์</th><th>Detail</th><th>IP</th></tr>
        </thead>
        <tbody>
          <?php
          $actionColor = [
            'login'              => 'var(--green)',
            'login_fail'         => 'var(--red)',
            'logout'             => 'var(--text-3)',
            'stream_start'       => 'var(--accent)',
            'admin_audit_stream' => 'var(--orange)',
            'admin_kill_session' => 'var(--red)',
            'admin_approve_topup'=> 'var(--green)',
            'admin_reject_topup' => 'var(--red)',
          ];
          foreach ($logs as $l):
            $color = $actionColor[$l['action']] ?? 'var(--text-2)';
          ?>
          <tr>
            <td class="muted nowrap" style="font-size:11px;"><?= date('d/m H:i:s', strtotime($l['created_at'])) ?></td>
            <td><?= htmlspecialchars($l['username'] ?? '—') ?></td>
            <td><span style="color:<?= $color ?>;font-size:12px;font-weight:600;"><?= htmlspecialchars($l['action']) ?></span></td>
            <td class="muted" style="font-size:12px;"><?= htmlspecialchars($l['device_label'] ?: ($l['serial'] ?? '')) ?></td>
            <td class="mono muted" style="font-size:11px;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                title="<?= htmlspecialchars($l['detail'] ?? '') ?>"><?= htmlspecialchars(substr($l['detail'] ?? '', 0, 40)) ?></td>
            <td class="mono muted" style="font-size:11px;"><?= htmlspecialchars($l['ip'] ?? '') ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$logs): ?>
          <tr><td colspan="6" style="text-align:center;padding:32px;color:var(--text-3);">ยังไม่มี log</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ── Topup requests ── -->
  <div class="tab-pane <?= $tab === 'topup' ? 'active' : '' ?>" id="pane-topup">
    <div class="section">
      <div class="section-head">คำขอเติมเครดิต<?= $pendingTopups > 0 ? " · <span style='color:var(--yellow)'>{$pendingTopups} รอดำเนินการ</span>" : '' ?></div>
      <?php if (empty($topups)): ?>
        <div style="color:var(--text-3);padding:20px 0;font-size:13px;">ยังไม่มีคำขอ</div>
      <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>#</th>
            <th>ผู้ใช้</th>
            <th>จำนวน</th>
            <th>สลิป</th>
            <th>วันที่</th>
            <th>สถานะ</th>
            <th>หมายเหตุ</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($topups as $t): ?>
          <tr>
            <td class="muted"><?= (int)$t['id'] ?></td>
            <td><?= htmlspecialchars($t['username']) ?> <span class="muted">#<?= (int)$t['user_id'] ?></span></td>
            <td style="font-weight:700;color:var(--green);">฿<?= number_format((float)$t['amount']) ?></td>
            <td><a href="/<?= htmlspecialchars($t['slip_path']) ?>" target="_blank"
                   style="color:var(--accent);text-decoration:none;font-size:12px;">ดูสลิป ↗</a></td>
            <td class="muted"><?= date('d/m/y H:i', strtotime($t['created_at'])) ?></td>
            <td>
              <?php if ($t['status'] === 'pending'): ?>
                <span style="color:var(--yellow);font-weight:700;font-size:12px;">รอดำเนินการ</span>
              <?php elseif ($t['status'] === 'approved'): ?>
                <span style="color:var(--green);font-size:12px;">✓ อนุมัติ</span>
              <?php else: ?>
                <span style="color:var(--red);font-size:12px;">✕ ปฏิเสธ</span>
              <?php endif; ?>
            </td>
            <td class="muted" style="font-size:12px;"><?= htmlspecialchars($t['note'] ?? '') ?></td>
            <td>
              <?php if ($t['status'] === 'pending'): ?>
              <div style="display:flex;gap:6px;align-items:center;">
                <form method="post" style="display:inline">
                  <input type="hidden" name="csrf"   value="<?= $csrf ?>">
                  <input type="hidden" name="action" value="approve_topup">
                  <input type="hidden" name="rid"    value="<?= (int)$t['id'] ?>">
                  <input type="hidden" name="tab"    value="topup">
                  <button type="submit" class="act-btn"
                          style="background:rgba(48,209,88,.15);color:var(--green);border-color:rgba(48,209,88,.3);"
                          onclick="return confirm('อนุมัติเติมเครดิต ฿<?= number_format((float)$t['amount']) ?> ให้ <?= htmlspecialchars($t['username']) ?>?')">
                    ✓ อนุมัติ
                  </button>
                </form>
                <form method="post" style="display:inline" onsubmit="return rejectConfirm(this)">
                  <input type="hidden" name="csrf"   value="<?= $csrf ?>">
                  <input type="hidden" name="action" value="reject_topup">
                  <input type="hidden" name="rid"    value="<?= (int)$t['id'] ?>">
                  <input type="hidden" name="note"   class="reject-note" value="">
                  <input type="hidden" name="tab"    value="topup">
                  <button type="submit" class="act-btn"
                          style="background:rgba(255,69,58,.12);color:var(--red);border-color:rgba(255,69,58,.3);">
                    ✕ ปฏิเสธ
                  </button>
                </form>
              </div>
              <?php else: ?>
              <span class="muted" style="font-size:11px;"><?= htmlspecialchars($t['reviewer'] ?? '') ?></span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>

</div>

<script>
function switchTab(name) {
  document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.seg-btn').forEach(b => b.classList.remove('active'));
  document.getElementById('pane-' + name).classList.add('active');
  document.getElementById('tab-'  + name).classList.add('active');
  history.replaceState(null, '', '/admin_dashboard.php?tab=' + name);
}
function rejectConfirm(form) {
  const note = prompt('หมายเหตุการปฏิเสธ (ไม่บังคับ):') ?? '';
  if (note === null) return false;
  form.querySelector('.reject-note').value = note;
  return true;
}

// ── Monitor polling ──────────────────────────────────────────────────────────
function fmtBytes(bps) {
  // sysinfo total_received/transmitted returns bytes — display as B/s, KB/s, MB/s
  if (bps >= 1048576) return (bps/1048576).toFixed(1) + ' MB/s';
  if (bps >= 1024)    return (bps/1024).toFixed(0)    + ' KB/s';
  return bps + ' B/s';
}
function fmtAge(s) {
  if (s < 60)    return s + ' วินาที';
  if (s < 3600)  return Math.floor(s/60) + ' นาที';
  if (s < 86400) return Math.floor(s/3600) + ' ชั่วโมง';
  return Math.floor(s/86400) + ' วัน';
}
function fmtMins(m) {
  if (m < 60)   return m + ' นาที';
  if (m < 1440) return Math.floor(m/60) + 'ชม. ' + (m%60) + 'น.';
  return Math.floor(m/1440) + 'ว. ' + Math.floor((m%1440)/60) + 'ชม.';
}

const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

async function fetchMonitor() {
  try {
    const r = await fetch('/metrics.php', { cache: 'no-store' });
    if (!r.ok) return;
    const d = await r.json();

    // CPU
    if (d.cpu_percent !== null) {
      const c = d.cpu_percent;
      const cls = c >= 85 ? 'crit' : c >= 60 ? 'warn' : '';
      document.getElementById('mv-cpu').textContent = c.toFixed(1) + '%';
      document.getElementById('mv-cpu').className = 'mon-val ' + cls;
      const bar = document.getElementById('mb-cpu');
      bar.style.width = Math.min(c, 100) + '%';
      bar.className = 'mon-bar ' + cls;
      document.getElementById('ms-cpu').textContent = c >= 85 ? 'สูงมาก' : c >= 60 ? 'สูง' : 'ปกติ';
    }

    // RAM
    if (d.ram_used_mb !== null) {
      const pct = d.ram_used_mb / d.ram_total_mb * 100;
      const cls = pct >= 85 ? 'crit' : pct >= 65 ? 'warn' : '';
      const fmtMB = mb => mb >= 1024 ? (mb/1024).toFixed(1)+' GB' : mb+' MB';
      document.getElementById('mv-ram').textContent = fmtMB(d.ram_used_mb);
      document.getElementById('mv-ram').className = 'mon-val ' + cls;
      const bar = document.getElementById('mb-ram');
      bar.style.width = pct.toFixed(1) + '%';
      bar.className = 'mon-bar ' + cls;
      document.getElementById('ms-ram').textContent = 'จาก ' + fmtMB(d.ram_total_mb) + ' · ' + pct.toFixed(0) + '%';
    }

    // Network: pick highest-traffic physical interface (skip filter/virtual drivers)
    const skipWords = /filter|hyper-v|loopback|virtual|vethernet/i;
    let bestIface = '', bestRx = 0, bestTx = 0;
    for (const [name, v] of Object.entries(d.network || {})) {
      if (skipWords.test(name)) continue;
      if (v.rx_bps + v.tx_bps > bestRx + bestTx) {
        bestIface = name; bestRx = v.rx_bps; bestTx = v.tx_bps;
      }
    }
    if (!bestIface) for (const [name, v] of Object.entries(d.network || {})) {
      if (v.rx_bps + v.tx_bps > bestRx + bestTx) {
        bestIface = name; bestRx = v.rx_bps; bestTx = v.tx_bps;
      }
    }
    document.getElementById('mv-tx').textContent = fmtBytes(bestTx);
    document.getElementById('mv-rx').textContent = fmtBytes(bestRx);
    document.getElementById('ms-tx').textContent = bestIface || '—';

    // Devices
    if (d.devices_online !== null) {
      document.getElementById('mv-dev').textContent = d.devices_online + ' / ' + d.devices_total;
      document.getElementById('ms-dev').textContent = 'ออนไลน์จากทั้งหมด ' + d.devices_total + ' เครื่อง';
    }

    // Active streams: count from Rust (truly connected WebSockets)
    document.getElementById('mv-sess').textContent = d.sessions.length;

    // Sessions table
    const sb = document.getElementById('mon-sessions-body');
    const sc = document.getElementById('mon-sess-count');
    if (d.sessions.length === 0) {
      sb.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:24px;color:var(--text-3);">ไม่มี stream ที่กำลัง active</td></tr>';
      sc.textContent = '';
    } else {
      sc.textContent = '· ' + d.sessions.length + ' stream';
      sb.innerHTML = d.sessions.map(s => `
        <tr>
          <td><span style="font-weight:600;">${esc(s.username)}</span></td>
          <td><div>${esc(s.device_label)}</div><div class="mono muted" style="font-size:11px;">${esc(s.serial)}</div></td>
          <td class="muted nowrap">${fmtAge(s.age_s)}ที่แล้ว</td>
          <td><a href="/focus.php?serial=${encodeURIComponent(s.serial)}" target="_blank" class="btn btn-sm btn-neutral">🔍 Audit</a></td>
        </tr>`).join('');
    }

    // Expiring leases table
    const lb = document.getElementById('mon-leases-body');
    if (d.expiring_leases.length === 0) {
      lb.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:20px;color:var(--text-3);">ไม่มี lease ที่ใกล้หมดอายุ</td></tr>';
    } else {
      lb.innerHTML = d.expiring_leases.map(l => {
        const urgent = l.mins_left < 120;
        return `<tr>
          <td>${esc(l.username)}</td>
          <td><div>${esc(l.label)}</div><div class="mono muted" style="font-size:11px;">${esc(l.serial)}</div></td>
          <td style="color:${urgent?'var(--red)':'var(--yellow)'};font-weight:600;">${fmtMins(l.mins_left)}</td>
          <td class="muted">${esc(l.expires_at)}</td>
        </tr>`;
      }).join('');
    }

    document.getElementById('mon-updated').textContent = 'อัปเดต ' + new Date().toLocaleTimeString('th-TH');
  } catch(e) {
    document.getElementById('mon-updated').textContent = 'โหลดไม่สำเร็จ';
  }
}

let monTimer = null;
const origSwitch = window.switchTab;
window.switchTab = function(name) {
  origSwitch(name);
  clearInterval(monTimer);
  if (name === 'monitor') {
    fetchMonitor();
    monTimer = setInterval(fetchMonitor, 2000);
  }
};

// Auto-start if monitor tab is active on load
if (document.getElementById('pane-monitor').classList.contains('active')) {
  fetchMonitor();
  monTimer = setInterval(fetchMonitor, 2000);
}
</script>
</body>
</html>
