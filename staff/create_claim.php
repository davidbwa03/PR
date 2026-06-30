<?php
// staff/create_claim.php
session_start();
require_once 'db.php';

if (!isset($_SESSION['staff_logged_in']) || $_SESSION['staff_logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

$staff_name = isset($_SESSION['staff_name']) ? $_SESSION['staff_name'] : 'Administrator';
$staff_id   = isset($_SESSION['staff_id']) ? (int) $_SESSION['staff_id'] : 0;

function normalizeHospitalName(string $name): string
{
    $name = strtolower(trim($name));
    $name = preg_replace('/[^a-z0-9\s]/', '', $name) ?? $name;
    $name = preg_replace('/\s+/', ' ', $name) ?? $name;
    return trim($name);
}

function resolveHospitalIdByName(PDO $pdo, string $hospitalName): int
{
    $hospitalName = trim($hospitalName);
    if ($hospitalName === '') {
        return 0;
    }

    $stmtExact = $pdo->prepare("SELECT id FROM hospitals WHERE LOWER(name) = LOWER(?) LIMIT 1");
    $stmtExact->execute([$hospitalName]);
    $exactId = (int) $stmtExact->fetchColumn();
    if ($exactId > 0) {
        return $exactId;
    }

    $normalizedTarget = normalizeHospitalName($hospitalName);
    $stmtAll = $pdo->query("SELECT id, name FROM hospitals");
    $rows = $stmtAll->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $candidateName = trim((string) ($row['name'] ?? ''));
        if ($candidateName === '') {
            continue;
        }

        $normalizedCandidate = normalizeHospitalName($candidateName);
        if (
            $normalizedCandidate === $normalizedTarget ||
            str_contains($normalizedCandidate, $normalizedTarget) ||
            str_contains($normalizedTarget, $normalizedCandidate)
        ) {
            return (int) ($row['id'] ?? 0);
        }
    }

    return 0;
}

function ensureHospitalIdFromStaff(PDO $pdo, array $staff): int
{
    $hospitalName = trim((string) ($staff['hospital_name'] ?? ''));
    if ($hospitalName === '') {
        return 0;
    }

    $resolvedId = resolveHospitalIdByName($pdo, $hospitalName);
    if ($resolvedId > 0) {
        return $resolvedId;
    }

    $email = trim((string) ($staff['email'] ?? ''));
    $phone = trim((string) ($staff['phone'] ?? ''));
    $county = trim((string) ($staff['county'] ?? ''));
    $address = trim((string) ($staff['address'] ?? ''));

    if ($email !== '') {
        $stmtByEmail = $pdo->prepare("SELECT id FROM hospitals WHERE LOWER(email) = LOWER(?) LIMIT 1");
        $stmtByEmail->execute([$email]);
        $idByEmail = (int) $stmtByEmail->fetchColumn();
        if ($idByEmail > 0) {
            return $idByEmail;
        }
    }

    if ($email === '' || $phone === '' || $county === '' || $address === '') {
        return 0;
    }

    try {
        $insertHospital = $pdo->prepare(
            "INSERT INTO hospitals (name, email, phone, county, address, status) VALUES (?, ?, ?, ?, ?, 'Active')"
        );
        $insertHospital->execute([$hospitalName, $email, $phone, $county, $address]);
        return (int) $pdo->lastInsertId();
    } catch (PDOException $e) {
        // Handle possible unique key collisions by resolving again.
        $retryId = resolveHospitalIdByName($pdo, $hospitalName);
        if ($retryId > 0) {
            return $retryId;
        }
        if ($email !== '') {
            $stmtByEmail = $pdo->prepare("SELECT id FROM hospitals WHERE LOWER(email) = LOWER(?) LIMIT 1");
            $stmtByEmail->execute([$email]);
            return (int) $stmtByEmail->fetchColumn();
        }
        return 0;
    }
}

$hospital_brand_name = 'Hospital';
$hospital_id = 0;
try {
    if ($staff_id > 0) {
        $stmt_hospital = $pdo->prepare("SELECT hospital_name, email, phone, county, address FROM staff WHERE id = ? LIMIT 1");
        $stmt_hospital->execute([$staff_id]);
        $staff_row = $stmt_hospital->fetch(PDO::FETCH_ASSOC) ?: [];
        $staff_hospital_name = trim((string) ($staff_row['hospital_name'] ?? ''));

        if ($staff_hospital_name !== '') {
            $hospital_brand_name = $staff_hospital_name;
            $hospital_id = ensureHospitalIdFromStaff($pdo, $staff_row);
        }
    }
} catch (PDOException $e) {
    // fall back to defaults
}

$success_msg = "";
$error_msg   = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_claim'])) {
    $patient_id_input  = trim($_POST['patient_id_input'] ?? '');
    $submission_date   = trim($_POST['submission_date'] ?? '');
    $claim_amount       = trim($_POST['claim_amount'] ?? '');
    $claim_reason        = trim($_POST['claim_reason'] ?? '');

    $patient_id = null;
    $patient_ref_match = [];

    if (preg_match('/^PT-\d{4}-(\d+)$/i', $patient_id_input, $patient_ref_match)) {
        $patient_id = (int) $patient_ref_match[1];
    }

    if (empty($patient_id_input) || $submission_date === '' || $claim_amount === '' || empty($claim_reason)) {
        $error_msg = "Please fill in patient reference, submission date, claim amount, and claim reason.";
    } elseif (!is_numeric($claim_amount) || (float) $claim_amount <= 0) {
        $error_msg = "Claim amount must be a valid positive number.";
    } else {
        try {
            $valid_submission_date = DateTime::createFromFormat('Y-m-d', $submission_date);
            if (!$valid_submission_date || $valid_submission_date->format('Y-m-d') !== $submission_date) {
                throw new InvalidArgumentException('Invalid submission date');
            }

            if ($patient_id !== null && $patient_id > 0) {
                $stmt_patient = $pdo->prepare("SELECT id FROM patients WHERE id = ? LIMIT 1");
                $stmt_patient->execute([$patient_id]);
            } elseif (ctype_digit($patient_id_input)) {
                // Numeric input can be either Patient DB ID or National ID.
                $stmt_patient = $pdo->prepare("SELECT id FROM patients WHERE id = ? OR national_id = ? LIMIT 1");
                $stmt_patient->execute([(int) $patient_id_input, $patient_id_input]);
            } else {
                $stmt_patient = $pdo->prepare("SELECT id FROM patients WHERE national_id = ? OR LOWER(email) = LOWER(?) LIMIT 1");
                $stmt_patient->execute([$patient_id_input, $patient_id_input]);
            }
            $resolved_patient_id = $stmt_patient->fetchColumn();

            if (!$resolved_patient_id) {
                $error_msg = "No patient matched the provided reference. Use Patient DB ID, National ID, Email, or PT-YYYY-ID.";
            } elseif ($hospital_id <= 0) {
                $error_msg = "Your staff hospital ('" . htmlspecialchars($hospital_brand_name, ENT_QUOTES, 'UTF-8') . "') does not match a hospital in Manage Hospitals.";
            } else {
                $stmt_hospital_exists = $pdo->prepare("SELECT id FROM hospitals WHERE id = ? LIMIT 1");
                $stmt_hospital_exists->execute([$hospital_id]);
                $resolved_hospital_id = $stmt_hospital_exists->fetchColumn();

                if (!$resolved_hospital_id) {
                    throw new RuntimeException('Invalid hospital_id on staff account');
                }
               
                $claim_number = 'CLM-' . date('Y') . '-' . str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT);

                $stmt = $pdo->prepare(
                    "INSERT INTO claims
                        (claim_number, patient_id, hospital_id, medical_record_id, claim_amount, claim_reason, status, submitted_date, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, 'Pending', ?, NOW(), NOW())"
                );
                $stmt->execute([
                    $claim_number,
                    (int) $resolved_patient_id,
                    $hospital_id > 0 ? $hospital_id : null,
                    null,
                    (float) $claim_amount,
                    $claim_reason,
                    $submission_date,
                ]);

                $success_msg = "Claim " . htmlspecialchars($claim_number) . " submitted successfully and is pending review.";
            }
        } catch (Throwable $e) {
            error_log('Claim submission failed: ' . $e->getMessage());
            $error_msg = "Could not submit claim. Please verify the details and try again.";
        }
    }
}

