<?php
session_start();
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['request_id'], $_POST['action'])) {
    $request_id = $_POST['request_id'];
    $action = $_POST['action']; // 'approved' or 'declined'

    // Update the request status
    $stmt = $pdo->prepare("UPDATE access_requests SET request_status = ?, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$action, $request_id]);

    // Redirect back to privacy settings
    header("Location: privacy_settings.php?status=success");
    exit();
}
?>