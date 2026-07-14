<?php
// admin/access_requests.php
session_start();
require_once 'db.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

$columns = [];
try {
    $columns = $pdo->query("SHOW COLUMNS FROM access_requests")->fetchAll(PDO::FETCH_COLUMN, 0);
} catch (PDOException $e) {
    $columns = [];
}

$statusColumn = null;
if (in_array('status', $columns, true)) {
    $statusColumn = 'status';
} elseif (in_array('request_status', $columns, true)) {
    $statusColumn = 'request_status';
}

// Fetch all requests with safe ordering across schema variations.
$orderBy = 'id DESC';
if (in_array('created_at', $columns, true)) {
    $orderBy = 'created_at DESC';
} elseif (in_array('requested_at', $columns, true)) {
    $orderBy = 'requested_at DESC';
}

$requests = $pdo->query("SELECT * FROM access_requests ORDER BY {$orderBy}")->fetchAll();

$patientsById = [];
$patientEmailsById = [];
try {
    $patientColumns = $pdo->query("SHOW COLUMNS FROM patients")->fetchAll(PDO::FETCH_COLUMN, 0);
    if (in_array('id', $patientColumns, true) && in_array('name', $patientColumns, true)) {
        $selectColumns = in_array('email', $patientColumns, true) ? 'id, name, email' : 'id, name';
        $patientRows = $pdo->query("SELECT {$selectColumns} FROM patients")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($patientRows as $patientRow) {
            $patientId = (int) ($patientRow['id'] ?? 0);
            $patientName = trim((string) ($patientRow['name'] ?? ''));
            if ($patientId > 0 && $patientName !== '') {
                $patientsById[$patientId] = $patientName;
            }

            $patientEmail = trim((string) ($patientRow['email'] ?? ''));
            if ($patientId > 0 && $patientEmail !== '') {
                $patientEmailsById[$patientId] = $patientEmail;
            }
        }
    }
} catch (PDOException $e) {
    $patientsById = [];
    $patientEmailsById = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access Requests | HMS Admin</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/5.3.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f4f7f8;
        }
        .sidebar {
            width: 260px;
            min-height: 100vh;
            background: #fff;
            border-right: 1px solid #e0e6e8;
            position: fixed;
            top: 0;
            left: 0;
            overflow-y: auto;
        }
        .sidebar .brand {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid #e0e6e8;
        }
        .sidebar .brand h5 {
            color: #107c91;
            margin: 0;
        }
        .sidebar .nav-section {
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #9aa5a8;
            padding: 1rem 1.5rem 0.4rem;
        }
        .sidebar .nav-link {
            color: #4a5658;
            padding: 0.6rem 1.5rem;
            font-size: 0.92rem;
            border-left: 3px solid transparent;
        }
        .sidebar .nav-link i {
            width: 20px;
            color: #8a9598;
        }
        .sidebar .nav-link:hover {
            background-color: #f0f7f8;
            color: #107c91;
        }
        .sidebar .nav-link:hover i {
            color: #107c91;
        }
        .sidebar .nav-link.active {
            background-color: #e6f3f5;
            color: #107c91;
            border-left-color: #107c91;
            font-weight: 500;
        }
        .sidebar .nav-link.active i {
            color: #107c91;
        }
        .main-content {
            margin-left: 260px;
            padding: 2rem;
        }
        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.75rem;
        }
        .panel-card {
            background: #fff;
            border: 1px solid #e0e6e8;
            border-radius: 10px;
            padding: 1.5rem;
        }
    </style>
</head>
<body>

<div class="sidebar">
    <div class="brand">
        <h5><i class="fa-solid fa-hospital me-2"></i>HMS Admin</h5>
    </div>
    <nav class="nav flex-column py-2">
        <a class="nav-link" href="dashboard.php"><i class="fa-solid fa-gauge me-2"></i>Dashboard</a>

        <div class="nav-section">Hospitals</div>
        <a class="nav-link" href="add_hospital.php"><i class="fa-solid fa-plus me-2"></i>Add Hospital</a>
        <a class="nav-link" href="edit_hospitals.php"><i class="fa-solid fa-pen me-2"></i>Edit Hospitals</a>
        <a class="nav-link" href="manage_hospitals.php"><i class="fa-solid fa-list me-2"></i>Manage Hospitals</a>
        <a class="nav-link" href="delete_hospitals.php"><i class="fa-solid fa-trash me-2"></i>Delete Hospitals</a>

        <div class="nav-section">Directory</div>
        <a class="nav-link" href="view_doctors.php"><i class="fa-solid fa-user-doctor me-2"></i>View All Doctors</a>
        <a class="nav-link" href="view_patients.php"><i class="fa-solid fa-hospital-user me-2"></i>View All Patients</a>

        <div class="nav-section">Reports & Requests</div>
        <a class="nav-link" href="reports.php"><i class="fa-solid fa-chart-line me-2"></i>Generate Reports</a>
        <a class="nav-link active" href="access_requests.php"><i class="fa-solid fa-folder-open me-2"></i>View Access Requests</a>

        <hr class="mx-3">
        <a class="nav-link text-danger" href="logout.php"><i class="fa-solid fa-right-from-bracket me-2"></i>Logout</a>
    </nav>
