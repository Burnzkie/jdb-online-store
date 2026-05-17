<?php
session_start();
require_once '../classes/Database.php';

if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    session_destroy();
    header('Location: ../auth/login.php?error=access_denied');
    exit;
}

$pdo       = Database::getInstance()->getConnection();
$user_name = $_SESSION['user_name'] ?? 'Admin';
$avatarUrl = 'https://ui-avatars.com/api/?name=' . urlencode($user_name) . '&background=667eea&color=fff';

// ── Stats ────────────────────────────────────────────────────
$totalSales = (float)$pdo->query("
    SELECT COALESCE(SUM(total_amount),0) FROM orders
    WHERE  MONTH(created_at) = MONTH(CURRENT_DATE())
      AND  YEAR(created_at)  = YEAR(CURRENT_DATE())
")->fetchColumn();

$refunds = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'refunded'")->fetchColumn();

// Monthly revenue – last 6 months
$salesLabels = [];
$salesData   = [];
for ($i = 5; $i >= 0; $i--) {
    $ym   = date('Y-m', strtotime("-{$i} months"));
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE DATE_FORMAT(created_at,'%Y-%m') = ?"
    );
    $stmt->execute([$ym]);
    $salesLabels[] = date('M Y', strtotime("-{$i} months"));
    $salesData[]   = (float)$stmt->fetchColumn();
}

