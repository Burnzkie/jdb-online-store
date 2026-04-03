<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../includes/header.php';

// ── Handle status update (POST) ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['status'], $_POST['order_id'])) {
    Security::requireCsrfToken();

    $orderId = filter_var($_POST['order_id'], FILTER_VALIDATE_INT);
    $status  = $_POST['status'];

    if ($orderId !== false && $orderId > 0) {
        if ($orderModel->updateStatus($orderId, $status)) {
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Order status updated.'];
        } else {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Invalid status or update failed.'];
        }
    }

    // JS redirect because HTML is already sent by header.php
    echo '<script>window.location.replace("orders.php");</script>';
    exit;
}

$orders = $orderModel->getAllOrders();
$validStatuses = ['pending', 'processing', 'shipped', 'completed', 'cancelled'];
?>

<h2><i class="bi bi-cart"></i> Manage Orders</h2>
<hr>

<?php if (!empty($_SESSION['flash'])): ?>
    <div class="alert alert-<?= htmlspecialchars($_SESSION['flash']['type'], ENT_QUOTES, 'UTF-8') ?> alert-dismissible fade show" role="alert">
        <?= htmlspecialchars($_SESSION['flash']['message'], ENT_QUOTES, 'UTF-8') ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="table-responsive">
    <table class="table table-hover align-middle">
        <thead class="table-dark">
            <tr>
                <th>Order #</th>
                <th>Customer</th>
                <th>Email</th>
                <th>Total</th>
                <th>Status</th>
                <th>Payment</th>
                <th>Date</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($orders)): ?>
            <tr>
                <td colspan="8" class="text-center text-muted py-4">No orders found.</td>
            </tr>
            <?php else: foreach ($orders as $order): ?>
            <tr>
                <td><strong>#<?= htmlspecialchars($order['order_number'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                <td><?= htmlspecialchars($order['customer_name'] ?: ($order['user_fullname'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($order['customer_email'] ?: ($order['user_email'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                <td>&#8369;<?= number_format((float)$order['total'], 2) ?></td>
                <td>
                    <form action="orders.php" method="POST" class="d-inline">
                        <?= Security::csrfField() ?>
                        <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
                        <select name="status" onchange="this.form.submit()"
                                class="form-select form-select-sm" aria-label="Order status">
                            <?php foreach ($validStatuses as $s): ?>
                            <option value="<?= $s ?>" <?= $order['status'] === $s ? 'selected' : '' ?>>
                                <?= ucfirst($s) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </td>
                <td>
                    <span class="badge bg-<?= $order['payment_status'] === 'paid' ? 'success' : 'danger' ?>">
                        <?= ucfirst(htmlspecialchars($order['payment_status'], ENT_QUOTES, 'UTF-8')) ?>
                    </span>
                </td>
                <td><?= date('M d, Y', strtotime($order['created_at'])) ?></td>
                <td>
                    <a href="order-details.php?id=<?= (int)$order['id'] ?>" class="btn btn-sm btn-outline-info">
                        <i class="bi bi-eye"></i> View
                    </a>
                </td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<?php require_once '../includes/footer.php'; ?>