<?php
define('DB_HOST', getenv('INVENTORY_DB_HOST') ?: 'localhost');
define('DB_USER', getenv('INVENTORY_DB_USER') ?: 'root');
define('DB_PASS', getenv('INVENTORY_DB_PASS') !== false ? getenv('INVENTORY_DB_PASS') : '');
define('DB_NAME', getenv('INVENTORY_DB_NAME') ?: 'norton_inventory');

function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            error_log('Inventory database connection failed: ' . $e->getMessage());
            throw new RuntimeException('The inventory database is unavailable. Check the connection settings and try again.');
        }
    }
    return $pdo;
}