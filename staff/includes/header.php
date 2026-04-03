<?php
// Guard against duplicate session_start() calls across included files
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Strict role check
if (!isset($_SESSION['user_id'], $_SESSION['user_role']) || $_SESSION['user_role'] !== 'staff') {
    header('Location: ../../auth/login.php');
    exit;
}

require_once '../../classes/Database.php';
require_once '../../classes/User.php';
require_once '../../classes/Product.php';
require_once '../../classes/Order.php';
require_once '../../classes/Customer.php';
require_once '../../classes/Security.php';

// Set security headers on every page
Security::setSecurityHeaders();

// Initialize CSRF token
$csrfToken = Security::generateCsrfToken();

$db  = Database::getInstance();
$pdo = $db->getConnection();

$userModel   = new User($pdo);
$currentUser = $userModel->getUserById((int)$_SESSION['user_id']);

if (!$currentUser) {
    session_destroy();
    header('Location: ../../auth/login.php');
    exit;
}

// Models
$productModel  = new Product($pdo);
$orderModel    = new Order($pdo);
$customerModel = new Customer($pdo);

// User display info
$user_name   = trim(($currentUser['firstname'] ?? '') . ' ' . ($currentUser['lastname'] ?? ''));
$avatar_name = urlencode($user_name ?: 'Staff User');

// ── Build the correct web root path ──────────────────────────────────────────
// Pages live at:  /jdb_parts/staff/public/somepage.php
//   dirname x1  = /jdb_parts/staff/public
//   dirname x2  = /jdb_parts/staff
//   dirname x3  = /jdb_parts                ← project root (where /uploads/ lives)
//
// DB stores profile_picture as: uploads/profiles/profile_3_xxx.jpg
// Final URL becomes:            /jdb_parts/uploads/profiles/profile_3_xxx.jpg
$projectRoot = rtrim(dirname(dirname(dirname($_SERVER['SCRIPT_NAME']))), '/');

// Avatar URL — use updated_at as cache-buster so the new photo shows immediately
// after upload without having to hard-refresh.
$avatarCacheBust = !empty($currentUser['updated_at'])
    ? strtotime($currentUser['updated_at'])
    : time();

$avatarFallback = 'https://ui-avatars.com/api/?name=' . $avatar_name . '&background=667eea&color=fff&size=128';

$avatarBase = !empty($currentUser['profile_picture'])
    ? $projectRoot . '/' . ltrim($currentUser['profile_picture'], '/') . '?v=' . $avatarCacheBust
    : $avatarFallback;

$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Panel - JDB Parts</title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="../../assets/css/dashboard.css">

    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f5f7ff; }
    </style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- Sidebar -->
<nav class="sidebar" id="sidebar">
    <div class="d-flex justify-content-between align-items-center mb-5">
        <div class="logo d-flex align-items-center gap-3 text-light">
            <i class="bi bi-tools fs-4"></i>
            <span>JDB Parts Staff</span>
        </div>
        <button class="btn btn-sm text-muted d-lg-none" id="closeSidebar" aria-label="Close sidebar">&times;</button>
    </div>

    <ul class="nav flex-column mt-3">
        <?php
        $navItems = [
            ['href' => 'dashboard.php',  'icon' => 'bi-house-door', 'label' => 'Dashboard'],
            ['href' => 'orders.php',     'icon' => 'bi-cart',       'label' => 'Orders'],
            ['href' => 'products.php',   'icon' => 'bi-box-seam',   'label' => 'Products'],
            ['href' => 'customers.php',  'icon' => 'bi-people',     'label' => 'Customers'],
        ];
        foreach ($navItems as $item):
            $active = $currentPage === $item['href'] ? 'active' : '';
        ?>
        <li class="nav-item mb-2">
            <a href="<?= $item['href'] ?>" class="nav-link <?= $active ?> d-flex align-items-center gap-3 px-3 py-2 rounded">
                <i class="bi <?= $item['icon'] ?> fs-5"></i>
                <span><?= $item['label'] ?></span>
            </a>
        </li>
        <?php endforeach; ?>
    </ul>
</nav>

<!-- Main Content Wrapper -->
<div class="main-content" id="mainContent">

    <!-- Top Header -->
    <header class="bg-white shadow-sm rounded p-4 mb-4 sticky-top" style="top: 0; z-index: 1000;">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
            <div class="d-flex align-items-center gap-3">
                <button class="btn btn-white p-2 d-lg-none" id="sidebarToggle" aria-label="Toggle sidebar">
                    <i class="bi bi-list fs-3"></i>
                </button>
                <div>
                    <h4 class="mb-0">Welcome back, <strong><?= htmlspecialchars($user_name, ENT_QUOTES, 'UTF-8') ?></strong></h4>
                    <small class="text-muted">Staff control panel</small>
                </div>
            </div>

            <div class="d-flex align-items-center gap-3">
                <div class="position-relative d-none d-md-block">
                    <input type="text" class="form-control rounded-pill ps-5" placeholder="Search..." style="width: 260px;">
                    <i class="bi bi-search position-absolute top-50 start-3 translate-middle-y text-muted"></i>
                </div>

                <div class="dropdown">
                    <a href="#" class="d-flex align-items-center text-decoration-none dropdown-toggle"
                       data-bs-toggle="dropdown" aria-expanded="false">
                        <img src="<?= htmlspecialchars($avatarBase, ENT_QUOTES, 'UTF-8') ?>"
                             alt="<?= htmlspecialchars($user_name, ENT_QUOTES, 'UTF-8') ?>"
                             width="40" height="40"
                             class="rounded-circle object-fit-cover border border-light shadow-sm"
                             onerror="this.onerror=null;this.src='<?= htmlspecialchars($avatarFallback, ENT_QUOTES, 'UTF-8') ?>'">
                        <span class="ms-2 d-none d-sm-inline"><?= htmlspecialchars($user_name, ENT_QUOTES, 'UTF-8') ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="profile.php"><i class="bi bi-person me-2"></i> My Profile</a></li>
                        <li><a class="dropdown-item" href="settings.php"><i class="bi bi-gear me-2"></i> Settings</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="../../auth/logout.php"><i class="bi bi-box-arrow-right me-2"></i> Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </header>

    <!-- Page content -->
    <main class="px-3 px-lg-4">