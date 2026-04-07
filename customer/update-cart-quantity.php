<?php
// customer/update-cart-quantity.php
session_start();
require_once '../classes/Database.php';
require_once '../classes/CartService.php';
require_once '../classes/User.php';

if (!User::isLoggedIn() || $_SESSION['user_role'] !== 'customer') {
    http_response_code(403);
    echo json_encode(['success' => false]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false]);
    exit;
}

if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Security check failed']);
    exit;
}

$productId = (int)($_POST['product_id'] ?? 0);
$quantity  = max(1, (int)($_POST['quantity'] ?? 1));

if ($productId <= 0) {
    echo json_encode(['success' => false]);
    exit;
}

$pdo = Database::getInstance()->getConnection();
$cartService = new CartService($pdo);

$result = $cartService->updateItem($productId, $quantity);

echo json_encode([
    'success' => $result['success'],
    'message' => $result['message'] ?? ''
]);