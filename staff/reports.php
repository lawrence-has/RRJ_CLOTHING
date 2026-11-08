<?php
require_once __DIR__ . '/../config/config.php';
$title = 'Reports';
$u = require_staff();

// ── CSV Export ───────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    csrf_verify();
    $from = $_GET['from'] ?? date('Y-m-01');
    $to   = $_GET['to']   ?? date('Y-m-d');
    $rows = db()->prepare('SELECT o.order_no,u.name AS customer,o.subtotal,o.shipping,o.total,o.payment_method,o.shipping_method,o.status,o.created_at FROM orders o JOIN users u ON u.id=o.user_id WHERE DATE(o.created_at) BETWEEN ? AND ? AND o.status<>"Cancelled" ORDER BY o.created_at DESC');
    $rows->execute([$from, $to]);
    $data = $rows->fetchAll();
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="rrj_sales_' . $from . '_' . $to . '.csv"');
    $fh = fopen('php://output', 'w');
    fputcsv($fh, ['Order #','Customer','Subtotal','Shipping','Total','Payment','Shipping Method','Status','Date']);
    foreach ($data as $r) {
        fputcsv($fh, [$r['order_no'],$r['customer'],$r['subtotal'],$r['shipping'],$r['total'],$r['payment_method'],$r['shipping_method'],$r['status'],$r['created_at']]);
    }
    fclose($fh);
    exit;
}

require 'header.php';

// ── Date range filter ────────────────────────────────────
$from = trim($_GET['from'] ?? date('Y-m-01'));
$to   = trim($_GET['to']   ?? date('Y-m-d'));

// ── Summary stats ────────────────────────────────────────
$s = db()->prepare("SELECT COALESCE(SUM(total),0) AS sales, COUNT(*) AS orders, COALESCE(AVG(total),0) AS avg FROM orders WHERE DATE(created_at) BETWEEN ? AND ? AND status<>'Cancelled'");
$s->execute([$from, $to]);
$summary = $s->fetch();

$customers = (int) db()->query("SELECT COUNT(*) FROM users WHERE role='customer'")->fetchColumn();

