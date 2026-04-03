<?php
// admin/order-details.php
session_start();
require_once '../classes/Database.php';

if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin' || !isset($_GET['id'])) {
    session_destroy();
    header('Location: ../auth/login.php?error=access_denied');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$pdo     = Database::getInstance()->getConnection();
$orderId = (int)$_GET['id'];

// Fetch order + customer info
$stmt = $pdo->prepare("
    SELECT o.*,
           CONCAT(u.firstname, ' ', u.lastname) AS customer_fullname,
           u.email AS customer_email,
           u.phone AS customer_phone
    FROM orders o
    JOIN users u ON o.user_id = u.id
    WHERE o.id = ?
    LIMIT 1
");
$stmt->execute([$orderId]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    header('Location: orders.php');
    exit;
}

// Fetch order items
$items = [];
try {
    $s = $pdo->prepare("
        SELECT oi.*, p.name AS product_name, p.image AS product_image
        FROM order_items oi
        LEFT JOIN products p ON oi.product_id = p.id
        WHERE oi.order_id = ?
        ORDER BY oi.id
    ");
    $s->execute([$orderId]);
    $items = $s->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Handle inline status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    if (hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $upd = $pdo->prepare("UPDATE orders SET status = ? WHERE id = ?");
        $upd->execute([$_POST['status'] ?? $order['status'], $orderId]);
    }
    header('Location: order-details.php?id=' . $orderId . '&msg=' . urlencode('Order status updated.'));
    exit;
}

$subtotal   = (float)($order['total_amount'] ?? $order['total'] ?? 0);
$shipping   = (float)($order['shipping_amount'] ?? 0);
$grandTotal = $subtotal + $shipping;

$user_name = $_SESSION['user_name'] ?? 'Admin';
$avatarUrl = 'https://ui-avatars.com/api/?name=' . urlencode($user_name) . '&background=667eea&color=fff';
$currentPage = 'orders';

$statusColors = [
    'pending'    => 'warning',
    'processing' => 'info',
    'shipped'    => 'primary',
    'delivered'  => 'success',
    'cancelled'  => 'secondary',
    'refunded'   => 'danger',
];
$validStatuses = array_keys($statusColors);
$status        = strtolower($order['status'] ?? 'pending');
$statusColor   = $statusColors[$status] ?? 'secondary';

$payColors = ['paid' => 'success', 'pending' => 'warning', 'failed' => 'danger'];
$payColor  = $payColors[strtolower($order['payment_status'] ?? '')] ?? 'secondary';

$message = urldecode($_GET['msg'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order <?= htmlspecialchars($order['order_number'] ?? '#' . $orderId) ?> – JDB Parts Admin</title>
    <link rel="stylesheet" href="../assets/css/bootstrap.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>

<?php require_once '_sidebar.php'; ?>

<div class="main-content" id="mainContent">

    <!-- Top Header -->
    <header class="top-header d-flex align-items-center justify-content-between flex-wrap gap-3">
        <div class="d-flex align-items-center gap-3">
            <button class="btn btn-light p-2 d-lg-none" id="sidebarToggle"><i class="bi bi-list fs-4"></i></button>
            <button class="btn btn-light p-2 d-none d-lg-block" id="sidebarToggle"><i class="bi bi-layout-sidebar fs-5"></i></button>
            <div>
                <h5 class="mb-0 fw-semibold">Order Details</h5>
                <small class="text-muted">
                    <a href="orders.php" class="text-muted text-decoration-none">Orders</a>
                    <i class="bi bi-chevron-right mx-1" style="font-size:.7rem;"></i>
                    <?= htmlspecialchars($order['order_number'] ?? '#' . $orderId) ?>
                </small>
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
        <div class="alert alert-success alert-dismissible fade show mb-3">
            <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Back -->
    <div class="mb-4">
        <a href="orders.php" class="btn btn-light btn-sm">
            <i class="bi bi-arrow-left me-1"></i> Back to Orders
        </a>
    </div>

    <!-- Order Banner -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body d-flex align-items-center justify-content-between flex-wrap gap-3 p-4">
            <div class="d-flex align-items-center gap-3">
                <div class="rounded-circle bg-<?= $statusColor ?> bg-opacity-10 d-flex align-items-center justify-content-center"
                     style="width:54px;height:54px;flex-shrink:0;">
                    <i class="bi bi-bag-check fs-4 text-<?= $statusColor ?>"></i>
                </div>
                <div>
                    <h5 class="mb-0 fw-bold"><?= htmlspecialchars($order['order_number'] ?? '#' . $orderId) ?></h5>
                    <small class="text-muted">Placed on <?= date('F j, Y \a\t g:i A', strtotime($order['created_at'])) ?></small>
                </div>
            </div>
            <!-- Inline status update -->
            <form method="POST" class="d-flex align-items-center gap-2">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="update_status" value="1">
                <label class="text-muted small mb-0 me-1 fw-medium">Update Status:</label>
                <select name="status" class="form-select form-select-sm" style="min-width:140px;" onchange="this.form.submit()">
                    <?php foreach ($validStatuses as $s): ?>
                        <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
    </div>

    <div class="row g-4">

        <!-- LEFT: Order Items -->
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-bottom py-3">
                    <h6 class="mb-0 fw-semibold">
                        <i class="bi bi-list-ul me-2 text-primary"></i>Ordered Items
                        <span class="badge bg-light text-dark border ms-1"><?= count($items) ?></span>
                    </h6>
                </div>

                <?php if (empty($items)): ?>
                    <div class="card-body text-center text-muted py-5">
                        <i class="bi bi-inbox display-4 d-block mb-2"></i>
                        No items found for this order.
                    </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width:50%">Product</th>
                                <th>Unit Price</th>
                                <th>Qty</th>
                                <th class="text-end">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($items as $item):
                            $imgSrc      = $item['product_image'] ?? $item['image'] ?? '';
                            if ($imgSrc && !preg_match('#^(https?://|/|\.\.)#', $imgSrc)) {
                                $imgSrc = '../' . $imgSrc;
                            }
                            $productName = $item['product_name'] ?? $item['name'] ?? 'Unknown Product';
                            $lineTotal   = (float)$item['price'] * (int)$item['quantity'];
                        ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-3">
                                    <?php if ($imgSrc): ?>
                                        <img src="<?= htmlspecialchars($imgSrc) ?>"
                                             width="52" height="52"
                                             class="rounded border object-fit-cover flex-shrink-0"
                                             alt="<?= htmlspecialchars($productName) ?>"
                                             onerror="this.style.display='none'">
                                    <?php else: ?>
                                        <div class="rounded border bg-light d-flex align-items-center justify-content-center flex-shrink-0"
                                             style="width:52px;height:52px;">
                                            <i class="bi bi-box text-muted"></i>
                                        </div>
                                    <?php endif; ?>
                                    <div>
                                        <div class="fw-semibold"><?= htmlspecialchars($productName) ?></div>
                                        <?php if (!empty($item['product_id'])): ?>
                                            <small class="text-muted">Product ID: <?= (int)$item['product_id'] ?></small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td>&#8369;<?= number_format((float)$item['price'], 2) ?></td>
                            <td>
                                <span class="badge bg-light text-dark border px-2"><?= (int)$item['quantity'] ?></span>
                            </td>
                            <td class="text-end fw-semibold">&#8369;<?= number_format($lineTotal, 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Totals -->
                <div class="card-footer bg-white border-top py-3 px-4">
                    <div class="d-flex justify-content-end">
                        <div style="min-width:270px;">
                            <div class="d-flex justify-content-between text-muted small mb-1">
                                <span>Subtotal</span>
                                <span>&#8369;<?= number_format($subtotal, 2) ?></span>
                            </div>
                            <div class="d-flex justify-content-between text-muted small mb-2">
                                <span>Shipping Fee</span>
                                <span>&#8369;<?= number_format($shipping, 2) ?></span>
                            </div>
                            <div class="d-flex justify-content-between fw-bold border-top pt-2">
                                <span>Grand Total</span>
                                <span class="text-primary fs-6">&#8369;<?= number_format($grandTotal, 2) ?></span>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- RIGHT: Customer, Shipping, Payment -->
        <div class="col-lg-4">

            <!-- Customer -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white border-bottom py-3">
                    <h6 class="mb-0 fw-semibold"><i class="bi bi-person me-2 text-primary"></i>Customer</h6>
                </div>
                <div class="card-body">
                    <p class="fw-semibold mb-1"><?= htmlspecialchars($order['customer_fullname']) ?></p>
                    <p class="text-muted small mb-1">
                        <i class="bi bi-envelope me-1"></i><?= htmlspecialchars($order['customer_email']) ?>
                    </p>
                    <?php if (!empty($order['customer_phone'])): ?>
                        <p class="text-muted small mb-0">
                            <i class="bi bi-phone me-1"></i><?= htmlspecialchars($order['customer_phone']) ?>
                        </p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Shipping -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white border-bottom py-3">
                    <h6 class="mb-0 fw-semibold"><i class="bi bi-truck me-2 text-primary"></i>Shipping Address</h6>
                </div>
                <div class="card-body">
                    <p class="small mb-0"><?= nl2br(htmlspecialchars($order['shipping_address'] ?? 'N/A')) ?></p>
                </div>
            </div>

            <!-- Payment -->
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-bottom py-3">
                    <h6 class="mb-0 fw-semibold"><i class="bi bi-credit-card me-2 text-primary"></i>Payment Info</h6>
                </div>
                <div class="card-body">
                    <div class="d-flex justify-content-between small mb-2">
                        <span class="text-muted">Method</span>
                        <span class="fw-medium"><?= ucfirst(htmlspecialchars($order['payment_method'] ?? 'COD')) ?></span>
                    </div>
                    <div class="d-flex justify-content-between small mb-2">
                        <span class="text-muted">Payment Status</span>
                        <span class="badge bg-<?= $payColor ?>"><?= ucfirst($order['payment_status'] ?? 'pending') ?></span>
                    </div>
                    <div class="d-flex justify-content-between small">
                        <span class="text-muted">Order Status</span>
                        <span class="badge bg-<?= $statusColor ?>"><?= ucfirst($status) ?></span>
                    </div>
                    <?php if (!empty($order['notes'])): ?>
                        <hr class="my-2">
                        <p class="text-muted mb-1 fw-semibold text-uppercase" style="font-size:.7rem;letter-spacing:.05em;">Order Notes</p>
                        <p class="small mb-0"><?= nl2br(htmlspecialchars($order['notes'])) ?></p>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>

</div>

<script src="../assets/js/bootstrap_buddle.js"></script>
<script src="../assets/js/dashboard_sidebar.js"></script>
</body>
</html>