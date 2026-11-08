<?php
require_once __DIR__ . '/../config/config.php';
$title = 'Sales';
$u = require_staff();

// ── Record walk-in sale ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_sale'])) {
    csrf_verify();

    $customer_name    = trim($_POST['customer_name']    ?? 'Walk-in Customer');
    $payment_method   = trim($_POST['payment_method']   ?? 'Cash');
    $items_product    = $_POST['item_product']  ?? [];
    $items_qty        = $_POST['item_qty']       ?? [];
    $items_size       = $_POST['item_size']      ?? [];
    $items_price      = $_POST['item_price']     ?? [];

    // Validate at least one item
    $lineItems = [];
    foreach ($items_product as $i => $pid) {
        $pid  = (int)$pid;
        $qty  = max(1, (int)($items_qty[$i]  ?? 1));
        $size = trim($items_size[$i]  ?? '');
        $price= (float)($items_price[$i] ?? 0);
        if ($pid > 0 && $price > 0) {
            $lineItems[] = compact('pid','qty','size','price');
        }
    }

    if (!$lineItems) {
        flash('error', 'Add at least one product to record a sale.');
        redirect('sales.php?tab=new');
    }

    // Check stock availability
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Get or create a walk-in user record
        $wu = $pdo->prepare('SELECT id FROM users WHERE email=? LIMIT 1');
        $wu->execute(['walkin@rrjclothing.com']);
        $walkInUser = $wu->fetchColumn();
        if (!$walkInUser) {
            $pdo->prepare('INSERT INTO users(name,email,password,role,status) VALUES(?,?,?,?,?)')
                ->execute(['Walk-in Customer','walkin@rrjclothing.com',password_hash(bin2hex(random_bytes(8)),PASSWORD_DEFAULT),'customer','Active']);
            $walkInUser = (int)$pdo->lastInsertId();
        }
        $walkInUser = (int)$walkInUser;

        // Verify stock and fetch product details
        $subtotal = 0.0;
        $enriched = [];
        foreach ($lineItems as $line) {
            $ps = $pdo->prepare('SELECT id,name,stock,color FROM products WHERE id=? AND status="Active" FOR UPDATE');
            $ps->execute([$line['pid']]);
            $prod = $ps->fetch();
            if (!$prod) throw new DomainException("Product #{$line['pid']} not found or inactive.");
            if ((int)$prod['stock'] < $line['qty']) {
                throw new DomainException("Not enough stock for \"{$prod['name']}\" (available: {$prod['stock']}).");
            }
            $subtotal += $line['price'] * $line['qty'];
            $enriched[] = array_merge($line, ['name' => $prod['name'], 'color' => $prod['color']]);
        }

        $total    = $subtotal; // no shipping for walk-in
        $orderNo  = 'WLK-' . strtoupper(substr(md5(uniqid('', true)), 0, 8));

        // Insert order
        $pdo->prepare('INSERT INTO orders(order_no,user_id,subtotal,shipping,total,payment_method,shipping_method,address,status,payment_status,notes)
                       VALUES(?,?,?,0,?,?,?,?,?,?,?)')
            ->execute([
                $orderNo, $walkInUser, $subtotal, $total,
                $payment_method, 'Walk-in',
                $customer_name,     // address field repurposed for customer name
                'Delivered',        // walk-in = immediate delivery
                'Paid',
                'Walk-in sale recorded by ' . $u['name'],
            ]);
        $orderId = (int)$pdo->lastInsertId();

        // Insert items, deduct stock, log movements
        $ii = $pdo->prepare('INSERT INTO order_items(order_id,product_id,quantity,price,size,color) VALUES(?,?,?,?,?,?)');
        $sm = $pdo->prepare('INSERT INTO stock_movements(product_id,user_id,type,quantity,note) VALUES(?,?,?,?,?)');
        $sd = $pdo->prepare('UPDATE products SET stock=stock-? WHERE id=?');
        foreach ($enriched as $line) {
            $ii->execute([$orderId, $line['pid'], $line['qty'], $line['price'], $line['size'], $line['color']]);
            $sd->execute([$line['qty'], $line['pid']]);
            $sm->execute([$line['pid'], $u['id'], 'sale', $line['qty'], "Walk-in sale {$orderNo}"]);
        }

        $pdo->commit();
        log_activity($u['id'], 'Walk-in Sale', "Order {$orderNo} · " . money($total));
        flash('success', "Walk-in sale recorded — {$orderNo} · " . money($total));
        redirect('sales.php');

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error', $e instanceof DomainException ? $e->getMessage() : 'Could not save sale. Please try again.');
        redirect('sales.php?tab=new');
    }
}

