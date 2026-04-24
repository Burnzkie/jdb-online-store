<?php
require_once '../classes/Database.php';

// Read raw payload
$payload   = file_get_contents('php://input');
$signature = $_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '';

require_once '../classes/PaymentService.php';
$payService = new PaymentService();

if (!$payService->verifyWebhook($payload, $signature)) {
    http_response_code(401);
    exit('Unauthorized');
}

$event = json_decode($payload, true);
$type  = $event['data']['attributes']['type'] ?? '';

if ($type === 'checkout_session.payment.paid') {
    $sessionId = $event['data']['attributes']['data']['id'] ?? '';
    $pdo       = Database::getInstance()->getConnection();

    // Find order by session ID and mark as paid
    $stmt = $pdo->prepare("
        UPDATE orders
        SET payment_status = 'paid', updated_at = NOW()
        WHERE payment_session_id = ? AND payment_status = 'pending'
    ");
    $stmt->execute([$sessionId]);
}

http_response_code(200);
echo 'OK';