<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['staff_logged_in']) || $_SESSION['staff_logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

$staff_name = isset($_SESSION['staff_name']) ? $_SESSION['staff_name'] : 'Hospital Staff';
$success_msg = '';
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_vitals'])) {
    $patient_ref = trim($_POST['patient_ref'] ?? '');
    $blood_pressure = trim($_POST['blood_pressure'] ?? '');
    $heart_rate = trim($_POST['heart_rate'] ?? '');
    $temperature = trim($_POST['temperature'] ?? '');
    $hospital_name = trim($_POST['hospital_name'] ?? 'Central Medical Center');
    $notes = trim($_POST['notes'] ?? '');

    $patient_id = null;
    $patient_ref_match = [];

    if (preg_match('/^PT-\d{4}-(\d+)$/i', $patient_ref, $patient_ref_match)) {
        $patient_id = (int)$patient_ref_match[1];
    } elseif (ctype_digit($patient_ref)) {
        $patient_id = (int)$patient_ref;
    }

    try {
        if (empty($patient_ref) || empty($blood_pressure) || empty($heart_rate) || empty($temperature)) {
            $error_msg = 'Patient reference, blood pressure, heart rate, and temperature are required.';
        } else {
            if ($patient_id !== null && $patient_id > 0) {
                $stmt_patient = $pdo->prepare("SELECT id, name FROM patients WHERE id = ? LIMIT 1");
                $stmt_patient->execute([$patient_id]);
            } else {
                $stmt_patient = $pdo->prepare("SELECT id, name FROM patients WHERE national_id = ? OR email = ? LIMIT 1");
                $stmt_patient->execute([$patient_ref, $patient_ref]);
            }

            $patient = $stmt_patient->fetch(PDO::FETCH_ASSOC);

            if (!$patient) {
                $error_msg = 'No patient matched the provided reference.';
            } else {
                $resolved_patient_id = (int)$patient['id'];
                $clinical_note = empty($notes) ? 'Vitals update captured by hospital staff.' : $notes;

                $stmt_insert = $pdo->prepare(" 
                    INSERT INTO medical_records (
                        patient_id,
                        visit_type,
                        hospital_name,
                        visit_date,
                        notes,
                        created_by,
                        blood_pressure,
                        heart_rate,
                        temperature,
                        clinical_notes
                    ) VALUES (
                        :pid,
                        :visit_type,
                        :hospital,
                        CURDATE(),
                        :notes,
                        :created_by,
                        :blood_pressure,
                        :heart_rate,
                        :temperature,
                        :clinical_notes
                    )
                ");

                $stmt_insert->execute([
                    'pid' => $resolved_patient_id,
                    'visit_type' => 'Vitals Check',
                    'hospital' => $hospital_name,
                    'notes' => $notes,
                    'created_by' => $staff_name,
                    'blood_pressure' => $blood_pressure,
                    'heart_rate' => $heart_rate,
                    'temperature' => $temperature,
                    'clinical_notes' => $clinical_note,
                ]);

                $success_msg = 'Vitals were saved for ' . $patient['name'] . '.';
            }
        }
    } catch (PDOException $e) {
        $error_msg = 'Could not save vitals right now. Please try again.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Update Patient Vitals - Central Medical Center</title>
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
        .panel-card { background: #ffffff; border: 1px solid var(--border-light); border-radius: 12px; padding: 28px; max-width: 900px; }
        .panel-title { font-size: 22px; font-weight: 700; color: #0f172a; margin-bottom: 8px; }
        .panel-subtitle { font-size: 13px; color: var(--text-sub); margin-bottom: 24px; }
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
                <a href="patient_requests.php" class="menu-link">Patient Requests</a>
                <a href="send_records.php" class="menu-link">Send Records to Doctors</a>
                <a href="update_vitals.php" class="menu-link active">Update Patient Vitals</a>
                <a href="add_practitioner.php" class="menu-link">Add Practitioners</a>
                <a href="manage_practitioners.php" class="menu-link">Manage Doctors</a>
                <a href="analytics.php" class="menu-link">Analytics</a>
            </div>
        </div>
        <div><a href="logout.php" class="logout-link">Sign Out</a></div>
    </nav>

    <main class="workspace">
        <div class="panel-card">
            <h2 class="panel-title">Update Patient Vitals</h2>
            <p class="panel-subtitle">Hospital staff can add blood pressure, heart rate, and body temperature for a patient.</p>

            <?php if (!empty($success_msg)): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success_msg); ?></div>
            <?php endif; ?>
            <?php if (!empty($error_msg)): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error_msg); ?></div>
            <?php endif; ?>

            <form method="POST" class="row g-3">
                <div class="col-12">
                    <label class="form-label">Patient Reference</label>
                    <input type="text" name="patient_ref" class="form-control" placeholder="Patient DB ID, National ID, Email, or PT-YYYY-ID" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Blood Pressure</label>
                    <input type="text" name="blood_pressure" class="form-control" placeholder="120/80" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Heart Rate (BPM)</label>
                    <input type="number" min="1" name="heart_rate" class="form-control" placeholder="72" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Temperature (C)</label>
                    <input type="number" step="0.1" min="30" max="45" name="temperature" class="form-control" placeholder="36.6" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Hospital / Facility</label>
                    <input type="text" name="hospital_name" class="form-control" value="Central Medical Center">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Notes</label>
                    <input type="text" name="notes" class="form-control" placeholder="Optional observation notes">
                </div>
                <div class="col-12">
                    <button type="submit" name="save_vitals" class="btn btn-primary" style="background-color: var(--teal-accent); border: none;">Save Vitals</button>
                </div>
            </form>
        </div>
    </main>
</body>
</html>
