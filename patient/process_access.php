<?php
session_start();
if (!isset($_SESSION['2fa_verified']) || $_SESSION['2fa_verified'] !== true) {
    header("Location: login.php");
    exit();
}
require_once 'db.php';

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['request_id'], $_POST['action'])) {
    $request_id = (int)$_POST['request_id'];
    $action = $_POST['action']; // Can evaluate as 'approved' or 'declined'
    
    if (in_array($action, ['approved', 'declined'])) {
        try {
            $stmt = $pdo->prepare("UPDATE access_requests SET request_status = ? WHERE id = ?");
            $stmt->execute([$action, $request_id]);
        } catch (PDOException $e) {
            // Silently fall back or log locally 
        }
    }
}

// Bounce back smoothly to the privacy panel interface
header("Location: privacy_settings.php");
exit();