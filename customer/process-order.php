<?php
/**
 * customer/process-order.php
 * Handles order creation with security, validation, and race-condition protection.
 */

declare(strict_types=1);

session_start();

if (!isset($_SESSION['initiated'])) {
    session_regenerate_id(true);
    $_SESSION['initiated'] = true;
}

require_once '../classes/Database.php';
require_once '../classes/Product.php';
require_once '../classes/User.php';

// ── Constants ────────────────────────────────────
define('SHIPPING_COST',    150.00);
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
    logOrderError("CSRF token mismatch", ['user_id' => $_SESSION['user_id'] ?? 'unknown']);
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Security validation failed. Please try again.'];
    header("Location: checkout.php");
    exit;
}

// ── Rate Limiting (APCu with safe fallback) ──────
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

// ── Helper Functions ─────────────────────────────
/**
 * Sanitize input for DATABASE storage.
 * DO NOT use htmlspecialchars() here - that is for HTML output only.
 * PDO prepared statements handle SQL injection automatically.
 */
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

/**
 * FIX: Generate a unique order number and verify it doesn't already exist in the DB.
 */
function generateUniqueOrderNumber(PDO $pdo, int $userId): string {
    $prefix = ORDER_PREFIX . date('ymd') . '-';
    $maxTries = 10;

    for ($i = 0; $i < $maxTries; $i++) {
        $number = $prefix . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        $stmt = $pdo->prepare("SELECT 1 FROM orders WHERE order_number = ? LIMIT 1");
        $stmt->execute([$number]);
        if (!$stmt->fetch()) {
            return $number;  // unique — we're done
        }
    }

    throw new RuntimeException('Could not generate a unique order number after multiple attempts.');
}

// ── 1. Validate Cart ─────────────────────────────
$cart = $_SESSION['cart'] ?? [];

if (empty($cart)) {
    redirectWithError('Your cart is empty.', 'cart.php');
}

