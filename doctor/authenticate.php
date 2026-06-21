<?php
session_start();
require_once 'db.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = $_POST['email'];
    $password = $_POST['password'];

    // 1. Fetch the doctor record
    $stmt = $conn->prepare("SELECT id, name, password FROM doctors WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $doctor = $result->fetch_assoc();

    // 2. Verify password
    if ($doctor && password_verify($password, $doctor['password'])) {
        // Successful login
        $_SESSION['doctor_logged_in'] = true;
        
        // --- ADD THIS LINE ---
        $_SESSION['doctor_email'] = $email; 
        // ---------------------
        
        header("Location: dashboard.php");
        exit;
    } else {
        $_SESSION['login_error'] = "Invalid email or password.";
        header("Location: login.php");
        exit;
    }
}
?>