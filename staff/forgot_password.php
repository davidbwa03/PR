<?php
// staff/forgot_password.php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: system-ui, sans-serif; }
        body { background: linear-gradient(135deg, #024c43 0%, #00f283 100%); min-height: 100vh; display: flex; justify-content: center; align-items: center; padding: 20px; }
        .container { background-color: #ffffff; width: 100%; max-width: 400px; padding: 40px 30px; border-radius: 12px; box-shadow: 0 8px 24px rgba(0, 0, 0, 0.1); text-align: center; }
        h1 { color: #1e293b; font-size: 24px; margin-bottom: 25px; }
        input[type="email"] { width: 100%; padding: 12px 16px; margin-bottom: 16px; border: 1px solid #cbd5e1; border-radius: 6px; }
        button[type="submit"] { width: 100%; padding: 12px; background-color: #0e7490; color: #ffffff; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; }
    </style>
</head>
<body>
<div class="container">
    <h1>Reset Password</h1>
    <p style="margin-bottom: 20px; color: #64748b; font-size: 14px;">Enter your email to receive a verification code.</p>
    
    <form action="send-email.php" method="POST">
        <input type="email" name="email" placeholder="Email Address" required>
        <input type="hidden" name="purpose" value="forgot_password">
        <button type="submit">Send OTP</button>
    </form>
</div>
</body>
</html>