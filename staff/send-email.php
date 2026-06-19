<?php
// staff/send-email.php
session_start();
require_once 'db.php';

// Load Composer's autoloader from the root vendor directory
require __DIR__ . '/../vendor/autoload.php'; 

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $email = trim($_POST['email']);
    $password = trim($_POST['password']);

    if (empty($email) || empty($password)) {
        $_SESSION['login_error'] = "Please fill in all fields.";
        header("Location: login.php");
        exit;
    }

    try {
        // Look up the practitioner by email
        $stmt = $pdo->prepare("SELECT * FROM staff WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            // Store temporary staff ID to keep track during the verification step
            $_SESSION['temp_staff_id'] = $user['id'];
            
            // Clear any active or leftover verification codes for this specific user
            $stmt = $pdo->prepare("DELETE FROM verification_codes WHERE staff_id = ?");
            $stmt->execute([$user['id']]);

            // Generate a secure 6-digit verification code
            $otp_code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
            $expires_at = date('Y-m-d H:i:s', strtotime('+10 minutes'));

            // Save the newly generated code to the database
            $stmt = $pdo->prepare("INSERT INTO verification_codes (staff_id, code, expires_at) VALUES (?, ?, ?)");
            $stmt->execute([$user['id'], $otp_code, $expires_at]);

            // Initialize PHPMailer using your working SMTP configuration
            $mail = new PHPMailer(true);

            // Server Settings
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com';                     
            $mail->SMTPAuth   = true;
            
            // UPDATE THESE VALUES WITH YOUR PATIENT MODULE SMTP CREDENTIALS
            $mail->Username   = 'islaehrk@gmail.com'; // Your actual testing Gmail account
            $mail->Password   = 'cbdybeflrdviubgu';  // Your 16-character Google App Password       
            
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = 587;

            // Recipients - Sends directly to the email pulled from the database row
            $mail->setFrom('HM@gmail.com', 'Healthcare Middleware');
            $mail->addAddress($user['email'], $user['name']);         

            // Content Setup
            $mail->isHTML(true);
            $mail->Subject = 'Your Security Verification Code';
            $mail->Body    = "
                <h3>Practitioner Portal Access</h3>
                <p>Hello " . htmlspecialchars($user['name']) . ",</p>
                <p>Your one-time security clearance code is: <strong style='font-size: 18px; color: #0e7490;'>" . $otp_code . "</strong></p>
                <p>This code will expire in 10 minutes.</p>
            ";

            $mail->send();

            // Redirect to the OTP input view
            header("Location: verify.php");
            exit;
        } else {
            $_SESSION['login_error'] = "Invalid email or password.";
            header("Location: login.php");
            exit;
        }
    } catch (Exception $e) {
        $_SESSION['login_error'] = "Mailer Error: " . $mail->ErrorInfo;
        header("Location: login.php");
        exit;
    } catch (\PDOException $e) {
        $_SESSION['login_error'] = "Database error during authentication.";
        header("Location: login.php");
        exit;
    }
} else {
    header("Location: login.php");
    exit;
}