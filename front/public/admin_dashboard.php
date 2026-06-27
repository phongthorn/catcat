<?php
require_once __DIR__ . '/../../back/lib/auth.php';
require_once __DIR__ . '/../../back/lib/adb.php';
$admin = require_admin();

// ── CSRF ───────────────────────────────────────────────────────────────────
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

// ── POST actions (PRG pattern) ─────────────────────────────────────────────
$flash = '';
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
            $flash = 'กรุณากรอกชื่อผู้ใช้และรหัสผ่าน (≥6 ตัว)';
        }
    } elseif ($action === 'del_user') {
        $uid = (int) ($_POST['uid'] ?? 0);
        if ($uid !== (int) $admin['id']) { // กันลบตัวเอง
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
            $flash = 'ลบผู้ใช้สำเร็จ';
        } else {
            $flash = 'ไม่สามารถลบบัญชีของตัวเองได้';
        }
    } elseif ($action === 'set_role') {
        $uid  = (int) ($_POST['uid'] ?? 0);
        $role = in_array($_POST['role'] ?? '', ['customer','admin']) ? $_POST['role'] : 'customer';
        $pdo->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $uid]);
        $flash = 'เปลี่ยน role สำเร็จ';
    } elseif ($action === 'add_lease') {
        $uid     = (int) ($_POST['uid'] ?? 0);
        $serial  = $_POST['serial'] ?? '';
        $hours   = max(1, (int) ($_POST['hours'] ?? 24));
        if ($uid && $serial !== '') {
            $pdo->prepare(
                'INSERT INTO leases (user_id, serial, expires_at)
                   VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? HOUR))
                   ON DUPLICATE KEY UPDATE expires_at = DATE_ADD(NOW(), INTERVAL ? HOUR)'
            )->execute([$uid, $serial, $hours, $hours]);
            $flash = "เพิ่ม lease $hours ชั่วโมงสำเร็จ";
        }
    } elseif ($action === 'terminate_lease') {
        $pdo->prepare('DELETE FROM leases WHERE id = ?')->execute([(int)($_POST['lid'] ?? 0)]);
        $flash = 'ยกเลิก lease สำเร็จ';
    } elseif ($action === 'extend_lease') {
        $hours = max(1, (int) ($_POST['hours'] ?? 24));
        $pdo->prepare(
            'UPDATE leases SET expires_at = DATE_ADD(GREATEST(NOW(), IFNULL(expires_at, NOW())), INTERVAL ? HOUR)
              WHERE id = ?'
        )->execute([$hours, (int)($_POST['lid'] ?? 0)]);
        $flash = "ต่ออายุ $hours ชั่วโมงสำเร็จ";
    }

    header('Location: /admin.php?tab=' . urlencode($_POST['tab'] ?? 'devices') . '&flash=' . urlencode($flash));
    exit;
}

$tab   = $_GET['tab']   ?? 'devices';
$flash = $flash ?: ($_GET['flash'] ?? '');

// ── Data ───────────────────────────────────────────────────────────────────
$pdo = db();

try { $online = adb_list_devices(); } catch (Throwable $e) { $online = []; }

$devices = $pdo->query(
    'SELECT d.serial, d.label, d.is_rentable, d.created_at,
            COUNT(l.id) AS lease_count
       FROM devices d
       LEFT JOIN leases l ON l.serial = d.serial AND (l.expires_at IS NULL OR l.expires_at > NOW())
      GROUP BY d.serial ORDER BY d.label, d.serial'
)->fetchAll();

$users = $pdo->query(
    'SELECT u.id, u.username, u.role, u.created_at,
            COUNT(l.id) AS lease_count
       FROM users u
       LEFT JOIN leases l ON l.user_id = u.id AND (l.expires_at IS NULL OR l.expires_at > NOW())
      GROUP BY u.id ORDER BY u.username'
)->fetchAll();

