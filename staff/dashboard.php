<?php
require_once __DIR__ . '/../config/config.php';
$title = 'Dashboard';
require 'header.php';

// ── Today's stats (MySQL: CURDATE()) ────────────────────
$todaySales   = (float) db()->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE DATE(created_at)=CURDATE() AND status<>'Cancelled'")->fetchColumn();
$todayOrders  = (int)   db()->query("SELECT COUNT(*) FROM orders WHERE DATE(created_at)=CURDATE() AND status<>'Cancelled'")->fetchColumn();
$totalCustomers = (int) db()->query("SELECT COUNT(*) FROM users WHERE role='customer'")->fetchColumn();
$totalProducts  = (int) db()->query("SELECT COUNT(*) FROM products WHERE status='Active'")->fetchColumn();
$lowStock     = (int)   db()->query("SELECT COUNT(*) FROM products WHERE stock<=reorder_level AND status='Active'")->fetchColumn();

// ── This month ───────────────────────────────────────────
$monthSales   = (float) db()->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE()) AND status<>'Cancelled'")->fetchColumn();
$pendingOrders= (int)   db()->query("SELECT COUNT(*) FROM orders WHERE status='Order Placed'")->fetchColumn();

// ── Weekly sales chart (last 7 days) ─────────────────────
$weekly = db()->query("
    SELECT DATE(created_at) AS day, COALESCE(SUM(total),0) AS sales
    FROM orders
    WHERE created_at >= CURDATE() - INTERVAL 6 DAY AND status<>'Cancelled'
    GROUP BY DATE(created_at)
    ORDER BY day ASC
")->fetchAll();

$chartDays   = [];
$chartSales  = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $chartDays[]  = date('D', strtotime($d));
    $found = array_filter($weekly, fn($r) => $r['day'] === $d);
    $chartSales[] = $found ? (float)array_values($found)[0]['sales'] : 0;
}

