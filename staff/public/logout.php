<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../classes/User.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

User::logout();
header('Location: ../auth/login.php');
exit;