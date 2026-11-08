<?php require_once __DIR__.'/../config/config.php'; $u = current_user(); $page = $_GET['page'] ?? 'home'; ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= e($title ?? APP_NAME) ?> · RRJ Clothing</title>
  <link rel="stylesheet" href="assets/style.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body>

<header class="topbar">
  <a class="brand" href="index.php"><img src="assets/logo.png" alt="RRJ Clothing"></a>

  <nav aria-label="Main navigation">
    <a href="index.php"                class="<?= $page==='home'    ?'active':'' ?>">Home</a>
    <a href="index.php?page=shop"      class="<?= $page==='shop'    ?'active':'' ?>">Shop</a>
    <a href="index.php?page=orders"    class="<?= $page==='orders'  ?'active':'' ?>">My Orders</a>
  </nav>

  <div class="top-actions">
    <a href="index.php?page=cart" class="cart-btn" aria-label="Cart">
      <i class="bi bi-cart3"></i> <span class="badge cart-badge"><?= cart_count() ?></span>
    </a>
    <?php if ($u && $u['role'] === 'customer'): ?>
      <a href="index.php?page=account" class="<?= $page==='account'?'active':'' ?>">
        <i class="bi bi-person"></i> <?= e(explode(' ', $u['name'])[0]) ?>
      </a>
      <a href="index.php?page=logout" class="btn secondary small">Logout</a>
    <?php else: ?>
      <a href="index.php?page=login">Login</a>
      <a href="index.php?page=register" class="btn small">Register</a>
    <?php endif; ?>
  </div>

  <button class="hamburger" id="hamburger" aria-label="Toggle menu" aria-expanded="false">
    <span></span><span></span><span></span>
  </button>
</header>

<!-- Mobile nav -->
<nav class="mobile-nav" id="mobileNav" aria-label="Mobile navigation">
  <a href="index.php"               class="<?= $page==='home'    ?'active':'' ?>"><i class="bi bi-house"></i> Home</a>
  <a href="index.php?page=shop"     class="<?= $page==='shop'    ?'active':'' ?>"><i class="bi bi-bag"></i> Shop</a>
  <a href="index.php?page=orders"   class="<?= $page==='orders'  ?'active':'' ?>"><i class="bi bi-box-seam"></i> My Orders</a>
  <?php if ($u && $u['role'] === 'customer'): ?>
    <a href="index.php?page=account"><i class="bi bi-person"></i> My Account</a>
    <a href="index.php?page=logout"><i class="bi bi-box-arrow-right"></i> Logout</a>
  <?php else: ?>
    <a href="index.php?page=login">Login</a>
    <a href="index.php?page=register" class="btn small" style="width:fit-content">Register</a>
  <?php endif; ?>
</nav>

<?php foreach (flashes() as $f): ?>
  <div class="flash <?= e($f['type']) ?>" role="alert"><?= e($f['message']) ?></div>
<?php endforeach; ?>

<main class="container">
