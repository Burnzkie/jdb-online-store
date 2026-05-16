<?php
/**
 * customer/payment-success.php
 * Called by PayMongo after successful GCash/Maya payment.
 * Verifies the payment, creates the order, clears the cart.
 */

declare(strict_types=1);

session_start();

require_once '../classes/Env.php';
Env::load(__DIR__ . '/../.env');

require_once '../classes/Database.php';
require_once '../classes/User.php';
require_once '../classes/PaymentService.php';

if (!User::isLoggedIn()) {
    header("Location: ../login.php");
    exit;
}

define('MAX_INPUT_LENGTH', 1000);
define('ORDER_PREFIX',     'JDB-');

function generateUniqueOrderNumber(PDO $pdo): string {
    $prefix   = ORDER_PREFIX . date('ymd') . '-';
    for ($i = 0; $i < 10; $i++) {
        $number = $prefix . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        $stmt   = $pdo->prepare("SELECT 1 FROM orders WHERE order_number = ? LIMIT 1");
        $stmt->execute([$number]);
        if (!$stmt->fetch()) return $number;
    }
    throw new RuntimeException('Could not generate a unique order number.');
}

$userId  = (int)($_SESSION['user_id'] ?? 0);
$pending = $_SESSION['pending_order'] ?? null;

// ── Validate pending order exists and hasn't expired ──
if (!$pending || ($pending['expires'] ?? 0) < time() || ($pending['user_id'] ?? 0) !== $userId) {
    $_SESSION['flash'] = [
        'type'    => 'danger',
        'message' => 'Your payment session has expired. Please try again.',
    ];
    header("Location: checkout.php");
    exit;
}

$sessionId = $pending['payment_session_id'] ?? '';

// ── Verify payment with PayMongo ──────────────────
$verified = false;
try {
    $payService    = new PaymentService();
    $sessionData   = $payService->getCheckoutSession($sessionId);
    $sessionStatus = $sessionData['data']['attributes']['status'] ?? '';
    $verified      = ($sessionStatus === 'completed');
} catch (Exception $e) {
    error_log('payment-success.php: PayMongo verify error: ' . $e->getMessage());
}

if (!$verified) {
    // Payment not confirmed — clear pending, send back to checkout
    unset($_SESSION['pending_order']);
    $_SESSION['flash'] = [
        'type'    => 'danger',
        'message' => 'Payment could not be verified. Please choose a different payment method or use COD.',
    ];
    header("Location: checkout.php");
    exit;
}

// ── Payment confirmed — create the order ─────────
$pdo        = Database::getInstance()->getConnection();
$formData   = $pending['form_data'];
$cart       = $pending['cart'];
$validItems = $pending['items'];
$subtotal   = $pending['subtotal'];
$shipping   = $pending['shipping'];
$grandTotal = $pending['total'];

