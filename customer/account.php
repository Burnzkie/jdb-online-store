<?php
// customer/account.php
session_start();
require_once '../classes/Database.php';
require_once '../classes/User.php';

if (!User::isLoggedIn() || $_SESSION['user_role'] !== 'customer') {
    header("Location: ../login.php");
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}


$pdo     = Database::getInstance()->getConnection();
$userObj = new User($pdo);
$user    = $userObj->getUserById($_SESSION['user_id']);

$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $flash = ['type' => 'danger', 'message' => 'Security validation failed. Please try again.'];

    } elseif (isset($_POST['update_profile'])) {

        $fn = trim($_POST['firstname'] ?? '');
        $ln = trim($_POST['lastname']  ?? '');
        $em = trim($_POST['email']     ?? '');
        $ph = trim($_POST['phone']     ?? '');

        if (!$fn || !$ln || !$em) {
            $flash = ['type' => 'danger', 'message' => 'First name, last name, and email are required.'];
        } elseif (!filter_var($em, FILTER_VALIDATE_EMAIL)) {
            $flash = ['type' => 'danger', 'message' => 'Invalid email format.'];
        } else {
            if ($em !== $user['email']) {
                $chk = $pdo->prepare("SELECT 1 FROM users WHERE email = ? AND id != ?");
                $chk->execute([$em, $user['id']]);
                if ($chk->fetch()) {
                    $flash = ['type' => 'danger', 'message' => 'That email is already in use by another account.'];
                }
            }
            if (!$flash) {
                $userObj->updateUserProfile($user['id'], $fn, $ln, $em, $ph);
                $_SESSION['user_name']  = "$fn $ln";
                $_SESSION['user_email'] = $em;
                $user  = $userObj->getUserById($user['id']);
                $flash = ['type' => 'success', 'message' => 'Profile updated successfully.'];
            }
        }

    } elseif (isset($_POST['change_password'])) {

        $cur = $_POST['current_password'] ?? '';
        $new = $_POST['new_password']     ?? '';
        $con = $_POST['confirm_password'] ?? '';

        if (!password_verify($cur, $user['password_hash'])) {
            $flash = ['type' => 'danger', 'message' => 'Current password is incorrect.'];
        } elseif ($new !== $con) {
            $flash = ['type' => 'danger', 'message' => 'New passwords do not match.'];
        } elseif (strlen($new) < 8) {
            $flash = ['type' => 'danger', 'message' => 'Password must be at least 8 characters.'];
        } else {
            $hash = password_hash($new, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$hash, $user['id']]);
            $flash = ['type' => 'success', 'message' => 'Password changed successfully.'];
        }
    }
}

