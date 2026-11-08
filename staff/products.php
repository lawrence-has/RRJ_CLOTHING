<?php
require_once __DIR__ . '/../config/config.php';
$title = 'Products';
$u = require_staff();

// ── Delete ───────────────────────────────────────────────
if (isset($_GET['delete'])) {
    csrf_verify();
    $id = (int)$_GET['delete'];
    // Check if product has order items before deleting
    $hasOrders = db()->prepare('SELECT COUNT(*) FROM order_items WHERE product_id=?');
    $hasOrders->execute([$id]);
    if ((int)$hasOrders->fetchColumn() > 0) {
        flash('error', 'Cannot delete — this product has existing order history. Deactivate it instead.');
    } else {
        $s = db()->prepare('SELECT image FROM products WHERE id=?');
        $s->execute([$id]);
        $row = $s->fetch();
        if ($row && $row['image'] && file_exists(UPLOAD_DIR . $row['image'])) {
            @unlink(UPLOAD_DIR . $row['image']);
        }
        db()->prepare('DELETE FROM products WHERE id=?')->execute([$id]);
        log_activity($u['id'], 'Deleted Product', 'Product ID ' . $id);
        flash('success', 'Product deleted.');
    }
    redirect('products.php');
}

// ── Toggle status ────────────────────────────────────────
if (isset($_GET['toggle'])) {
    csrf_verify();
    $id = (int)$_GET['toggle'];
    $s  = db()->prepare('SELECT status FROM products WHERE id=?');
    $s->execute([$id]);
    $cur = $s->fetchColumn();
    $new = $cur === 'Active' ? 'Inactive' : 'Active';
    db()->prepare('UPDATE products SET status=? WHERE id=?')->execute([$new, $id]);
    log_activity($u['id'], 'Product Status Changed', "ID {$id} → {$new}");
    flash('success', "Product set to {$new}.");
    redirect('products.php');
}

// ── Save (add/edit) ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id   = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name']        ?? '');
    $cat  = trim($_POST['category']    ?? '');
    $price= (float)($_POST['price']    ?? 0);
    $stock= (int)($_POST['stock']      ?? 0);
    $rl   = (int)($_POST['reorder_level'] ?? 5);
    $color= trim($_POST['color']       ?? '');
    $sizes= trim($_POST['sizes']       ?? 'S,M,L,XL');
    $desc = trim($_POST['description'] ?? '');
    $status = in_array($_POST['status'] ?? '', ['Active','Inactive']) ? $_POST['status'] : 'Active';

    if (!$name || !$cat || $price <= 0) {
        flash('error', 'Name, category, and a positive price are required.');
        redirect($id ? "products.php?edit={$id}" : 'products.php');
    }

    // Handle image upload
    $oldImage = '';
    if ($id) {
        $si = db()->prepare('SELECT image FROM products WHERE id=?');
        $si->execute([$id]);
        $oldImage = (string)($si->fetchColumn() ?: '');
    }
    $image = handle_product_image($_FILES['image'] ?? [], $oldImage);

    if ($id) {
        $s = db()->prepare('UPDATE products SET name=?,category=?,price=?,stock=?,reorder_level=?,color=?,sizes=?,description=?,image=?,status=? WHERE id=?');
        $s->execute([$name,$cat,$price,$stock,$rl,$color,$sizes,$desc,$image,$status,$id]);
        log_activity($u['id'], 'Updated Product', $name);
        flash('success', 'Product updated.');
    } else {
        $s = db()->prepare('INSERT INTO products(name,category,price,stock,reorder_level,color,sizes,description,image,status) VALUES(?,?,?,?,?,?,?,?,?,?)');
        $s->execute([$name,$cat,$price,$stock,$rl,$color,$sizes,$desc,$image,$status]);
        $newId = (int)db()->lastInsertId();
        if ($stock > 0) {
            db()->prepare('INSERT INTO stock_movements(product_id,user_id,type,quantity,note) VALUES(?,?,?,?,?)')
                ->execute([$newId, $u['id'], 'in', $stock, 'Initial stock']);
        }
        log_activity($u['id'], 'Added Product', $name);
        flash('success', 'Product added.');
    }
    redirect('products.php');
}

require 'header.php';

