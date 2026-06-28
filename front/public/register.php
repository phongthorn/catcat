<?php
require_once __DIR__ . '/../../back/lib/auth.php';

if (current_user()) { header('Location: /'); exit; }

$error    = '';
$username = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm']  ?? '';

    if ($username === '' || strlen($username) < 3) {
        $error = 'ชื่อผู้ใช้ต้องมีอย่างน้อย 3 ตัวอักษร';
    } elseif (!preg_match('/^[A-Za-z0-9_]+$/', $username)) {
        $error = 'ชื่อผู้ใช้ใช้ได้เฉพาะ a–z, 0–9, _';
    } elseif (strlen($password) < 6) {
        $error = 'รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร';
    } elseif ($password !== $confirm) {
        $error = 'รหัสผ่านไม่ตรงกัน';
    } else {
        $pdo = db();
        $exists = $pdo->prepare('SELECT 1 FROM users WHERE username = ?');
        $exists->execute([$username]);
        if ($exists->fetchColumn()) {
            $error = 'ชื่อผู้ใช้นี้ถูกใช้ไปแล้ว';
        } else {
            $pdo->prepare('INSERT INTO users (username, password_hash, role) VALUES (?,?,?)')
                ->execute([$username, password_hash($password, PASSWORD_DEFAULT), 'customer']);
            login($username, $password);
            header('Location: /');
            exit;
        }
    }
}
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
  <title>Catcat · สมัครสมาชิก</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      min-height: 100svh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 24px;
      background-color: #000;
      background-image:
        radial-gradient(ellipse 80% 60% at 50% -10%, rgba(99,99,255,.18) 0%, transparent 70%);
      font-family: -apple-system, BlinkMacSystemFont, "SF Pro Display", "Helvetica Neue", Arial, sans-serif;
      -webkit-font-smoothing: antialiased;
    }

    .card {
      width: 100%;
      max-width: 360px;
      background: rgba(28, 28, 30, 0.85);
      backdrop-filter: blur(40px) saturate(180%);
      -webkit-backdrop-filter: blur(40px) saturate(180%);
      border: 1px solid rgba(255,255,255,.08);
      border-radius: 20px;
      padding: 40px 32px 36px;
      box-shadow:
        0 0 0 0.5px rgba(255,255,255,.06),
        0 24px 64px rgba(0,0,0,.6);
    }

    .logo {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 10px;
      margin-bottom: 28px;
    }

    .app-icon {
      width: 56px; height: 56px;
      border-radius: 14px;
      background: linear-gradient(145deg, #2a2a2e, #1a1a1e);
      border: 1px solid rgba(255,255,255,.10);
      display: flex; align-items: center; justify-content: center;
      font-size: 28px;
      box-shadow: 0 4px 16px rgba(0,0,0,.4);
    }

    .logo-title {
      font-size: 22px; font-weight: 600;
      color: #f5f5f7; letter-spacing: -.3px;
    }

    .logo-sub {
      font-size: 13px;
      color: rgba(235,235,245,.5);
      margin-top: -6px;
    }

    .error-msg {
      background: rgba(255, 59, 48, .12);
      border: 1px solid rgba(255, 59, 48, .25);
      border-radius: 10px;
      padding: 10px 14px;
      font-size: 13px; color: #ff6961;
      text-align: center;
      margin-bottom: 16px;
    }

    .section-label {
      font-size: 12px;
      font-weight: 600;
      color: rgba(235,235,245,.45);
      letter-spacing: .6px;
      text-transform: uppercase;
      margin-bottom: 6px;
      padding-left: 4px;
    }

    .field-group {
      background: rgba(44, 44, 46, 0.70);
      border: 1px solid rgba(255,255,255,.07);
      border-radius: 12px;
      overflow: hidden;
      margin-bottom: 6px;
    }

    .field-group input {
      display: block; width: 100%;
      background: transparent; border: none;
      border-bottom: 1px solid rgba(255,255,255,.06);
      padding: 14px 16px;
      font-size: 16px; color: #f5f5f7;
      font-family: inherit; outline: none;
      transition: background .15s;
    }

    .field-group input:last-child { border-bottom: none; }
    .field-group input::placeholder { color: rgba(235,235,245,.35); }
    .field-group input:focus { background: rgba(255,255,255,.04); }

    .hint {
      font-size: 12px;
      color: rgba(235,235,245,.35);
      padding: 0 4px;
      margin-bottom: 20px;
    }

    .btn-primary {
      display: block; width: 100%;
      padding: 15px;
      border: none; border-radius: 12px;
      background: #0a84ff; color: #fff;
      font-size: 16px; font-weight: 600;
      font-family: inherit; letter-spacing: -.1px;
      cursor: pointer;
      transition: opacity .15s, transform .1s;
      margin-top: 20px;
    }

    .btn-primary:hover  { opacity: .88; }
    .btn-primary:active { opacity: .75; transform: scale(.98); }

    .footer-links {
      display: flex;
      justify-content: center;
      margin-top: 24px;
    }

    .footer-links a {
      font-size: 13px;
      color: #0a84ff;
      text-decoration: none;
    }

    .footer-links a:hover { opacity: .75; }
  </style>
</head>
<body>
  <div class="card">
    <div class="logo">
      <div class="app-icon">🐱</div>
      <div class="logo-title">สร้างบัญชี</div>
      <div class="logo-sub">Catcat · จัดการอุปกรณ์ Android</div>
    </div>

    <?php if ($error): ?>
      <div class="error-msg"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post">
      <div class="section-label">ชื่อผู้ใช้</div>
      <div class="field-group">
        <input name="username" type="text"
               placeholder="ตัวอักษร ตัวเลข หรือ _"
               autofocus autocomplete="username"
               value="<?= htmlspecialchars($username) ?>">
      </div>
      <div class="hint">ใช้ได้เฉพาะ a–z, 0–9, _ และต้องมีอย่างน้อย 3 ตัว</div>

      <div class="section-label">รหัสผ่าน</div>
      <div class="field-group">
        <input name="password" type="password"
               placeholder="รหัสผ่าน"
               autocomplete="new-password">
        <input name="confirm" type="password"
               placeholder="ยืนยันรหัสผ่าน"
               autocomplete="new-password">
      </div>
      <div class="hint">อย่างน้อย 6 ตัวอักษร</div>

      <button type="submit" class="btn-primary">สมัครสมาชิก</button>
    </form>

    <div class="footer-links">
      <a href="/login.php">มีบัญชีแล้ว? เข้าสู่ระบบ</a>
    </div>
  </div>
</body>
</html>
