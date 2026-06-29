<?php
// admin/dashboard.php
session_start();
require_once 'db.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

$admin_name = $_SESSION['admin_name'] ?? 'Admin';

// --- Stats (wrap each in try/catch in case a table/column name differs from your schema) ---
function safeCount($pdo, $sql) {
    try {
        return (int) $pdo->query($sql)->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

$totalHospitals = safeCount($pdo, "SELECT COUNT(*) FROM hospitals");
$totalDoctors   = safeCount($pdo, "SELECT COUNT(*) FROM doctors");
$totalPatients  = safeCount($pdo, "SELECT COUNT(*) FROM patients");
$pendingRequests = safeCount($pdo, "SELECT COUNT(*) FROM access_requests WHERE status = 'pending'");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Hospital Management System</title>
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
        .stat-card, .action-card {
            background: #fff;
            border: 1px solid #e0e6e8;
            border-radius: 10px;
            padding: 1.25rem;
        }
        .stat-card .icon-box {
            width: 46px;
            height: 46px;
            border-radius: 8px;
            background: #e6f3f5;
            color: #107c91;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }
        .stat-card h3 {
            margin: 0.5rem 0 0;
            font-weight: 600;
        }
        .stat-card p {
            margin: 0;
            color: #8a9598;
            font-size: 0.85rem;
        }
        .action-card {
            text-decoration: none;
            color: #344;
            display: block;
            transition: box-shadow 0.15s ease, border-color 0.15s ease;
        }
        .action-card:hover {
            border-color: #107c91;
            box-shadow: 0 2px 10px rgba(16,124,145,0.1);
            color: #107c91;
        }
        .action-card i {
            font-size: 22px;
            color: #107c91;
            margin-bottom: 0.5rem;
            display: block;
        }
        .action-card span {
            font-size: 0.88rem;
            font-weight: 500;
        }
        .avatar-circle {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: #107c91;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
        }
    </style>
</head>
<body>

<!-- Sidebar -->
<div class="sidebar">
    <div class="brand">
        <h5><i class="fa-solid fa-hospital me-2"></i>HMS Admin</h5>
    </div>
    <nav class="nav flex-column py-2">
        <a class="nav-link active" href="dashboard.php"><i class="fa-solid fa-gauge me-2"></i>Dashboard</a>

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
        <a class="nav-link" href="access_requests.php"><i class="fa-solid fa-folder-open me-2"></i>View Access Requests</a>

        <hr class="mx-3">
        <a class="nav-link text-danger" href="logout.php"><i class="fa-solid fa-right-from-bracket me-2"></i>Logout</a>
    </nav>
</div>

<!-- Main content -->
<div class="main-content">

    <div class="topbar">
        <div>
            <h4 class="mb-0">Welcome back, <?php echo htmlspecialchars($admin_name); ?></h4>
            <p class="text-muted mb-0">Here's what's happening across the system today.</p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <div class="avatar-circle"><?php echo strtoupper(substr($admin_name, 0, 1)); ?></div>
        </div>
    </div>

    <!-- Stat cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="stat-card">
                <div class="icon-box"><i class="fa-solid fa-hospital"></i></div>
                <h3><?php echo $totalHospitals; ?></h3>
                <p>Total Hospitals</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="icon-box"><i class="fa-solid fa-user-doctor"></i></div>
                <h3><?php echo $totalDoctors; ?></h3>
                <p>Total Doctors</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="icon-box"><i class="fa-solid fa-hospital-user"></i></div>
                <h3><?php echo $totalPatients; ?></h3>
                <p>Total Patients</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="icon-box"><i class="fa-solid fa-folder-open"></i></div>
                <h3><?php echo $pendingRequests; ?></h3>
                <p>Pending Access Requests</p>
            </div>
        </div>
    </div>

    <!-- Quick actions -->
    <h6 class="text-muted mb-3">Quick Actions</h6>
    <div class="row g-3">
        <div class="col-md-4 col-sm-6">
            <a href="add_hospital.php" class="action-card">
                <i class="fa-solid fa-plus"></i>
                <span>Add Hospital</span>
            </a>
        </div>
        <div class="col-md-4 col-sm-6">
            <a href="access_requests.php" class="action-card">
                <i class="fa-solid fa-folder-open"></i>
                <span>Access Requests</span>
            </a>
        </div>
        <div class="col-md-4 col-sm-6">
            <a href="reports.php" class="action-card">
                <i class="fa-solid fa-chart-line"></i>
                <span>Generate Reports</span>
            </a>
        </div>
    </div>

</div>

</body>
</html>