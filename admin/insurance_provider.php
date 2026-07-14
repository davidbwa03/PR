<?php
session_start();
require_once 'db.php'; // Ensure your DB connection is set up

if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

$message = "";
$message_type = "";

try {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS insurance_staff (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            email VARCHAR(150) NOT NULL,
            company VARCHAR(150) NOT NULL,
            password VARCHAR(255) NOT NULL,
            otp_code VARCHAR(10) NULL,
            otp_expires_at TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_insurance_staff_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
} catch (PDOException $e) {
    die("Error preparing database tables: " . $e->getMessage());
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $company = trim($_POST['company']);
    $login_password = trim($_POST['login_password'] ?? '');

    if ($name === '' || $email === '' || $company === '') {
        $message = "Error: Please fill in all required fields.";
        $message_type = "alert-danger";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Error: Please enter a valid email address.";
        $message_type = "alert-danger";
    } elseif ($login_password === '') {
        $message = "Error: Staff login password is required.";
        $message_type = "alert-danger";
    } elseif (strlen($login_password) < 6) {
        $message = "Error: Staff login password must be at least 6 characters.";
        $message_type = "alert-danger";
    } else {
        $check_staff = $pdo->prepare("SELECT id FROM insurance_staff WHERE email = ?");
        $check_staff->execute([$email]);

        if ($check_staff->rowCount() > 0) {
            $message = "Error: An insurance staff account with this email already exists.";
            $message_type = "alert-danger";
        } else {
            try {
                $hashed_password = password_hash($login_password, PASSWORD_BCRYPT);

                $stmt_staff = $pdo->prepare(
                    "INSERT INTO insurance_staff (name, email, company, password)
                     VALUES (?, ?, ?, ?)"
                );
                $stmt_staff->execute([$name, $email, $company, $hashed_password]);

                $message = "Insurance staff account created successfully. Login Email: " . $email;
                $message_type = "alert-success";
            } catch (Throwable $e) {
                $message = "Error: Could not complete registration. " . $e->getMessage();
                $message_type = "alert-danger";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Insurance Provider | HMS Admin</title>
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

        <div class="nav-section">Insurance Providers</div>
        <a class="nav-link active" href="insurance_provider.php"><i class="fa-solid fa-plus me-2"></i>Add Insurance Provider</a>

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
            <h4 class="mb-0">Add Insurance Provider Staff</h4>
            <p class="text-muted mb-0">Register a login account for an insurance provider staff member.</p>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert <?= htmlspecialchars($message_type) ?>"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <div class="panel-card">
        <form method="POST" class="needs-validation" novalidate>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Full Name</label>
                    <input type="text" name="name" class="form-control" required>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control" required>
                </div>
            </div>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Insurance Company</label>
                    <input type="text" name="company" class="form-control" placeholder="e.g. Jubilee, NHIF, SHA" required>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Login Password</label>
                    <input type="password" name="login_password" class="form-control" minlength="6" required>
                    <small class="text-muted">Minimum 6 characters.</small>
                </div>
            </div>
            <button type="submit" class="btn btn-primary px-4">Save Insurance Provider</button>
        </form>
    </div>
</div>

<script>
    // Bootstrap validation script
    (function () {
        'use strict';
        var forms = document.querySelectorAll('.needs-validation');
        Array.prototype.slice.call(forms).forEach(function (form) {
            form.addEventListener('submit', function (event) {
                if (!form.checkValidity()) {
                    event.preventDefault();
                    event.stopPropagation();
                }
                form.classList.add('was-validated');
            }, false);
        });
    })();
</script>
</body>
</html>
