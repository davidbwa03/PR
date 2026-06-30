<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['insurer_2fa_pending']) || empty($_SESSION['insurer_id'])) {
    header("Location: login.php");
    exit();
}

$error = "";

if (!isset($_SESSION['insurer_2fa_attempts'])) {
    $_SESSION['insurer_2fa_attempts'] = 0;
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if ($_SESSION['insurer_2fa_attempts'] >= 5) {
        $error = "Too many attempts. Please log in again.";
        unset($_SESSION['insurer_2fa_pending'], $_SESSION['insurer_id'], $_SESSION['insurer_2fa_attempts']);
    } else {
        $user_otp = trim($_POST['otp'] ?? '');

        $stmt = $pdo->prepare("SELECT id, otp_code, otp_expires_at FROM insurance_staff WHERE id = ?");
        $stmt->execute([$_SESSION['insurer_id']]);
        $insurer = $stmt->fetch();

        if ($insurer && $insurer['otp_code'] !== null
            && hash_equals((string)$insurer['otp_code'], $user_otp)
            && time() < strtotime($insurer['otp_expires_at'])) {

            $clear = $pdo->prepare("UPDATE insurance_staff SET otp_code = NULL, otp_expires_at = NULL WHERE id = ?");
            $clear->execute([$insurer['id']]);

            session_regenerate_id(true);
            $_SESSION['insurer_2fa_verified'] = true;
            unset($_SESSION['insurer_2fa_pending'], $_SESSION['insurer_2fa_attempts']);

            header("Location: dashboard.php");
            exit();
        } else {
            $_SESSION['insurer_2fa_attempts']++;
            $error = "Invalid or expired code.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify 2FA</title>
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
            text-align: center;
            letter-spacing: 4px;
            transition: border-color 0.15s, box-shadow 0.15s;
        }

        .field input:focus {
            border-color: #0e7490;
            box-shadow: 0 0 0 3px rgba(14,116,144,0.1);
        }

        .field input::placeholder {
            color: #9ca3af;
            font-size: 0.88rem;
            letter-spacing: normal;
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

    <h2>Enter OTP</h2>
    <p class="subtitle">Enter the 6-digit verification code we sent to your company email.</p>

    <?php if (!empty($error)): ?>
        <div class="alert-error">
            &#9888; <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="verify-2fa.php" autocomplete="off">

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
            >
        </div>

        <button type="submit" class="btn-login">Verify</button>

    </form>

    <div class="footer-note">
        <a href="insurer-login.php">&larr; Back to Login</a>
    </div>

</div>

</body>
</html>