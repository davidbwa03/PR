<?php
// staff/patient_requests.php
session_start();
require_once 'db.php';

if (!isset($_SESSION['staff_logged_in']) || $_SESSION['staff_logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

$staff_name = isset($_SESSION['staff_name']) ? $_SESSION['staff_name'] : 'Administrator';
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

// Staff cannot approve or decline requests here — only the patient can.
// This page is read-only: just the patient's name and their current status.

try {
    $stmt_requests = $pdo->query("
        SELECT ar.id, ar.patient_id, ar.request_status,
               ar.requested_at,
               ar.updated_at,
               p.name AS patient_name,
               p.email AS patient_email,
               p.national_id AS patient_national_id,
               p.phone AS patient_phone,
               p.dob AS patient_dob,
               p.gender AS patient_gender
        FROM access_requests ar
        LEFT JOIN patients p ON p.id = ar.patient_id
        ORDER BY ar.requested_at DESC
    ");
    $all_requests = $stmt_requests->fetchAll(PDO::FETCH_ASSOC);
} catch (\PDOException $e) {
    $all_requests = [];
}

function statusBadge($status) {
    $status = strtolower((string)$status);
    if ($status === 'approved') {
        return ['class' => 'badge-approved', 'icon' => 'bi-check-circle-fill', 'label' => 'Approved'];
    } elseif ($status === 'declined') {
        return ['class' => 'badge-declined', 'icon' => 'bi-x-circle-fill', 'label' => 'Declined'];
    }
    return ['class' => 'badge-pending', 'icon' => 'bi-clock-fill', 'label' => 'Pending'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Requests - Central Medical Center</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --sidebar-width: 260px;
            --main-bg: #f4f6f8;
            --sidebar-bg: #ffffff;
            --text-main: #1e293b;
            --text-sub: #64748b;
            --teal-accent: #107c91;
            --border-light: #e2e8f0;
        }

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
        .hospital-header h2 { font-size: 26px; font-weight: 700; color: #0f172a; margin: 0 0 4px 0; }
        .hospital-header p { font-size: 13px; color: var(--text-sub); margin-bottom: 32px; }
        .panel-card { background: #ffffff; border: 1px solid var(--border-light); border-radius: 10px; padding: 28px; height: 100%; }
        .custom-badge { font-size: 11px; font-weight: 600; padding: 4px 10px; border-radius: 4px; display: inline-block; }
        .badge-approved { background-color: #f0fdf4; color: #16a34a; }
        .badge-pending { background-color: #fffbeb; color: #d97706; }
        .badge-declined { background-color: #fef2f2; color: #ef4444; }

        /* Patient request cards */
        .pr-card { background: #ffffff; border: 1px solid var(--border-light); border-radius: 14px; padding: 24px; height: 100%; display: flex; flex-direction: column; align-items: flex-start; gap: 14px; }
        .pr-name { font-size: 16px; font-weight: 700; color: var(--text-main); margin: 0; }
        .pr-badge { font-size: 12px; font-weight: 600; padding: 6px 14px; border-radius: 20px; display: inline-flex; align-items: center; gap: 6px; white-space: nowrap; }
        .pr-details { font-size: 12px; color: var(--text-sub); line-height: 1.7; margin: 0; }
        .pr-details span { color: var(--text-main); font-weight: 600; }
        .pr-hidden { font-size: 12px; color: var(--text-sub); margin: 0; }
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
                <a href="patient_requests.php" class="menu-link active">Patient Requests</a>
                <a href="send_records.php" class="menu-link">Send Records to Doctors</a>
                <a href="update_vitals.php" class="menu-link">Update Patient Vitals</a>
                <a href="add_practitioner.php" class="menu-link">Add Practitioners</a>
                <a href="manage_practitioners.php" class="menu-link">Manage Doctors</a>
                <a href="create_claim.php" class="menu-link">Create the claim</a>
                <a href="analytics.php" class="menu-link">Analytics</a>
            </div>
        </div>
        <div><a href="logout.php" class="logout-link">Sign Out</a></div>
    </nav>

    <main class="workspace">
        <div class="hospital-header">
            <h2>Patient Data Requests</h2>
            <p>Patient details are visible only after the patient approves access.</p>
        </div>

        <?php if (empty($all_requests)): ?>
            <div class="panel-card text-center text-muted">No patient data requests yet.</div>
        <?php else: ?>
        <div class="row g-4">
            <?php foreach ($all_requests as $req):
                $badge = statusBadge($req['request_status']);
                $displayName = $req['patient_name'] ?: ('Patient #' . $req['patient_id']);
                $isApproved = strtolower((string)$req['request_status']) === 'approved';
                $approvalTime = !empty($req['updated_at']) ? strtotime((string)$req['updated_at']) : false;
                $detailsExpired = $isApproved && $approvalTime !== false && (time() - $approvalTime) > (48 * 60 * 60);
                $requestSentAt = !empty($req['requested_at']) ? date('Y-m-d H:i', strtotime((string)$req['requested_at'])) : 'N/A';
            ?>
            <div class="col-12 col-md-6 col-lg-4">
                <div class="pr-card">
                    <p class="pr-hidden">Request sent: <?= htmlspecialchars($requestSentAt); ?></p>
                    <?php if ($isApproved && !$detailsExpired): ?>
                        <h3 class="pr-name"><?= htmlspecialchars($displayName); ?></h3>
                        <p class="pr-details">
                            National ID: <span><?= htmlspecialchars($req['patient_national_id'] ?: 'N/A'); ?></span><br>
                            Email: <span><?= htmlspecialchars($req['patient_email'] ?: 'N/A'); ?></span><br>
                            Phone: <span><?= htmlspecialchars($req['patient_phone'] ?: 'N/A'); ?></span><br>
                            DOB: <span><?= htmlspecialchars($req['patient_dob'] ?: 'N/A'); ?></span><br>
                            Gender: <span><?= htmlspecialchars($req['patient_gender'] ?: 'N/A'); ?></span>
                        </p>
                    <?php else: ?>
                        <?php if ($isApproved && $detailsExpired): ?>
                            <h3 class="pr-name"><?= htmlspecialchars($displayName); ?></h3>
                            <p class="pr-hidden">Patient details have expired after 48hrs.</p>
                        <?php elseif (strtolower((string)$req['request_status']) === 'declined'): ?>
                            <h3 class="pr-name"><?= htmlspecialchars($displayName); ?></h3>
                            <p class="pr-hidden">Patient declined this request. No details are visible.</p>
                        <?php else: ?>
                            <h3 class="pr-name"><?= htmlspecialchars($displayName); ?></h3>
                        <?php endif; ?>
                    <?php endif; ?>
                    <span class="pr-badge <?= $badge['class']; ?>"><i class="bi <?= $badge['icon']; ?>"></i> <?= $badge['label']; ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </main>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>