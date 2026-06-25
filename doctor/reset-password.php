<?php
session_start();
require_once 'db.php';

// Guard: must arrive from forgot-password flow
if (empty($_SESSION['reset_otp_pending']) || empty($_SESSION['reset_doctor_id'])) {
    header("Location: forgot-password.php");
    exit();
}

$error   = "";
$success = "";
$step    = isset($_SESSION['reset_otp_verified']) ? 'new_password' : 'verify_otp';

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // ── STEP 1: Verify OTP ───────────────────────────────────────────────────
    if (isset($_POST['verify_otp'])) {
        $entered_otp = trim($_POST['otp'] ?? '');

        if (empty($entered_otp)) {
            $error = "Please enter the verification code.";
        } else {
            $stmt = $pdo->prepare("SELECT otp_code, otp_expires_at FROM doctors WHERE id = ? LIMIT 1");
            $stmt->execute([$_SESSION['reset_doctor_id']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                $error = "Doctor record not found.";
            } elseif ($row['otp_code'] !== $entered_otp) {
                $error = "Invalid verification code. Please try again.";
            } elseif (strtotime($row['otp_expires_at']) < time()) {
                $error = "Verification code has expired. <a href='forgot-password.php' style='color:#0e7490;font-weight:600;'>Request a new one</a>.";
            } else {
                $_SESSION['reset_otp_verified'] = true;
                // Invalidate OTP — cannot be reused
                $clear = $pdo->prepare("UPDATE doctors SET otp_code = NULL, otp_expires_at = NULL WHERE id = ?");
                $clear->execute([$_SESSION['reset_doctor_id']]);
                $step = 'new_password';
            }
        }
    }

    // ── STEP 2: Set new password ─────────────────────────────────────────────
    if (isset($_POST['reset_password'])) {
        if (empty($_SESSION['reset_otp_verified'])) {
            header("Location: forgot-password.php");
            exit();
        }

        $new_password     = $_POST['new_password']     ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if (empty($new_password) || empty($confirm_password)) {
            $error = "Please fill in all fields.";
            $step  = 'new_password';
        } elseif (strlen($new_password) < 8) {
            $error = "Password must be at least 8 characters long.";
            $step  = 'new_password';
        } elseif ($new_password !== $confirm_password) {
            $error = "Passwords do not match.";
            $step  = 'new_password';
        } else {
            $hashed = password_hash($new_password, PASSWORD_DEFAULT);
            $update = $pdo->prepare("UPDATE doctors SET password = ? WHERE id = ?");
            $update->execute([$hashed, $_SESSION['reset_doctor_id']]);

            unset(
                $_SESSION['reset_doctor_id'],
                $_SESSION['reset_doctor_email'],
                $_SESSION['reset_doctor_name'],
                $_SESSION['reset_otp_pending'],
                $_SESSION['reset_otp_verified']
            );

            $success = "Your password has been reset successfully.";
            $step    = 'done';
        }
    }
}

// Masked email for display
$masked_email = '';
if (!empty($_SESSION['reset_doctor_email'])) {
    $parts = explode('@', $_SESSION['reset_doctor_email']);
    if (count($parts) === 2) {
        $local = $parts[0];
        $masked_email = substr($local, 0, min(2, strlen($local)))
                      . str_repeat('*', max(0, strlen($local) - 2))
                      . '@' . $parts[1];
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

        .otp-input {
            letter-spacing: 6px;
            text-align: center;
            font-size: 1.3rem !important;
            font-weight: 700 !important;
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

        .done-icon {
            font-size: 2.5rem;
            margin-bottom: 14px;
        }
    </style>
</head>
<body>

<div class="card">

    <div class="card-icon">
        <a href="../index.html" style="text-decoration: none; color: #fff;"><p style="margin: 0; color: #fff;">D</p></a>
    </div>

    <?php if ($step === 'verify_otp'): ?>

        <h2>Check Your Email</h2>

        <?php if (!empty($error)): ?>
            <div class="alert-error">&#9888; <?php echo $error; ?></div>
        <?php endif; ?>

        <form method="POST" action="reset-password.php" autocomplete="off">
            <div class="field">
                <input
                    class="otp-input"
                    type="text"
                    name="otp"
                    placeholder="000000"
                    maxlength="6"
                    pattern="\d{6}"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    required
                    autofocus
                >
            </div>

            <button type="submit" name="verify_otp" class="btn-login">Verify Code</button>
        </form>

        <div class="footer-note">
            Didn't receive a code? <a href="forgot-password.php">Try again</a>
        </div>

    <?php elseif ($step === 'new_password'): ?>

        <h2>New Password</h2>
        <p class="subtitle">Identity verified. Please choose a strong new password.</p>

        <?php if (!empty($error)): ?>
            <div class="alert-error">&#9888; <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST" action="reset-password.php" autocomplete="off">
            <div class="field">
                <input
                    type="password"
                    name="new_password"
                    placeholder="New password (min. 8 characters)"
                    minlength="8"
                    autocomplete="new-password"
                    required
                    autofocus
                >
            </div>

            <div class="field">
                <input
                    type="password"
                    name="confirm_password"
                    placeholder="Confirm new password"
                    minlength="8"
                    autocomplete="new-password"
                    required
                >
            </div>

            <button type="submit" name="reset_password" class="btn-login">Reset Password</button>
        </form>

    <?php elseif ($step === 'done'): ?>

        <div class="done-icon"></div>
        <h2>Password Reset</h2>
        <p class="subtitle"><?php echo htmlspecialchars($success); ?></p>

        <div class="footer-note" style="margin-top: 10px;">
            <a href="login.php">Back to Login</a>
        </div>

    <?php endif; ?>

</div>

</body>
</html>