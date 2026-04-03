<?php
// customer/update-cart.php
session_start();
require_once '../classes/Database.php';
require_once '../classes/CartService.php';
require_once '../classes/User.php';

if (!User::isLoggedIn() || $_SESSION['user_role'] !== 'customer') {
    header("Location: ../login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: cart.php");
    exit;
}

$pdo         = Database::getInstance()->getConnection();
$cartService = new CartService($pdo);

// CSRF validation
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Security validation failed.'];
    header("Location: cart.php");
    exit;
}

$hasWarning = false;

if (isset($_POST['quantities']) && is_array($_POST['quantities'])) {
    foreach ($_POST['quantities'] as $pid => $qty) {
        $pid = (int)$pid;
        $qty = (int)$qty;

        $result = $cartService->updateItem($pid, $qty);

        if (!$result['success']) {
            $hasWarning = true;
        }
    }
}

$_SESSION['flash'] = $hasWarning
    ? ['type' => 'warning', 'message' => 'Some quantities were adjusted due to stock limits.']
    : ['type' => 'success', 'message' => 'Cart updated successfully.'];

header("Location: cart.php");
exit;