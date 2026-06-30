<?php
// staff/add_practitioner.php
session_start();
require_once 'db.php';

// Auth Check
if (!isset($_SESSION['staff_logged_in']) || $_SESSION['staff_logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

$staff_id = isset($_SESSION['staff_id']) ? (int) $_SESSION['staff_id'] : 0;
$hospital_brand_name = 'Hospital';

try {
    if ($staff_id > 0) {
        $stmt_hospital_name = $pdo->prepare("SELECT hospital_name FROM staff WHERE id = ? LIMIT 1");
        $stmt_hospital_name->execute([$staff_id]);
        $resolved_hospital_name = trim((string) $stmt_hospital_name->fetchColumn());
        if ($resolved_hospital_name !== '') {
            $hospital_brand_name = $resolved_hospital_name;
        }
    }
} catch (PDOException $e) {
    $hospital_brand_name = 'Hospital';
}

$message = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitize and collect inputs
    $name = trim($_POST['name'] ?? '');
    $specialty = trim($_POST['specialty'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password_raw = $_POST['password'] ?? '';
    $password = password_hash($password_raw, PASSWORD_BCRYPT);
    $phone = trim($_POST['phone'] ?? '');
    $dob = trim($_POST['dob'] ?? '');
    $gender = trim($_POST['gender'] ?? '');
    $address = trim($_POST['address'] ?? '');

    try {
        // Insert into the 'doctors' table
        $stmt = $pdo->prepare("INSERT INTO doctors (name, specialty, email, password, phone, dob, gender, address) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$name, $specialty, $email, $password, $phone, $dob, $gender, $address]);
        $message = "Doctor added successfully.";
    } catch (PDOException $e) {
        $message = "Error: Could not add doctor. The email might already be registered.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Practitioner - Hospital Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root { --sidebar-width: 260px; --main-bg: #f4f6f8; --sidebar-bg: #ffffff; --text-main: #1e293b; --text-sub: #64748b; --teal-accent: #107c91; --border-light: #e2e8f0; }
        body { background-color: var(--main-bg); font-family: system-ui, sans-serif; color: var(--text-main); }
        .sidebar-container { width: var(--sidebar-width); background-color: var(--sidebar-bg); border-right: 1px solid var(--border-light); height: 100vh; position: fixed; top: 0; left: 0; padding: 32px 20px; display: flex; flex-direction: column; justify-content: space-between; z-index: 1000; }
        .sidebar-brand { display: flex; align-items: center; gap: 12px; margin-bottom: 40px; padding-left: 8px; }
        .brand-avatar { background-color: var(--teal-accent); color: #ffffff; width: 40px; height: 40px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 18px; }
        .brand-title h1 { font-size: 15px; font-weight: 700; margin: 0; color: var(--text-main); }
        .brand-title span { font-size: 11px; color: var(--text-sub); display: block; }
        .sidebar-menu { display: flex; flex-direction: column; gap: 8px; }
        .menu-link { display: flex; align-items: center; padding: 12px 16px; color: var(--text-main); text-decoration: none; border-radius: 8px; font-size: 14px; font-weight: 600; transition: all 0.2s ease; }
        .menu-link.active { background-color: var(--teal-accent); color: #ffffff; }
        .menu-link:hover:not(.active) { background-color: #f1f5f9; color: var(--teal-accent); }
        .logout-link { display: flex; align-items: center; padding: 12px 16px; color: var(--text-sub); text-decoration: none; font-size: 14px; font-weight: 600; border-radius: 8px; transition: all 0.2s; }
        .logout-link:hover { background-color: #fef2f2; color: #ef4444; }
        .workspace { margin-left: var(--sidebar-width); padding: 40px 48px; }
        .panel-card { background: #ffffff; border: 1px solid var(--border-light); border-radius: 10px; padding: 28px; max-width: 800px; }
    </style>
</head>
<body>

    <nav class="sidebar-container">
        <div class="w-100">
            <div class="sidebar-brand">
                <div class="brand-avatar">H</div>
                <div class="brand-title"><h1><?= htmlspecialchars($hospital_brand_name); ?></h1><span>Portal</span></div>
            </div>
            <div class="sidebar-menu">
                <a href="dashboard.php" class="menu-link">Overview</a>
                <a href="patient_requests.php" class="menu-link">Patient Requests</a>
                <a href="send_records.php" class="menu-link">Send Records to Doctors</a>
                <a href="update_vitals.php" class="menu-link">Update Patient Vitals</a>
                <a href="add_practitioner.php" class="menu-link active">Add Practitioners</a>
                <a href="manage_practitioners.php" class="menu-link">Manage Doctors</a>
                <a href="create_claim.php" class="menu-link">Create the claim</a>
                <a href="analytics.php" class="menu-link">Analytics</a>
            </div>
        </div>
        <div><a href="logout.php" class="logout-link">Sign Out</a></div>
    </nav>

    <main class="workspace">
        <div class="panel-card">
            <h2 class="h4 fw-bold mb-4">Add New Practitioner</h2>
            <?php if ($message): ?>
                <div class="alert alert-info"><?= htmlspecialchars($message); ?></div>
            <?php endif; ?>
            <form method="POST">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label small fw-bold">Full Name</label>
                        <input type="text" name="name" class="form-control" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label small fw-bold">Specialty</label>
                        <input type="text" name="specialty" class="form-control" required>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-bold">Email</label>
                    <input type="email" name="email" class="form-control" required>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label small fw-bold">Phone Number</label>
                        <input type="tel" name="phone" class="form-control" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label small fw-bold">Date of Birth</label>
                        <input type="date" name="dob" class="form-control" required>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label small fw-bold">Gender</label>
                        <select name="gender" class="form-control" required>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label small fw-bold">Password</label>
                        <input type="password" name="password" class="form-control" required>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-bold">Address</label>
                    <textarea name="address" class="form-control" rows="2" required></textarea>
                </div>
                <button type="submit" class="btn btn-primary w-100" style="background-color: var(--teal-accent); border: none;">Register Practitioner</button>
            </form>
        </div>
    </main>

</body>
</html>