<?php
session_start();
require_once 'db.php';
require_once 'send-email.php';

$error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = "Please fill in all fields.";
    } else {
        $stmt = $pdo->prepare("SELECT id, name, email, company, password FROM insurance_staff WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $insurer = $stmt->fetch();

        if ($insurer && password_verify($password, $insurer['password'])) {
            session_regenerate_id(true);

            $_SESSION['insurer_id']      = $insurer['id'];
            $_SESSION['insurer_name']    = $insurer['name'];
            $_SESSION['insurer_email']   = $insurer['email'];
            $_SESSION['insurer_company'] = $insurer['company'];
            $_SESSION['insurer_2fa_pending']  = true;
            $_SESSION['insurer_2fa_verified'] = false;

            $otp_code   = (string)random_int(100000, 999999);
            $expires_at = date('Y-m-d H:i:s', time() + 600);

            $update = $pdo->prepare("UPDATE insurance_staff SET otp_code = ?, otp_expires_at = ? WHERE id = ?");
            $update->execute([$otp_code, $expires_at, $insurer['id']]);

            sendOTP($insurer['email'], $insurer['name'], $otp_code);

            header("Location: verify-2fa.php");
            exit();
        } else {
            $error = "Invalid login credentials.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Insurance Portal Login</title>
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
            margin-bottom: 28px;
            letter-spacing: -0.01em;
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

        .forgot-link {
            text-align: right;
            margin-top: -6px;
            margin-bottom: 16px;
            font-size: 0.8rem;
        }
        .forgot-link a {
            color: #0e7490;
            font-weight: 600;
            text-decoration: none;
        }
        .forgot-link a:hover { text-decoration: underline; }

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
        <a href="../index.html" style="text-decoration: none; color: #fff;"><p style="margin: 0; color: #fff;">I</p></a>
    </div>

    <h2>Insurance Portal Login</h2>

    <?php if (!empty($error)): ?>
        <div class="alert-error">
            &#9888; <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="login.php" autocomplete="off">

        <div class="field">
            <input
                type="email"
                name="email"
                placeholder="Use company email address"
                value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>"
                required
                autofocus
            >
        </div>

        <div class="field">
            <input
                type="password"
                name="password"
                placeholder="Password"
                required
            >
        </div>

        <div class="forgot-link">
            <a href="insurer-forgot-password.php">Forgot password?</a>
        </div>

        <button type="submit" class="btn-login">Login</button>

    </form>

</div>

</body>
</html>