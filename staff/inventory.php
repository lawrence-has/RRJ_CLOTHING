<?php
require_once __DIR__ . '/../config/config.php';
$title = 'Inventory';
$u = require_staff();

// ── Adjust stock ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id    = (int)$_POST['id'];
    $delta = (int)$_POST['delta'];
    $note  = trim($_POST['note'] ?? '');

    $s = db()->prepare('SELECT name, stock FROM products WHERE id=?');
    $s->execute([$id]);
    $prod = $s->fetch();

    if ($prod) {
        $newStock = max(0, (int)$prod['stock'] + $delta);
        db()->prepare('UPDATE products SET stock=? WHERE id=?')->execute([$newStock, $id]);

        $type = $delta >= 0 ? 'in' : 'out';
        db()->prepare('INSERT INTO stock_movements(product_id,user_id,type,quantity,note) VALUES(?,?,?,?,?)')
            ->execute([$id, $u['id'], $type, abs($delta), $note ?: ($type === 'in' ? 'Stock received' : 'Stock adjustment')]);

        log_activity($u['id'], $delta >= 0 ? 'Stock In' : 'Stock Out',
            $prod['name'] . ' · ' . ($delta >= 0 ? '+' : '') . $delta . ' → ' . $newStock);
        flash('success', $prod['name'] . ': stock updated to ' . $newStock . '.');
    }
    redirect('inventory.php');
}

require 'header.php';

$filter   = trim($_GET['filter'] ?? '');  // 'low' or ''
$search   = trim($_GET['q']     ?? '');
$where    = ['1=1'];
$params   = [];
if ($search) { $where[] = 'name LIKE ?'; $params[] = "%{$search}%"; }
if ($filter === 'low') { $where[] = 'stock<=reorder_level'; }

$sql  = 'SELECT * FROM products WHERE ' . implode(' AND ', $where) . ' ORDER BY stock ASC';
$stmt = db()->prepare($sql); $stmt->execute($params);
$rows = $stmt->fetchAll();

$totalProducts = (int)db()->query('SELECT COUNT(*) FROM products')->fetchColumn();
$lowCount      = (int)db()->query('SELECT COUNT(*) FROM products WHERE stock<=reorder_level AND status="Active"')->fetchColumn();
$outCount      = (int)db()->query('SELECT COUNT(*) FROM products WHERE stock=0')->fetchColumn();

