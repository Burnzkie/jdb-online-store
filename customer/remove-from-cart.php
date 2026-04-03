<?php
// customer/remove-from-cart.php
session_start();
require_once '../classes/Database.php';
require_once '../classes/CartService.php';
require_once '../classes/User.php';

if (!User::isLoggedIn() || $_SESSION['user_role'] !== 'customer') {
    header("Location: ../login.php");
    exit;
}

$pdo         = Database::getInstance()->getConnection();
$cartService = new CartService($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Security validation failed.'];
        header("Location: cart.php");
        exit;
    }
    $productId = (int)($_POST['product_id'] ?? 0);
} else {
    $productId = (int)($_GET['id'] ?? 0);
}

if ($productId > 0) {
    $result = $cartService->removeItem($productId);
    $_SESSION['flash'] = [
        'type'    => $result['success'] ? 'success' : 'warning',
        'message' => $result['message'],
    ];
}

header("Location: cart.php");
exit;