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
    SELECT COALESCE(SUM(total_amount), 0)
    FROM   orders
    WHERE  MONTH(created_at) = MONTH(CURRENT_DATE())
      AND  YEAR(created_at)  = YEAR(CURRENT_DATE())
")->fetchColumn();

$prevSales = (float)$pdo->query("
    SELECT COALESCE(SUM(total_amount), 0)
    FROM   orders
    WHERE  MONTH(created_at) = MONTH(CURRENT_DATE() - INTERVAL 1 MONTH)
      AND  YEAR(created_at)  = YEAR(CURRENT_DATE() - INTERVAL 1 MONTH)
")->fetchColumn();

$salesChange   = $prevSales > 0 ? round(($totalSales - $prevSales) / $prevSales * 100, 1) : 0;
$totalOrders   = (int)$pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
$totalCustomers= (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='customer'")->fetchColumn();
$totalRefunds  = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status='refunded'")->fetchColumn();

// ── Chart: last 6 months revenue ────────────────────────────
$chartLabels = [];
$chartData   = [];
for ($i = 5; $i >= 0; $i--) {
    $ym   = date('Y-m', strtotime("-{$i} months"));
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE DATE_FORMAT(created_at,'%Y-%m') = ?"
    );
    $stmt->execute([$ym]);
    $chartLabels[] = date('M Y', strtotime("-{$i} months"));
    $chartData[]   = (float)$stmt->fetchColumn();
}

// ── Recent orders ────────────────────────────────────────────
$recentOrders = $pdo->query("
    SELECT o.id, o.order_number, o.total_amount, o.status, o.created_at,
           COALESCE(o.customer_name, CONCAT(u.firstname,' ',u.lastname), 'Guest') AS customer
    FROM   orders o
    LEFT JOIN users u ON u.id = o.user_id
    ORDER BY o.created_at DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// ── Low-stock products ───────────────────────────────────────
$lowStock = $pdo->query("
    SELECT id, name, stock FROM products
    WHERE is_active = 1 AND stock <= 5
    ORDER BY stock ASC LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

$currentPage = 'dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $pageTitle = 'Dashboard – JDB Parts Admin'; require_once __DIR__ . '/partials/head.php'; ?>
</head>
<body>

<?php require_once '_sidebar.php'; ?>

<div class="main-content" id="mainContent">

    <!-- Header -->
    <header class="top-header d-flex align-items-center justify-content-between flex-wrap gap-3">
        <div class="d-flex align-items-center gap-3">
            <button class="btn btn-light d-lg-none p-2" id="sidebarToggle" aria-label="Toggle sidebar">
                <i class="bi bi-list fs-4"></i>
            </button>
            <button class="btn btn-light d-none d-lg-block p-2" id="sidebarToggle" aria-label="Toggle sidebar">
                <i class="bi bi-layout-sidebar fs-5"></i>
            </button>
            <div>
                <h5 class="mb-0 fw-semibold">Welcome back, <?= htmlspecialchars($user_name) ?> 👋</h5>
                <small class="text-muted">Here's what's happening with your store today.</small>
            </div>
        </div>

        <div class="d-flex align-items-center gap-3">
            <div class="position-relative d-none d-md-block">
                <input type="text" class="form-control rounded-pill ps-5"
                       placeholder="Quick search…" style="width:260px;">
                <i class="bi bi-search position-absolute top-50 translate-middle-y text-muted" style="left:1rem;"></i>
            </div>
            <div class="dropdown">
                <a href="#" class="d-flex align-items-center gap-2 text-decoration-none dropdown-toggle"
                   data-bs-toggle="dropdown" aria-expanded="false">
                    <img src="<?= $avatarUrl ?>" width="38" height="38" class="rounded-circle" alt="Avatar">
                    <span class="d-none d-sm-inline fw-medium"><?= htmlspecialchars($user_name) ?></span>
                </a>
                <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                    <li><a class="dropdown-item" href="settings.php"><i class="bi bi-gear me-2"></i>Settings</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="../auth/logout.php">
                        <i class="bi bi-box-arrow-left me-2"></i>Logout</a></li>
                </ul>
            </div>
        </div>
    </header>

    <!-- Stats row -->
    <div class="row g-4 mb-4">
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card total-sales-card h-100">
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <div>
                        <p class="mb-1 small fw-semibold text-uppercase opacity-75">Revenue (This Month)</p>
                        <h3 class="mb-0 fw-bold">&#8369;<?= number_format($totalSales, 2) ?></h3>
                    </div>
                    <span class="bg-white bg-opacity-25 p-2 rounded-3">
                        <i class="bi bi-graph-up-arrow fs-4"></i>
                    </span>
                </div>
                <small>
                    <i class="bi bi-arrow-<?= $salesChange >= 0 ? 'up' : 'down' ?>"></i>
                    <?= abs($salesChange) ?>% vs last month
                </small>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card h-100">
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <div>
                        <p class="mb-1 small text-muted fw-semibold text-uppercase">Total Orders</p>
                        <h3 class="mb-0 fw-bold"><?= number_format($totalOrders) ?></h3>
                    </div>
                    <span class="bg-primary bg-opacity-10 p-2 rounded-3 text-primary">
                        <i class="bi bi-cart-check fs-4"></i>
                    </span>
                </div>
                <a href="orders.php" class="small text-muted text-decoration-none">View all orders →</a>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card h-100">
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <div>
                        <p class="mb-1 small text-muted fw-semibold text-uppercase">Customers</p>
                        <h3 class="mb-0 fw-bold"><?= number_format($totalCustomers) ?></h3>
                    </div>
                    <span class="bg-success bg-opacity-10 p-2 rounded-3 text-success">
                        <i class="bi bi-people fs-4"></i>
                    </span>
                </div>
                <a href="customers.php" class="small text-muted text-decoration-none">Manage customers →</a>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card h-100">
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <div>
                        <p class="mb-1 small text-muted fw-semibold text-uppercase">Refunds</p>
                        <h3 class="mb-0 fw-bold"><?= number_format($totalRefunds) ?></h3>
                    </div>
                    <span class="bg-danger bg-opacity-10 p-2 rounded-3 text-danger">
                        <i class="bi bi-arrow-return-left fs-4"></i>
                    </span>
                </div>
                <small class="text-muted">Refunded orders total</small>
            </div>
        </div>
    </div>

    <!-- Charts + Recent Orders -->
    <div class="row g-4 mb-4">
        <!-- Revenue chart -->
        <div class="col-lg-8">
            <div class="chart-card h-100">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h6 class="fw-semibold mb-0">Revenue (Last 6 Months)</h6>
                </div>
                <canvas id="salesChart" height="260"></canvas>
            </div>
        </div>

        <!-- Low stock -->
        <div class="col-lg-4">
            <div class="chart-card h-100">
                <h6 class="fw-semibold mb-3">⚠️ Low Stock Alert</h6>
                <?php if (empty($lowStock)): ?>
                    <p class="text-muted small">All products are well-stocked.</p>
                <?php else: ?>
                    <?php foreach ($lowStock as $ls): ?>
                        <div class="d-flex justify-content-between align-items-center mb-2 small">
                            <span class="text-truncate me-2"><?= htmlspecialchars($ls['name']) ?></span>
                            <span class="badge bg-<?= $ls['stock'] == 0 ? 'danger' : 'warning text-dark' ?>">
                                <?= $ls['stock'] ?> left
                            </span>
                        </div>
                    <?php endforeach; ?>
                    <a href="product.php" class="btn btn-sm btn-outline-secondary mt-2 w-100">Manage Stock</a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Recent Orders table -->
    <div class="chart-card">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="fw-semibold mb-0">Recent Orders</h6>
            <a href="orders.php" class="btn btn-sm btn-outline-primary">View All</a>
        </div>
        <?php if (empty($recentOrders)): ?>
            <p class="text-muted">No orders yet.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Order #</th>
                        <th>Customer</th>
                        <th>Total</th>
                        <th>Status</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $statusColors = [
                        'pending'    => 'warning text-dark',
                        'processing' => 'info text-dark',
                        'shipped'    => 'primary',
                        'delivered'  => 'success',
                        'cancelled'  => 'secondary',
                        'refunded'   => 'danger',
                    ];
                    foreach ($recentOrders as $o):
                        $color = $statusColors[$o['status']] ?? 'secondary';
                    ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($o['order_number'] ?? '#' . $o['id']) ?></strong></td>
                        <td><?= htmlspecialchars($o['customer']) ?></td>
                        <td>&#8369;<?= number_format($o['total_amount'], 2) ?></td>
                        <td><span class="badge bg-<?= $color ?>"><?= ucfirst($o['status']) ?></span></td>
                        <td class="text-muted small"><?= date('d M Y', strtotime($o['created_at'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

</div><!-- /.main-content -->

<script src="../assets/js/bootstrap_buddle.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="../assets/js/dashboard_sidebar.js"></script>
<script>
(function () {
    const ctx = document.getElementById('salesChart').getContext('2d');
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?= json_encode($chartLabels) ?>,
            datasets: [{
                label: 'Revenue (&#8369;)',
                data: <?= json_encode($chartData) ?>,
                borderColor: '#667eea',
                backgroundColor: 'rgba(102,126,234,0.12)',
                tension: 0.4,
                fill: true,
                pointRadius: 5,
                pointBackgroundColor: '#667eea',
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: ctx => '₱' + ctx.parsed.y.toLocaleString('en-PH', {minimumFractionDigits:2,maximumFractionDigits:2})
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: v => '₱' + Number(v).toLocaleString('en-PH', {minimumFractionDigits:2,maximumFractionDigits:2})
                    },
                    grid: { color: 'rgba(0,0,0,0.04)' }
                },
                x: { grid: { display: false } }
            }
        }
    });
}());
</script>
</body>
</html>