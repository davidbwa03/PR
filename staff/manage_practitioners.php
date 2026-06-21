<?php
// staff/manage_practitioners.php
session_start();
require_once 'db.php';

if (!isset($_SESSION['staff_logged_in']) || $_SESSION['staff_logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

// Handle Password Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_password'])) {
    $new_pass = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("UPDATE doctors SET password = ? WHERE id = ?");
    $stmt->execute([$new_pass, $_POST['doctor_id']]);
    $message = "Password updated successfully.";
}

$doctors = $pdo->query("SELECT id, name, specialty FROM doctors")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Doctors - Central Medical Center</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root { --sidebar-width: 260px; --main-bg: #f4f6f8; --sidebar-bg: #ffffff; --text-main: #1e293b; --text-sub: #64748b; --teal-accent: #107c91; --border-light: #e2e8f0; }
        body { background-color: var(--main-bg); font-family: system-ui, sans-serif; color: var(--text-main); }
        .sidebar-container { width: var(--sidebar-width); background-color: var(--sidebar-bg); border-right: 1px solid var(--border-light); height: 100vh; position: fixed; padding: 32px 20px; display: flex; flex-direction: column; justify-content: space-between; }
        .sidebar-brand { display: flex; align-items: center; gap: 12px; margin-bottom: 40px; padding-left: 8px; }
        .brand-avatar { background-color: var(--teal-accent); color: #ffffff; width: 40px; height: 40px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-weight: bold; }
        .menu-link { display: flex; align-items: center; padding: 12px 16px; color: var(--text-main); text-decoration: none; border-radius: 8px; font-size: 14px; font-weight: 600; }
        .menu-link.active { background-color: var(--teal-accent); color: #ffffff; }
        .logout-link { color: var(--text-sub); text-decoration: none; font-size: 14px; font-weight: 600; padding: 12px 16px; }
        .workspace { margin-left: var(--sidebar-width); padding: 40px 48px; }
        .panel-card { background: #ffffff; border: 1px solid var(--border-light); border-radius: 10px; padding: 28px; }
    </style>
</head>
<body>

<nav class="sidebar-container">
    <div class="w-100">
        <div class="sidebar-brand">
            <div class="brand-avatar">H</div>
            <div class="brand-title"><h1>Hospital Admin</h1><span>Portal</span></div>
        </div>
        <div class="sidebar-menu">
            <a href="dashboard.php" class="menu-link">Overview</a>
            <a href="add_practitioner.php" class="menu-link">Add Practitioners</a>
            <a href="manage_practitioners.php" class="menu-link active">Manage Doctors</a>
            <a href="analytics.php" class="menu-link">Analytics</a>
        </div>
    </div>
    <a href="logout.php" class="logout-link">Sign Out</a>
</nav>

<main class="workspace">
    <h2 class="mb-4">Manage Doctor Credentials</h2>
    <?php if(isset($message)): ?>
        <div class="alert alert-success"><?= $message; ?></div>
    <?php endif; ?>
    
    <div class="panel-card">
        <table class="table">
            <thead><tr><th>Doctor Name</th><th>Action</th></tr></thead>
            <tbody>
                <?php foreach ($doctors as $d): ?>
                <tr>
                    <td class="align-middle"><?= htmlspecialchars($d['name']); ?></td>
                    <td>
                        <form method="POST" class="d-flex gap-2">
                            <input type="hidden" name="doctor_id" value="<?= $d['id']; ?>">
                            <input type="password" name="password" class="form-control form-control-sm" placeholder="New Password" required>
                            <button type="submit" name="update_password" class="btn btn-sm btn-primary">Update</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</main>

</body>
</html>