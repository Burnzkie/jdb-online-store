<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../includes/header.php';

// ── Handle delete (POST) ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    Security::requireCsrfToken();

    $deleteId = filter_var($_POST['delete_id'], FILTER_VALIDATE_INT);
    if ($deleteId !== false && $deleteId > 0) {
        if ($productModel->delete($deleteId)) {
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Product deleted successfully.'];
        } else {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Failed to delete product.'];
        }
    }

    // JS redirect because HTML is already sent by header.php
    echo '<script>window.location.replace("products.php");</script>';
    exit;
}

$products = $productModel->getAllProducts(100, 0);
?>

<h2><i class="bi bi-box-seam"></i> Manage Products</h2>
<hr>

<?php if (!empty($_SESSION['flash'])): ?>
    <div class="alert alert-<?= htmlspecialchars($_SESSION['flash']['type'], ENT_QUOTES, 'UTF-8') ?> alert-dismissible fade show" role="alert">
        <?= htmlspecialchars($_SESSION['flash']['message'], ENT_QUOTES, 'UTF-8') ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<a href="product-add.php" class="btn btn-success mb-3">
    <i class="bi bi-plus-lg"></i> Add New Product
</a>

<div class="table-responsive">
    <table class="table table-striped align-middle">
        <thead class="table-dark">
            <tr>
                <th>Image</th>
                <th>Name</th>
                <th>Category</th>
                <th>Price</th>
                <th>Stock</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($products)): ?>
            <tr>
                <td colspan="7" class="text-center text-muted py-4">No products found.</td>
            </tr>
            <?php else: foreach ($products as $product): ?>
            <tr>
                <td>
                    <?php if (!empty($product['image'])): ?>
                        <img src="../../uploads/<?= htmlspecialchars($product['image'], ENT_QUOTES, 'UTF-8') ?>"
                             width="50" height="50"
                             class="rounded object-fit-cover"
                             alt="<?= htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8') ?>">
                    <?php else: ?>
                        <div class="bg-light rounded d-flex align-items-center justify-content-center"
                             style="width:50px;height:50px;">
                            <i class="bi bi-image text-muted"></i>
                        </div>
                    <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($product['category_name'] ?? 'Uncategorized', ENT_QUOTES, 'UTF-8') ?></td>
                <td>
                    <?php if (!empty($product['sale_price']) && $product['sale_price'] < $product['price']): ?>
                        <span class="text-decoration-line-through text-muted me-1">
                            &#8369;<?= number_format((float)$product['price'], 2) ?>
                        </span>
                        <span class="text-danger fw-bold">&#8369;<?= number_format((float)$product['sale_price'], 2) ?></span>
                    <?php else: ?>
                        &#8369;<?= number_format((float)$product['price'], 2) ?>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="<?= (int)$product['stock'] <= 5 ? 'text-danger fw-bold' : '' ?>">
                        <?= (int)$product['stock'] ?>
                    </span>
                </td>
                <td>
                    <span class="badge bg-<?= $product['is_active'] ? 'success' : 'secondary' ?>">
                        <?= $product['is_active'] ? 'Active' : 'Inactive' ?>
                    </span>
                </td>
                <td>
                    <a href="product-edit.php?id=<?= (int)$product['id'] ?>" class="btn btn-sm btn-warning me-1">
                        <i class="bi bi-pencil"></i> Edit
                    </a>
                    <button type="button" class="btn btn-sm btn-danger"
                            data-bs-toggle="modal"
                            data-bs-target="#deleteModal<?= (int)$product['id'] ?>">
                        <i class="bi bi-trash"></i> Delete
                    </button>
                </td>
            </tr>

            <!-- Delete Confirmation Modal -->
            <div class="modal fade" id="deleteModal<?= (int)$product['id'] ?>" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-sm">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Confirm Delete</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            Delete <strong><?= htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8') ?></strong>?
                            This cannot be undone.
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                            <form action="products.php" method="POST" class="d-inline">
                                <?= Security::csrfField() ?>
                                <input type="hidden" name="delete_id" value="<?= (int)$product['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<?php require_once '../includes/footer.php'; ?>