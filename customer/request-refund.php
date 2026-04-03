<?php
/**
 * customer/request-refund.php
 * Submits a refund request for a cancelled or completed order.
 * Requires the order to have been paid and either cancelled or completed.
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
$reason  = trim(strip_tags($_POST['refund_reason'] ?? ''));
$method  = trim(strip_tags($_POST['refund_method'] ?? ''));
$details = trim(strip_tags($_POST['refund_details'] ?? ''));

if ($orderId <= 0) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Invalid order.'];
    header("Location: orders.php");
    exit;
}

if (empty($reason)) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Please provide a reason for your refund request.'];
    header("Location: order-details.php?id=$orderId");
    exit;
}

$allowedMethods = ['gcash', 'bank_transfer', 'store_credit', 'original'];
if (!in_array($method, $allowedMethods, true)) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Please select a valid refund method.'];
    header("Location: order-details.php?id=$orderId");
    exit;
}

if (strlen($reason)  > 1000) $reason  = substr($reason,  0, 1000);
if (strlen($details) > 1000) $details = substr($details, 0, 1000);

$userId = (int)$_SESSION['user_id'];
$pdo    = Database::getInstance()->getConnection();

try {
    $pdo->beginTransaction();

    // Fetch and lock the order
    $stmt = $pdo->prepare("
        SELECT id, status, payment_status, order_number, total_amount, shipping_amount, payment_method
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

    $status        = strtolower($order['status']);
    $paymentStatus = strtolower($order['payment_status']);

    // Only allow refund on cancelled (paid) or completed orders
    $refundableStatuses = ['cancelled', 'completed'];
    if (!in_array($status, $refundableStatuses, true)) {
        $pdo->rollBack();
        $_SESSION['flash'] = ['type' => 'warning', 'message' => 'Refunds can only be requested for cancelled or completed orders.'];
        header("Location: order-details.php?id=$orderId");
        exit;
    }

    // Must have been paid
    $paidStatuses = ['paid', 'refund_pending'];
    if (!in_array($paymentStatus, $paidStatuses, true)) {
        $pdo->rollBack();
        $_SESSION['flash'] = ['type' => 'warning', 'message' => 'No payment was recorded for this order, so no refund is applicable.'];
        header("Location: order-details.php?id=$orderId");
        exit;
    }

    // Prevent duplicate requests
    if ($paymentStatus === 'refund_pending') {
        $pdo->rollBack();
        $_SESSION['flash'] = ['type' => 'info', 'message' => 'A refund request is already pending for this order.'];
        header("Location: order-details.php?id=$orderId");
        exit;
    }

    $grandTotal = (float)$order['total_amount'] + (float)($order['shipping_amount'] ?? 0);

    $refundNote = implode("\n", array_filter([
        "--- Refund Request ---",
        "Reason: $reason",
        "Preferred Method: $method",
        $details ? "Details: $details" : '',
        "Amount: ₱" . number_format($grandTotal, 2),
        "Requested: " . date('Y-m-d H:i:s'),
        "--- End Refund Request ---",
    ]));

    // Check if refund_requests table exists, use it if so; otherwise append to order notes
    $hasRefundTable = false;
    try {
        $check = $pdo->query("SHOW TABLES LIKE 'refund_requests'");
        $hasRefundTable = ($check->rowCount() > 0);
    } catch (Exception $e) {
        $hasRefundTable = false;
    }

    if ($hasRefundTable) {
        $insert = $pdo->prepare("
            INSERT INTO refund_requests
                (order_id, user_id, reason, refund_method, refund_details, amount, status, created_at)
            VALUES
                (?, ?, ?, ?, ?, ?, 'pending', NOW())
        ");
        $insert->execute([$orderId, $userId, $reason, $method, $details, $grandTotal]);
    }

    // Always update the order's payment_status and append a note
    $update = $pdo->prepare("
        UPDATE orders
        SET payment_status = 'refund_pending',
            notes          = CONCAT(COALESCE(notes, ''), '\n\n', ?),
            updated_at     = NOW()
        WHERE id = ?
    ");
    $update->execute([$refundNote, $orderId]);

    $pdo->commit();

    $_SESSION['flash'] = [
        'type'    => 'success',
        'message' => "Refund request for order <strong>{$order['order_number']}</strong> submitted. Our team will review it within 3–5 business days.",
    ];

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("request-refund.php error: " . $e->getMessage());
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Unable to submit refund request. Please try again or contact support.'];
}

header("Location: order-details.php?id=$orderId");
exit;