// ── Load edit data ───────────────────────────────────────
$edit = null;
if (isset($_GET['edit'])) {
    $s = db()->prepare('SELECT * FROM products WHERE id=?');
    $s->execute([(int)$_GET['edit']]);
    $edit = $s->fetch();
}

// ── Load products with search ────────────────────────────
$search = trim($_GET['q'] ?? '');
$catFilter = trim($_GET['cat'] ?? '');
$where  = ['1=1'];
$params = [];
if ($search)    { $where[] = 'name LIKE ?'; $params[] = "%{$search}%"; }
if ($catFilter) { $where[] = 'category=?';  $params[] = $catFilter; }
$sql   = 'SELECT * FROM products WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC';
$stmt  = db()->prepare($sql);
$stmt->execute($params);
$rows  = $stmt->fetchAll();

$cats  = ['Men','Women','Kids','Accessories'];
?>

<!-- Add / Edit form -->
<div class="staff-panel staff-form">
  <div class="toolbar">
    <h2><?= $edit ? '<i class="bi bi-pencil"></i> Edit Product' : '<i class="bi bi-plus-lg"></i> Add New Product' ?></h2>
    <?php if ($edit): ?>
      <a href="products.php" class="btn secondary small">Cancel Edit</a>
    <?php endif; ?>
  </div>

  <form method="post" action="products.php" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">

    <div class="form-grid">
      <div class="form-group">
        <label>Product Name *</label>
        <input name="name" required maxlength="160" value="<?= e($edit['name'] ?? '') ?>" placeholder="e.g. RRJ Basic Tee">
      </div>
      <div class="form-group">
        <label>Category *</label>
        <select name="category" required>
          <?php foreach ($cats as $c): ?>
            <option value="<?= e($c) ?>" <?= ($edit['category'] ?? '') === $c ? 'selected' : '' ?>><?= e($c) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Price (₱) *</label>
        <input type="number" step="0.01" min="0.01" name="price" required value="<?= e((string)($edit['price'] ?? '')) ?>">
      </div>
      <div class="form-group">
        <label>Stock Quantity</label>
        <input type="number" min="0" name="stock" value="<?= (int)($edit['stock'] ?? 0) ?>">
      </div>
      <div class="form-group">
        <label>Reorder Level</label>
        <input type="number" min="0" name="reorder_level" value="<?= (int)($edit['reorder_level'] ?? 5) ?>">
        <div class="form-hint">Alert when stock drops to this level</div>
      </div>
      <div class="form-group">
        <label>Color</label>
        <input name="color" value="<?= e($edit['color'] ?? '') ?>" placeholder="e.g. Black">
      </div>
      <div class="form-group">
        <label>Sizes (comma-separated)</label>
        <input name="sizes" value="<?= e($edit['sizes'] ?? 'S,M,L,XL') ?>" placeholder="S,M,L,XL">
      </div>
      <div class="form-group">
        <label>Status</label>
        <select name="status">
          <option value="Active"   <?= ($edit['status'] ?? 'Active') === 'Active'   ? 'selected' : '' ?>>Active</option>
          <option value="Inactive" <?= ($edit['status'] ?? '')        === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
        </select>
      </div>
    </div>

    <div class="form-group">
      <label>Description</label>
      <textarea name="description" rows="3" placeholder="Describe the product…"><?= e($edit['description'] ?? '') ?></textarea>
    </div>

    <div class="form-group">
      <label>Product Image</label>
      <div style="display:flex;align-items:center;gap:14px">
        <div class="img-preview" id="imgPreview">
          <?php if (!empty($edit['image']) && file_exists(UPLOAD_DIR . $edit['image'])): ?>
            <img src="../<?= UPLOAD_URL . e($edit['image']) ?>" alt="current">
          <?php else: ?>
            <i class="bi bi-tag" style="font-size:30px;color:#ccc"></i>
          <?php endif; ?>
        </div>
        <div>
          <input type="file" name="image" accept="image/*" data-preview="imgPreview" style="width:auto">
          <div class="form-hint">JPEG, PNG, WebP · max 5 MB</div>
        </div>
      </div>
    </div>

    <button class="btn"><?= $edit ? 'Save Changes' : 'Add Product' ?></button>
    <?php if ($edit): ?>
      <a href="products.php" class="btn secondary" style="margin-left:8px">Cancel</a>
    <?php endif; ?>
  </form>