// ── Recent 10 transactions ───────────────────────────────
$recent = db()->query("
    SELECT o.*, u.name AS customer
    FROM orders o
    JOIN users u ON u.id = o.user_id
    ORDER BY o.id DESC LIMIT 10
")->fetchAll();

// ── Top 5 products ───────────────────────────────────────
$topProducts = db()->query("
    SELECT p.name, SUM(oi.quantity) AS sold, SUM(oi.quantity*oi.price) AS revenue
    FROM order_items oi
    JOIN products p ON p.id=oi.product_id
    JOIN orders o ON o.id=oi.order_id
    WHERE o.status<>'Cancelled'
    GROUP BY p.id ORDER BY sold DESC LIMIT 5
")->fetchAll();

$statusBadge = [
    'Order Placed' => 'badge-pending',
    'Processing'   => 'badge-processing',
    'Shipped'      => 'badge-shipped',
    'Delivered'    => 'badge-delivered',
    'Cancelled'    => 'badge-cancelled',
];
?>

<div class="staff-welcome-strip">
  <div>
    <span class="welcome-kicker">STORE OPERATIONS OVERVIEW</span>
    <h2>Welcome back, <?= e(explode(' ', trim($u['name']))[0]) ?>.</h2>
    <p>Here is the latest activity across RRJ Clothing. Use the staff tools to keep daily operations moving.</p>
  </div>
  <div class="welcome-date"><?= date('l, F j, Y') ?></div>
</div>

<!-- Stat cards -->
<div class="staff-grid">
  <div class="staff-card">
    <div class="card-icon"><i class="bi bi-currency-dollar"></i></div>
    <h3>Today's Sales</h3>
    <strong><?= money($todaySales) ?></strong>
    <span class="muted">Revenue today</span>
  </div>
  <div class="staff-card green">
    <div class="card-icon"><i class="bi bi-box-seam"></i></div>
    <h3>Today's Orders</h3>
    <strong><?= $todayOrders ?></strong>
    <span class="muted"><?= $pendingOrders ?> pending</span>
  </div>
  <div class="staff-card blue">
    <div class="card-icon"><i class="bi bi-people"></i></div>
    <h3>Total Customers</h3>
    <strong><?= $totalCustomers ?></strong>
    <span class="muted">Registered accounts</span>
  </div>
  <div class="staff-card yellow">
    <div class="card-icon"><i class="bi bi-exclamation-triangle"></i></div>
    <h3>Low Stock Items</h3>
    <strong><?= $lowStock ?></strong>
    <span class="muted"><a href="inventory.php" style="color:var(--red)">View inventory →</a></span>
  </div>
  <div class="staff-card">
    <div class="card-icon"><i class="bi bi-tag"></i></div>
    <h3>Active Products</h3>
    <strong><?= $totalProducts ?></strong>
    <span class="muted"><a href="products.php" style="color:var(--red)">Manage catalog →</a></span>
  </div>
</div>

<!-- Monthly highlight -->
<div class="staff-panel" style="margin-top:18px;padding:16px 20px;background:linear-gradient(135deg,#17191d,#2d1417);color:#fff;border:none">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
    <div>
      <div style="font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#aaa">This Month's Revenue</div>
      <div style="font-size:32px;font-weight:900;color:#fff;margin-top:4px"><?= money($monthSales) ?></div>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap">
      <a href="sales.php"    class="btn secondary small" data-tooltip="View all orders" data-tooltip-pos="bottom"><i class="bi bi-clipboard-data"></i> All Orders</a>
      <a href="products.php" class="btn secondary small" data-tooltip="Add a new product" data-tooltip-pos="bottom"><i class="bi bi-plus-lg"></i> Add Product</a>
      <a href="reports.php"  class="btn secondary small" data-tooltip="View sales reports" data-tooltip-pos="bottom"><i class="bi bi-bar-chart-line"></i> Reports</a>
    </div>
  </div>
</div>

<!-- Weekly chart + Top products -->
<div style="display:grid;grid-template-columns:1.6fr 1fr;gap:18px;margin-top:18px" class="chart-layout">

  <div class="staff-panel" style="margin-top:0">
    <div class="toolbar"><h2>Sales — Last 7 Days</h2></div>
    <div class="chart-wrap">
      <canvas id="salesChart"></canvas>
    </div>
  </div>

  <div class="staff-panel" style="margin-top:0">
    <div class="toolbar"><h2>Top Selling Products</h2><a href="reports.php" class="btn secondary small">Full Report</a></div>
    <table>
      <tr><th>Product</th><th>Sold</th><th>Revenue</th></tr>
      <?php foreach ($topProducts as $r): ?>
        <tr>
          <td style="max-width:160px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($r['name']) ?></td>
          <td><?= (int)$r['sold'] ?></td>
          <td><?= money((float)$r['revenue']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$topProducts): ?>
        <tr><td colspan="3" style="text-align:center;color:var(--muted)">No sales yet</td></tr>
      <?php endif; ?>
    </table>
  </div>

</div>

<!-- Recent transactions -->
<div class="staff-panel">
  <div class="toolbar">
    <h2>Recent Transactions</h2>
    <a href="sales.php" class="btn secondary small">View All</a>
  </div>
  <div class="responsive-scroll">
    <table>
      <tr><th>Invoice</th><th>Customer</th><th>Total</th><th>Payment</th><th>Date</th><th>Status</th><th></th></tr>
      <?php foreach ($recent as $r):
          $sc = $statusBadge[$r['status']] ?? '';
      ?>
        <tr>
          <td><b><?= e($r['order_no']) ?></b></td>
          <td><?= e($r['customer']) ?></td>
          <td><?= money((float)$r['total']) ?></td>
          <td><?= e($r['payment_method']) ?></td>
          <td><?= date('M d, Y', strtotime($r['created_at'])) ?></td>
          <td><span class="status <?= $sc ?>"><?= e($r['status']) ?></span></td>
          <td><a href="sales.php?view=<?= (int)$r['id'] ?>" class="btn secondary small">View</a></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$recent): ?>
        <tr><td colspan="7" style="text-align:center;color:var(--muted);padding:30px">No transactions yet</td></tr>
      <?php endif; ?>
    </table>
  </div>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
(function(){
  const days  = <?= json_encode($chartDays) ?>;
  const sales = <?= json_encode($chartSales) ?>;
  const ctx   = document.getElementById('salesChart');
  if (!ctx) return;
  new Chart(ctx, {
    type: 'bar',
    data: {
      labels: days,
      datasets: [{
        label: 'Sales (₱)',
        data: sales,
        backgroundColor: 'rgba(215,25,32,.75)',
        borderColor: '#d71920',
        borderWidth: 1,
        borderRadius: 4,
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: {
        y: {
          beginAtZero: true,
          ticks: { callback: v => '₱'+v.toLocaleString() },
          grid: { color: '#f0f0f0' }
        },
        x: { grid: { display: false } }
      }
    }
  });
})();
</script>

<?php require 'footer.php'; ?>
