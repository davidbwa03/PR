<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

$message = "";
$message_type = "";

// Handle Deletion Logic
if (isset($_GET['delete_id'])) {
    $id = (int) ($_GET['delete_id'] ?? 0);

    if ($id <= 0) {
        $message = "Error: Invalid hospital ID.";
        $message_type = "alert-danger";
    } else {
        try {
            $stmt_hospital = $pdo->prepare("SELECT id, name, email FROM hospitals WHERE id = ? LIMIT 1");
            $stmt_hospital->execute([$id]);
            $hospital = $stmt_hospital->fetch(PDO::FETCH_ASSOC);

            if (!$hospital) {
                $message = "Hospital not found or already deleted.";
                $message_type = "alert-warning";
            } else {
                $pdo->beginTransaction();

                // Remove linked staff accounts for this hospital to keep data consistent.
                $stmt_staff = $pdo->prepare("DELETE FROM staff WHERE email = ? OR hospital_name = ?");
                $stmt_staff->execute([$hospital['email'], $hospital['name']]);
                $deleted_staff_count = $stmt_staff->rowCount();

                $stmt_delete = $pdo->prepare("DELETE FROM hospitals WHERE id = ?");
                $stmt_delete->execute([$id]);

                if ($stmt_delete->rowCount() === 1) {
                    $pdo->commit();
                    $message = "Hospital deleted successfully.";
                    if ($deleted_staff_count > 0) {
                        $message .= " Linked staff deleted: " . $deleted_staff_count . ".";
                    }
                    $message_type = "alert-success";
                } else {
                    $pdo->rollBack();
                    $message = "Error: Delete did not affect any SQL row.";
                    $message_type = "alert-danger";
                }
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $message = "Error: Could not delete hospital from SQL. " . $e->getMessage();
            $message_type = "alert-danger";
        }
    }
}

// Fetch list of hospitals
$hospitals = $pdo->query("SELECT * FROM hospitals")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delete Hospitals | HMS Admin</title>
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
        .table thead th {
            color: #64757a;
            font-weight: 600;
            font-size: 0.85rem;
            letter-spacing: 0.01em;
            border-bottom-color: #e0e6e8;
        }
        .btn-danger {
            background-color: #dc3545;
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
        <a class="nav-link" href="insurance_provider.php"><i class="fa-solid fa-file-medical me-2"></i>Insurance Providers</a>

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
    <div class="topbar">
        <div>
            <h4 class="mb-0">Delete Hospitals</h4>
            <p class="text-muted mb-0">Remove hospital entries from the system directory.</p>
        </div>
    </div>
    
    <?php if ($message): ?>
        <div class="alert <?= htmlspecialchars($message_type) ?>"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <div class="panel-card">
        <table class="table table-hover">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($hospitals as $h): ?>
                <tr>
                    <td><?= htmlspecialchars($h['name']) ?></td>
                    <td><?= htmlspecialchars($h['email']) ?></td>
                    <td>
                        <a href="delete_hospitals.php?delete_id=<?= $h['id'] ?>" 
                           class="btn btn-sm btn-danger" 
                           onclick="return confirm('Are you sure you want to delete this hospital? This action cannot be undone.');">
                           <i class="fa-solid fa-trash"></i> Delete
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

</body>
</html>
