<?php
require_once __DIR__ . '/../config/config.php';
$title = 'Users';
$u = require_admin();

// ── Toggle status ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_user'])) {
    csrf_verify();
    $id  = (int)($_POST['user_id'] ?? 0);
    if ($id === (int)$u['id']) { flash('error', 'You cannot deactivate your own account.'); redirect('users.php'); }
    $s   = db()->prepare('SELECT status,name FROM users WHERE id=?');
    $s->execute([$id]);
    $row = $s->fetch();
    if ($row) {
        $new = $row['status'] === 'Active' ? 'Inactive' : 'Active';
        db()->prepare('UPDATE users SET status=? WHERE id=?')->execute([$new, $id]);
        log_activity($u['id'], 'User Status Changed', $row['name'] . " → {$new}");
        flash('success', $row['name'] . " set to {$new}.");
    }
    redirect('users.php');
}

// ── Add user ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    csrf_verify();
    $name  = trim($_POST['name']     ?? '');
    $email = trim($_POST['email']    ?? '');
    $role  = in_array($_POST['role'] ?? '', ['admin','staff','customer']) ? $_POST['role'] : 'staff';
    $pass  = $_POST['password']      ?? '';
    if (!$name || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 6) {
        flash('error', 'Name, valid email, and password (min 6 chars) are required.');
        redirect('users.php');
    }
    if ($role === 'admin') require_admin(); // only admins can create admins
    try {
        db()->prepare('INSERT INTO users(name,email,password,role) VALUES(?,?,?,?)')
            ->execute([$name, $email, password_hash($pass, PASSWORD_DEFAULT), $role]);
        log_activity($u['id'], 'Added User', "{$email} ({$role})");
        flash('success', 'User added successfully.');
    } catch (Throwable) {
        flash('error', 'Email already exists.');
    }
    redirect('users.php');
}

// ── Edit user (role, status) ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_user'])) {
    csrf_verify();
    $id      = (int)$_POST['user_id'];
    $name    = trim($_POST['name']   ?? '');
    $role    = in_array($_POST['role'] ?? '', ['admin','staff','customer']) ? $_POST['role'] : 'staff';
    $status  = in_array($_POST['status'] ?? '', ['Active','Inactive']) ? $_POST['status'] : 'Active';
    $newPass = trim($_POST['new_password'] ?? '');
    if ($id === (int)$u['id'] && $status === 'Inactive') { flash('error', 'Cannot deactivate your own account.'); redirect('users.php'); }
    $set  = ['name=?','role=?','status=?'];
    $vals = [$name, $role, $status];
    if ($newPass !== '') {
        if (strlen($newPass) < 6) { flash('error', 'New password must be at least 6 characters.'); redirect('users.php'); }
        $set[]  = 'password=?';
        $vals[] = password_hash($newPass, PASSWORD_DEFAULT);
    }
    $vals[] = $id;
    db()->prepare('UPDATE users SET ' . implode(',', $set) . ' WHERE id=?')->execute($vals);
    log_activity($u['id'], 'Edited User', "ID {$id} · {$role} · {$status}");
    flash('success', 'User updated.');
    redirect('users.php');
}

require 'header.php';

// ── Load users ───────────────────────────────────────────
$search = trim($_GET['q'] ?? '');
$where  = ['1=1'];
$params = [];
if ($search) { $where[] = '(name LIKE ? OR email LIKE ?)'; $params = ["%{$search}%","%{$search}%"]; }
$stmt = db()->prepare('SELECT * FROM users WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC');
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Load edit target
$editUser = null;
if (isset($_GET['edit'])) {
    $es = db()->prepare('SELECT * FROM users WHERE id=?');
    $es->execute([(int)$_GET['edit']]);
    $editUser = $es->fetch();
}

$roleBadge = ['admin'=>'badge-admin','staff'=>'badge-staff','customer'=>'badge-customer'];
?>

