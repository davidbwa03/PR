<?php
// staff/analytics.php
session_start();
require_once 'db.php';

if (!isset($_SESSION['staff_logged_in']) || $_SESSION['staff_logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

$staff_id = isset($_SESSION['staff_id']) ? (int) $_SESSION['staff_id'] : 0;
$hospital_brand_name = 'Hospital';

try {
    if ($staff_id > 0) {
        $stmt_hospital_name = $pdo->prepare("SELECT hospital_name FROM staff WHERE id = ? LIMIT 1");
        $stmt_hospital_name->execute([$staff_id]);
        $resolved_hospital_name = trim((string) $stmt_hospital_name->fetchColumn());
        if ($resolved_hospital_name !== '') {
            $hospital_brand_name = $resolved_hospital_name;
        }
    }
} catch (PDOException $e) {
    $hospital_brand_name = 'Hospital';
}

try {
    // 1. Get Monthly Patient Volume
    $stmt_patients = $pdo->query("SELECT DATE_FORMAT(created_at, '%M') as month, COUNT(*) as total FROM patients GROUP BY MONTH(created_at) ORDER BY MONTH(created_at) DESC LIMIT 6");
    $patient_trends = $stmt_patients->fetchAll(PDO::FETCH_ASSOC);

    // 2. Get Requests by Status
    $stmt_status = $pdo->query("SELECT request_status, COUNT(*) as count FROM access_requests GROUP BY request_status");
    $status_distribution = $stmt_status->fetchAll(PDO::FETCH_ASSOC);

    // 3. Get Staff vs Doctor vs Patient count
    $stmt_distribution = $pdo->query("SELECT 'Staff' as role, COUNT(*) as count FROM staff UNION SELECT 'Doctors', COUNT(*) FROM doctors UNION SELECT 'Patients', COUNT(*) FROM patients");
    $role_distribution = $stmt_distribution->fetchAll(PDO::FETCH_ASSOC);

    // 4. Get doctor contact details
    $stmt_doctors = $pdo->query("SELECT name, phone, email FROM doctors ORDER BY name ASC");
    $doctor_contacts = $stmt_doctors->fetchAll(PDO::FETCH_ASSOC);

} catch (\PDOException $e) {
    $patient_trends = [];
    $status_distribution = [];
    $role_distribution = [];
    $doctor_contacts = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics - Central Medical Center</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --sidebar-width: 260px;
            --main-bg: #f4f6f8;
            --sidebar-bg: #ffffff;
            --text-main: #1e293b;
            --text-sub: #64748b;
            --teal-accent: #107c91;
            --border-light: #e2e8f0;
        }

        body { background-color: var(--main-bg); font-family: system-ui, -apple-system, sans-serif; color: var(--text-main); overflow-x: hidden; }

        .sidebar-container {
            width: var(--sidebar-width); background-color: var(--sidebar-bg); border-right: 1px solid var(--border-light);
            height: 100vh; position: fixed; top: 0; left: 0; padding: 32px 20px;
            display: flex; flex-direction: column; justify-content: space-between; z-index: 1000;
        }

        .sidebar-brand { display: flex; align-items: center; gap: 12px; margin-bottom: 40px; padding-left: 8px; }
        .brand-avatar { background-color: var(--teal-accent); color: #ffffff; width: 40px; height: 40px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 18px; }
        .brand-title h1 { font-size: 15px; font-weight: 700; margin: 0; color: var(--text-main); }
        .brand-title span { font-size: 11px; color: var(--text-sub); display: block; }
        .sidebar-menu { display: flex; flex-direction: column; gap: 8px; }
        
        .menu-link { display: flex; align-items: center; padding: 12px 16px; color: var(--text-main); text-decoration: none; border-radius: 8px; font-size: 14px; font-weight: 600; transition: all 0.2s ease; }
        .menu-link.active { background-color: var(--teal-accent); color: #ffffff; }
        .menu-link:hover:not(.active) { background-color: #f1f5f9; color: var(--teal-accent); }

        .logout-link { display: flex; align-items: center; padding: 12px 16px; color: var(--text-sub); text-decoration: none; font-size: 14px; font-weight: 600; border-radius: 8px; transition: all 0.2s; }
        .logout-link:hover { background-color: #fef2f2; color: #ef4444; }

        .workspace { margin-left: var(--sidebar-width); padding: 40px 48px; }
        .panel-card { background: #ffffff; border: 1px solid var(--border-light); border-radius: 10px; padding: 28px; margin-bottom: 24px; }
        .panel-title { font-size: 16px; font-weight: 700; color: var(--text-main); margin-bottom: 20px; }
        .doctor-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 16px; }
        .doctor-contact-card { border: 1px solid var(--border-light); border-radius: 10px; padding: 16px; background: #f8fafc; }
        .doctor-name { font-size: 15px; font-weight: 700; margin-bottom: 10px; color: var(--text-main); }
        .doctor-meta { margin: 0; font-size: 13px; color: var(--text-sub); line-height: 1.6; }
        .doctor-meta strong { color: var(--text-main); font-weight: 600; }
    </style>
</head>
<body>

<nav class="sidebar-container">
        <div class="w-100">
            <div class="sidebar-brand">
                <div class="brand-avatar">H</div>
                <div class="brand-title"><h1><?= htmlspecialchars($hospital_brand_name); ?></h1><span>Portal</span></div>
            </div>
            <div class="sidebar-menu">
                <a href="dashboard.php" class="menu-link">Overview</a>
                <a href="patient_requests.php" class="menu-link">Patient Requests</a>
                <a href="send_records.php" class="menu-link">Send Records to Doctors</a>
                <a href="update_vitals.php" class="menu-link">Update Patient Vitals</a>
                <a href="add_practitioner.php" class="menu-link">Add Practitioners</a>
                <a href="manage_practitioners.php" class="menu-link">Manage Doctors</a>
                <a href="create_claim.php" class="menu-link">Create the claim</a>
                <a href="analytics.php" class="menu-link active">Analytics</a>
            </div>
        </div>
        <div><a href="logout.php" class="logout-link">Sign Out</a></div>
    </nav>

    <main class="workspace">
        <h2 class="mb-4">Hospital Analytics</h2>
        
        <div class="row">
            <div class="col-lg-6">
                <div class="panel-card">
                    <h2 class="panel-title">Patient Trends</h2>
                    <table class="table">
                        <thead><tr><th>Month</th><th>Registrations</th></tr></thead>
                        <tbody>
                            <?php foreach ($patient_trends as $row): ?>
                                <tr><td><?= htmlspecialchars($row['month']); ?></td><td><?= $row['total']; ?></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="panel-card">
                    <h2 class="panel-title">Practitioner Distribution</h2>
                    <ul class="list-group">
                        <?php foreach ($role_distribution as $row): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <?= htmlspecialchars($row['role']); ?>
                                <span class="badge bg-info rounded-pill"><?= $row['count']; ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
        
        <div class="panel-card">
            <h2 class="panel-title">Request Status Overview</h2>
            <?php if (!empty($status_distribution)): ?>
                <div class="row">
                    <?php foreach ($status_distribution as $row): ?>
                        <div class="col-md-4">
                            <div class="border p-3 rounded text-center">
                                <div class="text-muted small"><?= htmlspecialchars(ucfirst($row['request_status'])); ?></div>
                                <h3 class="mt-2"><?= $row['count']; ?></h3>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="text-muted mb-0">Request status will appear after a request is submitted.</p>
            <?php endif; ?>
        </div>

        <div class="panel-card">
            <h2 class="panel-title">Doctor Contact Details</h2>
            <?php if (!empty($doctor_contacts)): ?>
                <div class="doctor-grid">
                    <?php foreach ($doctor_contacts as $doctor): ?>
                        <div class="doctor-contact-card">
                            <div class="doctor-name"><?= htmlspecialchars($doctor['name'] ?: 'Unnamed Doctor'); ?></div>
                            <p class="doctor-meta"><strong>Phone:</strong> <?= htmlspecialchars($doctor['phone'] ?: 'Not provided'); ?></p>
                            <p class="doctor-meta"><strong>Email:</strong> <?= htmlspecialchars($doctor['email'] ?: 'Not provided'); ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="text-muted mb-0">No doctors found yet.</p>
            <?php endif; ?>
        </div>
    </main>

</body>
</html>