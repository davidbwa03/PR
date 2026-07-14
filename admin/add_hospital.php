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

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS staff (
            id INT(11) NOT NULL AUTO_INCREMENT,
            practitioner_id VARCHAR(50) NOT NULL,
            name VARCHAR(100) NOT NULL,
            hospital_name VARCHAR(255) NULL,
            email VARCHAR(100) NOT NULL,
            phone VARCHAR(20) NULL,
            county VARCHAR(100) NULL,
            address TEXT NULL,
            status ENUM('Active', 'Inactive') DEFAULT 'Active',
            password VARCHAR(255) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_staff_practitioner_id (practitioner_id),
            UNIQUE KEY unique_staff_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
} catch (PDOException $e) {
    die("Error preparing database tables: " . $e->getMessage());
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $county = trim($_POST['county']);
    $address = trim($_POST['address']);
    $status = ($_POST['status'] ?? 'Inactive') === 'Active' ? 'Active' : 'Inactive';
    $login_password = trim($_POST['login_password'] ?? '');
    $requires_staff_account = ($status === 'Active');

    if ($name === '' || $email === '' || $phone === '' || $county === '' || $address === '') {
        $message = "Error: Please fill in all required fields.";
        $message_type = "alert-danger";
    } elseif ($requires_staff_account && $login_password === '') {
        $message = "Error: Staff login password is required for Active hospitals.";
        $message_type = "alert-danger";
    } elseif ($requires_staff_account && strlen($login_password) < 6) {
        $message = "Error: Staff login password must be at least 6 characters.";
        $message_type = "alert-danger";
    } else {
        $check_hospital = $pdo->prepare("SELECT id FROM hospitals WHERE name = ? OR email = ?");
        $check_hospital->execute([$name, $email]);

        if ($check_hospital->rowCount() > 0) {
            $message = "Error: A hospital with this name or email already exists.";
            $message_type = "alert-danger";
        } else {
            try {
                $practitioner_id = '';

                if ($requires_staff_account) {
                    $check_staff = $pdo->prepare("SELECT id FROM staff WHERE email = ?");
                    $check_staff->execute([$email]);
                    if ($check_staff->rowCount() > 0) {
                        throw new RuntimeException("A staff account with this email already exists.");
                    }
                }

                $pdo->beginTransaction();

                $stmt_hospital = $pdo->prepare("INSERT INTO hospitals (name, email, phone, county, address, status) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt_hospital->execute([$name, $email, $phone, $county, $address, $status]);

                if ($requires_staff_account) {
                    $current_year = date('Y');
                    $id_prefix = 'MD-' . $current_year . '-';

                    $last_id_stmt = $pdo->prepare(
                        "SELECT practitioner_id
                         FROM staff
                         WHERE practitioner_id LIKE ?
                         ORDER BY practitioner_id DESC
                         LIMIT 1"
                    );
                    $last_id_stmt->execute([$id_prefix . '%']);
                    $last_practitioner_id = $last_id_stmt->fetchColumn();

                    $next_sequence = 1;
                    if ($last_practitioner_id && preg_match('/^MD-\\d{4}-(\\d{4})$/', $last_practitioner_id, $matches)) {
                        $next_sequence = ((int) $matches[1]) + 1;
                    }

                    $practitioner_id = $id_prefix . str_pad((string) $next_sequence, 4, '0', STR_PAD_LEFT);

                    $check_practitioner = $pdo->prepare("SELECT id FROM staff WHERE practitioner_id = ?");
                    while (true) {
                        $check_practitioner->execute([$practitioner_id]);
                        if ($check_practitioner->rowCount() === 0) {
                            break;
                        }
                        $next_sequence++;
                        $practitioner_id = $id_prefix . str_pad((string) $next_sequence, 4, '0', STR_PAD_LEFT);
                    }

                    $hashed_password = password_hash($login_password, PASSWORD_BCRYPT);

                    $stmt_staff = $pdo->prepare(
                        "INSERT INTO staff (practitioner_id, name, hospital_name, email, phone, county, address, status, password)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
                    );
                    $stmt_staff->execute([
                        $practitioner_id,
                        $name,
                        $name,
                        $email,
                        $phone,
                        $county,
                        $address,
                        $status,
                        $hashed_password
                    ]);
                }

                $pdo->commit();

                if ($requires_staff_account) {
                    $message = "Hospital and staff login created successfully. Login Email: " . $email . " | Practitioner ID: " . $practitioner_id;
                } else {
                    $message = "Inactive hospital created successfully. No staff account was created.";
                }
                $message_type = "alert-success";
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
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
    <title>Add Hospital | HMS Admin</title>
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
        <a class="nav-link active" href="add_hospital.php"><i class="fa-solid fa-plus me-2"></i>Add Hospital</a>
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
            <h4 class="mb-0">Add New Hospital</h4>
            <p class="text-muted mb-0">Create a hospital profile and include core contact details.</p>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert <?= htmlspecialchars($message_type) ?>"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <div class="panel-card">
        <form method="POST" class="needs-validation" novalidate>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Hospital Name</label>
                    <input type="text" name="name" class="form-control" required>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control" required>
                </div>
            </div>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Phone</label>
                    <input type="text" name="phone" class="form-control" required>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">County</label>
                    <input type="text" name="county" class="form-control" required>
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label">Address</label>
                <textarea name="address" class="form-control" rows="2" required></textarea>
            </div>
            <div class="mb-3">
                <label class="form-label">Status</label>
                <select name="status" id="statusSelect" class="form-select">
                    <option value="Active">Active</option>
                    <option value="Inactive">Inactive</option>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label">Staff Login Password</label>
                <input type="password" name="login_password" id="loginPassword" class="form-control" minlength="6" required>
                <small class="text-muted" id="passwordHelp">Required only for Active status. Inactive hospitals do not get a staff account.</small>
            </div>
            <button type="submit" class="btn btn-primary px-4">Save Hospital</button>
        </form>
    </div>
</div>

<script>
    // Bootstrap validation script
    (function () {
        'use strict';
        var statusSelect = document.getElementById('statusSelect');
        var loginPassword = document.getElementById('loginPassword');

        function syncPasswordRequirement() {
            if (!statusSelect || !loginPassword) {
                return;
            }
            var active = statusSelect.value === 'Active';
            loginPassword.required = active;
            loginPassword.disabled = !active;
            if (!active) {
                loginPassword.value = '';
            }
        }

        syncPasswordRequirement();
        if (statusSelect) {
            statusSelect.addEventListener('change', syncPasswordRequirement);
        }

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
