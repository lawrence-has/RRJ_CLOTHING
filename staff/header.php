<?php
require_once __DIR__ . '/../config/config.php';
$u  = require_staff();
$cp = basename($_SERVER['PHP_SELF']);

$navLinks = [
    ['file' => 'dashboard.php',  'icon' => '<i class="bi bi-speedometer2"></i>', 'label' => 'Dashboard',      'group' => 'main'],
    ['file' => 'products.php',   'icon' => '<i class="bi bi-tag"></i>',          'label' => 'Products',        'group' => 'catalog'],
    ['file' => 'inventory.php',  'icon' => '<i class="bi bi-box-seam"></i>',     'label' => 'Inventory',       'group' => 'catalog'],
    ['file' => 'sales.php',      'icon' => '<i class="bi bi-credit-card"></i>',  'label' => 'Sales',           'group' => 'orders'],
    ['file' => 'suppliers.php',  'icon' => '<i class="bi bi-truck"></i>',         'label' => 'Suppliers',       'group' => 'orders'],
    ['file' => 'reports.php',    'icon' => '<i class="bi bi-bar-chart-line"></i>','label' => 'Reports',        'group' => 'reports'],
    ['file' => 'users.php',      'icon' => '<i class="bi bi-shield-lock"></i>',  'label' => 'Users',           'group' => 'system'],
    ['file' => 'settings.php',   'icon' => '<i class="bi bi-gear"></i>',         'label' => 'Settings',        'group' => 'system'],
    ['file' => 'activity.php',   'icon' => '<i class="bi bi-clipboard-data"></i>','label' => 'Activity Log',  'group' => 'system'],
];

if (($u['role'] ?? '') !== 'admin') {
    $navLinks = array_values(array_filter($navLinks, static fn($link) =>
        !in_array($link['file'], ['users.php', 'settings.php'], true)
    ));
}

$groupLabels = [
    'main'    => '',
    'catalog' => 'Catalog',
    'orders'  => 'Orders',
    'reports' => 'Analytics',
    'system'  => 'System',
];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= e($title ?? 'Dashboard') ?> · RRJ Management</title>
  <link rel="stylesheet" href="../assets/style.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body>

<!-- Mobile sidebar toggle -->
<button class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle sidebar"><i class="bi bi-list"></i></button>
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<div class="staff-shell">

  <!-- Sidebar -->
  <aside class="staff-sidebar" id="staffSidebar" role="navigation" aria-label="Staff navigation">

    <div class="sidebar-logo">
      <img src="../assets/logo.png" alt="RRJ Clothing">
      <span class="sidebar-brand-caption">STAFF MANAGEMENT PORTAL</span>
    </div>

    <nav class="staff-nav">
      <?php
      $lastGroup = null;
      foreach ($navLinks as $link):
        if ($link['group'] !== $lastGroup):
          if ($lastGroup !== null) echo '</div>';
          $lastGroup = $link['group'];
          $groupLabel = $groupLabels[$link['group']] ?? '';
          echo '<div class="nav-group">';
          if ($groupLabel) echo '<div class="nav-label">' . e($groupLabel) . '</div>';
        endif;
      ?>
        <a href="<?= e($link['file']) ?>"
           class="<?= $cp === $link['file'] ? 'active' : '' ?>"
           title="<?= e($link['label']) ?>"
           data-tooltip="<?= e($link['label']) ?>" data-tooltip-pos="bottom">
          <span class="nav-icon"><?= $link['icon'] ?></span>
          <span class="nav-text"><?= e($link['label']) ?></span>
        </a>
      <?php endforeach; ?>
      <?php if ($lastGroup !== null) echo '</div>'; ?>
    </nav>

    <div class="sidebar-bottom">
      <form method="post" action="logout.php" class="sidebar-logout">
        <?= csrf_field() ?>
        <button type="submit" title="Logout" data-tooltip="Log out of portal" data-tooltip-pos="bottom">
          <span class="nav-icon"><i class="bi bi-box-arrow-right"></i></span><span class="nav-text">Logout</span>
        </button>
      </form>
    </div>
  </aside>

  <!-- Main content -->
  <section class="staff-main">
    <div class="staff-topbar">
      <div>
        <div class="staff-eyebrow">RRJ CLOTHING · STAFF PORTAL</div>
        <h1><?= e($title ?? 'Dashboard') ?></h1>
      </div>
      <div class="right">
        <div class="staff-user" data-tooltip="Logged in as <?= e($u['role']) ?>" data-tooltip-pos="bottom">
          <div class="avatar"><?= strtoupper(substr($u['name'], 0, 1)) ?></div>
          <span class="staff-user-name"><?= e($u['name']) ?></span>
          <span class="staff-role-chip"><?= e(ucfirst($u['role'])) ?></span>
        </div>
      </div>
    </div>

    <?php foreach (flashes() as $f): ?>
      <div class="flash <?= e($f['type']) ?>" role="alert"><?= e($f['message']) ?></div>
    <?php endforeach; ?>
