<?php
session_start();
require_once '../classes/Database.php';
require_once '../classes/Order.php';

if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    session_destroy();
    header('Location: ../auth/login.php?error=access_denied');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$pdo       = Database::getInstance()->getConnection();
$orderObj  = new Order($pdo);
$user_name = $_SESSION['user_name'] ?? 'Admin';
$avatarUrl = 'https://ui-avatars.com/api/?name=' . urlencode($user_name) . '&background=667eea&color=fff';

// ── Status update ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    if (hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $newStatus = $_POST['status'] ?? '';
        $orderId   = (int)$_POST['order_id'];

        $orderObj->updateStatus($orderId, $newStatus);

        // Send shipping email INSIDE the POST block where variables are defined
        if ($newStatus === 'shipped') {
            try {
                require_once '../classes/EmailService.php';
                $emailService = new EmailService();
                $orderData    = $orderObj->getOrderById($orderId);
                if ($orderData) {
                    $emailService->sendShippingUpdate(
                        $orderData['customer_email'] ?? $orderData['user_email'] ?? '',
                        $orderData['customer_name']  ?? $orderData['user_fullname'] ?? '',
                        $orderData['order_number'],
                        'shipped'
                    );
                }
            } catch (Exception $e) {
                error_log('Shipping email failed: ' . $e->getMessage());
            }
        }
    }
    header('Location: orders.php?msg=' . urlencode('Order status updated.'));
    exit;
}

$message   = urldecode($_GET['msg'] ?? '');
$allOrders = $orderObj->getAllOrders();

$statusColors = [
    'pending'    => 'warning text-dark',
    'processing' => 'info text-dark',
    'shipped'    => 'primary',
    'delivered'  => 'success',    // fixed: was missing
    'completed'  => 'success',
    'cancelled'  => 'secondary',
    'refunded'   => 'danger',
];
$validStatuses = array_keys($statusColors); // fixed: now includes 'delivered'
$currentPage   = 'orders';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Orders – JDB Parts Admin</title>
    <link rel="stylesheet" href="../assets/css/bootstrap.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>

<?php require_once '_sidebar.php'; ?>

<div class="main-content" id="mainContent">

    <header class="top-header d-flex align-items-center justify-content-between flex-wrap gap-3">
        <div class="d-flex align-items-center gap-3">
            <button class="btn btn-light p-2 d-lg-none" id="sidebarToggle"><i class="bi bi-list fs-4"></i></button>
            <button class="btn btn-light p-2 d-none d-lg-block" id="sidebarToggle"><i class="bi bi-layout-sidebar fs-5"></i></button>
            <div>
                <h5 class="mb-0 fw-semibold">Orders</h5>
                <small class="text-muted">Manage and track customer orders</small>
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

    <?php if ($message): ?>
        <div class="alert alert-success alert-dismissible fade show mb-4">
            <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="text-muted mb-0"><?= count($allOrders) ?> order<?= count($allOrders) !== 1 ? 's' : '' ?> total</h6>
    </div>

    <?php if (empty($allOrders)): ?>
        <div class="text-center py-5 chart-card">
            <i class="bi bi-cart display-1 text-muted"></i>
            <h5 class="mt-3 text-muted">No orders yet</h5>
        </div>
    <?php else: ?>
        <div class="card shadow-sm border-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Order #</th>
                            <th>Customer</th>
                            <th>Date</th>
                            <th>Total</th>
                            <th>Payment</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allOrders as $o):
                            $customer = $o['customer_name'] ?? $o['user_fullname'] ?? 'Guest';
                            $email    = $o['customer_email'] ?? $o['user_email'] ?? '';
                            $payColors = [
                                'paid'           => 'success',
                                'pending'        => 'warning text-dark',
                                'failed'         => 'danger',
                                'refund_pending' => 'warning text-dark',
                                'cancelled'      => 'secondary',
                            ];
                            $payColor = $payColors[$o['payment_status'] ?? ''] ?? 'secondary';
                            $oStatus  = strtolower($o['status'] ?? 'pending');
                            $oColor   = $statusColors[$oStatus] ?? 'secondary';
                        ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($o['order_number'] ?? '#' . $o['id']) ?></strong></td>
                            <td>
                                <?= htmlspecialchars($customer) ?>
                                <?php if ($email): ?>
                                    <br><small class="text-muted"><?= htmlspecialchars($email) ?></small>
                                <?php endif; ?>
                            </td>
                            <td class="small text-muted"><?= date('d M Y', strtotime($o['created_at'])) ?></td>
                            <td>&#8369;<?= number_format($o['total'], 2) ?></td>
                            <td>
                                <span class="badge bg-<?= $payColor ?>">
                                    <?= ucfirst(str_replace('_', ' ', $o['payment_status'] ?? 'pending')) ?>
                                </span>
                            </td>
                            <td>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                    <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
                                    <input type="hidden" name="update_status" value="1">
                                    <select name="status" class="form-select form-select-sm"
                                            style="min-width:130px;"
                                            onchange="this.form.submit()">
                                        <?php foreach ($validStatuses as $s): ?>
                                            <option value="<?= $s ?>" <?= $oStatus === $s ? 'selected' : '' ?>>
                                                <?= ucfirst($s) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                            </td>
                            <td>
                                <a href="order-details.php?id=<?= $o['id'] ?>"
                                   class="btn btn-sm btn-outline-primary" title="View details">
                                    <i class="bi bi-eye"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

</div>

<script src="../assets/js/bootstrap_buddle.js"></script>
<script src="../assets/js/dashboard_sidebar.js"></script>
</body>
</html>