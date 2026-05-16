<?php
/**
 * customer/process-order.php
 * Handles order creation with security, validation, and race-condition protection.
 *
 * GCash/Maya flow: validate → save pending_order to session → redirect to PayMongo
 *                  (order is created ONLY after payment is confirmed in payment-success.php)
 * COD/Bank flow:   validate → create order immediately → redirect to confirmation
 */

declare(strict_types=1);

session_start();

if (!isset($_SESSION['initiated'])) {
    session_regenerate_id(true);
    $_SESSION['initiated'] = true;
}

require_once '../classes/Env.php';
Env::load(__DIR__ . '/../.env');

require_once '../classes/Database.php';
require_once '../classes/CartService.php';
require_once '../classes/ShippingService.php';
require_once '../classes/Product.php';
require_once '../classes/User.php';

define('MAX_INPUT_LENGTH', 1000);
define('ORDER_PREFIX',     'JDB-');

// ── Auth ─────────────────────────────────────────
if (!User::isLoggedIn() || $_SESSION['user_role'] !== 'customer') {
    header("Location: ../login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['flash'] = ['type' => 'warning', 'message' => 'Invalid request method.'];
    header("Location: cart.php");
    exit;
}

// ── CSRF ─────────────────────────────────────────
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Security validation failed. Please try again.'];
    header("Location: checkout.php");
    exit;
}

// ── Rate Limiting ─────────────────────────────────
$userId       = (int)($_SESSION['user_id'] ?? 0);
$rateLimitKey = 'order_submit_' . $userId;

if (function_exists('apcu_fetch')) {
    $attempts = apcu_fetch($rateLimitKey);
    if ($attempts === false) $attempts = 0;
    if ($attempts >= 5) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Too many order attempts. Please wait a few minutes.'];
        header("Location: checkout.php");
        exit;
    }
    apcu_store($rateLimitKey, $attempts + 1, 300);
}

if (!$userId) {
    header("Location: ../login.php?error=session_expired");
    exit;
}

$pdo = Database::getInstance()->getConnection();

// ── Helper Functions ──────────────────────────────
function sanitizeInput(string $input, int $maxLength = MAX_INPUT_LENGTH): string {
    $input = trim($input);
    $input = strip_tags($input);
    if (strlen($input) > $maxLength) {
        throw new InvalidArgumentException('Input exceeds maximum allowed length.');
    }
    return $input;
}

function logOrderError(string $message, array $context = []): void {
    error_log(json_encode([
        'timestamp' => date('Y-m-d H:i:s'),
        'message'   => $message,
        'context'   => $context,
        'user_id'   => $_SESSION['user_id'] ?? null,
    ]));
}

function redirectWithError(string $message, string $location = 'checkout.php'): never {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => $message];
    header("Location: $location");
    exit;
}

function generateUniqueOrderNumber(PDO $pdo, int $userId): string {
    $prefix   = ORDER_PREFIX . date('ymd') . '-';
    $maxTries = 10;
    for ($i = 0; $i < $maxTries; $i++) {
        $number = $prefix . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        $stmt   = $pdo->prepare("SELECT 1 FROM orders WHERE order_number = ? LIMIT 1");
        $stmt->execute([$number]);
        if (!$stmt->fetch()) return $number;
    }
    throw new RuntimeException('Could not generate a unique order number after multiple attempts.');
}

// ── 1. Load Cart from DB ──────────────────────────
$cartService = new CartService($pdo);
$dbItems     = $cartService->getCartItems();

$cart = [];
foreach ($dbItems as $row) {
    $cart[(int)$row['product_id']] = (int)$row['quantity'];
}
$_SESSION['cart'] = $cart;

if (empty($cart)) {
    redirectWithError('Your cart is empty.', 'cart.php');
}

// ── Resolve selected items + qty overrides ────────
$selectedItems = [];
if (!empty($_POST['selected_items'])) {
    $selectedItems = array_values(array_filter(
        array_map('intval', explode(',', $_POST['selected_items']))
    ));
}

$qtyOverrides = [];
if (!empty($_POST['selected_qtys']) && !empty($selectedItems)) {
    $rawQtys = array_map('intval', explode(',', $_POST['selected_qtys']));
    foreach ($selectedItems as $idx => $pid) {
        $qtyOverrides[$pid] = isset($rawQtys[$idx]) ? max(1, $rawQtys[$idx]) : 1;
    }
}

