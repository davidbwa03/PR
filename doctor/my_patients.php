<?php
session_start();
if (!isset($_SESSION['doctor_id'])) {
    header("Location: login.php");
    exit();
}
require_once 'db.php';

$doctor_id   = $_SESSION['doctor_id'];
$doctor_name = $_SESSION['doctor_name'];

// Fetch all requests made by this doctor
$stmt = $pdo->prepare("
    SELECT ar.id AS request_id,
           ar.request_status,
           ar.requested_at,
           p.name        AS patient_name,
           p.national_id,
           p.email       AS patient_email,
           p.id          AS patient_id
    FROM access_requests ar
    JOIN patients p ON p.id = ar.patient_id
    WHERE ar.doctor_name = :dname
    ORDER BY ar.requested_at DESC
");
$stmt->execute(['dname' => $doctor_name]);
$all_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Counts per status
$total    = count($all_requests);
$approved = count(array_filter($all_requests, fn($r) => $r['request_status'] === 'approved'));
$pending  = count(array_filter($all_requests, fn($r) => $r['request_status'] === 'pending'));
$declined = count(array_filter($all_requests, fn($r) => $r['request_status'] === 'declined'));

// Active filter from URL
$filter = $_GET['filter'] ?? 'all';
$filtered = match($filter) {
    'approved' => array_filter($all_requests, fn($r) => $r['request_status'] === 'approved'),
    'pending'  => array_filter($all_requests, fn($r) => $r['request_status'] === 'pending'),
    'declined' => array_filter($all_requests, fn($r) => $r['request_status'] === 'declined'),
    default    => $all_requests,
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Patients | Practitioner Portal</title>
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
            <strong>Practitioner</strong>
            <small>Portal Panel</small>
        </div>
    </div>

    <a href="dashboard.php" class="nav-link-custom">
        <i class="fa-solid fa-magnifying-glass"></i> Request Patient Data
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

<!-- ══ Main ══ -->
<div class="main-content">

    <div class="page-header">
        <h2>My Patients</h2>
        <p>All access requests you have submitted — Dr. <?php echo htmlspecialchars($doctor_name); ?></p>
    </div>

    <!-- Stat Filter Cards -->
    <div class="stats-row">
        <a href="my_patients.php?filter=all" class="stat-card <?php echo $filter === 'all' ? 'active-filter' : ''; ?>">
            <div class="stat-icon all"><i class="fa-solid fa-users"></i></div>
            <div class="stat-info">
                <span>Total</span>
                <strong><?php echo $total; ?></strong>
            </div>
        </a>
        <a href="my_patients.php?filter=approved" class="stat-card <?php echo $filter === 'approved' ? 'active-filter' : ''; ?>">
            <div class="stat-icon approved"><i class="fa-solid fa-circle-check"></i></div>
            <div class="stat-info">
                <span>Approved</span>
                <strong><?php echo $approved; ?></strong>
            </div>
        </a>
        <a href="my_patients.php?filter=pending" class="stat-card <?php echo $filter === 'pending' ? 'active-filter' : ''; ?>">
            <div class="stat-icon pending"><i class="fa-solid fa-clock"></i></div>
            <div class="stat-info">
                <span>Pending</span>
                <strong><?php echo $pending; ?></strong>
            </div>
        </a>
        <a href="my_patients.php?filter=declined" class="stat-card <?php echo $filter === 'declined' ? 'active-filter' : ''; ?>">
            <div class="stat-icon declined"><i class="fa-solid fa-circle-xmark"></i></div>
            <div class="stat-info">
                <span>Declined</span>
                <strong><?php echo $declined; ?></strong>
            </div>
        </a>
    </div>

    <!-- Table -->
    <div class="table-card">
        <div class="table-card-header">
            <h5>
                <?php echo match($filter) {
                    'approved' => '<i class="fa-solid fa-circle-check" style="color:#16a34a;margin-right:8px;"></i> Approved Patients',
                    'pending'  => '<i class="fa-solid fa-clock" style="color:#ca8a04;margin-right:8px;"></i> Pending Requests',
                    'declined' => '<i class="fa-solid fa-circle-xmark" style="color:#dc2626;margin-right:8px;"></i> Declined Requests',
                    default    => '<i class="fa-solid fa-list" style="color:#475569;margin-right:8px;"></i> All Requests',
                }; ?>
            </h5>
            <span class="results-count"><?php echo count($filtered); ?> record<?php echo count($filtered) !== 1 ? 's' : ''; ?></span>
        </div>

        <?php if (!empty($filtered)): ?>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Patient</th>
                    <th>Email</th>
                    <th>Requested</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php $i = 1; foreach ($filtered as $row): ?>
                <tr>
                    <td style="color:#94a3b8; font-size:0.8rem;"><?php echo $i++; ?></td>
                    <td>
                        <span class="patient-name"><?php echo htmlspecialchars($row['patient_name']); ?></span>
                        <span class="patient-nid">NID: <?php echo htmlspecialchars($row['national_id']); ?></span>
                    </td>
                    <td><?php echo htmlspecialchars($row['patient_email']); ?></td>
                    <td><?php echo date('M d, Y', strtotime($row['requested_at'])); ?></td>
                    <td>
                        <?php $s = $row['request_status']; ?>
                        <span class="badge-status <?php echo $s; ?>">
                            <?php if ($s === 'approved'): ?>
                                <i class="fa-solid fa-circle-check"></i> Approved
                            <?php elseif ($s === 'pending'): ?>
                                <i class="fa-solid fa-clock"></i> Pending
                            <?php else: ?>
                                <i class="fa-solid fa-circle-xmark"></i> Declined
                            <?php endif; ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
            <div class="empty-state">
                <i class="fa-solid fa-user-slash"></i>
                <p>No <?php echo $filter !== 'all' ? $filter : ''; ?> requests found.<br>
                <a href="dashboard.php" style="color:#0e7490; font-weight:600;">Request patient data →</a></p>
            </div>
        <?php endif; ?>
    </div>

</div>

</body>
</html>