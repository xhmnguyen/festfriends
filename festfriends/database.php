<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once("/home/hnguye14/DBNguyen.php");

try {
    $pdo = new PDO(
        "mysql:host=".DBHOST.";dbname=".DBNAME.";charset=utf8mb4",
        USERNAME,
        PASSWORD
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
?>