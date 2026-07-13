<?php
session_start();
if (!isset($_SESSION['doctor_id'])) {
    header("Location: login.php");
    exit();
}
require_once 'db.php';

$doctor_id   = $_SESSION['doctor_id'];
$doctor_name = $_SESSION['doctor_name'];

// Fetch doctor's specialty
$doctor_specialty = 'Doctor';
$specialtyStmt = $pdo->prepare("SELECT specialty FROM doctors WHERE id = :doctor_id LIMIT 1");
$specialtyStmt->execute(['doctor_id' => $doctor_id]);
$specialty = $specialtyStmt->fetchColumn();
if (!empty($specialty)) {
    $doctor_specialty = $specialty;
}

// Total patients assigned to this doctor (approved access)
$stmtTotal = $pdo->prepare("
    SELECT COUNT(DISTINCT ar.patient_id)
    FROM access_requests ar
        WHERE ar.doctor_name = :dname
            AND ar.request_status = 'approved'
            AND ar.records_sent = 1
");
$stmtTotal->execute(['dname' => $doctor_name]);
$total_patients = (int) $stmtTotal->fetchColumn();

// Total medical records sent to this doctor
$stmtRecords = $pdo->prepare("
    SELECT COUNT(*)
        FROM medical_records mr
        WHERE EXISTS (
                SELECT 1
                FROM access_requests ar
                WHERE ar.patient_id = mr.patient_id
                    AND ar.doctor_name = :dname
                    AND ar.request_status = 'approved'
                    AND ar.records_sent = 1
        )
");
$stmtRecords->execute(['dname' => $doctor_name]);
$total_records = (int) $stmtRecords->fetchColumn();

// New records received today
$stmtNew = $pdo->prepare("
    SELECT COUNT(*)
    FROM medical_records mr
        WHERE EXISTS (
                SELECT 1
                FROM access_requests ar
                WHERE ar.patient_id = mr.patient_id
                    AND ar.doctor_name = :dname
                    AND ar.request_status = 'approved'
                    AND ar.records_sent = 1
        )
      AND DATE(mr.created_at) = CURDATE()
");
$stmtNew->execute(['dname' => $doctor_name]);
$new_today = (int) $stmtNew->fetchColumn();

// Recently received patient records (last 5)
$stmtRecent = $pdo->prepare("
    SELECT DISTINCT
           p.id          AS patient_id,
           p.name        AS patient_name,
           p.national_id,
           p.email       AS patient_email,
           ar.requested_at,
            COALESCE(ar.updated_at, ar.requested_at) AS access_granted_at,
           (SELECT COUNT(*) FROM medical_records WHERE patient_id = p.id) AS record_count
    FROM access_requests ar
    JOIN patients p ON p.id = ar.patient_id
        WHERE ar.doctor_name = :dname
            AND ar.request_status = 'approved'
            AND ar.records_sent = 1
    ORDER BY ar.requested_at DESC
    LIMIT 5
");
$stmtRecent->execute(['dname' => $doctor_name]);
$recent_patients = $stmtRecent->fetchAll(PDO::FETCH_ASSOC);

foreach ($recent_patients as &$row) {
    $accessAt = !empty($row['access_granted_at']) ? strtotime((string)$row['access_granted_at']) : false;
    $row['details_expired'] = $accessAt !== false && (time() - $accessAt) > (48 * 60 * 60);
}
unset($row);

// Today's date greeting
$hour = (int) date('H');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | Practitioner Portal</title>
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

        /* ── Main ── */
        .main-content {
            margin-left: 220px;
            padding: 40px 44px;
            min-height: 100vh;
        }

        .page-header { margin-bottom: 28px; }
        .page-header h2 { font-size: 1.45rem; font-weight: 700; color: #0f172a; }
        .page-header p  { color: #64748b; font-size: 0.85rem; margin-top: 3px; }

        /* ── Stat Cards ── */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 28px;
        }

        .stat-card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 18px 20px;
            display: flex;
            align-items: center;
            gap: 14px;
            text-decoration: none;
            transition: box-shadow 0.15s, border-color 0.15s;
        }
        .stat-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,0.07); border-color: #cbd5e1; }
        .stat-card.active-filter { border-color: #0e7490; box-shadow: 0 0 0 3px rgba(14,116,144,0.1); }

        .stat-icon {
            width: 42px; height: 42px;
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1rem;
            flex-shrink: 0;
        }
        .stat-icon.all      { background: #f1f5f9; color: #475569; }
        .stat-icon.approved { background: #dcfce7; color: #16a34a; }
        .stat-icon.pending  { background: #fef9c3; color: #ca8a04; }
        .stat-icon.declined { background: #fee2e2; color: #dc2626; }

        .stat-info span { font-size: 0.75rem; font-weight: 600; color: #94a3b8; text-transform: uppercase; display: block; }
        .stat-info strong { font-size: 1.4rem; font-weight: 700; color: #0f172a; }

        /* ── Table Card ── */
        .table-card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            overflow: hidden;
        }

        .table-card-header {
            padding: 18px 24px;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .table-card-header h5 {
            font-size: 0.95rem;
            font-weight: 600;
            color: #0f172a;
            margin: 0;
        }

        .results-count {
            font-size: 0.8rem;
            color: #94a3b8;
        }

        table { width: 100%; border-collapse: collapse; }

        thead tr { background: #f8fafc; }
        thead th {
            padding: 12px 20px;
            font-size: 0.75rem;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            border-bottom: 1px solid #f1f5f9;
            white-space: nowrap;
        }

        tbody tr {
            border-bottom: 1px solid #f8fafc;
            transition: background 0.1s;
        }
        tbody tr:last-child { border-bottom: none; }
        tbody tr:hover { background: #f8fafc; }

        tbody td {
            padding: 14px 20px;
            font-size: 0.875rem;
            color: #334155;
            vertical-align: middle;
        }

        .patient-name { font-weight: 600; color: #0f172a; display: block; }
        .patient-nid  { font-size: 0.78rem; color: #94a3b8; }

        /* Status badges */
        .badge-status {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 0.75rem;
            font-weight: 600;
            padding: 4px 12px;
            border-radius: 20px;
        }
        .badge-status.approved { background: #dcfce7; color: #16a34a; }
        .badge-status.pending  { background: #fef9c3; color: #ca8a04; }
        .badge-status.declined { background: #fee2e2; color: #dc2626; }

        /* View Records button */
        .btn-records {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            background: #0e7490;
            color: #fff;
            border-radius: 8px;
            font-size: 0.78rem;
            font-weight: 600;
            text-decoration: none;
            transition: background 0.15s;
            white-space: nowrap;
        }
        .btn-records:hover { background: #0b5f75; color: #fff; }

        /* Empty */
        .empty-state {
            text-align: center;
            padding: 56px 20px;
            color: #94a3b8;
        }
        .empty-state i { font-size: 2.2rem; display: block; margin-bottom: 12px; color: #e2e8f0; }
        .empty-state p { font-size: 0.88rem; }

        @media (max-width: 900px) {
            .stats-row { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 768px) {
            .sidebar { display: none; }
            .main-content { margin-left: 0; padding: 24px 18px; }
            .stats-row { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>

<!-- ══ Sidebar ══ -->
<div class="sidebar">
    <div class="sidebar-brand">
        <div class="icon-box"><i class="fa-solid fa-stethoscope"></i></div>
        <div class="brand-label">
            <strong>Dr. <?php echo htmlspecialchars($doctor_name); ?></strong>
            <small><?php echo htmlspecialchars($doctor_specialty); ?></small>
        </div>
    </div>

    <a href="dashboard.php" class="nav-link-custom active">
        <i class="fa-solid fa-house"></i> Dashboard overview
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
        <h2><?php echo $greeting; ?>, Dr. <?php echo htmlspecialchars($doctor_name); ?> </h2>
        <p><?php echo date('l, F j, Y'); ?> &mdash; Here's your practice overview for today.</p>
    </div>

    <!-- Stat Cards -->
    <div class="stats-row">
        <a href="my_patients.php" class="stat-card">
            <div class="stat-icon all"><i class="fa-solid fa-users"></i></div>
            <div class="stat-info">
                <span>My Patients</span>
                <strong><?php echo $total_patients; ?></strong>
            </div>
        </a>
        <a href="my_patients.php" class="stat-card">
            <div class="stat-icon approved"><i class="fa-solid fa-file-medical"></i></div>
            <div class="stat-info">
                <span>Total Records</span>
                <strong><?php echo $total_records; ?></strong>
            </div>
        </a>
        <div class="stat-card">
            <div class="stat-icon pending"><i class="fa-solid fa-file-circle-plus"></i></div>
            <div class="stat-info">
                <span>New Today</span>
                <strong><?php echo $new_today; ?></strong>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon all"><i class="fa-solid fa-calendar-day"></i></div>
            <div class="stat-info">
                <span>Today's Date</span>
                <strong style="font-size:1rem;"><?php echo date('d M Y'); ?></strong>
            </div>
        </div>
    </div>

    <!-- Recent Patients Table -->
    <div class="table-card">
        <div class="table-card-header">
            <h5><i class="fa-solid fa-clock-rotate-left" style="color:#0e7490; margin-right:8px;"></i> Recently Assigned Patients</h5>
            <a href="my_patients.php" class="results-count" style="color:#0e7490; font-weight:600; text-decoration:none;">View all &rarr;</a>
        </div>

        <?php if (!empty($recent_patients)): ?>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Patient</th>
                    <th>Email</th>
                    <th>Assigned On</th>
                    <th>Records</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php $i = 1; foreach ($recent_patients as $row): ?>
                <tr>
                    <td style="color:#94a3b8; font-size:0.8rem;"><?php echo $i++; ?></td>
                    <td>
                        <span class="patient-name"><?php echo htmlspecialchars($row['patient_name']); ?></span>
                        <?php if (!empty($row['details_expired'])): ?>
                            <span class="patient-nid">Patient details have expired after 48hrs.</span>
                        <?php else: ?>
                            <span class="patient-nid">NID: <?php echo htmlspecialchars($row['national_id']); ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo !empty($row['details_expired']) ? 'Hidden' : htmlspecialchars($row['patient_email']); ?></td>
                    <td><?php echo date('M d, Y', strtotime($row['requested_at'])); ?></td>
                    <td>
                        <span class="badge-status approved">
                            <i class="fa-solid fa-file-medical"></i> <?php echo $row['record_count']; ?> file<?php echo $row['record_count'] != 1 ? 's' : ''; ?>
                        </span>
                    </td>
                    <td>
                        <a href="patient_records.php?patient_id=<?php echo $row['patient_id']; ?>" class="btn-records">
                            <i class="fa-solid fa-file-medical"></i> View Records
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
            <div class="empty-state">
                <i class="fa-solid fa-user-slash"></i>
                <p>No patients have been assigned to you yet.<br>
                <span style="color:#94a3b8; font-size:0.82rem;">The hospital admin will assign patients and send their records to you.</span></p>
            </div>
        <?php endif; ?>
    </div>

</div>

</body>
</html>