// ── Update payment status ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_payment_status'])) {
    csrf_verify();
    $oid = (int)($_POST['order_id'] ?? 0);
    $paymentStatus = trim($_POST['payment_status'] ?? '');
    if (!in_array($paymentStatus, ['Unpaid', 'Paid', 'Refunded'], true)) {
        flash('error', 'Invalid payment status.');
        redirect('sales.php?view=' . $oid);
    }
    $q = db()->prepare('SELECT order_no,payment_status FROM orders WHERE id=?');
    $q->execute([$oid]);
    $paymentOrder = $q->fetch();
    if (!$paymentOrder) {
        flash('error', 'Order not found.');
    } elseif ($paymentOrder['payment_status'] === 'Refunded' && $paymentStatus !== 'Refunded') {
        flash('error', 'A refunded order cannot be changed back to another payment status.');
    } else {
        db()->prepare('UPDATE orders SET payment_status=? WHERE id=?')->execute([$paymentStatus, $oid]);
        log_activity((int)$u['id'], 'Updated Payment Status', "Order {$paymentOrder['order_no']} → {$paymentStatus}");
        flash('success', 'Payment status updated.');
    }
    redirect('sales.php?view=' . $oid);
}

// ── Update order status ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    csrf_verify();
    $oid    = (int)$_POST['order_id'];
    $status = trim($_POST['status'] ?? '');
    $transitions = [
        'Order Placed' => ['Processing', 'Cancelled'],
        'Processing'   => ['Shipped', 'Cancelled'],
        'Shipped'      => ['Delivered'],
        'Delivered'    => [],
        'Cancelled'    => [],
    ];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $orderQuery = $pdo->prepare('SELECT order_no,status FROM orders WHERE id=? FOR UPDATE');
        $orderQuery->execute([$oid]);
        $order = $orderQuery->fetch();
        if (!$order || !in_array($status, $transitions[$order['status']] ?? [], true)) {
            throw new DomainException('That order status change is not allowed.');
        }
        if ($status === 'Cancelled') {
            $itemsQuery = $pdo->prepare('SELECT product_id,quantity FROM order_items WHERE order_id=?');
            $itemsQuery->execute([$oid]);
            $restoreStock = $pdo->prepare('UPDATE products SET stock=stock+? WHERE id=?');
            $movement     = $pdo->prepare('INSERT INTO stock_movements(product_id,user_id,type,quantity,note) VALUES(?,?,?,?,?)');
            foreach ($itemsQuery->fetchAll() as $item) {
                $quantity = (int)$item['quantity'];
                $restoreStock->execute([$quantity, $item['product_id']]);
                $movement->execute([$item['product_id'], $u['id'], 'in', $quantity, "Cancelled order {$order['order_no']} - stock restored"]);
            }
        }
        $pdo->prepare('UPDATE orders SET status=? WHERE id=?')->execute([$status, $oid]);
        $pdo->commit();
        log_activity($u['id'], 'Updated Order Status', "Order {$order['order_no']} → {$status}");
        flash('success', $status === 'Cancelled'
            ? "Order {$order['order_no']} cancelled and stock restored."
            : "Order status updated to {$status}.");
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error', $e instanceof DomainException ? $e->getMessage() : 'Unable to update this order. Please try again.');
    }
    redirect('sales.php?view=' . $oid);
}

require 'header.php';

// Active tab
$activeTab = $_GET['tab'] ?? 'list';

// ── All products for sale form ───────────────────────────
$saleProducts = db()->query('SELECT id,name,category,price,stock,sizes,color FROM products WHERE status="Active" AND stock>0 ORDER BY name')->fetchAll();

// ── Filters ──────────────────────────────────────────────
$search  = trim($_GET['q']      ?? '');
$statusF = trim($_GET['status'] ?? '');
$dateFrom= trim($_GET['from']   ?? '');
$dateTo  = trim($_GET['to']     ?? '');
$perPage = 15;
$curPage = max(1, (int)($_GET['p'] ?? 1));

