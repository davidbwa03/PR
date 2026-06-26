<?php
session_start();
// Ensure user is logged in
if (!isset($_SESSION['2fa_verified']) || $_SESSION['2fa_verified'] !== true) {
    header("Location: login.php");
    exit();
}
require_once 'db.php';

$patient_email = $_SESSION['patient'];

// Fetch id, name, and national_id from the database
$stmt = $pdo->prepare("SELECT id, name, national_id FROM patients WHERE email = :email");
$stmt->execute(['email' => $patient_email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    die("Error: Patient record not found.");
}

$real_id = $user['id'];

// Fetch pending requests
$stmt_req = $pdo->prepare("SELECT id, doctor_name AS hospital_admin_name, medical_facility, requested_at 
                           FROM access_requests 
                           WHERE patient_id = :pid AND request_status = 'pending' 
                           ORDER BY requested_at DESC");
$stmt_req->execute(['pid' => $real_id]);
$requests = $stmt_req->fetchAll(PDO::FETCH_ASSOC);
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
    <h3>Welcome, <?php echo htmlspecialchars($user['name']); ?></h3>
    <p class="text-muted">National ID: <?php echo htmlspecialchars($user['national_id'] ?? 'Not Set'); ?></p>

    <section class="card-custom border-warning" style="border-left: 5px solid #eab308;">
        <h5><i class="fa-solid fa-user-doctor text-warning me-2"></i> Incoming Hospital Admin Access Requests</h5>
        
        <?php if (!empty($requests)): ?>
            <?php foreach ($requests as $req): ?>
                <div class="request-item">
                    <div>
                        <strong>Hospital Admin: <?php echo htmlspecialchars($req['hospital_admin_name'] ?? 'Unknown Admin'); ?></strong><br>
                        <small class="text-muted"><?php echo htmlspecialchars($req['medical_facility'] ?? 'No facility provided'); ?></small>
                    </div>
                    <form action="process_access.php" method="POST">
                        <input type="hidden" name="request_id" value="<?php echo $req['id']; ?>">
                        <button type="submit" name="action" value="approved" class="btn-approve">Approve</button>
                        <button type="submit" name="action" value="declined" class="btn-decline">Decline</button>
                    </form>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p class="text-muted mt-3">No pending access requests at this time.</p>
        <?php endif; ?>
    </section>
</div>

</body>
</html>