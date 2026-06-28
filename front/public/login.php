<?php
require_once __DIR__ . '/../../back/lib/auth.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (login($_POST['username'] ?? '', $_POST['password'] ?? '')) {
        header('Location: /');
        exit;
    }
    $error = 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง';
}
if (current_user()) { header('Location: /'); exit; }
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
  <title>Catcat · เข้าสู่ระบบ</title>
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
      margin-bottom: 32px;
    }

    .logo-icon {
      width: 56px;
      height: 56px;
      border-radius: 14px;
      background: linear-gradient(145deg, #2a2a2e, #1a1a1e);
      border: 1px solid rgba(255,255,255,.1);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 28px;
      box-shadow: 0 4px 16px rgba(0,0,0,.4);
    }

    .logo-title {
      font-size: 22px;
      font-weight: 600;
      color: #f5f5f7;
      letter-spacing: -.3px;
    }

    .logo-sub {
      font-size: 13px;
      color: rgba(235,235,245,.5);
      letter-spacing: .1px;
      margin-top: -6px;
    }

    .error-msg {
      background: rgba(255, 59, 48, .12);
      border: 1px solid rgba(255, 59, 48, .25);
      border-radius: 10px;
      padding: 10px 14px;
      font-size: 13px;
      color: #ff6961;
      text-align: center;
      margin-bottom: 16px;
    }

    .field-group {
      background: rgba(44, 44, 46, 0.7);
      border: 1px solid rgba(255,255,255,.07);
      border-radius: 12px;
      overflow: hidden;
      margin-bottom: 16px;
    }

    .field-group input {
      display: block;
      width: 100%;
      background: transparent;
      border: none;
      border-bottom: 1px solid rgba(255,255,255,.06);
      padding: 14px 16px;
      font-size: 16px;
      color: #f5f5f7;
      outline: none;
      font-family: inherit;
      transition: background .15s;
    }

    .field-group input:last-child {
      border-bottom: none;
    }

    .field-group input::placeholder {
      color: rgba(235,235,245,.35);
    }

    .field-group input:focus {
      background: rgba(255,255,255,.04);
    }

    .btn-primary {
      display: block;
      width: 100%;
      padding: 15px;
      border: none;
      border-radius: 12px;
      background: #0a84ff;
      color: #fff;
      font-size: 16px;
      font-weight: 600;
      font-family: inherit;
      letter-spacing: -.1px;
      cursor: pointer;
      transition: opacity .15s, transform .1s;
      margin-top: 20px;
    }

    .btn-primary:hover  { opacity: .88; }
    .btn-primary:active { opacity: .75; transform: scale(.98); }

    .footer-links {
      display: flex;
      justify-content: center;
      gap: 20px;
      margin-top: 24px;
    }

    .footer-links a {
      font-size: 12px;
      color: rgba(235,235,245,.4);
      text-decoration: none;
      transition: color .15s;
    }

    .footer-links a:hover { color: rgba(235,235,245,.7); }
  </style>
</head>
<body>
  <div class="card">
    <div class="logo">
      <div class="logo-icon">🐱</div>
      <div class="logo-title">Catcat</div>
      <div class="logo-sub">จัดการอุปกรณ์ Android</div>
    </div>

    <?php if ($error): ?>
      <div class="error-msg"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post">
      <div class="field-group">
        <input name="username" type="text" placeholder="ชื่อผู้ใช้"
               value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
               autofocus autocomplete="username">
        <input name="password" type="password" placeholder="รหัสผ่าน"
               autocomplete="current-password">
      </div>
      <button type="submit" class="btn-primary">เข้าสู่ระบบ</button>
    </form>

    <div class="footer-links">
      <a href="/register.php">สมัครสมาชิก</a>
    </div>
  </div>
</body>
</html>