$where  = ['1=1'];
$params = [];
if ($search)   { $where[] = '(o.order_no LIKE ? OR u.name LIKE ? OR o.address LIKE ?)'; $params = array_merge($params, ["%{$search}%","%{$search}%","%{$search}%"]); }
if ($statusF)  { $where[] = 'o.status=?';            $params[] = $statusF; }
if ($dateFrom) { $where[] = 'DATE(o.created_at)>=?'; $params[] = $dateFrom; }
if ($dateTo)   { $where[] = 'DATE(o.created_at)<=?'; $params[] = $dateTo; }

$cntSql = 'SELECT COUNT(*) FROM orders o JOIN users u ON u.id=o.user_id WHERE ' . implode(' AND ', $where);
$cs = db()->prepare($cntSql); $cs->execute($params);
$total      = (int)$cs->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));
$curPage    = min($curPage, $totalPages);
$offset     = ($curPage - 1) * $perPage;

$sql = 'SELECT o.*, u.name AS customer, u.email AS customer_email
        FROM orders o JOIN users u ON u.id=o.user_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY o.id DESC LIMIT ? OFFSET ?';
$stmt = db()->prepare($sql);
foreach ($params as $i => $value) { $stmt->bindValue($i + 1, $value); }
$stmt->bindValue(count($params) + 1, (int)$perPage, PDO::PARAM_INT);
$stmt->bindValue(count($params) + 2, (int)$offset,  PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

// View single order
$viewOrder = null;
$viewItems = [];
if (isset($_GET['view'])) {
    $vs = db()->prepare('SELECT o.*,u.name AS customer,u.email AS customer_email,u.phone AS customer_phone FROM orders o JOIN users u ON u.id=o.user_id WHERE o.id=?');
    $vs->execute([(int)$_GET['view']]);
    $viewOrder = $vs->fetch();
    if ($viewOrder) {
        $vi = db()->prepare('SELECT oi.*,p.name AS product_name FROM order_items oi JOIN products p ON p.id=oi.product_id WHERE oi.order_id=?');
        $vi->execute([$viewOrder['id']]);
        $viewItems = $vi->fetchAll();
    }
}

$statusBadge = [
    'Order Placed' => 'badge-pending',
    'Processing'   => 'badge-processing',
    'Shipped'      => 'badge-shipped',
    'Delivered'    => 'badge-delivered',
    'Cancelled'    => 'badge-cancelled',
];
$allStatuses = ['Order Placed','Processing','Shipped','Delivered','Cancelled'];
$statusTransitions = [
    'Order Placed' => ['Processing', 'Cancelled'],
    'Processing'   => ['Shipped', 'Cancelled'],
    'Shipped'      => ['Delivered'],
    'Delivered'    => [],
    'Cancelled'    => [],
];
?>

<?php if ($viewOrder): ?>
<!-- ── Order detail view ─────────────────────────────────── -->
<div class="staff-panel">
  <div class="toolbar">
    <div>
      <h2><?= e($viewOrder['order_no']) ?></h2>
      <span class="muted">Placed <?= date('M d, Y g:i A', strtotime($viewOrder['created_at'])) ?>
        <?php if ($viewOrder['shipping_method'] === 'Walk-in'): ?>
          &nbsp;<span class="status badge-delivered" style="font-size:10px">Walk-in</span>
        <?php endif; ?>
      </span>
    </div>
    <div class="right">
      <a href="sales.php" class="btn secondary small"><i class="bi bi-arrow-left"></i> Back</a>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px" class="form-grid">
    <div>
      <div class="muted" style="margin-bottom:6px">CUSTOMER</div>
      <b><?= e($viewOrder['customer']) ?></b><br>
      <span class="muted"><?= e($viewOrder['customer_email']) ?></span><br>
      <span class="muted"><?= e($viewOrder['customer_phone'] ?? '') ?></span>
    </div>
    <div>
      <div class="muted" style="margin-bottom:6px"><?= $viewOrder['shipping_method']==='Walk-in' ? 'CUSTOMER NAME' : 'SHIPPING ADDRESS' ?></div>
      <?= nl2br(e($viewOrder['address'])) ?>
    </div>
    <div>
      <div class="muted" style="margin-bottom:6px">PAYMENT METHOD</div>
      <?= e($viewOrder['payment_method']) ?> · <?= e($viewOrder['shipping_method']) ?>
    </div>
    <div>
      <div class="muted" style="margin-bottom:6px">PAYMENT STATUS</div>
      <b><?= e($viewOrder['payment_status'] ?? 'Unpaid') ?></b>
      <?php if ($viewOrder['shipping_method'] !== 'Walk-in'): ?>
      <form method="post" action="sales.php" style="display:flex;gap:8px;align-items:center;margin-top:8px;flex-wrap:wrap">
        <?= csrf_field() ?>
        <input type="hidden" name="order_id" value="<?= (int)$viewOrder['id'] ?>">
        <select name="payment_status" aria-label="Payment status">
          <?php foreach (['Unpaid','Paid','Refunded'] as $ps): ?>
            <option value="<?= e($ps) ?>" <?= ($viewOrder['payment_status'] ?? 'Unpaid') === $ps ? 'selected' : '' ?>><?= e($ps) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn small" type="submit" name="update_payment_status" value="1">Save</button>
      </form>
      <?php endif; ?>
    </div>
    <div>
      <div class="muted" style="margin-bottom:6px">ORDER STATUS</div>
      <span class="status <?= $statusBadge[$viewOrder['status']] ?? '' ?>"><?= e($viewOrder['status']) ?></span>
    </div>
  </div>

  <h3 style="margin-bottom:12px">Items</h3>
  <div class="responsive-scroll">
    <table>
      <tr><th>Product</th><th>Size</th><th>Color</th><th>Qty</th><th>Unit Price</th><th>Total</th></tr>
      <?php foreach ($viewItems as $item): ?>
        <tr>
          <td><?= e($item['product_name']) ?></td>
          <td><?= e($item['size'] ?: '—') ?></td>
          <td><?= e($item['color'] ?: '—') ?></td>
          <td><?= (int)$item['quantity'] ?></td>
          <td><?= money((float)$item['price']) ?></td>
          <td><?= money((float)$item['price'] * (int)$item['quantity']) ?></td>
        </tr>
      <?php endforeach; ?>
      <tr style="background:#fafafa">
        <td colspan="5" style="text-align:right"><b>Subtotal</b></td>
        <td><b><?= money((float)$viewOrder['subtotal']) ?></b></td>
      </tr>
      <?php if ((float)$viewOrder['shipping'] > 0): ?>
      <tr style="background:#fafafa">
        <td colspan="5" style="text-align:right"><b>Shipping</b></td>
        <td><b><?= money((float)$viewOrder['shipping']) ?></b></td>
      </tr>
      <?php endif; ?>
      <tr style="background:#fafafa">
        <td colspan="5" style="text-align:right"><b>Total</b></td>
        <td><b style="font-size:16px;color:var(--red)"><?= money((float)$viewOrder['total']) ?></b></td>
      </tr>
    </table>
  </div>

  <?php $nextStatuses = $statusTransitions[$viewOrder['status']] ?? []; ?>
  <?php if ($nextStatuses): ?>
  <div style="margin-top:20px;padding-top:16px;border-top:1px solid var(--line)">
    <h3 style="margin-bottom:12px">Update Order Status</h3>
    <form method="post" action="sales.php" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
      <?= csrf_field() ?>
      <input type="hidden" name="update_status" value="1">
      <input type="hidden" name="order_id" value="<?= (int)$viewOrder['id'] ?>">
      <select name="status" style="width:220px" required>
        <?php foreach ($nextStatuses as $st): ?>
          <option value="<?= e($st) ?>"><?= e($st) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn">Update Status</button>
    </form>
  </div>
  <?php else: ?>
    <p class="muted" style="margin-top:16px;padding-top:16px;border-top:1px solid var(--line)">This order is in a final status and cannot be changed.</p>
  <?php endif; ?>
</div>

<?php else: ?>
<!-- ── Tabs ──────────────────────────────────────────────── -->
<div class="tab-wrapper">
  <div class="tabs">
    <button class="tab-btn <?= $activeTab==='list'?'active':'' ?>" data-tab="list">
      <i class="bi bi-list-ul"></i> All Sales
    </button>
    <button class="tab-btn <?= $activeTab==='new'?'active':'' ?>" data-tab="new">
      <i class="bi bi-plus-lg"></i> New Walk-in Sale
    </button>
  </div>

  <!-- ── All Sales tab ───────────────────────────────────── -->
  <div class="tab-content <?= $activeTab==='list'?'active':'' ?>" data-tab="list">
    <div class="staff-panel" style="margin-top:0">
      <div class="toolbar">
        <h2>All Sales <span class="muted" style="font-weight:400;font-size:13px">(<?= $total ?>)</span></h2>
      </div>

      <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;align-items:flex-end">
        <input type="hidden" name="tab" value="list">
        <div>
          <div class="muted" style="font-size:11px;margin-bottom:3px">Search</div>
          <input name="q" value="<?= e($search) ?>" placeholder="Order # or customer…" style="width:200px">
        </div>
        <div>
          <div class="muted" style="font-size:11px;margin-bottom:3px">Status</div>
          <select name="status" style="width:160px">
            <option value="">All Statuses</option>
            <?php foreach ($allStatuses as $st): ?>
              <option value="<?= e($st) ?>" <?= $statusF===$st?'selected':'' ?>><?= e($st) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <div class="muted" style="font-size:11px;margin-bottom:3px">From</div>
          <input type="date" name="from" value="<?= e($dateFrom) ?>" style="width:150px">
        </div>
        <div>
          <div class="muted" style="font-size:11px;margin-bottom:3px">To</div>
          <input type="date" name="to" value="<?= e($dateTo) ?>" style="width:150px">
        </div>
        <button class="btn small">Filter</button>
        <?php if ($search||$statusF||$dateFrom||$dateTo): ?>
          <a href="sales.php" class="btn secondary small">Clear</a>
        <?php endif; ?>
      </form>

      <div class="responsive-scroll">
        <table>
          <tr><th>Invoice</th><th>Customer</th><th>Type</th><th>Date</th><th>Total</th><th>Payment</th><th>Pay. Status</th><th>Status</th><th></th></tr>
          <?php foreach ($rows as $r):
              $sc = $statusBadge[$r['status']] ?? '';
              $isWalkin = $r['shipping_method'] === 'Walk-in';
          ?>
            <tr>
              <td><b><?= e($r['order_no']) ?></b></td>
              <td>
                <?= $isWalkin ? e($r['address']) : e($r['customer']) ?>
                <?php if (!$isWalkin): ?><div class="muted" style="font-size:11px"><?= e($r['customer_email']) ?></div><?php endif; ?>
              </td>
              <td>
                <?php if ($isWalkin): ?>
                  <span class="status badge-delivered" style="font-size:10px">Walk-in</span>
                <?php else: ?>
                  <span class="status badge-pending" style="font-size:10px">Online</span>
                <?php endif; ?>
              </td>
              <td><?= date('M d, Y', strtotime($r['created_at'])) ?></td>
              <td><b><?= money((float)$r['total']) ?></b></td>
              <td><?= e($r['payment_method']) ?></td>
              <td><?= e($r['payment_status'] ?? 'Unpaid') ?></td>
              <td><span class="status <?= $sc ?>"><?= e($r['status']) ?></span></td>
              <td><a href="sales.php?view=<?= (int)$r['id'] ?>" class="btn secondary small">View</a></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$rows): ?>
            <tr><td colspan="9" style="text-align:center;padding:30px;color:var(--muted)">No sales found</td></tr>
          <?php endif; ?>
        </table>
      </div>

      <?php
      $pBase = 'sales.php?tab=list&q=' . urlencode($search) . '&status=' . urlencode($statusF) . '&from=' . urlencode($dateFrom) . '&to=' . urlencode($dateTo);
      echo paginationStaff($curPage, $totalPages, $pBase);
      ?>
    </div>
  </div>

  <!-- ── New Walk-in Sale tab ─────────────────────────────── -->
  <div class="tab-content <?= $activeTab==='new'?'active':'' ?>" data-tab="new">
    <div class="staff-panel" style="margin-top:0">
      <h2 style="margin-bottom:20px"><i class="bi bi-cart-plus"></i> Record Walk-in Sale</h2>

      <?php if (!$saleProducts): ?>
        <div class="notice">No active products with stock available. <a href="inventory.php">Check inventory</a>.</div>
      <?php else: ?>
      <form method="post" action="sales.php" id="saleForm">
        <?= csrf_field() ?>
        <input type="hidden" name="add_sale" value="1">

        <div class="form-grid" style="max-width:600px">
          <div class="form-group">
            <label>Customer Name</label>
            <input name="customer_name" placeholder="Walk-in Customer" maxlength="120">
            <div class="form-hint">Optional — leave blank for anonymous walk-in</div>
          </div>
          <div class="form-group">
            <label>Payment Method *</label>
            <select name="payment_method">
              <option value="Cash">Cash</option>
              <option value="GCash">GCash</option>
              <option value="Maya">Maya</option>
              <option value="Card">Card</option>
              <option value="Bank Transfer">Bank Transfer</option>
            </select>
          </div>
        </div>

        <!-- Line items -->
        <div style="margin-bottom:12px">
          <label style="font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#444">
            Items *
          </label>
        </div>

        <div id="saleItems">
          <!-- rows injected by JS -->
        </div>

        <button type="button" class="btn secondary small" id="addItemBtn" style="margin-bottom:20px">
          <i class="bi bi-plus-lg"></i> Add Item
        </button>

        <!-- Running total -->
        <div style="max-width:340px;margin-left:auto;background:#fafafa;border:1px solid var(--line);border-radius:8px;padding:16px">
          <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:8px">
            <span class="muted">Subtotal</span>
            <span id="saleSubtotal">₱0.00</span>
          </div>
          <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:4px">
            <span class="muted">Shipping</span>
            <span>₱0.00</span>
          </div>
          <hr style="border:none;border-top:1px solid var(--line);margin:10px 0">
          <div style="display:flex;justify-content:space-between;font-weight:800;font-size:17px">
            <span>Total</span>
            <span id="saleTotal" style="color:var(--red)">₱0.00</span>
          </div>
        </div>

        <div style="margin-top:20px;display:flex;gap:10px">
          <button class="btn" id="submitSaleBtn">
            <i class="bi bi-check-lg"></i> Record Sale
          </button>
          <button type="reset" class="btn secondary" onclick="initSaleForm()">
            <i class="bi bi-arrow-counterclockwise"></i> Reset
          </button>
        </div>
      </form>
      <?php endif; ?>
    </div>
  </div>