// ── Sales by category ────────────────────────────────────
$catData = db()->prepare("SELECT p.category, SUM(oi.quantity) AS qty, SUM(oi.quantity*oi.price) AS revenue
    FROM order_items oi
    JOIN products p ON p.id=oi.product_id
    JOIN orders o ON o.id=oi.order_id
    WHERE DATE(o.created_at) BETWEEN ? AND ? AND o.status<>'Cancelled'
    GROUP BY p.category ORDER BY revenue DESC");
$catData->execute([$from, $to]);
$categories = $catData->fetchAll();

// ── Top products ─────────────────────────────────────────
$topProds = db()->prepare("SELECT p.name, p.category, SUM(oi.quantity) AS qty, SUM(oi.quantity*oi.price) AS revenue
    FROM order_items oi
    JOIN products p ON p.id=oi.product_id
    JOIN orders o ON o.id=oi.order_id
    WHERE DATE(o.created_at) BETWEEN ? AND ? AND o.status<>'Cancelled'
    GROUP BY p.id ORDER BY qty DESC LIMIT 10");
$topProds->execute([$from, $to]);
$topProducts = $topProds->fetchAll();

// ── Daily sales trend ────────────────────────────────────
$daily = db()->prepare("SELECT DATE(created_at) AS day, COUNT(*) AS orders, SUM(total) AS sales
    FROM orders WHERE DATE(created_at) BETWEEN ? AND ? AND status<>'Cancelled'
    GROUP BY DATE(created_at) ORDER BY day ASC");
$daily->execute([$from, $to]);
$dailyData = $daily->fetchAll();
$chartDays  = array_map(fn($r) => date('M d', strtotime($r['day'])), $dailyData);
$chartSales = array_map(fn($r) => (float)$r['sales'], $dailyData);

// ── Inventory snapshot ───────────────────────────────────
$inventory = db()->query("SELECT * FROM products ORDER BY stock ASC")->fetchAll();

// ── Payment method breakdown ─────────────────────────────
$payData = db()->prepare("SELECT payment_method, COUNT(*) AS cnt, SUM(total) AS total FROM orders WHERE DATE(created_at) BETWEEN ? AND ? AND status<>'Cancelled' GROUP BY payment_method");
$payData->execute([$from, $to]);
$payBreakdown = $payData->fetchAll();
?>

<!-- Date range filter -->
<div class="staff-panel" style="margin-top:0">
  <form method="get" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
    <div>
      <div class="muted" style="font-size:11px;margin-bottom:3px">From</div>
      <input type="date" name="from" value="<?= e($from) ?>" style="width:160px">
    </div>
    <div>
      <div class="muted" style="font-size:11px;margin-bottom:3px">To</div>
      <input type="date" name="to" value="<?= e($to) ?>" style="width:160px">
    </div>
    <button class="btn">Apply Range</button>
    <a href="reports.php?from=<?= urlencode(date('Y-m-01')) ?>&to=<?= urlencode(date('Y-m-d')) ?>" class="btn secondary">This Month</a>
    <a href="reports.php?from=<?= urlencode(date('Y-01-01')) ?>&to=<?= urlencode(date('Y-m-d')) ?>" class="btn secondary">This Year</a>
    <a href="reports.php?from=2000-01-01&to=<?= urlencode(date('Y-m-d')) ?>" class="btn secondary">All Time</a>
    <a href="reports.php?export=csv&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>&csrf_token=<?= e(csrf_token()) ?>"
       class="btn dark" data-tooltip="Download sales data as CSV" data-tooltip-pos="bottom"><i class="bi bi-download"></i> Export CSV</a>
  </form>
</div>

<!-- Summary cards -->
<div class="staff-grid" style="margin-top:16px">
  <div class="staff-card">
    <div class="card-icon"><i class="bi bi-currency-dollar"></i></div>
    <h3>Total Sales</h3>
    <strong><?= money((float)$summary['sales']) ?></strong>
    <span class="muted"><?= date('M d', strtotime($from)) ?> – <?= date('M d, Y', strtotime($to)) ?></span>
  </div>
  <div class="staff-card green">
    <div class="card-icon"><i class="bi bi-box-seam"></i></div>
    <h3>Total Orders</h3>
    <strong><?= (int)$summary['orders'] ?></strong>
    <span class="muted">In selected range</span>
  </div>
  <div class="staff-card blue">
    <div class="card-icon"><i class="bi bi-bar-chart-line"></i></div>
    <h3>Average Order</h3>
    <strong><?= money((float)$summary['avg']) ?></strong>
    <span class="muted">Per transaction</span>
  </div>
  <div class="staff-card yellow">
    <div class="card-icon"><i class="bi bi-people"></i></div>
    <h3>Total Customers</h3>
    <strong><?= $customers ?></strong>
    <span class="muted">All time</span>
  </div>
</div>

<!-- Sales trend chart -->
<div class="staff-panel" style="margin-top:18px">
  <div class="toolbar"><h2>Daily Sales Trend</h2></div>
  <div class="chart-wrap" style="height:220px">
    <canvas id="trendChart"></canvas>
  </div>
</div>

<!-- Category + Payment breakdown -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-top:18px" class="form-grid">
  <div class="staff-panel" style="margin-top:0">
    <h2 style="margin-bottom:14px">Sales by Category</h2>
    <table>
      <tr><th>Category</th><th>Units Sold</th><th>Revenue</th></tr>
      <?php foreach ($categories as $c): ?>
        <tr>
          <td><?= e($c['category']) ?></td>
          <td><?= (int)$c['qty'] ?></td>
          <td><?= money((float)$c['revenue']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$categories): ?>
        <tr><td colspan="3" style="text-align:center;color:var(--muted)">No data</td></tr>
      <?php endif; ?>
    </table>
  </div>

  <div class="staff-panel" style="margin-top:0">
    <h2 style="margin-bottom:14px">Payment Methods</h2>
    <table>
      <tr><th>Method</th><th>Orders</th><th>Total</th></tr>
      <?php foreach ($payBreakdown as $p): ?>
        <tr>
          <td><?= e($p['payment_method']) ?></td>
          <td><?= (int)$p['cnt'] ?></td>
          <td><?= money((float)$p['total']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$payBreakdown): ?>
        <tr><td colspan="3" style="text-align:center;color:var(--muted)">No data</td></tr>
      <?php endif; ?>
    </table>
  </div>
</div>

<!-- Top products -->
<div class="staff-panel">
  <div class="toolbar">
    <h2>Top Selling Products</h2>
    <span class="muted">Showing top 10</span>
  </div>
  <div class="responsive-scroll">
    <table>
      <tr><th>#</th><th>Product</th><th>Category</th><th>Units Sold</th><th>Revenue</th></tr>
      <?php foreach ($topProducts as $i => $r): ?>
        <tr>
          <td class="muted"><?= $i + 1 ?></td>
          <td><b><?= e($r['name']) ?></b></td>
          <td><?= e($r['category']) ?></td>
          <td><?= (int)$r['qty'] ?></td>
          <td><?= money((float)$r['revenue']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$topProducts): ?>
        <tr><td colspan="5" style="text-align:center;padding:20px;color:var(--muted)">No sales data in this period</td></tr>
      <?php endif; ?>
    </table>
  </div>
</div>

<!-- Inventory snapshot -->
<div class="staff-panel">
  <div class="toolbar">
    <h2>Inventory Snapshot</h2>
    <a href="inventory.php" class="btn secondary small">Manage Inventory</a>
  </div>
  <div class="responsive-scroll">
    <table>
      <tr><th>Product</th><th>Category</th><th>Stock</th><th>Reorder Level</th><th>Status</th></tr>
      <?php foreach ($inventory as $r):
          $isLow = (int)$r['stock'] <= (int)$r['reorder_level'] && $r['stock'] > 0;
          $isOut = (int)$r['stock'] <= 0;
      ?>
        <tr>
          <td><?= e($r['name']) ?></td>
          <td><?= e($r['category']) ?></td>
          <td><?= (int)$r['stock'] ?></td>
          <td><?= (int)$r['reorder_level'] ?></td>
          <td>
            <?php if ($isOut):  ?><span class="status off">Out of Stock</span>
            <?php elseif ($isLow): ?><span class="status low">Low Stock</span>
            <?php else: ?><span class="status">In Stock</span><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
(function(){
  const days  = <?= json_encode($chartDays) ?>;
  const sales = <?= json_encode($chartSales) ?>;
  const ctx   = document.getElementById('trendChart');
  if (!ctx) return;
  new Chart(ctx, {
    type: 'line',
    data: {
      labels: days,
      datasets: [{
        label: 'Sales (₱)',
        data: sales,
        borderColor: '#d71920',
        backgroundColor: 'rgba(215,25,32,.08)',
        tension: 0.35,
        fill: true,
        pointRadius: 3,
      }]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: {
        y: { beginAtZero:true, ticks:{callback:v=>'₱'+v.toLocaleString()}, grid:{color:'#f0f0f0'} },
        x: { grid:{display:false} }
      }
    }
  });
})();
</script>

<?php require 'footer.php'; ?>
