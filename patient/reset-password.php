<?php
session_start();

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Guard: must arrive from forgot-password flow
if (empty($_SESSION['reset_otp_pending']) || empty($_SESSION['reset_patient_id'])) {
    header("Location: forgot-password.php");
    exit();
}

if (!file_exists('db.php')) {
    die("Deployment Error: db.php configuration file is missing from this workspace root layout.");
}
require_once 'db.php';

$error   = "";
$success = "";
$step    = isset($_SESSION['reset_otp_verified']) ? 'new_password' : 'verify_otp';

// ── DEBUG BANNER (remove in production) ──────────────────────────────────────
$debug_code = $_SESSION['reset_debug_code'] ?? null;
// ─────────────────────────────────────────────────────────────────────────────

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    try {
        if (!isset($pdo)) {
            throw new PDOException("Database connection object (\$pdo) was not initialized.");
        }

        // ── STEP 1: Verify OTP ───────────────────────────────────────────────
        if (isset($_POST['verify_otp'])) {
            $entered_otp = isset($_POST['otp']) ? trim($_POST['otp']) : '';

            if (empty($entered_otp)) {
                $error = "Please enter the verification code.";
            } else {
                $patient_id = $_SESSION['reset_patient_id'];

                $stmt = $pdo->prepare(
                    "SELECT email_2fa_code, two_fa_expires_at FROM patients WHERE id = ? LIMIT 1"
                );
                $stmt->execute([$patient_id]);
                $row = $stmt->fetch();

                if (!$row) {
                    $error = "Patient record not found.";
                } elseif ($row['email_2fa_code'] !== $entered_otp) {
                    $error = "Invalid verification code. Please try again.";
                } elseif (strtotime($row['two_fa_expires_at']) < time()) {
                    $error = "Verification code has expired. <a href='forgot-password.php' style='color:#0e7490;'>Request a new one</a>.";
                } else {
                    // OTP correct – advance to password step
                    $_SESSION['reset_otp_verified'] = true;
                    // Invalidate the OTP so it can't be reused
                    $clear = $pdo->prepare("UPDATE patients SET email_2fa_code = NULL, two_fa_expires_at = NULL WHERE id = ?");
                    $clear->execute([$patient_id]);
                    $step = 'new_password';
                }
            }
        }

        // ── STEP 2: Set new password ─────────────────────────────────────────
        if (isset($_POST['reset_password'])) {
            if (empty($_SESSION['reset_otp_verified'])) {
                header("Location: forgot-password.php");
                exit();
            }

            $new_password     = isset($_POST['new_password'])     ? $_POST['new_password']     : '';
            $confirm_password = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';

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
                $patient_id   = $_SESSION['reset_patient_id'];
                $hashed       = password_hash($new_password, PASSWORD_DEFAULT);

                $update = $pdo->prepare("UPDATE patients SET password = ? WHERE id = ?");
                $update->execute([$hashed, $patient_id]);

                // Clean up session reset data
                unset(
                    $_SESSION['reset_patient_id'],
                    $_SESSION['reset_patient_email'],
                    $_SESSION['reset_patient_name'],
                    $_SESSION['reset_otp_pending'],
                    $_SESSION['reset_otp_verified'],
                    $_SESSION['reset_debug_code']
                );

                $success = "Your password has been reset successfully.";
                $step    = 'done';
            }
        }

    } catch (PDOException $e) {
        $error = "System Error: " . $e->getMessage();
    }
}

$masked_email = '';
if (!empty($_SESSION['reset_patient_email'])) {
    $parts = explode('@', $_SESSION['reset_patient_email']);
    if (count($parts) === 2) {
        $local  = $parts[0];
        $domain = $parts[1];
        $masked_email = substr($local, 0, min(2, strlen($local))) . str_repeat('*', max(0, strlen($local) - 2)) . '@' . $domain;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password</title>
    <link rel="stylesheet" href="login.css">
</head>
<body>

<div class="container">
    <div class="logo">
        <a href="../index.html">🏥</a>
    </div>

    <?php if ($step === 'verify_otp'): ?>

        <h1>Verify Your Email</h1>


        <?php if ($debug_code): ?>
            <div style="color: #92400e; background-color: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 12px; text-align: center; font-size: 13px; margin-bottom: 20px;">
            <strong>Debug mode</strong> — mailer not configured.<br>
                Your OTP: <strong style="letter-spacing: 3px;"><?php echo htmlspecialchars($debug_code); ?></strong>
            </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="error" style="color: #ef4444; background-color: #fef2f2; border: 1px solid #fee2e2; border-radius: 8px; padding: 14px; text-align: center; font-weight: 600; margin-bottom: 20px; font-size: 13px; line-height: 1.4; word-break: break-word;">
                <?php echo $error; /* already safe HTML */ ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="reset-password.php">
            <input
                type="text"
                name="otp"
                placeholder="Enter 6-digit code"
                maxlength="6"
                pattern="\d{6}"
                inputmode="numeric"
                autocomplete="one-time-code"
                style="letter-spacing: 4px; text-align: center; font-size: 20px;"
                required>

            <button type="submit" name="verify_otp">
                Verify Code
            </button>

        </form>

    <?php elseif ($step === 'new_password'): ?>

        <h1>Reset Password</h1>

        <p style="text-align: center; font-size: 14px; color: #64748b; margin-bottom: 24px; line-height: 1.5;">
            Identity verified. Please enter your new password below.
        </p>

        <?php if (!empty($error)): ?>
            <div class="error" style="color: #ef4444; background-color: #fef2f2; border: 1px solid #fee2e2; border-radius: 8px; padding: 14px; text-align: center; font-weight: 600; margin-bottom: 20px; font-size: 13px; line-height: 1.4; word-break: break-word;">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="reset-password.php">
            <input
                type="password"
                name="new_password"
                placeholder="New Password (min. 8 characters)"
                minlength="8"
                autocomplete="new-password"
                required>

            <input
                type="password"
                name="confirm_password"
                placeholder="Confirm New Password"
                minlength="8"
                autocomplete="new-password"
                required>

            <button type="submit" name="reset_password">
                Reset Password
            </button>
        </form>

    <?php elseif ($step === 'done'): ?>

        <h1>Password Reset</h1>

        <div style="color: #16a34a; background-color: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 14px; text-align: center; font-weight: 600; margin-bottom: 20px; font-size: 13px; line-height: 1.4;">
            ✅ <?php echo htmlspecialchars($success); ?>
        </div>

        <p style="text-align: center; margin-top: 10px; font-size: 14px; color: #64748b;">
            You can now
            <a href="login.php" style="color: #0e7490; font-weight: 600; text-decoration: none;">login</a>
            with your new password.
        </p>

    <?php endif; ?>
</div>

</body>
</html>