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
require_once '../classes/ShippingService.php';
require_once '../classes/User.php';

if (!User::isLoggedIn() || $_SESSION['user_role'] !== 'customer') {
    header("Location: ../login.php");
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!defined('CHECKOUT_SHIPPING')) {
    define('CHECKOUT_SHIPPING', ShippingService::calculate('metro manila'));
}

$pdo         = Database::getInstance()->getConnection();
$cartService = new CartService($pdo);

if ($cartService->isEmpty()) {
    $_SESSION['flash'] = ['type' => 'warning', 'message' => 'Your cart is empty. Add items first.'];
    header("Location: cart.php");
    exit;
}

$cartItems = $cartService->getCartItems();

if (empty($cartItems)) {
    $_SESSION['flash'] = ['type' => 'warning', 'message' => 'Your cart is empty. Add items first.'];
    header("Location: cart.php");
    exit;
}

$selectedIds = [];
if (!empty($_GET['selected'])) {
    $selectedIds = array_values(array_filter(
        array_map('intval', explode(',', $_GET['selected']))
    ));
}

$qtyOverrides = [];
if (!empty($_GET['qtys']) && !empty($selectedIds)) {
    $rawQtys = array_map('intval', explode(',', $_GET['qtys']));
    foreach ($selectedIds as $idx => $pid) {
        $q = isset($rawQtys[$idx]) ? max(1, $rawQtys[$idx]) : 1;
        $qtyOverrides[$pid] = $q;
    }
}

if (!empty($selectedIds)) {
    $cartItems = array_values(array_filter(
        $cartItems,
        fn($i) => in_array((int)$i['product_id'], $selectedIds, true)
    ));
}
if (empty($cartItems)) {
    $cartItems = $cartService->getCartItems();
}

foreach ($cartItems as &$item) {
    $pid = (int)$item['product_id'];
    if (isset($qtyOverrides[$pid])) {
        $item['quantity']   = $qtyOverrides[$pid];
        $item['line_total'] = round($item['price'] * $item['quantity'], 2);
    }
}
unset($item);

$subtotal   = (float) array_sum(array_column($cartItems, 'line_total'));
$grandTotal = $subtotal + CHECKOUT_SHIPPING;

