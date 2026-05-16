<?php
/**
 * customer/payment-cancel.php
 * Called by PayMongo when the customer cancels or abandons payment.
 * No order was created yet, so just clear the pending session
 * and send the customer back to checkout with their items still selected.
 */

session_start();

require_once '../classes/User.php';

if (!User::isLoggedIn()) {
    header("Location: ../login.php");
    exit;
}

$pending = $_SESSION['pending_order'] ?? null;

// Build redirect URL with selected items + qtys so checkout pre-selects them
$redirectUrl = 'checkout.php';
if ($pending) {
    $items = $pending['items'] ?? [];
    $pids  = array_column($items, 'product_id');
    $qtys  = array_column($items, 'quantity');

    if (!empty($pids)) {
        $redirectUrl .= '?selected=' . implode(',', $pids) . '&qtys=' . implode(',', $qtys);
    }
}

// Keep pending_order in session so checkout.php can pre-fill the form fields.
// It will be cleared once the customer successfully places an order.
// Just mark it as "returned_from_payment" so checkout knows to show the notice.
if ($pending) {
    $_SESSION['pending_order']['returned'] = true;
}

$_SESSION['flash'] = [
    'type'    => 'warning',
    'message' => 'Payment was cancelled. Your items are still here — please choose <strong>Cash on Delivery</strong> or try again.',
];

header("Location: $redirectUrl");
exit;