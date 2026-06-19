<?php
// staff/login.php
session_start();

$error = "";
if (isset($_SESSION['login_error'])) {
    $error = $_SESSION['login_error'];
    unset($_SESSION['login_error']); 
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hospital Staff Login</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: system-ui, sans-serif; }
        body {
            background: linear-gradient(135deg, #024c43 0%, #00f283 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        .container {
            background-color: #ffffff;
            width: 100%;
            max-width: 400px;
            padding: 40px 30px;
            border-radius: 12px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.1);
            text-align: center;
        }
        .logo { margin-bottom: 20px; }
        .logo-icon {
            background-color: #0e7490;
            color: #ffffff;
            width: 45px;
            height: 45px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            font-weight: bold;
            font-size: 20px;
            text-decoration: none;
        }
        h1 { color: #1e293b; font-size: 24px; font-weight: 700; margin-bottom: 25px; }
        .error {
            color: #ef4444; background-color: #fef2f2; border: 1px solid #fee2e2;
            border-radius: 6px; padding: 12px; font-size: 13px; font-weight: 600; margin-bottom: 20px;
            line-height: 1.4;
        }
        input[type="email"], input[type="password"] {
            width: 100%; padding: 12px 16px; margin-bottom: 16px;
            border: 1px solid #cbd5e1; border-radius: 6px; font-size: 14px; color: #334155; outline: none;
        }
        input[type="email"]:focus, input[type="password"]:focus { border-color: #0e7490; }
        button[type="submit"] {
            width: 100%; padding: 12px; background-color: #0e7490; color: #ffffff;
            border: none; border-radius: 6px; font-size: 15px; font-weight: 600; cursor: pointer; margin-top: 4px;
        }
        button[type="submit"]:hover { background-color: #0c5e75; }
    </style>
</head>
<body>

<div class="container">
    <div class="logo">
        <a href="../index.html" class="logo-icon">H</a>
    </div>

    <h1>Hospital Staff Login</h1>

    <?php if (!empty($error)): ?>
        <div class="error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form method="POST" action="send-email.php">
        <input type="email" name="email" placeholder="Email" required autocomplete="email">
        <input type="password" name="password" placeholder="Password" required autocomplete="current-password">
        <button type="submit" name="login">Login</button>
    </form>
</div>

</body>
</html>