$userId = (int)$_SESSION['user_id'];
$stmt   = $pdo->prepare("SELECT firstname, lastname, email, phone FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$userId]);
$userDefaults = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

// ── Restore form data if customer returned from a failed/cancelled payment ──
$pendingOrder      = $_SESSION['pending_order'] ?? null;
$pendingFormData   = ($pendingOrder['returned'] ?? false) ? ($pendingOrder['form_data'] ?? []) : [];
$pendingPayment    = $pendingFormData['payment'] ?? '';

$defaultFirstName = $pendingFormData['firstname'] ?? $userDefaults['firstname'] ?? '';
$defaultLastName  = $pendingFormData['lastname']  ?? $userDefaults['lastname']  ?? '';
$defaultEmail     = $pendingFormData['email']     ?? $userDefaults['email']     ?? '';
$defaultPhone     = $pendingFormData['phone']     ?? $userDefaults['phone']     ?? '';
$defaultNotes     = $pendingFormData['notes']     ?? '';

// If returned from failed payment, default to COD so they can place the order immediately
$defaultPayment   = ($pendingPayment && $pendingPayment !== 'gcash' && $pendingPayment !== 'maya')
    ? $pendingPayment
    : 'cod';
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
        /* Manual fallback textarea — hidden by default */
        #manual-address-wrap { display: none; }
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

            <form action="process-order.php" method="post" id="checkoutForm" novalidate>

                <?php if (!empty($pendingOrder['returned'])): ?>
                <div class="alert alert-warning d-flex align-items-start mb-4" role="alert">
                    <i class="fas fa-exclamation-triangle me-3 mt-1"></i>
                    <div>
                        <strong>Payment was not completed.</strong><br>
                        Your items are still here. We've switched your payment method to
                        <strong>Cash on Delivery</strong> — or you can select GCash/Maya to try again.
                    </div>
                </div>
                <?php endif; ?>
                <input type="hidden" name="csrf_token"
                       value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="selected_items"
                       value="<?= htmlspecialchars(implode(',', array_column($cartItems, 'product_id'))) ?>">
                <input type="hidden" name="selected_qtys"
                       value="<?= htmlspecialchars(implode(',', array_column($cartItems, 'quantity'))) ?>">
                <!-- Shipping fee updated by JS when province is selected -->
                <input type="hidden" name="shipping_fee"
                       id="dynamic-shipping-fee" value="<?= CHECKOUT_SHIPPING ?>">
                <!-- Combined address populated by JS — falls back to manual textarea -->
                <input type="hidden" name="shipping_address" id="combined-address">

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

                            <!-- Street Address -->
                            <div class="col-12">
                                <label class="form-label">
                                    Street Address <span class="required-mark">*</span>
                                </label>
                                <input type="text" class="form-control" name="street_address"
                                       id="street_address"
                                       placeholder="House/Unit No., Street Name"
                                       maxlength="200" required>
                                <div class="invalid-feedback">Please enter your street address.</div>
                            </div>

                            <!-- Province -->
                            <div class="col-md-6">
                                <label class="form-label">
                                    Province <span class="required-mark">*</span>
                                </label>
                                <!--
                                    NOTE: NOT marked required here — we validate
                                    via JS before submit to avoid disabled-select issues
                                -->
                                <select class="form-select" id="province-select" name="province">
                                    <option value="">Loading provinces...</option>
                                </select>
                                <div class="invalid-feedback" id="province-error" style="display:none">
                                    Please select your province.
                                </div>
                            </div>

                            <!-- City -->
                            <div class="col-md-6">
                                <label class="form-label">
                                    City / Municipality <span class="required-mark">*</span>
                                </label>
                                <select class="form-select" id="city-select" name="city" disabled>
                                    <option value="">Select province first</option>
                                </select>
                                <div class="invalid-feedback" id="city-error" style="display:none">
                                    Please select your city.
                                </div>
                            </div>

                            <!-- Barangay -->
                            <div class="col-md-6">
                                <label class="form-label">
                                    Barangay <span class="required-mark">*</span>
                                </label>
                                <select class="form-select" id="barangay-select" name="barangay" disabled>
                                    <option value="">Select city first</option>
                                </select>
                                <div class="invalid-feedback" id="barangay-error" style="display:none">
                                    Please select your barangay.
                                </div>
                            </div>

                            <!-- Postal Code -->
                            <div class="col-md-6">
                                <label class="form-label">Postal Code</label>
                                <input type="text" class="form-control" name="postal_code"
                                       placeholder="e.g. 1234" maxlength="10">
                            </div>

                            <!-- Manual fallback — shown only when PSGC fails -->
                            <div class="col-12" id="manual-address-wrap">
                                <div class="alert alert-warning py-2 mb-2">
                                    <i class="fas fa-exclamation-triangle me-2"></i>
                                    Address lookup is unavailable. Please type your full address below.
                                </div>
                                <textarea class="form-control" id="manual-address"
                                          name="manual_address" rows="3"
                                          placeholder="Barangay, City/Municipality, Province, Postal Code"></textarea>
                            </div>

                            <!-- Dynamic shipping display -->
                            <div class="col-12">
                                <div class="alert alert-info py-2 mb-0" id="shipping-info">
                                    <i class="fas fa-truck me-2"></i>
                                    Shipping fee: <strong id="shipping-fee-display">
                                        ₱<?= number_format(CHECKOUT_SHIPPING, 2) ?>
                                    </strong>
                                    <small class="text-muted d-block">
                                        Select your province for exact rate
                                    </small>
                                </div>
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
                                   name="payment_method" id="cod" value="cod"
                                   <?= $defaultPayment === 'cod' ? 'checked' : '' ?> required>
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

                        <div class="form-check mb-3 p-3 border rounded">
                            <input class="form-check-input" type="radio"
                                   name="payment_method" id="gcash" value="gcash"
                                   <?= $defaultPayment === 'gcash' ? 'checked' : '' ?>>
                            <label class="form-check-label w-100" for="gcash">
                                <div class="d-flex align-items-center">
                                    <img src="../assets/img/gcash.png" width="40" class="me-3" alt="GCash">
                                    <div>
                                        <strong>GCash</strong>
                                        <p class="mb-0 small text-muted">Pay via GCash mobile wallet</p>
                                    </div>
                                </div>
                            </label>
                        </div>

                        <div class="form-check mb-3 p-3 border rounded">
                            <input class="form-check-input" type="radio"
                                   name="payment_method" id="maya" value="maya"
                                   <?= $defaultPayment === 'maya' ? 'checked' : '' ?>>
                            <label class="form-check-label w-100" for="maya">
                                <div class="d-flex align-items-center">
                                    <img src="../assets/img/maya.png" width="40" class="me-3" alt="Maya">
                                    <div>
                                        <strong>Maya (PayMaya)</strong>
                                        <p class="mb-0 small text-muted">Pay via Maya e-wallet</p>
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
                                  placeholder="Special instructions for your order (optional)"><?= htmlspecialchars($defaultNotes) ?></textarea>
                    </div>
                </div>

                <!-- Actions -->
                <div class="d-flex justify-content-between mt-4">
                    <a href="cart.php" class="btn btn-outline-secondary btn-lg px-4">
                        <i class="fas fa-arrow-left me-2"></i> Back to Cart
                    </a>
                    <button type="submit" class="btn btn-primary btn-lg px-5" id="submitBtn">
                        <span id="submitText">
                            Place Order <i class="fas fa-check ms-2"></i>
                        </span>
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
                <div class="card-header bg-primary text-white py-3
                            d-flex align-items-center justify-content-between">
                    <h5 class="mb-0"><i class="fas fa-receipt me-2"></i> Order Summary</h5>
                    <span class="badge bg-white text-primary fw-bold">
                        <?= count($cartItems) ?> item<?= count($cartItems) !== 1 ? 's' : '' ?>
                    </span>
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
                        <span class="shipping-summary-value">
                            &#8369;<?= number_format(CHECKOUT_SHIPPING, 2) ?>
                        </span>
                    </div>
                    <hr>
                    <div class="d-flex justify-content-between fw-bold fs-4 text-primary">
                        <span>Total</span>
                        <span class="grand-total-value">
                            &#8369;<?= number_format($grandTotal, 2) ?>
                        </span>
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
    const provinceSelect  = document.getElementById('province-select');
    const citySelect      = document.getElementById('city-select');
    const barangaySelect  = document.getElementById('barangay-select');
    const combinedAddr    = document.getElementById('combined-address');
    const manualWrap      = document.getElementById('manual-address-wrap');
    const manualTextarea  = document.getElementById('manual-address');

    // Track whether PSGC loaded successfully
    let psgcAvailable = false;
    let submitted     = false;

    // ── Safely create an <option> element to avoid innerHTML XSS/crash ──────
    // fixed: was using innerHTML += which breaks on names with quotes/special chars
    function makeOption(value, text, dataCode) {
        const opt = document.createElement('option');
        opt.value       = value;
        opt.textContent = text;          // safe — no HTML parsing
        if (dataCode) opt.dataset.code = dataCode;
        return opt;
    }

    function clearSelect(sel, placeholder) {
        sel.innerHTML = '';
        sel.appendChild(makeOption('', placeholder));
    }

    // ── Load provinces ───────────────────────────────────────────────────────
    fetch('ajax/get-provinces.php')
        .then(r => {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(provinces => {
            if (!Array.isArray(provinces) || provinces.length === 0) {
                throw new Error('Empty response');
            }
            psgcAvailable = true;
            clearSelect(provinceSelect, 'Select Province');
            provinces.forEach(p => {
                provinceSelect.appendChild(makeOption(p.name, p.name, p.code));
            });
        })
        .catch(() => {
            // PSGC failed — show manual textarea fallback
            psgcAvailable = false;
            clearSelect(provinceSelect, 'Address lookup unavailable');
            provinceSelect.disabled = true;
            citySelect.disabled     = true;
            barangaySelect.disabled = true;
            manualWrap.style.display = 'block';
        });

    // ── Province change ──────────────────────────────────────────────────────
    provinceSelect.addEventListener('change', function () {
        const option = this.options[this.selectedIndex];
        const code   = option.dataset.code;

        clearSelect(citySelect,    'Loading...');
        citySelect.disabled = true;

        clearSelect(barangaySelect, 'Select city first');
        barangaySelect.disabled = true;

        updateCombinedAddress();
        updateShippingFee(this.value);

        if (!code) return;

        fetch('ajax/get-cities.php?province_code=' + encodeURIComponent(code))
            .then(r => {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(cities => {
                clearSelect(citySelect, 'Select City / Municipality');
                cities.forEach(c => {
                    citySelect.appendChild(makeOption(c.name, c.name, c.code));
                });
                citySelect.disabled = false;
            })
            .catch(() => {
                clearSelect(citySelect, 'Failed to load cities');
                // Show manual fallback if cities fail too
                manualWrap.style.display = 'block';
            });
    });

    // ── City change ──────────────────────────────────────────────────────────
    citySelect.addEventListener('change', function () {
        const option = this.options[this.selectedIndex];
        const code   = option.dataset.code;

        clearSelect(barangaySelect, 'Loading...');
        barangaySelect.disabled = true;

        updateCombinedAddress();

        if (!code) return;

        fetch('ajax/get-barangays.php?city_code=' + encodeURIComponent(code))
            .then(r => {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(barangays => {
                clearSelect(barangaySelect, 'Select Barangay');
                barangays.forEach(b => {
                    barangaySelect.appendChild(makeOption(b.name, b.name));
                });
                barangaySelect.disabled = false;
            })
            .catch(() => {
                clearSelect(barangaySelect, 'Failed to load barangays');
                manualWrap.style.display = 'block';
            });
    });

    // ── Barangay / street changes ────────────────────────────────────────────
    barangaySelect.addEventListener('change', updateCombinedAddress);
    document.getElementById('street_address').addEventListener('input', updateCombinedAddress);
    manualTextarea.addEventListener('input', updateCombinedAddress);

    // ── Build combined address ───────────────────────────────────────────────
    function updateCombinedAddress() {
        let address;

        if (!psgcAvailable || manualWrap.style.display !== 'none') {
            // Manual fallback — combine street + textarea
            const street = document.getElementById('street_address').value.trim();
            const manual = manualTextarea.value.trim();
            address = [street, manual].filter(Boolean).join(', ');
        } else {
            const street   = document.getElementById('street_address').value.trim();
            const barangay = barangaySelect.value.trim();
            const city     = citySelect.value.trim();
            const province = provinceSelect.value.trim();
            const postal   = document.querySelector('[name="postal_code"]').value.trim();
            address = [street, barangay, city, province, postal].filter(Boolean).join(', ');
        }

        combinedAddr.value = address;
    }

    // ── Shipping fee ─────────────────────────────────────────────────────────
    function updateShippingFee(province) {
        if (!province) return;

        fetch('ajax/get-shipping-rate.php?province=' + encodeURIComponent(province))
            .then(r => {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(data => {
                const rate  = parseFloat(data.rate) || <?= CHECKOUT_SHIPPING ?>;
                const grand = <?= $subtotal ?> + rate;

                document.getElementById('shipping-fee-display').textContent =
                    '₱' + rate.toLocaleString('en-PH', { minimumFractionDigits: 2 });

                const summaryShipping = document.querySelector('.shipping-summary-value');
                if (summaryShipping) {
                    summaryShipping.textContent =
                        '₱' + rate.toLocaleString('en-PH', { minimumFractionDigits: 2 });
                }

                const summaryTotal = document.querySelector('.grand-total-value');
                if (summaryTotal) {
                    summaryTotal.textContent =
                        '₱' + grand.toLocaleString('en-PH', { minimumFractionDigits: 2 });
                }

                document.getElementById('dynamic-shipping-fee').value = rate.toFixed(2);
            })
            .catch(() => {
                // Silently keep default rate — don't crash
            });
    }

    // ── Form validation (manual, not Bootstrap's built-in) ───────────────────
    // fixed: Bootstrap skips disabled selects so we validate address manually
    form.addEventListener('submit', function (e) {
        // Always update combined address right before submit
        updateCombinedAddress();

        let valid = true;

        // 1. Standard HTML5 validation (name, email, phone, street)
        if (!form.checkValidity()) {
            e.preventDefault();
            e.stopPropagation();
            form.classList.add('was-validated');

            const firstInvalid = form.querySelector(':invalid:not([type="hidden"])');
            if (firstInvalid) {
                firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
                firstInvalid.focus();
            }
            valid = false;
        }

        // 2. Address validation — province/city/barangay or manual textarea
        if (psgcAvailable && manualWrap.style.display === 'none') {
            // PSGC dropdowns loaded — require selections
            if (!provinceSelect.value) {
                document.getElementById('province-error').style.display = 'block';
                provinceSelect.classList.add('is-invalid');
                if (valid) provinceSelect.scrollIntoView({ behavior: 'smooth', block: 'center' });
                valid = false;
            } else {
                document.getElementById('province-error').style.display = 'none';
                provinceSelect.classList.remove('is-invalid');
            }

            if (!citySelect.value) {
                document.getElementById('city-error').style.display = 'block';
                citySelect.classList.add('is-invalid');
                valid = false;
            } else {
                document.getElementById('city-error').style.display = 'none';
                citySelect.classList.remove('is-invalid');
            }

            if (!barangaySelect.value) {
                document.getElementById('barangay-error').style.display = 'block';
                barangaySelect.classList.add('is-invalid');
                valid = false;
            } else {
                document.getElementById('barangay-error').style.display = 'none';
                barangaySelect.classList.remove('is-invalid');
            }
        } else {
            // Manual fallback — require the textarea
            const manual = manualTextarea.value.trim();
            if (!manual) {
                manualTextarea.classList.add('is-invalid');
                if (valid) manualTextarea.scrollIntoView({ behavior: 'smooth', block: 'center' });
                valid = false;
            } else {
                manualTextarea.classList.remove('is-invalid');
            }
        }

        // 3. Final check — combined address must not be empty
        if (!combinedAddr.value.trim()) {
            if (valid) {
                alert('Please complete your shipping address before proceeding.');
            }
            e.preventDefault();
            valid = false;
        }

        if (!valid) return;

        // Prevent double-submit
        if (submitted) { e.preventDefault(); return; }
        submitted = true;
        submitBtn.disabled = true;
        submitText.classList.add('d-none');
        submitSpinner.classList.remove('d-none');

        form.classList.add('was-validated');
    });

    // ── Clean phone input ─────────────────────────────────────────────────────
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
    <div class="toast align-items-center text-white
                bg-<?= htmlspecialchars($flash['type'] ?? 'info') ?> border-0"
         role="alert" aria-live="assertive" aria-atomic="true">
        <div class="d-flex">
            <div class="toast-body"><?= htmlspecialchars($flash['message']) ?></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto"
                    data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>
<script>
    new bootstrap.Toast(document.querySelector('.toast'), { delay: 5000 }).show();
</script>
<?php endif; ?>

</body>
</html>