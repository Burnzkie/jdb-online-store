<?php
// ── All POST handling and redirects BEFORE header.php outputs HTML ────────────
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../includes/header.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Security::requireCsrfToken();

    if (isset($_POST['ban_id'])) {
        $banId = filter_var($_POST['ban_id'], FILTER_VALIDATE_INT);
        if ($banId !== false && $banId > 0) {
            $reason = isset($_POST['reason']) ? trim($_POST['reason']) : null;
            $customerModel->ban($banId, $reason ?: null);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Customer has been banned.'];
        }
    }

    if (isset($_POST['unban_id'])) {
        $unbanId = filter_var($_POST['unban_id'], FILTER_VALIDATE_INT);
        if ($unbanId !== false && $unbanId > 0) {
            $customerModel->unban($unbanId);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Customer has been unbanned.'];
        }
    }

    // JS redirect because HTML is already sent by header.php
    echo '<script>window.location.replace("customers.php");</script>';
    exit;
}

$customers = $customerModel->getAllCustomers();
?>

<h2><i class="bi bi-people"></i> Manage Customers</h2>
<hr>

<?php if (!empty($_SESSION['flash'])): ?>
    <div class="alert alert-<?= htmlspecialchars($_SESSION['flash']['type'], ENT_QUOTES, 'UTF-8') ?> alert-dismissible fade show" role="alert">
        <?= htmlspecialchars($_SESSION['flash']['message'], ENT_QUOTES, 'UTF-8') ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="table-responsive">
    <table class="table table-striped align-middle">
        <thead class="table-dark">
            <tr>
                <th>Name</th>
                <th>Email</th>
                <th>Phone</th>
                <th>Orders</th>
                <th>Joined</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($customers)): ?>
            <tr>
                <td colspan="7" class="text-center text-muted py-4">No customers found.</td>
            </tr>
            <?php else: foreach ($customers as $customer):
                $customerId = (int)$customer['id'];
            ?>
            <tr>
                <td><?= htmlspecialchars($customer['firstname'] . ' ' . $customer['lastname'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($customer['email'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($customer['phone'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= (int)$customer['order_count'] ?></td>
                <td><?= date('M d, Y', strtotime($customer['created_at'])) ?></td>
                <td>
                    <span class="badge bg-<?= $customer['is_banned'] ? 'danger' : 'success' ?>">
                        <?= $customer['is_banned'] ? 'Banned' : 'Active' ?>
                    </span>
                </td>
                <td>
                    <?php if ($customer['is_banned']): ?>
                        <form action="customers.php" method="POST" class="d-inline">
                            <?= Security::csrfField() ?>
                            <input type="hidden" name="unban_id" value="<?= $customerId ?>">
                            <button type="submit" class="btn btn-sm btn-success">Unban</button>
                        </form>
                    <?php else: ?>
                        <button type="button" class="btn btn-sm btn-danger"
                                data-bs-toggle="modal"
                                data-bs-target="#banModal<?= $customerId ?>">
                            Ban
                        </button>
                    <?php endif; ?>
                </td>
            </tr>

            <!-- Ban Modal -->
            <div class="modal fade" id="banModal<?= $customerId ?>" tabindex="-1"
                 aria-labelledby="banModalLabel<?= $customerId ?>" aria-hidden="true">
                <div class="modal-dialog">
                    <form action="customers.php" method="POST">
                        <?= Security::csrfField() ?>
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="banModalLabel<?= $customerId ?>">
                                    Ban <?= htmlspecialchars($customer['firstname'] . ' ' . $customer['lastname'], ENT_QUOTES, 'UTF-8') ?>?
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <input type="hidden" name="ban_id" value="<?= $customerId ?>">
                                <label for="reason<?= $customerId ?>" class="form-label">
                                    Reason <span class="text-muted">(optional)</span>
                                </label>
                                <textarea id="reason<?= $customerId ?>" name="reason"
                                          class="form-control" rows="3" maxlength="500"></textarea>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-danger">Confirm Ban</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<?php require_once '../includes/footer.php'; ?>