</div>

<!-- Products list -->
<div class="staff-panel">
  <div class="toolbar">
    <h2>All Products</h2>
    <div class="right">
      <form method="get" style="display:flex;gap:8px;align-items:center">
        <input type="hidden" name="" value="">
        <input name="q" value="<?= e($search) ?>" placeholder="Search…" style="width:180px">
        <select name="cat">
          <option value="">All Categories</option>
          <?php foreach ($cats as $c): ?>
            <option value="<?= e($c) ?>" <?= $catFilter===$c?'selected':'' ?>><?= e($c) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn small">Filter</button>
        <?php if ($search||$catFilter): ?>
          <a href="products.php" class="btn secondary small">Clear</a>
        <?php endif; ?>
      </form>
    </div>
  </div>
  <div class="responsive-scroll">
    <table>
      <tr>
        <th>Image</th><th>Product</th><th>Category</th><th>Price</th>
        <th>Stock</th><th>Sizes</th><th>Status</th><th>Actions</th>
      </tr>
      <?php foreach ($rows as $r):
          $isLow  = (int)$r['stock'] <= (int)$r['reorder_level'] && $r['stock'] > 0;
          $isOut  = (int)$r['stock'] <= 0;
          $active = $r['status'] === 'Active';
      ?>
        <tr>
          <td>
            <div style="width:44px;height:44px;border-radius:6px;background:#f7f0ee;display:flex;align-items:center;justify-content:center;font-size:20px;overflow:hidden">
              <?php if (!empty($r['image']) && file_exists(UPLOAD_DIR . $r['image'])): ?>
                <img src="../<?= UPLOAD_URL . e($r['image']) ?>" style="width:100%;height:100%;object-fit:cover">
              <?php else: ?><i class="bi bi-tag" style="font-size:20px;color:#ccc"></i><?php endif; ?>
            </div>
          </td>
          <td>
            <b><?= e($r['name']) ?></b>
            <div class="muted"><?= e($r['color']) ?></div>
          </td>
          <td><?= e($r['category']) ?></td>
          <td><b><?= money((float)$r['price']) ?></b></td>
          <td>
            <?= (int)$r['stock'] ?>
            <?php if ($isOut):  ?><br><span class="status off">Out</span>
            <?php elseif ($isLow): ?><br><span class="status low">Low</span>
            <?php endif; ?>
          </td>
          <td class="muted"><?= e($r['sizes']) ?></td>
          <td>
            <span class="status <?= $active ? '' : 'off' ?>">
              <?= $active ? 'Active' : 'Inactive' ?>
            </span>
          </td>
          <td style="white-space:nowrap">
            <a href="products.php?edit=<?= (int)$r['id'] ?>" class="btn secondary small" title="Edit" data-tooltip="Edit product" data-tooltip-pos="bottom"><i class="bi bi-pencil"></i> Edit</a>
            <a href="products.php?toggle=<?= (int)$r['id'] ?>&csrf_token=<?= e(csrf_token()) ?>"
               class="btn secondary small"
               data-confirm="<?= $active ? 'Deactivate' : 'Activate' ?> this product?"
               data-tooltip="<?= $active ? 'Deactivate product' : 'Activate product' ?>" data-tooltip-pos="bottom"
               title="<?= $active ? 'Deactivate' : 'Activate' ?>">
              <?= $active ? '<i class="bi bi-pause"></i>' : '<i class="bi bi-play"></i>' ?>
            </a>
            <a href="products.php?delete=<?= (int)$r['id'] ?>&csrf_token=<?= e(csrf_token()) ?>"
               class="btn secondary small"
               data-confirm="Permanently delete '<?= e($r['name']) ?>'? This cannot be undone."
               data-tooltip="Delete product" data-tooltip-pos="bottom"
               title="Delete"><i class="bi bi-trash"></i></a>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?>
        <tr><td colspan="8" style="text-align:center;padding:30px;color:var(--muted)">No products found</td></tr>
      <?php endif; ?>
    </table>
  </div>
  <div class="muted" style="margin-top:10px;font-size:12px"><?= count($rows) ?> product<?= count($rows)!==1?'s':'' ?></div>
</div>

<?php require 'footer.php'; ?>
