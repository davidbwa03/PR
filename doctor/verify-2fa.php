<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['2fa_pending'])) {
    header("Location: login.php");
    exit();
}

$error = "";
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $user_otp = trim($_POST['otp']);
    
    $stmt = $pdo->prepare("SELECT id, otp_code, otp_expires_at FROM doctors WHERE id = ?");
    $stmt->execute([$_SESSION['doctor_id']]);
    $doctor = $stmt->fetch();

    if ($doctor && $user_otp === $doctor['otp_code'] && time() < strtotime($doctor['otp_expires_at'])) {
        $clear = $pdo->prepare("UPDATE doctors SET otp_code = NULL, otp_expires_at = NULL WHERE id = ?");
        $clear->execute([$doctor['id']]);

        $_SESSION['2fa_verified'] = true;
        unset($_SESSION['2fa_pending']);
        header("Location: dashboard.php");
        exit();
    } else {
        $error = "Invalid or expired code.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <style>
        body { background: linear-gradient(to right, #0a4a5a, #00d084); display: flex; align-items: center; justify-content: center; min-height: 100vh; font-family: sans-serif; }
        .card { background: #fff; padding: 40px; border-radius: 16px; width: 100%; max-width: 388px; text-align: center; }
        .error { color: #b91c1c; background: #fef2f2; padding: 10px; border-radius: 8px; margin-bottom: 15px; font-size: 14px; border: 1px solid #fecaca; }
        input { width: 100%; padding: 12px; margin: 10px 0; border: 1px solid #ddd; border-radius: 8px; }
        .btn-login { width: 100%; padding: 12px; background: #0e7490; color: #fff; border: none; border-radius: 8px; cursor: pointer; }
    </style>
</head>
<body>
    <div class="card">
        <h2>Enter OTP</h2>
        
        <?php if (!empty($error)): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST">
            <input type="text" name="otp" placeholder="Enter 6-digit code" required>
            <button type="submit" class="btn-login">Verify</button>
            <a href="login.php" style="display:block; margin-top:12px; color:#0e7490; font-size:14px;">← Back to Login</a
        </form>
    </div>
</body>
</html>