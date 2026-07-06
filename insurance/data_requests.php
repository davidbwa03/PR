<?php
// insurance/data_requests.php
session_start();
require_once 'db.php';

if (!isset($_SESSION['insurer_id'])) {
    header("Location: login.php");
    exit();
}

if (!isset($_SESSION['insurer_2fa_verified']) || $_SESSION['insurer_2fa_verified'] !== true) {
    header("Location: verify-2fa.php");
    exit();
}

$insurance_name = isset($_SESSION['insurer_name']) ? $_SESSION['insurer_name'] : 'Insurance Provider';
$insurance_id   = isset($_SESSION['insurer_id']) ? (int) $_SESSION['insurer_id'] : 0;

$reviewed_date_column = 'reviewed_date';
$rejection_reason_column = 'rejection_reason';

try {
    $claim_columns = $pdo->query("SHOW COLUMNS FROM claims")->fetchAll(PDO::FETCH_COLUMN);
    if (in_array('reviewed_at', $claim_columns, true)) {
        $reviewed_date_column = 'reviewed_at';
    }
    if (in_array('decline_reason', $claim_columns, true)) {
        $rejection_reason_column = 'decline_reason';
    }
} catch (PDOException $e) {
    // Keep default schema column names when metadata lookup fails.
}

$success_msg = "";
$error_msg   = "";

// Handle approve / decline actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['claim_action'], $_POST['claim_id'])) {
    $claim_id     = (int) $_POST['claim_id'];
    $claim_action = $_POST['claim_action'];
    $decline_reason = trim($_POST['decline_reason'] ?? '');

    $allowed_actions = ['approve' => 'Approved', 'decline' => 'Rejected'];

    if ($claim_id <= 0 || !isset($allowed_actions[$claim_action])) {
        $error_msg = "Invalid claim action.";
    } elseif ($claim_action === 'decline' && $decline_reason === '') {
        $error_msg = "Please provide a reason for declining this claim.";
    } else {
        try {
            $stmt_check = $pdo->prepare("SELECT claim_id, status, claim_number FROM claims WHERE claim_id = ? LIMIT 1");
            $stmt_check->execute([$claim_id]);
            $claim_row = $stmt_check->fetch(PDO::FETCH_ASSOC);

            if (!$claim_row) {
                $error_msg = "Claim not found.";
            } elseif (strtolower(trim((string) $claim_row['status'])) !== 'pending') {
                $error_msg = "This claim has already been reviewed.";
            } else {
                $new_status = $allowed_actions[$claim_action];

                if ($claim_action === 'decline') {
                    $stmt_update = $pdo->prepare(
                        "UPDATE claims
                            SET status = ?, `{$rejection_reason_column}` = ?, reviewed_by = ?, `{$reviewed_date_column}` = NOW(), updated_at = NOW()
                          WHERE claim_id = ?"
                    );
                    $stmt_update->execute([$new_status, $decline_reason, $insurance_id, $claim_id]);
                } else {
                    $stmt_update = $pdo->prepare(
                        "UPDATE claims
                            SET status = ?, reviewed_by = ?, `{$reviewed_date_column}` = NOW(), updated_at = NOW()
                          WHERE claim_id = ?"
                    );
                    $stmt_update->execute([$new_status, $insurance_id, $claim_id]);
                }

                $success_msg = "Claim " . htmlspecialchars($claim_row['claim_number']) . " has been " . strtolower($new_status) . ".";
            }
        } catch (PDOException $e) {
            error_log('Claim review failed: ' . $e->getMessage());
            $error_msg = "Could not update the claim. Please try again.";
        }
    }
}

// Pending claims awaiting a decision
$pending_claims = [];
try {
    $stmt_pending = $pdo->query(
        "SELECT c.claim_id, c.claim_number, c.claim_amount, c.claim_reason, c.status, c.submitted_date,
                p.id AS patient_id, p.name AS patient_name, p.national_id,
                h.id AS hospital_id, h.name AS hospital_name
           FROM claims c
           LEFT JOIN patients p ON p.id = c.patient_id
           LEFT JOIN hospitals h ON h.id = c.hospital_id
            WHERE LOWER(TRIM(c.status)) = 'pending'
          ORDER BY c.submitted_date ASC"
    );
    $pending_claims = $stmt_pending->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Pending claims fetch failed: ' . $e->getMessage());
    $pending_claims = [];
}

