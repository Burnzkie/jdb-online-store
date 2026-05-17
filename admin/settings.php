<?php
session_start();
require_once '../classes/Database.php';
require_once '../classes/User.php';

if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    session_destroy();
    header('Location: ../auth/login.php?error=access_denied');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$pdo     = Database::getInstance()->getConnection();
$userObj = new User($pdo);
$userId  = (int)$_SESSION['user_id'];
$user_name  = $_SESSION['user_name'] ?? 'Admin';
$avatarUrl  = 'https://ui-avatars.com/api/?name=' . urlencode($user_name) . '&background=667eea&color=fff';

$message     = '';
$messageType = 'success';

// Fetch current user
$currentUser = $userObj->getUserById($userId);
if (!$currentUser) {
    session_destroy();
    header('Location: ../auth/login.php');
    exit;
}

// ── Handle POST ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $message     = 'Invalid request. Please refresh and try again.';
        $messageType = 'danger';
    } elseif (isset($_POST['update_profile'])) {
        $firstname = trim($_POST['firstname'] ?? '');
        $lastname  = trim($_POST['lastname'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $phone     = trim($_POST['phone'] ?? '');

        if (empty($firstname) || empty($lastname) || empty($email)) {
            $message     = 'First name, last name, and email are required.';
            $messageType = 'danger';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message     = 'Please enter a valid email address.';
            $messageType = 'danger';
        } else {
            if ($userObj->updateUserProfile($userId, $firstname, $lastname, $email, $phone ?: null)) {
                $_SESSION['user_name'] = $firstname . ' ' . $lastname;
                $user_name             = $_SESSION['user_name'];
                $avatarUrl             = 'https://ui-avatars.com/api/?name=' . urlencode($user_name) . '&background=667eea&color=fff';
                $currentUser           = $userObj->getUserById($userId); // refresh
                $message               = 'Profile updated successfully.';
            } else {
                $message     = 'Failed to update profile. Please try again.';
                $messageType = 'danger';
            }
        }

    } elseif (isset($_POST['change_password'])) {
        $currentPw  = $_POST['current_password'] ?? '';
        $newPw      = $_POST['new_password']      ?? '';
        $confirmPw  = $_POST['confirm_password']  ?? '';

        if (empty($currentPw) || empty($newPw) || empty($confirmPw)) {
            $message     = 'All password fields are required.';
            $messageType = 'danger';
        } elseif (!password_verify($currentPw, $currentUser['password_hash'])) {
            $message     = 'Current password is incorrect.';
            $messageType = 'danger';
        } elseif ($newPw !== $confirmPw) {
            $message     = 'New passwords do not match.';
            $messageType = 'danger';
        } elseif (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{8,}$/', $newPw)) {
            $message     = 'New password must be 8+ chars with uppercase, lowercase, number, and symbol.';
            $messageType = 'danger';
        } else {
            $stmt = $pdo->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?");
            if ($stmt->execute([password_hash($newPw, PASSWORD_DEFAULT), $userId])) {
                $message = 'Password changed successfully.';
            } else {
                $message     = 'Failed to change password.';
                $messageType = 'danger';
            }
        }
    }
}

$currentPage = 'settings';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $pageTitle = 'Settings – JDB Parts Admin'; require_once __DIR__ . '/partials/head.php'; ?>
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
                <h5 class="mb-0 fw-semibold">Settings</h5>
                <small class="text-muted">Manage your account and preferences</small>
            </div>
        </div>
        <div class="dropdown">
            <a href="#" class="d-flex align-items-center gap-2 text-decoration-none dropdown-toggle"
               data-bs-toggle="dropdown">
                <img src="<?= $avatarUrl ?>" width="36" height="36" class="rounded-circle" alt="">
                <span class="d-none d-sm-inline fw-medium"><?= htmlspecialchars($user_name) ?></span>
            </a>
            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                <li><a class="dropdown-item text-danger" href="../auth/logout.php">
                    <i class="bi bi-box-arrow-left me-2"></i>Logout</a></li>
            </ul>
        </div>
    </header>

    <?php if ($message): ?>
        <div class="alert alert-<?= $messageType ?> alert-dismissible fade show mb-4" role="alert">
            <i class="bi bi-<?= $messageType === 'success' ? 'check-circle' : 'exclamation-triangle' ?> me-2"></i>
            <?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4">

        <!-- Profile -->
        <div class="col-lg-6">
            <div class="card shadow-sm border-0 rounded-3 h-100">
                <div class="card-header bg-white py-3 border-bottom">
                    <h6 class="mb-0 fw-semibold"><i class="bi bi-person me-2 text-primary"></i>Profile Information</h6>
                </div>
                <div class="card-body p-4">
                    <form method="POST" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <div class="row g-3 mb-3">
                            <div class="col-6">
                                <label class="form-label fw-semibold">First Name <span class="text-danger">*</span></label>
                                <input type="text" name="firstname" class="form-control"
                                       value="<?= htmlspecialchars($currentUser['firstname'] ?? '') ?>" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label fw-semibold">Last Name <span class="text-danger">*</span></label>
                                <input type="text" name="lastname" class="form-control"
                                       value="<?= htmlspecialchars($currentUser['lastname'] ?? '') ?>" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Email <span class="text-danger">*</span></label>
                            <input type="email" name="email" class="form-control"
                                   value="<?= htmlspecialchars($currentUser['email'] ?? '') ?>" required>
                        </div>
                        <div class="mb-4">
                            <label class="form-label fw-semibold">Phone</label>
                            <input type="tel" name="phone" class="form-control"
                                   value="<?= htmlspecialchars($currentUser['phone'] ?? '') ?>">
                        </div>
                        <button type="submit" name="update_profile" class="btn btn-primary w-100">
                            <i class="bi bi-save me-1"></i> Save Changes
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Password -->
        <div class="col-lg-6">
            <div class="card shadow-sm border-0 rounded-3 h-100">
                <div class="card-header bg-white py-3 border-bottom">
                    <h6 class="mb-0 fw-semibold"><i class="bi bi-shield-lock me-2 text-warning"></i>Change Password</h6>
                </div>
                <div class="card-body p-4">
                    <form method="POST" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Current Password</label>
                            <div class="input-group">
                                <input type="password" name="current_password" class="form-control" required
                                       id="cur_pw" autocomplete="current-password">
                                <button type="button" class="btn btn-outline-secondary toggle-pw" data-target="cur_pw">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">New Password</label>
                            <div class="input-group">
                                <input type="password" name="new_password" class="form-control" required
                                       id="new_pw" autocomplete="new-password">
                                <button type="button" class="btn btn-outline-secondary toggle-pw" data-target="new_pw">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                            <div class="form-text">
                                Min 8 chars · uppercase · lowercase · number · symbol (@$!%*?&)
                            </div>
                        </div>
                        <div class="mb-4">
                            <label class="form-label fw-semibold">Confirm New Password</label>
                            <div class="input-group">
                                <input type="password" name="confirm_password" class="form-control" required
                                       id="conf_pw" autocomplete="new-password">
                                <button type="button" class="btn btn-outline-secondary toggle-pw" data-target="conf_pw">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>
                        <button type="submit" name="change_password" class="btn btn-warning w-100">
                            <i class="bi bi-key me-1"></i> Update Password
                        </button>
                    </form>
                </div>
            </div>
        </div>

    </div>
</div>

<script src="../assets/js/bootstrap_buddle.js"></script>
<script src="../assets/js/dashboard_sidebar.js"></script>
<script>
document.querySelectorAll('.toggle-pw').forEach(btn => {
    btn.addEventListener('click', function () {
        const inp  = document.getElementById(this.dataset.target);
        const icon = this.querySelector('i');
        if (inp.type === 'password') {
            inp.type = 'text';
            icon.classList.replace('bi-eye', 'bi-eye-slash');
        } else {
            inp.type = 'password';
            icon.classList.replace('bi-eye-slash', 'bi-eye');
        }
    });
});
</script>
</body>
</html>