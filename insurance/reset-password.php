<?php
session_start();
require_once 'db.php';

if (empty($_SESSION['reset_doctor_id']) || empty($_SESSION['reset_otp_pending'])) {
    header("Location: forgot-password.php");
    exit();
}

$error   = "";
$success = "";
$otp_verified = !empty($_SESSION['reset_otp_verified']);

if (!isset($_SESSION['reset_attempts'])) {
    $_SESSION['reset_attempts'] = 0;
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // Step 1: verify the OTP
    if (isset($_POST['otp']) && !$otp_verified) {
        if ($_SESSION['reset_attempts'] >= 5) {
            $error = "Too many attempts. Please start over.";
            unset($_SESSION['reset_doctor_id'], $_SESSION['reset_otp_pending'], $_SESSION['reset_attempts']);
        } else {
            $user_otp = trim($_POST['otp']);

            $stmt = $pdo->prepare("SELECT otp_code, otp_expires_at FROM doctors WHERE id = ?");
            $stmt->execute([$_SESSION['reset_doctor_id']]);
            $doctor = $stmt->fetch();

            if ($doctor && $doctor['otp_code'] !== null
                && hash_equals((string)$doctor['otp_code'], $user_otp)
                && time() < strtotime($doctor['otp_expires_at'])) {
                $_SESSION['reset_otp_verified'] = true;
                $otp_verified = true;
            } else {
                $_SESSION['reset_attempts']++;
                $error = "Invalid or expired code.";
            }
        }
    }

    // Step 2: set the new password
    if (isset($_POST['new_password']) && $otp_verified) {
        $new_password     = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if (strlen($new_password) < 8) {
            $error = "Password must be at least 8 characters.";
        } elseif ($new_password !== $confirm_password) {
            $error = "Passwords do not match.";
        } else {
            $hashed = password_hash($new_password, PASSWORD_DEFAULT);
            $update = $pdo->prepare("UPDATE doctors SET password = ?, otp_code = NULL, otp_expires_at = NULL WHERE id = ?");
            $update->execute([$hashed, $_SESSION['reset_doctor_id']]);

            unset($_SESSION['reset_doctor_id'], $_SESSION['reset_doctor_email'], $_SESSION['reset_doctor_name'],
                  $_SESSION['reset_otp_pending'], $_SESSION['reset_otp_verified'], $_SESSION['reset_attempts']);

            $success = "Password reset successfully. You can now log in.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(to right, #0a4a5a 0%, #0e7a6e 35%, #00d084 70%, #00f586 100%);
            padding: 20px;
        }

        .card {
            background: #ffffff;
            border-radius: 16px;
            padding: 50px 44px 42px;
            width: 100%;
            max-width: 388px;
            box-shadow: 0 8px 40px rgba(0,0,0,0.13);
            text-align: center;
        }

        .card-icon {
            width: 60px;
            height: 60px;
            background: #0e7490;
            border-radius: 13px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 18px;
            font-size: 1.55rem;
            color: #fff;
        }

        .card h2 {
            font-size: 1.5rem;
            font-weight: 700;
            color: #0d1f2d;
            margin-bottom: 10px;
            letter-spacing: -0.01em;
        }

        .subtitle {
            font-size: 0.85rem;
            color: #64748b;
            margin-bottom: 26px;
            line-height: 1.55;
        }

        .alert-error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #b91c1c;
            border-radius: 8px;
            padding: 10px 13px;
            font-size: 0.82rem;
            margin-bottom: 16px;
            text-align: left;
        }

        .alert-success {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            color: #15803d;
            border-radius: 8px;
            padding: 10px 13px;
            font-size: 0.82rem;
            margin-bottom: 16px;
            text-align: left;
        }

        .field {
            margin-bottom: 14px;
            text-align: left;
        }

        .field input {
            width: 100%;
            padding: 13px 16px;
            border: 1.5px solid #e2e8f0;
            border-radius: 10px;
            font-size: 0.9rem;
            color: #1e293b;
            background: #ffffff;
            outline: none;
            transition: border-color 0.15s, box-shadow 0.15s;
        }

        .field input:focus {
            border-color: #0e7490;
            box-shadow: 0 0 0 3px rgba(14,116,144,0.1);
        }

        .field input::placeholder {
            color: #9ca3af;
            font-size: 0.88rem;
        }

        .btn-login {
            width: 100%;
            padding: 13px;
            background: #0e7490;
            color: #fff;
            border: none;
            border-radius: 10px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            margin-top: 6px;
            transition: background 0.15s, transform 0.1s;
        }
        .btn-login:hover  { background: #0b5f75; }
        .btn-login:active { transform: scale(0.99); }

        .footer-note {
            margin-top: 22px;
            font-size: 0.82rem;
            color: #64748b;
        }
        .footer-note a {
            color: #0e7490;
            font-weight: 600;
            text-decoration: none;
        }
        .footer-note a:hover { text-decoration: underline; }
    </style>
</head>
<body>

<div class="card">

    <div class="card-icon">
        <a href="../index.html" style="text-decoration: none; color: #fff;"><p style="margin: 0; color: #fff;">D</p></a>
    </div>

    <?php if (!empty($success)): ?>

        <h2>Password Reset</h2>
        <div class="alert-success">&#10003; <?php echo htmlspecialchars($success); ?></div>
        <div class="footer-note">
            <a href="login.php">Go to Login</a>
        </div>

    <?php elseif (!$otp_verified): ?>

        <h2>Enter OTP</h2>
        <p class="subtitle">Enter the 6-digit code we sent to your hospital email.</p>

        <?php if (!empty($error)): ?>
            <div class="alert-error">&#9888; <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST" action="reset-password.php" autocomplete="off">
            <div class="field">
                <input
                    type="text"
                    name="otp"
                    placeholder="------"
                    inputmode="numeric"
                    pattern="[0-9]{6}"
                    maxlength="6"
                    required
                    autofocus
                    style="text-align:center; letter-spacing:4px;"
                >
            </div>
            <button type="submit" class="btn-login">Verify Code</button>
        </form>

    <?php else: ?>

        <h2>Set New Password</h2>
        <p class="subtitle">Choose a new password for your account.</p>

        <?php if (!empty($error)): ?>
            <div class="alert-error">&#9888; <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST" action="reset-password.php" autocomplete="off">
            <div class="field">
                <input type="password" name="new_password" placeholder="New password" required autofocus minlength="8">
            </div>
            <div class="field">
                <input type="password" name="confirm_password" placeholder="Confirm new password" required minlength="8">
            </div>
            <button type="submit" class="btn-login">Reset Password</button>
        </form>

    <?php endif; ?>

    <?php if (empty($success)): ?>
        <div class="footer-note">
            <a href="login.php">&larr; Back to Login</a>
        </div>
    <?php endif; ?>

</div>

</body>
</html>