<?php
session_start();
if (!isset($_SESSION['doctor_id'])) {
    header("Location: login.php");
    exit();
}
require_once 'db.php';

$doctor_id   = $_SESSION['doctor_id'];
$doctor_name = $_SESSION['doctor_name'];

// Fetch doctor info
$stmt = $pdo->prepare("SELECT * FROM doctors WHERE id = :id");
$stmt->execute(['id' => $doctor_id]);
$doctor = $stmt->fetch(PDO::FETCH_ASSOC);
$doctor_specialty = $doctor['specialty'] ?? 'General Practitioner';

$pdo->exec("CREATE TABLE IF NOT EXISTS patient_allergies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    allergen_name VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_patient_allergen (patient_id, allergen_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Fetch all patients assigned to this doctor (approved by admin)
$stmtPatients = $pdo->prepare("
    SELECT DISTINCT
           p.id          AS patient_id,
           p.name        AS patient_name,
           p.national_id,
           p.email       AS patient_email,
           ar.requested_at AS assigned_at,
          COALESCE(ar.updated_at, ar.requested_at) AS access_granted_at,
        (SELECT COUNT(*) FROM medical_records WHERE patient_id = p.id) AS record_count,
        (SELECT COUNT(*) FROM patient_allergies WHERE patient_id = p.id) AS allergy_count
    FROM access_requests ar
    JOIN patients p ON p.id = ar.patient_id
    WHERE ar.doctor_name = :dname
      AND ar.request_status = 'approved'
      AND ar.records_sent = 1
    ORDER BY ar.requested_at DESC
");
$stmtPatients->execute(['dname' => $doctor_name]);
$patients = $stmtPatients->fetchAll(PDO::FETCH_ASSOC);

$total_patients = count($patients);

foreach ($patients as &$row) {
    $accessAt = !empty($row['access_granted_at']) ? strtotime((string)$row['access_granted_at']) : false;
    $row['details_expired'] = $accessAt !== false && (time() - $accessAt) > (48 * 60 * 60);
}
unset($row);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Practitioner Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { background-color: #f8fafc; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; color: #1e293b; }
        
        /* ── Sidebar (Maintained) ── */
        .sidebar { width: 220px; height: 100vh; position: fixed; top: 0; left: 0; background: #ffffff; border-right: 1px solid #e2e8f0; padding: 28px 18px; display: flex; flex-direction: column; z-index: 100; }
        .sidebar-brand { display: flex; align-items: center; gap: 10px; margin-bottom: 36px; }
        .icon-box { background: #0e7490; color: #ffffff; width: 38px; height: 38px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 1rem; }
        .brand-label strong { font-size: 0.9rem; display: block; color: #0f172a; }
        .brand-label small  { font-size: 0.68rem; color: #94a3b8; }
        .nav-link-custom { display: flex; align-items: center; gap: 10px; padding: 11px 14px; margin-bottom: 6px; border-radius: 8px; color: #475569; text-decoration: none; font-size: 0.88rem; font-weight: 500; transition: background 0.15s; }
        .nav-link-custom:hover { background: #f1f5f9; color: #0e7490; }
        .nav-link-custom.active { background: #0e7490; color: #ffffff; }
        .sign-out { margin-top: auto; display: flex; align-items: center; gap: 10px; padding: 11px 14px; color: #64748b; text-decoration: none; font-size: 0.88rem; }

        /* ── Content ── */
        .main-content { margin-left: 220px; padding: 40px 44px; }
        .card-custom { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 28px 30px; margin-bottom: 28px; }
        .section-title { font-size: 1.1rem; font-weight: 600; color: #0f172a; margin-bottom: 20px; }
        
        .appointment-row { display: flex; align-items: center; justify-content: space-between; padding: 18px 0; border-bottom: 1px solid #f1f5f9; }
        .time-slot { font-weight: 600; color: #0e7490; width: 90px; }
        .patient-info strong { display: block; color: #0f172a; }
        .patient-info small { color: #64748b; font-size: 0.8rem; }
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
    <div class="page-header" style="margin-bottom: 28px;">
        <h2 style="font-size: 1.45rem; font-weight: 700; color: #0f172a;">My Patients</h2>
        <p style="color: #64748b; font-size: 0.85rem; margin-top: 3px;">
            Patients assigned to you by the hospital admin &mdash;
            <strong style="color: #0e7490;"><?php echo $total_patients; ?></strong> patient<?php echo $total_patients !== 1 ? 's' : ''; ?> total
        </p>
    </div>

    <div class="card-custom">
        <div class="section-title">
            <i class="fa-solid fa-users" style="color:#0e7490; margin-right:8px;"></i> Assigned Patients
        </div>

        <?php if (!empty($patients)): ?>
            <?php foreach ($patients as $row): ?>
            <div class="appointment-row">
                <div class="time-slot"><?php echo date('d M', strtotime($row['assigned_at'])); ?></div>
                <div class="patient-info" style="flex:1; padding: 0 20px;">
                    <strong><?php echo htmlspecialchars($row['patient_name']); ?></strong>
                    <?php if (!empty($row['details_expired'])): ?>
                        <small>Patient details have expired after 48hrs.</small>
                    <?php else: ?>
                        <small>NID: <?php echo htmlspecialchars($row['national_id']); ?> &bull; <?php echo htmlspecialchars($row['patient_email']); ?></small>
                    <?php endif; ?>
                </div>
                <div style="display:flex; align-items:center; gap:14px;">
                    <span style="font-size:0.78rem; color:#16a34a; background:#dcfce7; padding:4px 10px; border-radius:20px; font-weight:600;">
                        <i class="fa-solid fa-file-medical"></i>
                        <?php echo $row['record_count']; ?> record<?php echo $row['record_count'] != 1 ? 's' : ''; ?>
                    </span>
                    <span style="font-size:0.78rem; color:#b91c1c; background:#fee2e2; padding:4px 10px; border-radius:20px; font-weight:600;">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                        <?php echo (int)$row['allergy_count']; ?> allergen<?php echo ((int)$row['allergy_count']) !== 1 ? 's' : ''; ?>
                    </span>
                    <a href="patient_records.php?patient_id=<?php echo $row['patient_id']; ?>"
                       style="display:inline-flex; align-items:center; gap:6px; padding:6px 14px; background:#0e7490; color:#fff; border-radius:8px; font-size:0.78rem; font-weight:600; text-decoration:none;">
                        <i class="fa-solid fa-file-medical"></i> View Records
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="empty-state">
                <i class="fa-solid fa-user-slash fa-2x mb-3"></i>
                <p>No patients have been assigned to you yet.</p>
                <small style="font-size:0.82rem;">The hospital admin will assign patients and send their medical records to you.</small>
            </div>
        <?php endif; ?>
    </div>
</div>

</body>
</html>