<?php
require_once __DIR__ . '/../config/config.php';
$title = 'Settings';
$u = require_admin();

// ── Change own password ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_pw'])) {
    csrf_verify();
    $current = (string)($_POST['current_pw'] ?? '');
    $newPass = (string)($_POST['new_pw'] ?? '');
    $q = db()->prepare('SELECT password FROM users WHERE id=? LIMIT 1');
    $q->execute([(int)$u['id']]);
    $storedHash = (string)$q->fetchColumn();

    if (!password_verify($current, $storedHash)) {
        flash('error', 'Your current password is incorrect.');
    } elseif (strlen($newPass) < 8) {
        flash('error', 'Your new password must contain at least 8 characters.');
    } else {
        db()->prepare('UPDATE users SET password=? WHERE id=?')->execute([password_hash($newPass, PASSWORD_DEFAULT), (int)$u['id']]);
        session_regenerate_id(true);
        $fresh = db()->prepare('SELECT * FROM users WHERE id=?');
        $fresh->execute([(int)$u['id']]);
        $_SESSION['user'] = $fresh->fetch();
        log_activity((int)$u['id'], 'Changed Password', 'Staff account password updated');
        flash('success', 'Your password has been updated.');
    }
    redirect('settings.php');
}

// ── Save settings ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $allowed = [
        'store_name', 'store_phone', 'store_email', 'store_address',
        'shipping_standard', 'shipping_express', 'currency_symbol',
    ];
    $s = db()->prepare('INSERT INTO settings(`key`,`value`) VALUES(?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)');
    foreach ($allowed as $key) {
        if (isset($_POST[$key])) {
            $val = trim($_POST[$key]);
            // Validate numeric fields
            if (in_array($key, ['shipping_standard','shipping_express'], true)) {
                $val = (string)max(0, (float)$val);
            }
            $s->execute([$key, $val]);
        }
    }
    log_activity($u['id'], 'Updated Settings', '');
    flash('success', 'Settings saved successfully.');
    redirect('settings.php');
}

require 'header.php';
?>

<div style="max-width:760px">

  <!-- Store info -->
  <div class="staff-panel">
    <h2 style="margin-bottom:20px"><i class="bi bi-shop"></i> Store Information</h2>
    <form method="post" action="settings.php">
      <?= csrf_field() ?>
      <div class="form-grid">
        <div class="form-group">
          <label>Store Name</label>
          <input name="store_name" value="<?= e(setting('store_name','RRJ Clothing')) ?>">
        </div>
        <div class="form-group">
          <label>Currency Symbol</label>
          <input name="currency_symbol" value="<?= e(setting('currency_symbol','₱')) ?>" style="width:80px">
        </div>
        <div class="form-group">
          <label>Phone Number</label>
          <input name="store_phone" value="<?= e(setting('store_phone')) ?>">
        </div>
        <div class="form-group">
          <label>Email</label>
          <input type="email" name="store_email" value="<?= e(setting('store_email')) ?>">
        </div>
      </div>
      <div class="form-group">
        <label>Store Address</label>
        <textarea name="store_address" rows="2"><?= e(setting('store_address')) ?></textarea>
      </div>
      <button class="btn">Save Store Info</button>
    </form>
  </div>

  <!-- Shipping rates -->
  <div class="staff-panel">
    <h2 style="margin-bottom:20px"><i class="bi bi-truck"></i> Shipping Rates</h2>
    <form method="post" action="settings.php">
      <?= csrf_field() ?>
      <div class="form-grid">
        <div class="form-group">
          <label>Standard Delivery (₱)</label>
          <input type="number" step="0.01" min="0" name="shipping_standard" value="<?= e(setting('shipping_standard','60')) ?>">
          <div class="form-hint">3–5 business days</div>
        </div>
        <div class="form-group">
          <label>Express Delivery (₱)</label>
          <input type="number" step="0.01" min="0" name="shipping_express" value="<?= e(setting('shipping_express','120')) ?>">
          <div class="form-hint">1–2 business days</div>
        </div>
      </div>
      <button class="btn">Save Shipping Rates</button>
    </form>
  </div>

  <!-- System info -->
  <div class="staff-panel">
    <h2 style="margin-bottom:14px"><i class="bi bi-gear"></i> System Information</h2>
    <table>
      <tr><td class="muted" style="width:200px">PHP Version</td><td><?= phpversion() ?></td></tr>
      <tr><td class="muted">System</td><td>RRJ Clothing Management System</td></tr>
      <tr><td class="muted">Database</td><td>MySQL / MariaDB</td></tr>
      <tr><td class="muted">Server Time</td><td><?= date('F d, Y g:i A') ?></td></tr>
      <tr><td class="muted">Uploads Folder</td><td><?= file_exists(UPLOAD_DIR) ? '<i class="bi bi-check-circle-fill" style="color:var(--green)"></i> Writable' : '<i class="bi bi-exclamation-triangle-fill" style="color:#e65100"></i> Not found' ?></td></tr>
      <tr><td class="muted">Logged in as</td><td><?= e($u['name']) ?> (<?= e($u['role']) ?>)</td></tr>
    </table>
  </div>

  <!-- Change own password -->
  <div class="staff-panel">
    <h2 style="margin-bottom:20px"><i class="bi bi-lock"></i> Change My Password</h2>
    <form method="post" action="settings.php" id="pwForm">
      <?= csrf_field() ?>
      <input type="hidden" name="change_pw" value="1">
      <div class="form-grid">
        <div class="form-group">
          <label>Current Password</label>
          <input type="password" name="current_pw" required>
        </div>
        <div class="form-group">
          <label>New Password (minimum 8 characters)</label>
          <input type="password" name="new_pw" required minlength="8">
        </div>
      </div>
      <button class="btn">Update Password</button>
    </form>
  </div>

</div>

<?php require 'footer.php'; ?>
