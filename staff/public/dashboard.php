<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../includes/header.php';

// Fetch once, reuse across the page
$allOrders      = $orderModel->getAllOrders();
$recentOrders   = array_slice($allOrders, 0, 5);
$totalOrders    = count($allOrders);
$totalProducts  = $productModel->getTotalProducts();
$totalCustomers = count($customerModel->getAllCustomers());

$statusColors = [
    'completed'  => 'success',
    'processing' => 'primary',
    'shipped'    => 'info',
    'cancelled'  => 'danger',
    'pending'    => 'warning',
];
?>

<h2><i class="bi bi-speedometer2"></i> Staff Dashboard</h2>
<hr>

<!-- Summary Cards -->
<div class="row text-center">
    <div class="col-md-4 mb-4">
        <div class="card bg-primary text-white shadow-sm">
            <div class="card-body py-4">
                <i class="bi bi-cart-check fs-2 mb-2 d-block"></i>
                <h5>Total Orders</h5>
                <h3 class="fw-bold"><?= $totalOrders ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-4 mb-4">
        <div class="card bg-success text-white shadow-sm">
            <div class="card-body py-4">
                <i class="bi bi-box-seam fs-2 mb-2 d-block"></i>
                <h5>Total Products</h5>
                <h3 class="fw-bold"><?= $totalProducts ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-4 mb-4">
        <div class="card bg-info text-white shadow-sm">
            <div class="card-body py-4">
                <i class="bi bi-people fs-2 mb-2 d-block"></i>
                <h5>Active Customers</h5>
                <h3 class="fw-bold"><?= $totalCustomers ?></h3>
            </div>
        </div>
    </div>
</div>

<!-- Recent Orders -->
<div class="mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0">Recent Orders</h4>
        <a href="orders.php" class="btn btn-sm btn-outline-primary">View All</a>
    </div>

    <div class="table-responsive">
        <table class="table table-striped align-middle">
            <thead class="table-dark">
                <tr>
                    <th>Order #</th>
                    <th>Customer</th>
                    <th>Total</th>
                    <th>Status</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recentOrders)): ?>
                <tr>
                    <td colspan="5" class="text-center text-muted py-4">No orders yet.</td>
                </tr>
                <?php else: foreach ($recentOrders as $order):
                    $statusColor = $statusColors[$order['status']] ?? 'secondary';
                ?>
                <tr>
                    <td><strong>#<?= htmlspecialchars($order['order_number'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                    <td><?= htmlspecialchars($order['customer_name'] ?: ($order['user_fullname'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                    <td>&#8369;<?= number_format((float)$order['total'], 2) ?></td>
                    <td>
                        <span class="badge bg-<?= $statusColor ?>">
                            <?= ucfirst(htmlspecialchars($order['status'], ENT_QUOTES, 'UTF-8')) ?>
                        </span>
                    </td>
                    <td><?= date('M d, Y', strtotime($order['created_at'])) ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>