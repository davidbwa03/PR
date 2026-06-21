<?php
session_start();
if (!isset($_SESSION['2fa_verified']) || $_SESSION['2fa_verified'] !== true) {
    header("Location: login.php");
    exit();
}
require_once 'db.php';

$patient_email = $_SESSION['patient'];
$stmt = $pdo->prepare("SELECT id, name FROM patients WHERE email = :email");
$stmt->execute(['email' => $patient_email]);
$user = $stmt->fetch();
$real_id = $user['id'] ?? 1;

$stmt_req = $pdo->prepare("SELECT id, doctor_name, medical_facility, requested_at 
                           FROM access_requests 
                           WHERE patient_id = :pid AND request_status = 'pending'");
$stmt_req->execute(['pid' => $real_id]);
$requests = $stmt_req->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Privacy Settings</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f8fafc; font-family: 'Segoe UI', sans-serif; }
        
        /* Sidebar Style*/
        .sidebar { width: 260px; height: 100vh; position: fixed; background: white; border-right: 1px solid #e2e8f0; padding: 20px; display: flex; flex-direction: column; }
        .sidebar-brand { display: flex; align-items: center; gap: 10px; margin-bottom: 30px; }
        .icon-box { background: #0e7490; color: white; width: 40px; height: 40px; border-radius: 8px; display: flex; align-items: center; justify-content: center; }
        .nav-item { padding: 12px; margin-bottom: 8px; border-radius: 8px; color: #475569; text-decoration: none; display: flex; align-items: center; }
        .nav-item.active { background: #0e7490; color: white; }
        .sign-out { margin-top: auto; color: #64748b; text-decoration: none; }

        .main-content { margin-left: 260px; padding: 40px; }
        .card-custom { background: white; border: 1px solid #e2e8f0; border-radius: 12px; padding: 24px; margin-bottom: 24px; }
        .request-item { display: flex; justify-content: space-between; align-items: center; border: 1px solid #e2e8f0; padding: 15px; border-radius: 12px; margin-bottom: 10px; }
        .btn-approve { background: #22c55e; color: white; border-radius: 20px; padding: 5px 15px; border: none; font-size: 0.8rem; font-weight: 600; }
        .btn-decline { background: transparent; color: #ef4444; border: 1px solid #ef4444; border-radius: 20px; padding: 5px 15px; font-size: 0.8rem; font-weight: 600; }
    </style>
</head>
<body>

<div class="sidebar">
    <div class="sidebar-brand">
        <div class="icon-box"><i class="fa-solid fa-user"></i></div>
        <div><strong>Patient</strong><br><small style="font-size: 0.7rem;">Portal Panel</small></div>
    </div>
    <a href="dashboard.php" class="nav-item"><i class="fa-solid fa-chart-pie me-2"></i> Dashboard</a>
    <a href="medicalrecord.php" class="nav-item"><i class="fa-solid fa-file-medical me-2"></i> Medical Records</a>
    <a href="privacy_settings.php" class="nav-item active"><i class="fa-solid fa-shield-halved me-2"></i> Privacy Settings</a>
    <a href="current_health.php" class="nav-item"><i class="fa-solid fa-heart-pulse me-2"></i> Current Health</a>
    <a href="logout.php" class="sign-out"><i class="fa-solid fa-arrow-right-from-bracket me-2"></i> Sign Out</a>
</div>

<div class="main-content">
    <h3>Welcome, David Bwashi</h3>
    <p class="text-muted">Patient Reference Number: PT-2026-01</p>

    <section class="card-custom border-warning" style="border-left: 5px solid #eab308;">
        <h5><i class="fa-solid fa-user-doctor text-warning me-2"></i> Incoming Doctor Access Requests</h5>
        <?php foreach ($requests as $req): ?>
            <div class="request-item">
                <div><strong>Dr. <?php echo htmlspecialchars($req['doctor_name']); ?></strong><br>
                <small><?php echo htmlspecialchars($req['medical_facility']); ?></small></div>
                <form action="process_access.php" method="POST">
                    <input type="hidden" name="request_id" value="<?php echo $req['id']; ?>">
                    <button type="submit" name="action" value="approved" class="btn-approve">Approve</button>
                    <button type="submit" name="action" value="declined" class="btn-decline">Decline</button>
                </form>
            </div>
        <?php endforeach; ?>
    </section>
</div>

</body>
</html>