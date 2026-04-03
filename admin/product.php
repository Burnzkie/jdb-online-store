<?php
session_start();
require_once '../classes/Database.php';
require_once '../classes/Product.php';

$allowedRoles = ['admin', 'staff'];
if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], $allowedRoles, true)) {
    header('Location: ../auth/login.php?error=access_denied');
    exit;
}

// CSRF bootstrap
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$pdo         = Database::getInstance()->getConnection();
$productObj  = new Product($pdo);
$user_name   = $_SESSION['user_name'] ?? 'Admin';
$avatarUrl   = 'https://ui-avatars.com/api/?name=' . urlencode($user_name) . '&background=667eea&color=fff';

$action  = $_GET['action'] ?? 'list';
$id      = !empty($_GET['id']) ? (int)$_GET['id'] : null;
$message = $_GET['msg'] ?? '';
$search  = trim($_GET['q'] ?? '');

// Categories
$categories = $pdo->query("SELECT id, name FROM categories ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// ── Image upload helper ───────────────────────────────────────
$uploadDir = '../uploads/products/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}
$uploadedImage = '';

function handleImageUpload(string $uploadDir): string
{
    if (empty($_FILES['image']['name']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        return '';
    }
    $file  = $_FILES['image'];
    $ext   = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    // Verify MIME type (not just extension)
    $mime = mime_content_type($file['tmp_name']);
    $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    if (!in_array($ext, $allowed, true) || !in_array($mime, $allowedMimes, true)) {
        return '';
    }
    if ($file['size'] > 5 * 1024 * 1024) {
        return '';
    }
    $newName = uniqid('img_', true) . '.' . $ext;
    $dest    = $uploadDir . $newName;
    return move_uploaded_file($file['tmp_name'], $dest)
        ? '../uploads/products/' . $newName
        : '';
}

// ── Handle POST (create / update) ────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        die('Invalid request.');
    }

    $uploadedImage = handleImageUpload($uploadDir);

    $data = [
        'name'              => trim($_POST['name'] ?? ''),
        'slug'              => trim($_POST['slug'] ?? ''),
        'short_description' => trim($_POST['short_description'] ?? ''),
        'price'             => max(0, (float)($_POST['price'] ?? 0)),
        'sale_price'        => !empty($_POST['sale_price']) ? (float)$_POST['sale_price'] : null,
        'sku'               => trim($_POST['sku'] ?? ''),
        'stock'             => max(0, (int)($_POST['stock'] ?? 0)),
        'category_id'       => (int)($_POST['category_id'] ?? 0),
        'image'             => $uploadedImage ?: ($_POST['existing_image'] ?? null),
        'is_featured'       => isset($_POST['is_featured']) ? 1 : 0,
    ];

    // Validate category
    $validCatIds = array_column($categories, 'id');
    if (!in_array($data['category_id'], $validCatIds, true)) {
        $data['category_id'] = $validCatIds[0] ?? 1;
    }

    if ($action === 'edit' && $id) {
        $productObj->update($id, $data);
        $msg = 'Product updated successfully!';
    } else {
        $productObj->create($data);
        $msg = 'Product created successfully!';
    }
    header('Location: product.php?msg=' . urlencode($msg));
    exit;
}

// ── Delete ────────────────────────────────────────────────────
if ($action === 'delete' && $id) {
    if (!hash_equals($_SESSION['csrf_token'], $_GET['csrf'] ?? '')) {
        header('Location: product.php?msg=' . urlencode('Invalid request.'));
        exit;
    }
    $productObj->delete($id);
    header('Location: product.php?msg=Product+deleted');
    exit;
}

// ── Load data for edit ────────────────────────────────────────
$productData = [];
if ($action === 'edit' && $id) {
    $productData = $productObj->getProduct($id);
    if (!$productData) {
        header('Location: product.php?msg=Product+not+found');
        exit;
    }
}

