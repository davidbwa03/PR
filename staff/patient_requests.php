<?php
// staff/patient_requests.php
session_start();
require_once 'db.php';

if (!isset($_SESSION['staff_logged_in']) || $_SESSION['staff_logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

$staff_name = isset($_SESSION['staff_name']) ? $_SESSION['staff_name'] : 'Administrator';
$success_msg = "";
$error_msg = "";

// Handle approve / decline actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['approve_request'])) {
        $request_id = (int)$_POST['request_id'];
        if ($request_id > 0) {
            $stmt = $pdo->prepare("UPDATE access_requests SET request_status = 'approved', updated_at = NOW() WHERE id = ?");
            $stmt->execute([$request_id]);
            $success_msg = "Request approved. You can now send the records from the \"Send Records to Doctors\" page.";
        }
    } elseif (isset($_POST['decline_request'])) {
        $request_id = (int)$_POST['request_id'];
        if ($request_id > 0) {
            $stmt = $pdo->prepare("UPDATE access_requests SET request_status = 'declined', updated_at = NOW() WHERE id = ?");
            $stmt->execute([$request_id]);
            $success_msg = "Request declined.";
        }
    }
}

// Fetch all requests, joined with patient details
try {
    $stmt_requests = $pdo->query("
        SELECT ar.id, ar.patient_id, ar.doctor_name, ar.medical_facility, ar.request_status,
               ar.requested_at, ar.updated_at, ar.records_sent,
               p.name AS patient_name, p.national_id AS patient_national_id
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
        .pr-card { background: #ffffff; border: 1px solid var(--border-light); border-radius: 14px; padding: 24px; height: 100%; display: flex; flex-direction: column; justify-content: space-between; gap: 20px; }
        .pr-card-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; margin-bottom: 4px; }
        .pr-name { font-size: 16px; font-weight: 700; color: var(--text-main); margin: 0; }
        .pr-meta { font-size: 13px; color: var(--text-sub); margin: 2px 0; }
        .pr-meta span { color: var(--text-main); font-weight: 600; }
        .pr-badge { font-size: 11px; font-weight: 600; padding: 6px 12px; border-radius: 20px; display: inline-flex; align-items: center; gap: 6px; white-space: nowrap; }
        .pr-view-btn { background-color: var(--teal-accent); color: #ffffff; border: none; border-radius: 30px; padding: 12px; font-weight: 600; font-size: 14px; display: flex; align-items: center; justify-content: center; gap: 8px; width: 100%; }
        .pr-view-btn:hover { opacity: 0.92; color: #ffffff; }
    </style>
</head>
<body>
    <nav class="sidebar-container">
        <div class="w-100">
            <div class="sidebar-brand">
                <div class="brand-avatar">H</div>
                <div class="brand-title"><h1>Hospital Admin</h1><span>Portal</span></div>
            </div>
            <div class="sidebar-menu">
                <a href="dashboard.php" class="menu-link">Overview</a>
                <a href="patient_requests.php" class="menu-link active">Patient Requests</a>
                <a href="send_records.php" class="menu-link">Send Records to Doctors</a>
                <a href="add_practitioner.php" class="menu-link">Add Practitioners</a>
                <a href="manage_practitioners.php" class="menu-link">Manage Doctors</a>
                <a href="analytics.php" class="menu-link">Analytics</a>
            </div>
        </div>
        <div><a href="logout.php" class="logout-link">Sign Out</a></div>
    </nav>

    <main class="workspace">
        <div class="hospital-header">
            <h2>Patient Data Requests</h2>
            <p>Review incoming record requests from doctors and other facilities</p>
        </div>

        <?php if (!empty($success_msg)): ?>
            <div class="alert alert-info mb-4"><?= htmlspecialchars($success_msg); ?></div>
        <?php endif; ?>
        <?php if (!empty($error_msg)): ?>
            <div class="alert alert-danger mb-4"><?= htmlspecialchars($error_msg); ?></div>
        <?php endif; ?>

        <?php if (empty($all_requests)): ?>
            <div class="panel-card text-center text-muted">No patient data requests yet.</div>
        <?php else: ?>
        <div class="row g-4">
            <?php foreach ($all_requests as $req):
                $badge = statusBadge($req['request_status']);
                $displayName = $req['patient_name'] ?: ('Patient #' . $req['patient_id']);
                $nationalId = $req['patient_national_id'] ?: '—';
                $dateStr = date('Y-m-d', strtotime($req['requested_at']));
                $statusLower = strtolower($req['request_status']);
            ?>
            <div class="col-12 col-md-6 col-lg-4">
                <div class="pr-card">
                    <div>
                        <div class="pr-card-top">
                            <h3 class="pr-name"><?= htmlspecialchars($displayName); ?></h3>
                            <span class="pr-badge <?= $badge['class']; ?>"><i class="bi <?= $badge['icon']; ?>"></i> <?= $badge['label']; ?></span>
                        </div>
                        <p class="pr-meta">National ID: <?= htmlspecialchars($nationalId); ?></p>
                        <p class="pr-meta">Date: <?= htmlspecialchars($dateStr); ?></p>
                    </div>
                    <button type="button" class="pr-view-btn" data-bs-toggle="modal" data-bs-target="#requestModal<?= (int)$req['id']; ?>">
                        <i class="bi bi-eye"></i> View Request
                    </button>
                </div>
            </div>

            <div class="modal fade" id="requestModal<?= (int)$req['id']; ?>" tabindex="-1" aria-hidden="true">
              <div class="modal-dialog">
                <div class="modal-content">
                  <div class="modal-header">
                    <h5 class="modal-title">Request Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                  </div>
                  <div class="modal-body">
                    <p class="pr-meta"><span>Patient:</span> <?= htmlspecialchars($displayName); ?></p>
                    <p class="pr-meta"><span>National ID:</span> <?= htmlspecialchars($nationalId); ?></p>
                    <p class="pr-meta"><span>Requesting Doctor:</span> <?= htmlspecialchars($req['doctor_name']); ?></p>
                    <p class="pr-meta"><span>Facility:</span> <?= htmlspecialchars($req['medical_facility']); ?></p>
                    <p class="pr-meta"><span>Requested On:</span> <?= htmlspecialchars($req['requested_at']); ?></p>
                    <p class="pr-meta"><span>Status:</span> <?= htmlspecialchars($badge['label']); ?></p>

                    <?php if ($statusLower === 'approved'): ?>
                        <?php if ((int)$req['records_sent'] === 1): ?>
                            <div class="alert alert-success mt-3 mb-0">Records already sent to the doctor.</div>
                        <?php else: ?>
                            <div class="alert alert-warning mt-3 mb-0">Approved — go to <strong>Send Records to Doctors</strong> to dispatch this patient's records.</div>
                        <?php endif; ?>
                    <?php endif; ?>
                  </div>
                  <?php if ($statusLower === 'pending'): ?>
                  <div class="modal-footer">
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="request_id" value="<?= (int)$req['id']; ?>">
                        <button type="submit" name="decline_request" class="btn btn-outline-danger">Decline</button>
                    </form>
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="request_id" value="<?= (int)$req['id']; ?>">
                        <button type="submit" name="approve_request" class="btn btn-primary" style="background-color: var(--teal-accent); border:none;">Approve</button>
                    </form>
                  </div>
                  <?php endif; ?>
                </div>
              </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </main>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>