<div style="display:grid;grid-template-columns:380px 1fr;gap:18px;align-items:start" class="form-grid">

  <!-- Add / Edit user form -->
  <div class="staff-panel" style="margin-top:0">
    <h2 style="margin-bottom:16px"><?= $editUser ? '<i class="bi bi-pencil"></i> Edit User' : '<i class="bi bi-plus-lg"></i> Add User' ?></h2>
    <form method="post" action="users.php">
      <?= csrf_field() ?>
      <?php if ($editUser): ?>
        <input type="hidden" name="edit_user"  value="1">
        <input type="hidden" name="user_id" value="<?= (int)$editUser['id'] ?>">
      <?php else: ?>
        <input type="hidden" name="add_user"  value="1">
      <?php endif; ?>

      <div class="form-group">
        <label>Full Name *</label>
        <input name="name" required value="<?= e($editUser['name'] ?? '') ?>">
      </div>
      <?php if (!$editUser): ?>
        <div class="form-group">
          <label>Email Address *</label>
          <input type="email" name="email" required>
        </div>
      <?php else: ?>
        <div class="form-group">
          <label>Email (cannot change)</label>
          <input value="<?= e($editUser['email']) ?>" disabled style="background:#f5f5f5">
        </div>
      <?php endif; ?>
      <div class="form-group">
        <label>Role *</label>
        <select name="role">
          <?php foreach (['staff'=>'Staff','customer'=>'Customer','admin'=>'Admin'] as $r => $l): ?>
            <?php if ($r === 'admin' && $u['role'] !== 'admin') continue; // only admins can assign admin ?>
            <option value="<?= $r ?>" <?= ($editUser['role']??'staff')===$r?'selected':'' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if ($editUser): ?>
        <div class="form-group">
          <label>Status</label>
          <select name="status">
            <option value="Active"   <?= ($editUser['status']??'')==='Active'  ?'selected':'' ?>>Active</option>
            <option value="Inactive" <?= ($editUser['status']??'')==='Inactive'?'selected':'' ?>>Inactive</option>
          </select>
        </div>
        <div class="form-group">
          <label>New Password <span class="muted">(leave blank to keep current)</span></label>
          <input type="password" name="new_password" minlength="6" placeholder="New password…">
        </div>
      <?php else: ?>
        <div class="form-group">
          <label>Password * (min 6 characters)</label>
          <input type="password" name="password" required minlength="6">
        </div>
      <?php endif; ?>

      <button class="btn"><?= $editUser ? 'Save Changes' : 'Add User' ?></button>
      <?php if ($editUser): ?>
        <a href="users.php" class="btn secondary" style="margin-left:8px">Cancel</a>
      <?php endif; ?>
    </form>
  </div>

  <!-- Users table -->
  <div class="staff-panel" style="margin-top:0">
    <div class="toolbar">
      <h2>All Users <span class="muted" style="font-weight:400">(<?= count($rows) ?>)</span></h2>
      <form method="get" style="display:flex;gap:8px">
        <input name="q" value="<?= e($search) ?>" placeholder="Search…" style="width:180px">
        <button class="btn small">Search</button>
        <?php if ($search): ?><a href="users.php" class="btn secondary small">Clear</a><?php endif; ?>
      </form>
    </div>
    <div class="responsive-scroll">
      <table>
        <tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Joined</th><th>Actions</th></tr>
        <?php foreach ($rows as $r):
            $rc = $roleBadge[$r['role']] ?? '';
        ?>
          <tr>
            <td><b><?= e($r['name']) ?></b></td>
            <td class="muted"><?= e($r['email']) ?></td>
            <td><span class="status <?= $rc ?>"><?= e(ucfirst($r['role'])) ?></span></td>
            <td><span class="status <?= $r['status']==='Active'?'':'off' ?>"><?= e($r['status']) ?></span></td>
            <td class="muted"><?= date('M d, Y', strtotime($r['created_at'])) ?></td>
            <td style="white-space:nowrap">
              <a href="users.php?edit=<?= (int)$r['id'] ?>" class="btn secondary small" data-tooltip="Edit user" data-tooltip-pos="bottom"><i class="bi bi-pencil"></i></a>
              <?php if ($r['id'] !== $u['id']): ?>
                <form method="post" action="users.php" style="display:inline" data-confirm-submit="<?= $r['status']==='Active'?'Deactivate':'Activate' ?> <?= e($r['name']) ?>?">
                  <?= csrf_field() ?>
                  <input type="hidden" name="toggle_user" value="1">
                  <input type="hidden" name="user_id" value="<?= (int)$r['id'] ?>">
                  <button class="btn secondary small" type="submit"
                          aria-label="<?= $r['status']==='Active'?'Deactivate':'Activate' ?> user"
                          data-tooltip="<?= $r['status']==='Active' ? 'Deactivate user' : 'Activate user' ?>" data-tooltip-pos="bottom">
                    <?= $r['status']==='Active' ? '<i class="bi bi-pause"></i>' : '<i class="bi bi-play"></i>' ?>
                  </button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
          <tr><td colspan="6" style="text-align:center;padding:30px;color:var(--muted)">No users found</td></tr>
        <?php endif; ?>
      </table>
    </div>
  </div>

</div>

<?php require 'footer.php'; ?>
