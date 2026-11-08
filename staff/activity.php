<?php
require_once __DIR__ . '/../config/config.php';
$title = 'Activity Log';
require 'header.php';

// ── Filters ──────────────────────────────────────────────
$search   = trim($_GET['q']      ?? '');
$action   = trim($_GET['action'] ?? '');
$dateFrom = trim($_GET['from']   ?? '');
$dateTo   = trim($_GET['to']     ?? '');
$perPage  = 25;
$curPage  = max(1, (int)($_GET['p'] ?? 1));

$where  = ['1=1'];
$params = [];
if ($search)   { $where[] = '(a.action LIKE ? OR a.details LIKE ? OR u.name LIKE ?)'; $params = array_merge($params, ["%{$search}%","%{$search}%","%{$search}%"]); }
if ($action)   { $where[] = 'a.action=?';              $params[] = $action; }
if ($dateFrom) { $where[] = 'DATE(a.created_at)>=?';   $params[] = $dateFrom; }
if ($dateTo)   { $where[] = 'DATE(a.created_at)<=?';   $params[] = $dateTo; }

$cntSql = 'SELECT COUNT(*) FROM activity_logs a LEFT JOIN users u ON u.id=a.user_id WHERE ' . implode(' AND ', $where);
$cs = db()->prepare($cntSql); $cs->execute($params);
$total      = (int)$cs->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));
$curPage    = min($curPage, $totalPages);
$offset     = ($curPage - 1) * $perPage;

$sql = 'SELECT a.*, u.name AS user_name, u.role AS user_role
        FROM activity_logs a
        LEFT JOIN users u ON u.id=a.user_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY a.id DESC LIMIT ? OFFSET ?';
$stmt = db()->prepare($sql);

foreach ($params as $i => $value) {
    $stmt->bindValue($i + 1, $value);
}

$stmt->bindValue(count($params) + 1, (int)$perPage, PDO::PARAM_INT);
$stmt->bindValue(count($params) + 2, (int)$offset, PDO::PARAM_INT);

$stmt->execute();

$rows = $stmt->fetchAll();

// Distinct action types for filter
$actions = db()->query('SELECT DISTINCT action FROM activity_logs ORDER BY action ASC')->fetchAll(PDO::FETCH_COLUMN);

$actionIcons = [
    'Login'             => '<i class="bi bi-box-arrow-in-right"></i>',
    'Logout'            => '<i class="bi bi-box-arrow-right"></i>',
    'Register'          => '<i class="bi bi-person-plus"></i>',
    'Placed Order'      => '<i class="bi bi-cart3"></i>',
    'Added Product'     => '<i class="bi bi-plus-lg"></i>',
    'Updated Product'   => '<i class="bi bi-pencil"></i>',
    'Deleted Product'   => '<i class="bi bi-trash"></i>',
    'Stock In'          => '<i class="bi bi-box-seam"></i>',
    'Stock Out'         => '<i class="bi bi-box-arrow-up"></i>',
    'Updated Settings'  => '<i class="bi bi-gear"></i>',
    'Added User'        => '<i class="bi bi-person"></i>',
    'Edited User'       => '<i class="bi bi-pencil"></i>',
    'Updated Profile'   => '<i class="bi bi-person"></i>',
    'Changed Password'  => '<i class="bi bi-lock"></i>',
    'Updated Order Status' => '<i class="bi bi-clipboard-data"></i>',
    'Added Supplier'       => '<i class="bi bi-truck"></i>',
    'Updated Supplier'     => '<i class="bi bi-truck"></i>',
    'Deleted Supplier'     => '<i class="bi bi-truck"></i>',
    'Supplier Status Changed' => '<i class="bi bi-truck"></i>',
    'Restock Request Created' => '<i class="bi bi-arrow-repeat"></i>',
    'Restock Request Updated' => '<i class="bi bi-arrow-repeat"></i>',
];
?>

<!-- Filters -->
<div class="staff-panel" style="margin-bottom:0">
  <form method="get" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
    <div>
      <div class="muted" style="font-size:11px;margin-bottom:3px">Search</div>
      <input name="q" value="<?= e($search) ?>" placeholder="Action or details…" style="width:200px">
    </div>
    <div>
      <div class="muted" style="font-size:11px;margin-bottom:3px">Action Type</div>
      <select name="action" style="width:200px">
        <option value="">All Actions</option>
        <?php foreach ($actions as $a): ?>
          <option value="<?= e($a) ?>" <?= $action===$a?'selected':'' ?>><?= e($a) ?></option>
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
    <?php if ($search||$action||$dateFrom||$dateTo): ?>
      <a href="activity.php" class="btn secondary small">Clear</a>
    <?php endif; ?>
  </form>
</div>

<!-- Log table -->
<div class="staff-panel">
  <div class="toolbar">
    <h2>Activity Log <span class="muted" style="font-weight:400">(<?= $total ?> entries)</span></h2>
  </div>
  <div class="responsive-scroll">
    <table>
      <tr><th>Date & Time</th><th>User</th><th>Role</th><th>Action</th><th>Details</th><th>IP</th></tr>
      <?php foreach ($rows as $r):
          $icon = $actionIcons[$r['action']] ?? '•';
          $roleBadge = ['admin'=>'badge-admin','staff'=>'badge-staff','customer'=>'badge-customer'][$r['user_role']??''] ?? '';
      ?>
        <tr>
          <td class="muted" style="white-space:nowrap">
            <?= date('M d, Y', strtotime($r['created_at'])) ?><br>
            <span style="font-size:11px"><?= date('g:i A', strtotime($r['created_at'])) ?></span>
          </td>
          <td><?= e($r['user_name'] ?? 'Guest') ?></td>
          <td>
            <?php if ($r['user_role']): ?>
              <span class="status <?= $roleBadge ?>"><?= ucfirst($r['user_role']) ?></span>
            <?php else: ?>
              <span class="muted">—</span>
            <?php endif; ?>
          </td>
          <td><span><?= $icon ?></span> <?= e($r['action']) ?></td>
          <td class="muted"><?= e($r['details']) ?></td>
          <td class="muted" style="font-size:11px"><?= e($r['ip'] ?? '—') ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?>
        <tr><td colspan="6" style="text-align:center;padding:30px;color:var(--muted)">No activity found</td></tr>
      <?php endif; ?>
    </table>
  </div>

  <?php
  $pBase = 'activity.php?q=' . urlencode($search) . '&action=' . urlencode($action) . '&from=' . urlencode($dateFrom) . '&to=' . urlencode($dateTo);
  $total_pages = $totalPages;
  $cur = $curPage;
  if ($total_pages > 1):
      echo '<div class="pagination">';
      if ($cur > 1) echo '<a href="' . $pBase . '&p=' . ($cur-1) . '">‹ Prev</a>';
      for ($i = 1; $i <= $total_pages; $i++) {
          if ($i === $cur) echo '<span class="current">' . $i . '</span>';
          elseif ($i===1||$i===$total_pages||abs($i-$cur)<=1) echo '<a href="' . $pBase . '&p=' . $i . '">' . $i . '</a>';
          elseif (abs($i-$cur)===2) echo '<span class="dots">…</span>';
      }
      if ($cur < $total_pages) echo '<a href="' . $pBase . '&p=' . ($cur+1) . '">Next ›</a>';
      echo '</div>';
  endif;
  ?>
</div>

<?php require 'footer.php'; ?>
