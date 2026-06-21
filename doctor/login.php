<?php
// doctor/login.php
session_start();

if (isset($_SESSION['doctor_logged_in']) && $_SESSION['doctor_logged_in'] === true) {
    header("Location: dashboard.php");
    
    exit;
}

$error = isset($_SESSION['login_error']) ? $_SESSION['login_error'] : "";
unset($_SESSION['login_error']); 
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Doctor Login</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: system-ui, sans-serif; }
        body { background: linear-gradient(135deg, #024c43 0%, #00f283 100%); min-height: 100vh; display: flex; justify-content: center; align-items: center; padding: 20px; }
        .container { background-color: #ffffff; width: 100%; max-width: 400px; padding: 40px 30px; border-radius: 12px; box-shadow: 0 8px 24px rgba(0, 0, 0, 0.1); text-align: center; }
        .logo-icon { background-color: #0e7490; color: #ffffff; width: 45px; height: 45px; display: inline-flex; align-items: center; justify-content: center; border-radius: 8px; font-weight: bold; text-decoration: none; margin-bottom: 20px;}
        h1 { color: #1e293b; font-size: 24px; margin-bottom: 25px; }
        .error { color: #ef4444; background-color: #fef2f2; padding: 12px; border-radius: 6px; margin-bottom: 20px; font-size: 13px; font-weight: 600; }
        input[type="email"], input[type="password"] { width: 100%; padding: 12px 16px; margin-bottom: 16px; border: 1px solid #cbd5e1; border-radius: 6px; }
        button[type="submit"] { width: 100%; padding: 12px; background-color: #0e7490; color: #ffffff; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; }
        .recovery-link { font-size: 13px; color: #0e7490; text-decoration: none; }
    </style>
</head>
<body>
<div class="container">
    <a href="../index.html" class="logo-icon">D</a>
    <h1>Doctor Login</h1>
    <?php if ($error) echo "<div class='error'>$error</div>"; ?>
    
    <form action="authenticate.php" method="POST">
        <input type="email" name="email" placeholder="Email" required>
        <input type="password" name="password" placeholder="Password" required>
        <div style="margin-bottom: 16px; text-align: right;">
            <a href="forgot_password.php" class="recovery-link">Forgot Password?</a>
        </div>
        <button type="submit">Login</button>
    </form>
</div>
</body>
</html>