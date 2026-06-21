<?php
// staff/includes/email_functions.php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Adjust the path to your vendor folder accordingly
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

function sendOTPEmail($recipientEmail, $recipientName, $otpCode) {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'islaehrk@gmail.com'; 
        $mail->Password   = 'cbdybeflrdviubgu'; 
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom('islaehrk@gmail.com', 'Central Medical Center');
        $mail->addAddress($recipientEmail, $recipientName);
        $mail->isHTML(true);
        $mail->Subject = "Your Security Verification Code";
        $mail->Body    = "Hello {$recipientName},<br><br>Your verification code is: <b>{$otpCode}</b><br><br>This code expires in 15 minutes.";

        return $mail->send();
    } catch (Exception $e) {
        return false;
    }
}
?>