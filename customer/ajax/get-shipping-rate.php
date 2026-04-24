<?php
session_start();
require_once '../../classes/ShippingService.php';

header('Content-Type: application/json');

$province = trim($_GET['province'] ?? '');
if (empty($province)) {
    echo json_encode(['rate' => 150.00]);
    exit;
}

$rate = ShippingService::calculate($province);
echo json_encode(['rate' => $rate]);