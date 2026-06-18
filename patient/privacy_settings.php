<?php
session_start();

// Strict Gatekeeper Check: Kick out anyone who hasn't verified via 2FA
if (!isset($_SESSION['2fa_verified']) || $_SESSION['2fa_verified'] !== true) {
    header("Location: login.php");
    exit();
}

// Include your local database connection parameters
require_once 'db.php';

// Safely pull details from active session memory
$patient_email = $_SESSION['patient'];
$patient_name = $_SESSION['patient_name'] ?? 'David Bwashi';

try {
    // 1. Fetch real user profile parameters to swap static tags with database parameters
    $stmt_user = $pdo->prepare("SELECT id, name FROM patients WHERE email = :email LIMIT 1");
    $stmt_user->execute(['email' => $patient_email]);
    $user_data = $stmt_user->fetch();

    if ($user_data) {
        $real_id = $user_data['id'];
        $patient_name = $user_data['name'];
        // Format matching the system ID schema style: PT-2026-1
        $patient_id = "PT-2026-" . $real_id;
    } else {
        $real_id = 1;
        $patient_id = "PT-2026-1"; 
    }

    // 2. Dynamically extract the prescribed medications from your existing medical_records layout
    $stmt_meds = $pdo->prepare("SELECT medications_prescribed, hospital_name, physician_name FROM medical_records WHERE patient_id = :patient_id ORDER BY id DESC LIMIT 2");
    $stmt_meds->execute(['patient_id' => $real_id]);
    $medication_records = $stmt_meds->fetchAll();

} catch (PDOException $e) {
    // Graceful error fallback to ensure the UI page components still render if queries fail
    $patient_id = "PT-2026-1";
    $medication_records = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Portal | Privacy Settings</title> 
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
            --green-toggle: #22c55e;
        }

        * {
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body, html {
            margin: 0;
            padding: 0;
            background-color: var(--bg-light);
            color: var(--text-dark);
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* --- DASHBOARD SIDEBAR STRUCTURE --- */
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

        .menu-btn.secondary {
            background-color: transparent;
            color: #64748b;
        }

        .menu-btn:hover {
            background-color: var(--shif-teal-hover);
            color: white;
        }

        .menu-btn.secondary:hover {
            background-color: #f1f5f9;
            color: var(--text-dark);
        }

        .logout-btn {
            margin-top: auto;
            color: #64748b;
            text-decoration: none;
            display: flex;
            align-items: center;
            padding: 12px 16px;
            border-radius: 8px;
            transition: background 0.2s;
        }

        .logout-btn:hover {
            background-color: #fef2f2;
            color: #ef4444;
        }

        /* --- MAIN WORKSPACE OFFSET FRAME --- */
        .main-content {
            margin-left: var(--sidebar-width);
            padding: 40px;
            max-width: 1200px;
        }

        /* --- COMPONENT CARDS --- */
        .card-custom {
            background-color: var(--card-bg);
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
        }

        .card-header-title {
            font-size: 18px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 6px;
        }

        /* --- PRIVACY CONTROLS LISTING GRID --- */
        .privacy-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
        }

        .privacy-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background-color: #ffffff;
            border: 1px solid #f1f5f9;
            padding: 14px 20px;
            border-radius: 12px;
        }

        .privacy-label {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 500;
            font-size: 14px;
        }

        /* Custom Switch CSS Toggle Elements */
        .switch {
            position: relative;
            display: inline-block;
            width: 46px;
            height: 24px;
        }

        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .slider {
            position: absolute;
            cursor: pointer;
            top: 0; left: 0; right: 0; bottom: 0;
            background-color: #cbd5e1;
            transition: .2s;
            border-radius: 34px;
        }

        .slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .2s;
            border-radius: 50%;
        }

        input:checked + .slider {
            background-color: var(--green-toggle);
        }

        input:checked + .slider:before {
            transform: translateX(22px);
        }

        /* --- SPLIT LAYOUT BOTTOM METRIC BLOCKS --- */
        .split-row {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 24px;
        }

        .medication-inner-box {
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 10px;
        }

        .medication-inner-box h4 {
            margin: 0 0 4px 0;
            font-size: 14px;
            font-weight: 600;
            color: var(--text-dark);
        }

        .medication-inner-box p {
            margin: 2px 0;
            font-size: 12px;
            color: #64748b;
        }

        .badge-container {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .badge-allergy {
            background-color: #fee2e2;
            color: #ef4444;
            padding: 6px 12px;
            border-radius: 6px;
            font-weight: 500;
            font-size: 0.85rem;
            display: inline-block;
        }
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
            <a href="dashboard.php" class="menu-btn secondary">
                <i class="fa-solid fa-chart-pie me-2"></i> Dashboard
            </a>
            <a href="medicalrecord.php" class="menu-btn secondary">
                <i class="fa-solid fa-file-medical me-2"></i> Medical Records
            </a>
            <a href="privacy_settings.php" class="menu-btn">
                <i class="fa-solid fa-shield-halved me-2"></i> Privacy Settings
            </a>
            <a href="current_health.php" class="menu-btn secondary">
                <i class="fa-solid fa-heart-pulse me-2"></i> Current Health
            </a>
        </div>

        <a href="logout.php" class="logout-btn">
            <i class="fa-solid fa-right-from-bracket me-2"></i> Sign Out
        </a>
    </div>

    <div class="main-content">
        
        <div class="mb-4">
            <h2 class="fw-bold mb-1" style="text-transform: capitalize;">Welcome, <?php echo htmlspecialchars($patient_name); ?></h2>
            <p class="text-muted small mb-0">Patient Reference Number: <?php echo htmlspecialchars($patient_id); ?></p>
        </div>

        <section class="card-custom">
            <div class="card-header-title mb-1">
                Privacy Controls
            </div>
            <p class="text-muted small mb-4">Control what medical information is visible to doctors and hospitals across external EMR platforms</p>

            <div class="privacy-grid">
                <div class="privacy-item">
                    <div class="privacy-label">Allergies Summary</div>
                    <label class="switch"><input type="checkbox" checked><span class="slider"></span></label>
                </div>
                
                <div class="privacy-item">
                    <div class="privacy-label">Active Prescription Tracking</div>
                    <label class="switch"><input type="checkbox" checked><span class="slider"></span></label>
                </div>

                <div class="privacy-item">
                    <div class="privacy-label">Chronic Diagnostic Logs</div>
                    <label class="switch"><input type="checkbox" checked><span class="slider"></span></label>
                </div>

                <div class="privacy-item" style="background-color: #f8fafc; border-color: #e2e8f0;">
                    <div class="privacy-label text-muted">Sensitive Mental Health Summary</div>
                    <label class="switch"><input type="checkbox"><span class="slider"></span></label>
                </div>

                <div class="privacy-item">
                    <div class="privacy-label">Surgical Typologies</div>
                    <label class="switch"><input type="checkbox" checked><span class="slider"></span></label>
                </div>
            </div>
        </section>

        <div class="split-row">
            <section class="card-custom">
                <h6 class="fw-bold mb-3 text-dark">Current Medications</h6>
                <div style="margin-top: 15px;">
                    <?php if (!empty($medication_records)): ?>
                        <?php foreach ($medication_records as $med): ?>
                            <div class="medication-inner-box">
                                <h4><?php echo htmlspecialchars($med['medications_prescribed']); ?></h4>
                                <p>Facility: <?php echo htmlspecialchars($med['hospital_name']); ?></p>
                                <small class="text-muted">Prescribed by: <?php echo htmlspecialchars($med['physician_name']); ?></small>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center py-4 text-muted small">
                            No active long-term prescriptions cataloged on the middleware system yet.
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="card-custom">
                <h6 class="fw-bold mb-3 text-dark">Active Allergen Flag Logs</h6>
                <div style="margin-top: 15px;" class="badge-container">
                    <span class="badge-allergy">Penicillin Compounds</span>
                    <span class="badge-allergy">Sulphur Excipients</span>
                </div>
            </section>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>