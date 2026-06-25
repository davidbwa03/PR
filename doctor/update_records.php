<?php
session_start();
if (!isset($_SESSION['doctor_id'])) {
    header("Location: login.php");
    exit();
}
require_once 'db.php';

$doctor_id   = $_SESSION['doctor_id'];
$doctor_name = $_SESSION['doctor_name'];

$success_msg = '';
$error_msg   = '';

// Fetch only approved patients for this doctor
$stmt_patients = $pdo->prepare("
    SELECT p.id, p.name, p.national_id, p.email
    FROM access_requests ar
    JOIN patients p ON p.id = ar.patient_id
    WHERE ar.doctor_name = :dname AND ar.request_status = 'approved'
    ORDER BY p.name ASC
");
$stmt_patients->execute(['dname' => $doctor_name]);
$approved_patients = $stmt_patients->fetchAll(PDO::FETCH_ASSOC);

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $patient_id  = (int)($_POST['patient_id'] ?? 0);
    $form_type   = $_POST['form_type'] ?? '';

    // Verify this patient is actually approved
    $stmt_verify = $pdo->prepare("
        SELECT id FROM access_requests
        WHERE doctor_name = :dname AND patient_id = :pid AND request_status = 'approved'
    ");
    $stmt_verify->execute(['dname' => $doctor_name, 'pid' => $patient_id]);
    $allowed = $stmt_verify->fetch();

    if (!$allowed) {
        $error_msg = "You do not have approved access to this patient.";
    } elseif ($form_type === 'medical_record') {
        $visit_type    = trim($_POST['visit_type'] ?? '');
        $hospital_name = trim($_POST['hospital_name'] ?? '');
        $visit_date    = trim($_POST['visit_date'] ?? '');
        $notes         = trim($_POST['notes'] ?? '');
        $diagnosis     = trim($_POST['diagnosis'] ?? '');
        $treatment     = trim($_POST['treatment'] ?? '');

        if (empty($visit_type) || empty($hospital_name) || empty($visit_date)) {
            $error_msg = "Please fill in all required fields.";
        } else {
            $stmt_ins = $pdo->prepare("
                INSERT INTO medical_records (patient_id, visit_type, hospital_name, visit_date, notes, created_by, diagnosis, treatment)
                VALUES (:pid, :vtype, :hospital, :vdate, :notes, :doc, :diag, :treat)
            ");
            $stmt_ins->execute([
                'pid'      => $patient_id,
                'vtype'    => $visit_type,
                'hospital' => $hospital_name,
                'vdate'    => $visit_date,
                'notes'    => $notes,
                'doc'      => $doctor_name,
                'diag'     => $diagnosis,
                'treat'    => $treatment
            ]);
            $success_msg = "Medical record added successfully.";
        }
    } elseif ($form_type === 'prescription') {
        $medication_name = trim($_POST['medication_name'] ?? '');
        $dosage          = trim($_POST['dosage'] ?? '');
        $frequency       = trim($_POST['frequency'] ?? '');
        $duration        = trim($_POST['duration'] ?? '');
        $notes           = trim($_POST['presc_notes'] ?? '');

        if (empty($medication_name) || empty($dosage) || empty($frequency)) {
            $error_msg = "Please fill in all required prescription fields.";
        } else {
            $stmt_presc = $pdo->prepare("
                INSERT INTO medication_prescriptions (patient_id, medication_name, dosage, frequency, duration, notes, prescribed_by)
                VALUES (:pid, :mname, :dosage, :freq, :dur, :notes, :doc)
            ");
            $stmt_presc->execute([
                'pid'    => $patient_id,
                'mname'  => $medication_name,
                'dosage' => $dosage,
                'freq'   => $frequency,
                'dur'    => $duration,
                'notes'  => $notes,
                'doc'    => $doctor_name,
            ]);
            $success_msg = "Prescription added successfully.";
        }
    }
}

$selected_id      = (int)($_GET['patient_id'] ?? $_POST['patient_id'] ?? 0);
$selected_patient = null;
$recent_records   = [];
$recent_prescriptions = [];

if ($selected_id) {
    foreach ($approved_patients as $ap) {
        if ($ap['id'] === $selected_id) { $selected_patient = $ap; break; }
    }
    if ($selected_patient) {
        $stmt_rec = $pdo->prepare("SELECT * FROM medical_records WHERE patient_id = :pid ORDER BY visit_date DESC LIMIT 5");
        $stmt_rec->execute(['pid' => $selected_id]);
        $recent_records = $stmt_rec->fetchAll(PDO::FETCH_ASSOC);

        $stmt_presc = $pdo->prepare("SELECT * FROM medication_prescriptions WHERE patient_id = :pid ORDER BY id DESC LIMIT 5");
        $stmt_presc->execute(['pid' => $selected_id]);
        $recent_prescriptions = $stmt_presc->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Update Records | Practitioner Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { background-color: #f8fafc; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; color: #1e293b; }
        .sidebar { width: 220px; height: 100vh; position: fixed; top: 0; left: 0; background: #ffffff; border-right: 1px solid #e2e8f0; padding: 28px 18px; display: flex; flex-direction: column; z-index: 100; }
        .sidebar-brand { display: flex; align-items: center; gap: 10px; margin-bottom: 36px; }
        .icon-box { background: #0e7490; color: #ffffff; width: 38px; height: 38px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 1rem; flex-shrink: 0; }
        .brand-label strong { font-size: 0.9rem; display: block; color: #0f172a; }
        .brand-label small { font-size: 0.68rem; color: #94a3b8; }
        .nav-link-custom { display: flex; align-items: center; gap: 10px; padding: 11px 14px; margin-bottom: 6px; border-radius: 8px; color: #475569; text-decoration: none; font-size: 0.88rem; font-weight: 500; transition: background 0.15s, color 0.15s; }
        .nav-link-custom:hover { background: #f1f5f9; color: #0e7490; }
        .nav-link-custom.active { background: #0e7490; color: #ffffff; }
        .sign-out { margin-top: auto; display: flex; align-items: center; gap: 10px; padding: 11px 14px; color: #64748b; text-decoration: none; font-size: 0.88rem; border-radius: 8px; }
        .sign-out:hover { background: #fee2e2; color: #dc2626; }
        .main-content { margin-left: 220px; padding: 40px 44px; min-height: 100vh; }
        .page-header { margin-bottom: 28px; }
        .page-header h2 { font-size: 1.45rem; font-weight: 700; color: #0f172a; }
        .alert-custom { padding: 12px 16px; border-radius: 9px; font-size: 0.85rem; margin-bottom: 22px; }
        .alert-success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; }
        .alert-error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
        .selector-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 24px 26px; margin-bottom: 24px; }
        .patient-select { width: 100%; padding: 11px 14px; border: 1.5px solid #e2e8f0; border-radius: 9px; }
        .forms-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 22px; margin-bottom: 24px; }
        .form-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 24px 26px; }
        .field { margin-bottom: 14px; }
        .field label { display: block; font-size: 0.79rem; font-weight: 600; color: #475569; margin-bottom: 6px; }
        .field input, .field select, .field textarea { width: 100%; padding: 10px 13px; border: 1.5px solid #e2e8f0; border-radius: 8px; font-size: 0.87rem; }
        .btn-submit { width: 100%; padding: 11px; background: #0e7490; color: #fff; border: none; border-radius: 9px; font-weight: 600; cursor: pointer; }
        .btn-submit.presc { background: #7c3aed; }
        .preview-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 22px; }
        .preview-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 22px 24px; }
        .record-item { padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 0.83rem; }
        .disabled-overlay { opacity: 0.45; pointer-events: none; }
    </style>
</head>
<body>
<div class="sidebar">
    <div class="sidebar-brand">
        <div class="icon-box"><i class="fa-solid fa-stethoscope"></i></div>
        <div class="brand-label"><strong>Practitioner</strong><small>Portal</small></div>
    </div>
    <a href="dashboard.php" class="nav-link-custom"><i class="fa-solid fa-magnifying-glass"></i> Request Data</a>
    <a href="my_patients.php" class="nav-link-custom"><i class="fa-solid fa-users"></i> My Patients</a>
    <a href="update_records.php" class="nav-link-custom active"><i class="fa-solid fa-pen-to-square"></i> Update Records</a>
    <a href="logout.php" class="sign-out"><i class="fa-solid fa-arrow-right-from-bracket"></i> Sign Out</a>
</div>

<div class="main-content">
    <div class="page-header"><h2>Update Records</h2></div>

    <?php if ($success_msg): ?><div class="alert-custom alert-success"><?php echo $success_msg; ?></div><?php endif; ?>
    <?php if ($error_msg): ?><div class="alert-custom alert-error"><?php echo $error_msg; ?></div><?php endif; ?>

    <div class="selector-card">
        <form method="GET">
            <select name="patient_id" class="patient-select" onchange="this.form.submit()">
                <option value="">— Select Patient —</option>
                <?php foreach ($approved_patients as $ap): ?>
                    <option value="<?php echo $ap['id']; ?>" <?php echo $selected_id == $ap['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($ap['name']); ?> (<?php echo htmlspecialchars($ap['national_id']); ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <div class="forms-grid <?php echo !$selected_patient ? 'disabled-overlay' : ''; ?>">
        <div class="form-card">
            <h5>Add Medical Record</h5>
            <form method="POST">
                <input type="hidden" name="form_type" value="medical_record">
                <input type="hidden" name="patient_id" value="<?php echo $selected_id; ?>">
                <div class="field"><label>Visit Type</label><select name="visit_type"><option>Consultation</option><option>Follow-up</option></select></div>
                <div class="field"><label>Hospital</label><input type="text" name="hospital_name"></div>
                <div class="field"><label>Date</label><input type="date" name="visit_date"></div>
                <div class="field"><label>Diagnosis</label><textarea name="diagnosis"></textarea></div>
                <div class="field"><label>Treatment</label><textarea name="treatment"></textarea></div>
                <div class="field"><label>Notes</label><textarea name="notes"></textarea></div>
                <button type="submit" class="btn-submit">Add Record</button>
            </form>
        </div>
        <div class="form-card">
            <h5>Add Prescription</h5>
            <form method="POST">
                <input type="hidden" name="form_type" value="prescription">
                <input type="hidden" name="patient_id" value="<?php echo $selected_id; ?>">
                <div class="field"><label>Medication</label><input type="text" name="medication_name"></div>
                <div class="field"><label>Dosage</label><input type="text" name="dosage"></div>
                <div class="field"><label>Frequency</label><input type="text" name="frequency"></div>
                <div class="field"><label>Duration</label><input type="text" name="duration"></div>
                <div class="field"><label>Notes</label><textarea name="presc_notes"></textarea></div>
                <button type="submit" class="btn-submit presc">Add Prescription</button>
            </form>
        </div>
    </div>

    <?php if ($selected_patient): ?>
    <div class="preview-grid">
        <div class="preview-card">
            <h6>Recent Medical Records</h6>
            <?php foreach ($recent_records as $rec): ?>
                <div class="record-item">
                    <strong><?php echo htmlspecialchars($rec['visit_type']); ?></strong><br>
                    <small><?php echo htmlspecialchars($rec['visit_date']); ?> | <?php echo htmlspecialchars($rec['hospital_name']); ?></small>
                    <p>Diag: <?php echo htmlspecialchars($rec['diagnosis']); ?><br>Treat: <?php echo htmlspecialchars($rec['treatment']); ?></p>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="preview-card">
            <h6>Recent Prescriptions</h6>
            <?php foreach ($recent_prescriptions as $presc): ?>
                <div class="record-item">
                    <strong><?php echo htmlspecialchars($presc['medication_name']); ?></strong><br>
                    <small><?php echo htmlspecialchars($presc['dosage']); ?> - <?php echo htmlspecialchars($presc['frequency']); ?></small>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
</body>
</html>