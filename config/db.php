<?php
require_once __DIR__ . '/../classes/Database.php';

function getDB(): PDO {
    static $db = null;
    if ($db === null) {
        $database = new Database();
        $db = $database->getConnection();
    }
    return $db;
}