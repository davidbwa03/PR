<?php
// staff/send-email.php
session_start();
require_once 'db.php';
require_once 'includes/email_functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'];
    $purpose = $_POST['purpose']; 
    // Use the null coalescing operator (??) to prevent the "Undefined array key" warning
    $password = $_POST['password'] ?? null; 

    if ($purpose === 'login') {
        // 1. LOGIN FLOW: Validate email and password
        $stmt = $pdo->prepare("SELECT * FROM staff WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password'])) {
            $otp = rand(100000, 999999);
            $_SESSION['otp'] = $otp;
            $_SESSION['email'] = $email;
            $_SESSION['purpose'] = 'login';
            $_SESSION['otp_expiry'] = time() + 900;

            sendOTPEmail($email, $user['name'], $otp);
            header("Location: verify_code.php");
            exit();
        } else {
            $_SESSION['login_error'] = "Invalid email or password.";
            header("Location: login.php");
            exit();
        }
    } else {
        // 2. FORGOT PASSWORD FLOW: No password needed
        $otp = rand(100000, 999999);
        $_SESSION['otp'] = $otp;
        $_SESSION['email'] = $email;
        $_SESSION['purpose'] = 'forgot_password';
        $_SESSION['otp_expiry'] = time() + 900;

        if (sendOTPEmail($email, 'Staff Member', $otp)) {
            header("Location: verify_code.php");
            exit();
        } else {
            die("Error: Could not send email.");
        }
    }
}
?>