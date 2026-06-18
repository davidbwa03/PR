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

try {
    // 1. Get the patient's primary ID from their active session email
    $patientStmt = $pdo->prepare("SELECT id, name FROM patients WHERE email = ?");
    $patientStmt->execute([$email]);
    $patient = $patientStmt->fetch();

    if ($patient) {
        $patient_id = $patient['id'];
        $patient_name = $patient['name'];

        // 2. Query all medical history records tied to this patient ID chronologically
        $query = "SELECT * FROM medical_records WHERE patient_id = ? ORDER BY visit_date DESC";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$patient_id]);
        $records = $stmt->fetchAll();
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

        /* Base Reset Layout System Configurations */
        html, body {
            margin: 0;
            padding: 0;
            width: 100%;
            height: 100%;
            background-color: var(--bg-light);
            color: var(--text-dark);
        }

        body {
            display: flex;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            -webkit-font-smoothing: antialiased;
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
            background-color: transparent;
            color: #64748b;
            border: none;
            border-radius: 8px;
            font-weight: 500;
            text-align: left;
            text-decoration: none;
            transition: all 0.2s ease;
        }

        .menu-btn.active {
            background-color: var(--shif-teal); /* Solid teal tone toggle */
            color: white !important;
        }

        .menu-btn:hover {
            background-color: #f1f5f9;
            color: var(--text-dark);
        }

        .menu-btn.active:hover {
            background-color: var(--shif-teal-hover);
            color: white;
        }

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

        /* --- MAIN WORKSPACE OFFSET FRAME --- */
        .main-workspace {
            margin-left: var(--sidebar-width);
            flex: 1;
            padding: 40px;
            box-sizing: border-box;
            max-width: 1200px;
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

        .no-records {
            text-align: center;
            padding: 48px;
            background: #ffffff;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            color: #64748b;
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
            <a href="dashboard.php" class="menu-btn"><i class="fa-solid fa-chart-pie"></i> Dashboard</a>
            <a href="medicalrecord.php" class="menu-btn active"><i class="fa-solid fa-file-medical"></i> Medical Records</a>
            <a href="privacy_settings.php" class="menu-btn"><i class="fa-solid fa-shield-halved"></i> Privacy Settings</a>
            <a href="current_health.php" class="menu-btn"><i class="fa-solid fa-heart-pulse"></i> Current Health</a>
        </div>

        <a href="logout.php" class="logout-btn">
            <i class="fa-solid fa-arrow-right-from-bracket"></i> Sign Out
        </a>
    </div>

    <div class="main-workspace">
        
        <div class="workspace-header">
            <h2 class="fw-bold mb-1">Medical Records Across All Hospitals</h2>
            <p class="text-muted small">Complete history from all healthcare providers</p>
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
                            <h2 class="visit-title"><?php echo htmlspecialchars($record['visit_type']); ?></h2>
                            <span class="visit-id"><?php echo htmlspecialchars($record['visit_number']); ?></span>
                        </div>
                        <div class="visit-date">
                            <i class="fa-regular fa-calendar me-1"></i> <?php echo date('Y-m-d', strtotime($record['visit_date'])); ?>
                        </div>
                    </div>

                    <div class="metadata-grid">
                        <div class="meta-group">
                            <div class="meta-label">Hospital</div>
                            <div class="meta-value"><i class="fa-solid fa-hospital text-muted me-1"></i> <?php echo htmlspecialchars($record['hospital_name']); ?></div>
                        </div>
                        <div class="meta-group">
                            <div class="meta-label">Attending Physician</div>
                            <div class="meta-value"><i class="fa-solid fa-user-doctor text-muted me-1"></i> <?php echo htmlspecialchars($record['physician_name']); ?></div>
                        </div>
                    </div>

                    <div class="meds-container">
                        <div class="meta-label">Medications Prescribed</div>
                        <div>
                            <?php 
                            $meds = explode(',', $record['medications_prescribed']);
                            foreach ($meds as $med) {
                                if (trim($med) !== "") {
                                    echo '<span class="med-tag"><i class="fa-solid fa-pills me-1"></i>' . htmlspecialchars(trim($med)) . '</span>';
                                }
                            }
                            ?>
                        </div>
                    </div>

                    <div>
                        <div class="meta-label">Clinical Notes</div>
                        <p class="notes-content"><?php echo htmlspecialchars($record['clinical_notes']); ?></p>
                    </div>

                </div>
            <?php endforeach; ?>

        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>