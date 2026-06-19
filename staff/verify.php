<?php
// staff/verify.php
session_start();
require_once 'db.php';

// If there is no active temporary login session, boot them back to login
if (!isset($_SESSION['temp_staff_id'])) {
    header("Location: login.php");
    exit;
}

$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify'])) {
    $entered_code = trim($_POST['otp_code']);
    $staff_id = $_SESSION['temp_staff_id'];

    try {
        // 1. Fetch the code matching this user from the database
        $stmt = $pdo->prepare("SELECT * FROM verification_codes WHERE staff_id = ? AND code = ?");
        $stmt->execute([$staff_id, $entered_code]);
        $verification = $stmt->fetch();

        if ($verification) {
            // 2. Perform expiration check inside PHP to fix timezone conflicts
            $expiry_time = strtotime($verification['expires_at']);
            $current_time = time();

            if ($current_time <= $expiry_time) {
                // Success! Fetch full profile data for the session
                $stmt = $pdo->prepare("SELECT id, practitioner_id, name, email FROM staff WHERE id = ?");
                $stmt->execute([$staff_id]);
                $user = $stmt->fetch();

                // Initialize authenticated session keys
                $_SESSION['staff_logged_in'] = true;
                $_SESSION['staff_id'] = $user['id'];
                $_SESSION['practitioner_id'] = $user['practitioner_id'];
                $_SESSION['staff_name'] = $user['name'];

                // Clean up temporary data
                unset($_SESSION['temp_staff_id']);
                unset($_SESSION['debug_otp']);

                // Delete the used code from database
                $stmt = $pdo->prepare("DELETE FROM verification_codes WHERE staff_id = ?");
                $stmt->execute([$user['id']]);

                // Redirect straight to your practitioner portal dashboard
                header("Location: dashboard.php");
                exit;
            } else {
                $error = "This verification code has expired. Please return to login to request a fresh one.";
            }
        } else {
            $error = "Invalid verification code. Please check your email and try again.";
        }
    } catch (\PDOException $e) {
        $error = "System error during verification processing.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Identity</title>
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
        h1 { color: #1e293b; font-size: 22px; font-weight: 700; margin-bottom: 12px; }
        p { color: #64748b; font-size: 14px; margin-bottom: 20px; line-height: 1.5; }
        .error { 
            color: #ef4444; background-color: #fef2f2; border: 1px solid #fee2e2; 
            border-radius: 6px; padding: 12px; font-size: 13px; font-weight: 600; margin-bottom: 20px; 
        }
        .debug-box { 
            background-color: #f8fafc; border: 1px dashed #cbd5e1; 
            border-radius: 6px; padding: 12px; margin-bottom: 20px; font-size: 13px; color: #0f172a; 
        }
        input[type="text"] { 
            width: 100%; padding: 12px; margin-bottom: 16px; 
            border: 1px solid #cbd5e1; border-radius: 6px; font-size: 18px; 
            text-align: center; letter-spacing: 4px; font-weight: bold; outline: none; 
        }
        input[type="text"]:focus { border-color: #0e7490; }
        button[type="submit"] { 
            width: 100%; padding: 12px; background-color: #0e7490; color: #ffffff; 
            border: none; border-radius: 6px; font-size: 15px; font-weight: 600; cursor: pointer; 
        }
        button[type="submit"]:hover { background-color: #0c5e75; }
    </style>
</head>
<body>
<div class="container">
    <h1>Enter Security Code</h1>
    <p>A verification code has been dispatched to your medical account profile. Enter it below to gain workspace clearance.</p>

    <?php if (isset($_SESSION['debug_otp'])): ?>
        <div class="debug-box">
            <strong>Development Mode OTP:</strong> <?php echo $_SESSION['debug_otp']; ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div class="error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form method="POST" action="verify.php">
        <input type="text" name="otp_code" placeholder="000000" required maxlength="6" pattern="\d{6}" autocomplete="one-time-code">
        <button type="submit" name="verify">Verify & Access Dashboard</button>
    </form>
</div>
</body>
</html>