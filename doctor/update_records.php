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

    // Verify this patient is actually approved for this doctor
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

        if (empty($visit_type) || empty($hospital_name) || empty($visit_date)) {
            $error_msg = "Please fill in all required fields for the medical record.";
        } else {
            $stmt_ins = $pdo->prepare("
                INSERT INTO medical_records (patient_id, visit_type, hospital_name, visit_date, notes, created_by)
                VALUES (:pid, :vtype, :hospital, :vdate, :notes, :doc)
            ");
            $stmt_ins->execute([
                'pid'      => $patient_id,
                'vtype'    => $visit_type,
                'hospital' => $hospital_name,
                'vdate'    => $visit_date,
                'notes'    => $notes,
                'doc'      => $doctor_name,
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

// Selected patient for preview
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

        body {
            background-color: #f8fafc;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: #1e293b;
        }

        /* ── Sidebar ── */
        .sidebar {
            width: 220px;
            height: 100vh;
            position: fixed;
            top: 0; left: 0;
            background: #ffffff;
            border-right: 1px solid #e2e8f0;
            padding: 28px 18px;
            display: flex;
            flex-direction: column;
            z-index: 100;
        }

        .sidebar-brand { display: flex; align-items: center; gap: 10px; margin-bottom: 36px; }

        .icon-box {
            background: #0e7490; color: #ffffff;
            width: 38px; height: 38px; border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1rem; flex-shrink: 0;
        }

        .brand-label strong { font-size: 0.9rem; display: block; color: #0f172a; }
        .brand-label small  { font-size: 0.68rem; color: #94a3b8; }

        .nav-link-custom {
            display: flex; align-items: center; gap: 10px;
            padding: 11px 14px; margin-bottom: 6px;
            border-radius: 8px; color: #475569;
            text-decoration: none; font-size: 0.88rem; font-weight: 500;
            transition: background 0.15s, color 0.15s;
        }
        .nav-link-custom:hover  { background: #f1f5f9; color: #0e7490; }
        .nav-link-custom.active { background: #0e7490; color: #ffffff; }
        .nav-link-custom i { width: 18px; text-align: center; }

        .sign-out {
            margin-top: auto;
            display: flex; align-items: center; gap: 10px;
            padding: 11px 14px; color: #64748b;
            text-decoration: none; font-size: 0.88rem;
            border-radius: 8px; transition: background 0.15s;
        }
        .sign-out:hover { background: #fee2e2; color: #dc2626; }

        /* ── Main ── */
        .main-content { margin-left: 220px; padding: 40px 44px; min-height: 100vh; }

        .page-header { margin-bottom: 28px; }
        .page-header h2 { font-size: 1.45rem; font-weight: 700; color: #0f172a; }
        .page-header p  { color: #64748b; font-size: 0.85rem; margin-top: 3px; }

        /* ── Alerts ── */
        .alert-custom {
            padding: 12px 16px; border-radius: 9px;
            font-size: 0.85rem; margin-bottom: 22px;
            display: flex; align-items: center; gap: 9px;
        }
        .alert-success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; }
        .alert-error   { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }

        /* ── Patient Selector ── */
        .selector-card {
            background: #fff; border: 1px solid #e2e8f0;
            border-radius: 14px; padding: 24px 26px;
            margin-bottom: 24px;
        }
        .selector-card h5 {
            font-size: 0.95rem; font-weight: 600;
            color: #0f172a; margin-bottom: 14px;
        }

        .patient-select {
            width: 100%; padding: 11px 14px;
            border: 1.5px solid #e2e8f0; border-radius: 9px;
            font-size: 0.88rem; color: #1e293b;
            background: #f8fafc; outline: none;
            transition: border-color 0.15s;
            cursor: pointer;
        }
        .patient-select:focus { border-color: #0e7490; background: #fff; }

        /* Selected patient pill */
        .patient-pill {
            display: inline-flex; align-items: center; gap: 10px;
            background: #f0f9ff; border: 1px solid #bae6fd;
            border-radius: 10px; padding: 10px 16px;
            margin-top: 14px;
        }
        .patient-pill .avatar {
            width: 34px; height: 34px; border-radius: 50%;
            background: #0e7490; color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.85rem; font-weight: 700; flex-shrink: 0;
        }
        .patient-pill strong { font-size: 0.88rem; color: #0f172a; display: block; }
        .patient-pill small  { font-size: 0.76rem; color: #64748b; }

        /* ── Two-column forms ── */
        .forms-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 22px;
            margin-bottom: 24px;
        }

        .form-card {
            background: #fff; border: 1px solid #e2e8f0;
            border-radius: 14px; padding: 24px 26px;
        }
        .form-card h5 {
            font-size: 0.95rem; font-weight: 600;
            color: #0f172a; margin-bottom: 18px;
            display: flex; align-items: center; gap: 8px;
        }

        .field { margin-bottom: 14px; }
        .field label {
            display: block; font-size: 0.79rem;
            font-weight: 600; color: #475569;
            margin-bottom: 6px; letter-spacing: 0.01em;
        }
        .field input, .field select, .field textarea {
            width: 100%; padding: 10px 13px;
            border: 1.5px solid #e2e8f0; border-radius: 8px;
            font-size: 0.87rem; color: #1e293b;
            background: #f8fafc; outline: none;
            transition: border-color 0.15s, background 0.15s;
            font-family: inherit;
        }
        .field input:focus, .field select:focus, .field textarea:focus {
            border-color: #0e7490; background: #fff;
        }
        .field textarea { resize: vertical; min-height: 72px; }
        .field input::placeholder, .field textarea::placeholder { color: #b0bec5; }

        .required-star { color: #ef4444; margin-left: 2px; }

        .btn-submit {
            width: 100%; padding: 11px;
            background: #0e7490; color: #fff;
            border: none; border-radius: 9px;
            font-size: 0.9rem; font-weight: 600;
            cursor: pointer; margin-top: 4px;
            transition: background 0.15s, transform 0.1s;
        }
        .btn-submit:hover  { background: #0b6070; }
        .btn-submit:active { transform: scale(0.99); }

        .btn-submit.presc { background: #7c3aed; }
        .btn-submit.presc:hover { background: #6d28d9; }

        /* ── Recent Records Preview ── */
        .preview-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 22px;
        }

        .preview-card {
            background: #fff; border: 1px solid #e2e8f0;
            border-radius: 14px; padding: 22px 24px;
        }
        .preview-card h6 {
            font-size: 0.88rem; font-weight: 600;
            color: #0f172a; margin-bottom: 14px;
            display: flex; align-items: center; gap: 7px;
        }

        .record-item {
            padding: 10px 0;
            border-bottom: 1px solid #f1f5f9;
            font-size: 0.83rem;
        }
        .record-item:last-child { border-bottom: none; }
        .record-item strong { color: #0f172a; display: block; margin-bottom: 2px; }
        .record-item span   { color: #64748b; font-size: 0.78rem; }

        .empty-preview {
            text-align: center; padding: 28px 0;
            color: #cbd5e1; font-size: 0.82rem;
        }
        .empty-preview i { display: block; font-size: 1.6rem; margin-bottom: 8px; }

        /* disabled state */
        .disabled-overlay {
            opacity: 0.45; pointer-events: none;
        }

        @media (max-width: 900px) {
            .forms-grid, .preview-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 768px) {
            .sidebar { display: none; }
            .main-content { margin-left: 0; padding: 24px 18px; }
        }
    </style>
</head>
<body>

<!-- ══ Sidebar ══ -->
<div class="sidebar">
    <div class="sidebar-brand">
        <div class="icon-box"><i class="fa-solid fa-stethoscope"></i></div>
        <div class="brand-label">
            <strong>Practitioner</strong>
            <small>Portal Panel</small>
        </div>
    </div>

    <a href="dashboard.php" class="nav-link-custom">
        <i class="fa-solid fa-magnifying-glass"></i> Request Patient Data
    </a>
    <a href="my_patients.php" class="nav-link-custom">
        <i class="fa-solid fa-users"></i> My Patients
    </a>
    <a href="update_records.php" class="nav-link-custom active">
        <i class="fa-solid fa-pen-to-square"></i> Update Records
    </a>

    <a href="logout.php" class="sign-out">
        <i class="fa-solid fa-arrow-right-from-bracket"></i> Sign Out
    </a>
</div>

<!-- ══ Main ══ -->
<div class="main-content">

    <div class="page-header">
        <h2>Update Records</h2>
        <p>Add medical records and prescriptions for your approved patients</p>
    </div>

    <?php if ($success_msg): ?>
        <div class="alert-custom alert-success">
            <i class="fa-solid fa-circle-check"></i> <?php echo htmlspecialchars($success_msg); ?>
        </div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert-custom alert-error">
            <i class="fa-solid fa-circle-exclamation"></i> <?php echo htmlspecialchars($error_msg); ?>
        </div>
    <?php endif; ?>

    <!-- Patient Selector -->
    <div class="selector-card">
        <h5><i class="fa-solid fa-user-check" style="color:#0e7490;"></i> Select Approved Patient</h5>

        <?php if (empty($approved_patients)): ?>
            <p style="font-size:0.85rem; color:#94a3b8;">
                No approved patients yet. Ask a patient to approve your request from
                <a href="dashboard.php" style="color:#0e7490; font-weight:600;">Request Patient Data</a>.
            </p>
        <?php else: ?>
            <form method="GET" action="update_records.php">
                <select name="patient_id" class="patient-select" onchange="this.form.submit()">
                    <option value="">— Choose a patient —</option>
                    <?php foreach ($approved_patients as $ap): ?>
                        <option value="<?php echo $ap['id']; ?>"
                            <?php echo $selected_id === $ap['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($ap['name']); ?> &nbsp;·&nbsp; NID: <?php echo htmlspecialchars($ap['national_id']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>

            <?php if ($selected_patient): ?>
                <div class="patient-pill">
                    <div class="avatar"><?php echo strtoupper(substr($selected_patient['name'], 0, 1)); ?></div>
                    <div>
                        <strong><?php echo htmlspecialchars($selected_patient['name']); ?></strong>
                        <small>NID: <?php echo htmlspecialchars($selected_patient['national_id']); ?> &nbsp;·&nbsp; <?php echo htmlspecialchars($selected_patient['email']); ?></small>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Forms -->
    <div class="forms-grid <?php echo !$selected_patient ? 'disabled-overlay' : ''; ?>">

        <!-- Medical Record Form -->
        <div class="form-card">
            <h5><i class="fa-solid fa-file-medical" style="color:#0e7490;"></i> Add Medical Record</h5>
            <form method="POST" action="update_records.php">
                <input type="hidden" name="form_type"  value="medical_record">
                <input type="hidden" name="patient_id" value="<?php echo $selected_id; ?>">

                <div class="field">
                    <label>Visit Type <span class="required-star">*</span></label>
                    <select name="visit_type">
                        <option value="">— Select —</option>
                        <option>Consultation</option>
                        <option>Follow-up</option>
                        <option>Emergency</option>
                        <option>Routine Check-up</option>
                        <option>Surgery</option>
                        <option>Lab Test</option>
                        <option>Imaging</option>
                        <option>Vaccination</option>
                        <option>Physiotherapy</option>
                        <option>Other</option>
                    </select>
                </div>

                <div class="field">
                    <label>Hospital / Facility <span class="required-star">*</span></label>
                    <input type="text" name="hospital_name" placeholder="e.g. Nairobi General Hospital">
                </div>

                <div class="field">
                    <label>Visit Date <span class="required-star">*</span></label>
                    <input type="date" name="visit_date" max="<?php echo date('Y-m-d'); ?>">
                </div>

                <div class="field">
                    <label>Clinical Notes</label>
                    <textarea name="notes" placeholder="Optional notes about this visit..."></textarea>
                </div>

                <button type="submit" class="btn-submit">
                    <i class="fa-solid fa-plus me-1"></i> Add Medical Record
                </button>
            </form>
        </div>

        <!-- Prescription Form -->
        <div class="form-card">
            <h5><i class="fa-solid fa-pills" style="color:#7c3aed;"></i> Add Prescription</h5>
            <form method="POST" action="update_records.php">
                <input type="hidden" name="form_type"  value="prescription">
                <input type="hidden" name="patient_id" value="<?php echo $selected_id; ?>">

                <div class="field">
                    <label>Medication Name <span class="required-star">*</span></label>
                    <input type="text" name="medication_name" placeholder="e.g. Amoxicillin">
                </div>

                <div class="field">
                    <label>Dosage <span class="required-star">*</span></label>
                    <input type="text" name="dosage" placeholder="e.g. 500mg">
                </div>

                <div class="field">
                    <label>Frequency <span class="required-star">*</span></label>
                    <select name="frequency">
                        <option value="">— Select —</option>
                        <option>Once daily</option>
                        <option>Twice daily</option>
                        <option>Three times daily</option>
                        <option>Four times daily</option>
                        <option>Every 6 hours</option>
                        <option>Every 8 hours</option>
                        <option>Every 12 hours</option>
                        <option>Once weekly</option>
                        <option>As needed (PRN)</option>
                    </select>
                </div>

                <div class="field">
                    <label>Duration</label>
                    <input type="text" name="duration" placeholder="e.g. 7 days, 2 weeks">
                </div>

                <div class="field">
                    <label>Notes</label>
                    <textarea name="presc_notes" placeholder="e.g. Take with food, avoid alcohol..."></textarea>
                </div>

                <button type="submit" class="btn-submit presc">
                    <i class="fa-solid fa-plus me-1"></i> Add Prescription
                </button>
            </form>
        </div>

    </div>

    <!-- Recent Records Preview -->
    <?php if ($selected_patient): ?>
    <div class="preview-grid">

        <div class="preview-card">
            <h6><i class="fa-solid fa-clock-rotate-left" style="color:#0e7490;"></i> Recent Medical Records</h6>
            <?php if (!empty($recent_records)): ?>
                <?php foreach ($recent_records as $rec): ?>
                    <div class="record-item">
                        <strong><?php echo htmlspecialchars($rec['visit_type']); ?> — <?php echo htmlspecialchars($rec['hospital_name']); ?></strong>
                        <span><?php echo date('M d, Y', strtotime($rec['visit_date'])); ?>
                            <?php if (!empty($rec['notes'])): ?>
                                &nbsp;·&nbsp; <?php echo htmlspecialchars(substr($rec['notes'], 0, 60)) . (strlen($rec['notes']) > 60 ? '...' : ''); ?>
                            <?php endif; ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-preview">
                    <i class="fa-solid fa-folder-open"></i>
                    No records yet for this patient.
                </div>
            <?php endif; ?>
        </div>

        <div class="preview-card">
            <h6><i class="fa-solid fa-clock-rotate-left" style="color:#7c3aed;"></i> Recent Prescriptions</h6>
            <?php if (!empty($recent_prescriptions)): ?>
                <?php foreach ($recent_prescriptions as $presc): ?>
                    <div class="record-item">
                        <strong><?php echo htmlspecialchars($presc['medication_name']); ?> — <?php echo htmlspecialchars($presc['dosage']); ?></strong>
                        <span><?php echo htmlspecialchars($presc['frequency']); ?>
                            <?php if (!empty($presc['duration'])): ?>
                                &nbsp;·&nbsp; <?php echo htmlspecialchars($presc['duration']); ?>
                            <?php endif; ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-preview">
                    <i class="fa-solid fa-prescription-bottle"></i>
                    No prescriptions yet for this patient.
                </div>
            <?php endif; ?>
        </div>

    </div>
    <?php endif; ?>

</div>

</body>
</html>