<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Patient') {
    header("Location: login.php");
    exit();
}

require_once 'db.php'; 

try {
    $patient_id = $_SESSION['user_id']; 

    $pdo->exec("CREATE TABLE IF NOT EXISTS patient_privacy_consents (
        patient_id INT NOT NULL PRIMARY KEY,
        allergies_summary_text TEXT NULL,
        chronic_diagnostic_logs_text TEXT NULL,
        surgical_typologies_summary_text TEXT NULL,
        surgical_typologies_necessary TINYINT(1) NOT NULL DEFAULT 0,
        authored_by_doctor_id INT NULL,
        authored_by_doctor_name VARCHAR(255) NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    try { $pdo->exec("ALTER TABLE patient_privacy_consents ADD COLUMN allergies_summary_text TEXT NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE patient_privacy_consents ADD COLUMN chronic_diagnostic_logs_text TEXT NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE patient_privacy_consents ADD COLUMN surgical_typologies_summary_text TEXT NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE patient_privacy_consents ADD COLUMN surgical_typologies_necessary TINYINT(1) NOT NULL DEFAULT 0"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE patient_privacy_consents ADD COLUMN authored_by_doctor_id INT NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE patient_privacy_consents ADD COLUMN authored_by_doctor_name VARCHAR(255) NULL"); } catch (PDOException $e) {}

    $stmt_consents = $pdo->prepare("SELECT allergies_summary_text, chronic_diagnostic_logs_text, surgical_typologies_summary_text, surgical_typologies_necessary, authored_by_doctor_name, updated_at FROM patient_privacy_consents WHERE patient_id = :patient_id LIMIT 1");
    $stmt_consents->execute(['patient_id' => $patient_id]);
    $privacy_consents = $stmt_consents->fetch(PDO::FETCH_ASSOC);

    if (!$privacy_consents) {
        $privacy_consents = [
            'allergies_summary_text' => 'No doctor summary provided yet.',
            'chronic_diagnostic_logs_text' => 'No doctor summary provided yet.',
            'surgical_typologies_summary_text' => 'No doctor summary provided yet.',
            'surgical_typologies_necessary' => 0,
            'authored_by_doctor_name' => null,
            'updated_at' => null,
        ];
    }

    // 1. ✅ FIXED: Count accepted doctors from access_requests (matches privacy_settings.php)
    $stmt_docs = $pdo->prepare("SELECT COUNT(*) AS total_doctors FROM access_requests WHERE patient_id = :patient_id AND request_status = 'approved'");
    $stmt_docs->execute(['patient_id' => $patient_id]);
    $doctors_count = $stmt_docs->fetch()['total_doctors'];

    // 2. Count of integrated health records
    $stmt_records = $pdo->prepare("SELECT COUNT(*) AS total_records FROM medical_records WHERE patient_id = :patient_id");
    $stmt_records->execute(['patient_id' => $patient_id]);
    $records_count = $stmt_records->fetch()['total_records'];

    // 3. Count of distinct medications recommended/prescribed
    $stmt_meds = $pdo->prepare("SELECT COUNT(*) AS total_medications FROM medication_prescriptions WHERE patient_id = :patient_id");
    $stmt_meds->execute(['patient_id' => $patient_id]);
    $medications_count = $stmt_meds->fetch()['total_medications'];

    // 4. Fetch the 2 most recent prescriptions
    $stmt_med_list = $pdo->prepare("SELECT medication_name, dosage, frequency, prescribed_by FROM medication_prescriptions WHERE patient_id = :patient_id ORDER BY id DESC LIMIT 2");
    $stmt_med_list->execute(['patient_id' => $patient_id]);
    $medications = $stmt_med_list->fetchAll();

    // 5. Fetch the 5 most recent clinical interactions
    $stmt_audit = $pdo->prepare("SELECT visit_type, hospital_name, visit_date FROM medical_records WHERE patient_id = :patient_id ORDER BY visit_date DESC LIMIT 5");
    $stmt_audit->execute(['patient_id' => $patient_id]);
    $recent_records = $stmt_audit->fetchAll();

} catch (PDOException $e) {
    $error_msg = "An error occurred retrieving your SHIF integration analytics.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Portal | Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --sidebar-width: 260px;
            --bg-light: #f8fafc;
            --shif-teal: #0e7490;
            --shif-teal-hover: #0891b2;
            --text-dark: #1e293b;
        }

        body {
            background-color: var(--bg-light);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: var(--text-dark);
            overflow-x: hidden;
        }

        .sidebar {
            width: var(--sidebar-width);
            position: fixed;
            top: 0; bottom: 0; left: 0;
            background-color: #ffffff;
            border-right: 1px solid #e2e8f0;
            padding: 24px;
            display: flex;
            flex-direction: column;
            z-index: 100;
        }

        .sidebar-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 32px;
            padding-bottom: 16px;
            border-bottom: 1px solid #f1f5f9;
        }

        .sidebar-brand .icon-box {
            background-color: var(--shif-teal);
            color: white;
            width: 40px; height: 40px;
            border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.2rem;
        }

        .sidebar-menu { display: flex; flex-direction: column; gap: 8px; flex-grow: 1; }

        .menu-btn {
            display: flex; align-items: center; gap: 12px;
            width: 100%; padding: 12px 16px;
            background-color: var(--shif-teal);
            color: white; border: none; border-radius: 8px;
            font-weight: 500; text-align: left; text-decoration: none;
            transition: all 0.2s ease;
        }

        .menu-btn.secondary { background-color: transparent; color: #64748b; }
        .menu-btn:hover { background-color: var(--shif-teal-hover); color: white; }
        .menu-btn.secondary:hover { background-color: #f1f5f9; color: var(--text-dark); }

        .logout-btn {
            margin-top: auto; color: #64748b; text-decoration: none;
            display: flex; align-items: center; gap: 12px;
            padding: 12px 16px; border-radius: 8px; transition: background 0.2s;
        }
        .logout-btn:hover { background-color: #fef2f2; color: #ef4444; }

        .main-content { margin-left: var(--sidebar-width); padding: 40px; min-height: 100vh; }

        .card-custom {
            background: #ffffff; border: 1px solid #e2e8f0;
            border-radius: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.02);
            margin-bottom: 24px;
        }

        .control-row {
            background-color: #ffffff; border: 1px solid #f1f5f9;
            border-radius: 12px; padding: 14px 20px;
            display: block;
            margin-bottom: 12px;
        }
        .control-row summary {
            list-style: none;
            cursor: pointer;
        }
        .control-row summary::-webkit-details-marker {
            display: none;
        }
        .control-row .summary-title {
            margin-bottom: 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }
        .expand-icon {
            color: #64748b;
            font-size: 0.8rem;
            transition: transform 0.2s ease;
        }
        .control-row[open] .expand-icon {
            transform: rotate(180deg);
        }
        .control-content {
            margin-top: 10px;
        }

        .summary-title { font-size: 0.9rem; font-weight: 700; color: #0f172a; margin-bottom: 6px; }
        .summary-text { font-size: 0.84rem; color: #475569; margin-bottom: 0; white-space: pre-wrap; }
        .status-chip {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            padding: 4px 10px;
            font-size: 0.75rem;
            font-weight: 700;
            margin-top: 8px;
        }
        .status-chip.allowed { background: #dcfce7; color: #166534; }
        .status-chip.blocked { background: #fef2f2; color: #991b1b; }
        .authored-meta { font-size: 0.78rem; color: #64748b; margin-bottom: 14px; }

        .badge-allergy {
            background-color: #fee2e2; color: #ef4444;
            padding: 6px 12px; border-radius: 6px;
            font-weight: 500; font-size: 0.85rem;
            display: inline-block; margin-right: 8px;
        }
    </style>
</head>
<body>

    <div class="sidebar">
        <div class="sidebar-brand">
            <div class="icon-box"><i class="fa-solid fa-user-shield"></i></div>
            <div>
                <h6 class="fw-bold mb-0">Patient</h6>
                <small class="text-muted" style="font-size: 0.75rem;">Portal Panel</small>
            </div>
        </div>

        <div class="sidebar-menu">
            <a href="dashboard.php" class="menu-btn"><i class="fa-solid fa-chart-pie"></i>Dashboard</a>
            <a href="medicalrecord.php" class="menu-btn secondary"><i class="fa-solid fa-file-medical"></i>Medical Records</a>
            <a href="privacy_settings.php" class="menu-btn secondary"><i class="fa-solid fa-shield-halved"></i>Privacy Settings</a>
            <a href="current_health.php" class="menu-btn secondary"><i class="fa-solid fa-heart-pulse"></i>Current Health</a>
        </div>

        <a href="logout.php" class="logout-btn">
            <i class="fa-solid fa-arrow-right-from-bracket"></i> Sign Out
        </a>
    </div>

    <div class="main-content">
        
        <div class="mb-4">
            <h2 class="fw-bold mb-1">Welcome, <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Patient'); ?></h2>
            <p class="text-muted small mb-0">Patient Reference Number: PT-2026-0<?php echo htmlspecialchars($_SESSION['user_id'] ?? '1'); ?></p>
        </div>

        <?php if (isset($error_msg)): ?>
            <div class="alert alert-danger" role="alert">
                <i class="fa-solid fa-triangle-exclamation me-2"></i><?php echo $error_msg; ?>
            </div>
        <?php endif; ?>

        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="card card-custom p-3 border-start border-4 border-success">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <span class="text-muted small text-uppercase fw-bold">Accepted Doctors</span>
                            <h3 class="fw-bold mb-0 mt-1"><?php echo (int)($doctors_count ?? 0); ?></h3>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card card-custom p-3 border-start border-4 border-info">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <span class="text-muted small text-uppercase fw-bold">Medical Records</span>
                            <h3 class="fw-bold mb-0 mt-1"><?php echo (int)($records_count ?? 0); ?></h3>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card card-custom p-3 border-start border-4 border-warning">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <span class="text-muted small text-uppercase fw-bold">Recommended Meds</span>
                            <h3 class="fw-bold mb-0 mt-1"><?php echo (int)($medications_count ?? 0); ?></h3>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card card-custom p-4">
            <div class="d-flex align-items-center gap-2 mb-1">
                <i class="fa-solid fa-shield-halved text-info"></i>
                <h5 class="fw-bold mb-0">Privacy Consent Controls</h5>
            </div>
            <p class="text-muted small mb-2">Doctor-written privacy summaries visible to you.</p>
            <?php if (!empty($privacy_consents['authored_by_doctor_name'])): ?>
                <p class="authored-meta">Written by Dr. <?php echo htmlspecialchars($privacy_consents['authored_by_doctor_name']); ?><?php if (!empty($privacy_consents['updated_at'])): ?> on <?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($privacy_consents['updated_at']))); ?><?php endif; ?>.</p>
            <?php else: ?>
                <p class="authored-meta">No doctor summary has been published yet.</p>
            <?php endif; ?>

            <div class="row">
                <div class="col-md-6">
                    <details class="control-row">
                        <summary>
                            <p class="summary-title"><span><i class="fa-solid fa-file-lines text-success me-2"></i>Allergies Summary</span><i class="fa-solid fa-chevron-down expand-icon"></i></p>
                        </summary>
                        <div class="control-content">
                            <p class="summary-text"><?php echo htmlspecialchars($privacy_consents['allergies_summary_text'] ?? ''); ?></p>
                        </div>
                    </details>
                    <details class="control-row">
                        <summary>
                            <p class="summary-title"><span><i class="fa-solid fa-file-lines text-success me-2"></i>Chronic Diagnostic Logs</span><i class="fa-solid fa-chevron-down expand-icon"></i></p>
                        </summary>
                        <div class="control-content">
                            <p class="summary-text"><?php echo htmlspecialchars($privacy_consents['chronic_diagnostic_logs_text'] ?? ''); ?></p>
                        </div>
                    </details>
                </div>
                <div class="col-md-6">
                    <details class="control-row">
                        <summary>
                            <p class="summary-title"><span><i class="fa-solid fa-file-lines text-success me-2"></i>Surgical Typologies</span><i class="fa-solid fa-chevron-down expand-icon"></i></p>
                        </summary>
                        <div class="control-content">
                            <p class="summary-text"><?php echo htmlspecialchars($privacy_consents['surgical_typologies_summary_text'] ?? ''); ?></p>
                            <?php if (!empty($privacy_consents['surgical_typologies_necessary'])): ?>
                                <span class="status-chip allowed">Necessary (Ticked by Doctor)</span>
                            <?php endif; ?>
                        </div>
                    </details>
                </div>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card card-custom p-4 h-100">
                    <h6 class="fw-bold mb-3 text-dark">Current Medications</h6>
                    <?php if (!empty($medications)): ?>
                        <?php foreach ($medications as $med): ?>
                            <div class="p-3 border rounded-3 mb-2 bg-light">
                                <h6 class="fw-bold mb-1 text-dark"><?php echo htmlspecialchars($med['medication_name']); ?> - <?php echo htmlspecialchars($med['dosage']); ?></h6>
                                <p class="text-muted small mb-1"><?php echo htmlspecialchars($med['frequency']); ?></p>
                                <small class="text-secondary">By <?php echo htmlspecialchars($med['prescribed_by']); ?></small>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center py-4 text-muted small">
                            No active long-term prescriptions cataloged on the middleware system yet.
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card card-custom p-4 h-100">
                    <h6 class="fw-bold mb-3 text-dark">Active Allergen Flag Logs</h6>
                    <div class="py-2">
                        <span class="badge-allergy">Penicillin Compounds</span>
                        <span class="badge-allergy">Sulphur Excipients</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-12">
                <div class="card border-0 shadow-sm rounded-4">
                    <div class="card-header bg-white py-3 border-0">
                        <h5 class="fw-bold mb-0 text-dark">Recent EMR Facility Exchanges</h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light text-secondary">
                                    <tr>
                                        <th class="ps-4">Visit Classification</th>
                                        <th>Originating Healthcare Facility</th>
                                        <th class="pe-4">Integration Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($recent_records)): ?>
                                        <?php foreach ($recent_records as $row): ?>
                                            <tr>
                                                <td class="ps-4 fw-medium text-dark"><?php echo htmlspecialchars($row['visit_type']); ?></td>
                                                <td><?php echo htmlspecialchars($row['hospital_name']); ?></td>
                                                <td class="pe-4 text-muted small"><?php echo htmlspecialchars($row['visit_date']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="3" class="text-center py-4 text-muted">
                                                No unified clinical summaries retrieved from connected local EMRs yet.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>