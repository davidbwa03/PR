<?php
// staff/dashboard.php
session_start();
require_once 'db.php';

// If user isn't logged in, send them back to login immediately
if (!isset($_SESSION['staff_logged_in']) || $_SESSION['staff_logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

// Extract authenticated session attributes safely
$staff_name = isset($_SESSION['staff_name']) ? $_SESSION['staff_name'] : 'Administrator';
$success_msg = "";
$error_msg = "";
$system_uptime = "99.9%";

// Handle POST submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // New Feature: Request Patient Summary
    if (isset($_POST['request_summary'])) {
        $patient_id_input = trim($_POST['patient_id_input'] ?? '');
        $patient_id = null;
        $patient_ref_match = [];

        if (preg_match('/^PT-\d{4}-(\d+)$/i', $patient_id_input, $patient_ref_match)) {
            $patient_id = (int)$patient_ref_match[1];
        } elseif (ctype_digit($patient_id_input)) {
            $patient_id = (int)$patient_id_input;
        }
        
        if (!empty($patient_id_input)) {
            if ($patient_id !== null && $patient_id > 0) {
                $stmt_patient = $pdo->prepare("SELECT id FROM patients WHERE id = ? LIMIT 1");
                $stmt_patient->execute([$patient_id]);
            } else {
                $stmt_patient = $pdo->prepare("SELECT id FROM patients WHERE national_id = ? OR email = ? LIMIT 1");
                $stmt_patient->execute([$patient_id_input, $patient_id_input]);
            }

            $resolved_patient_id = $stmt_patient->fetchColumn();

            if ($resolved_patient_id) {
                $stmt = $pdo->prepare("INSERT INTO access_requests (patient_id, doctor_name, medical_facility, request_status, requested_at) VALUES (?, ?, ?, 'pending', NOW())");
                $stmt->execute([(int)$resolved_patient_id, $staff_name, 'System Request']);
                $success_msg = "Summary request for " . htmlspecialchars($patient_id_input) . " has been initiated.";
            } else {
                $error_msg = "No patient matched the provided reference. Use Patient DB ID, National ID, or Email.";
            }
        }
    }
    // Existing: Data request
    elseif (isset($_POST['request_data'])) {
        $patient_id = (int)$_POST['patient_id'];
        $doctor_name = trim($_POST['doctor_name'] ?? '');
        $medical_facility = trim($_POST['medical_facility'] ?? '');
        
        if ($patient_id > 0 && !empty($doctor_name) && !empty($medical_facility)) {
            $stmt = $pdo->prepare("INSERT INTO access_requests (patient_id, doctor_name, medical_facility, request_status, requested_at) VALUES (?, ?, ?, 'pending', NOW())");
            $stmt->execute([$patient_id, $doctor_name, $medical_facility]);
            $success_msg = "Data request successfully sent to the patient.";
        }
    } 
    // Existing: Send records
    elseif (isset($_POST['send_records'])) {
        $request_id = (int)$_POST['request_id'];
        if ($request_id > 0) {
            $stmt = $pdo->prepare("UPDATE access_requests SET records_sent = 1, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$request_id]);
            $success_msg = "Medical records successfully sent to the doctor.";
        }
    }
}

try {
    $stmt_practitioners = $pdo->query("SELECT (SELECT COUNT(*) FROM staff) + (SELECT COUNT(*) FROM doctors)");
    $active_practitioners_count = $stmt_practitioners->fetchColumn();

    $stmt_patients_today = $pdo->query("SELECT COUNT(*) FROM patients WHERE DATE(created_at) = CURDATE()");
    $patients_today_count = $stmt_patients_today->fetchColumn();
    if ($patients_today_count == 0) {
        $stmt_total_patients = $pdo->query("SELECT COUNT(*) FROM patients");
        $patients_today_count = $stmt_total_patients->fetchColumn();
    }

    $stmt_requests_count = $pdo->query("SELECT COUNT(*) FROM access_requests");
    $data_requests_count = $stmt_requests_count->fetchColumn();

    $stmt_staff = $pdo->query("SELECT id, name, specialty, 'staff' as type FROM staff UNION ALL SELECT id, name, specialty, 'doctors' as type FROM doctors ORDER BY name ASC LIMIT 3");
    $practitioners_list = $stmt_staff->fetchAll(PDO::FETCH_ASSOC);

    $stmt_requests = $pdo->query("SELECT id, patient_id, doctor_name, medical_facility, request_status, requested_at, records_sent FROM access_requests ORDER BY requested_at DESC LIMIT 10");
    $recent_requests = $stmt_requests->fetchAll(PDO::FETCH_ASSOC);

} catch (\PDOException $e) {
    $active_practitioners_count = 0;
    $patients_today_count = 0; 
    $data_requests_count = 0;
    $system_uptime = "99.9%";
    $practitioners_list = [];
    $recent_requests = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hospital Admin Portal - Central Medical Center</title>
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
        .sidebar-container { width: var(--sidebar-width); background-color: var(--sidebar-bg); border-right: 1px solid var(--border-light); height: 100vh; position: fixed; top: 0; left: 0; padding: 32px 20px; display: flex; flex-direction: column; justify-content: space-between; z-index: 1000; }
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
        .hospital-header h2 { font-size: 26px; font-weight: 700; color: #0f172a; margin: 0 0 4px 0; }
        .hospital-header p { font-size: 13px; color: var(--text-sub); margin-bottom: 32px; }
        .metric-card { background-color: #ffffff; border: 1px solid var(--border-light); border-radius: 10px; padding: 24px; display: flex; justify-content: space-between; align-items: flex-start; }
        .metric-meta span { font-size: 13px; color: var(--text-sub); font-weight: 500; display: block; margin-bottom: 8px; }
        .metric-meta h3 { font-size: 28px; font-weight: 700; margin: 0; color: var(--text-main); }
        .metric-indicator-dot { width: 8px; height: 8px; background-color: var(--teal-accent); border-radius: 50%; margin-top: 6px; }
        .panel-card { background: #ffffff; border: 1px solid var(--border-light); border-radius: 10px; padding: 28px; height: 100%; }
        .panel-title { font-size: 16px; font-weight: 700; color: var(--text-main); margin-bottom: 2px; }
        .panel-subtitle { font-size: 12px; color: var(--text-sub); margin-bottom: 24px; }
        .staff-row { padding: 16px 0; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center; }
        .staff-row:last-child { border-bottom: none; padding-bottom: 0; }
        .staff-name { font-size: 14px; font-weight: 700; color: var(--text-main); }
        .staff-specialty { font-size: 12px; color: var(--teal-accent); font-weight: 500; }
        .request-row { padding: 16px 0; border-bottom: 1px solid #f1f5f9; }
        .request-row:last-child { border-bottom: none; padding-bottom: 0; }
        .request-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 4px; }
        .request-title { font-size: 14px; font-weight: 700; color: var(--text-main); }
        .request-id { font-size: 11px; color: var(--text-sub); margin-bottom: 6px; }
        .request-details { font-size: 12px; color: var(--text-sub); line-height: 1.6; }
        .request-details span { color: var(--text-main); font-weight: 500; }
        .custom-badge { font-size: 11px; font-weight: 600; padding: 4px 10px; border-radius: 4px; display: inline-block; }
        .badge-approved { background-color: #f0fdf4; color: #16a34a; }
        .badge-pending { background-color: #fffbeb; color: #d97706; }
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
                <a href="dashboard.php" class="menu-link active">Overview</a>
                <a href="patient_requests.php" class="menu-link">Patient Requests</a>
                <a href="send_records.php" class="menu-link">Send Records to Doctors</a>
                <a href="add_practitioner.php" class="menu-link">Add Practitioners</a>
                <a href="manage_practitioners.php" class="menu-link">Manage Doctors</a>
                <a href="analytics.php" class="menu-link">Analytics</a>
            </div>
        </div>
        <div><a href="logout.php" class="logout-link">Sign Out</a></div>
    </nav>

    <main class="workspace">
        <div class="hospital-header">
            <h2>Central Medical Center</h2>
            <p>Hospital ID: HOSP-2024-CMC</p>
        </div>

        <?php if (!empty($success_msg)): ?>
            <div class="alert alert-info mb-4"><?= htmlspecialchars($success_msg); ?></div>
        <?php endif; ?>
        <?php if (!empty($error_msg)): ?>
            <div class="alert alert-danger mb-4"><?= htmlspecialchars($error_msg); ?></div>
        <?php endif; ?>

        <div class="panel-card mb-4">
            <h2 class="panel-title">Request Patient Summary</h2>
            <p class="panel-subtitle">Request patient data from insurance system and other hospitals</p>
            <form action="dashboard.php" method="POST" class="row g-3">
                <div class="col-md-10">
                    <input type="text" name="patient_id_input" class="form-control" placeholder="Enter Patient DB ID, National ID, Email, or PT-YYYY-ID" required>
                </div>
                <div class="col-md-2">
                    <button type="submit" name="request_summary" class="btn btn-primary w-100" style="background-color: var(--teal-accent); border: none;">Request Data</button>
                </div>
            </form>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-12 col-sm-6 col-lg-4"><div class="metric-card h-100"><div class="metric-meta"><span>Active Practitioners</span><h3><?= htmlspecialchars($active_practitioners_count); ?></h3></div><div class="metric-indicator-dot"></div></div></div>
            <div class="col-12 col-sm-6 col-lg-4"><div class="metric-card h-100"><div class="metric-meta"><span>Patients Today</span><h3><?= htmlspecialchars($patients_today_count); ?></h3></div><div class="metric-indicator-dot"></div></div></div>
            <div class="col-12 col-sm-6 col-lg-4"><div class="metric-card h-100"><div class="metric-meta"><span>Data Requests</span><h3><?= htmlspecialchars($data_requests_count); ?></h3></div><div class="metric-indicator-dot"></div></div></div>
        </div>

        <div class="row g-4">
            <div class="col-lg-6">...</div>
            <div class="col-lg-6">...</div>
        </div>
    </main>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>