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
$totals      = $cartService->calculateTotals(150.00);
$subtotal    = $totals['subtotal'];
$shipping    = 150.00;
$grandTotal  = $subtotal + $shipping;
$cartCount   = $cartService->getItemCount();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Cart — JDB Parts</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=Sora:wght@600;700&display=swap" rel="stylesheet">
    <style>
        :root{--primary:#1a56db;--primary-dark:#1344b4;--primary-soft:#eff4ff;--success:#0e9f6e;--success-soft:#f0fdf9;--danger:#e02424;--danger-soft:#fff5f5;--warning:#d97706;--surface:#fff;--bg:#f4f6fb;--border:#e5e9f2;--text:#111827;--muted:#6b7280;--radius:14px;--radius-sm:9px;}
        *{box-sizing:border-box;margin:0;padding:0;}
        body{font-family:'Plus Jakarta Sans',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;}

        .top-nav{background:var(--surface);border-bottom:1px solid var(--border);height:62px;display:flex;align-items:center;padding:0 24px;position:sticky;top:0;z-index:100;gap:12px;}
        .brand{font-family:'Sora',sans-serif;font-weight:700;font-size:1.1rem;color:var(--primary);text-decoration:none;display:flex;align-items:center;gap:9px;}
        .brand-icon{width:34px;height:34px;background:var(--primary);border-radius:9px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:.85rem;}
        .nav-links{display:flex;gap:2px;margin-left:auto;}
        .nav-links a{display:flex;align-items:center;gap:6px;padding:7px 13px;border-radius:8px;font-size:.855rem;font-weight:500;color:var(--muted);text-decoration:none;transition:.15s;}
        .nav-links a:hover{background:var(--bg);color:var(--text);}
        .nav-links a.active{background:var(--primary-soft);color:var(--primary);}
        .cart-pill{position:relative;}
        .cart-pill .badge{position:absolute;top:-5px;right:-7px;background:var(--danger);color:#fff;border-radius:50%;width:17px;height:17px;font-size:.62rem;display:flex;align-items:center;justify-content:center;font-weight:700;}

        .page-wrap{max-width:1060px;margin:0 auto;padding:32px 20px 60px;}
        .page-header{margin-bottom:24px;}
        .page-header h1{font-family:'Sora',sans-serif;font-size:1.5rem;font-weight:700;margin-bottom:4px;}
        .page-header p{color:var(--muted);font-size:.875rem;}
        .breadcrumb-nav{display:flex;align-items:center;gap:8px;font-size:.8rem;color:var(--muted);margin-bottom:20px;}
        .breadcrumb-nav a{color:var(--muted);text-decoration:none;}
        .breadcrumb-nav a:hover{color:var(--primary);}
        .breadcrumb-nav .sep{color:var(--border);}
        .breadcrumb-nav .cur{color:var(--text);font-weight:600;}

        .cart-grid{display:grid;grid-template-columns:1fr 340px;gap:24px;align-items:start;}

        /* CART ITEMS */
        .cart-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;}
        .cart-card-head{padding:14px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;}
        .cart-card-head h2{font-family:'Sora',sans-serif;font-size:.95rem;font-weight:700;}
        .item-count-chip{background:var(--primary-soft);color:var(--primary);font-size:.75rem;font-weight:700;padding:2px 9px;border-radius:50px;}

        .cart-item{display:flex;align-items:flex-start;gap:16px;padding:18px 20px;border-bottom:1px solid var(--border);transition:.15s;}
        .cart-item:last-child{border-bottom:none;}
        .cart-item:hover{background:#fafbfd;}
        .item-img{width:80px;height:80px;border-radius:10px;object-fit:cover;background:var(--bg);flex-shrink:0;border:1px solid var(--border);}
        .item-img-placeholder{width:80px;height:80px;border-radius:10px;background:var(--bg);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;color:var(--border);font-size:1.4rem;flex-shrink:0;}
        .item-info{flex:1;min-width:0;}
        .item-name{font-weight:600;font-size:.9rem;margin-bottom:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
        .item-stock{font-size:.75rem;color:var(--muted);}
        .item-price{font-size:.85rem;color:var(--muted);margin-top:2px;}
        .item-right{display:flex;flex-direction:column;align-items:flex-end;gap:10px;flex-shrink:0;}
        .item-total{font-weight:700;font-size:.95rem;color:var(--text);}

        /* QTY STEPPER */
        .qty-wrap{display:flex;align-items:center;border:1.5px solid var(--border);border-radius:8px;overflow:hidden;}
        .qty-btn{width:32px;height:32px;border:none;background:var(--bg);cursor:pointer;font-size:1rem;display:flex;align-items:center;justify-content:center;color:var(--text);transition:.15s;flex-shrink:0;}
        .qty-btn:hover{background:var(--primary-soft);color:var(--primary);}
        .qty-num{width:40px;height:32px;text-align:center;border:none;border-left:1.5px solid var(--border);border-right:1.5px solid var(--border);font-family:'Plus Jakarta Sans',sans-serif;font-size:.875rem;font-weight:600;background:var(--surface);color:var(--text);}
        .qty-num:focus{outline:none;}

        .btn-remove{background:none;border:none;cursor:pointer;color:var(--border);font-size:.85rem;padding:4px 6px;border-radius:6px;transition:.15s;text-decoration:none;display:inline-flex;align-items:center;}
        .btn-remove:hover{color:var(--danger);background:var(--danger-soft);}

        /* SUMMARY */
        .summary-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;position:sticky;top:82px;}
        .summary-head{padding:14px 20px;border-bottom:1px solid var(--border);}
        .summary-head h2{font-family:'Sora',sans-serif;font-size:.95rem;font-weight:700;}
        .summary-body{padding:20px;}
        .summary-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;font-size:.875rem;}
        .summary-row.total{font-weight:700;font-size:1.05rem;padding-top:12px;border-top:1px solid var(--border);margin-top:4px;}
        .summary-label{color:var(--muted);}
        .summary-value{font-weight:600;}
        .shipping-note{font-size:.75rem;color:var(--muted);display:flex;align-items:center;gap:5px;margin-bottom:16px;padding:10px 12px;background:var(--bg);border-radius:8px;}

        .btn-checkout{display:flex;align-items:center;justify-content:center;gap:9px;width:100%;padding:13px;background:var(--primary);color:#fff;border:none;border-radius:var(--radius-sm);font-family:'Plus Jakarta Sans',sans-serif;font-size:.95rem;font-weight:700;cursor:pointer;text-decoration:none;transition:.2s;margin-bottom:10px;}
        .btn-checkout:hover{background:var(--primary-dark);color:#fff;transform:translateY(-1px);box-shadow:0 4px 16px rgba(26,86,219,.3);}
        .btn-continue{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;padding:11px;background:var(--surface);color:var(--text);border:1.5px solid var(--border);border-radius:var(--radius-sm);font-family:'Plus Jakarta Sans',sans-serif;font-size:.875rem;font-weight:600;cursor:pointer;text-decoration:none;transition:.2s;}
        .btn-continue:hover{border-color:var(--text);color:var(--text);}
        .secure-note{text-align:center;font-size:.75rem;color:var(--muted);margin-top:12px;display:flex;align-items:center;justify-content:center;gap:5px;}

        /* EMPTY */
        .empty-cart{text-align:center;padding:64px 24px;}
        .empty-cart-icon{width:90px;height:90px;border-radius:50%;background:var(--bg);display:inline-flex;align-items:center;justify-content:center;font-size:2.2rem;color:var(--border);margin-bottom:20px;}
        .empty-cart h3{font-family:'Sora',sans-serif;font-size:1.2rem;margin-bottom:8px;}
        .empty-cart p{color:var(--muted);font-size:.875rem;margin-bottom:24px;}

        .toast-container-fixed{position:fixed;top:16px;right:16px;z-index:9999;}
        .jdb-toast{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-sm);padding:14px 18px;display:flex;align-items:center;gap:12px;box-shadow:0 8px 24px rgba(0,0,0,.12);min-width:280px;animation:toastIn .3s ease;font-size:.875rem;}
        @keyframes toastIn{from{opacity:0;transform:translateX(20px)}to{opacity:1;transform:translateX(0)}}
        .toast-close{margin-left:auto;background:none;border:none;cursor:pointer;color:var(--muted);font-size:.9rem;padding:0;}

        /* CHECKBOX */
        .item-checkbox-wrap{display:flex;align-items:center;flex-shrink:0;}
        .item-checkbox{width:18px;height:18px;cursor:pointer;accent-color:var(--primary);border-radius:4px;}
        .cart-item.unchecked{opacity:.45;}
        .select-all-wrap{display:flex;align-items:center;gap:8px;font-size:.85rem;font-weight:600;color:var(--text);}
        .select-all-wrap input{width:16px;height:16px;cursor:pointer;accent-color:var(--primary);}
        .selected-count-chip{background:var(--success-soft);color:var(--success);font-size:.75rem;font-weight:700;padding:2px 9px;border-radius:50px;}
        @media(max-width:768px){.cart-grid{grid-template-columns:1fr}.summary-card{position:static}}
        @media(max-width:576px){.top-nav{padding:0 14px}.nav-links a span{display:none}}
    </style>
</head>
<body>

<nav class="top-nav">
    <a href="dashboard.php" class="brand">
        <span class="brand-icon"><i class="fas fa-car-side"></i></span>
        JDB Parts
    </a>
    <div class="nav-links">
        <a href="dashboard.php"><i class="fas fa-home"></i> <span>Home</span></a>
        <a href="products.php"><i class="fas fa-store"></i> <span>Shop</span></a>
        <a href="orders.php"><i class="fas fa-box"></i> <span>Orders</span></a>
        <a href="cart.php" class="active cart-pill">
            <i class="fas fa-shopping-cart"></i> <span>Cart</span>
            <?php if ($cartCount > 0): ?><span class="badge"><?= $cartCount ?></span><?php endif; ?>
        </a>
        <a href="account.php"><i class="fas fa-user"></i> <span>Account</span></a>
    </div>
</nav>

<div class="page-wrap">

    <div class="breadcrumb-nav">
        <a href="dashboard.php">Home</a><span class="sep">/</span>
        <span class="cur">Shopping Cart</span>
    </div>
    <div class="page-header">
        <h1>Shopping Cart</h1>
        <p><?= $cartCount ?> item<?= $cartCount !== 1 ? 's' : '' ?> in your cart</p>
    </div>

    <?php if (empty($cartItems)): ?>
        <div class="cart-card">
            <div class="empty-cart">
                <div class="empty-cart-icon"><i class="fas fa-shopping-cart"></i></div>
                <h3>Your cart is empty</h3>
                <p>Looks like you haven't added any items yet. Browse our products and find something you like!</p>
                <a href="products.php" style="display:inline-flex;align-items:center;gap:8px;padding:11px 28px;background:var(--primary);color:#fff;border-radius:var(--radius-sm);text-decoration:none;font-weight:600;font-size:.9rem;">
                    <i class="fas fa-store"></i> Browse Products
                </a>
            </div>
        </div>
    <?php else: ?>

    <div class="cart-grid">

        <!-- Cart Items -->
        <div>
            <form method="post" action="update-cart.php" id="cartForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <div class="cart-card">
                <div class="cart-card-head">
                    <div class="select-all-wrap">
                        <input type="checkbox" id="selectAll" title="Select all">
                        <h2>Cart Items</h2>
                    </div>
                    <div style="display:flex;align-items:center;gap:8px;">
                        <span class="selected-count-chip" id="selectedCountChip">0 selected</span>
                        <span class="item-count-chip"><?= $cartCount ?> item<?= $cartCount !== 1 ? 's' : '' ?></span>
                    </div>
                </div>
                <?php foreach ($cartItems as $item): ?>
                <div class="cart-item" data-pid="<?= $item['product_id'] ?>" data-price="<?= $item['price'] ?>">
                    <!-- Checkbox -->
                    <div class="item-checkbox-wrap">
                        <input type="checkbox" class="item-checkbox"
                               data-pid="<?= $item['product_id'] ?>"
                               title="Select item for checkout">
                    </div>
                    <?php if (!empty($item['image'])): ?>
                        <img src="<?= htmlspecialchars($item['image']) ?>"
                             alt="<?= htmlspecialchars($item['name']) ?>"
                             class="item-img"
                             onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                        <div class="item-img-placeholder" style="display:none"><i class="fas fa-box"></i></div>
                    <?php else: ?>
                        <div class="item-img-placeholder"><i class="fas fa-box"></i></div>
                    <?php endif; ?>

                    <div class="item-info">
                        <p class="item-name"><?= htmlspecialchars($item['name']) ?></p>
                        <p class="item-price">&#8369;<?= number_format($item['price'], 2) ?> each</p>
                        <p class="item-stock"><?= $item['stock'] ?> in stock</p>
                    </div>

                    <div class="item-right">
                        <p class="item-total">&#8369;<span class="line-val"><?= number_format($item['line_total'], 2) ?></span></p>
                        <div class="qty-wrap">
                            <button type="button" class="qty-btn btn-dec" aria-label="Decrease">−</button>
                            <input type="number"
                                   class="qty-num qty-display"
                                   value="<?= $item['quantity'] ?>"
                                   min="0" max="<?= $item['stock'] ?>"
                                   data-price="<?= $item['price'] ?>"
                                   data-pid="<?= $item['product_id'] ?>"
                                   readonly>
                            <input type="hidden" name="quantities[<?= $item['product_id'] ?>]" class="qty-hidden" value="<?= $item['quantity'] ?>">
                            <button type="button" class="qty-btn btn-inc" aria-label="Increase">+</button>
                        </div>
                        <form method="post" action="remove-from-cart.php" style="display:inline"
                              onsubmit="return confirm('Remove this item?')">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="product_id" value="<?= $item['product_id'] ?>">
                            <button type="submit" class="btn-remove" title="Remove">
                                <i class="fas fa-trash-alt me-1"></i> Remove
                            </button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Bottom bar -->
            <div style="display:flex;justify-content:space-between;align-items:center;margin-top:14px;flex-wrap:wrap;gap:10px;">
                <a href="products.php" style="display:inline-flex;align-items:center;gap:7px;color:var(--muted);text-decoration:none;font-size:.875rem;font-weight:500;padding:8px 14px;border:1.5px solid var(--border);border-radius:var(--radius-sm);transition:.15s;"
                   onmouseover="this.style.borderColor='var(--text)';this.style.color='var(--text)'"
                   onmouseout="this.style.borderColor='var(--border)';this.style.color='var(--muted)'">
                    <i class="fas fa-arrow-left"></i> Continue Shopping
                </a>
                <button type="submit" style="display:inline-flex;align-items:center;gap:7px;color:var(--primary);background:var(--primary-soft);border:1.5px solid var(--primary);border-radius:var(--radius-sm);padding:8px 18px;font-family:'Plus Jakarta Sans',sans-serif;font-size:.875rem;font-weight:600;cursor:pointer;transition:.15s;">
                    <i class="fas fa-sync-alt"></i> Update Cart
                </button>
            </div>
            </form>
        </div>

        <!-- Order Summary -->
        <div>
            <div class="summary-card">
                <div class="summary-head"><h2>Order Summary</h2></div>
                <div class="summary-body">
                    <div class="summary-row"><span class="summary-label">Subtotal (<span id="selectedItemCount">0</span> items)</span><span class="summary-value" id="summarySubtotal">&#8369;0.00</span></div>
                    <div class="summary-row"><span class="summary-label">Shipping Fee</span><span class="summary-value">&#8369;<?= number_format($shipping, 2) ?></span></div>
                    <div class="summary-row total"><span>Total</span><span style="color:var(--primary)" id="summaryTotal">&#8369;<?= number_format($grandTotal, 2) ?></span></div>

                    <div class="shipping-note">
                        <i class="fas fa-truck" style="color:var(--success)"></i>
                        Estimated delivery: 3–7 business days
                    </div>

                    <a href="#" class="btn-checkout" id="checkoutBtn" onclick="goCheckout(event)">
                        <i class="fas fa-lock"></i> Proceed to Checkout
                    </a>
                    <a href="products.php" class="btn-continue">
                        <i class="fas fa-store"></i> Continue Shopping
                    </a>
                    <p class="secure-note"><i class="fas fa-shield-alt"></i> Secured by ShadDev</p>
                </div>
            </div>
        </div>

    </div>

    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const SHIPPING = 150;

// ── Quantity steppers ──────────────────────────────────────────
document.querySelectorAll('.cart-item').forEach(item => {
    const display  = item.querySelector('.qty-display');
    const hidden   = item.querySelector('.qty-hidden');
    const dec      = item.querySelector('.btn-dec');
    const inc      = item.querySelector('.btn-inc');
    const lineVal  = item.querySelector('.line-val');
    const price    = parseFloat(display.dataset.price);
    const max      = parseInt(display.max);

    function update(q) {
        q = Math.max(0, Math.min(max, q));
        display.value = q;
        hidden.value  = q;
        if (lineVal) lineVal.textContent = (price * q).toLocaleString('en-PH', {minimumFractionDigits:2,maximumFractionDigits:2});
        recalcSummary();
    }

    dec.addEventListener('click', () => update(parseInt(display.value) - 1));
    inc.addEventListener('click', () => update(parseInt(display.value) + 1));
});

// ── Checkbox logic ─────────────────────────────────────────────
const selectAll = document.getElementById('selectAll');
const itemCheckboxes = () => document.querySelectorAll('.item-checkbox');

function syncSelectAll() {
    const boxes = itemCheckboxes();
    const total = boxes.length;
    const checked = [...boxes].filter(b => b.checked).length;
    selectAll.checked = checked === total;
    selectAll.indeterminate = checked > 0 && checked < total;
    document.getElementById('selectedCountChip').textContent = checked + ' selected';
    recalcSummary();

    // Dim unchecked items
    boxes.forEach(b => {
        b.closest('.cart-item').classList.toggle('unchecked', !b.checked);
    });

    // Disable checkout if none selected
    const btn = document.getElementById('checkoutBtn');
    if (btn) {
        btn.style.opacity = checked === 0 ? '.5' : '1';
        btn.style.pointerEvents = checked === 0 ? 'none' : 'auto';
    }
}

selectAll.addEventListener('change', () => {
    itemCheckboxes().forEach(b => b.checked = selectAll.checked);
    syncSelectAll();
});

itemCheckboxes().forEach(b => b.addEventListener('change', syncSelectAll));

// ── Summary recalculation (only checked items) ─────────────────
function recalcSummary() {
    let sub = 0;
    let count = 0;
    document.querySelectorAll('.cart-item').forEach(item => {
        const cb  = item.querySelector('.item-checkbox');
        const inp = item.querySelector('.qty-display');
        if (cb && cb.checked && inp) {
            sub += parseFloat(inp.dataset.price) * parseInt(inp.value);
            count++;
        }
    });
    const total = sub > 0 ? sub + SHIPPING : 0;
    const fmt   = v => '₱' + v.toLocaleString('en-PH', {minimumFractionDigits:2,maximumFractionDigits:2});
    const el    = document.getElementById('summarySubtotal');
    const et    = document.getElementById('summaryTotal');
    const ec    = document.getElementById('selectedItemCount');
    if (el) el.textContent = fmt(sub);
    if (et) et.textContent = fmt(total);
    if (ec) ec.textContent = count;
}

// ── Checkout: pass selected product IDs via query string ───────
function goCheckout(e) {
    e.preventDefault();
    const selected = [...itemCheckboxes()].filter(b => b.checked).map(b => b.dataset.pid);
    if (selected.length === 0) return;
    const url = 'checkout.php?selected=' + selected.join(',');
    window.location.href = url;
}

// Init
syncSelectAll();
</script>

<?php if (isset($_SESSION['flash'])): $f = $_SESSION['flash']; unset($_SESSION['flash']); ?>
<div class="toast-container-fixed">
    <div class="jdb-toast" id="jdbToast">
        <i class="fas <?= $f['type']==='success'?'fa-check-circle':'fa-exclamation-circle' ?> fa-lg" style="color:var(--<?= $f['type']==='success'?'success':'danger' ?>)"></i>
        <span><?= htmlspecialchars($f['message']) ?></span>
        <button class="toast-close" onclick="document.getElementById('jdbToast').remove()"><i class="fas fa-times"></i></button>
    </div>
</div>
<script>setTimeout(()=>{const t=document.getElementById('jdbToast');if(t)t.style.opacity=0,setTimeout(()=>t.remove(),300)},4000)</script>
<?php endif; ?>
</body>
</html>