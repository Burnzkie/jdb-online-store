<?php
session_start();
require_once '../classes/Database.php';
require_once '../classes/User.php';

if (!User::isLoggedIn()) { header("Location: ../login.php"); exit; }

$orderId = (int)($_GET['order_id'] ?? 0);
if (!$orderId) { header("Location: orders.php"); exit; }

$pdo = Database::getInstance()->getConnection();

// Mark order as paid
$pdo->prepare("
    UPDATE orders
    SET payment_status = 'paid', updated_at = NOW()
    WHERE id = ? AND user_id = ?
")->execute([$orderId, $_SESSION['user_id']]);

$_SESSION['flash'] = ['type' => 'success', 'message' => 'Payment successful! Your order is confirmed.'];
header("Location: order-confirmation.php?order_id=$orderId");
exit;