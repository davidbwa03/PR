<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

$hospital_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$hospital = null;
$error_message = '';

if ($hospital_id <= 0) {
    $error_message = 'Invalid hospital selected.';
} else {
    try {
        $stmt = $pdo->prepare("SELECT * FROM hospitals WHERE id = ? LIMIT 1");
        $stmt->execute([$hospital_id]);
        $hospital = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$hospital) {
            $error_message = 'Hospital not found.';
        }
    } catch (PDOException $e) {
        $error_message = 'Unable to load hospital details right now.';
    }
}

function hospitalField(array $hospital, array $keys, string $fallback = 'N/A'): string {
    foreach ($keys as $key) {
        if (array_key_exists($key, $hospital)) {
            $value = trim((string) $hospital[$key]);
            if ($value !== '') {
                return htmlspecialchars($value);
            }
        }
    }
    return htmlspecialchars($fallback);
}

$hospital_display_name = $hospital
    ? hospitalField($hospital, ['name', 'hospital_name'])
    : 'Hospital Details';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Hospital | HMS Admin</title>
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
        .detail-card {
            background: #fff;
            border: 1px solid #e0e6e8;
            border-radius: 10px;
            padding: 1.5rem;
        }
        .detail-label {
            color: #8a9598;
            font-size: 0.82rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin-bottom: 0.2rem;
        }
        .detail-value {
            color: #334;
            font-weight: 600;
            margin-bottom: 1rem;
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
        <a class="nav-link active" href="manage_hospitals.php"><i class="fa-solid fa-list me-2"></i>Manage Hospitals</a>
        <a class="nav-link" href="delete_hospitals.php"><i class="fa-solid fa-trash me-2"></i>Delete Hospitals</a>

        <div class="nav-section">Directory</div>
        <a class="nav-link" href="view_doctors.php"><i class="fa-solid fa-user-doctor me-2"></i>View All Doctors</a>
        <a class="nav-link" href="view_patients.php"><i class="fa-solid fa-hospital-user me-2"></i>View All Patients</a>

        <div class="nav-section">Reports & Requests</div>
        <a class="nav-link" href="reports.php"><i class="fa-solid fa-chart-line me-2"></i>Generate Reports</a>
        <a class="nav-link" href="access_requests.php"><i class="fa-solid fa-folder-open me-2"></i>View Access Requests</a>

        <hr class="mx-3">
        <a class="nav-link text-danger" href="logout.php"><i class="fa-solid fa-right-from-bracket me-2"></i>Logout</a>
    </nav>
</div>

<div class="main-content">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><?= htmlspecialchars($hospital_display_name) ?></h4>
        <a href="manage_hospitals.php" class="btn btn-outline-secondary">
            <i class="fa-solid fa-arrow-left me-1"></i>Back to Manage Hospitals
        </a>
    </div>

    <?php if ($error_message !== ''): ?>
        <div class="alert alert-warning"><?= htmlspecialchars($error_message) ?></div>
    <?php else: ?>
        <div class="detail-card">
            <div class="row">
                <div class="col-md-6">
                    <div class="detail-label">Hospital Name</div>
                    <div class="detail-value"><?= hospitalField($hospital, ['name', 'hospital_name']) ?></div>
                </div>
                <div class="col-md-6">
                    <div class="detail-label">Email</div>
                    <div class="detail-value"><?= hospitalField($hospital, ['email']) ?></div>
                </div>
                <div class="col-md-6">
                    <div class="detail-label">Phone</div>
                    <div class="detail-value"><?= hospitalField($hospital, ['phone', 'phone_number']) ?></div>
                </div>
                <div class="col-md-6">
                    <div class="detail-label">Status</div>
                    <div class="detail-value"><?= hospitalField($hospital, ['status']) ?></div>
                </div>
                <div class="col-md-6">
                    <div class="detail-label">Practitioner ID</div>
                    <div class="detail-value"><?= hospitalField($hospital, ['practitioner_id']) ?></div>
                </div>
                <div class="col-md-6">
                    <div class="detail-label">Created At</div>
                    <div class="detail-value"><?= hospitalField($hospital, ['created_at']) ?></div>
                </div>
                <div class="col-md-12">
                    <div class="detail-label">Address</div>
                    <div class="detail-value"><?= hospitalField($hospital, ['address', 'hospital_address']) ?></div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

</body>
</html>