$fullName   = trim(($user['firstname'] ?? '') . ' ' . ($user['lastname'] ?? ''));
$initials   = strtoupper(substr($user['firstname'] ?? 'U', 0, 1) . substr($user['lastname'] ?? '', 0, 1));
$memberSince = isset($user['created_at']) ? date('F Y', strtotime($user['created_at'])) : '—';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Account — JDB Parts</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=Sora:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary:      #1a56db;
            --primary-dark: #1344b4;
            --primary-soft: #eff4ff;
            --success:      #0e9f6e;
            --success-soft: #f0fdf9;
            --danger:       #e02424;
            --danger-soft:  #fff5f5;
            --surface:      #ffffff;
            --bg:           #f4f6fb;
            --border:       #e5e9f2;
            --text:         #111827;
            --muted:        #6b7280;
            --sidebar-w:    260px;
            --radius:       14px;
            --shadow:       0 1px 4px rgba(0,0,0,.06), 0 4px 16px rgba(0,0,0,.07);
        }

        * { box-sizing: border-box; }
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            margin: 0;
        }

        /* ── Top Nav ── */
        .top-nav {
            background: var(--surface);
            border-bottom: 1px solid var(--border);
            height: 62px;
            display: flex;
            align-items: center;
            padding: 0 24px;
            position: sticky;
            top: 0;
            z-index: 100;
            gap: 16px;
        }
        .top-nav .brand {
            font-family: 'Sora', sans-serif;
            font-weight: 700;
            font-size: 1.15rem;
            color: var(--primary);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
        }
        .top-nav .brand-icon {
            width: 32px; height: 32px;
            background: var(--primary);
            border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            color: #fff;
            font-size: .8rem;
        }
        .top-nav .nav-links {
            display: flex;
            gap: 4px;
            margin-left: auto;
        }
        .top-nav .nav-links a {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 7px 14px;
            border-radius: 8px;
            font-size: .875rem;
            font-weight: 500;
            color: var(--muted);
            text-decoration: none;
            transition: background .15s, color .15s;
        }
        .top-nav .nav-links a:hover { background: var(--bg); color: var(--text); }
        .top-nav .nav-links a.active { background: var(--primary-soft); color: var(--primary); }

        /* ── Layout ── */
        .page-wrap {
            max-width: 1060px;
            margin: 0 auto;
            padding: 32px 20px 60px;
            display: grid;
            grid-template-columns: 220px 1fr;
            gap: 24px;
            align-items: start;
        }

        /* ── Sidebar ── */
        .sidebar {
            background: var(--surface);
            border-radius: var(--radius);
            border: 1px solid var(--border);
            overflow: hidden;
        }
        .sidebar-avatar {
            padding: 24px 20px 20px;
            text-align: center;
            border-bottom: 1px solid var(--border);
        }
        .avatar-circle {
            width: 72px; height: 72px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary) 0%, #4f8ef7 100%);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-family: 'Sora', sans-serif;
            font-size: 1.5rem;
            font-weight: 700;
            color: #fff;
            margin-bottom: 12px;
            box-shadow: 0 4px 14px rgba(26,86,219,.35);
        }
        .sidebar-name {
            font-weight: 700;
            font-size: .95rem;
            color: var(--text);
            margin: 0 0 2px;
        }
        .sidebar-email {
            font-size: .78rem;
            color: var(--muted);
            margin: 0;
            word-break: break-all;
        }
        .member-chip {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: var(--primary-soft);
            color: var(--primary);
            font-size: .72rem;
            font-weight: 600;
            padding: 3px 10px;
            border-radius: 50px;
            margin-top: 10px;
        }
        .sidebar-nav { padding: 10px 8px; }
        .sidebar-nav a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border-radius: 9px;
            font-size: .875rem;
            font-weight: 500;
            color: var(--muted);
            text-decoration: none;
            transition: background .15s, color .15s;
            margin-bottom: 2px;
        }
        .sidebar-nav a:hover { background: var(--bg); color: var(--text); }
        .sidebar-nav a.active {
            background: var(--primary-soft);
            color: var(--primary);
            font-weight: 600;
        }
        .sidebar-nav a .nav-icon {
            width: 30px; height: 30px;
            border-radius: 7px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: .78rem;
            background: var(--bg);
            flex-shrink: 0;
            transition: background .15s;
        }
        .sidebar-nav a.active .nav-icon { background: rgba(26,86,219,.12); }
        .sidebar-nav .divider {
            height: 1px;
            background: var(--border);
            margin: 8px 4px;
        }

        /* ── Main Content ── */
        .main-content { min-width: 0; }

        .section-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            margin-bottom: 20px;
            overflow: hidden;
        }
        .section-header {
            padding: 18px 24px 16px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .section-header-icon {
            width: 36px; height: 36px;
            border-radius: 9px;
            background: var(--primary-soft);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: .85rem;
        }
        .section-header h2 {
            font-family: 'Sora', sans-serif;
            font-size: 1rem;
            font-weight: 700;
            margin: 0;
            color: var(--text);
        }
        .section-header p {
            font-size: .78rem;
            color: var(--muted);
            margin: 1px 0 0;
        }
        .section-body { padding: 24px; }

        /* ── Form Controls ── */
        .form-group { margin-bottom: 18px; }
        .form-label {
            font-size: .82rem;
            font-weight: 600;
            color: var(--text);
            margin-bottom: 6px;
            display: block;
        }
        .form-label .req { color: var(--danger); margin-left: 2px; }
        .form-label .opt {
            font-weight: 400;
            color: var(--muted);
            font-size: .75rem;
        }
        .form-control {
            width: 100%;
            padding: 10px 14px;
            border: 1.5px solid var(--border);
            border-radius: 9px;
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: .9rem;
            color: var(--text);
            background: var(--surface);
            transition: border-color .2s, box-shadow .2s;
            outline: none;
            -webkit-appearance: none;
        }
        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(26,86,219,.12);
        }
        .form-control::placeholder { color: #b0b7c5; }
        .form-hint {
            font-size: .76rem;
            color: var(--muted);
            margin-top: 5px;
        }

        /* Password field wrapper */
        .pw-wrap { position: relative; }
        .pw-wrap .form-control { padding-right: 44px; }
        .pw-toggle {
            position: absolute;
            right: 13px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: var(--muted);
            font-size: .85rem;
            padding: 4px;
            line-height: 1;
            transition: color .15s;
        }
        .pw-toggle:hover { color: var(--text); }

        /* Password strength bar */
        .strength-bar {
            height: 4px;
            border-radius: 99px;
            background: var(--border);
            margin-top: 8px;
            overflow: hidden;
        }
        .strength-fill {
            height: 100%;
            border-radius: 99px;
            width: 0;
            transition: width .3s, background .3s;
        }
        .strength-label {
            font-size: .73rem;
            font-weight: 600;
            margin-top: 4px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }

        /* ── Buttons ── */
        .btn-primary-solid {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 11px 24px;
            background: var(--primary);
            color: #fff;
            border: none;
            border-radius: 9px;
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: .9rem;
            font-weight: 600;
            cursor: pointer;
            transition: background .2s, transform .15s, box-shadow .2s;
            text-decoration: none;
        }
        .btn-primary-solid:hover {
            background: var(--primary-dark);
            box-shadow: 0 4px 14px rgba(26,86,219,.3);
            transform: translateY(-1px);
        }
        .btn-primary-solid:active { transform: translateY(0); }
        .btn-outline-ghost {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 20px;
            background: transparent;
            color: var(--muted);
            border: 1.5px solid var(--border);
            border-radius: 9px;
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: .875rem;
            font-weight: 500;
            cursor: pointer;
            transition: border-color .2s, color .2s, background .2s;
            text-decoration: none;
        }
        .btn-outline-ghost:hover {
            border-color: var(--text);
            color: var(--text);
            background: var(--bg);
        }

        /* ── Alert / Flash ── */
        .flash-alert {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 14px 18px;
            border-radius: 10px;
            font-size: .875rem;
            font-weight: 500;
            margin-bottom: 20px;
            border: 1.5px solid transparent;
            animation: slideDown .25s ease;
        }
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-8px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .flash-alert.success {
            background: var(--success-soft);
            border-color: #a7f3d0;
            color: #065f46;
        }
        .flash-alert.danger {
            background: var(--danger-soft);
            border-color: #fca5a5;
            color: #991b1b;
        }
        .flash-icon { font-size: 1rem; flex-shrink: 0; margin-top: 1px; }
        .flash-close {
            margin-left: auto;
            background: none;
            border: none;
            cursor: pointer;
            color: inherit;
            opacity: .6;
            font-size: .9rem;
            padding: 0;
            line-height: 1;
            flex-shrink: 0;
        }
        .flash-close:hover { opacity: 1; }

        /* ── Danger Zone ── */
        .danger-section .section-header-icon {
            background: var(--danger-soft);
            color: var(--danger);
        }
        .danger-section .section-header h2 { color: var(--danger); }
        .danger-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
        }
        .danger-row p { font-size: .875rem; color: var(--muted); margin: 4px 0 0; }
        .danger-row h6 { margin: 0; font-weight: 600; font-size: .9rem; }
        .btn-danger-outline {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 9px 18px;
            background: transparent;
            border: 1.5px solid var(--danger);
            color: var(--danger);
            border-radius: 9px;
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: .875rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            white-space: nowrap;
            transition: background .2s, color .2s;
        }
        .btn-danger-outline:hover { background: var(--danger); color: #fff; }

        /* ── Responsive ── */
        @media (max-width: 768px) {
            .page-wrap {
                grid-template-columns: 1fr;
                padding: 16px 16px 48px;
            }
            .sidebar { display: flex; flex-direction: row; flex-wrap: wrap; }
            .sidebar-avatar { flex: 0 0 100%; }
            .sidebar-nav { flex: 0 0 100%; display: flex; flex-wrap: wrap; gap: 4px; padding: 10px; }
            .sidebar-nav a { flex: 1 1 auto; justify-content: center; font-size: .8rem; padding: 8px 10px; }
            .sidebar-nav .divider { display: none; }
            .form-row { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<!-- ── Top Navigation ── -->
<nav class="top-nav">
    <a href="dashboard.php" class="brand">
        <span class="brand-icon"><i class="fas fa-car-side"></i></span>
        JDB Parts
    </a>
    <div class="nav-links">
        <a href="products.php"><i class="fas fa-store"></i> Shop</a>
        <a href="orders.php"><i class="fas fa-box"></i> Orders</a>
        <a href="cart.php"><i class="fas fa-shopping-cart"></i> Cart</a>
        <a href="account.php" class="active"><i class="fas fa-user"></i> Account</a>
        <a href="../auth/logout.php" style="color:#e02424"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>
</nav>

<div class="page-wrap">

    <!-- ── Sidebar ── -->
    <aside class="sidebar">
        <div class="sidebar-avatar">
            <div class="avatar-circle"><?= htmlspecialchars($initials) ?></div>
            <p class="sidebar-name"><?= htmlspecialchars($fullName ?: 'Customer') ?></p>
            <p class="sidebar-email"><?= htmlspecialchars($user['email'] ?? '') ?></p>
            <div class="member-chip">
                <i class="fas fa-star" style="font-size:.6rem;"></i>
                Member since <?= $memberSince ?>
            </div>
        </div>
        
    </aside>

    <!-- ── Main Content ── -->
    <main class="main-content">

        <!-- Flash message -->
        <?php if ($flash): ?>
            <div class="flash-alert <?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" id="flashMsg">
                <span class="flash-icon">
                    <i class="fas <?= $flash['type'] === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?>"></i>
                </span>
                <?= htmlspecialchars($flash['message']) ?>
                <button class="flash-close" onclick="document.getElementById('flashMsg').remove()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        <?php endif; ?>

        <!-- ── Profile Information ── -->
        <div class="section-card">
            <div class="section-header">
                <div class="section-header-icon"><i class="fas fa-user"></i></div>
                <div>
                    <h2>Personal Information</h2>
                    <p>Update your name, email, and contact details</p>
                </div>
            </div>
            <div class="section-body">
                <form method="post" novalidate id="profileForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">
                                First Name <span class="req">*</span>
                            </label>
                            <input type="text" name="firstname" class="form-control"
                                   value="<?= htmlspecialchars($user['firstname'] ?? '') ?>"
                                   placeholder="e.g. Juan"
                                   maxlength="50" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">
                                Last Name <span class="req">*</span>
                            </label>
                            <input type="text" name="lastname" class="form-control"
                                   value="<?= htmlspecialchars($user['lastname'] ?? '') ?>"
                                   placeholder="e.g. Dela Cruz"
                                   maxlength="50" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            Email Address <span class="req">*</span>
                        </label>
                        <input type="email" name="email" class="form-control"
                               value="<?= htmlspecialchars($user['email'] ?? '') ?>"
                               placeholder="you@example.com"
                               maxlength="100" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            Phone Number <span class="opt">(optional)</span>
                        </label>
                        <input type="tel" name="phone" class="form-control"
                               value="<?= htmlspecialchars($user['phone'] ?? '') ?>"
                               placeholder="+63 9XX XXX XXXX"
                               maxlength="20">
                    </div>

                    <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:4px;">
                        <button type="reset" class="btn-outline-ghost">
                            <i class="fas fa-undo"></i> Reset
                        </button>
                        <button type="submit" name="update_profile" class="btn-primary-solid">
                            <i class="fas fa-save"></i> Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ── Change Password ── -->
        <div class="section-card">
            <div class="section-header">
                <div class="section-header-icon"><i class="fas fa-lock"></i></div>
                <div>
                    <h2>Change Password</h2>
                    <p>Keep your account secure with a strong password</p>
                </div>
            </div>
            <div class="section-body">
                <form method="post" novalidate id="passwordForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

                    <div class="form-group">
                        <label class="form-label">Current Password</label>
                        <div class="pw-wrap">
                            <input type="password" name="current_password" class="form-control"
                                   id="currentPw" autocomplete="current-password"
                                   placeholder="Enter your current password" required>
                            <button type="button" class="pw-toggle" onclick="togglePw('currentPw', this)">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">New Password</label>
                        <div class="pw-wrap">
                            <input type="password" name="new_password" class="form-control"
                                   id="newPw" autocomplete="new-password"
                                   placeholder="Min. 8 characters"
                                   minlength="8" required
                                   oninput="checkStrength(this.value)">
                            <button type="button" class="pw-toggle" onclick="togglePw('newPw', this)">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <div class="strength-bar"><div class="strength-fill" id="strengthFill"></div></div>
                        <div class="strength-label" id="strengthLabel" style="color:var(--muted);"></div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Confirm New Password</label>
                        <div class="pw-wrap">
                            <input type="password" name="confirm_password" class="form-control"
                                   id="confirmPw" autocomplete="new-password"
                                   placeholder="Re-enter your new password" required>
                            <button type="button" class="pw-toggle" onclick="togglePw('confirmPw', this)">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <div class="form-hint" id="matchHint"></div>
                    </div>

                    <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:4px;">
                        <button type="reset" class="btn-outline-ghost"
                                onclick="document.getElementById('strengthFill').style.width='0';
                                         document.getElementById('strengthLabel').textContent='';
                                         document.getElementById('matchHint').textContent='';">
                            <i class="fas fa-undo"></i> Clear
                        </button>
                        <button type="submit" name="change_password" class="btn-primary-solid">
                            <i class="fas fa-key"></i> Update Password
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ── Danger Zone ── -->
        <div class="section-card danger-section">
            <div class="section-header">
                <div class="section-header-icon"><i class="fas fa-exclamation-triangle"></i></div>
                <div>
                    <h2>Danger Zone</h2>
                    <p>Irreversible account actions</p>
                </div>
            </div>
            <div class="section-body">
                <div class="danger-row">
                    <div>
                        <h6>Sign out of all devices</h6>
                        <p>Ends all active sessions except the current one.</p>
                    </div>
                    <a href="../auth/logout.php" class="btn-danger-outline">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                </div>
            </div>
        </div>

    </main>
</div><!-- /page-wrap -->

<script>
/* ── Toggle password visibility ── */
function togglePw(id, btn) {
    const input = document.getElementById(id);
    const icon  = btn.querySelector('i');
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.replace('fa-eye', 'fa-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.replace('fa-eye-slash', 'fa-eye');
    }
}

/* ── Password strength indicator ── */
function checkStrength(pw) {
    const fill  = document.getElementById('strengthFill');
    const label = document.getElementById('strengthLabel');
    if (!pw) {
        fill.style.width = '0'; label.textContent = ''; return;
    }

    let score = 0;
    if (pw.length >= 8)  score++;
    if (pw.length >= 12) score++;
    if (/[A-Z]/.test(pw)) score++;
    if (/[0-9]/.test(pw)) score++;
    if (/[^A-Za-z0-9]/.test(pw)) score++;

    const levels = [
        { pct: '20%', color: '#e02424', text: 'Very weak' },
        { pct: '40%', color: '#f59e0b', text: 'Weak' },
        { pct: '60%', color: '#f59e0b', text: 'Fair' },
        { pct: '80%', color: '#0e9f6e', text: 'Strong' },
        { pct: '100%',color: '#059669', text: 'Very strong' },
    ];
    const lvl = levels[Math.max(0, score - 1)];
    fill.style.width     = lvl.pct;
    fill.style.background = lvl.color;
    label.textContent    = lvl.text;
    label.style.color    = lvl.color;
}

/* ── Confirm password match hint ── */
document.getElementById('confirmPw').addEventListener('input', function () {
    const newPw = document.getElementById('newPw').value;
    const hint  = document.getElementById('matchHint');
    if (!this.value) { hint.textContent = ''; return; }
    if (this.value === newPw) {
        hint.style.color   = '#0e9f6e';
        hint.textContent   = '✓ Passwords match';
    } else {
        hint.style.color   = '#e02424';
        hint.textContent   = '✗ Passwords do not match';
    }
});

/* ── Auto-dismiss flash after 5 s ── */
const flash = document.getElementById('flashMsg');
if (flash) setTimeout(() => flash.style.display = 'none', 5000);
</script>

</body>
</html>