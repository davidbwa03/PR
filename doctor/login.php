<?php
session_start();

if (isset($_SESSION['doctor_id'])) {
    header("Location: dashboard.php");
    exit();
}

require_once 'db.php';

$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($email) || empty($password)) {
        $error_msg = "Please enter both email and password.";
    } else {
        $stmt = $pdo->prepare("SELECT * FROM doctors WHERE email = :email");
        $stmt->execute(['email' => $email]);
        $doctor = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($doctor && password_verify($password, $doctor['password'])) {
            $_SESSION['doctor_id']   = $doctor['id'];
            $_SESSION['doctor_name'] = $doctor['name'];
            header("Location: dashboard.php");
            exit();
        } else {
            $error_msg = "Invalid email or password.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Practitioner Login</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            /* Matches the screenshot: dark teal left → bright green right, radial feel */
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

        /* Icon */
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

        /* Title */
        .card h2 {
            font-size: 1.5rem;
            font-weight: 700;
            color: #0d1f2d;
            margin-bottom: 28px;
            letter-spacing: -0.01em;
        }

        /* Error */
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

        /* Fields — no icons, just clean bordered inputs like the screenshot */
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

        /* Login button */
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

        /* Footer note */
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
         <a href="../index.html" style="text-decoration: none; color: #fff;"><p style="margin: 0; color: #fff;" >D</p></a>
    </div>

    <h2>Practitioner Login</h2>

    <?php if ($error_msg): ?>
        <div class="alert-error">
            &#9888; <?php echo htmlspecialchars($error_msg); ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="login.php" autocomplete="off">

        <div class="field">
            <input
                type="email"
                name="email"
                placeholder="Use hospital email address"
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

        <button type="submit" class="btn-login">Login</button>

    </form>

   

</div>

</body>
</html>