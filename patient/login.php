<?php
session_start();

// Enable strict error reporting
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (!file_exists('db.php')) {
    die("Deployment Error: db.php configuration file is missing from this workspace root layout.");
}
require_once 'db.php'; 

$has_mailer = file_exists('send-email.php');
if ($has_mailer) {
    require_once 'send-email.php';
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // Capture the input as 'login_id' (could be email or national_id)
    $login_id = isset($_POST['login_id']) ? trim($_POST['login_id']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';

    if (empty($login_id) || empty($password)) {
        $error = "Please fill in all fields.";
    } else {
        try {
            if (!isset($pdo)) {
                throw new PDOException("Database connection object ($pdo) was not initialized.");
            }

            // 1. Search for the patient where login_id matches either email OR national_id
            $stmt = $pdo->prepare("SELECT id, name, email, password FROM patients WHERE email = ? OR national_id = ? LIMIT 1");
            $stmt->execute([$login_id, $login_id]);
            $patient = $stmt->fetch();

            // 2. Evaluate password match
            if ($patient && password_verify($password, $patient['password'])) {

                $_SESSION['patient'] = $patient['email'];
                $_SESSION['patient_name'] = $patient['name'];
                $_SESSION['patient_id'] = $patient['id']; 
                $_SESSION['2fa_pending'] = true; 

                // 3. Generate and update OTP
                $otp_code = (string)rand(100000, 999999);
                $expires_at = date('Y-m-d H:i:s', time() + 600);

                $update_stmt = $pdo->prepare("UPDATE patients SET email_2fa_code = ?, two_fa_expires_at = ? WHERE id = ?");
                $update_stmt->execute([$otp_code, $expires_at, $patient['id']]);

                if ($has_mailer && function_exists('sendOTP')) {
                    sendOTP($patient['email'], $patient['name'], $otp_code);
                } else {
                    $_SESSION['2fa_debug_code'] = $otp_code;
                }

                header("Location: verify-2fa.php");
                exit();

            } else {
                unset($_SESSION['patient']);
                unset($_SESSION['2fa_pending']);
                $error = "Invalid login credentials.";
            }
        } catch (PDOException $e) {
            $error = "System Authentication Failure: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
        <div class="error" style="color: #ef4444; background-color: #fef2f2; border: 1px solid #fee2e2; border-radius: 8px; padding: 14px; text-align: center; font-weight: 600; margin-bottom: 20px; font-size: 13px; line-height: 1.4; word-break: break-word;">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="login.php">
        <input 
            type="text"
            name="login_id"
            placeholder="Email or National ID"
            required>

        <input
            type="password"
            name="password"
            placeholder="Password"
            required
            autocomplete="current-password">

        <button type="submit" name="login">
            Login
        </button>

        <p style="text-align: center; margin-top: 20px; font-size: 14px; color: #64748b;">
            Don't have an account?
            <a href="register.php" style="color: #0e7490; font-weight: 600; text-decoration: none;">Register</a>
        </p>
    </form>
</div>

</body>
</html>