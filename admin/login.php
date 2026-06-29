<?php
session_start();

// Enable strict error reporting
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (!file_exists('db.php')) {
    die("Deployment Error: db.php configuration file is missing from this workspace root layout.");
}
require_once 'db.php';

$has_mailer = file_exists('send-email.php');
if ($has_mailer) {
    require_once 'send-email.php';
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // Capture input — admin logs in with email only
    $login_email = isset($_POST['login_email']) ? trim($_POST['login_email']) : '';
    $password    = isset($_POST['password'])    ? $_POST['password']          : '';

    if (empty($login_email) || empty($password)) {
        $error = "Please fill in all fields.";
    } else {
        try {
            if (!isset($pdo)) {
                throw new PDOException("Database connection object (\$pdo) was not initialized.");
            }

            // 1. Look up admin by email
            $stmt = $pdo->prepare("SELECT admin_id, full_name, email, password FROM administrators WHERE email = ? LIMIT 1");
            $stmt->execute([$login_email]);
            $admin = $stmt->fetch();

            // 2. Verify password
            if ($admin && password_verify($password, $admin['password'])) {

                // Store admin session data
                $_SESSION['staff_logged_in'] = true;
                $_SESSION['staff_name']      = $admin['full_name'];
                $_SESSION['staff_email']     = $admin['email'];
                $_SESSION['admin_id']        = $admin['admin_id'];

                header("Location: dashboard.php");
                exit();

            } else {
                // Clear any stale session data on failed login
                unset($_SESSION['staff_logged_in']);
                unset($_SESSION['2fa_pending']);
                $error = "Invalid email or password.";
            }

        } catch (PDOException $e) {
            $error = "System Authentication Failure: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - Central Medical Center</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --teal-accent: #107c91;
            --teal-dark:   #0c6070;
            --main-bg:     #f4f6f8;
            --border-light:#e2e8f0;
            --text-main:   #1e293b;
            --text-sub:    #64748b;
        }

        body {
            background-color: var(--main-bg);
            font-family: system-ui, -apple-system, sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
        }

        .login-card {
            background: #ffffff;
            border: 1px solid var(--border-light);
            border-radius: 16px;
            padding: 44px 40px;
            width: 100%;
            max-width: 420px;
            box-shadow: 0 4px 24px rgba(0,0,0,0.07);
        }

        .brand {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
            margin-bottom: 32px;
        }

        .brand-icon {
            background-color: var(--teal-accent);
            color: #fff;
            width: 52px;
            height: 52px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }

        .brand h1 {
            font-size: 20px;
            font-weight: 700;
            color: var(--text-main);
            margin: 0;
        }

        .brand p {
            font-size: 13px;
            color: var(--text-sub);
            margin: 0;
        }

        .form-label {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-main);
            margin-bottom: 6px;
        }

        .form-control {
            border: 1px solid var(--border-light);
            border-radius: 8px;
            padding: 11px 14px;
            font-size: 14px;
            color: var(--text-main);
            transition: border-color 0.2s;
        }

        .form-control:focus {
            border-color: var(--teal-accent);
            box-shadow: 0 0 0 3px rgba(16,124,145,0.12);
            outline: none;
        }

        .btn-login {
            background-color: var(--teal-accent);
            color: #ffffff;
            border: none;
            border-radius: 8px;
            padding: 12px;
            font-size: 15px;
            font-weight: 600;
            width: 100%;
            cursor: pointer;
            transition: background-color 0.2s;
            margin-top: 8px;
        }

        .btn-login:hover { background-color: var(--teal-dark); }

        .error-box {
            background-color: #fef2f2;
            border: 1px solid #fee2e2;
            border-radius: 8px;
            padding: 12px 16px;
            color: #ef4444;
            font-size: 13px;
            font-weight: 600;
            text-align: center;
            margin-bottom: 20px;
            line-height: 1.4;
        }

        .forgot-link {
            font-size: 13px;
            color: var(--teal-accent);
            font-weight: 600;
            text-decoration: none;
            float: right;
        }

        .forgot-link:hover { text-decoration: underline; }

        .back-link {
            display: block;
            text-align: center;
            margin-top: 24px;
            font-size: 13px;
            color: var(--text-sub);
            text-decoration: none;
        }

        .back-link:hover { color: var(--teal-accent); }
    </style>
</head>
<body>

<div class="login-card">

    <!-- Brand -->
    <div class="brand">
        <a href="../index.html" class="brand-icon" style="text-decoration:none;">🏥</a>
        <h1>Admin Login</h1>
        <p>Central Medical Center — Staff Portal</p>
    </div>

    <!-- Error -->
    <?php if (!empty($error)): ?>
        <div class="error-box">
            <i class="bi bi-exclamation-circle me-1"></i>
            <?= htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <!-- Form -->
    <form method="POST" action="login.php">

        <div class="mb-3">
            <label class="form-label" for="login_email">Email Address</label>
            <input
                type="email"
                id="login_email"
                name="login_email"
                class="form-control"
                placeholder="admin email"
                value="<?= htmlspecialchars($_POST['login_email'] ?? ''); ?>"
                required
                autocomplete="email">
        </div>

        <div class="mb-1">
            <label class="form-label" for="password">Password</label>
            <input
                type="password"
                id="password"
                name="password"
                class="form-control"
                placeholder="Enter your password"
                required
                autocomplete="current-password">
        </div>


        <button type="submit" name="login" class="btn-login">
            <i class="bi bi-box-arrow-in-right me-2"></i>Sign In
        </button>

    </form>

    <a href="../index.html" class="back-link">
        <i class="bi bi-arrow-left me-1"></i>Back to Home
    </a>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>