// Recent claims submitted by this hospital (best-effort, ignores failures)
$recent_claims = [];
try {
    if ($hospital_id > 0) {
        $stmt_recent = $pdo->prepare(
            "SELECT claim_id, claim_number, patient_id, claim_amount, claim_reason, status, submitted_date
             FROM claims WHERE hospital_id = ? ORDER BY submitted_date DESC LIMIT 8"
        );
        $stmt_recent->execute([$hospital_id]);
    } else {
        $stmt_recent = $pdo->query(
            "SELECT claim_id, claim_number, patient_id, claim_amount, claim_reason, status, submitted_date
             FROM claims ORDER BY submitted_date DESC LIMIT 8"
        );
    }
    $recent_claims = $stmt_recent->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $recent_claims = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Make a Claim - <?= htmlspecialchars($hospital_brand_name); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
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
                <a href="manage_practitioners.php" class="menu-link">Manage Doctors</a>
                <a href="create_claim.php" class="menu-link active">Create the claim</a>
                <a href="analytics.php" class="menu-link">Analytics</a>
            </div>
        </div>
        <div><a href="logout.php" class="logout-link">Sign Out</a></div>
    </nav>

    <main class="workspace">
        <div class="hospital-header">
            <h2>Make a Claim</h2>
            <p><?= htmlspecialchars($hospital_brand_name); ?> &mdash; Submit an insurance claim for a patient</p>
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
                    <h2 class="panel-title">Claim Details</h2>
                    <p class="panel-subtitle">Patient reference, submission date, and claim amount</p>

                    <form action="create_claim.php" method="POST" class="row g-3">
                        <div class="col-12">
                            <label class="form-label" style="font-size:13px; font-weight:600;">Patient Reference</label>
                            <input type="text" name="patient_id_input" class="form-control" placeholder="Patient DB ID, National ID, Email, or PT-YYYY-ID" required>
                        </div>

                        <div class="col-12">
                            <label class="form-label" style="font-size:13px; font-weight:600;">Submission Date</label>
                            <input type="date" name="submission_date" class="form-control" value="<?= htmlspecialchars(date('Y-m-d')); ?>" required>
                        </div>

                        <div class="col-12">
                            <label class="form-label" style="font-size:13px; font-weight:600;">Claim Amount</label>
                            <div class="input-group">
                                <span class="input-group-text">Ksh</span>
                                <input type="number" step="0.01" min="0.01" name="claim_amount" class="form-control" placeholder="0.00" required>
                            </div>
                        </div>

                        <div class="col-12">
                            <label class="form-label" style="font-size:13px; font-weight:600;">Claim Reason</label>
                            <textarea name="claim_reason" class="form-control" rows="4" placeholder="Describe the treatment, diagnosis, or procedure this claim is for" required></textarea>
                        </div>

                        <div class="col-12">
                            <button type="submit" name="submit_claim" class="btn btn-primary w-100" style="background-color: var(--teal-accent); border: none;">Submit Claim</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="panel-card">
                    <h2 class="panel-title">Recently Submitted Claims</h2>
                    <p class="panel-subtitle">Last 8 claims from this hospital</p>

                    <?php if (!empty($recent_claims)): ?>
                        <?php foreach ($recent_claims as $claim): ?>
                            <?php
                                $status = strtolower($claim['status'] ?? 'pending');
                                $badge_class = 'badge-pending';
                                if ($status === 'approved') $badge_class = 'badge-approved';
                                if ($status === 'rejected' || $status === 'declined') $badge_class = 'badge-rejected';
                            ?>
                            <div class="request-row">
                                <div class="request-header">
                                    <div class="request-title"><?= htmlspecialchars($claim['claim_number'] ?? ('Claim #' . $claim['claim_id'])); ?></div>
                                    <span class="custom-badge <?= $badge_class; ?>"><?= htmlspecialchars(ucfirst($status)); ?></span>
                                </div>
                                <div class="request-id">Patient ID: <?= htmlspecialchars($claim['patient_id']); ?></div>
                                <div class="request-details">
                                    Amount: <span>Ksh <?= number_format((float) $claim['claim_amount'], 2); ?></span><br>
                                    Reason: <span><?= htmlspecialchars($claim['claim_reason']); ?></span><br>
                                    Submitted: <span><?= htmlspecialchars(date('M d, Y', strtotime($claim['submitted_date']))); ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-note">No claims submitted yet.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>