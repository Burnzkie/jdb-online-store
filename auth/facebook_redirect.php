<?php
session_start();
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/SocialAuth.php';

$pdo        = Database::getInstance()->getConnection();
$socialAuth = new SocialAuth($pdo);

header('Location: ' . $socialAuth->getFacebookAuthUrl());
exit;