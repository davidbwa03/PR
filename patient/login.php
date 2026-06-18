<?php
session_start();

// Enable strict error reporting to help uncover hidden database crashes
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Securely check database connectivity variables
if (!file_exists('db.php')) {
    die("Deployment Error: db.php configuration file is missing from this workspace root layout.");
}
require_once 'db.php';         // Connects to your local MySQL database

// Defensive verification checks on PHPMailer requirements
$has_mailer = file_exists('send-email.php');
if ($has_mailer) {
    require_once 'send-email.php';
}

$error = "";

// Process form submissions
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // Sanitize user entry bounds to check matching fields cleanly
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';

    if (empty($email) || empty($password)) {
        $error = "Please fill in both email and password fields.";
    } else {
        try {
            // Ensure connection instance is initialized completely
            if (!isset($pdo)) {
                throw new PDOException("Database connection object ($pdo) was not initialized inside db.php.");
            }

            // 1. Search for the patient row based on email parameters
            $stmt = $pdo->prepare("SELECT id, name, email, password FROM patients WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            $patient = $stmt->fetch();

            // 2. Evaluate matches using standard bcrypt structures
            if ($patient && password_verify($password, $patient['password'])) {

                // 3. Set up patient identity properties across session memories
                $_SESSION['patient'] = $patient['email'];
                $_SESSION['patient_name'] = $patient['name'];
                $_SESSION['patient_id'] = $patient['id']; 
                $_SESSION['2fa_pending'] = true; 

                // 4. Generate a fresh 6-digit verification code token
                $otp_code = (string)rand(100000, 999999);
                $expires_at = date('Y-m-d H:i:s', time() + 600); // 10 minutes validation window

                // 5. Commit token credentials to the data schema row instance safely
                $update_stmt = $pdo->prepare("UPDATE patients SET email_2fa_code = ?, two_fa_expires_at = ? WHERE email = ?");
                $update_stmt->execute([$otp_code, $expires_at, $email]);

                // 6. Transmit OTP through PHPMailer pipelines if available
                if ($has_mailer && function_exists('sendOTP')) {
                    sendOTP($email, $patient['name'], $otp_code);
                } else {
                    // Fallback debug option if mail configuration thresholds fail local constraints
                    $_SESSION['2fa_debug_code'] = $otp_code;
                }

                // Redirect smoothly to your security gateway verification frame
                header("Location: verify-2fa.php");
                exit();

            } else {
                // Defensive Cleanup: Purge memory buffers if validation returns false
                unset($_SESSION['patient']);
                unset($_SESSION['2fa_pending']);
                $error = "Invalid email or password.";
            }
        } catch (PDOException $e) {
            // Captures missing tables, column errors, or unexpected database terminations
            $error = "System Authentication Failure: " . $e->getMessage() . " [Code: " . $e->getCode() . "]";
        } catch (Exception $generic_exception) {
            $error = "General Application Exception: " . $generic_exception->getMessage();
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
            type="email"
            name="email"
            placeholder="Email"
            required
            autocomplete="email">

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