<?php
session_start();

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (!file_exists('db.php')) {
    die("Deployment Error: db.php configuration file is missing from this workspace folder.");
}
require_once 'db.php';

$has_mailer = file_exists('send-email.php');
if ($has_mailer) {
    require_once 'send-email.php';
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';

    if (empty($email) || empty($password)) {
        $error = "Please fill in both email and password fields.";
    } else {
        try {
            if (!isset($pdo)) {
                throw new PDOException("Database connection object (\$pdo) was not initialized inside db.php.");
            }

            $stmt = $pdo->prepare("SELECT staff_id, full_name, email, password_hash, role, is_active FROM hospital_staff WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            $staff = $stmt->fetch();

            if ($staff && password_verify($password, $staff['password_hash'])) {
                if ((int)$staff['is_active'] !== 1) {
                    $error = "Access Denied: This staff profile has been deactivated.";
                } else {
                    $_SESSION['staff_email'] = $staff['email'];
                    $_SESSION['staff_name'] = $staff['full_name'];
                    $_SESSION['staff_id'] = $staff['staff_id']; 
                    $_SESSION['staff_role'] = $staff['role']; 
                    $_SESSION['2fa_pending'] = true; 

                    $otp_code = (string)rand(100000, 999999);
                    $expires_at = date('Y-m-d H:i:s', time() + 600);

                    $update_stmt = $pdo->prepare("UPDATE hospital_staff SET email_2fa_code = ?, email_2fa_expires_at = ? WHERE email = ?");
                    $update_stmt->execute([$otp_code, $expires_at, $email]);

                    if ($has_mailer && function_exists('sendOTP')) {
                        sendOTP($email, $staff['full_name'], $otp_code);
                    } else {
                        $_SESSION['2fa_debug_code'] = $otp_code;
                    }

                    header("Location: verify-2fa.php");
                    exit();
                }
            } else {
                unset($_SESSION['staff_email']);
                unset($_SESSION['2fa_pending']);
                $error = "Invalid email or password.";
            }
        } catch (PDOException $e) {
            $error = "System Authentication Failure: " . $e->getMessage();
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
    <title>Hospital Staff Login</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }

        body {
            /* Matches the vibrant green gradient background from image_eeef64.jpg */
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

        .logo {
            margin-bottom: 20px;
        }

        .logo-icon {
            background-color: #0e7490;
            color: #ffffff;
            width: 45px;
            height: 45px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            font-weight: bold;
            font-size: 20px;
            text-decoration: none;
        }

        h1 {
            color: #1e293b;
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 25px;
        }

        .error {
            color: #ef4444;
            background-color: #fef2f2;
            border: 1px solid #fee2e2;
            border-radius: 6px;
            padding: 12px;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 20px;
            line-height: 1.4;
            text-align: center;
            word-break: break-word;
        }

        input[type="email"],
        input[type="password"] {
            width: 100%;
            padding: 12px 16px;
            margin-bottom: 16px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 14px;
            color: #334155;
            outline: none;
            transition: border-color 0.2s ease;
        }

        input[type="email"]:focus,
        input[type="password"]:focus {
            border-color: #0e7490;
        }

        input::placeholder {
            color: #94a3b8;
        }

        button[type="submit"] {
            width: 100%;
            padding: 12px;
            background-color: #0e7490;
            color: #ffffff;
            border: none;
            border-radius: 6px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.2s ease;
            margin-top: 4px;
        }

        button[type="submit"]:hover {
            background-color: #0c5e75;
        }
    </style>
</head>
<body>

<div class="container">
    <div class="logo">
        <a href="../index.html" class="logo-icon">H</a>
    </div>

    <h1>Hospital Staff Login</h1>

    <?php if (!empty($error)): ?>
        <div class="error">
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
    </form>
</div>

</body>
</html>