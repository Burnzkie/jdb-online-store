<?php
/**
 * customer/cancel-order.php
 * Cancels a pending/processing order and restores stock.
 * Only the order owner can cancel, and only within the allowed window.
 */

declare(strict_types=1);

session_start();

require_once '../classes/Database.php';
require_once '../classes/User.php';

// ── Auth ─────────────────────────────────────────
if (!User::isLoggedIn() || $_SESSION['user_role'] !== 'customer') {
    header("Location: ../login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: orders.php");
    exit;
}

// ── CSRF ─────────────────────────────────────────
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Security validation failed. Please try again.'];
    header("Location: orders.php");
    exit;
}

$orderId = (int)($_POST['order_id'] ?? 0);
$reason  = trim(strip_tags($_POST['cancel_reason'] ?? ''));

if ($orderId <= 0) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Invalid order.'];
    header("Location: orders.php");
    exit;
}

if (empty($reason)) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Please provide a reason for cancellation.'];
    header("Location: order-details.php?id=$orderId");
    exit;
}

if (strlen($reason) > 1000) {
    $reason = substr($reason, 0, 1000);
}

$userId = (int)$_SESSION['user_id'];
$pdo    = Database::getInstance()->getConnection();

try {
    $pdo->beginTransaction();

    // Fetch and lock the order row
    $stmt = $pdo->prepare("
        SELECT id, status, payment_status, payment_method, order_number, total_amount, shipping_amount
        FROM orders
        WHERE id = ? AND user_id = ?
        FOR UPDATE
    ");
    $stmt->execute([$orderId, $userId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        $pdo->rollBack();
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Order not found or access denied.'];
        header("Location: orders.php");
        exit;
    }

    $cancellableStatuses = ['pending', 'processing'];
    if (!in_array(strtolower($order['status']), $cancellableStatuses, true)) {
        $pdo->rollBack();
        $_SESSION['flash'] = ['type' => 'warning', 'message' => 'This order cannot be cancelled — it may already be shipped or completed.'];
        header("Location: order-details.php?id=$orderId");
        exit;
    }

    // Determine refund eligibility
    // COD orders that are still pending have NOT been paid yet → no refund needed
    // GCash / bank orders in any cancellable state may have been paid → flag for refund
    $isPaid            = strtolower($order['payment_status']) === 'paid';
    $isNonCodPayment   = in_array(strtolower($order['payment_method']), ['gcash', 'bank'], true);
    $refundNeeded      = $isPaid || $isNonCodPayment;

    $newPaymentStatus  = $refundNeeded ? 'refund_pending' : 'cancelled';
    $cancellationNotes = "Cancelled by customer. Reason: $reason";

    // Update order status
    $update = $pdo->prepare("
        UPDATE orders
        SET status          = 'cancelled',
            payment_status  = ?,
            notes           = CONCAT(COALESCE(notes, ''), '\n\n', ?),
            updated_at      = NOW()
        WHERE id = ?
    ");
    $update->execute([$newPaymentStatus, $cancellationNotes, $orderId]);

    // Restore stock for each item
    $itemsStmt = $pdo->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = ?");
    $itemsStmt->execute([$orderId]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    $restoreStmt = $pdo->prepare("
        UPDATE products
        SET stock      = stock + ?,
            updated_at = NOW()
        WHERE id = ?
    ");
    foreach ($items as $item) {
        $restoreStmt->execute([$item['quantity'], $item['product_id']]);
    }

    $pdo->commit();

    if ($refundNeeded) {
        $_SESSION['flash'] = [
            'type'    => 'info',
            'message' => "Order <strong>{$order['order_number']}</strong> cancelled. A refund request has been submitted and will be reviewed by our team.",
        ];
    } else {
        $_SESSION['flash'] = [
            'type'    => 'success',
            'message' => "Order <strong>{$order['order_number']}</strong> has been cancelled successfully.",
        ];
    }

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("cancel-order.php error: " . $e->getMessage());
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Unable to cancel order. Please try again or contact support.'];
}

header("Location: order-details.php?id=$orderId");
exit;