<?php
session_start();
require_once '../classes/Database.php';
require_once '../classes/User.php';

if (!User::isLoggedIn()) { header("Location: ../login.php"); exit; }

$orderId = (int)($_GET['order_id'] ?? 0);
if (!$orderId) { header("Location: cart.php"); exit; }

$pdo = Database::getInstance()->getConnection();

// Restore stock and cancel the order
$pdo->beginTransaction();
try {
    $items = $pdo->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = ?");
    $items->execute([$orderId]);
    foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $pdo->prepare("UPDATE products SET stock = stock + ? WHERE id = ?")
            ->execute([$item['quantity'], $item['product_id']]);
    }
    $pdo->prepare("UPDATE orders SET status = 'cancelled', payment_status = 'cancelled' WHERE id = ? AND user_id = ?")
        ->execute([$orderId, $_SESSION['user_id']]);
    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
}

$_SESSION['flash'] = ['type' => 'warning', 'message' => 'Payment was cancelled. Your order has been voided.'];
header("Location: cart.php");
exit;