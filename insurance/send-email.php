<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/../vendor/autoload.php';

function sendOTP($recipient_email, $recipient_name, $otp_code) {
    $mail = new PHPMailer(true);
    
    try {
        $mail->SMTPDebug = 2; // Set to 0 once email is confirmed working
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'islaehrk@gmail.com'; // Your real Gmail address                 
        $mail->Password   = 'cbdybeflrdviubgu';    // Your 16-character Google App Password                 
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;         
        $mail->Port       = 587;                                    

        $mail->setFrom('HM@gmail.com', 'Healthcare Middleware');
        $mail->addAddress($recipient_email, $recipient_name);
        
        $mail->isHTML(true);
        $mail->Subject = 'Your Secure 2FA Verification Code';
        $mail->Body    = "
            <div style='font-family: Arial, sans-serif; max-width: 500px; padding: 20px; border: 1px solid #e2e8f0; border-radius: 8px;'>
                <h2 style='color: #007A9B; text-align: center;'>Doctor Security Verification</h2>
                <p>Hello, <strong>{$recipient_name}</strong></p>
                <p>Use the following code to complete your verification:</p>
                <div style='background-color: #f1f5f9; padding: 15px; text-align: center; font-size: 26px; font-weight: bold; letter-spacing: 6px;'>{$otp_code}</div>
                <p>This code expires in 10 minutes.</p>
            </div>";
            
        return $mail->send();
    } catch (Exception $e) {
        // Outputting error for troubleshooting
        echo "Message could not be sent. Mailer Error: {$mail->ErrorInfo}";
        return false;
    }
}