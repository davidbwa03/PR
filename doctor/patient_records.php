<?php
session_start();
if (!isset($_SESSION['doctor_id'])) {
    header("Location: login.php");
    exit();
}
require_once 'db.php';

$doctor_id   = $_SESSION['doctor_id'];
$doctor_name = $_SESSION['doctor_name'];

// Fetch doctor's specialty to keep sidebar consistent with dashboard
$doctor_specialty = 'Doctor';
$specialtyStmt = $pdo->prepare("SELECT specialty FROM doctors WHERE id = :doctor_id LIMIT 1");
$specialtyStmt->execute(['doctor_id' => $doctor_id]);
$specialty = $specialtyStmt->fetchColumn();
if (!empty($specialty)) {
    $doctor_specialty = $specialty;
}

$pdo->exec("CREATE TABLE IF NOT EXISTS patient_allergies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    allergen_name VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_patient_allergen (patient_id, allergen_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Validate patient_id from URL
$patient_id = isset($_GET['patient_id']) ? (int)$_GET['patient_id'] : 0;
if (!$patient_id) {
    header("Location: my_patients.php");
    exit();
}

// Security: confirm this doctor has an APPROVED request for this patient and records are sent
$access_stmt = $pdo->prepare("
    SELECT id, doctor_name, medical_facility, request_status, records_sent, requested_at, updated_at
    FROM access_requests
    WHERE doctor_name = ? AND patient_id = ? AND request_status = 'approved' AND records_sent = 1
    LIMIT 1
");
$access_stmt->execute([$doctor_name, $patient_id]);
$access_consent = $access_stmt->fetch(PDO::FETCH_ASSOC);
if (!$access_consent) {
    header("Location: my_patients.php");
    exit();
}

$accessTimestamp = !empty($access_consent['updated_at'])
    ? strtotime((string)$access_consent['updated_at'])
    : (!empty($access_consent['requested_at']) ? strtotime((string)$access_consent['requested_at']) : false);
$detailsExpired = $accessTimestamp !== false && (time() - $accessTimestamp) > (48 * 60 * 60);

// Fetch patient info - Adjusted column names to match schema (assuming 'dob' exists)
$p_stmt = $pdo->prepare("SELECT id, name, email, national_id, dob AS date_of_birth, gender, phone FROM patients WHERE id = ? LIMIT 1");
$p_stmt->execute([$patient_id]);
$patient = $p_stmt->fetch(PDO::FETCH_ASSOC);
if (!$patient) {
    header("Location: my_patients.php");
    exit();
}

// Fetch medical records - Mapped database columns to expected names
$rec_stmt = $pdo->prepare("
    SELECT id,
           visit_date AS record_date,
              visit_type,
              COALESCE(diagnosis, clinical_notes) AS diagnosis,
              treatment,
           notes,
              COALESCE(created_by, physician_name) AS doctor_name,
           created_at
    FROM medical_records
    WHERE patient_id = ?
    ORDER BY visit_date DESC, created_at DESC
");
$rec_stmt->execute([$patient_id]);
$records = $rec_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch prescriptions directly from medication_prescriptions
$presc_stmt = $pdo->prepare("
    SELECT medication_name,
           dosage,
           frequency,
           duration,
           notes,
           prescribed_by,
           created_at
    FROM medication_prescriptions
    WHERE patient_id = ?
    ORDER BY id DESC
");
$presc_stmt->execute([$patient_id]);
$prescriptions = $presc_stmt->fetchAll(PDO::FETCH_ASSOC);

$allergy_stmt = $pdo->prepare("SELECT allergen_name FROM patient_allergies WHERE patient_id = ? ORDER BY created_at DESC, id DESC");
$allergy_stmt->execute([$patient_id]);
$allergies = $allergy_stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Records | Practitioner Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            background-color: #f8fafc;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: #1e293b;
        }

        /* G��G�� Sidebar G��G�� */
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

        .sidebar-brand {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 36px;
        }

        .icon-box {
            background: #0e7490;
            color: #ffffff;
            width: 38px; height: 38px;
            border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1rem;
            flex-shrink: 0;
        }

        .brand-label strong { font-size: 0.9rem; display: block; color: #0f172a; }
        .brand-label small  { font-size: 0.68rem; color: #94a3b8; }

        .nav-link-custom {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 11px 14px;
            margin-bottom: 6px;
            border-radius: 8px;
            color: #475569;
            text-decoration: none;
            font-size: 0.88rem;
            font-weight: 500;
            transition: background 0.15s, color 0.15s;
        }
        .nav-link-custom:hover  { background: #f1f5f9; color: #0e7490; }
        .nav-link-custom.active { background: #0e7490; color: #ffffff; }
        .nav-link-custom i { width: 18px; text-align: center; }

        .sign-out {
            margin-top: auto;
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 11px 14px;
            color: #64748b;
            text-decoration: none;
            font-size: 0.88rem;
            border-radius: 8px;
            transition: background 0.15s;
        }
        .sign-out:hover { background: #fee2e2; color: #dc2626; }

        /* G��G�� Main G��G�� */
        .main-content {
            margin-left: 220px;
            padding: 40px 44px;
            min-height: 100vh;
        }

        /* G��G�� Back link G��G�� */
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            color: #64748b;
            font-size: 0.83rem;
            text-decoration: none;
            margin-bottom: 22px;
            transition: color 0.15s;
        }
        .back-link:hover { color: #0e7490; }

        /* G��G�� Patient profile card G��G�� */
        .profile-card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 24px 28px;
            display: flex;
            align-items: center;
            gap: 22px;
            margin-bottom: 28px;
        }

        .profile-avatar {
            width: 58px; height: 58px;
            background: #e0f2fe;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.5rem;
            color: #0e7490;
            flex-shrink: 0;
        }

        .profile-info h3 {
            font-size: 1.1rem;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 4px;
        }

        .profile-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            margin-top: 6px;
        }

        .profile-meta span {
            font-size: 0.8rem;
            color: #64748b;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .profile-meta span i { color: #94a3b8; }

        /* G��G�� Privacy consent card G��G�� */
        .consent-card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-left: 4px solid #0e7490;
            border-radius: 12px;
            padding: 18px 20px;
            margin-bottom: 20px;
        }

        .consent-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 10px;
            flex-wrap: wrap;
        }

        .consent-title {
            font-size: 0.95rem;
            font-weight: 700;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .consent-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.74rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            border-radius: 999px;
            padding: 4px 10px;
        }

        .consent-pill.approved {
            color: #166534;
            background: #dcfce7;
            border: 1px solid #86efac;
        }

        .consent-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px 18px;
        }

        .consent-grid span {
            font-size: 0.82rem;
            color: #475569;
        }

        .consent-grid strong {
            color: #0f172a;
        }

        /* G��G�� Records section G��G�� */
        .section-title {
            font-size: 1rem;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* G��G�� Record card G��G�� */
        .record-card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            margin-bottom: 16px;
            overflow: hidden;
            transition: box-shadow 0.15s;
        }
        .record-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,0.07); }

        .record-header {
            padding: 14px 20px;
            background: #f8fafc;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 8px;
        }

        .record-date {
            font-size: 0.82rem;
            font-weight: 700;
            color: #0e7490;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .record-doctor {
            font-size: 0.78rem;
            color: #94a3b8;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .record-body {
            padding: 18px 20px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .record-field label {
            font-size: 0.7rem;
            font-weight: 700;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            display: block;
            margin-bottom: 4px;
        }

        .record-field p {
            font-size: 0.875rem;
            color: #1e293b;
            line-height: 1.55;
            margin: 0;
        }

        .record-field.full { grid-column: 1 / -1; }

        .record-field p.empty { color: #cbd5e1; font-style: italic; }

        /* G��G�� Empty state G��G�� */
        .empty-state {
            text-align: center;
            padding: 64px 20px;
            color: #94a3b8;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
        }
        .empty-state i { font-size: 2.4rem; display: block; margin-bottom: 14px; color: #e2e8f0; }
        .empty-state p { font-size: 0.88rem; }

        @media (max-width: 768px) {
            .sidebar { display: none; }
            .main-content { margin-left: 0; padding: 24px 18px; }
            .record-body { grid-template-columns: 1fr; }
            .profile-card { flex-direction: column; align-items: flex-start; }
        }
    </style>
</head>
<body>

<div class="sidebar">
    <div class="sidebar-brand">
        <div class="icon-box"><i class="fa-solid fa-stethoscope"></i></div>
        <div class="brand-label">
            <strong>Dr. <?php echo htmlspecialchars($doctor_name); ?></strong>
            <small><?php echo htmlspecialchars($doctor_specialty); ?></small>
        </div>
    </div>

    <a href="dashboard.php" class="nav-link-custom">
        <i class="fa-solid fa-house"></i> Dashboard overview
    </a>
    <a href="my_patients.php" class="nav-link-custom active">
        <i class="fa-solid fa-users"></i> My Patients
    </a>
    <a href="update_records.php" class="nav-link-custom">
        <i class="fa-solid fa-pen-to-square"></i> Update Records
    </a>

    <a href="logout.php" class="sign-out">
        <i class="fa-solid fa-arrow-right-from-bracket"></i> Sign Out
    </a>
</div>

<div class="main-content">

    <a href="my_patients.php" class="back-link">
        <i class="fa-solid fa-arrow-left"></i> Back to My Patients
    </a>

    <div class="profile-card">
        <div class="profile-avatar">
            <i class="fa-solid fa-user"></i>
        </div>
        <div class="profile-info">
            <h3><?php echo htmlspecialchars($patient['name']); ?></h3>
            <div class="profile-meta">
                <?php if ($detailsExpired): ?>
                    <span><i class="fa-solid fa-lock"></i> Patient details have expired after 48hrs.</span>
                <?php else: ?>
                    <span><i class="fa-solid fa-id-card"></i> NID: <?php echo htmlspecialchars($patient['national_id']); ?></span>
                    <span><i class="fa-solid fa-envelope"></i> <?php echo htmlspecialchars($patient['email']); ?></span>
                    <?php if (!empty($patient['date_of_birth'])): ?>
                        <span><i class="fa-solid fa-cake-candles"></i> <?php echo date('M d, Y', strtotime($patient['date_of_birth'])); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($patient['gender'])): ?>
                        <span><i class="fa-solid fa-venus-mars"></i> <?php echo htmlspecialchars(ucfirst($patient['gender'])); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($patient['phone'])): ?>
                        <span><i class="fa-solid fa-phone"></i> <?php echo htmlspecialchars($patient['phone']); ?></span>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="consent-card">
        <div class="consent-head">
            <div class="consent-title">
                <i class="fa-solid fa-shield-heart" style="color:#0e7490;"></i>
                Privacy Consent Controls
            </div>
            <span class="consent-pill approved">
                <i class="fa-solid fa-circle-check"></i>
                <?php echo htmlspecialchars(ucfirst((string)$access_consent['request_status'])); ?>
            </span>
        </div>
        <?php if ($detailsExpired): ?>
            <div class="consent-grid">
                <span><strong>Patient details have expired after 48hrs.</strong></span>
            </div>
        <?php else: ?>
            <div class="consent-grid">
                <span>Patient Consent Scope: <strong>Medical records shared</strong></span>
                <span>Records Delivery: <strong><?php echo ((int)$access_consent['records_sent'] === 1) ? 'Completed' : 'Pending'; ?></strong></span>
                <span>Approved For Doctor: <strong>Dr. <?php echo htmlspecialchars($access_consent['doctor_name'] ?: $doctor_name); ?></strong></span>
                <span>Facility: <strong><?php echo htmlspecialchars($access_consent['medical_facility'] ?: 'Not specified'); ?></strong></span>
                <span>Request Date: <strong><?php echo !empty($access_consent['requested_at']) ? htmlspecialchars(date('Y-m-d H:i', strtotime($access_consent['requested_at']))) : 'N/A'; ?></strong></span>
                <span>Last Consent Update: <strong><?php echo !empty($access_consent['updated_at']) ? htmlspecialchars(date('Y-m-d H:i', strtotime($access_consent['updated_at']))) : 'N/A'; ?></strong></span>
            </div>
        <?php endif; ?>
    </div>

    <div class="consent-card">
        <div class="consent-head">
            <div class="consent-title">
                <i class="fa-solid fa-triangle-exclamation" style="color:#dc2626;"></i>
                Patient Allergies
            </div>
            <span class="consent-pill approved" style="background:#fee2e2; color:#b91c1c; border-color:#fecaca;">
                <?php if ($detailsExpired): ?>
                    Hidden
                <?php else: ?>
                    <?php echo count($allergies); ?> allergen<?php echo count($allergies) !== 1 ? 's' : ''; ?>
                <?php endif; ?>
            </span>
        </div>
        <?php if ($detailsExpired): ?>
            <span style="font-size:0.82rem; color:#64748b;">Patient details have expired after 48hrs.</span>
        <?php elseif (!empty($allergies)): ?>
            <div class="consent-grid" style="grid-template-columns: repeat(3, minmax(0, 1fr));">
                <?php foreach ($allergies as $allergy): ?>
                    <span><strong><?php echo htmlspecialchars($allergy['allergen_name']); ?></strong></span>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <span style="font-size:0.82rem; color:#64748b;">No allergies have been added by this patient yet.</span>
        <?php endif; ?>
    </div>

    <div class="section-title">
        <i class="fa-solid fa-file-medical" style="color:#0e7490;"></i>
        Medical Records
        <span style="font-size:0.78rem; font-weight:500; color:#94a3b8; margin-left:4px;">(<?php echo count($records); ?> entr<?php echo count($records) !== 1 ? 'ies' : 'y'; ?>)</span>
    </div>

    <?php if ($detailsExpired): ?>
        <div class="empty-state">
            <i class="fa-solid fa-lock"></i>
            <p>Patient details have expired after 48hrs. Only patient name is visible.</p>
        </div>
    <?php elseif (!empty($records)): ?>
        <?php foreach ($records as $rec): ?>
        <div class="record-card">
            <div class="record-header">
                <span class="record-date">
                    <i class="fa-solid fa-calendar-day"></i>
                    <?php echo date('F d, Y', strtotime($rec['record_date'])); ?>
                </span>
                <span class="record-doctor">
                    <i class="fa-solid fa-user-doctor"></i>
                    Dr. <?php echo htmlspecialchars($rec['doctor_name']); ?>
                </span>
            </div>
            <div class="record-body">
                <div class="record-field">
                    <label><i class="fa-solid fa-heart-pulse"></i> Visit Type</label>
                    <p><?php echo $rec['visit_type'] ? htmlspecialchars($rec['visit_type']) : '<span class="empty">Not recorded</span>'; ?></p>
                </div>
                <div class="record-field">
                    <label><i class="fa-solid fa-stethoscope"></i> Diagnosis</label>
                    <p><?php echo $rec['diagnosis'] ? htmlspecialchars($rec['diagnosis']) : '<span class="empty">Not recorded</span>'; ?></p>
                </div>
                <div class="record-field">
                    <label><i class="fa-solid fa-syringe"></i> Treatment</label>
                    <p><?php echo $rec['treatment'] ? htmlspecialchars($rec['treatment']) : '<span class="empty">Not recorded</span>'; ?></p>
                </div>
                <?php if (!empty($rec['notes'])): ?>
                <div class="record-field full">
                    <label><i class="fa-solid fa-note-sticky"></i> Notes</label>
                    <p><?php echo htmlspecialchars($rec['notes']); ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>

    <?php else: ?>
        <div class="empty-state">
            <i class="fa-solid fa-folder-open"></i>
            <p>No medical records found for this patient.</p>
        </div>
    <?php endif; ?>

    <div class="section-title" style="margin-top: 28px;">
        <i class="fa-solid fa-pills" style="color:#0e7490;"></i>
        Prescription History
        <span style="font-size:0.78rem; font-weight:500; color:#94a3b8; margin-left:4px;">(<?php echo count($prescriptions); ?> entr<?php echo count($prescriptions) !== 1 ? 'ies' : 'y'; ?>)</span>
    </div>

    <?php if ($detailsExpired): ?>
        <div class="empty-state" style="padding: 30px 20px;">
            <i class="fa-solid fa-lock"></i>
            <p>Prescription details are hidden because access expired after 48hrs.</p>
        </div>
    <?php elseif (!empty($prescriptions)): ?>
        <?php foreach ($prescriptions as $presc): ?>
        <div class="record-card">
            <div class="record-header">
                <span class="record-date">
                    <i class="fa-solid fa-capsules"></i>
                    <?php echo htmlspecialchars($presc['medication_name']); ?>
                </span>
                <span class="record-doctor">
                    <i class="fa-solid fa-user-doctor"></i>
                    Dr. <?php echo htmlspecialchars($presc['prescribed_by'] ?: 'N/A'); ?>
                </span>
            </div>
            <div class="record-body">
                <div class="record-field">
                    <label><i class="fa-solid fa-prescription-bottle-medical"></i> Dosage</label>
                    <p><?php echo $presc['dosage'] ? htmlspecialchars($presc['dosage']) : '<span class="empty">Not recorded</span>'; ?></p>
                </div>
                <div class="record-field">
                    <label><i class="fa-solid fa-clock"></i> Frequency</label>
                    <p><?php echo $presc['frequency'] ? htmlspecialchars($presc['frequency']) : '<span class="empty">Not recorded</span>'; ?></p>
                </div>
                <div class="record-field">
                    <label><i class="fa-solid fa-hourglass-half"></i> Duration</label>
                    <p><?php echo $presc['duration'] ? htmlspecialchars($presc['duration']) : '<span class="empty">Not recorded</span>'; ?></p>
                </div>
                <?php if (!empty($presc['notes'])): ?>
                <div class="record-field full">
                    <label><i class="fa-solid fa-note-sticky"></i> Notes</label>
                    <p><?php echo htmlspecialchars($presc['notes']); ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="empty-state" style="padding: 30px 20px;">
            <i class="fa-solid fa-capsules"></i>
            <p>No prescriptions found for this patient.</p>
        </div>
    <?php endif; ?>

</div>

</body>
</html>
