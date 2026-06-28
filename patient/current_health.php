<?php
session_start();

// Strict Gatekeeper Check: require a logged-in patient with verified 2FA
if (!isset($_SESSION['patient']) || !isset($_SESSION['2fa_verified']) || $_SESSION['2fa_verified'] !== true) {
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

    // 2. Pull the latest record that actually has vitals values populated.
    // Using generic latest record can surface non-vitals visits and show stale/blank metrics.
    $stmt_health = $pdo->prepare("SELECT visit_type, visit_date, created_at, clinical_notes, notes, medications_prescribed, hospital_name, blood_pressure, heart_rate, temperature, created_by FROM medical_records WHERE patient_id = :patient_id AND blood_pressure IS NOT NULL AND blood_pressure <> '' AND heart_rate IS NOT NULL AND heart_rate <> '' AND temperature IS NOT NULL AND temperature <> '' ORDER BY COALESCE(visit_date, created_at) DESC, id DESC LIMIT 1");
    $stmt_health->execute(['patient_id' => $real_id]);
    $latest_record = $stmt_health->fetch();

} catch (PDOException $e) {
    // Graceful error fallback
    $patient_id = "PT-2026-1";
    $latest_record = false;
}

$bp_display = !empty($latest_record['blood_pressure']) ? $latest_record['blood_pressure'] : '--';
$hr_display = !empty($latest_record['heart_rate']) ? $latest_record['heart_rate'] : '--';
$temp_display = !empty($latest_record['temperature']) ? $latest_record['temperature'] : '--';

$visit_date_display = !empty($latest_record['visit_date']) ? date('Y-m-d', strtotime($latest_record['visit_date'])) : 'N/A';
$updated_at_display = !empty($latest_record['created_at']) ? date('Y-m-d H:i', strtotime($latest_record['created_at'])) : 'N/A';
$visit_type_display = !empty($latest_record['visit_type']) ? $latest_record['visit_type'] : 'Vitals Update';

$facility_display = !empty($latest_record['hospital_name']) ? $latest_record['hospital_name'] : 'N/A';
$updated_by_display = !empty($latest_record['created_by']) ? $latest_record['created_by'] : 'N/A';
$notes_display = !empty($latest_record['clinical_notes'])
    ? $latest_record['clinical_notes']
    : (!empty($latest_record['notes']) ? $latest_record['notes'] : 'No notes provided.');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Portal | Current Health</title> 
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

        /* --- IDENTICAL DASHBOARD SIDEBAR STRUCTURE --- */
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

        /* --- GRID LAYOUT FOR METRIC CARDS --- */
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 24px;
        }

        .metric-mini-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .metric-mini-card.teal-border { border-left: 4px solid var(--shif-teal); }
        .metric-mini-card.blue-border { border-left: 4px solid #3b82f6; }
        .metric-mini-card.purple-border { border-left: 4px solid #a855f7; }

        .metric-info h3 {
            margin: 0;
            font-size: 24px;
            font-weight: 700;
            color: var(--text-dark);
        }

        .metric-info p {
            margin: 0;
            font-size: 12px;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .metric-icon {
            font-size: 1.8rem;
            color: #cbd5e1;
        }

        .vitals-box {
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 16px;
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
            <a href="privacy_settings.php" class="menu-btn secondary">
                <i class="fa-solid fa-shield-halved me-2"></i> Privacy Settings
            </a>
            <a href="current_health.php" class="menu-btn">
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

        <div class="metrics-grid">
            <div class="metric-mini-card teal-border">
                <div class="metric-info">
                    <p>Systolic Blood Pressure</p>
                    <h3>
                        <?php echo htmlspecialchars($bp_display); ?> 
                        <span style="font-size: 14px; font-weight: normal; color: #64748b;">mmHg</span>
                    </h3>
                </div>
                <div class="metric-icon"><i class="fa-solid fa-gauge-high"></i></div>
            </div>
            
            <div class="metric-mini-card blue-border">
                <div class="metric-info">
                    <p>Resting Heart Rate</p>
                    <h3>
                        <?php echo htmlspecialchars($hr_display); ?> 
                        <span style="font-size: 14px; font-weight: normal; color: #64748b;">BPM</span>
                    </h3>
                </div>
                <div class="metric-icon"><i class="fa-solid fa-heartbeat"></i></div>
            </div>
            
            <div class="metric-mini-card purple-border">
                <div class="metric-info">
                    <p>Body Temperature</p>
                    <h3>
                        <?php echo htmlspecialchars($temp_display); ?> 
                        <span style="font-size: 14px; font-weight: normal; color: #64748b;">°C</span>
                    </h3>
                </div>
                <div class="metric-icon"><i class="fa-solid fa-temperature-half"></i></div>
            </div>
        </div>

        <section class="card-custom">
            <div class="card-header-title mb-2">
                <i class="fa-solid fa-notes-medical text-teal" style="color: var(--shif-teal);"></i> Latest Clinical Vitals and Observation
            </div>
            <p class="text-muted small mb-4">This section shows the most recent vitals update saved by hospital staff or doctor.</p>

            <div class="vitals-box">
                <?php if ($latest_record): ?>
                    <div class="row">
                        <div class="col-md-4 border-end">
                            <small class="text-uppercase text-muted fw-bold d-block mb-1" style="font-size: 11px;">Visit Type</small>
                            <span class="fw-bold text-dark d-block mb-2"><?php echo htmlspecialchars($visit_type_display); ?></span>
                            <small class="text-uppercase text-muted fw-bold d-block mb-1" style="font-size: 11px;">Last Assessment Date</small>
                            <span class="fw-bold text-dark"><?php echo htmlspecialchars($visit_date_display); ?></span>
                        </div>
                        <div class="col-md-8 ps-4">
                            <small class="text-uppercase text-muted fw-bold d-block mb-1" style="font-size: 11px;">Clinical Notes</small>
                            <p class="mb-2 text-dark" style="font-size: 14px; line-height: 1.5;"><?php echo nl2br(htmlspecialchars($notes_display)); ?></p>
                            <small class="text-muted d-block">Origin Facility: <strong class="text-dark"><?php echo htmlspecialchars($facility_display); ?></strong></small>
                            <small class="text-muted d-block mt-1">Updated By: <strong class="text-dark"><?php echo htmlspecialchars($updated_by_display); ?></strong></small>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="text-center py-4 text-muted small">
                        <i class="fa-solid fa-info-circle mb-2 d-block" style="font-size: 1.5rem;"></i>
                        No operational clinical trends extracted yet. Connect EMR systems to feed summary pipelines.
                    </div>
                <?php endif; ?>
            </div>
        </section>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>