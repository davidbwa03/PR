<?php
$host    = 'localhost';
$db_user = 'root';                  
$db_pass = '';                     
$db_name = 'healthcare_middleware'; // Your database name in phpMyAdmin

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    die("Database Connection failed: " . $e->getMessage());
}
?>