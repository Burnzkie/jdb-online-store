<?php
// customer/buy-now.php
session_start();
require_once '../classes/Database.php';
require_once '../classes/CartService.php';
require_once '../classes/User.php';

if (!User::isLoggedIn() || $_SESSION['user_role'] !== 'customer') {
    header("Location: ../login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['product_id'])) {
    header("Location: products.php");
    exit;
}

// CSRF check
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Security validation failed. Please try again.'];
    header("Location: products.php");
    exit;
}

$productId = (int)$_POST['product_id'];
$quantity  = max(1, (int)($_POST['quantity'] ?? 1));

$pdo         = Database::getInstance()->getConnection();
$cartService = new CartService($pdo);

// Buy-Now: replace cart with just this one item
$cartService->clear();

$result = $cartService->addItem($productId, $quantity);

if (!$result['success']) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => $result['message']];
    header("Location: products.php");
    exit;
}

header("Location: checkout.php");
exit;