$leases = $pdo->query(
    'SELECT l.id, l.expires_at, l.created_at,
            u.username, d.serial, d.label
       FROM leases l
       JOIN users u   ON u.id     = l.user_id
       JOIN devices d ON d.serial = l.serial
      ORDER BY l.expires_at IS NULL DESC, l.expires_at ASC'
)->fetchAll();

$sessions = $pdo->query(
    'SELECT s.session_id, s.serial, s.created_at, u.username
       FROM sessions s
       JOIN users u ON u.id = s.user_id
      ORDER BY s.created_at DESC LIMIT 50'
)->fetchAll();

// Summary stats
$totalDevices  = count($devices);
$onlineDevices = count(array_filter($devices, fn($d) => ($online[$d['serial']] ?? '') === 'device'));
$totalUsers    = count($users);
$activeLeases  = count($leases);
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Panda — Admin</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"
        integrity="sha512-iecdLmaskl7CVkqkXNQ/ZH/XLlvWZOJyj7Yy7tcenmpD1ypASozpmT/E0iPtmFIB46ZmdtAc9eNBvH0H/ZpiBw=="
        crossorigin="anonymous">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Segoe UI', sans-serif; background: #f0f2f5; color: #222; font-size: 14px; }

    /* ── Top bar ── */
    header { background: #1e293b; color: #fff; padding: 0 20px; height: 50px;
             display: flex; align-items: center; justify-content: space-between; }
    .logo  { font-weight: 700; font-size: 16px; display: flex; align-items: center; gap: 8px; }
    .header-right { display: flex; align-items: center; gap: 16px; font-size: 13px; color: #94a3b8; }
    .header-right a { color: #94a3b8; text-decoration: none; }
    .header-right a:hover { color: #fff; }

    /* ── Stats bar ── */
    .stats { display: flex; gap: 16px; padding: 16px 20px 0; flex-wrap: wrap; }
    .stat-card { background: #fff; border-radius: 10px; padding: 14px 20px;
                 box-shadow: 0 1px 4px rgba(0,0,0,.06); min-width: 130px; }
    .stat-card .val { font-size: 28px; font-weight: 700; line-height: 1.1; }
    .stat-card .lbl { font-size: 11px; color: #888; margin-top: 2px; }
    .val.green { color: #16a34a; }
    .val.blue  { color: #2563eb; }

    /* ── Flash ── */
    .flash { margin: 12px 20px 0; padding: 10px 14px; background: #dcfce7;
             border: 1px solid #86efac; border-radius: 6px; color: #166534; font-size: 13px; }

    /* ── Tabs ── */
    .tabs { display: flex; gap: 4px; padding: 16px 20px 0; border-bottom: 2px solid #e2e8f0;
            margin: 0 0 0; }
    .tab-btn { padding: 8px 18px; border: none; background: none; cursor: pointer; font-size: 14px;
               color: #64748b; border-bottom: 2px solid transparent; margin-bottom: -2px;
               border-radius: 6px 6px 0 0; display: flex; align-items: center; gap: 6px; }
    .tab-btn:hover { background: #f1f5f9; color: #1e293b; }
    .tab-btn.active { color: #2563eb; border-bottom-color: #2563eb; font-weight: 600; background: #fff; }

    /* ── Content ── */
    .content { padding: 20px; }

    /* ── Table ── */
    .card { background: #fff; border-radius: 10px; box-shadow: 0 1px 4px rgba(0,0,0,.06);
            overflow: hidden; margin-bottom: 20px; }
    .card-head { padding: 14px 18px; border-bottom: 1px solid #f1f5f9;
                 font-weight: 600; display: flex; align-items: center; gap: 8px; }
    table { width: 100%; border-collapse: collapse; }
    th { text-align: left; padding: 10px 14px; background: #f8fafc;
         font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: #64748b;
         border-bottom: 1px solid #e2e8f0; }
    td { padding: 10px 14px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
    tr:last-child td { border-bottom: none; }
    tr:hover td { background: #f8fafc; }

    .dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 4px; }
    .dot.on  { background: #22c55e; }
    .dot.off { background: #cbd5e1; }

    .badge { display: inline-block; padding: 2px 8px; border-radius: 99px; font-size: 11px; font-weight: 600; }
    .badge.admin    { background: #fef3c7; color: #92400e; }
    .badge.customer { background: #eff6ff; color: #1e40af; }
    .badge.rentable { background: #dcfce7; color: #166534; }
    .badge.no       { background: #fef2f2; color: #991b1b; }

    /* ── Buttons ── */
    .btn { display: inline-flex; align-items: center; gap: 4px; padding: 5px 12px;
           border: none; border-radius: 6px; cursor: pointer; font-size: 13px;
           font-family: inherit; text-decoration: none; line-height: 1.4; }
    .btn-sm { padding: 3px 8px; font-size: 12px; }
    .btn-primary { background: #2563eb; color: #fff; }
    .btn-primary:hover { background: #1d4ed8; }
    .btn-danger  { background: #fee2e2; color: #dc2626; }
    .btn-danger:hover  { background: #fecaca; }
    .btn-warn    { background: #fef3c7; color: #92400e; }
    .btn-warn:hover    { background: #fde68a; }
    .btn-neutral { background: #f1f5f9; color: #475569; }
    .btn-neutral:hover { background: #e2e8f0; }

    /* ── Form ── */
    .form-row { display: flex; gap: 8px; flex-wrap: wrap; align-items: flex-end; padding: 14px 18px; }
    .form-group { display: flex; flex-direction: column; gap: 4px; }
    .form-group label { font-size: 11px; color: #64748b; font-weight: 600; text-transform: uppercase; }
    input[type=text], input[type=password], select, input[type=number] {
        border: 1px solid #e2e8f0; border-radius: 6px; padding: 6px 10px;
        font-size: 13px; font-family: inherit; outline: none; min-width: 140px; }
    input:focus, select:focus { border-color: #2563eb; box-shadow: 0 0 0 2px rgba(37,99,235,.1); }

    .tab-pane { display: none; }
    .tab-pane.active { display: block; }

    .mono { font-family: 'Courier New', monospace; font-size: 12px; }
    .text-muted { color: #94a3b8; }
    .nowrap { white-space: nowrap; }
  </style>
</head>
<body>

<header>
  <div class="logo"><i class="fa-solid fa-screwdriver-wrench"></i> Panda Admin</div>
  <div class="header-right">
    <span><i class="fa-solid fa-user"></i> <?= htmlspecialchars($admin['username']) ?></span>
    <a href="/cloud_dashboard.php"><i class="fa-solid fa-mobile-screen"></i> Dashboard</a>
    <a href="/logout.php"><i class="fa-solid fa-power-off"></i> ออกจากระบบ</a>
  </div>
</header>

<!-- Stats -->
<div class="stats">
  <div class="stat-card">
    <div class="val green"><?= $onlineDevices ?> / <?= $totalDevices ?></div>
    <div class="lbl">อุปกรณ์ออนไลน์</div>
  </div>
  <div class="stat-card">
    <div class="val blue"><?= $totalUsers ?></div>
    <div class="lbl">ผู้ใช้ทั้งหมด</div>
  </div>
  <div class="stat-card">
    <div class="val"><?= $activeLeases ?></div>
    <div class="lbl">Lease ที่ใช้งาน</div>
  </div>
  <div class="stat-card">
    <div class="val"><?= count($sessions) ?></div>
    <div class="lbl">Stream sessions (ล่าสุด)</div>
  </div>
</div>

<?php if ($flash): ?>
<div class="flash"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<!-- Tabs -->
<div class="tabs">
  <?php
  $tabs = ['devices' => ['fa-mobile-screen','อุปกรณ์'], 'users' => ['fa-users','ผู้ใช้'],
           'leases'  => ['fa-key','Leases'],            'sessions' => ['fa-plug','Sessions']];
  foreach ($tabs as $k => [$icon, $label]):
  ?>
    <button class="tab-btn <?= $tab === $k ? 'active' : '' ?>"
            onclick="switchTab('<?= $k ?>')" id="tab-<?= $k ?>">
      <i class="fa-solid fa-<?= $icon ?>"></i> <?= $label ?>
    </button>
  <?php endforeach; ?>
</div>

<div class="content">

  <!-- ── Devices ── -->
  <div class="tab-pane <?= $tab === 'devices' ? 'active' : '' ?>" id="pane-devices">
    <div class="card">
      <div class="card-head"><i class="fa-solid fa-mobile-screen"></i> อุปกรณ์ทั้งหมด</div>
      <table>
        <thead>
          <tr><th>Serial</th><th>Label</th><th>สถานะ</th><th>เช่าได้</th><th>Leases</th><th>Actions</th></tr>
        </thead>
        <tbody>
          <?php foreach ($devices as $d):
            $isOn = ($online[$d['serial']] ?? '') === 'device';
          ?>
          <tr>
            <td class="mono"><?= htmlspecialchars($d['serial']) ?></td>
            <td><?= htmlspecialchars($d['label'] ?: '—') ?></td>
            <td><span class="dot <?= $isOn ? 'on' : 'off' ?>"></span><?= $isOn ? 'ออนไลน์' : 'ออฟไลน์' ?></td>
            <td>
              <form method="post" style="display:inline">
                <input type="hidden" name="csrf"   value="<?= $csrf ?>">
                <input type="hidden" name="action" value="toggle_rentable">
                <input type="hidden" name="serial" value="<?= htmlspecialchars($d['serial']) ?>">
                <input type="hidden" name="tab"    value="devices">
                <button type="submit" class="badge <?= $d['is_rentable'] ? 'rentable' : 'no' ?>"
                        style="border:none;cursor:pointer;font-family:inherit;">
                  <?= $d['is_rentable'] ? 'เปิด' : 'ปิด' ?>
                </button>
              </form>
            </td>
            <td><?= (int) $d['lease_count'] ?></td>
            <td class="nowrap">
              <form method="post" style="display:inline" onsubmit="return confirm('ลบอุปกรณ์นี้?')">
                <input type="hidden" name="csrf"   value="<?= $csrf ?>">
                <input type="hidden" name="action" value="del_device">
                <input type="hidden" name="serial" value="<?= htmlspecialchars($d['serial']) ?>">
                <input type="hidden" name="tab"    value="devices">
                <button type="submit" class="btn btn-sm btn-danger"><i class="fa-solid fa-trash"></i></button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <!-- Add device form -->
      <form method="post">
        <div class="form-row" style="border-top:1px solid #f1f5f9;">
          <input type="hidden" name="csrf"   value="<?= $csrf ?>">
          <input type="hidden" name="action" value="add_device">
          <input type="hidden" name="tab"    value="devices">
          <div class="form-group">
            <label>Serial</label>
            <input type="text" name="serial" placeholder="RFCT60M26MY" required>
          </div>
          <div class="form-group">
            <label>Label (ชื่อเล่น)</label>
            <input type="text" name="label" placeholder="Galaxy A">
          </div>
          <button type="submit" class="btn btn-primary"><i class="fa-solid fa-plus"></i> เพิ่มอุปกรณ์</button>
        </div>
      </form>
    </div>
  </div>

  <!-- ── Users ── -->
  <div class="tab-pane <?= $tab === 'users' ? 'active' : '' ?>" id="pane-users">
    <div class="card">
      <div class="card-head"><i class="fa-solid fa-users"></i> ผู้ใช้ทั้งหมด</div>
      <table>
        <thead>
          <tr><th>ID</th><th>Username</th><th>Role</th><th>Leases</th><th>สร้างเมื่อ</th><th>Actions</th></tr>
        </thead>
        <tbody>
          <?php foreach ($users as $u): ?>
          <tr>
            <td class="text-muted"><?= (int) $u['id'] ?></td>
            <td><?= htmlspecialchars($u['username']) ?></td>
            <td>
              <form method="post" style="display:inline">
                <input type="hidden" name="csrf"   value="<?= $csrf ?>">
                <input type="hidden" name="action" value="set_role">
                <input type="hidden" name="uid"    value="<?= (int) $u['id'] ?>">
                <input type="hidden" name="tab"    value="users">
                <select name="role" onchange="this.form.submit()" class="badge <?= $u['role'] ?>">
                  <option value="customer" <?= $u['role']==='customer'?'selected':'' ?>>customer</option>
                  <option value="admin"    <?= $u['role']==='admin'   ?'selected':'' ?>>admin</option>
                </select>
              </form>
            </td>
            <td><?= (int) $u['lease_count'] ?></td>
            <td class="text-muted"><?= date('d/m/y', strtotime($u['created_at'])) ?></td>
            <td>
              <?php if ((int) $u['id'] !== (int) $admin['id']): ?>
              <form method="post" style="display:inline" onsubmit="return confirm('ลบผู้ใช้ <?= htmlspecialchars($u['username']) ?>?')">
                <input type="hidden" name="csrf"   value="<?= $csrf ?>">
                <input type="hidden" name="action" value="del_user">
                <input type="hidden" name="uid"    value="<?= (int) $u['id'] ?>">
                <input type="hidden" name="tab"    value="users">
                <button type="submit" class="btn btn-sm btn-danger"><i class="fa-solid fa-trash"></i></button>
              </form>
              <?php else: ?>
              <span class="text-muted" title="ไม่สามารถลบบัญชีของตัวเองได้"><i class="fa-solid fa-lock"></i></span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <!-- Add user form -->
      <form method="post">
        <div class="form-row" style="border-top:1px solid #f1f5f9;">
          <input type="hidden" name="csrf"   value="<?= $csrf ?>">
          <input type="hidden" name="action" value="add_user">
          <input type="hidden" name="tab"    value="users">
          <div class="form-group">
            <label>Username</label>
            <input type="text" name="username" placeholder="johndoe" required>
          </div>
          <div class="form-group">
            <label>Password (≥6 ตัว)</label>
            <input type="password" name="password" placeholder="••••••••" required minlength="6">
          </div>
          <div class="form-group">
            <label>Role</label>
            <select name="role">
              <option value="customer">customer</option>
              <option value="admin">admin</option>
            </select>
          </div>
          <button type="submit" class="btn btn-primary"><i class="fa-solid fa-user-plus"></i> เพิ่มผู้ใช้</button>
        </div>
      </form>
    </div>
  </div>

  <!-- ── Leases ── -->
  <div class="tab-pane <?= $tab === 'leases' ? 'active' : '' ?>" id="pane-leases">
    <div class="card">
      <div class="card-head"><i class="fa-solid fa-key"></i> Leases ที่ใช้งาน</div>
      <table>
        <thead>
          <tr><th>ผู้ใช้</th><th>อุปกรณ์</th><th>หมดเวลา</th><th>สร้างเมื่อ</th><th>Actions</th></tr>
        </thead>
        <tbody>
          <?php foreach ($leases as $l):
            $exp = $l['expires_at'];
            $expired = $exp && strtotime($exp) < time();
            $expTxt  = $exp ? date('d/m/y H:i', strtotime($exp)) : 'ไม่มีวันหมด';
          ?>
          <tr>
            <td><?= htmlspecialchars($l['username']) ?></td>
            <td>
              <?= htmlspecialchars($l['label'] ?: $l['serial']) ?>
              <span class="text-muted mono" style="font-size:11px;"><?= htmlspecialchars($l['serial']) ?></span>
            </td>
            <td class="<?= $expired ? 'text-muted' : '' ?>">
              <?= $expTxt ?><?= $expired ? ' (หมดแล้ว)' : '' ?>
            </td>
            <td class="text-muted"><?= date('d/m/y', strtotime($l['created_at'])) ?></td>
            <td class="nowrap" style="display:flex;gap:4px;align-items:center;">
              <form method="post" style="display:flex;align-items:center;gap:4px;">
                <input type="hidden" name="csrf"   value="<?= $csrf ?>">
                <input type="hidden" name="action" value="extend_lease">
                <input type="hidden" name="lid"    value="<?= (int) $l['id'] ?>">
                <input type="hidden" name="tab"    value="leases">
                <input type="number" name="hours" value="24" min="1" max="720" style="width:64px;">
                <button type="submit" class="btn btn-sm btn-neutral">+ชม.</button>
              </form>
              <form method="post" style="display:inline" onsubmit="return confirm('ยกเลิก lease นี้?')">
                <input type="hidden" name="csrf"   value="<?= $csrf ?>">
                <input type="hidden" name="action" value="terminate_lease">
                <input type="hidden" name="lid"    value="<?= (int) $l['id'] ?>">
                <input type="hidden" name="tab"    value="leases">
                <button type="submit" class="btn btn-sm btn-danger"><i class="fa-solid fa-xmark"></i></button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$leases): ?>
          <tr><td colspan="5" style="text-align:center;padding:30px;color:#94a3b8;">ยังไม่มี lease</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
      <!-- Add lease form -->
      <form method="post">
        <div class="form-row" style="border-top:1px solid #f1f5f9;">
          <input type="hidden" name="csrf"   value="<?= $csrf ?>">
          <input type="hidden" name="action" value="add_lease">
          <input type="hidden" name="tab"    value="leases">
          <div class="form-group">
            <label>ผู้ใช้</label>
            <select name="uid">
              <?php foreach ($users as $u): ?>
              <option value="<?= (int) $u['id'] ?>"><?= htmlspecialchars($u['username']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>อุปกรณ์</label>
            <select name="serial">
              <?php foreach ($devices as $d): ?>
              <option value="<?= htmlspecialchars($d['serial']) ?>"><?= htmlspecialchars($d['label'] ?: $d['serial']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>จำนวนชั่วโมง</label>
            <input type="number" name="hours" value="24" min="1" max="8760" style="width:80px;">
          </div>
          <button type="submit" class="btn btn-primary"><i class="fa-solid fa-plus"></i> เพิ่ม Lease</button>
        </div>
      </form>
    </div>
  </div>

  <!-- ── Sessions ── -->
  <div class="tab-pane <?= $tab === 'sessions' ? 'active' : '' ?>" id="pane-sessions">
    <div class="card">
      <div class="card-head"><i class="fa-solid fa-plug"></i> Stream Sessions (50 ล่าสุด)</div>
      <table>
        <thead>
          <tr><th>Session ID</th><th>ผู้ใช้</th><th>Serial</th><th>เริ่มเมื่อ</th></tr>
        </thead>
        <tbody>
          <?php foreach ($sessions as $s): ?>
          <tr>
            <td class="mono text-muted" style="font-size:11px;"><?= htmlspecialchars(substr($s['session_id'],0,16)) ?>…</td>
            <td><?= htmlspecialchars($s['username']) ?></td>
            <td class="mono"><?= htmlspecialchars($s['serial']) ?></td>
            <td class="text-muted"><?= date('d/m/y H:i', strtotime($s['created_at'])) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$sessions): ?>
          <tr><td colspan="4" style="text-align:center;padding:30px;color:#94a3b8;">ยังไม่มี session</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div><!-- .content -->

<script>
function switchTab(name) {
  document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.getElementById('pane-' + name).classList.add('active');
  document.getElementById('tab-'  + name).classList.add('active');
  history.replaceState(null, '', '/admin.php?tab=' + name);
}
</script>
</body>
</html>
