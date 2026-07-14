<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

$message = "";
$message_type = "";

try {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS hospitals (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            email VARCHAR(150) NOT NULL,
            phone VARCHAR(30) NOT NULL,
            county VARCHAR(100) NOT NULL,
            address VARCHAR(255) NOT NULL,
            status ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_hospital_name (name),
            UNIQUE KEY unique_hospital_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
} catch (PDOException $e) {
    die("Error preparing hospitals table: " . $e->getMessage());
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['update'])) {
    $id = (int) ($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $county = trim($_POST['county'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $status = $_POST['status'] ?? 'Inactive';

    if ($id <= 0 || $name === '' || $email === '' || $phone === '' || $county === '' || $address === '') {
        $message = "Error: Please complete all hospital fields before saving.";
        $message_type = "alert-danger";
    } else {
        $status = ($status === 'Active') ? 'Active' : 'Inactive';

        try {
            $stmt_existing = $pdo->prepare("SELECT name, email FROM hospitals WHERE id = ? LIMIT 1");
            $stmt_existing->execute([$id]);
            $existing_hospital = $stmt_existing->fetch(PDO::FETCH_ASSOC);

            if (!$existing_hospital) {
                throw new RuntimeException("Hospital not found.");
            }

            $pdo->beginTransaction();

            $stmt_hospital = $pdo->prepare(
                "UPDATE hospitals
                 SET name = ?, email = ?, phone = ?, county = ?, address = ?, status = ?
                 WHERE id = ?"
            );
            $stmt_hospital->execute([$name, $email, $phone, $county, $address, $status, $id]);

            $stmt_staff_sync = $pdo->prepare(
                "UPDATE staff
                 SET name = ?, hospital_name = ?, email = ?, phone = ?, county = ?, address = ?, status = ?
                 WHERE email = ? OR hospital_name = ?"
            );
            $stmt_staff_sync->execute([
                $name,
                $name,
                $email,
                $phone,
                $county,
                $address,
                $status,
                $existing_hospital['email'],
                $existing_hospital['name']
            ]);

            $pdo->commit();

            if ($stmt_staff_sync->rowCount() > 0) {
                $message = "Hospital updated successfully and staff account synced.";
                $message_type = "alert-success";
            } else {
                $message = "Hospital updated successfully. No linked staff account found to sync.";
                $message_type = "alert-warning";
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $message = "Error: " . $e->getMessage();
            $message_type = "alert-danger";
        }
    }
}

$hospitals = $pdo->query("SELECT * FROM hospitals ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Hospitals | HMS Admin</title>
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
        .btn-primary {
            background-color: #107c91;
            border-color: #107c91;
        }
        .btn-primary:hover {
            background-color: #0f6f81;
            border-color: #0f6f81;
        }
        .table thead th {
            color: #64757a;
            font-weight: 600;
            font-size: 0.85rem;
            letter-spacing: 0.01em;
            border-bottom-color: #e0e6e8;
        }
        .modal-header {
            border-bottom-color: #e0e6e8;
        }
        .modal-footer {
            border-top-color: #e0e6e8;
        }
        .modal-open {
            padding-right: 0 !important;
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
        <a class="nav-link active" href="edit_hospitals.php"><i class="fa-solid fa-pen me-2"></i>Edit Hospitals</a>
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
            <h4 class="mb-0">Edit Hospitals</h4>
            <p class="text-muted mb-0">Update hospital profile details without leaving this page.</p>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert <?= htmlspecialchars($message_type) ?>"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <div class="panel-card">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($hospitals as $h): ?>
                <tr>
                    <td><?= htmlspecialchars($h['name']) ?></td>
                    <td><?= htmlspecialchars($h['email']) ?></td>
                    <td>
                        <span class="badge bg-<?= $h['status'] === 'Active' ? 'success' : 'secondary' ?>">
                            <?= htmlspecialchars($h['status']) ?>
                        </span>
                    </td>
                    <td>
                        <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editModal<?= (int) $h['id'] ?>">Edit</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php foreach ($hospitals as $h): ?>
<div class="modal fade" id="editModal<?= (int) $h['id'] ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form method="POST" class="modal-content" autocomplete="off">
            <div class="modal-header">
                <h5 class="modal-title">Edit <?= htmlspecialchars($h['name']) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="id" value="<?= (int) $h['id'] ?>">
                <div class="row">
                    <div class="col-md-6 mb-3"><label class="form-label">Name</label><input type="text" name="name" class="form-control" value="<?= htmlspecialchars($h['name']) ?>" required></div>
                    <div class="col-md-6 mb-3"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?= htmlspecialchars($h['email']) ?>" required></div>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($h['phone']) ?>" required></div>
                    <div class="col-md-6 mb-3"><label class="form-label">County</label><input type="text" name="county" class="form-control" value="<?= htmlspecialchars($h['county']) ?>" required></div>
                </div>
                <div class="mb-3"><label class="form-label">Address</label><textarea name="address" class="form-control" rows="2" required><?= htmlspecialchars($h['address']) ?></textarea></div>
                <div class="mb-2">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="Active" <?= $h['status'] === 'Active' ? 'selected' : '' ?>>Active</option>
                        <option value="Inactive" <?= $h['status'] === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="submit" name="update" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>
<?php endforeach; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