</div><!-- /.tab-wrapper -->
<?php endif; ?>

<!-- Product data for JS -->
<script>
const SALE_PRODUCTS = <?= json_encode(array_map(fn($p) => [
    'id'    => (int)$p['id'],
    'name'  => $p['name'],
    'cat'   => $p['category'],
    'price' => (float)$p['price'],
    'stock' => (int)$p['stock'],
    'sizes' => array_filter(array_map('trim', explode(',', $p['sizes'] ?? ''))),
    'color' => $p['color'],
], $saleProducts)) ?>;

const CURRENCY = '<?= e(setting('currency_symbol','₱')) ?>';

function formatMoney(v) {
  return CURRENCY + parseFloat(v).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

let itemCount = 0;

function addSaleRow(pid = '') {
  itemCount++;
  const idx  = itemCount;
  const row  = document.createElement('div');
  row.className = 'sale-item-row';
  row.dataset.idx = idx;

  // Product select options
  const opts = SALE_PRODUCTS.map(p =>
    `<option value="${p.id}" data-price="${p.price}" data-stock="${p.stock}" data-sizes="${p.sizes.join(',')}" data-color="${p.color}" ${p.id == pid ? 'selected' : ''}>${p.name} (${p.cat}) — ${CURRENCY}${p.price.toFixed(2)} [stock: ${p.stock}]</option>`
  ).join('');

  row.innerHTML = `
    <div class="sale-item-grid">
      <div class="form-group" style="margin:0">
        <label style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#666">Product</label>
        <select name="item_product[]" class="item-product" required>
          <option value="">Select product…</option>
          ${opts}
        </select>
      </div>
      <div class="form-group" style="margin:0">
        <label style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#666">Size</label>
        <select name="item_size[]" class="item-size">
          <option value="">— N/A —</option>
        </select>
      </div>
      <div class="form-group" style="margin:0">
        <label style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#666">Qty</label>
        <input type="number" name="item_qty[]" class="item-qty" min="1" value="1" required style="width:80px">
      </div>
      <div class="form-group" style="margin:0">
        <label style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#666">Unit Price</label>
        <input type="number" name="item_price[]" class="item-price" step="0.01" min="0" value="" placeholder="0.00" required style="width:110px">
      </div>
      <div class="form-group" style="margin:0">
        <label style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#666">Line Total</label>
        <div class="item-line-total" style="font-weight:800;padding-top:9px">₱0.00</div>
      </div>
      <div style="display:flex;align-items:flex-end;padding-bottom:1px">
        <button type="button" class="btn secondary small remove-item-btn" data-tooltip="Remove item" data-tooltip-pos="bottom">
          <i class="bi bi-trash"></i>
        </button>
      </div>
    </div>`;

  document.getElementById('saleItems').appendChild(row);

  const productSel = row.querySelector('.item-product');
  const sizeSel    = row.querySelector('.item-size');
  const qtyInp     = row.querySelector('.item-qty');
  const priceInp   = row.querySelector('.item-price');
  const lineTotal  = row.querySelector('.item-line-total');

  function populateSizes(sel) {
    const opt = sel.options[sel.selectedIndex];
    const rawSizes = opt?.dataset.sizes || '';
    sizeSel.innerHTML = '<option value="">— N/A —</option>';
    if (rawSizes) {
      rawSizes.split(',').filter(Boolean).forEach(s => {
        const o = document.createElement('option'); o.value = s.trim(); o.textContent = s.trim();
        sizeSel.appendChild(o);
      });
    }
    if (opt && opt.value) {
      priceInp.value = parseFloat(opt.dataset.price || 0).toFixed(2);
      qtyInp.max = opt.dataset.stock || 99;
    }
    recalcRow();
  }

  function recalcRow() {
    const qty   = Math.max(0, parseInt(qtyInp.value, 10) || 0);
    const price = Math.max(0, parseFloat(priceInp.value) || 0);
    const total = qty * price;
    lineTotal.textContent = formatMoney(total);
    recalcTotal();
  }

  productSel.addEventListener('change', () => populateSizes(productSel));
  qtyInp.addEventListener('input', recalcRow);
  priceInp.addEventListener('input', recalcRow);
  row.querySelector('.remove-item-btn').addEventListener('click', () => {
    row.remove(); recalcTotal();
  });

  if (pid) populateSizes(productSel);
}

function recalcTotal() {
  let sub = 0;
  document.querySelectorAll('.sale-item-row').forEach(row => {
    const qty   = Math.max(0, parseInt(row.querySelector('.item-qty')?.value, 10) || 0);
    const price = Math.max(0, parseFloat(row.querySelector('.item-price')?.value) || 0);
    sub += qty * price;
  });
  document.getElementById('saleSubtotal').textContent = formatMoney(sub);
  document.getElementById('saleTotal').textContent    = formatMoney(sub);
}

function initSaleForm() {
  document.getElementById('saleItems').innerHTML = '';
  itemCount = 0;
  addSaleRow();
  recalcTotal();
}

document.addEventListener('DOMContentLoaded', () => {
  const addBtn = document.getElementById('addItemBtn');
  if (addBtn) {
    addBtn.addEventListener('click', () => addSaleRow());
    initSaleForm();
  }
});
</script>

<?php
function paginationStaff(int $cur, int $total, string $base): string {
    if ($total <= 1) return '';
    $html = '<div class="pagination">';
    if ($cur > 1) $html .= '<a href="' . $base . '&p=' . ($cur-1) . '">‹ Prev</a>';
    for ($i = 1; $i <= $total; $i++) {
        if ($i === $cur) $html .= '<span class="current">' . $i . '</span>';
        elseif ($i===1||$i===$total||abs($i-$cur)<=1) $html .= '<a href="' . $base . '&p=' . $i . '">' . $i . '</a>';
        elseif (abs($i-$cur)===2) $html .= '<span class="dots">…</span>';
    }
    if ($cur < $total) $html .= '<a href="' . $base . '&p=' . ($cur+1) . '">Next ›</a>';
    $html .= '</div>';
    return $html;
}
require 'footer.php';
?>
