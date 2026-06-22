<?php
session_start();
if (!isset($_SESSION['doctor_id'])) {
    header("Location: login.php");
    exit();
}
require_once 'db.php';

$doctor_id = $_SESSION['doctor_id'];

// Fetch doctor info
$stmt = $pdo->prepare("SELECT * FROM doctors WHERE id = :id");
$stmt->execute(['id' => $doctor_id]);
$doctor = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$doctor) {
    session_destroy();
    header("Location: login.php");
    exit();
}

$success_msg = '';
$error_msg = '';

// Handle "Request Data" form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['national_id'])) {
    $national_id = trim($_POST['national_id']);

    if (!empty($national_id)) {
        // Find patient by national_id
        $stmt_pat = $pdo->prepare("SELECT id, name FROM patients WHERE national_id = :nid");
        $stmt_pat->execute(['nid' => $national_id]);
        $patient = $stmt_pat->fetch(PDO::FETCH_ASSOC);

        if ($patient) {
            // Check if a pending/approved request already exists (using doctor_name)
            $stmt_check = $pdo->prepare("SELECT id FROM access_requests 
                                         WHERE doctor_name = :dname AND patient_id = :pid 
                                         AND request_status IN ('pending', 'approved')");
            $stmt_check->execute(['dname' => $doctor['name'], 'pid' => $patient['id']]);
            $existing = $stmt_check->fetch();

            if ($existing) {
                $error_msg = "A pending or approved request already exists for this patient.";
            } else {
                // Insert new access request
                $stmt_ins = $pdo->prepare("INSERT INTO access_requests 
                    (patient_id, doctor_name, medical_facility, request_status, requested_at)
                    VALUES (:pid, :dname, :facility, 'pending', NOW())");
                $stmt_ins->execute([
                    'pid'      => $patient['id'],
                    'dname'    => $doctor['name'],
                    'facility' => $doctor['address'] ?? 'N/A',
                ]);
                $success_msg = "Access request sent to patient <strong>" . htmlspecialchars($patient['name']) . "</strong>. Awaiting approval.";
            }
        } else {
            $error_msg = "No patient found with National ID: <strong>" . htmlspecialchars($national_id) . "</strong>.";
        }
    } else {
        $error_msg = "Please enter a National ID.";
    }
}

// Fetch recent patients this doctor has requested (with statuses)
$stmt_recent = $pdo->prepare("
    SELECT ar.id AS request_id, ar.request_status, ar.requested_at,
           p.name AS patient_name, p.national_id, p.id AS patient_id
    FROM access_requests ar
    JOIN patients p ON p.id = ar.patient_id
    WHERE ar.doctor_name = :dname
    ORDER BY ar.requested_at DESC
    LIMIT 10
");
$stmt_recent->execute(['dname' => $doctor['name']]);
$recent_patients = $stmt_recent->fetchAll(PDO::FETCH_ASSOC);
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
        .nav-link-custom:hover { background: #f1f5f9; color: #0e7490; }
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

        /* ── Main Content ── */
        .main-content {
            margin-left: 220px;
            padding: 40px 44px;
            min-height: 100vh;
        }

        .page-header { margin-bottom: 32px; }
        .page-header h2 { font-size: 1.55rem; font-weight: 700; color: #0f172a; }
        .page-header p  { color: #64748b; font-size: 0.85rem; margin-top: 2px; }

        /* ── Request Card ── */
        .card-custom {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 28px 30px;
            margin-bottom: 28px;
        }
        .card-custom h5 {
            font-size: 1rem;
            font-weight: 600;
            color: #0f172a;
            margin-bottom: 6px;
        }
        .card-custom p.subtitle {
            font-size: 0.82rem;
            color: #94a3b8;
            margin-bottom: 20px;
        }

        .request-row {
            display: flex;
            gap: 12px;
            align-items: center;
        }
        .request-row input[type="text"] {
            flex: 1;
            padding: 10px 16px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 0.88rem;
            color: #334155;
            background: #f8fafc;
            outline: none;
            transition: border 0.15s;
        }
        .request-row input[type="text"]:focus { border-color: #0e7490; background: #fff; }
        .request-row input[type="text"]::placeholder { color: #b0bec5; }

        .btn-request {
            background: #0e7490;
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 10px 22px;
            font-size: 0.88rem;
            font-weight: 600;
            cursor: pointer;
            white-space: nowrap;
            transition: background 0.15s;
        }
        .btn-request:hover { background: #0b6070; }

        /* ── Alerts ── */
        .alert-custom {
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 0.85rem;
            margin-bottom: 18px;
        }
        .alert-success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; }
        .alert-error   { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }

        /* ── Recent Patients ── */
        .section-title {
            font-size: 1rem;
            font-weight: 600;
            color: #0f172a;
            margin-bottom: 16px;
        }

        .patient-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 15px 0;
            border-bottom: 1px solid #f1f5f9;
        }
        .patient-row:last-child { border-bottom: none; }

        .patient-info strong { font-size: 0.92rem; color: #0f172a; display: block; }
        .patient-info small  { font-size: 0.78rem; color: #94a3b8; }

        .patient-meta {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .last-seen { font-size: 0.78rem; color: #94a3b8; white-space: nowrap; }

        /* Status badges */
        .badge-status {
            font-size: 0.72rem;
            font-weight: 600;
            padding: 4px 12px;
            border-radius: 20px;
            text-transform: capitalize;
            white-space: nowrap;
        }
        .badge-pending  { background: #fef9c3; color: #854d0e; }
        .badge-approved { background: #dcfce7; color: #166534; }
        .badge-declined { background: #fee2e2; color: #991b1b; }

        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #94a3b8;
            font-size: 0.88rem;
        }
        .empty-state i { font-size: 2rem; margin-bottom: 10px; display: block; color: #cbd5e1; }

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

    <a href="dashboard.php" class="nav-link-custom active">
        <i class="fa-solid fa-magnifying-glass"></i> Request Patient Data
    </a>
    <a href="my_patients.php" class="nav-link-custom">
        <i class="fa-solid fa-users"></i> My Patients
    </a>
    <a href="update_records.php" class="nav-link-custom">
        <i class="fa-solid fa-pen-to-square"></i> Update Records
    </a>

    <a href="logout.php" class="sign-out">
        <i class="fa-solid fa-arrow-right-from-bracket"></i> Sign Out
    </a>
</div>

<!-- ══ Main ══ -->
<div class="main-content">

    <!-- Header -->
    <div class="page-header">
        <h2>Dr. <?php echo htmlspecialchars($doctor['name']); ?></h2>
        <p>Physician ID: MD-<?php echo str_pad($doctor['id'], 4, '0', STR_PAD_LEFT) . '-' . date('Y', strtotime($doctor['created_at'])); ?></p>
    </div>

    <!-- Alerts -->
    <?php if ($success_msg): ?>
        <div class="alert-custom alert-success"><i class="fa-solid fa-circle-check me-2"></i><?php echo $success_msg; ?></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert-custom alert-error"><i class="fa-solid fa-circle-exclamation me-2"></i><?php echo $error_msg; ?></div>
    <?php endif; ?>

    <!-- Request Patient Summary Card -->
    <div class="card-custom">
        <h5><i class="fa-solid fa-magnifying-glass me-2" style="color:#0e7490;"></i> Request Patient Summary</h5>
        <p class="subtitle">Request patient data from the system using their National ID</p>

        <form method="POST" action="dashboard.php">
            <div class="request-row">
                <input
                    type="text"
                    name="national_id"
                    placeholder="Enter Patient National ID (e.g., 12345678)"
                    value="<?php echo isset($_POST['national_id']) ? htmlspecialchars($_POST['national_id']) : ''; ?>"
                    autocomplete="off"
                >
                <button type="submit" class="btn-request">
                    <i class="fa-solid fa-paper-plane me-1"></i> Request Data
                </button>
            </div>
        </form>
    </div>

    <!-- Recent Patients -->
    <div class="card-custom">
        <div class="section-title">Recent Patients</div>

        <?php if (!empty($recent_patients)): ?>
            <?php foreach ($recent_patients as $rp):
                $status    = strtolower($rp['request_status']);
                $badge_cls = match($status) {
                    'approved' => 'badge-approved',
                    'declined' => 'badge-declined',
                    default    => 'badge-pending',
                };
                $label = match($status) {
                    'approved' => 'Approved',
                    'declined' => 'Declined',
                    default    => 'Pending',
                };
                $date_fmt = date('Y-m-d', strtotime($rp['requested_at']));
            ?>
            <div class="patient-row">
                <div class="patient-info">
                    <strong><?php echo htmlspecialchars($rp['patient_name']); ?></strong>
                    <small>NID: <?php echo htmlspecialchars($rp['national_id']); ?></small>
                </div>
                <div class="patient-meta">
                    <span class="last-seen">Requested: <?php echo $date_fmt; ?></span>
                    <span class="badge-status <?php echo $badge_cls; ?>"><?php echo $label; ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="empty-state">
                <i class="fa-solid fa-user-slash"></i>
                No patient requests yet. Enter a National ID above to get started.
            </div>
        <?php endif; ?>
    </div>

</div><!-- /main-content -->

</body>
</html>