<?php
/**
 * _sidebar.php  – shared sidebar include for all admin pages.
 *
 * Usage:  require_once '_sidebar.php';
 * Expects $currentPage to be set before including, e.g.:
 *   $currentPage = 'dashboard';
 */

$navItems = [
    ['page' => 'dashboard', 'href' => 'dashboard.php',  'icon' => 'bi-house-door',  'label' => 'Dashboard'],
    ['page' => 'orders',    'href' => 'orders.php',     'icon' => 'bi-cart',        'label' => 'Orders'],
    ['page' => 'products',  'href' => 'product.php',    'icon' => 'bi-box-seam',    'label' => 'Products'],
    ['page' => 'customers', 'href' => 'customers.php',  'icon' => 'bi-people',      'label' => 'Customers'],
    ['page' => 'categories','href' => 'categories.php', 'icon' => 'bi-folder',      'label' => 'Categories'],
    ['page' => 'analytics', 'href' => 'analytics.php',  'icon' => 'bi-graph-up',    'label' => 'Analytics'],
    ['page' => 'settings',  'href' => 'settings.php',   'icon' => 'bi-gear',        'label' => 'Settings'],
];
$currentPage = $currentPage ?? '';
?>
<!-- Overlay -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- Sidebar -->
<nav class="sidebar" id="sidebar" aria-label="Main navigation">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <a href="dashboard.php" class="logo text-decoration-none">
            <i class="bi bi-shop-window"></i>
            <span class="logo-text">JDB Parts</span>
        </a>
        <button class="btn btn-sm text-white d-lg-none" id="closeSidebar" aria-label="Close sidebar">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>

    <ul class="nav flex-column mt-3">
        <?php foreach ($navItems as $item): ?>
        <li class="nav-item mb-1">
            <a href="<?= $item['href'] ?>"
               class="nav-link<?= $currentPage === $item['page'] ? ' active' : '' ?>">
                <i class="bi <?= $item['icon'] ?> fs-5"></i>
                <span><?= $item['label'] ?></span>
            </a>
        </li>
        <?php endforeach; ?>
    </ul>

    <div class="mt-auto pt-4" style="border-top:1px solid rgba(255,255,255,0.08); margin-top:auto;">
        <a href="../auth/logout.php" class="nav-link text-danger">
            <i class="bi bi-box-arrow-left fs-5"></i>
            <span>Logout</span>
        </a>
    </div>
</nav>