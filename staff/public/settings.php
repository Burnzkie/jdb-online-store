<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../includes/header.php';

$currentUser = $userModel->getUserById((int)$_SESSION['user_id']);

// Can't redirect (HTML already sent) — show inline error
if (!$currentUser) {
    echo '<div class="alert alert-danger m-4">Session expired. '
       . '<a href="../../public/login.php" class="alert-link">Please log in again.</a></div>';
    require_once '../includes/footer.php';
    exit;
}

$profileSuccess = '';
$profileError   = '';
$passSuccess    = '';
$passError      = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Security::requireCsrfToken();

    // ── Profile Update ────────────────────────────────────────────────────────
    if (isset($_POST['update_profile'])) {
        $firstname = trim($_POST['firstname'] ?? '');
        $lastname  = trim($_POST['lastname']  ?? '');
        $email     = trim($_POST['email']     ?? '');
        $phone     = trim($_POST['phone']     ?? '');

        if ($firstname === '' || $lastname === '' || $email === '') {
            $profileError = 'First name, last name, and email are required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $profileError = 'Please enter a valid email address.';
        } elseif ($phone !== '' && !Security::validatePhone($phone)) {
            $profileError = 'Please enter a valid phone number.';
        } else {
            $existing = $userModel->getUserByEmail($email);
            if ($existing && (int)$existing['id'] !== (int)$_SESSION['user_id']) {
                $profileError = 'This email is already used by another account.';
            } elseif ($userModel->updateUserProfile(
                (int)$_SESSION['user_id'],
                Security::sanitizeString($firstname, 100),
                Security::sanitizeString($lastname, 100),
                $email,
                $phone !== '' ? $phone : null
            )) {
                $profileSuccess = 'Profile updated successfully!';
                $currentUser    = $userModel->getUserById((int)$_SESSION['user_id']);
            } else {
                $profileError = 'Failed to update profile. Please try again.';
            }
        }
    }

    // ── Password Change ───────────────────────────────────────────────────────
    if (isset($_POST['change_password'])) {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword     = $_POST['new_password']     ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
            $passError = 'All password fields are required.';
        } elseif ($newPassword !== $confirmPassword) {
            $passError = 'New password and confirmation do not match.';
        } else {
            $strength = Security::validatePassword($newPassword);
            if (!$strength['valid']) {
                $passError = implode(' ', $strength['errors']);
            } else {
                $freshUser = $userModel->getUserById((int)$_SESSION['user_id']);
                if ($freshUser && password_verify($currentPassword, $freshUser['password_hash'])) {
                    $stmt = $pdo->prepare("UPDATE users SET password_hash = :hash, updated_at = NOW() WHERE id = :id");
                    if ($stmt->execute([':hash' => Security::hashPassword($newPassword), ':id' => (int)$_SESSION['user_id']])) {
                        $passSuccess = 'Password changed successfully!';
                    } else {
                        $passError = 'Failed to update password. Please try again.';
                    }
                } else {
                    $passError = 'Current password is incorrect.';
                }
            }
        }
    }

    // ── Account Deactivation ──────────────────────────────────────────────────
    if (isset($_POST['deactivate_account'])) {
        if (isset($_POST['confirm_deactivate']) && $_POST['confirm_deactivate'] === 'yes') {
            if ($userModel->deactivateUser((int)$_SESSION['user_id'])) {
                session_destroy();
                // JS redirect because HTML already sent
                echo '<script>window.location.replace("../../public/login.php?msg=account_deactivated");</script>';
                exit;
            } else {
                $profileError = 'Failed to deactivate account. Please try again.';
            }
        } else {
            $profileError = 'Please check the confirmation box to deactivate your account.';
        }
    }
}
?>

