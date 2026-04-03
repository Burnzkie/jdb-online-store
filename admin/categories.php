<?php
session_start();
require_once '../classes/Database.php';

if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    session_destroy();
    header('Location: ../auth/login.php?error=access_denied');
    exit;
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$pdo       = Database::getInstance()->getConnection();
$user_name = $_SESSION['user_name'] ?? 'Admin';
$avatarUrl = 'https://ui-avatars.com/api/?name=' . urlencode($user_name) . '&background=667eea&color=fff';

$action  = $_GET['action'] ?? 'list';
$id      = !empty($_GET['id']) ? (int)$_GET['id'] : null;
$message = urldecode($_GET['msg'] ?? '');
$msgType = $_GET['type'] ?? 'success';

// ── Image upload helper ───────────────────────────────────────
$uploadDir = '../uploads/categories/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

function handleCategoryImageUpload(string $uploadDir): string
{
    if (empty($_FILES['image']['name']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        return '';
    }
    $file  = $_FILES['image'];
    $ext   = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed      = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    $mime = mime_content_type($file['tmp_name']);

    if (!in_array($ext, $allowed, true) || !in_array($mime, $allowedMimes, true)) {
        return '';
    }
    if ($file['size'] > 5 * 1024 * 1024) {
        return '';
    }
    $newName = uniqid('cat_', true) . '.' . $ext;
    $dest    = $uploadDir . $newName;
    return move_uploaded_file($file['tmp_name'], $dest)
        ? '../uploads/categories/' . $newName
        : '';
}

// ── Handle POST (create / update) ────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        die('Invalid request.');
    }

    $uploadedImage = handleCategoryImageUpload($uploadDir);

    $name        = trim($_POST['name']        ?? '');
    $slug        = trim($_POST['slug']        ?? '');
    $description = trim($_POST['description'] ?? '');
    $image       = $uploadedImage ?: ($_POST['existing_image'] ?? null);

    if (empty($name)) {
        $msg = 'Category name is required.';
        header('Location: categories.php?action=' . $action . ($id ? '&id=' . $id : '') . '&msg=' . urlencode($msg) . '&type=danger');
        exit;
    }

    // Auto-generate slug if empty
    if (empty($slug)) {
        $slug = trim(preg_replace('/[^a-z0-9-]+/', '-', strtolower($name)), '-');
    }

    if ($action === 'edit' && $id) {
        $stmt = $pdo->prepare(
            "UPDATE categories SET name = ?, slug = ?, description = ?, image = ?, updated_at = NOW() WHERE id = ?"
        );
        $stmt->execute([$name, $slug, $description ?: null, $image, $id]);
        $msg = 'Category updated successfully!';
    } else {
        $stmt = $pdo->prepare(
            "INSERT INTO categories (name, slug, description, image, created_at) VALUES (?, ?, ?, ?, NOW())"
        );
        $stmt->execute([$name, $slug, $description ?: null, $image]);
        $msg = 'Category created successfully!';
    }

    header('Location: categories.php?msg=' . urlencode($msg));
    exit;
}

// ── Delete ────────────────────────────────────────────────────
if ($action === 'delete' && $id) {
    if (!hash_equals($_SESSION['csrf_token'], $_GET['csrf'] ?? '')) {
        header('Location: categories.php?msg=' . urlencode('Invalid request.') . '&type=danger');
        exit;
    }

    // Block delete if products are assigned to this category
    $check = $pdo->prepare("SELECT COUNT(*) FROM products WHERE category_id = ?");
    $check->execute([$id]);
    if ($check->fetchColumn() > 0) {
        header('Location: categories.php?msg=' . urlencode('Cannot delete: products exist in this category.') . '&type=danger');
        exit;
    }

    $pdo->prepare("DELETE FROM categories WHERE id = ?")->execute([$id]);
    header('Location: categories.php?msg=Category+deleted');
    exit;
}

// ── Load single category for edit ────────────────────────────
$categoryData = [];
if ($action === 'edit' && $id) {
    $stmt = $pdo->prepare("SELECT * FROM categories WHERE id = ?");
    $stmt->execute([$id]);
    $categoryData = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$categoryData) {
        header('Location: categories.php?msg=Category+not+found&type=danger');
        exit;
    }
}