// Recently reviewed claims (last 8)
$reviewed_claims = [];
try {
    $stmt_reviewed = $pdo->query(
        "SELECT c.claim_id, c.claim_number, c.claim_amount, c.claim_reason, c.status, c.submitted_date,
                c.`{$rejection_reason_column}` AS rejection_reason, c.`{$reviewed_date_column}` AS reviewed_at,
                p.name AS patient_name,
                h.name AS hospital_name
           FROM claims c
           LEFT JOIN patients p ON p.id = c.patient_id
           LEFT JOIN hospitals h ON h.id = c.hospital_id
            WHERE LOWER(TRIM(c.status)) IN ('approved', 'rejected', 'declined')
            ORDER BY c.`{$reviewed_date_column}` DESC
          LIMIT 8"
    );
    $reviewed_claims = $stmt_reviewed->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Reviewed claims fetch failed: ' . $e->getMessage());
    $reviewed_claims = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Claim Requests - <?= htmlspecialchars($insurance_name); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --main-bg: #f4f6f8;
            --sidebar-bg: #0f2c3d;
            --sidebar-bg-light: #16384c;
            --accent: #1a7fa3;
            --text-main: #1e293b;
            --text-sub: #64748b;
            --border-light: #e2e8f0;
        }

        body { background-color: var(--main-bg); font-family: system-ui, -apple-system, sans-serif; color: var(--text-main); overflow-x: hidden; display: flex; min-height: 100vh; }
        .sidebar-container { width: 230px; background: var(--sidebar-bg); color: #fff; display: flex; flex-direction: column; padding: 20px 14px; flex-shrink: 0; }
        .sidebar-brand { display: flex; align-items: center; gap: 10px; padding: 6px 10px 22px 10px; margin-bottom: 0; }
        .brand-avatar { width: 30px; height: 30px; border-radius: 7px; background: var(--accent); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 14px; }
        .brand-title h1 { font-size: 14px; font-weight: 600; margin: 0; color: #fff; }
        .brand-title span { font-size: 11px; color: #9fb3c0; display: block; }
        .sidebar-menu { display: flex; flex-direction: column; gap: 6px; margin-top: 6px; }
        .menu-link { display: flex; align-items: center; gap: 10px; color: #c7d6df; text-decoration: none; font-size: 13.5px; padding: 10px 12px; border-radius: 7px; transition: all 0.2s ease; }
        .menu-link svg { width: 16px; height: 16px; flex-shrink: 0; }
        .menu-link.active { background: var(--accent); color: #fff; font-weight: 500; }
        .menu-link:hover:not(.active) { background: var(--sidebar-bg-light); color: #fff; }
        .sidebar-footer { margin-top: auto; }
        .logout-link { display: flex; align-items: center; gap: 8px; color: #9fb3c0; font-size: 13px; text-decoration: none; padding: 10px 12px; border-radius: 7px; }
        .logout-link:hover { background: var(--sidebar-bg-light); color: #fff; }
        .logout-link svg { width: 16px; height: 16px; flex-shrink: 0; }
        .workspace { flex: 1; padding: 40px 48px; }
        .hospital-header h2 { font-size: 26px; font-weight: 700; color: #0f172a; margin: 0 0 4px 0; }
        .hospital-header p { font-size: 13px; color: var(--text-sub); margin-bottom: 32px; }
        .panel-card { background: #ffffff; border: 1px solid var(--border-light); border-radius: 10px; padding: 28px; height: 100%; }
        .panel-title { font-size: 16px; font-weight: 700; color: var(--text-main); margin-bottom: 2px; }
        .panel-subtitle { font-size: 12px; color: var(--text-sub); margin-bottom: 24px; }
        .request-row { padding: 16px 0; border-bottom: 1px solid #f1f5f9; }
        .request-row:last-child { border-bottom: none; padding-bottom: 0; }
        .request-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 4px; }
        .request-title { font-size: 14px; font-weight: 700; color: var(--text-main); }
        .request-id { font-size: 11px; color: var(--text-sub); margin-bottom: 6px; }
        .request-details { font-size: 12px; color: var(--text-sub); line-height: 1.6; }
        .request-details span { color: var(--text-main); font-weight: 500; }
        .custom-badge { font-size: 11px; font-weight: 600; padding: 4px 10px; border-radius: 4px; display: inline-block; }
        .badge-approved { background-color: #f0fdf4; color: #16a34a; }
        .badge-pending { background-color: #fffbeb; color: #d97706; }
        .badge-rejected { background-color: #fef2f2; color: #ef4444; }
        .empty-note { font-size: 13px; color: var(--text-sub); text-align: center; padding: 24px 0; }
        .claim-actions { display: flex; gap: 8px; margin-top: 12px; }
        .btn-approve { background-color: #16a34a; border: none; color: #fff; font-size: 12px; font-weight: 600; padding: 6px 14px; border-radius: 6px; }
        .btn-approve:hover { background-color: #15803d; color: #fff; }
        .btn-decline { background-color: #ef4444; border: none; color: #fff; font-size: 12px; font-weight: 600; padding: 6px 14px; border-radius: 6px; }
        .btn-decline:hover { background-color: #dc2626; color: #fff; }
        .decline-reason-box { display: none; margin-top: 10px; }
        .decline-note { font-size: 12px; color: #ef4444; margin-top: 6px; }
    </style>
</head>
<body>
    <nav class="sidebar-container">
        <div>
            <div class="sidebar-brand">
                <div class="brand-avatar">I</div>
                <div class="brand-title"><h1><?= htmlspecialchars($insurance_name); ?></h1><span>Portal</span></div>
            </div>
            <div class="sidebar-menu">
                <a href="data_requests.php" class="menu-link active">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 2h6l1 4H8l1-4Z"/><path d="M5 6h14l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6Z"/></svg>
                    Data Requests
                </a>
                <a href="dashboard.php" class="menu-link">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
                    Claims Dashboard
                </a>
                <a href="system_activity.php" class="menu-link">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.87l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.7 1.7 0 0 0-1.87-.34 1.7 1.7 0 0 0-1.04 1.56V21a2 2 0 1 1-4 0v-.09A1.7 1.7 0 0 0 9 19.4a1.7 1.7 0 0 0-1.87.34l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.7 1.7 0 0 0 .34-1.87 1.7 1.7 0 0 0-1.56-1.04H3a2 2 0 1 1 0-4h.09A1.7 1.7 0 0 0 4.6 9a1.7 1.7 0 0 0-.34-1.87l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1.04-1.56V3a2 2 0 1 1 4 0v.09c0 .68.39 1.3 1.04 1.56.6.24 1.31.12 1.87-.34l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06c-.46.46-.58 1.17-.34 1.87.24.6.86 1.04 1.56 1.04H21a2 2 0 1 1 0 4h-.09c-.68 0-1.3.39-1.56 1.04Z"/></svg>
                    System Activity
                </a>
            </div>
        </div>
        <div class="sidebar-footer">
            <a href="logout.php" class="logout-link">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/></svg>
                Sign Out
            </a>
        </div>
    </nav>

    <main class="workspace">
        <div class="hospital-header">
            <h2>Claim Requests</h2>
            <p><?= htmlspecialchars($insurance_name); ?> &mdash; Review and decide on submitted insurance claims</p>
        </div>

        <?php if (!empty($success_msg)): ?>
            <div class="alert alert-info mb-4"><?= htmlspecialchars($success_msg); ?></div>
        <?php endif; ?>
        <?php if (!empty($error_msg)): ?>
            <div class="alert alert-danger mb-4"><?= htmlspecialchars($error_msg); ?></div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-lg-7">
                <div class="panel-card mb-4">
                    <h2 class="panel-title">Pending Claims</h2>
                    <p class="panel-subtitle">Claims awaiting your approval or decline</p>

                    <?php if (!empty($pending_claims)): ?>
                        <?php foreach ($pending_claims as $claim): ?>
                            <div class="request-row">
                                <div class="request-header">
                                    <div class="request-title"><?= htmlspecialchars($claim['claim_number'] ?? ('Claim #' . $claim['claim_id'])); ?></div>
                                    <span class="custom-badge badge-pending">Pending</span>
                                </div>
                                <div class="request-id">
                                    Patient: <?= htmlspecialchars($claim['patient_name'] ?? ('ID ' . $claim['patient_id'])); ?>
                                    &middot; Hospital: <?= htmlspecialchars($claim['hospital_name'] ?? 'N/A'); ?>
                                </div>
                                <div class="request-details">
                                    Amount: <span>Ksh <?= number_format((float) $claim['claim_amount'], 2); ?></span><br>
                                    Reason: <span><?= htmlspecialchars($claim['claim_reason']); ?></span><br>
                                    Submitted: <span><?= htmlspecialchars(date('M d, Y', strtotime($claim['submitted_date']))); ?></span>
                                </div>

                                <form action="data_requests.php" method="POST" class="claim-actions" data-claim-form>
                                    <input type="hidden" name="claim_id" value="<?= (int) $claim['claim_id']; ?>">
                                    <button type="submit" name="claim_action" value="approve" class="btn btn-approve">Approve</button>
                                    <button type="button" class="btn btn-decline" data-toggle-decline>Decline</button>
                                </form>

                                <div class="decline-reason-box">
                                    <form action="data_requests.php" method="POST">
                                        <input type="hidden" name="claim_id" value="<?= (int) $claim['claim_id']; ?>">
                                        <input type="hidden" name="claim_action" value="decline">
                                        <textarea name="decline_reason" class="form-control form-control-sm" rows="2" placeholder="Reason for declining this claim" required></textarea>
                                        <div class="d-flex gap-2 mt-2">
                                            <button type="submit" class="btn btn-decline">Confirm Decline</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-note">No pending claims to review.</div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="panel-card">
                    <h2 class="panel-title">Recently Reviewed Claims</h2>
                    <p class="panel-subtitle">Last 8 claims you have approved or declined</p>

                    <?php if (!empty($reviewed_claims)): ?>
                        <?php foreach ($reviewed_claims as $claim): ?>
                            <?php
                                $status = strtolower($claim['status'] ?? 'pending');
                                $badge_class = $status === 'approved' ? 'badge-approved' : 'badge-rejected';
                            ?>
                            <div class="request-row">
                                <div class="request-header">
                                    <div class="request-title"><?= htmlspecialchars($claim['claim_number'] ?? ('Claim #' . $claim['claim_id'])); ?></div>
                                    <span class="custom-badge <?= $badge_class; ?>"><?= htmlspecialchars(ucfirst($status)); ?></span>
                                </div>
                                <div class="request-id">
                                    Patient: <?= htmlspecialchars($claim['patient_name'] ?? 'N/A'); ?>
                                    &middot; Hospital: <?= htmlspecialchars($claim['hospital_name'] ?? 'N/A'); ?>
                                </div>
                                <div class="request-details">
                                    Amount: <span>Ksh <?= number_format((float) $claim['claim_amount'], 2); ?></span><br>
                                    Reason: <span><?= htmlspecialchars($claim['claim_reason']); ?></span>
                                    <?php if ($status === 'rejected' && !empty($claim['rejection_reason'])): ?>
                                        <br><span class="decline-note">Decline reason: <?= htmlspecialchars($claim['rejection_reason']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-note">No claims reviewed yet.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.querySelectorAll('[data-toggle-decline]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var box = btn.closest('.request-row').querySelector('.decline-reason-box');
                box.style.display = box.style.display === 'block' ? 'none' : 'block';
            });
        });
    </script>
</body>
</html>