// Recent stock movements
$movements = db()->query('
    SELECT sm.*, p.name AS product_name, u.name AS staff_name
    FROM stock_movements sm
    JOIN products p ON p.id=sm.product_id
    LEFT JOIN users u ON u.id=sm.user_id
    ORDER BY sm.id DESC LIMIT 20
')->fetchAll();
?>

<!-- Summary cards -->
<div class="staff-grid" style="grid-template-columns:repeat(3,1fr)">
  <div class="staff-card">
    <div class="card-icon"><i class="bi bi-box-seam"></i></div>
    <h3>Total Products</h3>
    <strong><?= $totalProducts ?></strong>
    <span class="muted">In catalog</span>
  </div>
  <div class="staff-card yellow">
    <div class="card-icon"><i class="bi bi-exclamation-triangle"></i></div>
    <h3>Low Stock</h3>
    <strong><?= $lowCount ?></strong>
    <span class="muted"><a href="inventory.php?filter=low" style="color:var(--red)">View all →</a></span>
  </div>
  <div class="staff-card" style="--accent:#b71c1c">
    <div class="card-icon"><i class="bi bi-slash-circle"></i></div>
    <h3>Out of Stock</h3>
    <strong><?= $outCount ?></strong>
    <span class="muted">Needs restock</span>
  </div>
</div>

<!-- Inventory table -->
<div class="staff-panel">
  <div class="toolbar">
    <h2>Stock Overview <?= $filter==='low' ? '— Low Stock Only' : '' ?></h2>
    <div class="right">
      <form method="get" style="display:flex;gap:8px">
        <input name="q" value="<?= e($search) ?>" placeholder="Search product…" style="width:180px">
        <?php if ($filter !== 'low'): ?>
          <a href="inventory.php?filter=low" class="btn secondary small" data-tooltip="Show only low stock items" data-tooltip-pos="bottom"><i class="bi bi-exclamation-triangle"></i> Low Stock</a>
        <?php else: ?>
          <a href="inventory.php" class="btn secondary small">Show All</a>
        <?php endif; ?>
        <button class="btn small">Search</button>
      </form>
      <a href="products.php" class="btn secondary small">Manage Products</a>
    </div>
  </div>

  <div class="responsive-scroll">
    <table>
      <tr><th>Product</th><th>Category</th><th>Current Stock</th><th>Reorder Level</th><th>Status</th><th>Adjust Stock</th></tr>
      <?php foreach ($rows as $r):
          $isLow = (int)$r['stock'] <= (int)$r['reorder_level'] && $r['stock'] > 0;
          $isOut = (int)$r['stock'] <= 0;
      ?>
        <tr>
          <td>
            <b><?= e($r['name']) ?></b>
            <div class="muted"><?= e($r['color']) ?></div>
          </td>
          <td><?= e($r['category']) ?></td>
          <td>
            <b style="font-size:15px;<?= $isOut?'color:var(--red)':($isLow?'color:#e65100':'') ?>">
              <?= (int)$r['stock'] ?>
            </b>
          </td>
          <td><?= (int)$r['reorder_level'] ?></td>
          <td>
            <?php if ($isOut):  ?><span class="status off">Out of Stock</span>
            <?php elseif ($isLow): ?><span class="status low">Low Stock</span>
            <?php else: ?><span class="status">In Stock</span>
            <?php endif; ?>
          </td>
          <td>
            <form method="post" action="inventory.php" style="display:flex;gap:6px;align-items:center">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <input type="number" name="delta" value="1" style="width:72px" title="Use negative to subtract">
              <input type="text"   name="note"  placeholder="Note (optional)" style="width:160px">
              <button class="btn small">Apply</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?>
        <tr><td colspan="6" style="text-align:center;padding:30px;color:var(--muted)">No products match</td></tr>
      <?php endif; ?>
    </table>
  </div>
  <div class="muted" style="margin-top:8px;font-size:12px">
    Enter a positive number to add stock, negative to subtract. E.g. <code>-3</code> removes 3 units.
  </div>
</div>

<!-- Stock movement history -->
<div class="staff-panel">
  <div class="toolbar">
    <h2>Recent Stock Movements</h2>
    <a href="activity.php" class="btn secondary small">Full Activity Log</a>
  </div>
  <div class="responsive-scroll">
    <table>
      <tr><th>Date</th><th>Product</th><th>Type</th><th>Qty</th><th>Note</th><th>Staff</th></tr>
      <?php foreach ($movements as $m):
          $typeClass = match($m['type']) {
              'in'         => 'style="color:var(--green)"',
              'out'        => 'style="color:var(--red)"',
              'sale'       => 'style="color:var(--blue)"',
              default      => '',
          };
          $typeIcon = match($m['type']) { 'in'=>'<i class="bi bi-arrow-up"></i>', 'out'=>'<i class="bi bi-arrow-down"></i>', 'sale'=>'<i class="bi bi-cart3"></i>', default=>'~' };
      ?>
        <tr>
          <td class="muted"><?= date('M d, g:i A', strtotime($m['created_at'])) ?></td>
          <td><?= e($m['product_name']) ?></td>
          <td <?= $typeIcon !== '~' ? $typeClass : '' ?>><b><?= $typeIcon ?> <?= ucfirst($m['type']) ?></b></td>
          <td><?= abs((int)$m['quantity']) ?></td>
          <td class="muted"><?= e($m['note']) ?></td>
          <td class="muted"><?= e($m['staff_name'] ?? 'System') ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$movements): ?>
        <tr><td colspan="6" style="text-align:center;padding:20px;color:var(--muted)">No movements recorded yet</td></tr>
      <?php endif; ?>
    </table>
  </div>
</div>

<?php require 'footer.php'; ?>
