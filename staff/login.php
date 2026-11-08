<?php
require_once __DIR__ . '/../config/config.php';
if (current_user() && in_array(current_user()['role'], ['admin','staff'], true)) {
    redirect('dashboard.php');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';
    $s = db()->prepare('SELECT * FROM users WHERE email=? AND role IN ("admin","staff") AND status="Active" LIMIT 1');
    $s->execute([$email]);
    $u = $s->fetch();
    if ($u && password_verify($pass, $u['password'])) {
        login_user($u);
        log_activity((int)$u['id'], 'Login', 'Staff login');
        redirect('dashboard.php');
    }
    flash('error', 'Invalid credentials or account is inactive.');
}
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Login · RRJ Clothing Management</title>
  <link rel="stylesheet" href="../assets/style.css">
  <style>
    body {
      display: flex; align-items: center; justify-content: center;
      min-height: 100vh;
      background: #0d0d0d;
    }
    .login-wrap { width: 100%; max-width: 420px; padding: 20px; }
    .login-card {
      background: #fff;
      border-radius: 14px;
      padding: 40px 36px 36px;
      box-shadow: 0 20px 60px rgba(0,0,0,.5);
    }
    .login-brand {
      text-align: center;
      margin-bottom: 28px;
    }
    .login-brand .logo-wrap {
      background: #fff;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border-radius: 12px;
      padding: 12px 20px;
      margin-bottom: 14px;
      border: 1px solid #e5e5e5;
    }
    .login-brand img {
      width: 130px;
      height: auto;
      display: block;
    }
    .login-brand h1 {
      font-size: 18px;
      font-weight: 800;
      color: #111;
      margin: 0 0 4px;
    }
    .login-brand .sub {
      font-size: 12px;
      color: #888;
    }
    .login-divider {
      height: 1px;
      background: var(--line);
      margin: 22px 0;
    }
    .form-group label { color: #333; }
  </style>
</head>
<body>
<div class="login-wrap">
  <div class="login-card">
    <div class="login-brand">
      <div class="logo-wrap">
        <img src="../assets/logo.png" alt="RRJ Clothing">
      </div>
      <h1>Staff Portal</h1>
      <div class="sub">Secure staff access for store operations and management</div>
    </div>

    <?php foreach (flashes() as $f): ?>
      <div class="flash <?= e($f['type']) ?>" role="alert"><?= e($f['message']) ?></div>
    <?php endforeach; ?>

    <form method="post" action="login.php">
      <?= csrf_field() ?>
      <div class="form-group">
        <label>Email Address</label>
        <input type="email" name="email" required autofocus
               value="<?= e($_POST['email'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Password</label>
        <input type="password" name="password" required>
      </div>
      <button class="btn" style="width:100%;justify-content:center;margin-top:8px;padding:13px">
        Sign In
      </button>
    </form>
  </div>
</div>
</body>
</html>
