<?php
// customer/cart.php
session_start();
require_once '../classes/Database.php';
require_once '../classes/CartService.php';
require_once '../classes/User.php';

if (!User::isLoggedIn() || $_SESSION['user_role'] !== 'customer') {
    header("Location: ../login.php");
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$pdo         = Database::getInstance()->getConnection();
$cartService = new CartService($pdo);
$cartItems   = $cartService->getCartItems();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Cart — JDB Parts</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        body { background: #f8f9fa; font-family: 'Plus Jakarta Sans', sans-serif; }
        .cart-item { padding: 20px; border-bottom: 1px solid #e5e9f2; background: white; margin-bottom: 8px; border-radius: 12px; }
        .item-img { width: 90px; height: 90px; object-fit: cover; border-radius: 10px; }
        .qty-wrap { display: flex; align-items: center; border: 2px solid #e5e9f2; border-radius: 8px; overflow: hidden; width: fit-content; }
        .qty-btn { width: 36px; height: 36px; background: #f8f9fa; border: none; font-size: 1.2rem; cursor: pointer; }
        .qty-num { width: 60px; text-align: center; border: none; font-weight: 600; font-size: 1rem; }
        .summary-card { position: sticky; top: 20px; }

        /* Select-all bar */
        .select-all-bar {
            background: white;
            border-radius: 12px;
            padding: 12px 20px;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
            border: 1px solid #e5e9f2;
        }
        .select-all-bar label { font-weight: 600; font-size: .9rem; cursor: pointer; margin: 0; }

        /* Highlight checked cart items */
        .cart-item.is-checked {
            border: 1.5px solid #1a56db22;
            background: #f7f9ff;
        }
    </style>
</head>
<body>

<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 fw-bold">Shopping Cart</h1>
        <a href="products.php" class="btn btn-outline-primary">Continue Shopping</a>
    </div>

    <?php if (empty($cartItems)): ?>
        <div class="text-center py-5">
            <h4>Your cart is empty</h4>
            <a href="products.php" class="btn btn-primary mt-3">Browse Products</a>
        </div>
    <?php else: ?>

    <div class="row">
        <!-- Cart Items -->
        <div class="col-lg-8">

            <!-- Select All bar -->
            <div class="select-all-bar">
                <input type="checkbox" class="form-check-input" id="select-all" style="width:18px;height:18px;">
                <label for="select-all">Select All (<?= count($cartItems) ?> items)</label>
            </div>

            <?php foreach ($cartItems as $item): ?>
            <div class="cart-item d-flex" data-pid="<?= $item['product_id'] ?>" data-price="<?= $item['price'] ?>">
                <!-- ✅ Default UNCHECKED — user selects what they want -->
                <input type="checkbox" class="form-check-input me-3 mt-3 item-checkbox"
                       data-pid="<?= $item['product_id'] ?>">

                <?php if (!empty($item['image'])): ?>
                    <img src="<?= htmlspecialchars($item['image']) ?>" class="item-img me-3" alt="<?= htmlspecialchars($item['name']) ?>">
                <?php endif; ?>

                <div class="flex-grow-1">
                    <h6 class="mb-1"><?= htmlspecialchars($item['name']) ?></h6>
                    <p class="text-muted small mb-0">
                        Unit price: ₱<span class="unit-price"><?= number_format($item['price'], 2) ?></span>
                    </p>
                    <p class="text-primary fw-semibold small mt-1 mb-0">
                        Line total: ₱<span class="line-total"><?= number_format($item['price'] * $item['quantity'], 2) ?></span>
                    </p>
                </div>

                <div class="text-end">
                    <div class="qty-wrap mb-2">
                        <button type="button" class="qty-btn btn-dec">−</button>
                        <input type="number" class="qty-num qty-input"
                               value="<?= $item['quantity'] ?>"
                               min="1" max="<?= $item['stock'] ?? 999 ?>"
                               data-pid="<?= $item['product_id'] ?>"
                               data-price="<?= $item['price'] ?>">
                        <button type="button" class="qty-btn btn-inc">+</button>
                    </div>
                    <a href="remove-from-cart.php?id=<?= $item['product_id'] ?>"
                       class="text-danger small" onclick="return confirm('Remove this item from cart?')">Remove</a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Order Summary -->
        <div class="col-lg-4">
            <div class="card summary-card">
                <div class="card-body">
                    <h5 class="card-title">Order Summary</h5>
                    <hr>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Subtotal (<span id="selected-count">0</span> items)</span>
                        <span id="subtotal">₱0.00</span>
                    </div>
                    <div class="d-flex justify-content-between mb-3">
                        <span>Shipping Fee</span>
                        <span>₱150.00</span>
                    </div>
                    <hr>
                    <div class="d-flex justify-content-between fw-bold fs-5">
                        <span>Total</span>
                        <span id="grand-total">₱0.00</span>
                    </div>

                    <button onclick="proceedToCheckout()" class="btn btn-primary w-100 mt-4 py-3">
                        <i class="fas fa-lock"></i> Proceed to Checkout
                    </button>
                </div>
            </div>
        </div>
    </div>

    <?php endif; ?>
</div>

<script>
const CSRF = <?= json_encode($_SESSION['csrf_token']) ?>;

// ── Recalculate summary ───────────────────────────────────────────────
function recalcSummary() {
    let subtotal = 0;
    let count    = 0;

    document.querySelectorAll('.cart-item').forEach(item => {
        const checkbox = item.querySelector('.item-checkbox');
        if (!checkbox.checked) return;

        const qtyInput = item.querySelector('.qty-input');
        const price    = parseFloat(item.dataset.price);
        const qty      = parseInt(qtyInput.value) || 0;

        subtotal += price * qty;
        count++;
    });

    const total = subtotal + 150;

    document.getElementById('selected-count').textContent = count;
    document.getElementById('subtotal').textContent    = '₱' + subtotal.toLocaleString('en-PH', { minimumFractionDigits: 2 });
    document.getElementById('grand-total').textContent = '₱' + total.toLocaleString('en-PH',   { minimumFractionDigits: 2 });
}

// ── Update line total for a single row ───────────────────────────────
function updateLineTotal(input) {
    const price = parseFloat(input.dataset.price);
    const qty   = parseInt(input.value) || 0;
    const row   = input.closest('.cart-item');
    const span  = row.querySelector('.line-total');
    if (span) span.textContent = (price * qty).toLocaleString('en-PH', { minimumFractionDigits: 2 });
}

// ── Auto-save quantity to server (debounced) ──────────────────────────
let saveTimers = {};
function scheduleSave(input) {
    const pid = input.dataset.pid;
    clearTimeout(saveTimers[pid]);
    saveTimers[pid] = setTimeout(() => {
        fetch('update-cart-quantity.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body:    `product_id=${pid}&quantity=${input.value}&csrf_token=${encodeURIComponent(CSRF)}`
        });
    }, 600);
}

// ── Quantity input events ─────────────────────────────────────────────
document.querySelectorAll('.qty-input').forEach(input => {
    input.addEventListener('input', () => {
        updateLineTotal(input);
        recalcSummary();
        scheduleSave(input);
    });
});

// ── +/− buttons ───────────────────────────────────────────────────────
document.querySelectorAll('.btn-inc').forEach(btn => {
    btn.addEventListener('click', () => {
        const input = btn.parentElement.querySelector('.qty-input');
        const max   = parseInt(input.max) || 999;
        let val     = parseInt(input.value);
        if (val < max) {
            input.value = val + 1;
            updateLineTotal(input);
            recalcSummary();
            scheduleSave(input);
        }
    });
});

document.querySelectorAll('.btn-dec').forEach(btn => {
    btn.addEventListener('click', () => {
        const input = btn.parentElement.querySelector('.qty-input');
        let val     = parseInt(input.value);
        if (val > 1) {
            input.value = val - 1;
            updateLineTotal(input);
            recalcSummary();
            scheduleSave(input);
        }
    });
});

// ── Checkbox: highlight row + recalc ─────────────────────────────────
document.querySelectorAll('.item-checkbox').forEach(cb => {
    cb.addEventListener('change', () => {
        cb.closest('.cart-item').classList.toggle('is-checked', cb.checked);
        syncSelectAll();
        recalcSummary();
    });
});

// ── Select-All ───────────────────────────────────────────────────────
const selectAllCb = document.getElementById('select-all');
if (selectAllCb) {
    selectAllCb.addEventListener('change', () => {
        document.querySelectorAll('.item-checkbox').forEach(cb => {
            cb.checked = selectAllCb.checked;
            cb.closest('.cart-item').classList.toggle('is-checked', cb.checked);
        });
        recalcSummary();
    });
}

function syncSelectAll() {
    if (!selectAllCb) return;
    const all     = document.querySelectorAll('.item-checkbox');
    const checked = document.querySelectorAll('.item-checkbox:checked');
    selectAllCb.indeterminate = checked.length > 0 && checked.length < all.length;
    selectAllCb.checked       = checked.length === all.length && all.length > 0;
}

// ── Proceed to Checkout ───────────────────────────────────────────────
// Passes both product IDs AND their current quantities so checkout.php
// uses exactly the qty the user has set — just like Shopee.
function proceedToCheckout() {
    const checkedBoxes = Array.from(document.querySelectorAll('.item-checkbox:checked'));

    if (checkedBoxes.length === 0) {
        alert('Please select at least one item to proceed to checkout.');
        return;
    }

    const pids = [];
    const qtys = [];

    checkedBoxes.forEach(cb => {
        const pid   = cb.dataset.pid;
        const input = document.querySelector(`.qty-input[data-pid="${pid}"]`);
        const qty   = input ? parseInt(input.value) || 1 : 1;
        pids.push(pid);
        qtys.push(qty);
    });

    window.location.href = `checkout.php?selected=${pids.join(',')}&qtys=${qtys.join(',')}`;
}

// ── Init ──────────────────────────────────────────────────────────────
recalcSummary();   // starts at ₱0 since nothing is checked
</script>

</body>
</html>