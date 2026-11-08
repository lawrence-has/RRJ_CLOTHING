<?php
require_once __DIR__ . '/../config/config.php';
$title = 'Suppliers';
$u = require_staff();

// ── Delete supplier ──────────────────────────────────────
if (isset($_GET['delete']) && isset($_GET['csrf_token'])) {
    csrf_verify();
    $id = (int)$_GET['delete'];
    db()->prepare('DELETE FROM suppliers WHERE id=?')->execute([$id]);
    log_activity($u['id'], 'Deleted Supplier', 'Supplier ID ' . $id);
    flash('success', 'Supplier deleted.');
    redirect('suppliers.php');
}

// ── Toggle supplier status ───────────────────────────────
if (isset($_GET['toggle']) && isset($_GET['csrf_token'])) {
    csrf_verify();
    $id  = (int)$_GET['toggle'];
    $s   = db()->prepare('SELECT status, name FROM suppliers WHERE id=?');
    $s->execute([$id]);
    $row = $s->fetch();
    if ($row) {
        $new = $row['status'] === 'Active' ? 'Inactive' : 'Active';
        db()->prepare('UPDATE suppliers SET status=? WHERE id=?')->execute([$new, $id]);
        log_activity($u['id'], 'Supplier Status Changed', $row['name'] . " → {$new}");
        flash('success', $row['name'] . " set to {$new}.");
    }
    redirect('suppliers.php');
}

// ── Update restock request status ───────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_restock'])) {
    csrf_verify();
    $rid    = (int)$_POST['restock_id'];
    $status = $_POST['restock_status'] ?? '';
    $allowed = ['Pending', 'Ordered', 'Received', 'Cancelled'];
    if (!in_array($status, $allowed, true)) {
        flash('error', 'Invalid status.');
        redirect('suppliers.php?view=' . (int)$_POST['supplier_id']);
    }
    $rs = db()->prepare('SELECT rr.*, p.name AS product_name, p.stock FROM restock_requests rr JOIN products p ON p.id = rr.product_id WHERE rr.id=?');
    $rs->execute([$rid]);
    $rr = $rs->fetch();
    if ($rr) {
        db()->prepare('UPDATE restock_requests SET status=? WHERE id=?')->execute([$status, $rid]);
        // When marked Received, add the quantity to stock
        if ($status === 'Received' && $rr['status'] !== 'Received') {
            $qty = (int)$rr['quantity'];
            db()->prepare('UPDATE products SET stock=stock+? WHERE id=?')->execute([$qty, $rr['product_id']]);
            db()->prepare('INSERT INTO stock_movements(product_id,user_id,type,quantity,note) VALUES(?,?,?,?,?)')
                ->execute([$rr['product_id'], $u['id'], 'in', $qty, 'Restock from supplier #' . $rr['supplier_id']]);
            log_activity($u['id'], 'Stock In', $rr['product_name'] . " +{$qty} via restock");
        }
        log_activity($u['id'], 'Restock Request Updated', "Request #{$rid} → {$status}");
        flash('success', 'Restock request updated to ' . $status . '.');
    }
    redirect('suppliers.php?view=' . (int)$_POST['supplier_id']);
}

// ── Save restock request ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_restock'])) {
    csrf_verify();
    $sid   = (int)$_POST['supplier_id'];
    $pid   = (int)$_POST['product_id'];
    $qty   = max(1, (int)$_POST['quantity']);
    $notes = trim($_POST['notes'] ?? '');
    if (!$sid || !$pid) {
        flash('error', 'Supplier and product are required.');
        redirect('suppliers.php?view=' . $sid);
    }
    db()->prepare('INSERT INTO restock_requests(supplier_id,product_id,quantity,notes,requested_by) VALUES(?,?,?,?,?)')
        ->execute([$sid, $pid, $qty, $notes, $u['id']]);
    // Fetch product name for activity log
    $pn = db()->prepare('SELECT name FROM products WHERE id=?');
    $pn->execute([$pid]);
    $pname = (string)($pn->fetchColumn() ?: 'Product #' . $pid);
    log_activity($u['id'], 'Restock Request Created', $pname . " × {$qty}");
    flash('success', 'Restock request created.');
    redirect('suppliers.php?view=' . $sid);
}

