<?php
session_start();
require_once 'db.php';         // Connects to your local MySQL database
require_once 'send-email.php'; // Includes your PHPMailer script execution rules

$error = "";

// Use a more reliable check for form submissions
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $email = trim($_POST['email']);
    $password = $_POST['password'];

    try {
        // 1. Search for the patient row in your database table based on email
        $stmt = $pdo->prepare("SELECT * FROM patients WHERE email = ?");
        $stmt->execute([$email]);
        $patient = $stmt->fetch();

        // 2. If the patient exists, verify their hashed password using bcrypt standards
        if ($patient && password_verify($password, $patient['password'])) {

            // 3. Set up active patient identity tracking inside the session state
            $_SESSION['patient'] = $patient['email'];
            $_SESSION['patient_name'] = $patient['name'];
            $_SESSION['2fa_pending'] = true; // Security gate for verify-2fa.php

            // 4. Generate a fresh 6-digit login verification token
            $otp_code = (string)rand(100000, 999999);
            $expires_at = date('Y-m-d H:i:s', time() + 600); // Valid for 10 minutes

            // 5. Save the generated OTP token directly to the database row for verification
            $update_stmt = $pdo->prepare("UPDATE patients SET email_2fa_code = ?, two_fa_expires_at = ? WHERE email = ?");
            $update_stmt->execute([$otp_code, $expires_at, $email]);

            // 6. Send the code straight to the patient's real email inbox
            sendOTP($email, $patient['name'], $otp_code);

            // Redirect smoothly to your clean identity check interface page
            header("Location: verify-2fa.php");
            exit();

        } else {
            $error = "Invalid email or password.";
        }
    } catch (PDOException $e) {
        $error = "System authorization error: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Patient Login</title>
    <link rel="stylesheet" href="login.css">
</head>
<body>

<div class="container">
    <div class="logo">
        <a href="../index.html">🏥</a>
    </div>

    <h1>Patient Login</h1>

    <?php if (!empty($error)): ?>
        <p class="error" style="color: red; text-align: center; font-weight: bold; margin-bottom: 15px;">
            <?php echo htmlspecialchars($error); ?>
        </p>
    <?php endif; ?>

    <form method="POST" action="login.php">
        <input 
            type="email"
            name="email"
            placeholder="Email"
            required
            autocomplete="email">

        <input
            type="password"
            name="password"
            placeholder="Password"
            required
            autocomplete="current-password">

        <button type="submit" name="login">
            Login
        </button>

        <p style="text-align: center; margin-top: 15px;">
            Don't have an account?
            <a href="register.php">Register</a>
        </p>
    </form>
</div>

</body>
</html>