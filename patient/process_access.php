<?php
session_start();
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_id'], $_POST['action'])) {
    $status = ($_POST['action'] === 'approved') ? 'approved' : 'declined';
    
    $stmt = $pdo->prepare("UPDATE access_requests SET request_status = :status WHERE id = :id");
    $stmt->execute(['status' => $status, 'id' => $_POST['request_id']]);

    $_SESSION['message'] = "Request has been " . $status . ".";
    header("Location: privacy_settings.php");
    exit();
}
?>