// ── Save supplier (add / edit) ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_supplier'])) {
    csrf_verify();
    $id           = (int)($_POST['id'] ?? 0);
    $name         = trim($_POST['name']         ?? '');
    $contact_name = trim($_POST['contact_name'] ?? '');
    $email        = trim($_POST['email']        ?? '');
    $phone        = trim($_POST['phone']        ?? '');
    $address      = trim($_POST['address']      ?? '');
    $notes        = trim($_POST['notes']        ?? '');
    $status       = in_array($_POST['status'] ?? '', ['Active','Inactive']) ? $_POST['status'] : 'Active';
    $products     = array_filter(array_map('intval', $_POST['products'] ?? []));
    $unit_costs   = $_POST['unit_costs'] ?? [];

    if (!$name) {
        flash('error', 'Supplier name is required.');
        redirect($id ? "suppliers.php?edit={$id}" : 'suppliers.php');
    }

    if ($id) {
        db()->prepare('UPDATE suppliers SET name=?,contact_name=?,email=?,phone=?,address=?,notes=?,status=? WHERE id=?')
            ->execute([$name, $contact_name, $email, $phone, $address, $notes, $status, $id]);
        log_activity($u['id'], 'Updated Supplier', $name);
        flash('success', 'Supplier updated.');
    } else {
        db()->prepare('INSERT INTO suppliers(name,contact_name,email,phone,address,notes,status) VALUES(?,?,?,?,?,?,?)')
            ->execute([$name, $contact_name, $email, $phone, $address, $notes, $status]);
        $id = (int)db()->lastInsertId();
        log_activity($u['id'], 'Added Supplier', $name);
        flash('success', 'Supplier added.');
    }

    // Sync linked products
    db()->prepare('DELETE FROM supplier_products WHERE supplier_id=?')->execute([$id]);
    $sp = db()->prepare('INSERT IGNORE INTO supplier_products(supplier_id,product_id,unit_cost) VALUES(?,?,?)');
    foreach ($products as $pid) {
        $cost = max(0, (float)($unit_costs[$pid] ?? 0));
        $sp->execute([$id, $pid, $cost]);
    }

    redirect('suppliers.php');
}

require 'header.php';

// ── Load edit data ───────────────────────────────────────
$edit = null;
$editLinkedProducts = [];
if (isset($_GET['edit'])) {
    $s = db()->prepare('SELECT * FROM suppliers WHERE id=?');
    $s->execute([(int)$_GET['edit']]);
    $edit = $s->fetch();
    if ($edit) {
        $lp = db()->prepare('SELECT product_id, unit_cost FROM supplier_products WHERE supplier_id=?');
        $lp->execute([$edit['id']]);
        foreach ($lp->fetchAll() as $row) {
            $editLinkedProducts[$row['product_id']] = $row['unit_cost'];
        }
    }
}