try {
    $pdo->beginTransaction();

    // Final stock check before writing
    $productIds   = array_column($validItems, 'product_id');
    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $stmt         = $pdo->prepare("SELECT id, stock, name FROM products WHERE id IN ($placeholders) FOR UPDATE");
    $stmt->execute($productIds);
    $stockMap = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $stockMap[$p['id']] = $p;
    }

    foreach ($validItems as $item) {
        $available = $stockMap[$item['product_id']]['stock'] ?? 0;
        if ($item['quantity'] > $available) {
            throw new RuntimeException("'{$item['name']}' is out of stock. Please contact support.");
        }
    }

    // Insert order
    $orderNumber    = generateUniqueOrderNumber($pdo);
    $hasDiscountCol = false;
    try {
        $colCheck       = $pdo->query("SHOW COLUMNS FROM orders LIKE 'discount_amount'");
        $hasDiscountCol = ($colCheck->rowCount() > 0);
    } catch (Exception $e) { $hasDiscountCol = false; }

    if ($hasDiscountCol) {
        $sql = "INSERT INTO orders (user_id, order_number, customer_name, customer_email,
                    customer_phone, total_amount, discount_amount, shipping_amount,
                    status, payment_method, payment_status, payment_session_id,
                    shipping_address, billing_address, notes, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 0.00, ?, 'processing', ?, 'paid', ?, ?, ?, ?, NOW())";
        $params = [$userId, $orderNumber, $formData['full_name'], $formData['email'],
                   $formData['phone'], $subtotal, $shipping, $formData['payment'],
                   $sessionId, $formData['shipping_address'], $formData['shipping_address'],
                   $formData['notes'] ?: null];
    } else {
        $sql = "INSERT INTO orders (user_id, order_number, customer_name, customer_email,
                    customer_phone, total_amount, shipping_amount,
                    status, payment_method, payment_status, payment_session_id,
                    shipping_address, billing_address, notes, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'processing', ?, 'paid', ?, ?, ?, ?, NOW())";
        $params = [$userId, $orderNumber, $formData['full_name'], $formData['email'],
                   $formData['phone'], $subtotal, $shipping, $formData['payment'],
                   $sessionId, $formData['shipping_address'], $formData['shipping_address'],
                   $formData['notes'] ?: null];
    }

    $pdo->prepare($sql)->execute($params);
    $orderId = (int)$pdo->lastInsertId();
    if (!$orderId) throw new RuntimeException('Failed to create order record.');

    // Insert order items
    $stmtItem = $pdo->prepare("INSERT INTO order_items (order_id, product_id, quantity, price, created_at) VALUES (?, ?, ?, ?, NOW())");
    foreach ($validItems as $item) {
        $stmtItem->execute([$orderId, $item['product_id'], $item['quantity'], $item['price']]);
    }

    // Decrement stock
    $stmtStock = $pdo->prepare("UPDATE products SET stock = stock - ?, updated_at = NOW() WHERE id = ? AND stock >= ?");
    foreach ($validItems as $item) {
        $stmtStock->execute([$item['quantity'], $item['product_id'], $item['quantity']]);
        if ($stmtStock->rowCount() === 0) {
            throw new RuntimeException("Stock update failed for '{$item['name']}'.");
        }
    }

    $pdo->commit();

    // ── Remove ordered items from cart ────────────
    $orderedPids  = array_keys($cart);
    $placeholders = implode(',', array_fill(0, count($orderedPids), '?'));
    $pdo->prepare("DELETE FROM cart_items WHERE user_id = ? AND product_id IN ($placeholders)")
        ->execute(array_merge([$userId], $orderedPids));
    foreach ($orderedPids as $pid) unset($_SESSION['cart'][$pid]);
    if (empty($_SESSION['cart'])) unset($_SESSION['cart']);

    // ── Clear pending order ───────────────────────
    unset($_SESSION['pending_order']);

    // ── Send confirmation email ───────────────────
    try {
        require_once '../classes/EmailService.php';
        $emailService = new EmailService();
        $emailService->sendOrderConfirmation(
            $formData['email'], $formData['full_name'], $orderNumber,
            $grandTotal, $validItems, $formData['shipping_address'], $formData['payment']
        );
    } catch (Exception $e) {
        error_log("Order confirmation email failed: " . $e->getMessage());
    }

    error_log(json_encode(['message' => 'Order created after payment', 'order_id' => $orderId, 'order_number' => $orderNumber]));

    $_SESSION['flash'] = [
        'type'    => 'success',
        'message' => "Payment successful! Order <strong>" . htmlspecialchars($orderNumber) . "</strong> has been placed.",
    ];

    header("Location: order-confirmation.php?order_id=$orderId");
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("payment-success.php order creation failed: " . $e->getMessage());

    unset($_SESSION['pending_order']);
    $_SESSION['flash'] = [
        'type'    => 'danger',
        'message' => 'Your payment was received but we encountered an error creating your order. Please contact support immediately.',
    ];
    header("Location: orders.php");
    exit;
}