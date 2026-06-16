<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// This points back out of the patient folder and tells Composer to load PHPMailer
require __DIR__ . '/../vendor/autoload.php';

function sendOTP($recipient_email, $recipient_name, $otp_code) {
    $mail = new PHPMailer(true);

    try {
        // SMTP Server Configuration Settings
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';                     
        $mail->SMTPAuth   = true;                                   
        $mail->Username   = 'islaehrk@gmail.com'; // Your real Gmail address                 
        $mail->Password   = 'cbdybeflrdviubgu';    // Your 16-character Google App Password                 
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;         
        $mail->Port       = 587;                                    

        // Email Identity Headers
        $mail->setFrom('HM@gmail.com', 'Healthcare Middleware');
        $mail->addAddress($recipient_email, $recipient_name);

        // Stylized Inbox Formatting
        $mail->isHTML(true);
        $mail->Subject = 'Your Secure 2FA Verification Code';
        $mail->Body    = "
            <div style='font-family: Arial, sans-serif; max-width: 500px; margin: 0 auto; padding: 20px; border: 1px solid #e2e8f0; border-radius: 8px;'>
                <h2 style='color: #007A9B; text-align: center;'>Security Verification</h2>
                <p>Hello, <strong>{$recipient_name}</strong></p>
                <p>To access your clinical record workspace, enter the following 6-digit confirmation security code:</p>
                <div style='background-color: #f1f5f9; padding: 15px; text-align: center; font-size: 26px; font-weight: bold; letter-spacing: 6px; color: #0f172a; border-radius: 6px; margin: 20px 0;'>
                    {$otp_code}
                </div>
                <p style='font-size: 12px; color: #64748b;'>This verification token expires in 10 minutes. If you did not make this request, please secure your credentials immediately.</p>
            </div>
        ";

        $mail->send();
        return true;
    } catch (Exception $e) {
        // Returns false if mail fails to send
        return false;
    }
}