<?php
// staff/reset_password.php
session_start();
require_once 'db.php';

// Ensure the user has verified their OTP before accessing this page
if (!isset($_SESSION['reset_staff_id']) || !isset($_SESSION['code_verified'])) {
    header("Location: forgot_password.php");
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $staff_id = $_SESSION['reset_staff_id'];

    // 1. Validate inputs
    if ($password !== $confirm_password) {
        $error = "Form credentials do not match.";
    } elseif (strlen($password) < 8) {
        $error = "Password must comprise at least 8 elements.";
    } else {
        // 2. Hash and Update
        $hashed = password_hash($password, PASSWORD_BCRYPT);

        $stmt = $pdo->prepare("UPDATE staff SET password = ? WHERE id = ?");
        $stmt->execute([$hashed, $staff_id]);

        // 3. Cleanup: Remove the session data used for the reset flow
        unset($_SESSION['reset_staff_id']);
        unset($_SESSION['code_verified']);
        unset($_SESSION['otp']);
        unset($_SESSION['email']);
        unset($_SESSION['purpose']);

        // 4. Redirect to login
        header("Location: login.php?reset=complete");
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Establish New Password</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f4f6f8; font-family: system-ui, sans-serif; }
        .recovery-card { width: 100%; max-width: 420px; border: 1px solid #e2e8f0; border-radius: 12px; }
        .btn-teal { background-color: #107c91; color: white; font-weight: 600; border: none; }
        .btn-teal:hover { background-color: #0e6b7d; color: white; }
    </style>
</head>
<body class="d-flex align-items-center justify-content-center vh-100">
    <div class="card p-4 shadow-sm recovery-card bg-white">
        <h5 class="fw-bold text-center mb-4" style="color: #1e293b;">Update Credentials</h5>

        <?php if($error): ?>
            <div class="alert alert-danger py-2 small text-center"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="mb-3">
                <label class="form-label small fw-bold text-secondary">New Password</label>
                <input type="password" name="password" class="form-control" required minlength="8">
            </div>
            <div class="mb-3">
                <label class="form-label small fw-bold text-secondary">Confirm New Password</label>
                <input type="password" name="confirm_password" class="form-control" required minlength="8">
            </div>
            <button type="submit" class="btn btn-teal w-100 py-2">Apply Changes</button>
        </form>
    </div>
</body>
</html>