<?php
// customer/checkout.php
declare(strict_types=1);
session_start();

if (!isset($_SESSION['initiated'])) {
    session_regenerate_id(true);
    $_SESSION['initiated'] = true;
}

require_once '../classes/Database.php';
require_once '../classes/CartService.php';
require_once '../classes/User.php';

if (!User::isLoggedIn() || $_SESSION['user_role'] !== 'customer') {
    header("Location: ../login.php");
    exit;
}

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Shipping cost — single definition here and in process-order.php
if (!defined('CHECKOUT_SHIPPING')) {
    define('CHECKOUT_SHIPPING', 150.00);
}

$pdo         = Database::getInstance()->getConnection();
$cartService = new CartService($pdo);

// Redirect to cart if empty
if ($cartService->isEmpty()) {
    $_SESSION['flash'] = ['type' => 'warning', 'message' => 'Your cart is empty. Add items first.'];
    header("Location: cart.php");
    exit;
}

// Validate & get cart items
$cartItems = $cartService->getCartItems();

if (empty($cartItems)) {
    $_SESSION['flash'] = ['type' => 'warning', 'message' => 'Your cart is empty. Add items first.'];
    header("Location: cart.php");
    exit;
}

// ── Filter by selected product IDs passed from cart.php (?selected=1,2,3) ──
$selectedIds = [];
if (!empty($_GET['selected'])) {
    $selectedIds = array_values(array_filter(
        array_map('intval', explode(',', $_GET['selected']))
    ));
}

// If specific items selected, keep only those; otherwise show all
if (!empty($selectedIds)) {
    $cartItems = array_values(array_filter(
        $cartItems,
        fn($i) => in_array((int)$i['product_id'], $selectedIds, true)
    ));
}

// Fallback: if filtering wiped everything, revert to full cart
if (empty($cartItems)) {
    $cartItems = $cartService->getCartItems();
}

// Recalculate totals for selected items only
$subtotal   = (float) array_sum(array_column($cartItems, 'line_total'));
$grandTotal = $subtotal + CHECKOUT_SHIPPING;

