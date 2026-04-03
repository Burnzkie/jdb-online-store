<?php
// customer/add-to-cart.php
session_start();
require_once '../classes/Database.php';
require_once '../classes/CartService.php';
require_once '../classes/User.php';

if (!User::isLoggedIn() || $_SESSION['user_role'] !== 'customer') {
    header("Location: ../login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: products.php");
    exit;
}

// CSRF check
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Security validation failed. Please try again.'];
    header("Location: products.php");
    exit;
}

if (!isset($_POST['product_id'], $_POST['quantity']) || !is_numeric($_POST['quantity'])) {
    header("Location: products.php?error=invalid_input");
    exit;
}

$productId = (int)$_POST['product_id'];
$quantity  = max(1, (int)$_POST['quantity']);

$pdo         = Database::getInstance()->getConnection();
$cartService = new CartService($pdo);

$result = $cartService->addItem($productId, $quantity);

$_SESSION['flash'] = [
    'type'    => $result['success'] ? 'success' : 'warning',
    'message' => $result['message'],
];

header("Location: products.php");
exit;