<div class="container py-4">
    <h2 class="mb-4"><i class="bi bi-gear me-2"></i>Settings</h2>

    <div class="row">
        <div class="col-lg-8">

            <!-- 1. Profile Information -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-light">
                    <h5 class="mb-0">Edit Profile Information</h5>
                </div>
                <div class="card-body">
                    <?php if ($profileSuccess): ?>
                        <div class="alert alert-success alert-dismissible fade show">
                            <?= htmlspecialchars($profileSuccess, ENT_QUOTES, 'UTF-8') ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php elseif ($profileError): ?>
                        <div class="alert alert-danger alert-dismissible fade show">
                            <?= htmlspecialchars($profileError, ENT_QUOTES, 'UTF-8') ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <form method="POST" novalidate>
                        <?= Security::csrfField() ?>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="firstname">First Name</label>
                                <input type="text" id="firstname" name="firstname" class="form-control"
                                       value="<?= htmlspecialchars($currentUser['firstname'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                       maxlength="100" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="lastname">Last Name</label>
                                <input type="text" id="lastname" name="lastname" class="form-control"
                                       value="<?= htmlspecialchars($currentUser['lastname'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                       maxlength="100" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="email">Email Address</label>
                                <input type="email" id="email" name="email" class="form-control"
                                       value="<?= htmlspecialchars($currentUser['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                       required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="phone">Phone Number</label>
                                <input type="tel" id="phone" name="phone" class="form-control"
                                       value="<?= htmlspecialchars($currentUser['phone'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                       maxlength="20">
                            </div>
                        </div>
                        <div class="mt-4">
                            <button type="submit" name="update_profile" class="btn btn-primary px-4">
                                Save Changes
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- 2. Change Password -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-light">
                    <h5 class="mb-0">Change Password</h5>
                </div>
                <div class="card-body">
                    <?php if ($passSuccess): ?>
                        <div class="alert alert-success alert-dismissible fade show">
                            <?= htmlspecialchars($passSuccess, ENT_QUOTES, 'UTF-8') ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php elseif ($passError): ?>
                        <div class="alert alert-danger alert-dismissible fade show">
                            <?= htmlspecialchars($passError, ENT_QUOTES, 'UTF-8') ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <form method="POST">
                        <?= Security::csrfField() ?>
                        <div class="mb-3">
                            <label class="form-label" for="current_password">Current Password</label>
                            <input type="password" id="current_password" name="current_password"
                                   class="form-control" required autocomplete="current-password">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="new_password">New Password</label>
                            <input type="password" id="new_password" name="new_password"
                                   class="form-control" required autocomplete="new-password">
                            <div class="form-text">Min 8 characters with uppercase, lowercase, number, and special character.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="confirm_password">Confirm New Password</label>
                            <input type="password" id="confirm_password" name="confirm_password"
                                   class="form-control" required autocomplete="new-password">
                        </div>
                        <button type="submit" name="change_password" class="btn btn-primary px-4">
                            Update Password
                        </button>
                    </form>
                </div>
            </div>

            <!-- 3. Danger Zone -->
            <div class="card border-danger shadow-sm">
                <div class="card-header bg-danger text-white">
                    <h5 class="mb-0">
                        <i class="bi bi-exclamation-triangle me-2"></i>Danger Zone &mdash; Deactivate Account
                    </h5>
                </div>
                <div class="card-body">
                    <p class="text-danger fw-bold">
                        This will immediately log you out and block future logins until reactivated by an administrator.
                        Your data is preserved.
                    </p>

                    <form method="POST"
                          onsubmit="return confirm('Are you sure you want to deactivate your account? You will be logged out immediately.');">
                        <?= Security::csrfField() ?>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="confirm_deactivate"
                                   value="yes" id="confirmDeactivate" required>
                            <label class="form-check-label text-danger fw-bold" for="confirmDeactivate">
                                I understand and want to deactivate my account
                            </label>
                        </div>
                        <button type="submit" name="deactivate_account" class="btn btn-danger px-4">
                            Deactivate My Account
                        </button>
                    </form>
                </div>
            </div>

        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>