</div>

<div class="main-content">
    <div class="topbar">
        <div>
            <h4 class="mb-0">System Access Requests</h4>
            <p class="text-muted mb-0">View incoming access requests and who received them.</p>
        </div>
    </div>

    <div class="panel-card">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>Requester</th>
                    <th>Requested Role/Access</th>
                    <th>Received By</th>
                    <th>Email</th>
                    <th>Doctor Received Details</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($requests as $r): ?>
                <?php
                    $requester_name = trim((string) ($r['requester_name'] ?? $r['doctor_name'] ?? $r['patient_name'] ?? 'Unknown Requester'));
                    $requested_access = trim((string) ($r['access_level'] ?? $r['medical_facility'] ?? 'General Access'));
                    $status_value = strtolower(trim((string) ($r['status'] ?? $r['request_status'] ?? 'pending')));
                    if ($status_value === '') {
                        $status_value = 'pending';
                    }
                    $badge_class = $status_value === 'pending' ? 'warning'
                        : (($status_value === 'approved') ? 'success' : (($status_value === 'rejected' || $status_value === 'declined') ? 'danger' : 'secondary'));

                    $recipientName = trim((string) (
                        $r['recipient_name']
                        ?? $r['received_by']
                        ?? $r['sent_to']
                        ?? $r['patient_name']
                        ?? ''
                    ));

                    if ($recipientName === '') {
                        $recipientId = (int) ($r['recipient_id'] ?? $r['patient_id'] ?? 0);
                        if ($recipientId > 0 && isset($patientsById[$recipientId])) {
                            $recipientName = $patientsById[$recipientId];
                        }
                    }

                    if ($recipientName === '') {
                        $recipientName = 'Unknown Recipient';
                    }

                    $recipientEmail = trim((string) (
                        $r['recipient_email']
                        ?? $r['received_email']
                        ?? $r['email']
                        ?? ''
                    ));

                    if ($recipientEmail === '') {
                        $recipientId = (int) ($r['recipient_id'] ?? $r['patient_id'] ?? 0);
                        if ($recipientId > 0 && isset($patientEmailsById[$recipientId])) {
                            $recipientEmail = $patientEmailsById[$recipientId];
                        }
                    }

                    if ($recipientEmail === '') {
                        $recipientEmail = 'N/A';
                    }

                    $doctorReceivedLabel = 'Pending';
                    $doctorReceivedClass = 'warning';

                    $recordsSentValue = (int) ($r['records_sent'] ?? 0);
                    $doctorReceiverName = trim((string) ($r['doctor_name'] ?? ''));
                    $isApprovedRequest = in_array($status_value, ['approved'], true);

                    if ($recordsSentValue === 1 && $doctorReceiverName !== '') {
                        $doctorReceivedLabel = 'Dr. ' . $doctorReceiverName;
                        $doctorReceivedClass = 'success';
                    } elseif ($isApprovedRequest) {
                        $doctorReceivedLabel = 'Pending';
                        $doctorReceivedClass = 'warning';
                    } elseif (in_array($status_value, ['rejected', 'declined'], true)) {
                        $doctorReceivedLabel = 'Not Sent';
                        $doctorReceivedClass = 'secondary';
                    }
                ?>
                <tr>
                    <td><?= htmlspecialchars($requester_name) ?></td>
                    <td><?= htmlspecialchars($requested_access) ?></td>
                    <td><?= htmlspecialchars($recipientName) ?></td>
                    <td><?= htmlspecialchars($recipientEmail) ?></td>
                    <td>
                        <span class="badge bg-<?= $doctorReceivedClass ?>">
                            <?= htmlspecialchars($doctorReceivedLabel) ?>
                        </span>
                    </td>
                    <td>
                        <span class="badge bg-<?= $badge_class ?>">
                            <?= ucfirst($status_value) ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($requests)): ?>
                <tr>
                    <td colspan="6" class="text-center text-muted">No access requests found.</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</body>
</html>