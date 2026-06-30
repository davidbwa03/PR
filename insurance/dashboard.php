<?php
session_start();
require_once 'db.php';

if (empty($_SESSION['insurer_id']) || empty($_SESSION['insurer_2fa_verified'])) {
    header("Location: insurer-login.php");
    exit();
}

$insurer_name    = $_SESSION['insurer_name']    ?? 'Reviewer';
$insurer_email   = $_SESSION['insurer_email']   ?? '';
$insurer_company = $_SESSION['insurer_company'] ?? '';

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['logout'])) {
    $_SESSION = [];
    session_destroy();
    header("Location: insurer-login.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Insurance Dashboard</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(to right, #0a4a5a 0%, #0e7a6e 35%, #00d084 70%, #00f586 100%);
            padding: 20px;
        }

        .topbar {
            max-width: 980px;
            margin: 0 auto 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .brand-icon {
            width: 44px;
            height: 44px;
            background: #ffffff;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            color: #0e7490;
            font-size: 1.2rem;
        }

        .brand-text { color: #ffffff; }
        .brand-text strong { display: block; font-size: 1.05rem; }
        .brand-text span { font-size: 0.78rem; opacity: 0.85; }

        .logout-form button {
            background: rgba(255,255,255,0.15);
            color: #fff;
            border: 1.5px solid rgba(255,255,255,0.4);
            border-radius: 10px;
            padding: 9px 18px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.15s;
        }
        .logout-form button:hover { background: rgba(255,255,255,0.28); }

        .container { max-width: 980px; margin: 0 auto; }

        .welcome-card {
            background: #ffffff;
            border-radius: 16px;
            padding: 32px 36px;
            box-shadow: 0 8px 40px rgba(0,0,0,0.13);
            margin-bottom: 24px;
        }

        .welcome-card h2 {
            font-size: 1.4rem;
            font-weight: 700;
            color: #0d1f2d;
            margin-bottom: 6px;
            letter-spacing: -0.01em;
        }

        .welcome-card p { font-size: 0.88rem; color: #64748b; }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
        }

        .stat-card {
            background: #ffffff;
            border-radius: 16px;
            padding: 26px;
            box-shadow: 0 8px 40px rgba(0,0,0,0.13);
        }

        .stat-icon {
            width: 44px;
            height: 44px;
            background: #0e7490;
            border-radius: 11px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 1.2rem;
            margin-bottom: 14px;
        }

        .stat-card h3 {
            font-size: 0.8rem;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin-bottom: 6px;
        }

        .stat-card .stat-value {
            font-size: 1.6rem;
            font-weight: 700;
            color: #0d1f2d;
        }
    </style>
</head>
<body>

<div class="topbar">
    <div class="brand">
        <div class="brand-icon">D</div>
        <div class="brand-text">
            <strong>Healthcare Middleware</strong>
            <span>Insurance Portal</span>
        </div>
    </div>
    <form class="logout-form" method="POST" action="insurer-dashboard.php">
        <button type="submit" name="logout" value="1">Log out</button>
    </form>
</div>

<div class="container">

    <div class="welcome-card">
        <h2>Welcome back, <?php echo htmlspecialchars($insurer_name); ?></h2>
        <p>
            <?php echo htmlspecialchars($insurer_email); ?>
            <?php if (!empty($insurer_company)): ?>
                &middot; <?php echo htmlspecialchars($insurer_company); ?>
            <?php endif; ?>
        </p>
    </div>

    <div class="grid">
        <div class="stat-card">
            <div class="stat-icon">&#128203;</div>
            <h3>Pending Claims</h3>
            <div class="stat-value">—</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">&#9989;</div>
            <h3>Approved This Month</h3>
            <div class="stat-value">—</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">&#9888;</div>
            <h3>Flagged for Review</h3>
            <div class="stat-value">—</div>
        </div>
    </div>

</div>

</body>
</html>