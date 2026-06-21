<?php
// staff/delete_doctor.php
session_start();
require_once 'db.php';

// Auth Check
if (!isset($_SESSION['staff_logged_in']) || $_SESSION['staff_logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

if (isset($_GET['id'])) {
    $id = $_GET['id'];
    
    // Perform the deletion
    $stmt = $pdo->prepare("DELETE FROM doctors WHERE id = ?");
    $stmt->execute([$id]);
    
    // Redirect back to dashboard with a success message
    header("Location: dashboard.php?message=Doctor+removed+successfully");
    exit();
}
?>