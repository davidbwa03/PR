<?php
session_start();
if (!isset($_SESSION['doctor_id'])) {
    header("Location: login.php");
    exit();
}
require_once 'db.php';

$success_msg = '';
$error_msg   = '';

$doctor_id   = $_SESSION['doctor_id'];
$doctor_name = $_SESSION['doctor_name'];

// Fetch doctor info for sidebar
$stmtDoc = $pdo->prepare("SELECT * FROM doctors WHERE id = :id");
$stmtDoc->execute(['id' => $doctor_id]);
$doctor = $stmtDoc->fetch(PDO::FETCH_ASSOC);
$doctor_specialty = $doctor['specialty'] ?? 'General Practitioner';

$pdo->exec("CREATE TABLE IF NOT EXISTS patient_privacy_consents (
    patient_id INT NOT NULL PRIMARY KEY,
    allergies_summary_text TEXT NULL,
    chronic_diagnostic_logs_text TEXT NULL,
    surgical_typologies_summary_text TEXT NULL,
    surgical_typologies_necessary TINYINT(1) NOT NULL DEFAULT 0,
    authored_by_doctor_id INT NULL,
    authored_by_doctor_name VARCHAR(255) NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

try { $pdo->exec("ALTER TABLE patient_privacy_consents ADD COLUMN allergies_summary_text TEXT NULL"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE patient_privacy_consents ADD COLUMN chronic_diagnostic_logs_text TEXT NULL"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE patient_privacy_consents ADD COLUMN surgical_typologies_summary_text TEXT NULL"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE patient_privacy_consents ADD COLUMN surgical_typologies_necessary TINYINT(1) NOT NULL DEFAULT 0"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE patient_privacy_consents ADD COLUMN authored_by_doctor_id INT NULL"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE patient_privacy_consents ADD COLUMN authored_by_doctor_name VARCHAR(255) NULL"); } catch (PDOException $e) {}

// Fetch all patients approved/assigned to this doctor by the admin
// Join via doctors table using doctor_id to avoid session name mismatch
$stmt_patients = $pdo->prepare("
    SELECT DISTINCT p.id, p.name, p.national_id, p.email
    FROM access_requests ar
    JOIN patients p ON p.id = ar.patient_id
    JOIN doctors d ON d.name = ar.doctor_name
    WHERE d.id = :did
      AND ar.request_status = 'approved'
      AND ar.records_sent = 1
    ORDER BY p.name ASC
");
$stmt_patients->execute(['did' => $doctor_id]);
$approved_patients = $stmt_patients->fetchAll(PDO::FETCH_ASSOC);

// Selected patient context
$selected_id      = (int)($_GET['patient_id'] ?? 0);
$selected_patient = null;
$recent_records   = [];
$recent_prescriptions = [];
$privacy_consents = [
    'allergies_summary_text' => '',
    'chronic_diagnostic_logs_text' => '',
    'surgical_typologies_summary_text' => '',
    'surgical_typologies_necessary' => 0,
    'authored_by_doctor_name' => null,
    'updated_at' => null,
];

if ($selected_id > 0) {
    foreach ($approved_patients as $ap) {
        if ((int)$ap['id'] === $selected_id) {
            $selected_patient = $ap;
            break;
        }
    }

    if ($selected_patient) {
        $stmt_consents = $pdo->prepare("SELECT allergies_summary_text, chronic_diagnostic_logs_text, surgical_typologies_summary_text, surgical_typologies_necessary, authored_by_doctor_name, updated_at FROM patient_privacy_consents WHERE patient_id = :pid LIMIT 1");
        $stmt_consents->execute(['pid' => $selected_id]);
        $loaded_consents = $stmt_consents->fetch(PDO::FETCH_ASSOC);
        if ($loaded_consents) {
            $privacy_consents = $loaded_consents;
        }

        $stmt_recent_records = $pdo->prepare("
            SELECT visit_type, hospital_name, visit_date, diagnosis, treatment
            FROM medical_records
            WHERE patient_id = :pid
            ORDER BY visit_date DESC, id DESC
            LIMIT 5
        ");
        $stmt_recent_records->execute(['pid' => $selected_id]);
        $recent_records = $stmt_recent_records->fetchAll(PDO::FETCH_ASSOC);

        $stmt_recent_prescriptions = $pdo->prepare("
            SELECT medication_name, dosage, frequency
            FROM medication_prescriptions
            WHERE patient_id = :pid
            ORDER BY id DESC
            LIMIT 5
        ");
        $stmt_recent_prescriptions->execute(['pid' => $selected_id]);
        $recent_prescriptions = $stmt_recent_prescriptions->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $patient_id = (int)($_POST['patient_id'] ?? 0);
    $form_type  = $_POST['form_type'] ?? '';

    // Verify the doctor has approved access to this patient (assigned by admin)
    $stmt_verify = $pdo->prepare("
        SELECT ar.id
        FROM access_requests ar
        JOIN doctors d ON d.name = ar.doctor_name
        WHERE d.id = :did
          AND ar.patient_id = :pid
          AND ar.request_status = 'approved'
          AND ar.records_sent = 1
        LIMIT 1
    ");
    $stmt_verify->execute(['did' => $doctor_id, 'pid' => $patient_id]);
    $allowed = $stmt_verify->fetch();

    if (!$allowed) {
        $error_msg = "Access Denied: You do not have approved access to this patient.";
    } elseif ($form_type === 'medical_record') {
        $visit_type    = trim($_POST['visit_type']    ?? '');
        $hospital_name = 'Central Medical Center';
        try {
            $stmt_facility = $pdo->prepare(
                "SELECT medical_facility
                 FROM access_requests ar
                 JOIN doctors d ON d.name = ar.doctor_name
                 WHERE d.id = :did
                   AND ar.patient_id = :pid
                   AND ar.request_status = 'approved'
                   AND ar.records_sent = 1
                   AND ar.medical_facility IS NOT NULL
                   AND TRIM(ar.medical_facility) <> ''
                 ORDER BY ar.updated_at DESC, ar.id DESC
                 LIMIT 1"
            );
            $stmt_facility->execute(['did' => $doctor_id, 'pid' => $patient_id]);
            $resolved_facility = trim((string) $stmt_facility->fetchColumn());
            if ($resolved_facility !== '') {
                $hospital_name = $resolved_facility;
            }
        } catch (PDOException $e) {
            $hospital_name = 'Central Medical Center';
        }
        $visit_date    = trim($_POST['visit_date']    ?? '');
        $notes         = trim($_POST['notes']         ?? '');
        $diagnosis     = trim($_POST['diagnosis']     ?? '');
        $treatment     = trim($_POST['treatment']     ?? '');

        if (empty($visit_type) || empty($visit_date)) {
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
                'treat'    => $treatment,
            ]);
            $success_msg = "Medical record added successfully.";
        }
    } elseif ($form_type === 'prescription') {
        $medication_name = trim($_POST['medication_name'] ?? '');
        $dosage          = trim($_POST['dosage']          ?? '');
        $frequency       = trim($_POST['frequency']       ?? '');
        $duration        = trim($_POST['duration']        ?? '');
        $notes           = trim($_POST['presc_notes']     ?? '');

        if (empty($medication_name) || empty($dosage) || empty($frequency)) {
            $error_msg = "Please fill in all required prescription fields.";
        } else {
            $stmt_presc = $pdo->prepare("
                INSERT INTO medication_prescriptions (patient_id, medication_name, dosage, frequency, duration, notes, prescribed_by)
                VALUES (:pid, :mname, :dosage, :freq, :dur, :notes, :doc)
            ");
            $stmt_presc->execute([
                'pid'   => $patient_id,
                'mname' => $medication_name,
                'dosage'=> $dosage,
                'freq'  => $frequency,
                'dur'   => $duration,
                'notes' => $notes,
                'doc'   => $doctor_name,
            ]);
            $success_msg = "Prescription added successfully.";
        }
    } elseif ($form_type === 'privacy_consent') {
        $allergies_summary_text = trim($_POST['allergies_summary_text'] ?? '');
        $chronic_diagnostic_logs_text = trim($_POST['chronic_diagnostic_logs_text'] ?? '');
        $surgical_typologies_summary_text = trim($_POST['surgical_typologies_summary_text'] ?? '');
        $surgical_typologies_necessary = 0;

        if ($allergies_summary_text === '' || $chronic_diagnostic_logs_text === '' || $surgical_typologies_summary_text === '') {
            $error_msg = "Please write all required privacy summaries before saving.";
        } else {
            $stmt_consent_upsert = $pdo->prepare("
                INSERT INTO patient_privacy_consents
                    (patient_id, allergies_summary_text, chronic_diagnostic_logs_text, surgical_typologies_summary_text, surgical_typologies_necessary, authored_by_doctor_id, authored_by_doctor_name)
                VALUES
                    (:patient_id, :allergies_summary_text, :chronic_diagnostic_logs_text, :surgical_typologies_summary_text, :surgical_typologies_necessary, :doctor_id, :doctor_name)
                ON DUPLICATE KEY UPDATE
                    allergies_summary_text = VALUES(allergies_summary_text),
                    chronic_diagnostic_logs_text = VALUES(chronic_diagnostic_logs_text),
                    surgical_typologies_summary_text = VALUES(surgical_typologies_summary_text),
                    surgical_typologies_necessary = VALUES(surgical_typologies_necessary),
                    authored_by_doctor_id = VALUES(authored_by_doctor_id),
                    authored_by_doctor_name = VALUES(authored_by_doctor_name)
            ");
            $stmt_consent_upsert->execute([
                'patient_id' => $patient_id,
                'allergies_summary_text' => $allergies_summary_text,
                'chronic_diagnostic_logs_text' => $chronic_diagnostic_logs_text,
                'surgical_typologies_summary_text' => $surgical_typologies_summary_text,
                'surgical_typologies_necessary' => $surgical_typologies_necessary,
                'doctor_id' => $doctor_id,
                'doctor_name' => $doctor_name,
            ]);

            if ($selected_id === $patient_id) {
                $privacy_consents = [
                    'allergies_summary_text' => '',
                    'chronic_diagnostic_logs_text' => '',
                    'surgical_typologies_summary_text' => '',
                    'surgical_typologies_necessary' => 0,
                    'authored_by_doctor_name' => $doctor_name,
                    'updated_at' => date('Y-m-d H:i:s'),
                ];
            }

            $success_msg = "Privacy consent summaries saved successfully. The form has been cleared.";
        }
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
        .empty-state { text-align: center; padding: 40px; color: #94a3b8; }
    </style>
</head>
<body>

<div class="sidebar">
    <div class="sidebar-brand">
        <div class="icon-box"><i class="fa-solid fa-stethoscope"></i></div>
        <div class="brand-label">
            <strong>Dr. <?php echo htmlspecialchars($doctor['name'] ?? $doctor_name); ?></strong>
            <small><?php echo htmlspecialchars($doctor_specialty); ?></small>
        </div>
    </div>

    <a href="dashboard.php" class="nav-link-custom">
        <i class="fa-solid fa-house"></i> Dashboard Overview
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

<div class="main-content">
    <div class="page-header">
        <h2>Update Records</h2>
        <p style="color:#64748b; font-size:0.85rem; margin-top:3px;">
            Add medical records or prescriptions for your assigned patients.
        </p>
    </div>

    <?php if ($success_msg): ?>
        <div class="alert-custom alert-success"><i class="fa-solid fa-circle-check" style="margin-right:6px;"></i><?php echo htmlspecialchars($success_msg); ?></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert-custom alert-error"><i class="fa-solid fa-circle-xmark" style="margin-right:6px;"></i><?php echo htmlspecialchars($error_msg); ?></div>
    <?php endif; ?>

    <!-- Patient Selector -->
    <div class="selector-card">
        <label style="font-size:0.82rem; font-weight:600; color:#475569; display:block; margin-bottom:8px;">
            <i class="fa-solid fa-user" style="color:#0e7490; margin-right:6px;"></i> Select a Patient
        </label>
        <?php if (!empty($approved_patients)): ?>
        <form method="GET">
            <select name="patient_id" class="patient-select" onchange="this.form.submit()">
                <option value="">— Select Patient —</option>
                <?php foreach ($approved_patients as $ap): ?>
                    <option value="<?php echo $ap['id']; ?>" <?php echo $selected_id == $ap['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($ap['name']); ?> (NID: <?php echo htmlspecialchars($ap['national_id']); ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
        <?php else: ?>
            <div class="empty-state" style="padding:20px 0;">
                <i class="fa-solid fa-user-slash" style="font-size:1.5rem; color:#e2e8f0; display:block; margin-bottom:8px;"></i>
                <p style="font-size:0.85rem;">No patients have been assigned to you yet.<br>
                <span style="font-size:0.8rem; color:#94a3b8;">The hospital admin will assign patients and send their medical records to you.</span></p>
            </div>
        <?php endif; ?>
    </div>

    <!-- Forms (disabled until patient selected) -->
    <div class="forms-grid <?php echo !$selected_patient ? 'disabled-overlay' : ''; ?>">
        <!-- Medical Record Form -->
        <div class="form-card">
            <h5 style="font-size:0.95rem; font-weight:600; color:#0f172a; margin-bottom:18px;">
                <i class="fa-solid fa-file-medical" style="color:#0e7490; margin-right:8px;"></i> Add Medical Record
            </h5>
            <form method="POST">
                <input type="hidden" name="form_type" value="medical_record">
                <input type="hidden" name="patient_id" value="<?php echo $selected_id; ?>">
                <div class="field">
                    <label>Visit Type <span style="color:#dc2626;">*</span></label>
                    <select name="visit_type">
                        <option value="Consultation">Consultation</option>
                        <option value="Follow-up">Follow-up</option>
                        <option value="Emergency">Emergency</option>
                        <option value="Routine Check-up">Routine Check-up</option>
                    </select>
                </div>
                <div class="field">
                    <label>Visit Date <span style="color:#dc2626;">*</span></label>
                    <input type="date" name="visit_date" value="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="field">
                    <label>Diagnosis</label>
                    <textarea name="diagnosis" rows="2" placeholder="Enter diagnosis..."></textarea>
                </div>
                <div class="field">
                    <label>Treatment</label>
                    <textarea name="treatment" rows="2" placeholder="Enter treatment plan..."></textarea>
                </div>
                <div class="field">
                    <label>Notes</label>
                    <textarea name="notes" rows="2" placeholder="Additional notes..."></textarea>
                </div>
                <button type="submit" class="btn-submit">
                    <i class="fa-solid fa-plus" style="margin-right:6px;"></i> Add Record
                </button>
            </form>
        </div>

        <!-- Prescription Form -->
        <div class="form-card">
            <h5 style="font-size:0.95rem; font-weight:600; color:#0f172a; margin-bottom:18px;">
                <i class="fa-solid fa-pills" style="color:#7c3aed; margin-right:8px;"></i> Add Prescription
            </h5>
            <form method="POST">
                <input type="hidden" name="form_type" value="prescription">
                <input type="hidden" name="patient_id" value="<?php echo $selected_id; ?>">
                <div class="field">
                    <label>Medication Name <span style="color:#dc2626;">*</span></label>
                    <input type="text" name="medication_name" placeholder="e.g. Amoxicillin">
                </div>
                <div class="field">
                    <label>Dosage <span style="color:#dc2626;">*</span></label>
                    <input type="text" name="dosage" placeholder="e.g. 500mg">
                </div>
                <div class="field">
                    <label>Frequency <span style="color:#dc2626;">*</span></label>
                    <input type="text" name="frequency" placeholder="e.g. Twice daily">
                </div>
                <div class="field">
                    <label>Duration</label>
                    <input type="text" name="duration" placeholder="e.g. 7 days">
                </div>
                <div class="field">
                    <label>Notes</label>
                    <textarea name="presc_notes" rows="2" placeholder="Additional instructions..."></textarea>
                </div>
                <button type="submit" class="btn-submit presc">
                    <i class="fa-solid fa-plus" style="margin-right:6px;"></i> Add Prescription
                </button>
            </form>
        </div>
    </div>

    <div class="form-card <?php echo !$selected_patient ? 'disabled-overlay' : ''; ?>" style="margin-bottom: 24px;">
        <h5 style="font-size:0.95rem; font-weight:600; color:#0f172a; margin-bottom:18px;">
            <i class="fa-solid fa-shield-halved" style="color:#0e7490; margin-right:8px;"></i> Privacy Consent Summaries
        </h5>
        <p style="font-size:0.82rem; color:#64748b; margin-bottom:14px;">
            Write clear summaries for patient privacy controls.
        </p>
        <form method="POST">
            <input type="hidden" name="form_type" value="privacy_consent">
            <input type="hidden" name="patient_id" value="<?php echo $selected_id; ?>">

            <div class="field">
                <label>Allergies Summary <span style="color:#dc2626;">*</span></label>
                <textarea name="allergies_summary_text" rows="3" placeholder="Write allergy-related privacy summary..." required></textarea>
            </div>

            <div class="field">
                <label>Chronic Diagnostic Logs <span style="color:#dc2626;">*</span></label>
                <textarea name="chronic_diagnostic_logs_text" rows="3" placeholder="Write chronic diagnostics privacy summary..." required></textarea>
            </div>

            <div class="field">
                <label>Surgical Typologies Summary <span style="color:#dc2626;">*</span></label>
                <textarea name="surgical_typologies_summary_text" rows="3" placeholder="Write surgical typologies privacy summary..." required></textarea>
            </div>

            <button type="submit" class="btn-submit">
                <i class="fa-solid fa-floppy-disk" style="margin-right:6px;"></i> Save Privacy Summaries
            </button>
        </form>
        <?php if (!empty($privacy_consents['authored_by_doctor_name'])): ?>
            <p style="font-size:0.76rem; color:#64748b; margin-top:12px; margin-bottom:0;">
                Last updated by Dr. <?php echo htmlspecialchars($privacy_consents['authored_by_doctor_name']); ?>
                <?php if (!empty($privacy_consents['updated_at'])): ?>on <?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($privacy_consents['updated_at']))); ?><?php endif; ?>.
            </p>
        <?php endif; ?>
    </div>

    <!-- Recent Records Preview (only when patient is selected) -->
    <?php if ($selected_patient): ?>
    <div class="preview-grid">
        <div class="preview-card">
            <h6 style="font-size:0.88rem; font-weight:600; color:#0f172a; margin-bottom:14px;">
                <i class="fa-solid fa-clock-rotate-left" style="color:#0e7490; margin-right:6px;"></i> Recent Medical Records
            </h6>
            <?php if (!empty($recent_records)): ?>
                <?php foreach ($recent_records as $rec): ?>
                <div class="record-item">
                    <strong><?php echo htmlspecialchars($rec['visit_type']); ?></strong><br>
                    <small style="color:#64748b;"><?php echo htmlspecialchars($rec['visit_date']); ?></small>
                    <?php if (!empty($rec['diagnosis'])): ?>
                    <p style="margin-top:4px; color:#475569;">Diag: <?php echo htmlspecialchars($rec['diagnosis']); ?></p>
                    <?php endif; ?>
                    <?php if (!empty($rec['treatment'])): ?>
                    <p style="color:#475569;">Treat: <?php echo htmlspecialchars($rec['treatment']); ?></p>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p style="font-size:0.82rem; color:#94a3b8; text-align:center; padding:20px 0;">No medical records found.</p>
            <?php endif; ?>
        </div>

        <div class="preview-card">
            <h6 style="font-size:0.88rem; font-weight:600; color:#0f172a; margin-bottom:14px;">
                <i class="fa-solid fa-pills" style="color:#7c3aed; margin-right:6px;"></i> Recent Prescriptions
            </h6>
            <?php if (!empty($recent_prescriptions)): ?>
                <?php foreach ($recent_prescriptions as $presc): ?>
                <div class="record-item">
                    <strong><?php echo htmlspecialchars($presc['medication_name']); ?></strong><br>
                    <small style="color:#64748b;"><?php echo htmlspecialchars($presc['dosage']); ?> &bull; <?php echo htmlspecialchars($presc['frequency']); ?></small>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p style="font-size:0.82rem; color:#94a3b8; text-align:center; padding:20px 0;">No prescriptions found.</p>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

</body>
</html>