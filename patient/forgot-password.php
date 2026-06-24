<?php
session_start();

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (!file_exists('db.php')) {
    die("Deployment Error: db.php configuration file is missing from this workspace root layout.");
}
require_once 'db.php';

$has_mailer = file_exists('send-email.php');
if ($has_mailer) {
    require_once 'send-email.php';
}

$error   = "";
$success = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $login_id = isset($_POST['login_id']) ? trim($_POST['login_id']) : '';

    if (empty($login_id)) {
        $error = "Please enter your email or National ID.";
    } else {
        try {
            if (!isset($pdo)) {
                throw new PDOException("Database connection object (\$pdo) was not initialized.");
            }

            // Find patient by email or national_id
            $stmt = $pdo->prepare("SELECT id, name, email FROM patients WHERE email = ? OR national_id = ? LIMIT 1");
            $stmt->execute([$login_id, $login_id]);
            $patient = $stmt->fetch();

            if ($patient) {
                // Generate OTP
                $otp_code  = (string)rand(100000, 999999);
                $expires_at = date('Y-m-d H:i:s', time() + 600); // 10 minutes

                // Store OTP in DB (reusing the same 2FA columns)
                $update = $pdo->prepare("UPDATE patients SET email_2fa_code = ?, two_fa_expires_at = ? WHERE id = ?");
                $update->execute([$otp_code, $expires_at, $patient['id']]);

                // Keep minimal session data needed for next step
                $_SESSION['reset_patient_id']    = $patient['id'];
                $_SESSION['reset_patient_email'] = $patient['email'];
                $_SESSION['reset_patient_name']  = $patient['name'];
                $_SESSION['reset_otp_pending']   = true;

                if ($has_mailer && function_exists('sendOTP')) {
                    sendOTP($patient['email'], $patient['name'], $otp_code);
                } else {
                    // Debug: expose code if mailer is absent
                    $_SESSION['reset_debug_code'] = $otp_code;
                }

                header("Location: reset-password.php");
                exit();
            } else {
                // Generic message to avoid user enumeration
                $success = "If that account exists, a reset code has been sent to the registered email.";
            }

        } catch (PDOException $e) {
            $error = "System Error: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password</title>
    <link rel="stylesheet" href="login.css">
</head>
<body>

<div class="container">
    <div class="logo">
        <a href="../index.html">🏥</a>
    </div>

    <h1>Forgot Password</h1>

    <p style="text-align: center; font-size: 14px; color: #64748b; margin-bottom: 24px; line-height: 1.5;">
        Enter your registered email or National ID and we'll send you a verification code to reset your password.
    </p>

    <?php if (!empty($error)): ?>
        <div class="error" style="color: #ef4444; background-color: #fef2f2; border: 1px solid #fee2e2; border-radius: 8px; padding: 14px; text-align: center; font-weight: 600; margin-bottom: 20px; font-size: 13px; line-height: 1.4; word-break: break-word;">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($success)): ?>
        <div style="color: #16a34a; background-color: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 14px; text-align: center; font-weight: 600; margin-bottom: 20px; font-size: 13px; line-height: 1.4;">
            <?php echo htmlspecialchars($success); ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="forgot-password.php">
        <input
            type="text"
            name="login_id"
            placeholder="Email or National ID"
            required>

        <button type="submit" name="send_otp">
            Send Verification Code
        </button>

        <p style="text-align: center; margin-top: 20px; font-size: 14px; color: #64748b;">
            Remembered your password?
            <a href="login.php" style="color: #0e7490; font-weight: 600; text-decoration: none;">Login</a>
        </p>
    </form>
</div>

</body>
</html>