try {
    $pdo->beginTransaction();

    $productIds   = array_keys($cart);
    $placeholders = implode(',', array_fill(0, count($productIds), '?'));

    // Lock rows to prevent race conditions
    $stmt = $pdo->prepare("
        SELECT id, price, sale_price, stock, name
        FROM products
        WHERE id IN ($placeholders)
        FOR UPDATE
    ");
    $stmt->execute($productIds);
    $availableProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $productMap = [];
    foreach ($availableProducts as $p) {
        $productMap[$p['id']] = $p;
    }

    $validItems  = [];
    $subtotal    = 0.0;
    $stockIssues = [];

    foreach ($cart as $productId => $quantity) {
        $quantity = (int)$quantity;

        if (!isset($productMap[$productId])) {
            unset($_SESSION['cart'][$productId]);
            continue;
        }

        $product = $productMap[$productId];

        if ($quantity < 1) {
            unset($_SESSION['cart'][$productId]);
            continue;
        }

        if ($quantity > $product['stock']) {
            $stockIssues[] = "{$product['name']} (only {$product['stock']} available)";
            unset($_SESSION['cart'][$productId]);
            continue;
        }

        $price     = (float)($product['sale_price'] ?: $product['price']);
        $lineTotal = $price * $quantity;
        $subtotal += $lineTotal;

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
        unset($_SESSION['cart']);
        redirectWithError('No valid items remain in your cart.', 'cart.php');
    }

    // FIX: grandTotal is calculated correctly; total_amount stores subtotal only
    $grandTotal = $subtotal + SHIPPING_COST;

    // ── 2. Validate Form Input ───────────────────
    $requiredFields = ['firstname', 'lastname', 'email', 'phone', 'shipping_address'];
    $formData       = [];

    try {
        foreach ($requiredFields as $field) {
            if (empty(trim($_POST[$field] ?? ''))) {
                throw new InvalidArgumentException("The field '$field' is required.");
            }
            $formData[$field] = sanitizeInput($_POST[$field]);
        }

        if (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Please enter a valid email address.');
        }

        // Validate phone against the RAW value before sanitizeInput encodes special chars
        $rawPhone = trim($_POST['phone'] ?? '');
        if (!preg_match('/^[0-9\s\-\+\(\)]{7,20}$/', $rawPhone)) {
            throw new InvalidArgumentException('Please enter a valid phone number (7–20 digits).');
        }

        $formData['full_name'] = trim($formData['firstname'] . ' ' . $formData['lastname']);
        $formData['notes']     = isset($_POST['notes']) ? sanitizeInput($_POST['notes'], 2000) : '';

        $allowedPayments = ['cod', 'gcash', 'bank'];
        $formData['payment'] = in_array($_POST['payment_method'] ?? '', $allowedPayments)
            ? $_POST['payment_method']
            : 'cod';

    } catch (InvalidArgumentException $e) {
        $pdo->rollBack();
        logOrderError('Form validation failed', ['error' => $e->getMessage()]);
        redirectWithError($e->getMessage());
    }

    // ── 3. Create Order ──────────────────────────
    $orderNumber = generateUniqueOrderNumber($pdo, $userId);

    // Check whether the orders table has a discount_amount column
    $hasDiscountCol = false;
    try {
        $colCheck = $pdo->query("SHOW COLUMNS FROM orders LIKE 'discount_amount'");
        $hasDiscountCol = ($colCheck->rowCount() > 0);
    } catch (Exception $e) {
        $hasDiscountCol = false;
    }

    if ($hasDiscountCol) {
        $sql = "
            INSERT INTO orders (
                user_id, order_number, customer_name, customer_email,
                customer_phone, total_amount, discount_amount, shipping_amount,
                status, payment_method, payment_status,
                shipping_address, billing_address, notes, created_at
            ) VALUES (
                ?, ?, ?, ?,
                ?, ?, 0.00, ?,
                'pending', ?, 'pending',
                ?, ?, ?, NOW()
            )
        ";
        $params = [
            $userId, $orderNumber, $formData['full_name'], $formData['email'],
            $formData['phone'], $subtotal, SHIPPING_COST,
            $formData['payment'],
            $formData['shipping_address'], $formData['shipping_address'],
            $formData['notes'] ?: null,
        ];
    } else {
        $sql = "
            INSERT INTO orders (
                user_id, order_number, customer_name, customer_email,
                customer_phone, total_amount, shipping_amount,
                status, payment_method, payment_status,
                shipping_address, billing_address, notes, created_at
            ) VALUES (
                ?, ?, ?, ?,
                ?, ?, ?,
                'pending', ?, 'pending',
                ?, ?, ?, NOW()
            )
        ";
        $params = [
            $userId, $orderNumber, $formData['full_name'], $formData['email'],
            $formData['phone'], $subtotal, SHIPPING_COST,
            $formData['payment'],
            $formData['shipping_address'], $formData['shipping_address'],
            $formData['notes'] ?: null,
        ];
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $orderId = (int)$pdo->lastInsertId();
    if (!$orderId) {
        throw new RuntimeException('Failed to create order record.');
    }

    // Insert order items
    $stmtItem = $pdo->prepare("
        INSERT INTO order_items (order_id, product_id, quantity, price, created_at)
        VALUES (?, ?, ?, ?, NOW())
    ");
    foreach ($validItems as $item) {
        $stmtItem->execute([$orderId, $item['product_id'], $item['quantity'], $item['price']]);
    }

    // Decrement stock (final safety check with rowCount)
    $stmtStock = $pdo->prepare("
        UPDATE products
        SET stock = stock - ?, updated_at = NOW()
        WHERE id = ? AND stock >= ?
    ");
    foreach ($validItems as $item) {
        $stmtStock->execute([$item['quantity'], $item['product_id'], $item['quantity']]);
        if ($stmtStock->rowCount() === 0) {
            throw new RuntimeException("Stock update failed for product ID {$item['product_id']}.");
        }
    }

    $pdo->commit();

    // Clear cart
    unset($_SESSION['cart']);

    // Clear rate-limit counter on success
    if (function_exists('apcu_delete')) {
        apcu_delete($rateLimitKey);
    }

    logOrderError('Order created successfully', [
        'order_id'     => $orderId,
        'order_number' => $orderNumber,
        'subtotal'     => $subtotal,
        'grand_total'  => $grandTotal,
    ]);

    $_SESSION['flash'] = [
        'type'    => 'success',
        'message' => "Order <strong>" . htmlspecialchars($orderNumber) . "</strong> placed successfully.",
    ];

    header("Location: order-confirmation.php?order_id=$orderId");
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    logOrderError('Order processing failed', ['error' => $e->getMessage()]);

    // DEV MODE: show the real error so you can debug it.
    // Remove or set to false when going live.
    $devMode = true;
    $errorMsg = $devMode
        ? 'Order failed: ' . $e->getMessage()
        : 'Unable to process your order. Please try again or contact support.';

    redirectWithError($errorMsg);
}