// ── Product list with search ──────────────────────────────────
$perPage     = 20;
$page        = max(1, (int)($_GET['page'] ?? 1));
$offset      = ($page - 1) * $perPage;
$allProducts = $productObj->getAllProducts($perPage, $offset, $search);
$totalCount  = $productObj->getTotalProducts($search);
$totalPages  = (int)ceil($totalCount / $perPage);

$currentPage = 'products';
$pageTitle   = match ($action) {
    'edit'  => 'Edit Product',
    'add'   => 'Add Product',
    default => 'Products'
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> – JDB Parts Admin</title>
    <link rel="stylesheet" href="../assets/css/bootstrap.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .product-img { width:56px; height:56px; object-fit:cover; border-radius:8px; }
        .img-placeholder { width:56px; height:56px; border-radius:8px; }
    </style>
</head>
<body>

<?php require_once '_sidebar.php'; ?>

<div class="main-content" id="mainContent">

    <!-- Header -->
    <header class="top-header d-flex align-items-center justify-content-between flex-wrap gap-3">
        <div class="d-flex align-items-center gap-3">
            <button class="btn btn-light p-2 d-lg-none" id="sidebarToggle"><i class="bi bi-list fs-4"></i></button>
            <button class="btn btn-light p-2 d-none d-lg-block" id="sidebarToggle"><i class="bi bi-layout-sidebar fs-5"></i></button>
            <div>
                <h5 class="mb-0 fw-semibold"><?= $pageTitle ?></h5>
                <small class="text-muted"><?= in_array($action, ['add','edit']) ? 'Fill in the details below' : 'Manage your product catalogue' ?></small>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <div class="dropdown">
                <a href="#" class="d-flex align-items-center gap-2 text-decoration-none dropdown-toggle"
                   data-bs-toggle="dropdown">
                    <img src="<?= $avatarUrl ?>" width="36" height="36" class="rounded-circle" alt="">
                    <span class="d-none d-sm-inline fw-medium"><?= htmlspecialchars($user_name) ?></span>
                </a>
                <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                    <li><a class="dropdown-item" href="settings.php"><i class="bi bi-gear me-2"></i>Settings</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="../auth/logout.php">
                        <i class="bi bi-box-arrow-left me-2"></i>Logout</a></li>
                </ul>
            </div>
        </div>
    </header>

    <?php if ($message): ?>
        <div class="alert alert-success alert-dismissible fade show mb-4" role="alert">
            <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars(urldecode($message)) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (in_array($action, ['add', 'edit'])): ?>
    <!-- ── Add / Edit Form ────────────────────────────── -->
    <div class="card shadow-sm border-0 rounded-3">
        <div class="card-body p-4 p-lg-5">
            <form method="POST" enctype="multipart/form-data" novalidate>
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="existing_image" value="<?= htmlspecialchars($productData['image'] ?? '') ?>">

                <div class="row g-4">
                    <!-- Left column -->
                    <div class="col-lg-8">
                        <div class="mb-4">
                            <label class="form-label fw-semibold">Product Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control form-control-lg"
                                   value="<?= htmlspecialchars($productData['name'] ?? '') ?>" required
                                   placeholder="e.g. Brake Pad Set">
                        </div>
                        <div class="mb-4">
                            <label class="form-label fw-semibold">Slug <span class="text-muted fw-normal">(auto-generated if empty)</span></label>
                            <input type="text" name="slug" class="form-control"
                                   value="<?= htmlspecialchars($productData['slug'] ?? '') ?>"
                                   placeholder="brake-pad-set">
                        </div>
                        <div class="mb-4">
                            <label class="form-label fw-semibold">Short Description</label>
                            <textarea name="short_description" class="form-control" rows="3"
                                      placeholder="Brief product description…"><?= htmlspecialchars($productData['short_description'] ?? '') ?></textarea>
                        </div>
                        <div class="row g-3 mb-4">
                            <div class="col-sm-6">
                                <label class="form-label fw-semibold">Regular Price (&#8369;) <span class="text-danger">*</span></label>
                                <input type="number" name="price" class="form-control"
                                       value="<?= $productData['price'] ?? '' ?>" min="0" step="100" required>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label fw-semibold">Sale Price (&#8369;)</label>
                                <input type="number" name="sale_price" class="form-control"
                                       value="<?= $productData['sale_price'] ?? '' ?>" min="0" step="100"
                                       placeholder="Leave blank if none">
                            </div>
                        </div>
                        <div class="row g-3 mb-4">
                            <div class="col-sm-6">
                                <label class="form-label fw-semibold">SKU</label>
                                <input type="text" name="sku" class="form-control"
                                       value="<?= htmlspecialchars($productData['sku'] ?? '') ?>">
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label fw-semibold">Stock Quantity <span class="text-danger">*</span></label>
                                <input type="number" name="stock" class="form-control"
                                       value="<?= $productData['stock'] ?? 0 ?>" min="0" required>
                            </div>
                        </div>
                        <div class="row g-3">
                            <div class="col-sm-6">
                                <label class="form-label fw-semibold">Category <span class="text-danger">*</span></label>
                                <?php if (empty($categories)): ?>
                                    <div class="alert alert-warning small p-2">
                                        No categories. <a href="categories.php?action=add">Add one first.</a>
                                    </div>
                                <?php else: ?>
                                    <select name="category_id" class="form-select" required>
                                        <option value="">Select category…</option>
                                        <?php foreach ($categories as $cat): ?>
                                            <option value="<?= $cat['id'] ?>"
                                                <?= isset($productData['category_id']) && $productData['category_id'] == $cat['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($cat['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                            </div>
                            <div class="col-sm-6 d-flex align-items-end">
                                <div class="form-check">
                                    <input type="checkbox" name="is_featured" class="form-check-input" id="featured"
                                           <?= !empty($productData['is_featured']) ? 'checked' : '' ?>>
                                    <label class="form-check-label fw-semibold" for="featured">
                                        <i class="bi bi-star-fill text-warning me-1"></i> Featured Product
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Right column: image -->
                    <div class="col-lg-4">
                        <label class="form-label fw-semibold">Product Image</label>
                        <div class="border rounded-3 p-3 text-center bg-light">
                            <?php if (!empty($productData['image'])): ?>
                                <img id="imgPreview" src="<?= htmlspecialchars($productData['image']) ?>"
                                     class="img-fluid rounded-2 mb-3" style="max-height:200px;" alt="Product image">
                            <?php else: ?>
                                <img id="imgPreview" src="" class="img-fluid rounded-2 mb-3 d-none"
                                     style="max-height:200px;" alt="Product image preview">
                                <div id="imgPlaceholder" class="py-4 text-muted">
                                    <i class="bi bi-image fs-1 d-block mb-2"></i>
                                    No image selected
                                </div>
                            <?php endif; ?>
                            <input type="file" name="image" class="form-control mt-2" accept="image/*"
                                   id="imageInput">
                            <small class="text-muted d-block mt-1">JPG, PNG, WebP, GIF · max 5 MB</small>
                        </div>
                    </div>
                </div>

                <div class="d-flex justify-content-end gap-2 mt-5">
                    <a href="product.php" class="btn btn-secondary btn-lg px-4">Cancel</a>
                    <button type="submit" class="btn btn-primary btn-lg px-4">
                        <i class="bi bi-check2 me-1"></i>
                        <?= $action === 'edit' ? 'Update Product' : 'Create Product' ?>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php else: ?>
    <!-- ── Product List ───────────────────────────────── -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <form class="d-flex gap-2" method="GET" action="product.php">
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>"
                   class="form-control" placeholder="Search products…" style="width:220px;">
            <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-search"></i></button>
            <?php if ($search): ?>
                <a href="product.php" class="btn btn-outline-danger"><i class="bi bi-x"></i></a>
            <?php endif; ?>
        </form>
        <a href="product.php?action=add" class="btn btn-success">
            <i class="bi bi-plus-lg me-1"></i> Add Product
        </a>
    </div>

    <div class="text-muted small mb-3">
        <?= $totalCount ?> product<?= $totalCount !== 1 ? 's' : '' ?>
        <?= $search ? '· Showing results for "<strong>' . htmlspecialchars($search) . '</strong>"' : '' ?>
    </div>

    <?php if (empty($allProducts)): ?>
        <div class="text-center py-5 chart-card">
            <i class="bi bi-box-seam display-1 text-muted"></i>
            <h5 class="mt-3 text-muted"><?= $search ? 'No products match your search.' : 'No products yet.' ?></h5>
            <?php if (!$search): ?>
                <a href="product.php?action=add" class="btn btn-primary mt-2">Add Your First Product</a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="card shadow-sm border-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width:70px;">Image</th>
                            <th>Product</th>
                            <th>Price</th>
                            <th>Stock</th>
                            <th>Category</th>
                            <th>Featured</th>
                            <th style="width:110px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allProducts as $p): ?>
                        <tr>
                            <td>
                                <?php if ($p['image']): ?>
                                    <img src="<?= htmlspecialchars($p['image']) ?>" class="product-img" alt="">
                                <?php else: ?>
                                    <div class="product-img img-placeholder bg-light border d-flex align-items-center justify-content-center">
                                        <i class="bi bi-image text-muted"></i>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars($p['name']) ?></strong>
                                <?php if ($p['sku']): ?>
                                    <br><small class="text-muted">SKU: <?= htmlspecialchars($p['sku']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td class="small">
                                &#8369;<?= number_format($p['price'], 2) ?>
                                <?php if ($p['sale_price']): ?>
                                    <br><span class="text-success fw-semibold">
                                        Sale: &#8369;<?= number_format($p['sale_price'], 2) ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge bg-<?= $p['stock'] > 5 ? 'success' : ($p['stock'] > 0 ? 'warning text-dark' : 'danger') ?>">
                                    <?= $p['stock'] ?>
                                </span>
                            </td>
                            <td class="small text-muted"><?= htmlspecialchars($p['category_name'] ?? '—') ?></td>
                            <td>
                                <?= $p['is_featured']
                                    ? '<i class="bi bi-star-fill text-warning"></i>'
                                    : '<i class="bi bi-star text-muted"></i>' ?>
                            </td>
                            <td>
                                <a href="product.php?action=edit&id=<?= $p['id'] ?>"
                                   class="btn btn-sm btn-outline-warning" title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <a href="product.php?action=delete&id=<?= $p['id'] ?>&csrf=<?= $_SESSION['csrf_token'] ?>"
                                   class="btn btn-sm btn-outline-danger ms-1" title="Delete"
                                   onclick="return confirm('Permanently delete this product?')">
                                    <i class="bi bi-trash"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <nav class="mt-4">
            <ul class="pagination justify-content-center">
                <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                    <a class="page-link" href="product.php?page=<?= $p ?>&q=<?= urlencode($search) ?>"><?= $p ?></a>
                </li>
                <?php endfor; ?>
            </ul>
        </nav>
        <?php endif; ?>
    <?php endif; ?>
    <?php endif; ?>

</div><!-- /.main-content -->

<script src="../assets/js/bootstrap_buddle.js"></script>
<script src="../assets/js/dashboard_sidebar.js"></script>
<script>
// Live image preview
document.getElementById('imageInput')?.addEventListener('change', function () {
    const preview     = document.getElementById('imgPreview');
    const placeholder = document.getElementById('imgPlaceholder');
    const file = this.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = e => {
        preview.src = e.target.result;
        preview.classList.remove('d-none');
        if (placeholder) placeholder.classList.add('d-none');
    };
    reader.readAsDataURL(file);
});
</script>
</body>
</html>