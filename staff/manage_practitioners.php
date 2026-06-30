<?php
// staff/manage_practitioners.php
session_start();
require_once 'db.php';

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

$statusMessage = '';

$hasStatusColumn = false;
try {
    $colCheck = $pdo->query("SHOW COLUMNS FROM doctors LIKE 'status'");
    $hasStatusColumn = $colCheck && $colCheck->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $hasStatusColumn = false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['doctor_id'], $_POST['status'])) {
    $doctorId = (int) $_POST['doctor_id'];
    $status = $_POST['status'] === 'active' ? 'active' : 'inactive';

    if ($hasStatusColumn) {
        $stmt = $pdo->prepare("UPDATE doctors SET status = :status WHERE id = :id");
        $stmt->execute([
            ':status' => $status,
            ':id' => $doctorId
        ]);

        $statusMessage = 'Practitioner status updated successfully.';
    } else {
        $statusMessage = 'Status column is not available in the doctors table.';
    }
}

$doctorQuery = $hasStatusColumn
    ? "SELECT id, name, specialty, status FROM doctors ORDER BY name ASC"
    : "SELECT id, name, specialty, 'inactive' AS status FROM doctors ORDER BY name ASC";

$doctors = $pdo->query($doctorQuery)->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Practitioners - Central Medical Center</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <style>
        :root { --sidebar-width: 260px; --main-bg: #f4f6f8; --sidebar-bg: #ffffff; --text-main: #1e293b; --text-sub: #64748b; --teal-accent: #107c91; --border-light: #e2e8f0; }
        body { background-color: var(--main-bg); font-family: system-ui, -apple-system, sans-serif; color: var(--text-main); overflow-x: hidden; }
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
        .panel-card { background: #ffffff; border: 1px solid var(--border-light); border-radius: 10px; padding: 28px; }

        /* Doctors Directory (new, additive only) */
        .directory-card { background: #ffffff; border: 1px solid var(--border-light); border-radius: 14px; padding: 20px 24px; display: flex; align-items: center; gap: 16px; height: 100%; }
        .directory-icon { width: 48px; height: 48px; background-color: #e6f7f5; border-radius: 12px; display: flex; align-items: center; justify-content: center; color: var(--teal-accent); font-size: 18px; flex-shrink: 0; }
        .directory-name { font-size: 15px; font-weight: 700; color: var(--text-main); margin: 0; }
        .directory-specialty { font-size: 13px; color: var(--text-sub); margin: 2px 0 0; }
        .directory-content { flex: 1; }
        .directory-manage { margin-top: 10px; }
        .directory-manage summary { cursor: pointer; list-style: none; font-size: 13px; font-weight: 600; color: var(--teal-accent); }
        .directory-manage summary::-webkit-details-marker { display: none; }
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
                <a href="add_practitioner.php" class="menu-link">Add Practitioners</a>
                <a href="manage_practitioners.php" class="menu-link active">Manage Doctors</a>
                <a href="create_claim.php" class="menu-link">Create the claim</a>
                <a href="analytics.php" class="menu-link">Analytics</a>
            </div>
        </div>
        <div><a href="logout.php" class="logout-link">Sign Out</a></div>
    </nav>

<main class="workspace">
    <h2 class="mb-4">Doctors Directory</h2>
    <?php if ($statusMessage): ?>
        <div class="alert alert-success"><?= htmlspecialchars($statusMessage); ?></div>
    <?php endif; ?>

    <div class="row g-4 mb-5">
        <?php foreach ($doctors as $d): ?>
        <div class="col-12 col-md-6 col-lg-4">
            <div class="directory-card">
                <div class="directory-icon"><i class="fa-solid fa-stethoscope"></i></div>
                <div class="directory-content">
                    <p class="directory-name"><?= htmlspecialchars($d['name']); ?></p>
                    <p class="directory-specialty"><?= htmlspecialchars($d['specialty'] ?? 'N/A'); ?></p>

                    <details class="directory-manage">
                        <summary>Set Status (<?= htmlspecialchars(ucfirst($d['status'] ?? 'inactive')); ?>)</summary>
                        <form method="post" class="d-flex gap-2 align-items-center mt-2 mb-0">
                            <input type="hidden" name="doctor_id" value="<?= (int)$d['id']; ?>">
                            <select name="status" class="form-select form-select-sm" style="max-width: 140px;">
                                <option value="active" <?= (($d['status'] ?? 'inactive') === 'active') ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?= (($d['status'] ?? 'inactive') === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                            <button type="submit" class="btn btn-sm btn-primary">Save</button>
                        </form>
                    </details>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</main>

</body>
</html>