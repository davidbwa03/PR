<?php
// staff/send_records.php
session_start();
require_once 'db.php';

if (!isset($_SESSION['staff_logged_in']) || $_SESSION['staff_logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

$staff_name = isset($_SESSION['staff_name']) ? $_SESSION['staff_name'] : 'Administrator';
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

$success_msg = "";
$error_msg = "";
$doctors_list = [];
$hasDoctorStatusColumn = false;

try {
    $colCheck = $pdo->query("SHOW COLUMNS FROM doctors LIKE 'status'");
    $hasDoctorStatusColumn = $colCheck && $colCheck->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $hasDoctorStatusColumn = false;
}

// Handle sending records to the requesting doctor
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_records'])) {
    $request_id = (int)$_POST['request_id'];
    $doctor_id = (int)($_POST['doctor_id'] ?? 0);

    if ($request_id > 0 && $doctor_id > 0) {
        $doctorLookupSql = $hasDoctorStatusColumn
            ? "SELECT name FROM doctors WHERE id = ? AND status = 'active' LIMIT 1"
            : "SELECT name FROM doctors WHERE id = ? LIMIT 1";

        $stmt_doctor = $pdo->prepare($doctorLookupSql);
        $stmt_doctor->execute([$doctor_id]);
        $selected_doctor_name = $stmt_doctor->fetchColumn();

        if ($selected_doctor_name) {
            $sender_facility = ($hospital_brand_name !== '') ? $hospital_brand_name : 'Central Medical Center';
            $stmt = $pdo->prepare("UPDATE access_requests SET doctor_name = ?, medical_facility = ?, records_sent = 1, updated_at = NOW() WHERE id = ? AND request_status = 'approved'");
            $stmt->execute([$selected_doctor_name, $sender_facility, $request_id]);
            if ($stmt->rowCount() > 0) {
                $success_msg = "Medical records successfully sent to Dr. " . $selected_doctor_name . ".";
            } else {
                $error_msg = "Unable to send records. This request may not be approved yet.";
            }
        } else {
            $error_msg = $hasDoctorStatusColumn
                ? "Selected doctor is inactive or was not found. Please choose an active doctor."
                : "Selected doctor was not found. Please choose a valid doctor.";
        }
    } else {
        $error_msg = "Please choose a doctor before sending records.";
    }
}

try {
    $doctorsSql = $hasDoctorStatusColumn
        ? "SELECT id, name, specialty FROM doctors WHERE status = 'active' ORDER BY name ASC"
        : "SELECT id, name, specialty FROM doctors ORDER BY name ASC";

    $stmt_doctors = $pdo->query($doctorsSql);
    $doctors_list = $stmt_doctors->fetchAll(PDO::FETCH_ASSOC);

    // Approved requests still waiting to be dispatched
    $stmt_pending = $pdo->query("
        SELECT ar.id, ar.patient_id, ar.doctor_name, ar.medical_facility, ar.requested_at, ar.updated_at,
               p.name AS patient_name, p.national_id AS patient_national_id
        FROM access_requests ar
        LEFT JOIN patients p ON p.id = ar.patient_id
        WHERE ar.request_status = 'approved' AND (ar.records_sent IS NULL OR ar.records_sent = 0)
        ORDER BY ar.updated_at DESC
    ");
    $pending_dispatch = $stmt_pending->fetchAll(PDO::FETCH_ASSOC);

    // Recently sent records, for reference
    $stmt_sent = $pdo->query("
        SELECT ar.id, ar.patient_id, ar.doctor_name, ar.medical_facility, ar.updated_at,
               p.name AS patient_name, p.national_id AS patient_national_id
        FROM access_requests ar
        LEFT JOIN patients p ON p.id = ar.patient_id
        WHERE ar.request_status = 'approved' AND ar.records_sent = 1
        ORDER BY ar.updated_at DESC
        LIMIT 10
    ");
    $sent_history = $stmt_sent->fetchAll(PDO::FETCH_ASSOC);
} catch (\PDOException $e) {
    $doctors_list = [];
    $pending_dispatch = [];
    $sent_history = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Send Records to Doctors - Central Medical Center</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
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
        .panel-card { background: #ffffff; border: 1px solid var(--border-light); border-radius: 10px; padding: 28px; height: 100%; }
        .panel-title { font-size: 16px; font-weight: 700; color: var(--text-main); margin-bottom: 2px; }
        .panel-subtitle { font-size: 12px; color: var(--text-sub); margin-bottom: 24px; }
        .custom-badge { font-size: 11px; font-weight: 600; padding: 4px 10px; border-radius: 4px; display: inline-block; }
        .badge-approved { background-color: #f0fdf4; color: #16a34a; }
        .badge-pending { background-color: #fffbeb; color: #d97706; }
        .badge-declined { background-color: #fef2f2; color: #ef4444; }

        /* Dispatch rows */
        .dispatch-row { padding: 18px 0; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center; gap: 16px; flex-wrap: wrap; }
        .dispatch-row:last-child { border-bottom: none; padding-bottom: 0; }
        .dispatch-name { font-size: 14px; font-weight: 700; color: var(--text-main); margin: 0; }
        .dispatch-meta { font-size: 12px; color: var(--text-sub); margin: 2px 0 0; line-height: 1.6; }
        .dispatch-meta span { color: var(--text-main); font-weight: 500; }
        .send-btn { background-color: var(--teal-accent); color: #ffffff; border: none; border-radius: 8px; padding: 10px 18px; font-weight: 600; font-size: 13px; display: flex; align-items: center; gap: 6px; white-space: nowrap; }
        .send-btn:hover { opacity: 0.92; color: #ffffff; }
        .doctor-select { min-width: 230px; border-radius: 8px; }
        .dispatch-form { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
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
                <a href="send_records.php" class="menu-link active">Send Records to Doctors</a>
                <a href="update_vitals.php" class="menu-link">Update Patient Vitals</a>
                <a href="add_practitioner.php" class="menu-link">Add Practitioners</a>
                <a href="manage_practitioners.php" class="menu-link">Manage Doctors</a>
                <a href="create_claim.php" class="menu-link">Create the claim</a>
                <a href="analytics.php" class="menu-link">Analytics</a>
            </div>
        </div>
        <div><a href="logout.php" class="logout-link">Sign Out</a></div>
    </nav>

    <main class="workspace">
        <div class="hospital-header">
            <h2>Send Records to Doctors</h2>
            <p>Dispatch approved patient records and choose which doctor receives them</p>
        </div>

        <?php if (!empty($success_msg)): ?>
            <div class="alert alert-info mb-4"><?= htmlspecialchars($success_msg); ?></div>
        <?php endif; ?>
        <?php if (!empty($error_msg)): ?>
            <div class="alert alert-danger mb-4"><?= htmlspecialchars($error_msg); ?></div>
        <?php endif; ?>

        <div class="panel-card mb-4">
            <h2 class="panel-title">Awaiting Dispatch</h2>
            <p class="panel-subtitle">Approved requests whose records have not been sent yet</p>

            <?php if (empty($doctors_list)): ?>
                <div class="alert alert-warning mb-3">No active doctors are available. Activate a doctor first, then send records.</div>
            <?php endif; ?>

            <?php if (empty($pending_dispatch)): ?>
                <p class="text-muted mb-0">Nothing waiting to be sent right now.</p>
            <?php else: ?>
                <?php foreach ($pending_dispatch as $req):
                    $displayName = $req['patient_name'] ?: ('Patient #' . $req['patient_id']);
                    $nationalId = $req['patient_national_id'] ?: '—';
                ?>
                <div class="dispatch-row">
                    <div>
                        <p class="dispatch-name\"><?= htmlspecialchars($displayName); ?></p>
                        <p class="dispatch-meta">National ID: <span><?= htmlspecialchars($nationalId); ?></span> &nbsp;•&nbsp; Requested By: <span><?= htmlspecialchars($req['doctor_name']); ?></span></p>
                        <p class="dispatch-meta">Facility: <span><?= htmlspecialchars($req['medical_facility']); ?></span> &nbsp;•&nbsp; Requested: <span><?= htmlspecialchars(date('Y-m-d', strtotime($req['requested_at']))); ?></span></p>
                    </div>
                    <form method="POST" class="dispatch-form">
                        <input type="hidden" name="request_id" value="<?= (int)$req['id']; ?>">
                        <select name="doctor_id" class="form-select doctor-select" required>
                            <option value="">Choose Doctor</option>
                            <?php foreach ($doctors_list as $doc): ?>
                                <option value="<?= (int)$doc['id']; ?>"><?= htmlspecialchars($doc['name']); ?><?= !empty($doc['specialty']) ? ' - ' . htmlspecialchars($doc['specialty']) : ''; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" name="send_records" class="send-btn" <?= empty($doctors_list) ? 'disabled' : ''; ?>><i class="bi bi-send-fill"></i> Send Records</button>
                    </form>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="panel-card">
            <h2 class="panel-title">Recently Sent</h2>
            <p class="panel-subtitle">Last 10 record dispatches</p>

            <?php if (empty($sent_history)): ?>
                <p class="text-muted mb-0">No records sent yet.</p>
            <?php else: ?>
                <?php foreach ($sent_history as $req):
                    $displayName = $req['patient_name'] ?: ('Patient #' . $req['patient_id']);
                    $nationalId = $req['patient_national_id'] ?: '—';
                ?>
                <div class="dispatch-row">
                    <div>
                        <p class="dispatch-name"><?= htmlspecialchars($displayName); ?></p>
                        <p class="dispatch-meta">National ID: <span><?= htmlspecialchars($nationalId); ?></span> &nbsp;•&nbsp; Sent To: <span><?= htmlspecialchars($req['doctor_name']); ?></span></p>
                        <p class="dispatch-meta">Sent: <span><?= htmlspecialchars(date('Y-m-d H:i', strtotime($req['updated_at']))); ?></span></p>
                    </div>
                    <span class="custom-badge badge-approved"><i class="bi bi-check-circle-fill"></i> Sent</span>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </main>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>