// ── View single supplier ─────────────────────────────────
$viewSupplier  = null;
$viewProducts  = [];
$viewRestocks  = [];
if (isset($_GET['view'])) {
    $s = db()->prepare('SELECT * FROM suppliers WHERE id=?');
    $s->execute([(int)$_GET['view']]);
    $viewSupplier = $s->fetch();
    if ($viewSupplier) {
        $vp = db()->prepare('SELECT p.*, sp.unit_cost FROM products p JOIN supplier_products sp ON sp.product_id=p.id WHERE sp.supplier_id=? ORDER BY p.name');
        $vp->execute([$viewSupplier['id']]);
        $viewProducts = $vp->fetchAll();

        $vr = db()->prepare('SELECT rr.*, p.name AS product_name, u.name AS requested_by_name
            FROM restock_requests rr
            JOIN products p ON p.id=rr.product_id
            LEFT JOIN users u ON u.id=rr.requested_by
            WHERE rr.supplier_id=? ORDER BY rr.id DESC');
        $vr->execute([$viewSupplier['id']]);
        $viewRestocks = $vr->fetchAll();
    }
}

// ── Supplier list with search ────────────────────────────
$search = trim($_GET['q'] ?? '');
$where  = ['1=1'];
$params = [];
if ($search) {
    $where[] = '(s.name LIKE ? OR s.contact_name LIKE ? OR s.email LIKE ? OR s.phone LIKE ?)';
    $params  = ["%{$search}%", "%{$search}%", "%{$search}%", "%{$search}%"];
}
$sql  = 'SELECT s.*, COUNT(DISTINCT sp.product_id) AS product_count, COUNT(DISTINCT rr.id) AS restock_count
         FROM suppliers s
         LEFT JOIN supplier_products sp ON sp.supplier_id=s.id
         LEFT JOIN restock_requests rr ON rr.supplier_id=s.id AND rr.status="Pending"
         WHERE ' . implode(' AND ', $where) . '
         GROUP BY s.id ORDER BY s.id DESC';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// All products for the form checkboxes
$allProducts = db()->query('SELECT id, name, category FROM products WHERE status="Active" ORDER BY name')->fetchAll();

$restockBadge = [
    'Pending'   => 'badge-pending',
    'Ordered'   => 'badge-processing',
    'Received'  => 'badge-delivered',
    'Cancelled' => 'badge-cancelled',
];

// Summary stats
$totalSuppliers = (int)db()->query('SELECT COUNT(*) FROM suppliers WHERE status="Active"')->fetchColumn();
$pendingRestocks = (int)db()->query('SELECT COUNT(*) FROM restock_requests WHERE status="Pending"')->fetchColumn();
$orderedRestocks = (int)db()->query('SELECT COUNT(*) FROM restock_requests WHERE status="Ordered"')->fetchColumn();
?>

<?php if ($viewSupplier): ?>
<!-- ── Supplier detail view ─────────────────────────────── -->
<div class="staff-panel">
  <div class="toolbar">
    <div>
      <h2><i class="bi bi-truck"></i> <?= e($viewSupplier['name']) ?></h2>
      <span class="muted">Supplier since <?= date('M Y', strtotime($viewSupplier['created_at'])) ?></span>
    </div>
    <div class="right">
      <a href="suppliers.php?edit=<?= (int)$viewSupplier['id'] ?>" class="btn secondary small"
         data-tooltip="Edit supplier" data-tooltip-pos="bottom">
        <i class="bi bi-pencil"></i> Edit
      </a>
      <a href="suppliers.php?toggle=<?= (int)$viewSupplier['id'] ?>&csrf_token=<?= e(csrf_token()) ?>"
         class="btn secondary small"
         data-confirm="<?= $viewSupplier['status']==='Active'?'Deactivate':'Activate' ?> this supplier?"
         data-tooltip="<?= $viewSupplier['status']==='Active' ? 'Deactivate supplier' : 'Activate supplier' ?>" data-tooltip-pos="bottom">
        <?= $viewSupplier['status']==='Active' ? '<i class="bi bi-pause"></i> Deactivate' : '<i class="bi bi-play"></i> Activate' ?>
      </a>
      <a href="suppliers.php" class="btn secondary small"><i class="bi bi-arrow-left"></i> Back</a>
    </div>
  </div>

  <!-- Stats -->
  <div class="staff-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:20px">
    <div class="staff-card">
      <div class="card-icon"><i class="bi bi-tag"></i></div>
      <h3>Products Supplied</h3>
      <strong><?= count($viewProducts) ?></strong>
    </div>
    <div class="staff-card yellow">
      <div class="card-icon"><i class="bi bi-hourglass-split"></i></div>
      <h3>Pending Restocks</h3>
      <strong><?= count(array_filter($viewRestocks, fn($r) => $r['status'] === 'Pending')) ?></strong>
    </div>
    <div class="staff-card">
      <h3>Status</h3>
      <strong style="font-size:16px;margin-top:8px">
        <span class="status <?= $viewSupplier['status']==='Active'?'':'off' ?>">
          <?= e($viewSupplier['status']) ?>
        </span>
      </strong>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px" class="form-grid">
    <!-- Contact info -->
    <div>
      <h3 style="margin-bottom:12px">Contact Information</h3>
      <table>
        <tr><td class="muted" style="width:130px">Contact Person</td><td><?= e($viewSupplier['contact_name'] ?: '—') ?></td></tr>
        <tr><td class="muted">Email</td><td><?= $viewSupplier['email'] ? '<a href="mailto:' . e($viewSupplier['email']) . '">' . e($viewSupplier['email']) . '</a>' : '—' ?></td></tr>
        <tr><td class="muted">Phone</td><td><?= e($viewSupplier['phone'] ?: '—') ?></td></tr>
        <tr><td class="muted">Address</td><td><?= nl2br(e($viewSupplier['address'] ?: '—')) ?></td></tr>
        <?php if ($viewSupplier['notes']): ?>
        <tr><td class="muted">Notes</td><td><?= nl2br(e($viewSupplier['notes'])) ?></td></tr>
        <?php endif; ?>
      </table>
    </div>

    <!-- Products supplied -->
    <div>
      <h3 style="margin-bottom:12px">Products Supplied</h3>
      <?php if (!$viewProducts): ?>
        <p class="muted">No products linked to this supplier.</p>
      <?php else: ?>
        <div class="responsive-scroll" style="max-height:220px;overflow-y:auto">
          <table>
            <tr><th>Product</th><th>Category</th><th>Unit Cost</th></tr>
            <?php foreach ($viewProducts as $p): ?>
              <tr>
                <td><?= e($p['name']) ?></td>
                <td class="muted"><?= e($p['category']) ?></td>
                <td><?= $p['unit_cost'] > 0 ? money((float)$p['unit_cost']) : '<span class="muted">—</span>' ?></td>
              </tr>
            <?php endforeach; ?>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Restock requests -->
<div class="staff-panel">
  <div class="toolbar">
    <h2><i class="bi bi-arrow-repeat"></i> Restock Requests</h2>
    <button class="btn small" onclick="openModal('restockModal')">
      <i class="bi bi-plus-lg"></i> New Request
    </button>
  </div>

  <?php if (!$viewRestocks): ?>
    <p class="muted" style="padding:20px 0">No restock requests yet for this supplier.</p>
  <?php else: ?>
    <div class="responsive-scroll">
      <table>
        <tr><th>Product</th><th>Qty</th><th>Requested By</th><th>Date</th><th>Notes</th><th>Status</th><th>Action</th></tr>
        <?php foreach ($viewRestocks as $rr):
            $rb = $restockBadge[$rr['status']] ?? '';
        ?>
          <tr>
            <td><b><?= e($rr['product_name']) ?></b></td>
            <td><?= (int)$rr['quantity'] ?></td>
            <td class="muted"><?= e($rr['requested_by_name'] ?? '—') ?></td>
            <td class="muted"><?= date('M d, Y', strtotime($rr['created_at'])) ?></td>
            <td class="muted"><?= e($rr['notes'] ?: '—') ?></td>
            <td><span class="status <?= $rb ?>"><?= e($rr['status']) ?></span></td>
            <td>
              <?php if (!in_array($rr['status'], ['Received','Cancelled'], true)): ?>
                <form method="post" action="suppliers.php" style="display:flex;gap:6px;align-items:center">
                  <?= csrf_field() ?>
                  <input type="hidden" name="update_restock" value="1">
                  <input type="hidden" name="restock_id" value="<?= (int)$rr['id'] ?>">
                  <input type="hidden" name="supplier_id" value="<?= (int)$viewSupplier['id'] ?>">
                  <select name="restock_status" style="width:130px">
                    <?php foreach (['Pending','Ordered','Received','Cancelled'] as $st): ?>
                      <option value="<?= $st ?>" <?= $rr['status']===$st?'selected':'' ?>><?= $st ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button class="btn small" data-tooltip="Update status" data-tooltip-pos="bottom">Save</button>
                </form>
              <?php else: ?>
                <span class="muted">—</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>
  <?php endif; ?>
</div>

<!-- New Restock Modal -->
<div class="modal-backdrop" id="restockModal">
  <div class="modal">
    <div class="modal-header">
      <h2><i class="bi bi-arrow-repeat"></i> New Restock Request</h2>
      <button class="modal-close" onclick="closeModal('restockModal')" aria-label="Close">&times;</button>
    </div>
    <form method="post" action="suppliers.php">
      <?= csrf_field() ?>
      <input type="hidden" name="add_restock" value="1">
      <input type="hidden" name="supplier_id" value="<?= (int)$viewSupplier['id'] ?>">
      <div class="form-group">
        <label>Product *</label>
        <select name="product_id" required>
          <option value="">Select product…</option>
          <?php foreach ($viewProducts as $p): ?>
            <option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?> (<?= e($p['category']) ?>)</option>
          <?php endforeach; ?>
          <?php if (!$viewProducts): ?>
            <?php foreach ($allProducts as $p): ?>
              <option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?></option>
            <?php endforeach; ?>
          <?php endif; ?>
        </select>
        <?php if (!$viewProducts): ?>
          <div class="form-hint">No products linked to this supplier — showing all active products.</div>
        <?php endif; ?>
      </div>
      <div class="form-group">
        <label>Quantity *</label>
        <input type="number" name="quantity" min="1" value="10" required>
      </div>
      <div class="form-group">
        <label>Notes</label>
        <textarea name="notes" rows="2" placeholder="Any special instructions…"></textarea>
      </div>
      <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:4px">
        <button type="button" class="btn secondary" onclick="closeModal('restockModal')">Cancel</button>
        <button class="btn"><i class="bi bi-send"></i> Submit Request</button>
      </div>
    </form>
  </div>
</div>

<?php elseif ($edit): ?>
<!-- ── Edit supplier form ────────────────────────────────── -->
<div class="staff-panel staff-form">
  <div class="toolbar">
    <h2><i class="bi bi-pencil"></i> Edit Supplier</h2>
    <a href="suppliers.php" class="btn secondary small">Cancel</a>
  </div>
  <?php include __DIR__ . '/suppliers_form.inc.php'; ?>
</div>

<?php else: ?>
<!-- ── Summary cards ─────────────────────────────────────── -->
<div class="staff-grid" style="grid-template-columns:repeat(3,1fr)">
  <div class="staff-card">
    <div class="card-icon"><i class="bi bi-truck"></i></div>
    <h3>Active Suppliers</h3>
    <strong><?= $totalSuppliers ?></strong>
    <span class="muted">In network</span>
  </div>
  <div class="staff-card yellow">
    <div class="card-icon"><i class="bi bi-hourglass-split"></i></div>
    <h3>Pending Restocks</h3>
    <strong><?= $pendingRestocks ?></strong>
    <span class="muted">Awaiting action</span>
  </div>
  <div class="staff-card blue">
    <div class="card-icon"><i class="bi bi-box-seam"></i></div>
    <h3>Orders In Progress</h3>
    <strong><?= $orderedRestocks ?></strong>
    <span class="muted">Ordered, not received</span>
  </div>
</div>

<!-- ── Add supplier form ─────────────────────────────────── -->
<div class="staff-panel staff-form">
  <div class="toolbar">
    <h2><i class="bi bi-plus-lg"></i> Add New Supplier</h2>
  </div>
  <?php include __DIR__ . '/suppliers_form.inc.php'; ?>
</div>

<!-- ── Suppliers list ────────────────────────────────────── -->
<div class="staff-panel">
  <div class="toolbar">
    <h2>All Suppliers <span class="muted" style="font-weight:400">(<?= count($rows) ?>)</span></h2>
    <div class="right">
      <form method="get" style="display:flex;gap:8px">
        <input name="q" value="<?= e($search) ?>" placeholder="Search name, contact, email…" style="width:240px">
        <button class="btn small">Search</button>
        <?php if ($search): ?><a href="suppliers.php" class="btn secondary small">Clear</a><?php endif; ?>
      </form>
    </div>
  </div>

  <div class="responsive-scroll">
    <table>
      <tr>
        <th>Supplier</th><th>Contact Person</th><th>Email</th><th>Phone</th>
        <th>Products</th><th>Pending Restocks</th><th>Status</th><th>Actions</th>
      </tr>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><b><?= e($r['name']) ?></b></td>
          <td><?= e($r['contact_name'] ?: '—') ?></td>
          <td><?= $r['email'] ? '<a href="mailto:' . e($r['email']) . '">' . e($r['email']) . '</a>' : '—' ?></td>
          <td><?= e($r['phone'] ?: '—') ?></td>
          <td><?= (int)$r['product_count'] ?></td>
          <td>
            <?php if ((int)$r['restock_count'] > 0): ?>
              <span class="status badge-pending"><?= (int)$r['restock_count'] ?> pending</span>
            <?php else: ?>
              <span class="muted">—</span>
            <?php endif; ?>
          </td>
          <td><span class="status <?= $r['status']==='Active'?'':'off' ?>"><?= e($r['status']) ?></span></td>
          <td style="white-space:nowrap">
            <a href="suppliers.php?view=<?= (int)$r['id'] ?>" class="btn secondary small"
               data-tooltip="View supplier details" data-tooltip-pos="bottom">View</a>
            <a href="suppliers.php?edit=<?= (int)$r['id'] ?>" class="btn secondary small"
               data-tooltip="Edit supplier" data-tooltip-pos="bottom"><i class="bi bi-pencil"></i></a>
            <a href="suppliers.php?toggle=<?= (int)$r['id'] ?>&csrf_token=<?= e(csrf_token()) ?>"
               class="btn secondary small"
               data-confirm="<?= $r['status']==='Active'?'Deactivate':'Activate' ?> <?= e($r['name']) ?>?"
               data-tooltip="<?= $r['status']==='Active' ? 'Deactivate' : 'Activate' ?>" data-tooltip-pos="bottom">
              <?= $r['status']==='Active' ? '<i class="bi bi-pause"></i>' : '<i class="bi bi-play"></i>' ?>
            </a>
            <a href="suppliers.php?delete=<?= (int)$r['id'] ?>&csrf_token=<?= e(csrf_token()) ?>"
               class="btn secondary small"
               data-confirm="Delete '<?= e($r['name']) ?>'? This will also remove all restock requests."
               data-tooltip="Delete supplier" data-tooltip-pos="bottom"><i class="bi bi-trash"></i></a>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?>
        <tr><td colspan="8" style="text-align:center;padding:30px;color:var(--muted)">No suppliers found</td></tr>
      <?php endif; ?>
    </table>
  </div>
</div>
<?php endif; ?>

<?php require 'footer.php'; ?>
