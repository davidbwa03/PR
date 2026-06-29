<?php
// staff/verify_code.php
session_start();
require_once 'db.php';

if (!isset($_SESSION['email'])) { header("Location: login.php"); exit(); }

$error = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($_POST['otp_input'] == $_SESSION['otp'] && time() < $_SESSION['otp_expiry']) {
        
        if ($_SESSION['purpose'] === 'login') {
            $stmt = $pdo->prepare("SELECT id, practitioner_id, name, status FROM staff WHERE email = ? LIMIT 1");
            $stmt->execute([$_SESSION['email']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user || strtoupper((string) ($user['status'] ?? 'Active')) !== 'ACTIVE') {
                unset($_SESSION['otp'], $_SESSION['email'], $_SESSION['purpose'], $_SESSION['otp_expiry']);
                $_SESSION['login_error'] = "You have been deactivated.";
                header("Location: login.php");
                exit();
            }

            $_SESSION['staff_logged_in'] = true; // Required for dashboard access
            $_SESSION['staff_id'] = $user['id'];
            $_SESSION['practitioner_id'] = $user['practitioner_id'];
            $_SESSION['staff_name'] = $user['name'];
            header("Location: dashboard.php");
        } else {
            $stmt = $pdo->prepare("SELECT id FROM staff WHERE email = ?");
            $stmt->execute([$_SESSION['email']]);
            $user = $stmt->fetch();
            
            $_SESSION['reset_staff_id'] = $user['id'];
            $_SESSION['code_verified'] = true;
            header("Location: reset_password.php");
        }
        exit();
    } else {
        $error = "Invalid or expired code.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Verification</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: system-ui, sans-serif; }
        body { background: linear-gradient(135deg, #024c43 0%, #00f283 100%); min-height: 100vh; display: flex; justify-content: center; align-items: center; padding: 20px; }
        .container { background-color: #ffffff; width: 100%; max-width: 400px; padding: 40px 30px; border-radius: 12px; box-shadow: 0 8px 24px rgba(0, 0, 0, 0.1); text-align: center; }
        h1 { color: #1e293b; font-size: 24px; margin-bottom: 20px; }
        input[type="text"] { width: 100%; padding: 12px 16px; margin-bottom: 16px; border: 1px solid #cbd5e1; border-radius: 6px; text-align: center; letter-spacing: 5px; }
        button { width: 100%; padding: 12px; background-color: #0e7490; color: #ffffff; border: none; border-radius: 6px; cursor: pointer; }
    </style>
</head>
<body>
<div class="container">
    <h1>Verify Code</h1>
    <?php if ($error) echo "<p style='color:red; margin-bottom:15px;'>$error</p>"; ?>
    <form method="POST">
        <input type="text" name="otp_input" maxlength="6" required placeholder="000000">
        <button type="submit">Verify Code</button>
    </form>
</div>
</body>
</html>