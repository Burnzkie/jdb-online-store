<?php
session_start();
require_once '../classes/Database.php';
require_once '../classes/Customer.php';

if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    session_destroy();
    header('Location: ../auth/login.php?error=access_denied');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$pdo         = Database::getInstance()->getConnection();
$customerObj = new Customer($pdo);
$user_name   = $_SESSION['user_name'] ?? 'Admin';
$avatarUrl   = 'https://ui-avatars.com/api/?name=' . urlencode($user_name) . '&background=667eea&color=fff';

$message     = '';
$messageType = 'success';

// ── POST: ban / unban ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        die('Invalid request.');
    }
    $cid = (int)($_POST['customer_id'] ?? 0);

    if (isset($_POST['ban']) && $cid) {
        $reason  = trim($_POST['ban_reason'] ?? '');
        $message = $customerObj->ban($cid, $reason ?: null)
            ? 'Customer banned successfully.'
            : 'Failed to ban customer.';
        $messageType = str_contains($message, 'Failed') ? 'danger' : 'success';
    } elseif (isset($_POST['unban']) && $cid) {
        $message = $customerObj->unban($cid)
            ? 'Customer unbanned successfully.'
            : 'Failed to unban customer.';
        $messageType = str_contains($message, 'Failed') ? 'danger' : 'success';
    }

    header('Location: customers.php?msg=' . urlencode($message) . '&type=' . $messageType);
    exit;
}

// Flash messages from redirect
$msg     = urldecode($_GET['msg']  ?? '');
$msgType = $_GET['type'] ?? 'success';

$search       = trim($_GET['q'] ?? '');
$allCustomers = $customerObj->getAllCustomers($search);
$currentPage  = 'customers';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customers – JDB Parts Admin</title>
    <link rel="stylesheet" href="../assets/css/bootstrap.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>

<?php require_once '_sidebar.php'; ?>

<div class="main-content" id="mainContent">

    <header class="top-header d-flex align-items-center justify-content-between flex-wrap gap-3">
        <div class="d-flex align-items-center gap-3">
            <button class="btn btn-light p-2 d-lg-none" id="sidebarToggle"><i class="bi bi-list fs-4"></i></button>
            <button class="btn btn-light p-2 d-none d-lg-block" id="sidebarToggle"><i class="bi bi-layout-sidebar fs-5"></i></button>
            <div>
                <h5 class="mb-0 fw-semibold">Customer Management</h5>
                <small class="text-muted">Manage accounts and enforce policies</small>
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

    <?php if ($msg): ?>
        <div class="alert alert-<?= htmlspecialchars($msgType) ?> alert-dismissible fade show mb-4">
            <i class="bi bi-<?= $msgType === 'success' ? 'check-circle' : 'exclamation-triangle' ?> me-2"></i>
            <?= htmlspecialchars($msg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Search -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <form class="d-flex gap-2" method="GET">
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>"
                   class="form-control" placeholder="Search by name or email…" style="width:260px;">
            <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-search"></i></button>
            <?php if ($search): ?>
                <a href="customers.php" class="btn btn-outline-danger"><i class="bi bi-x"></i></a>
            <?php endif; ?>
        </form>
        <small class="text-muted">
            <?= count($allCustomers) ?> customer<?= count($allCustomers) !== 1 ? 's' : '' ?>
        </small>
    </div>

    <?php if (empty($allCustomers)): ?>
        <div class="text-center py-5 chart-card">
            <i class="bi bi-people display-1 text-muted"></i>
            <h5 class="mt-3 text-muted"><?= $search ? 'No customers match your search.' : 'No customers registered yet.' ?></h5>
        </div>
    <?php else: ?>
        <div class="card shadow-sm border-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Customer</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Registered</th>
                            <th>Orders</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allCustomers as $c):
                            $banned = (bool)$c['is_banned'];
                        ?>
                        <tr class="<?= $banned ? 'table-danger' : '' ?>">
                            <td>
                                <strong><?= htmlspecialchars($c['firstname'] . ' ' . $c['lastname']) ?></strong>
                            </td>
                            <td class="small"><?= htmlspecialchars($c['email']) ?></td>
                            <td class="small text-muted"><?= htmlspecialchars($c['phone'] ?: '—') ?></td>
                            <td class="small text-muted"><?= date('d M Y', strtotime($c['created_at'])) ?></td>
                            <td><span class="badge bg-primary"><?= $c['order_count'] ?></span></td>
                            <td>
                                <span class="badge bg-<?= $banned ? 'danger' : 'success' ?>">
                                    <?= $banned ? 'Banned' : 'Active' ?>
                                </span>
                                <?php if ($banned && $c['ban_reason']): ?>
                                    <br><small class="text-muted"><?= htmlspecialchars(mb_substr($c['ban_reason'], 0, 40)) ?>…</small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($banned): ?>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                        <input type="hidden" name="customer_id" value="<?= $c['id'] ?>">
                                        <button type="submit" name="unban"
                                                class="btn btn-sm btn-success"
                                                onclick="return confirm('Unban this customer?')">
                                            <i class="bi bi-check-circle me-1"></i>Unban
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <button class="btn btn-sm btn-danger"
                                            data-bs-toggle="modal"
                                            data-bs-target="#banModal<?= $c['id'] ?>">
                                        <i class="bi bi-slash-circle me-1"></i>Ban
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>

                        <!-- Ban Modal -->
                        <?php if (!$banned): ?>
                        <div class="modal fade" id="banModal<?= $c['id'] ?>" tabindex="-1">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                        <input type="hidden" name="customer_id" value="<?= $c['id'] ?>">
                                        <div class="modal-header">
                                            <h5 class="modal-title text-danger">
                                                <i class="bi bi-slash-circle me-2"></i>Ban Customer
                                            </h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <p>You are about to ban:</p>
                                            <p class="fw-semibold">
                                                <?= htmlspecialchars($c['firstname'] . ' ' . $c['lastname']) ?>
                                                <span class="text-muted fw-normal">(<?= htmlspecialchars($c['email']) ?>)</span>
                                            </p>
                                            <div>
                                                <label class="form-label">Reason <span class="text-muted">(optional)</span></label>
                                                <textarea name="ban_reason" class="form-control" rows="3"
                                                          placeholder="e.g. Fraudulent activity, spam…"></textarea>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" name="ban" class="btn btn-danger">Confirm Ban</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

</div>

<script src="../assets/js/bootstrap_buddle.js"></script>
<script src="../assets/js/dashboard_sidebar.js"></script>
</body>
</html>