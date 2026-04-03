<?php
// ── All redirects MUST happen before header.php outputs any HTML ──────────────
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── Validate ?id param ────────────────────────────────────────────────────────
$productId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if (!$productId || $productId <= 0) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Invalid product ID.'];
    header('Location: products.php');
    exit;
}

require_once '../includes/header.php'; // outputs HTML — all redirects must be above this

// ── Fetch product (bypasses is_active=1 filter in getProduct()) ───────────────
$stmt = $pdo->prepare("
    SELECT p.*, c.name AS category_name
    FROM   products p
    LEFT JOIN categories c ON c.id = p.category_id
    WHERE  p.id = ?
    LIMIT  1
");
$stmt->execute([$productId]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

// Product not found — can't redirect (HTML already sent), show inline error
if (!$product) {
    echo '<div class="alert alert-danger m-4">'
       . '<i class="bi bi-exclamation-triangle me-2"></i>Product not found. '
       . '<a href="products.php" class="alert-link">Back to Products</a></div>';
    require_once '../includes/footer.php';
    exit;
}

// ── Fetch all categories for the dropdown ─────────────────────────────────────
$categories = $pdo->query("SELECT id, name FROM categories ORDER BY name ASC")
                   ->fetchAll(PDO::FETCH_ASSOC);

// ── Upload constants ──────────────────────────────────────────────────────────
$allowedExts   = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
$allowedMimes  = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
$maxFileSize   = 2 * 1024 * 1024; // 2 MB
$uploadDirDisk = '../../uploads/products/';

// ── Handle POST ───────────────────────────────────────────────────────────────
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Security::requireCsrfToken();

    // ── 1. Collect & sanitize inputs ──────────────────────────────────────────
    $name       = Security::sanitizeString(trim($_POST['name']        ?? ''), 255);
    $desc       = Security::sanitizeString(trim($_POST['description'] ?? ''), 1000);
    $sku        = Security::sanitizeString(trim($_POST['sku']         ?? ''), 100);
    $price      = Security::sanitizeFloat($_POST['price']      ?? '');
    $salePrice  = Security::sanitizeFloat($_POST['sale_price'] ?? '');
    $stock      = Security::sanitizeInt($_POST['stock']        ?? '');
    $categoryId = Security::sanitizeInt($_POST['category_id']  ?? '');
    $isFeatured = isset($_POST['is_featured']) ? 1 : 0;
    $isActive   = isset($_POST['is_active'])   ? 1 : 0;

    // ── 2. Validate ───────────────────────────────────────────────────────────
    if ($name === '') {
        $errors[] = 'Product name is required.';
    }
    if ($price === null || $price <= 0) {
        $errors[] = 'A valid regular price is required.';
    }
    if ($salePrice !== null && $price !== null && $salePrice >= $price) {
        $errors[] = 'Sale price must be less than the regular price.';
    }
    if ($stock === null || $stock < 0) {
        $errors[] = 'Stock quantity must be 0 or more.';
    }
    if (!$categoryId) {
        $errors[] = 'Please select a category.';
    }

    // ── 3. Handle image upload (keep existing if no new file uploaded) ─────────
    $imagePath = $product['image'];

    if (!empty($_FILES['image']['name'])) {
        $file       = $_FILES['image'];
        $validation = Security::validateFileUpload($file, $allowedMimes, $maxFileSize);
        $ext        = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!$validation['valid']) {
            $errors[] = $validation['message'];
        } elseif (!in_array($ext, $allowedExts, true)) {
            $errors[] = 'Allowed image formats: JPG, JPEG, PNG, GIF, WEBP.';
        } else {
            if (!is_dir($uploadDirDisk)) {
                mkdir($uploadDirDisk, 0755, true);
            }

            $newFileName = 'product_' . $productId . '_' . time() . '.' . $ext;
            $diskPath    = $uploadDirDisk . $newFileName;

            if (move_uploaded_file($file['tmp_name'], $diskPath)) {
                // Delete old image from disk
                if (!empty($product['image'])) {
                    $oldDisk = '../../uploads/' . ltrim($product['image'], '/');
                    if (file_exists($oldDisk)) {
                        @unlink($oldDisk);
                    }
                }
                $imagePath = 'products/' . $newFileName;
            } else {
                $errors[] = 'Failed to upload image. Check directory permissions.';
            }
        }
    }

    // ── 4. Handle image removal ───────────────────────────────────────────────
    if (isset($_POST['remove_image']) && empty($_FILES['image']['name'])) {
        if (!empty($product['image'])) {
            $oldDisk = '../../uploads/' . ltrim($product['image'], '/');
            if (file_exists($oldDisk)) {
                @unlink($oldDisk);
            }
        }
        $imagePath = null;
    }

    // ── 5. Save if no errors ──────────────────────────────────────────────────
    if (empty($errors)) {
        $updated = $productModel->update($productId, [
            'name'              => $name,
            'short_description' => $desc,
            'sku'               => $sku,
            'price'             => $price,
            'sale_price'        => $salePrice ?: null,
            'stock'             => $stock,
            'category_id'       => $categoryId,
            'image'             => $imagePath,
            'is_featured'       => $isFeatured,
        ]);

        if ($updated) {
            // update() doesn't handle is_active — set it directly
            $pdo->prepare("UPDATE products SET is_active = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$isActive, $productId]);

            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Product updated successfully.'];

            // JS redirect because HTTP headers are already sent (HTML started in header.php)
            echo '<script>window.location.replace("products.php");</script>';
            exit;
        }

        $errors[] = 'Failed to update product. Please try again.';
    }

    // Re-fetch so the form reflects current DB state after a failed save
    $stmt->execute([$productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>

<div class="d-flex justify-content-between align-items-center mb-2">
    <h2 class="mb-0"><i class="bi bi-pencil-square"></i> Edit Product</h2>
    <a href="products.php" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left"></i> Back to Products
    </a>
</div>
<hr>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <strong><i class="bi bi-exclamation-triangle me-1"></i> Please fix the following errors:</strong>
        <ul class="mb-0 mt-1">
            <?php foreach ($errors as $e): ?>
                <li><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></li>
            <?php endforeach; ?>
        </ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data" novalidate>
    <?= Security::csrfField() ?>

    <div class="row g-4">

        <!-- ── LEFT COLUMN ───────────────────────────────────────────────── -->
        <div class="col-lg-8">

            <!-- Basic Info -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-light">
                    <h5 class="mb-0"><i class="bi bi-info-circle me-2"></i>Basic Information</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="name" class="form-label fw-semibold">Product Name <span class="text-danger">*</span></label>
                        <input type="text" id="name" name="name" class="form-control"
                               value="<?= htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8') ?>"
                               maxlength="255" required>
                    </div>

                    <div class="mb-3">
                        <label for="description" class="form-label fw-semibold">Short Description</label>
                        <textarea id="description" name="description" class="form-control" rows="4"
                                  maxlength="1000"><?= htmlspecialchars($product['short_description'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                        <div class="form-text">Max 1,000 characters.</div>
                    </div>

                    <div class="mb-0">
                        <label for="sku" class="form-label fw-semibold">SKU</label>
                        <input type="text" id="sku" name="sku" class="form-control"
                               value="<?= htmlspecialchars($product['sku'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                               maxlength="100" placeholder="e.g. JDB-BRK-001">
                    </div>
                </div>
            </div>

            <!-- Pricing -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-light">
                    <h5 class="mb-0"><i class="bi bi-tag me-2"></i>Pricing</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="price" class="form-label fw-semibold">Regular Price (&#8369;) <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">&#8369;</span>
                                <input type="number" id="price" name="price" class="form-control"
                                       value="<?= htmlspecialchars((string)$product['price'], ENT_QUOTES, 'UTF-8') ?>"
                                       step="0.01" min="0.01" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label for="sale_price" class="form-label fw-semibold">
                                Sale Price (&#8369;) <span class="text-muted fw-normal">(optional)</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text">&#8369;</span>
                                <input type="number" id="sale_price" name="sale_price" class="form-control"
                                       value="<?= htmlspecialchars((string)($product['sale_price'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                       step="0.01" min="0" placeholder="Leave blank for no sale">
                            </div>
                            <div class="form-text">Must be lower than the regular price.</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Image -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-light">
                    <h5 class="mb-0"><i class="bi bi-image me-2"></i>Product Image</h5>
                </div>
                <div class="card-body">
                    <?php
                    $imgSrc = !empty($product['image'])
                        ? '../../uploads/' . htmlspecialchars($product['image'], ENT_QUOTES, 'UTF-8')
                        : null;
                    ?>

                    <?php if ($imgSrc): ?>
                    <div class="mb-3 d-flex align-items-center gap-3">
                        <img src="<?= $imgSrc ?>"
                             id="imagePreview"
                             alt="Current product image"
                             class="rounded border"
                             style="width:120px;height:120px;object-fit:cover;">
                        <div>
                            <p class="mb-1 small text-muted">Current image</p>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox"
                                       name="remove_image" id="remove_image" value="1">
                                <label class="form-check-label text-danger small" for="remove_image">
                                    Remove current image
                                </label>
                            </div>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="mb-3">
                        <div class="bg-light border rounded d-flex align-items-center justify-content-center"
                             id="imagePreview"
                             style="width:120px;height:120px;">
                            <i class="bi bi-image fs-2 text-muted"></i>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div>
                        <label for="image" class="form-label fw-semibold">
                            <?= $imgSrc ? 'Replace Image' : 'Upload Image' ?>
                        </label>
                        <input type="file" id="image" name="image"
                               class="form-control"
                               accept="image/jpeg,image/png,image/gif,image/webp">
                        <div class="form-text">Max 2 MB — JPG, PNG, GIF, WEBP.</div>
                    </div>
                </div>
            </div>

        </div><!-- /col-lg-8 -->

        <!-- ── RIGHT COLUMN ──────────────────────────────────────────────── -->
        <div class="col-lg-4">

            <!-- Publish -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-light">
                    <h5 class="mb-0"><i class="bi bi-toggle-on me-2"></i>Publish</h5>
                </div>
                <div class="card-body">
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" role="switch"
                               id="is_active" name="is_active" value="1"
                               <?= $product['is_active'] ? 'checked' : '' ?>>
                        <label class="form-check-label fw-semibold" for="is_active">
                            Active (visible to customers)
                        </label>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch"
                               id="is_featured" name="is_featured" value="1"
                               <?= $product['is_featured'] ? 'checked' : '' ?>>
                        <label class="form-check-label fw-semibold" for="is_featured">
                            Featured product
                        </label>
                    </div>
                </div>
            </div>

            <!-- Category & Stock -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-light">
                    <h5 class="mb-0"><i class="bi bi-folder me-2"></i>Category & Stock</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="category_id" class="form-label fw-semibold">
                            Category <span class="text-danger">*</span>
                        </label>
                        <select id="category_id" name="category_id" class="form-select" required>
                            <option value="">— Select category —</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= (int)$cat['id'] ?>"
                                    <?= (int)$product['category_id'] === (int)$cat['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-0">
                        <label for="stock" class="form-label fw-semibold">
                            Stock Quantity <span class="text-danger">*</span>
                        </label>
                        <input type="number" id="stock" name="stock" class="form-control"
                               value="<?= (int)$product['stock'] ?>"
                               min="0" required>
                        <?php if ((int)$product['stock'] <= 5): ?>
                            <div class="form-text text-danger fw-semibold">
                                <i class="bi bi-exclamation-triangle"></i> Low stock warning
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Meta info -->
            <div class="card shadow-sm mb-4 border-0 bg-light">
                <div class="card-body small text-muted">
                    <p class="mb-1"><i class="bi bi-hash me-1"></i><strong>ID:</strong> <?= (int)$product['id'] ?></p>
                    <p class="mb-1"><i class="bi bi-link-45deg me-1"></i><strong>Slug:</strong>
                        <?= htmlspecialchars($product['slug'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                    </p>
                    <p class="mb-0"><i class="bi bi-clock me-1"></i><strong>Last updated:</strong>
                        <?= !empty($product['updated_at'])
                            ? date('M d, Y g:i A', strtotime($product['updated_at']))
                            : 'Never' ?>
                    </p>
                </div>
            </div>

            <!-- Actions -->
            <div class="d-grid gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg me-1"></i> Save Changes
                </button>
                <a href="products.php" class="btn btn-outline-secondary">
                    <i class="bi bi-x-lg me-1"></i> Cancel
                </a>
            </div>

        </div><!-- /col-lg-4 -->

    </div><!-- /row -->
</form>

<script>
(function () {
    const imageInput = document.getElementById('image');
    const removeBox  = document.getElementById('remove_image');

    if (!imageInput) return;

    imageInput.addEventListener('change', function () {
        const file = this.files[0];
        if (!file) return;

        // Uncheck "remove image" when a new file is selected
        if (removeBox) removeBox.checked = false;

        // Live preview
        const reader = new FileReader();
        reader.onload = e => {
            let preview = document.getElementById('imagePreview');

            if (preview.tagName !== 'IMG') {
                // Replace placeholder div with a real <img>
                const img         = document.createElement('img');
                img.id            = 'imagePreview';
                img.alt           = 'Image preview';
                img.className     = 'rounded border';
                img.style.cssText = 'width:120px;height:120px;object-fit:cover;';
                preview.replaceWith(img);
                preview = img;
            }
            preview.src = e.target.result;
        };
        reader.readAsDataURL(file);
    });
})();
</script>

<?php require_once '../includes/footer.php'; ?>