// Top categories by revenue
$topCategories = $pdo->query("
    SELECT c.name,
           COALESCE(SUM(oi.quantity * oi.price), 0) AS revenue
    FROM   order_items oi
    JOIN   products    p  ON p.id  = oi.product_id
    JOIN   categories  c  ON c.id  = p.category_id
    GROUP  BY c.id
    ORDER  BY revenue DESC
    LIMIT  5
")->fetchAll(PDO::FETCH_ASSOC);

$maxRevenue = !empty($topCategories) ? max(array_column($topCategories, 'revenue')) : 1;

// Orders by status
$ordersByStatus = $pdo->query("
    SELECT status, COUNT(*) AS cnt FROM orders GROUP BY status
")->fetchAll(PDO::FETCH_ASSOC);

$statusLabels = array_column($ordersByStatus, 'status');
$statusCounts = array_column($ordersByStatus, 'cnt');

$currentPage = 'analytics';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $pageTitle = 'Analytics – JDB Parts Admin'; require_once __DIR__ . '/partials/head.php'; ?>
</head>
<body>

<?php require_once '_sidebar.php'; ?>

<div class="main-content" id="mainContent">

    <header class="top-header d-flex align-items-center justify-content-between flex-wrap gap-3">
        <div class="d-flex align-items-center gap-3">
            <button class="btn btn-light p-2 d-lg-none" id="sidebarToggle"><i class="bi bi-list fs-4"></i></button>
            <button class="btn btn-light p-2 d-none d-lg-block" id="sidebarToggle"><i class="bi bi-layout-sidebar fs-5"></i></button>
            <div>
                <h5 class="mb-0 fw-semibold">Analytics</h5>
                <small class="text-muted">Store performance overview</small>
            </div>
        </div>
        <div class="dropdown">
            <a href="#" class="d-flex align-items-center gap-2 text-decoration-none dropdown-toggle"
               data-bs-toggle="dropdown">
                <img src="<?= $avatarUrl ?>" width="36" height="36" class="rounded-circle" alt="">
                <span class="d-none d-sm-inline fw-medium"><?= htmlspecialchars($user_name) ?></span>
            </a>
            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                <li><a class="dropdown-item" href="settings.php"><i class="bi bi-gear me-2"></i>Settings</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item text-danger" href="../auth/logout.php">
                    <i class="bi bi-box-arrow-left me-2"></i>Logout</a></li>
            </ul>
        </div>
    </header>

    <!-- Stat cards -->
    <div class="row g-4 mb-4">
        <div class="col-md-4">
            <div class="stat-card total-sales-card h-100">
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <div>
                        <p class="mb-1 small fw-semibold text-uppercase opacity-75">Revenue This Month</p>
                        <h3 class="mb-0 fw-bold">&#8369;<?= number_format($totalSales, 2) ?></h3>
                    </div>
                    <span class="bg-white bg-opacity-25 p-2 rounded-3">
                        <i class="bi bi-graph-up-arrow fs-4"></i>
                    </span>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card h-100">
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <div>
                        <p class="mb-1 small text-muted fw-semibold text-uppercase">Order Statuses</p>
                        <h3 class="mb-0 fw-bold"><?= array_sum($statusCounts) ?></h3>
                    </div>
                    <span class="bg-info bg-opacity-10 p-2 rounded-3 text-info">
                        <i class="bi bi-cart-check fs-4"></i>
                    </span>
                </div>
                <small class="text-muted">Total orders tracked</small>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card h-100">
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <div>
                        <p class="mb-1 small text-muted fw-semibold text-uppercase">Refunds</p>
                        <h3 class="mb-0 fw-bold"><?= number_format($refunds) ?></h3>
                    </div>
                    <span class="bg-danger bg-opacity-10 p-2 rounded-3 text-danger">
                        <i class="bi bi-arrow-return-left fs-4"></i>
                    </span>
                </div>
                <small class="text-danger">Refunded orders</small>
            </div>
        </div>
    </div>

    <!-- Charts -->
    <div class="row g-4 mb-4">
        <div class="col-lg-8">
            <div class="chart-card h-100">
                <h6 class="fw-semibold mb-4">Revenue – Last 6 Months</h6>
                <canvas id="salesChart" height="260"></canvas>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="chart-card h-100">
                <h6 class="fw-semibold mb-4">Orders by Status</h6>
                <canvas id="statusChart" height="260"></canvas>
            </div>
        </div>
    </div>

    <!-- Top categories -->
    <div class="chart-card">
        <h6 class="fw-semibold mb-4">Top Categories by Revenue</h6>
        <?php if (empty($topCategories)): ?>
            <p class="text-muted">No sales data available yet.</p>
        <?php else: ?>
            <?php foreach ($topCategories as $cat): ?>
                <div class="d-flex justify-content-between mb-1 small">
                    <span><?= htmlspecialchars($cat['name']) ?></span>
                    <strong>&#8369;<?= number_format($cat['revenue'], 2) ?></strong>
                </div>
                <div class="progress mb-4">
                    <div class="progress-bar bg-primary"
                         style="width: <?= $maxRevenue > 0 ? round($cat['revenue'] / $maxRevenue * 100) : 0 ?>%">
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

</div>

<script src="../assets/js/bootstrap_buddle.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="../assets/js/dashboard_sidebar.js"></script>
<script>
(function () {
    // Revenue line chart
    new Chart(document.getElementById('salesChart').getContext('2d'), {
        type: 'line',
        data: {
            labels: <?= json_encode($salesLabels) ?>,
            datasets: [{
                label: 'Revenue',
                data: <?= json_encode($salesData) ?>,
                borderColor: '#667eea',
                backgroundColor: 'rgba(102,126,234,0.1)',
                tension: 0.4,
                fill: true,
                pointRadius: 5,
                pointBackgroundColor: '#667eea',
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { callback: v => '₱' + Number(v).toLocaleString('en-PH', {minimumFractionDigits:2,maximumFractionDigits:2}) },
                    grid: { color: 'rgba(0,0,0,0.04)' }
                },
                x: { grid: { display: false } }
            }
        }
    });

    // Order status donut chart
    new Chart(document.getElementById('statusChart').getContext('2d'), {
        type: 'doughnut',
        data: {
            labels: <?= json_encode(array_map('ucfirst', $statusLabels)) ?>,
            datasets: [{
                data: <?= json_encode($statusCounts) ?>,
                backgroundColor: ['#ffc107','#0dcaf0','#0d6efd','#198754','#6c757d','#dc3545'],
                borderWidth: 2,
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { position: 'bottom' } },
            cutout: '65%',
        }
    });
}());
</script>
</body>
</html>