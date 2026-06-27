<?php
require_once __DIR__ . '/../../back/lib/auth.php';

// Already logged in → go home
if (current_user()) { header('Location: /'); exit; }

$error = '';
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
            // Auto-login after register
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
  <title>Panda · สมัครสมาชิก</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tailwindcss@3.4.4/dist/tailwind.min.css"
        integrity="sha512-7eyHd5j5+VhSuzopZhUpG8dh5LOk0yHoL8BtlaO/eq4dM2YePSlftJi1yDDzCDuju1WhZuwdRw59aGzXdcra5Q=="
        crossorigin="anonymous">
</head>
<body class="bg-slate-900 text-slate-100 min-h-screen flex items-center justify-center p-4">
  <form method="post" class="w-full max-w-sm bg-slate-800 rounded-2xl p-6 shadow-xl space-y-4">
    <h1 class="text-2xl font-bold text-center">🐼 สมัครสมาชิก</h1>

    <?php if ($error): ?>
      <p class="text-red-400 text-sm text-center"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <input name="username" placeholder="ชื่อผู้ใช้ (a–z, 0–9, _)" autofocus autocomplete="username"
           value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
           class="w-full rounded-lg bg-slate-700 px-4 py-3 outline-none focus:ring-2 ring-emerald-500">

    <input name="password" type="password" placeholder="รหัสผ่าน (≥6 ตัว)" autocomplete="new-password"
           class="w-full rounded-lg bg-slate-700 px-4 py-3 outline-none focus:ring-2 ring-emerald-500">

    <input name="confirm" type="password" placeholder="ยืนยันรหัสผ่าน" autocomplete="new-password"
           class="w-full rounded-lg bg-slate-700 px-4 py-3 outline-none focus:ring-2 ring-emerald-500">

    <button class="w-full rounded-lg bg-emerald-600 hover:bg-emerald-500 py-3 font-semibold">
      สมัครสมาชิก
    </button>

    <p class="text-center text-sm text-slate-400">
      มีบัญชีแล้ว? <a href="/login.php" class="text-emerald-400 hover:underline">เข้าสู่ระบบ</a>
    </p>
  </form>
</body>
</html>