// ── Load all categories ───────────────────────────────────────
$allCategories = $pdo->query("
    SELECT c.*, COUNT(p.id) AS product_count
    FROM   categories c
    LEFT JOIN products p ON p.category_id = c.id
    GROUP BY c.id
    ORDER BY c.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$currentPage = 'categories';
$pageTitle   = match ($action) {
    'edit'  => 'Edit Category',
    'add'   => 'Add Category',
    default => 'Categories',
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
        .cat-img          { width:56px; height:56px; object-fit:cover; border-radius:8px; }
        .cat-placeholder  { width:56px; height:56px; border-radius:8px; }
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
                <small class="text-muted">
                    <?= in_array($action, ['add','edit']) ? 'Fill in the details below' : 'Manage your product categories' ?>
                </small>
            </div>
        </div>
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
    </header>

    <!-- Flash message -->
    <?php if ($message): ?>
        <div class="alert alert-<?= htmlspecialchars($msgType) ?> alert-dismissible fade show mb-4" role="alert">
            <i class="bi bi-<?= $msgType === 'success' ? 'check-circle' : 'exclamation-triangle' ?> me-2"></i>
            <?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (in_array($action, ['add', 'edit'])): ?>
    <!-- ── Add / Edit Form ────────────────────────────── -->
    <div class="card shadow-sm border-0 rounded-3">
        <div class="card-body p-4 p-lg-5">
            <form method="POST" enctype="multipart/form-data" novalidate>
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="existing_image" value="<?= htmlspecialchars($categoryData['image'] ?? '') ?>">

                <div class="row g-4">
                    <!-- Left column -->
                    <div class="col-lg-8">
                        <div class="mb-4">
                            <label class="form-label fw-semibold">
                                Category Name <span class="text-danger">*</span>
                            </label>
                            <input type="text" name="name" class="form-control form-control-lg"
                                   value="<?= htmlspecialchars($categoryData['name'] ?? '') ?>"
                                   placeholder="e.g. Brake Systems" required autofocus>
                        </div>
                        <div class="mb-4">
                            <label class="form-label fw-semibold">
                                Slug <span class="text-muted fw-normal">(auto-generated if empty)</span>
                            </label>
                            <input type="text" name="slug" class="form-control"
                                   value="<?= htmlspecialchars($categoryData['slug'] ?? '') ?>"
                                   placeholder="brake-systems">
                            <div class="form-text">Used in URLs. Lowercase letters, numbers, and hyphens only.</div>
                        </div>
                        <div class="mb-4">
                            <label class="form-label fw-semibold">Description <span class="text-muted fw-normal">(optional)</span></label>
                            <textarea name="description" class="form-control" rows="4"
                                      placeholder="Brief description of this category…"><?= htmlspecialchars($categoryData['description'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <!-- Right column: image -->
                    <div class="col-lg-4">
                        <label class="form-label fw-semibold">Category Image <span class="text-muted fw-normal">(optional)</span></label>
                        <div class="border rounded-3 p-3 text-center bg-light">
                            <?php if (!empty($categoryData['image'])): ?>
                                <img id="imgPreview" src="<?= htmlspecialchars($categoryData['image']) ?>"
                                     class="img-fluid rounded-2 mb-3" style="max-height:180px;" alt="Category image">
                            <?php else: ?>
                                <img id="imgPreview" src="" class="img-fluid rounded-2 mb-3 d-none"
                                     style="max-height:180px;" alt="Preview">
                                <div id="imgPlaceholder" class="py-4 text-muted">
                                    <i class="bi bi-folder fs-1 d-block mb-2"></i>
                                    No image selected
                                </div>
                            <?php endif; ?>
                            <input type="file" name="image" id="imageInput"
                                   class="form-control mt-2" accept="image/*">
                            <small class="text-muted d-block mt-1">JPG, PNG, WebP, GIF · max 5 MB</small>
                        </div>
                    </div>
                </div>

                <div class="d-flex justify-content-end gap-2 mt-5">
                    <a href="categories.php" class="btn btn-secondary btn-lg px-4">Cancel</a>
                    <button type="submit" class="btn btn-primary btn-lg px-4">
                        <i class="bi bi-check2 me-1"></i>
                        <?= $action === 'edit' ? 'Update Category' : 'Create Category' ?>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php else: ?>
    <!-- ── Categories List ───────────────────────────── -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <small class="text-muted">
            <?= count($allCategories) ?> categor<?= count($allCategories) !== 1 ? 'ies' : 'y' ?>
        </small>
        <a href="categories.php?action=add" class="btn btn-success">
            <i class="bi bi-plus-lg me-1"></i> Add Category
        </a>
    </div>

    <?php if (empty($allCategories)): ?>
        <div class="text-center py-5 chart-card">
            <i class="bi bi-folder display-1 text-muted"></i>
            <h5 class="mt-3 text-muted">No categories yet</h5>
            <a href="categories.php?action=add" class="btn btn-primary mt-2">Add Your First Category</a>
        </div>
    <?php else: ?>
        <div class="card shadow-sm border-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width:70px;">Image</th>
                            <th>Name</th>
                            <th>Slug</th>
                            <th>Products</th>
                            <th>Created</th>
                            <th style="width:110px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allCategories as $cat): ?>
                        <tr>
                            <td>
                                <?php if ($cat['image']): ?>
                                    <img src="<?= htmlspecialchars($cat['image']) ?>"
                                         class="cat-img" alt="<?= htmlspecialchars($cat['name']) ?>">
                                <?php else: ?>
                                    <div class="cat-placeholder cat-img bg-light border d-flex align-items-center justify-content-center">
                                        <i class="bi bi-folder text-muted"></i>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td><strong><?= htmlspecialchars($cat['name']) ?></strong>
                                <?php if ($cat['description']): ?>
                                    <br><small class="text-muted"><?= htmlspecialchars(mb_substr($cat['description'], 0, 60)) ?>…</small>
                                <?php endif; ?>
                            </td>
                            <td class="small text-muted"><?= htmlspecialchars($cat['slug'] ?: '—') ?></td>
                            <td>
                                <span class="badge bg-primary"><?= $cat['product_count'] ?></span>
                            </td>
                            <td class="small text-muted"><?= date('d M Y', strtotime($cat['created_at'])) ?></td>
                            <td>
                                <a href="categories.php?action=edit&id=<?= $cat['id'] ?>"
                                   class="btn btn-sm btn-outline-warning" title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <a href="categories.php?action=delete&id=<?= $cat['id'] ?>&csrf=<?= $_SESSION['csrf_token'] ?>"
                                   class="btn btn-sm btn-outline-danger ms-1" title="Delete"
                                   onclick="return confirm('Permanently delete \'<?= htmlspecialchars(addslashes($cat['name'])) ?>\'? This cannot be undone.')">
                                    <i class="bi bi-trash"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
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