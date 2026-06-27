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
  <title>Panda · เข้าสู่ระบบ</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tailwindcss@3.4.4/dist/tailwind.min.css"
        integrity="sha512-7eyHd5j5+VhSuzopZhUpG8dh5LOk0yHoL8BtlaO/eq4dM2YePSlftJi1yDDzCDuju1WhZuwdRw59aGzXdcra5Q=="
        crossorigin="anonymous">
</head>
<body class="bg-slate-900 text-slate-100 min-h-screen flex items-center justify-center p-4">
  <form method="post" class="w-full max-w-sm bg-slate-800 rounded-2xl p-6 shadow-xl space-y-4">
    <h1 class="text-2xl font-bold text-center">🐼 Panda</h1>
    <?php if ($error): ?>
      <p class="text-red-400 text-sm text-center"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>
    <input name="username" placeholder="ชื่อผู้ใช้" autofocus autocomplete="username"
           class="w-full rounded-lg bg-slate-700 px-4 py-3 outline-none focus:ring-2 ring-emerald-500">
    <input name="password" type="password" placeholder="รหัสผ่าน" autocomplete="current-password"
           class="w-full rounded-lg bg-slate-700 px-4 py-3 outline-none focus:ring-2 ring-emerald-500">
    <button class="w-full rounded-lg bg-emerald-600 hover:bg-emerald-500 py-3 font-semibold">
      เข้าสู่ระบบ
    </button>
  </form>
</body>
</html>
