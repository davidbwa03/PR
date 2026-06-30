<?php
session_start();
require_once 'db.php'; // Database connection

// Security Access Guard Layer: Ensure patient is completely authenticated
if (!isset($_SESSION['patient']) || !isset($_SESSION['2fa_verified']) || $_SESSION['2fa_verified'] !== true) {
    header("Location: login.php");
    exit();
}

$email = $_SESSION['patient'];
$records = [];
$prescriptions = [];

try {
    // 1. Get the patient's primary ID from their active session email
    $patientStmt = $pdo->prepare("SELECT id, name FROM patients WHERE email = ?");
    $patientStmt->execute([$email]);
    $patient = $patientStmt->fetch();

    if ($patient) {
        $patient_id = $patient['id'];
        $patient_name = $patient['name'];

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
        try { $pdo->exec("ALTER TABLE patient_privacy_consents ADD COLUMN authored_by_doctor_name VARCHAR(255) NULL"); } catch (PDOException $e) {}

        $consentStmt = $pdo->prepare("SELECT allergies_summary_text, chronic_diagnostic_logs_text, surgical_typologies_summary_text, surgical_typologies_necessary, authored_by_doctor_name, updated_at FROM patient_privacy_consents WHERE patient_id = ? LIMIT 1");
        $consentStmt->execute([$patient_id]);
        $privacy_consents = $consentStmt->fetch(PDO::FETCH_ASSOC);
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

        // 2. Query medical history records (exclude vitals-only entries shown in Current Health)
        $query = "SELECT * FROM medical_records WHERE patient_id = ? AND LOWER(COALESCE(visit_type, '')) NOT IN ('vitals check', 'vitals update') ORDER BY visit_date DESC";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$patient_id]);
        $records = $stmt->fetchAll();

        $prescriptionQuery = "SELECT * FROM medication_prescriptions WHERE patient_id = ? ORDER BY id DESC";
        $prescriptionStmt = $pdo->prepare($prescriptionQuery);
        $prescriptionStmt->execute([$patient_id]);
        $prescriptions = $prescriptionStmt->fetchAll();
    } else {
        die("Account configuration mapping mismatch error.");
    }
} catch (PDOException $e) {
    die("System clinical data integration fault: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Portal - Medical Records</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --sidebar-width: 260px;
            --bg-light: #f8fafc;
            --shif-teal: #0e7490;
            --shif-teal-hover: #0891b2;
            --text-dark: #1e293b;
            --card-bg: #ffffff;
            --border-light: #e2e8f0;
        }

        body {
            background-color: var(--bg-light);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: var(--text-dark);
            overflow-x: hidden;
        }
        
        /* --- EXACT IDENTICAL DASHBOARD SIDEBAR STRUCTURE --- */
        .sidebar {
            width: var(--sidebar-width);
            position: fixed;
            top: 0;
            bottom: 0;
            left: 0;
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

        /* CHANGED: Profile container styling synchronized with dashboard theme schema */
        .sidebar-brand .icon-box {
            background-color: var(--shif-teal); 
            color: white;             
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }

        .sidebar-menu {
            display: flex;
            flex-direction: column;
            gap: 8px;
            flex-grow: 1;
        }

        .menu-btn {
            display: flex;
            align-items: center;
            gap: 12px;
            width: 100%;
            padding: 12px 16px;
            background-color: var(--shif-teal);
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 500;
            text-align: left;
            text-decoration: none;
            transition: all 0.2s ease;
        }

        .menu-btn.secondary { background-color: transparent; color: #64748b; }

        .menu-btn:hover {
            background-color: var(--shif-teal-hover);
            color: white;
        }

        .menu-btn.secondary:hover { background-color: #f1f5f9; color: var(--text-dark); }

        .logout-btn {
            margin-top: auto;
            color: #64748b;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            border-radius: 8px;
            transition: background 0.2s;
            font-weight: 500;
        }

        .logout-btn:hover {
            background-color: #fef2f2;
            color: #ef4444;
        }

        .main-content {
            margin-left: var(--sidebar-width);
            padding: 40px;
            min-height: 100vh;
        }

        .workspace-header {
            margin-bottom: 36px;
        }

        /* --- CLINICAL DATA CARDS --- */
        .record-card {
            background: #ffffff; 
            padding: 28px;      
            margin-bottom: 24px;
            border: 1px solid #e2e8f0; 
            border-radius: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
        }
        
        .record-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 14px;
        }
        .visit-title {
            font-size: 18px;
            font-weight: 600;
            color: #0f172a;
            margin: 0 0 4px 0;
        }
        .visit-id {
            font-size: 12px;
            color: #94a3b8;
            font-family: monospace;
            display: block;
        }
        .visit-date {
            font-size: 13px;
            color: #64748b;
            font-weight: 500;
        }

        .metadata-grid {
            display: grid;
            grid-template-columns: 280px 1fr;
            gap: 20px;
            margin-bottom: 14px;
        }
        .meta-group {
            font-size: 14px;
            color: #475569;
        }
        .meta-label {
            font-size: 12px;
            color: #94a3b8;
            margin-bottom: 3px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .meta-value {
            color: #1e293b;
            font-weight: 500;
        }

        .meds-container {
            margin-bottom: 14px;
        }
        .med-tag {
            display: inline-block;
            background-color: #e0f2fe; 
            color: #007a9b;            
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 13px;
            font-weight: 500;
            margin-right: 8px;
            margin-top: 4px;
        }

        .notes-content {
            font-size: 14px;
            color: #475569;
            line-height: 1.5;
            margin: 4px 0 0 0;
        }

        .record-footer {
            margin-top: 16px;
            padding-top: 12px;
            border-top: 1px dashed #e2e8f0;
            font-size: 12px;
            color: #94a3b8;
            display: flex;
            justify-content: space-between;
        }

        .section-title {
            font-size: 18px;
            font-weight: 700;
            color: #0f172a;
            margin: 30px 0 16px;
        }

        .prescription-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 22px;
            margin-bottom: 16px;
        }

        .prescription-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }

        .no-records {
            text-align: center;
            padding: 48px;
            background: #ffffff;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            color: #64748b;
        }

                .consent-card {
                    background: #ffffff;
                    border: 1px solid #e2e8f0;
                    border-radius: 16px;
                    padding: 24px;
                    margin-bottom: 24px;
                }
                .consent-meta { font-size: 0.78rem; color: #64748b; margin-bottom: 12px; }
                .consent-box { border: 1px solid #e2e8f0; border-radius: 12px; padding: 14px; margin-bottom: 12px; }
                .consent-title { font-size: 0.9rem; font-weight: 700; margin-bottom: 6px; color: #0f172a; }
                .consent-text { font-size: 0.84rem; color: #475569; margin-bottom: 0; white-space: pre-wrap; }
                .consent-toggle summary { list-style: none; cursor: pointer; }
                .consent-toggle summary::-webkit-details-marker { display: none; }
                .consent-toggle .consent-title { margin-bottom: 0; display: flex; align-items: center; justify-content: space-between; }
                .consent-arrow { font-size: 0.8rem; color: #64748b; transition: transform 0.2s ease; }
                .consent-toggle[open] .consent-arrow { transform: rotate(180deg); }
                .consent-body { margin-top: 10px; }
                .consent-chip { display: inline-flex; align-items: center; font-size: 0.74rem; font-weight: 700; border-radius: 999px; padding: 4px 10px; margin-top: 8px; }
                .consent-chip.allowed { background: #dcfce7; color: #166534; }
                .consent-chip.blocked { background: #fef2f2; color: #991b1b; }
    </style>
</head>
<body>

    <div class="sidebar">
        <div class="sidebar-brand">
            <div class="icon-box">
                <i class="fa-solid fa-user-shield"></i>
            </div>
            <div>
                <h6 class="fw-bold mb-0">Patient</h6>
                <small class="text-muted" style="font-size: 0.75rem;">Portal Panel</small>
            </div>
        </div>

        <div class="sidebar-menu">
            <a href="dashboard.php" class="menu-btn secondary"><i class="fa-solid fa-chart-pie"></i>Dashboard</a>
            <a href="medicalrecord.php" class="menu-btn"><i class="fa-solid fa-file-medical"></i>Medical Records</a>
            <a href="privacy_settings.php" class="menu-btn secondary"><i class="fa-solid fa-shield-halved"></i>Privacy Settings</a>
            <a href="current_health.php" class="menu-btn secondary"><i class="fa-solid fa-heart-pulse"></i>Current Health</a>
        </div>

        <a href="logout.php" class="logout-btn">
            <i class="fa-solid fa-arrow-right-from-bracket"></i> Sign Out
        </a>
    </div>

    <div class="main-content">
        
        <div class="workspace-header">
            <h2 class="fw-bold mb-1">Medical Records Across All Hospitals</h2>
            <p class="text-muted small">Complete history from all healthcare providers</p>
        </div>

        <div class="consent-card">
            <h3 class="section-title" style="margin-top: 0;">Doctor-Written Privacy Consent Summaries</h3>
            <?php if (!empty($privacy_consents['authored_by_doctor_name'])): ?>
                <p class="consent-meta">Written by Dr. <?php echo htmlspecialchars($privacy_consents['authored_by_doctor_name']); ?><?php if (!empty($privacy_consents['updated_at'])): ?> on <?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($privacy_consents['updated_at']))); ?><?php endif; ?>.</p>
            <?php else: ?>
                <p class="consent-meta">No doctor summary has been published yet.</p>
            <?php endif; ?>

            <details class="consent-box consent-toggle">
                <summary>
                    <p class="consent-title">Allergies Summary <i class="fa-solid fa-chevron-down consent-arrow"></i></p>
                </summary>
                <div class="consent-body">
                    <p class="consent-text"><?php echo htmlspecialchars($privacy_consents['allergies_summary_text'] ?? ''); ?></p>
                </div>
            </details>

            <details class="consent-box consent-toggle">
                <summary>
                    <p class="consent-title">Chronic Diagnostic Logs <i class="fa-solid fa-chevron-down consent-arrow"></i></p>
                </summary>
                <div class="consent-body">
                    <p class="consent-text"><?php echo htmlspecialchars($privacy_consents['chronic_diagnostic_logs_text'] ?? ''); ?></p>
                </div>
            </details>

            <details class="consent-box consent-toggle" style="margin-bottom: 0;">
                <summary>
                    <p class="consent-title">Surgical Typologies <i class="fa-solid fa-chevron-down consent-arrow"></i></p>
                </summary>
                <div class="consent-body">
                    <p class="consent-text"><?php echo htmlspecialchars($privacy_consents['surgical_typologies_summary_text'] ?? ''); ?></p>
                    <?php if (!empty($privacy_consents['surgical_typologies_necessary'])): ?>
                        <span class="consent-chip allowed">Necessary (Ticked by Doctor)</span>
                    <?php endif; ?>
                </div>
            </details>
        </div>

        <?php if (empty($records)): ?>
            <div class="no-records">
                <h3>No Clinical Records Located</h3>
                <p>There are currently no clinical checkup histories matching this account reference profile.</p>
            </div>
        <?php else: ?>
            
            <?php foreach ($records as $record): ?>
                <div class="record-card">
                    
                    <div class="record-header">
                        <div>
                            <h2 class="visit-title"><?php echo htmlspecialchars($record['visit_type'] ?? 'Medical Visit'); ?></h2>
                            <span class="visit-id"><?php echo htmlspecialchars($record['visit_number'] ?? ('REC-' . ($record['id'] ?? 'N/A'))); ?></span>
                        </div>
                        <div class="visit-date">
                            <i class="fa-regular fa-calendar me-1"></i> <?php echo !empty($record['visit_date']) ? date('Y-m-d', strtotime($record['visit_date'])) : 'N/A'; ?>
                        </div>
                    </div>

                    <div class="metadata-grid">
                        <div class="meta-group">
                            <div class="meta-label">Hospital</div>
                            <div class="meta-value"><i class="fa-solid fa-hospital text-muted me-1"></i> <?php echo htmlspecialchars($record['hospital_name'] ?? 'N/A'); ?></div>
                        </div>
                        <div class="meta-group">
                            <div class="meta-label">Attending Physician</div>
                            <div class="meta-value"><i class="fa-solid fa-user-doctor text-muted me-1"></i> <?php echo htmlspecialchars($record['created_by'] ?? ($record['physician_name'] ?? 'N/A')); ?></div>
                        </div>
                    </div>

                    <?php if (!empty($record['medications_prescribed'])): ?>
                        <div class="meds-container">
                            <div class="meta-label">Medications Prescribed</div>
                            <div>
                                <?php 
                                $meds = explode(',', (string)$record['medications_prescribed']);
                                foreach ($meds as $med) {
                                    if (trim($med) !== "") {
                                        echo '<span class="med-tag"><i class="fa-solid fa-pills me-1"></i>' . htmlspecialchars(trim($med)) . '</span>';
                                    }
                                }
                                ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($record['diagnosis'])): ?>
                    <div class="mt-3">
                        <div class="meta-label">Diagnosis</div>
                        <p class="notes-content"><?php echo nl2br(htmlspecialchars($record['diagnosis'])); ?></p>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($record['treatment'])): ?>
                    <div class="mt-3">
                        <div class="meta-label">Treatment Plan</div>
                        <p class="notes-content"><?php echo nl2br(htmlspecialchars($record['treatment'])); ?></p>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($record['clinical_notes']) || !empty($record['notes'])): ?>
                    <div>
                        <div class="meta-label">Clinical Notes</div>
                        <p class="notes-content"><?php echo nl2br(htmlspecialchars($record['clinical_notes'] ?? $record['notes'])); ?></p>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($record['notes'])): ?>
                    <div class="mt-3">
                        <div class="meta-label">Additional Notes</div>
                        <p class="notes-content"><?php echo nl2br(htmlspecialchars($record['notes'])); ?></p>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($record['created_by']) || !empty($record['created_at'])): ?>
                    <div class="record-footer">
                        <span>
                            <?php if (!empty($record['created_by'])): ?>
                                Recorded by: <?php echo htmlspecialchars($record['created_by']); ?>
                            <?php endif; ?>
                        </span>
                        <span>
                            <?php if (!empty($record['created_at'])): ?>
                                Logged: <?php echo date('Y-m-d H:i', strtotime($record['created_at'])); ?>
                            <?php endif; ?>
                        </span>
                    </div>
                    <?php endif; ?>

                </div>
            <?php endforeach; ?>

            <h3 class="section-title">Prescriptions Added By Doctors</h3>
            <?php if (empty($prescriptions)): ?>
                <div class="no-records" style="padding: 24px;">
                    <p class="mb-0">No prescriptions have been added yet.</p>
                </div>
            <?php else: ?>
                <?php foreach ($prescriptions as $prescription): ?>
                    <div class="prescription-card">
                        <div class="prescription-grid">
                            <div>
                                <div class="meta-label">Medication Name</div>
                                <div class="meta-value"><?= htmlspecialchars($prescription['medication_name'] ?? 'N/A'); ?></div>
                            </div>
                            <div>
                                <div class="meta-label">Dosage</div>
                                <div class="meta-value"><?= htmlspecialchars($prescription['dosage'] ?? 'N/A'); ?></div>
                            </div>
                            <div>
                                <div class="meta-label">Frequency</div>
                                <div class="meta-value"><?= htmlspecialchars($prescription['frequency'] ?? 'N/A'); ?></div>
                            </div>
                            <div>
                                <div class="meta-label">Duration</div>
                                <div class="meta-value"><?= htmlspecialchars($prescription['duration'] ?? 'N/A'); ?></div>
                            </div>
                        </div>

                        <?php if (!empty($prescription['notes'])): ?>
                        <div class="mt-3">
                            <div class="meta-label">Prescription Notes</div>
                            <p class="notes-content"><?= nl2br(htmlspecialchars($prescription['notes'])); ?></p>
                        </div>
                        <?php endif; ?>

                        <div class="record-footer mt-3">
                            <span>Prescribed by: <?= htmlspecialchars($prescription['prescribed_by'] ?? 'N/A'); ?></span>
                            <span>ID: <?= htmlspecialchars((string)($prescription['id'] ?? 'N/A')); ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>