if (!empty($selectedItems)) {
    $workingCart = [];
    foreach ($selectedItems as $pid) {
        if (array_key_exists($pid, $cart)) {
            $workingCart[$pid] = $qtyOverrides[$pid] ?? $cart[$pid];
        }
    }
    if (empty($workingCart)) {
        redirectWithError('None of the selected items were found in your cart.', 'cart.php');
    }
    $cart = $workingCart;
}

try {
    $pdo->beginTransaction();

    // ── 2. Validate Stock ─────────────────────────
    $productIds   = array_keys($cart);
    $placeholders = implode(',', array_fill(0, count($productIds), '?'));

    $stmt = $pdo->prepare("
        SELECT id, price, sale_price, stock, name
        FROM products
        WHERE id IN ($placeholders)
        FOR UPDATE
    ");
    $stmt->execute($productIds);
    $productMap = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $productMap[$p['id']] = $p;
    }

    $validItems  = [];
    $subtotal    = 0.0;
    $stockIssues = [];

    foreach ($cart as $productId => $quantity) {
        $quantity = (int)$quantity;
        if (!isset($productMap[$productId]) || $quantity < 1) { unset($cart[$productId]); continue; }
        $product = $productMap[$productId];
        if ($quantity > $product['stock']) {
            $stockIssues[] = "{$product['name']} (only {$product['stock']} available)";
            unset($cart[$productId]);
            continue;
        }
        $price     = (float)($product['sale_price'] ?: $product['price']);
        $subtotal += $price * $quantity;
        $validItems[] = [
            'product_id' => (int)$productId,
            'quantity'   => $quantity,
            'price'      => $price,
            'name'       => $product['name'],
        ];
    }

    if (!empty($stockIssues)) {
        $pdo->rollBack();
        redirectWithError('Stock unavailable for: ' . implode(', ', $stockIssues));
    }
    if (empty($validItems)) {
        $pdo->rollBack();
        redirectWithError('No valid items remain in your cart.', 'cart.php');
    }

    // ── 3. Validate Form Input ────────────────────
    $formData = [];
    try {
        foreach (['firstname', 'lastname', 'email', 'phone', 'shipping_address'] as $field) {
            if (empty(trim($_POST[$field] ?? ''))) {
                throw new InvalidArgumentException("The field '$field' is required.");
            }
            $formData[$field] = sanitizeInput($_POST[$field]);
        }
        if (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Please enter a valid email address.');
        }
        $rawPhone = trim($_POST['phone'] ?? '');
        if (!preg_match('/^[0-9\s\-\+\(\)]{7,20}$/', $rawPhone)) {
            throw new InvalidArgumentException('Please enter a valid phone number (7–20 digits).');
        }
        $formData['full_name'] = trim($formData['firstname'] . ' ' . $formData['lastname']);
        $formData['notes']     = isset($_POST['notes']) ? sanitizeInput($_POST['notes'], 2000) : '';
        $allowedPayments       = ['cod', 'gcash', 'maya', 'bank'];
        $formData['payment']   = in_array($_POST['payment_method'] ?? '', $allowedPayments)
            ? $_POST['payment_method'] : 'cod';
    } catch (InvalidArgumentException $e) {
        $pdo->rollBack();
        redirectWithError($e->getMessage());
    }

    $province     = trim($_POST['province'] ?? '');
    $shippingCost = ShippingService::calculate($province);
    $grandTotal   = $subtotal + $shippingCost;

    // ════════════════════════════════════════════════════════════════
    // GCASH / MAYA — Pay FIRST, create order AFTER payment is confirmed
    // ════════════════════════════════════════════════════════════════
    if (in_array($formData['payment'], ['gcash', 'maya'], true)) {
        $pdo->rollBack(); // release the FOR UPDATE lock — no DB writes yet

        require_once '../classes/PaymentService.php';
        $paymentService = new PaymentService();

        if (!$paymentService->isConfigured()) {
            redirectWithError('Online payment is not configured. Please choose COD.');
        }

        // Save everything needed to create the order after payment succeeds
        $token = bin2hex(random_bytes(16));
        $_SESSION['pending_order'] = [
            'token'     => $token,
            'user_id'   => $userId,
            'form_data' => $formData,
            'cart'      => $cart,
            'items'     => $validItems,
            'subtotal'  => $subtotal,
            'shipping'  => $shippingCost,
            'total'     => $grandTotal,
            'province'  => $province,
            'expires'   => time() + 3600,
        ];

        $method    = $formData['payment'] === 'gcash' ? 'createGcashPayment' : 'createMayaPayment';
        $payResult = $paymentService->$method(
            $grandTotal,
            $token,            // use token as reference (no order_id yet)
            'PENDING-' . strtoupper(substr($token, 0, 8)),
            $formData['email'],
            $formData['full_name'],
            $validItems
        );

        if ($payResult['success']) {
            // Store session_id in pending_order so payment-success.php can verify it
            $_SESSION['pending_order']['payment_session_id'] = $payResult['session_id'];
            header("Location: " . $payResult['checkout_url']);
            exit;
        }

        // PayMongo session creation failed — clear pending, let customer choose another method
        unset($_SESSION['pending_order']);
        $errMsg = $payResult['message'] ?? 'Could not initiate payment.';
        redirectWithError("Payment could not be initiated ($errMsg). Please choose COD or try again.");
    }

    // ════════════════════════════════════════════════════════════════
    // COD / BANK — Create order immediately
    // ════════════════════════════════════════════════════════════════
    $orderNumber    = generateUniqueOrderNumber($pdo, $userId);
    $hasDiscountCol = false;
    try {
        $colCheck       = $pdo->query("SHOW COLUMNS FROM orders LIKE 'discount_amount'");
        $hasDiscountCol = ($colCheck->rowCount() > 0);
    } catch (Exception $e) { $hasDiscountCol = false; }

    if ($hasDiscountCol) {
        $sql = "INSERT INTO orders (user_id, order_number, customer_name, customer_email,
                    customer_phone, total_amount, discount_amount, shipping_amount,
                    status, payment_method, payment_status,
                    shipping_address, billing_address, notes, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 0.00, ?, 'pending', ?, 'pending', ?, ?, ?, NOW())";
        $params = [$userId, $orderNumber, $formData['full_name'], $formData['email'],
                   $formData['phone'], $subtotal, $shippingCost, $formData['payment'],
                   $formData['shipping_address'], $formData['shipping_address'], $formData['notes'] ?: null];
    } else {
        $sql = "INSERT INTO orders (user_id, order_number, customer_name, customer_email,
                    customer_phone, total_amount, shipping_amount,
                    status, payment_method, payment_status,
                    shipping_address, billing_address, notes, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?, 'pending', ?, ?, ?, NOW())";
        $params = [$userId, $orderNumber, $formData['full_name'], $formData['email'],
                   $formData['phone'], $subtotal, $shippingCost, $formData['payment'],
                   $formData['shipping_address'], $formData['shipping_address'], $formData['notes'] ?: null];
    }

    $pdo->prepare($sql)->execute($params);
    $orderId = (int)$pdo->lastInsertId();
    if (!$orderId) throw new RuntimeException('Failed to create order record.');

    $stmtItem = $pdo->prepare("INSERT INTO order_items (order_id, product_id, quantity, price, created_at) VALUES (?, ?, ?, ?, NOW())");
    foreach ($validItems as $item) {
        $stmtItem->execute([$orderId, $item['product_id'], $item['quantity'], $item['price']]);
    }

    $stmtStock = $pdo->prepare("UPDATE products SET stock = stock - ?, updated_at = NOW() WHERE id = ? AND stock >= ?");
    foreach ($validItems as $item) {
        $stmtStock->execute([$item['quantity'], $item['product_id'], $item['quantity']]);
        if ($stmtStock->rowCount() === 0) {
            throw new RuntimeException("Stock update failed for product ID {$item['product_id']}.");
        }
    }

    $pdo->commit();

    // Clear any leftover pending_order from a previously failed payment
    unset($_SESSION['pending_order']);

    // Remove ordered items from cart
    $orderedPids  = array_keys($cart);
    $placeholders = implode(',', array_fill(0, count($orderedPids), '?'));
    $pdo->prepare("DELETE FROM cart_items WHERE user_id = ? AND product_id IN ($placeholders)")
        ->execute(array_merge([$userId], $orderedPids));
    foreach ($orderedPids as $pid) unset($_SESSION['cart'][$pid]);
    if (empty($_SESSION['cart'])) unset($_SESSION['cart']);

    if (function_exists('apcu_delete')) apcu_delete($rateLimitKey);

    // Send confirmation email
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

    logOrderError('Order created successfully', ['order_id' => $orderId, 'order_number' => $orderNumber]);

    $_SESSION['flash'] = [
        'type'    => 'success',
        'message' => "Order <strong>" . htmlspecialchars($orderNumber) . "</strong> placed successfully.",
    ];

    header("Location: order-confirmation.php?order_id=$orderId");
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    logOrderError('Order processing failed', ['error' => $e->getMessage()]);
    $devMode  = true;
    $errorMsg = $devMode ? 'Order failed: ' . $e->getMessage() : 'Unable to process your order. Please try again or contact support.';
    redirectWithError($errorMsg);
}