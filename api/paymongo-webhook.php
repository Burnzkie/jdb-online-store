<?php
/**
 * webhook/paymongo-webhook.php
 */

require_once '../classes/Env.php';
Env::load(__DIR__ . '/../.env');

require_once '../classes/Database.php';
require_once '../classes/PaymentService.php';

$payload   = file_get_contents('php://input');
$signature = $_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '';

if (empty($payload)) {
    http_response_code(400);
    exit('Bad Request: empty payload');
}

$payService = new PaymentService();
if (!$payService->verifyWebhook($payload, $signature)) {
    error_log("PayMongo webhook: signature verification failed. Sig: $signature");
    http_response_code(401);
    exit('Unauthorized');
}

$event = json_decode($payload, true);
$type  = $event['data']['attributes']['type'] ?? '';

error_log("PayMongo webhook received: $type");

$pdo = Database::getInstance()->getConnection();

switch ($type) {

    case 'checkout_session.payment.paid':
        $sessionId = $event['data']['attributes']['data']['id'] ?? '';

        if (empty($sessionId)) {
            error_log('PayMongo webhook: missing session ID in paid event.');
            break;
        }

        $stmt = $pdo->prepare("
            UPDATE orders
            SET    status         = 'processing',
                   payment_status = 'paid',
                   updated_at     = NOW()
            WHERE  payment_session_id = ?
              AND  payment_status     = 'pending'
        ");
        $stmt->execute([$sessionId]);

        error_log("PayMongo webhook: marked " . $stmt->rowCount() . " order(s) as paid for session $sessionId.");
        break;

    case 'checkout_session.payment.failed':
        $sessionId = $event['data']['attributes']['data']['id'] ?? '';

        if (!empty($sessionId)) {
            $pdo->prepare("
                UPDATE orders
                SET    payment_status = 'failed',
                       updated_at     = NOW()
                WHERE  payment_session_id = ?
                  AND  payment_status     = 'pending'
            ")->execute([$sessionId]);

            error_log("PayMongo webhook: payment failed for session $sessionId.");
        }
        break;

    default:
        error_log("PayMongo webhook: unhandled event '$type' — ignoring.");
        break;
}

http_response_code(200);
echo 'OK';