// Pre-fill shipping form from user profile
$userId = (int)$_SESSION['user_id'];
$stmt   = $pdo->prepare("SELECT firstname, lastname, email, phone FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$userId]);
$userDefaults = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$defaultFirstName = $userDefaults['firstname'] ?? '';
$defaultLastName  = $userDefaults['lastname']  ?? '';
$defaultEmail     = $userDefaults['email']     ?? '';
$defaultPhone     = $userDefaults['phone']     ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout - JDB Parts</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        body { background: #f5f7fa; }
        .summary-item-img { width: 60px; height: 60px; object-fit: cover; border-radius: 8px; }
        .checkout-card  { border: none; box-shadow: 0 4px 15px rgba(0,0,0,0.08); border-radius: 12px; }
        .sticky-summary { position: sticky; top: 20px; }
        .required-mark  { color: #dc3545; }
    </style>
</head>
<body>

<div class="container my-5">
    <div class="row g-5">

        <!-- Left: Checkout Form -->
        <div class="col-lg-7">
            <div class="d-flex align-items-center mb-4">
                <a href="cart.php" class="btn btn-outline-secondary me-3">
                    <i class="fas fa-arrow-left"></i>
                </a>
                <h2 class="mb-0">Checkout</h2>
            </div>

            <form action="process-order.php" method="post" id="checkoutForm" class="needs-validation" novalidate>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <!-- Pass selected product IDs to process-order.php -->
                <input type="hidden" name="selected_items" value="<?= htmlspecialchars(implode(',', array_column($cartItems, 'product_id'))) ?>">

                <!-- Shipping Information -->
                <div class="card checkout-card mb-4">
                    <div class="card-header bg-light border-0 py-3">
                        <h5 class="mb-0">
                            <i class="fas fa-shipping-fast me-2 text-primary"></i>
                            Shipping Information
                        </h5>
                    </div>
                    <div class="card-body p-4">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">
                                    First Name <span class="required-mark">*</span>
                                </label>
                                <input type="text" class="form-control" name="firstname"
                                       value="<?= htmlspecialchars($defaultFirstName) ?>"
                                       maxlength="50" required>
                                <div class="invalid-feedback">Please enter your first name.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">
                                    Last Name <span class="required-mark">*</span>
                                </label>
                                <input type="text" class="form-control" name="lastname"
                                       value="<?= htmlspecialchars($defaultLastName) ?>"
                                       maxlength="50" required>
                                <div class="invalid-feedback">Please enter your last name.</div>
                            </div>
                            <div class="col-12">
                                <label class="form-label">
                                    Email <span class="required-mark">*</span>
                                </label>
                                <input type="email" class="form-control" name="email"
                                       value="<?= htmlspecialchars($defaultEmail) ?>"
                                       maxlength="100" required>
                                <div class="invalid-feedback">Please enter a valid email.</div>
                            </div>
                            <div class="col-12">
                                <label class="form-label">
                                    Phone Number <span class="required-mark">*</span>
                                </label>
                                <input type="tel" class="form-control" name="phone"
                                       value="<?= htmlspecialchars($defaultPhone) ?>"
                                       placeholder="+63 XXX XXX XXXX"
                                       pattern="[0-9\s\-\+\(\)]+"
                                       maxlength="20" required>
                                <div class="invalid-feedback">Please enter a valid phone number.</div>
                            </div>
                            <div class="col-12">
                                <label class="form-label">
                                    Complete Shipping Address <span class="required-mark">*</span>
                                </label>
                                <textarea class="form-control" name="shipping_address"
                                          rows="4" maxlength="500"
                                          placeholder="Street, Barangay, City, Province, Postal Code"
                                          required></textarea>
                                <div class="invalid-feedback">Please enter your shipping address.</div>
                                <small class="text-muted">Include street, barangay, city, province, and postal code.</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Payment Method -->
                <div class="card checkout-card mb-4">
                    <div class="card-header bg-light border-0 py-3">
                        <h5 class="mb-0">
                            <i class="fas fa-credit-card me-2 text-primary"></i>
                            Payment Method
                        </h5>
                    </div>
                    <div class="card-body p-4">
                        <div class="form-check mb-3 p-3 border rounded">
                            <input class="form-check-input" type="radio"
                                   name="payment_method" id="cod" value="cod" checked required>
                            <label class="form-check-label w-100" for="cod">
                                <div class="d-flex align-items-center">
                                    <i class="fas fa-money-bill-wave fa-2x text-success me-3"></i>
                                    <div>
                                        <strong>Cash on Delivery (COD)</strong>
                                        <p class="mb-0 small text-muted">Pay when you receive your order</p>
                                    </div>
                                </div>
                            </label>
                        </div>
                        <div class="form-check p-3 border rounded opacity-50">
                            <input class="form-check-input" type="radio"
                                   name="payment_method" id="gcash" value="gcash" disabled>
                            <label class="form-check-label w-100" for="gcash">
                                <div class="d-flex align-items-center">
                                    <i class="fas fa-mobile-alt fa-2x text-primary me-3"></i>
                                    <div>
                                        <strong>GCash</strong>
                                        <p class="mb-0 small text-muted">Coming soon</p>
                                    </div>
                                </div>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Order Notes -->
                <div class="card checkout-card mb-4">
                    <div class="card-header bg-light border-0 py-3">
                        <h5 class="mb-0">
                            <i class="fas fa-sticky-note me-2 text-primary"></i>
                            Order Notes <small class="text-muted fw-normal">(Optional)</small>
                        </h5>
                    </div>
                    <div class="card-body p-4">
                        <textarea class="form-control" name="notes" rows="3"
                                  maxlength="500"
                                  placeholder="Special instructions for your order (optional)"></textarea>
                    </div>
                </div>

                <!-- Actions -->
                <div class="d-flex justify-content-between mt-4">
                    <a href="cart.php" class="btn btn-outline-secondary btn-lg px-4">
                        <i class="fas fa-arrow-left me-2"></i> Back to Cart
                    </a>
                    <button type="submit" class="btn btn-primary btn-lg px-5" id="submitBtn">
                        <span id="submitText">Place Order <i class="fas fa-check ms-2"></i></span>
                        <span id="submitSpinner" class="d-none">
                            <span class="spinner-border spinner-border-sm me-2"
                                  style="width:1rem;height:1rem;border-width:.15em;"></span>
                            Processing...
                        </span>
                    </button>
                </div>
            </form>
        </div>

        <!-- Right: Order Summary -->
        <div class="col-lg-5">
            <div class="card checkout-card sticky-summary">
                <div class="card-header bg-primary text-white py-3 d-flex align-items-center justify-content-between">
                    <h5 class="mb-0"><i class="fas fa-receipt me-2"></i> Order Summary</h5>
                    <span class="badge bg-white text-primary fw-bold"><?= count($cartItems) ?> item<?= count($cartItems) !== 1 ? 's' : '' ?></span>
                </div>
                <div class="card-body p-4">
                    <?php foreach ($cartItems as $item): ?>
                        <div class="d-flex mb-3 pb-3 border-bottom">
                            <?php if (!empty($item['image'])): ?>
                                <img src="<?= htmlspecialchars($item['image']) ?>"
                                     class="summary-item-img me-3"
                                     alt="<?= htmlspecialchars($item['name']) ?>">
                            <?php else: ?>
                                <div class="summary-item-img bg-light d-flex align-items-center
                                            justify-content-center me-3 rounded">
                                    <i class="fas fa-box text-muted"></i>
                                </div>
                            <?php endif; ?>
                            <div class="flex-grow-1">
                                <h6 class="mb-1"><?= htmlspecialchars($item['name']) ?></h6>
                                <small class="text-muted">
                                    Qty: <?= $item['quantity'] ?> &times;
                                    &#8369;<?= number_format($item['price'], 2) ?>
                                </small>
                            </div>
                            <div class="text-end fw-bold">
                                &#8369;<?= number_format($item['line_total'], 2) ?>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <div class="d-flex justify-content-between mb-2">
                        <span>Subtotal</span>
                        <span>&#8369;<?= number_format($subtotal, 2) ?></span>
                    </div>
                    <div class="d-flex justify-content-between mb-3">
                        <span>Shipping</span>
                        <span>&#8369;<?= number_format(CHECKOUT_SHIPPING, 2) ?></span>
                    </div>
                    <hr>
                    <div class="d-flex justify-content-between fw-bold fs-4 text-primary">
                        <span>Total</span>
                        <span>&#8369;<?= number_format($grandTotal, 2) ?></span>
                    </div>
                </div>
                <div class="card-footer text-center bg-light border-0 py-3">
                    <small class="text-muted">
                        <i class="fas fa-shield-alt me-1"></i>
                        Secure Checkout &mdash; by ShadDev
                    </small>
                </div>
            </div>
        </div>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(() => {
    'use strict';
    const form          = document.getElementById('checkoutForm');
    const submitBtn     = document.getElementById('submitBtn');
    const submitText    = document.getElementById('submitText');
    const submitSpinner = document.getElementById('submitSpinner');
    let submitted       = false;

    form.addEventListener('submit', function (e) {
        if (!form.checkValidity()) {
            e.preventDefault();
            e.stopPropagation();
            const firstInvalid = form.querySelector(':invalid');
            if (firstInvalid) {
                firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
                firstInvalid.focus();
            }
        } else if (!submitted) {
            submitted = true;
            submitBtn.disabled = true;
            submitText.classList.add('d-none');
            submitSpinner.classList.remove('d-none');
        } else {
            e.preventDefault(); // block double-submit
        }
        form.classList.add('was-validated');
    }, false);

    // Strip non-phone chars as user types
    const phoneInput = document.querySelector('input[name="phone"]');
    if (phoneInput) {
        phoneInput.addEventListener('input', e => {
            e.target.value = e.target.value.replace(/[^\d+\-\s()]/g, '');
        });
    }
})();
</script>

<?php if (isset($_SESSION['flash'])):
    $flash = $_SESSION['flash']; unset($_SESSION['flash']);
?>
<div class="toast-container position-fixed top-0 end-0 p-3">
    <div class="toast align-items-center text-white bg-<?= htmlspecialchars($flash['type'] ?? 'info') ?> border-0"
         role="alert" aria-live="assertive" aria-atomic="true">
        <div class="d-flex">
            <div class="toast-body"><?= htmlspecialchars($flash['message']) ?></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto"
                    data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>
<script>new bootstrap.Toast(document.querySelector('.toast'), {delay:5000}).show();</script>
<?php endif; ?>

</body>
</html>