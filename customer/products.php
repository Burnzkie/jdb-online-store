<?php
session_start();
require_once '../classes/Database.php';
require_once '../classes/Product.php';
require_once '../classes/CartService.php';
require_once '../classes/User.php';

if (!User::isLoggedIn() || $_SESSION['user_role'] !== 'customer') {
    header("Location: ../login.php");
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$pdo = Database::getInstance()->getConnection();

$cartService = new CartService($pdo);
$cartCount   = $cartService->getItemCount();

// ────────────────────────────────────────────────
//  Pagination & Search
// ────────────────────────────────────────────────
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 12;
$offset  = ($page - 1) * $perPage;

$search = trim($_GET['search'] ?? '');
$where  = "WHERE stock > 0 AND is_active = 1";
$params = [];

if ($search !== '') {
    $where   .= " AND name LIKE ?";
    $params[] = "%$search%";
}

// Total count
$totalStmt = $pdo->prepare("SELECT COUNT(*) FROM products $where");
$totalStmt->execute($params);
$totalProducts = (int)$totalStmt->fetchColumn();
$totalPages    = max(1, ceil($totalProducts / $perPage));

if ($page > $totalPages && $totalPages > 0) {
    $redirect = "?page=$totalPages" . ($search ? "&search=" . urlencode($search) : '');
    header("Location: $redirect");
    exit;
}

// Fetch products
$stmt = $pdo->prepare("
    SELECT p.*, c.name AS category_name
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    $where
    ORDER BY p.created_at DESC
    LIMIT ? OFFSET ?
");
foreach ($params as $i => $param) {
    $stmt->bindValue($i + 1, $param, PDO::PARAM_STR);
}
$stmt->bindValue(count($params) + 1, $perPage, PDO::PARAM_INT);
$stmt->bindValue(count($params) + 2, $offset,  PDO::PARAM_INT);
$stmt->execute();
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Products - JDB Parts</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <style>
        body { background: #f5f7fa; }

        .product-card {
            transition: transform 0.25s, box-shadow 0.25s;
            border: none;
            border-radius: 14px;
            overflow: hidden;
        }
        .product-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 12px 28px rgba(0,0,0,0.12) !important;
        }
        .product-img {
            height: 200px;
            object-fit: contain;
            background: #f8f9fa;
            padding: 14px;
        }
        .sale-badge {
            background: #dc3545;
            color: #fff;
            font-weight: 700;
            padding: 0.35em 0.85em;
            border-radius: 50px;
            font-size: 0.78rem;
        }

        /* Quantity stepper */
        .qty-wrap { display: flex; align-items: center; gap: 0; }
        .qty-btn {
            width: 32px; height: 32px;
            padding: 0;
            line-height: 1;
            font-size: 1.1rem;
            flex-shrink: 0;
        }
        .qty-input {
            width: 52px !important;
            text-align: center;
            font-weight: 600;
            border-left: 0; border-right: 0;
            border-radius: 0 !important;
            -moz-appearance: textfield;
        }
        .qty-input::-webkit-inner-spin-button,
        .qty-input::-webkit-outer-spin-button { -webkit-appearance: none; }

        .line-total {
            font-weight: 700;
            color: #0d6efd;
            font-size: 0.95rem;
        }

        .cart-badge {
            font-size: .65rem;
            position: relative;
            top: -8px;
            left: -4px;
        }
    </style>
</head>
<body>

<!-- Top Nav Bar -->
<nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm mb-4">
    <div class="container">
        <a class="navbar-brand fw-bold text-primary" href="dashboard.php">
           <i class="bi bi-shop-window"></i> JDB Parts
        </a>
        <div class="d-flex align-items-center gap-3">
            <a href="cart.php" class="btn btn-outline-primary position-relative">
                <i class="fas fa-shopping-cart"></i>
                <?php if ($cartCount > 0): ?>
                    <span class="badge bg-danger cart-badge"><?= $cartCount ?></span>
                <?php endif; ?>
                Cart
            </a>
            <a href="dashboard.php" class="btn btn-outline-secondary">
                <i class="fas fa-tachometer-alt me-1"></i> Dashboard
            </a>
        </div>
    </div>
</nav>

<div class="container pb-5">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0 fw-bold">Our Products</h1>
        <span class="text-muted small"><?= number_format($totalProducts) ?> item<?= $totalProducts !== 1 ? 's' : '' ?> found</span>
    </div>

    <!-- Search -->
    <form class="mb-5" method="get">
        <div class="input-group input-group-lg shadow-sm">
            <span class="input-group-text bg-white border-end-0">
                <i class="fas fa-search text-muted"></i>
            </span>
            <input type="text" name="search" class="form-control border-start-0"
                   placeholder="Search products…"
                   value="<?= htmlspecialchars($search) ?>">
            <button type="submit" class="btn btn-primary px-4">Search</button>
            <?php if ($search): ?>
                <a href="products.php" class="btn btn-outline-secondary">Clear</a>
            <?php endif; ?>
        </div>
    </form>

    <?php if (empty($products)): ?>
        <div class="text-center py-5 my-5">
            <i class="fas fa-box-open fa-6x text-muted mb-4"></i>
            <h4>No products found</h4>
            <p class="text-muted lead">Try adjusting your search or check back later.</p>
            <a href="products.php" class="btn btn-outline-primary btn-lg">Clear Search</a>
        </div>
    <?php else: ?>

        <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 row-cols-xl-4 g-4">
            <?php foreach ($products as $product):
                $unitPrice = (float)($product['sale_price'] ?: $product['price']);
                $hasSale   = !empty($product['sale_price']) && (float)$product['sale_price'] < (float)$product['price'];
                $maxStock  = (int)$product['stock'];
                $imgSrc    = !empty($product['image'])
                    ? htmlspecialchars($product['image'])
                    : '';
            ?>
            <div class="col">
                <div class="card product-card h-100 shadow-sm"
                     data-unit-price="<?= $unitPrice ?>"
                     data-max-stock="<?= $maxStock ?>">

                    <div class="position-relative">
                        <?php if ($imgSrc): ?>
                        <img src="<?= $imgSrc ?>"
                             class="card-img-top product-img"
                             alt="<?= htmlspecialchars($product['name']) ?>"
                             loading="lazy"
                             onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                        <div class="card-img-top product-img d-none align-items-center justify-content-center bg-light" style="display:none!important">
                            <i class="fas fa-box-open fa-3x text-muted"></i>
                        </div>
                        <?php else: ?>
                        <div class="card-img-top product-img d-flex align-items-center justify-content-center bg-light">
                            <i class="fas fa-box-open fa-3x text-muted"></i>
                        </div>
                        <?php endif; ?>
                        <?php if ($hasSale): ?>
                            <span class="position-absolute top-0 start-0 m-2 sale-badge">SALE</span>
                        <?php endif; ?>
                        <?php if ($maxStock <= 5): ?>
                            <span class="position-absolute top-0 end-0 m-2 badge bg-warning text-dark">
                                Only <?= $maxStock ?> left!
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="card-body d-flex flex-column p-3">
                        <small class="text-muted mb-1">
                            <?= htmlspecialchars($product['category_name'] ?? 'Uncategorized') ?>
                        </small>
                        <h6 class="card-title fw-bold mb-2">
                            <?= htmlspecialchars($product['name']) ?>
                        </h6>

                        <!-- Price -->
                        <div class="mb-3">
                            <?php if ($hasSale): ?>
                                <span class="text-muted text-decoration-line-through me-1 small">
                                    &#8369;<?= number_format((float)$product['price'], 2) ?>
                                </span>
                            <?php endif; ?>
                            <span class="fs-5 fw-bold text-primary">
                                &#8369;<?= number_format($unitPrice, 2) ?>
                            </span>
                        </div>

                        <!-- Quantity Selector -->
                        <div class="mb-2">
                            <label class="form-label small fw-semibold mb-1">Quantity</label>
                            <div class="d-flex align-items-center gap-2">
                                <div class="qty-wrap">
                                    <button type="button"
                                            class="btn btn-outline-secondary qty-btn btn-dec"
                                            aria-label="Decrease quantity">−</button>
                                    <input type="number"
                                           class="form-control qty-input"
                                           value="1" min="1" max="<?= $maxStock ?>">
                                    <button type="button"
                                            class="btn btn-outline-secondary qty-btn btn-inc"
                                            aria-label="Increase quantity">+</button>
                                </div>
                                <small class="text-muted">/ <?= $maxStock ?> in stock</small>
                            </div>
                        </div>

                        <!-- Live Line Total -->
                        <div class="mb-3 p-2 bg-light rounded d-flex justify-content-between align-items-center">
                            <small class="text-muted">Total:</small>
                            <span class="line-total">
                                &#8369;<span class="total-amount"><?= number_format($unitPrice, 2) ?></span>
                            </span>
                        </div>

                        <!-- Action Buttons -->
                        <div class="mt-auto d-flex gap-2">
                            <form action="add-to-cart.php" method="post" class="flex-grow-1 add-cart-form">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                                <input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>">
                                <input type="hidden" name="quantity" value="1" class="hidden-qty">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="fas fa-cart-plus me-1"></i> Add to Cart
                                </button>
                            </form>
                            <form action="buy-now.php" method="post" class="buy-now-form">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                                <input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>">
                                <input type="hidden" name="quantity" value="1" class="hidden-qty">
                                <button type="submit" class="btn btn-outline-primary" title="Buy Now">
                                    <i class="fas fa-bolt"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <nav class="mt-5">
                <ul class="pagination justify-content-center">
                    <?php if ($page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?= $page-1 ?><?= $search ? '&search='.urlencode($search) : '' ?>">
                                &laquo;
                            </a>
                        </li>
                    <?php endif; ?>
                    <?php for ($i = max(1, $page-2); $i <= min($totalPages, $page+2); $i++): ?>
                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                            <a class="page-link"
                               href="?page=<?= $i ?><?= $search ? '&search='.urlencode($search) : '' ?>">
                                <?= $i ?>
                            </a>
                        </li>
                    <?php endfor; ?>
                    <?php if ($page < $totalPages): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?= $page+1 ?><?= $search ? '&search='.urlencode($search) : '' ?>">
                                &raquo;
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>
            </nav>
        <?php endif; ?>

    <?php endif; ?>
</div><!-- /container -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
/**
 * Quantity stepper + live total for each product card.
 */
document.querySelectorAll('.product-card').forEach(card => {
    const unitPrice   = parseFloat(card.dataset.unitPrice) || 0;
    const maxStock    = parseInt(card.dataset.maxStock, 10) || 1;

    const qtyInput    = card.querySelector('.qty-input');
    const totalSpan   = card.querySelector('.total-amount');
    const btnDec      = card.querySelector('.btn-dec');
    const btnInc      = card.querySelector('.btn-inc');

    // Hidden qty fields on both forms
    const hiddenQtys  = card.querySelectorAll('.hidden-qty');

    function clamp(val) {
        return Math.min(maxStock, Math.max(1, val));
    }

    function refresh(qty) {
        qty = clamp(qty);
        qtyInput.value   = qty;
        totalSpan.textContent = (unitPrice * qty).toLocaleString('en-PH', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
        hiddenQtys.forEach(h => h.value = qty);
        btnDec.disabled = qty <= 1;
        btnInc.disabled = qty >= maxStock;
    }

    btnDec.addEventListener('click', () => refresh(parseInt(qtyInput.value, 10) - 1));
    btnInc.addEventListener('click', () => refresh(parseInt(qtyInput.value, 10) + 1));

    qtyInput.addEventListener('change', () => refresh(parseInt(qtyInput.value, 10) || 1));
    qtyInput.addEventListener('input',  () => refresh(parseInt(qtyInput.value, 10) || 1));

    // Init state
    refresh(1);
});
</script>

<!-- Flash toast -->
<?php if (isset($_SESSION['flash'])):
    $f = $_SESSION['flash']; unset($_SESSION['flash']);
?>
<div class="toast-container position-fixed top-0 end-0 p-3">
    <div class="toast align-items-center text-white
                bg-<?= htmlspecialchars($f['type'] ?? 'success') ?> border-0"
         role="alert">
        <div class="d-flex">
            <div class="toast-body"><?= htmlspecialchars($f['message']) ?></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto"
                    data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>
<script>new bootstrap.Toast(document.querySelector('.toast'), {delay:3500}).show();</script>
<?php endif; ?>

</body>
</html>