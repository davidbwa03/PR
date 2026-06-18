<?php
session_start();
require_once 'db.php'; // Pulls database configuration parameters cleanly

// Guard Layer: If no login or registration transaction session exists, kick them out
if (!isset($_SESSION['patient'])) {
    header("Location: login.php");
    exit();
}

$error = "";

// Process the 2FA Form Submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $user_otp = trim($_POST['otp']);
    $email = $_SESSION['patient'];

    try {
        // MODIFIED: Added 'id' and 'name' to the query select statement to populate the dashboard metrics properly
        $stmt = $pdo->prepare("SELECT id, name, email_2fa_code, two_fa_expires_at FROM patients WHERE email = ?");
        $stmt->execute([$email]);
        $patient = $stmt->fetch();

        if ($patient) {
            $db_code = $patient['email_2fa_code'];
            $db_expires_at = $patient['two_fa_expires_at'];
            
            $current_time = time();
            $expiry_time = strtotime($db_expires_at);

            // 1. Check if the verification timeframe has lapsed
            if ($current_time > $expiry_time) {
                $error = "The verification code has expired. Please log in again.";
                unset($_SESSION['2fa_pending']);
            } 
            // 2. Perform strict verification matching against the stored field
            elseif ($user_otp === $db_code) {
                
                // -------------------------------------------------------------
                // FIX: INITIALIZE THE STRUCTURAL KEYS DEMANDED BY DASHBOARD.PHP
                // -------------------------------------------------------------
                $_SESSION['user_id'] = $patient['id'];       // Pass the active integer ID
                $_SESSION['role']    = 'Patient';            // Match case precisely with RBAC gate
                $_SESSION['full_name'] = $patient['name'];   // Displayed inside dashboard navigation menu
                
                // Keep safety verified tracking parameter
                $_SESSION['2fa_verified'] = true;
                
                // Clear the used token out of the database column for security compliance
                $clear_stmt = $pdo->prepare("UPDATE patients SET email_2fa_code = NULL, two_fa_expires_at = NULL WHERE email = ?");
                $clear_stmt->execute([$email]);

                // Clear structural temporary context parameters
                unset($_SESSION['2fa_pending']);
                unset($_SESSION['2fa_otp']);
                unset($_SESSION['2fa_expires']);

                // Route the verified patient straight into the main dashboard view safely
                header("Location: dashboard.php");
                exit();
            } else {
                $error = "Invalid code. Please check your verification code and try again.";
            }
        } else {
            $error = "Account records could not be found. Please return to login.";
        }
    } catch (PDOException $e) {
        $error = "System confirmation layer fault: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Identity Verification</title>
    <link rel="stylesheet" href="login.css">
    <style>
        .otp-input {
            text-align: center;
            letter-spacing: 8px;
            font-size: 24px;
            font-weight: bold;
            padding: 10px;
        }
        .info-text {
            color: #64748b;
            font-size: 14px;
            text-align: center;
            margin-bottom: 25px;
            line-height: 1.5;
        }
        .error-banner {
            color: #ef4444;
            background-color: #fef2f2;
            border: 1px solid #fca5a5;
            padding: 12px;
            border-radius: 6px;
            text-align: center;
            font-size: 14px;
            font-weight: bold;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>

<div class="container">
    <div class="logo">
        <a href="../index.html">🏥</a>
    </div>

    <h1>Security Verification</h1>
    
    <p class="info-text">
        To protect your clinical records, a 6-digit confirmation security code has been issued directly to your email inbox.
    </p>

    <?php if (!empty($error)): ?>
        <div class="error-banner">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="verify-2fa.php">
        <input 
            type="text" 
            name="otp" 
            class="otp-input" 
            placeholder="000000" 
            maxlength="6" 
            required 
            autocomplete="one-time-code"
            pattern="\d{6}">

        <button type="submit">
            Confirm & Enter Portal
        </button>

        <p style="text-align: center; font-size: 13px; margin-top: 20px;">
            <a href="login.php" style="color: #64748b; text-decoration: none;">← Cancel and return to Login</a>
        </p>
    </form>
</div>

</body>
</html>