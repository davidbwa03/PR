<?php
// staff/dashboard.php
session_start();

// Strict Access Control Guard
if (!isset($_SESSION['staff_logged_in']) || $_SESSION['staff_logged_in'] !== true) {
    header("Location: login.php");
    exit;
}

$practitioner_name = $_SESSION['staff_name'];
$practitioner_id = $_SESSION['practitioner_id'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Practitioner Portal - Dashboard</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: system-ui, sans-serif; }
        body { background-color: #f8fafc; color: #1e293b; display: flex; min-height: 100vh; }
        
        /* Sidebar Layout */
        .sidebar { width: 260px; background-color: #ffffff; border-right: 1px solid #e2e8f0; display: flex; flex-direction: column; padding: 24px; justify-content: space-between; }
        .brand { display: flex; align-items: center; gap: 12px; margin-bottom: 40px; }
        .brand-icon { background-color: #0e7490; color: #ffffff; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; border-radius: 8px; font-weight: bold; font-size: 18px; }
        .brand-title { font-size: 16px; font-weight: 700; color: #0f172a; }
        .brand-subtitle { font-size: 11px; color: #64748b; font-weight: 400; display: block; }
        
        .menu-btn { display: width; width: 100%; padding: 12px 16px; background-color: transparent; border: none; border-radius: 8px; text-align: left; font-size: 14px; font-weight: 600; color: #475569; cursor: pointer; margin-bottom: 8px; transition: all 0.2s; }
        .menu-btn.active, .menu-btn:hover { background-color: #0e7490; color: #ffffff; }
        .logout-btn { display: block; padding: 12px 16px; color: #64748b; text-decoration: none; font-size: 14px; font-weight: 500; }
        .logout-btn:hover { color: #ef4444; }

        /* Main Body Frame */
        .main-content { flex: 1; padding: 40px; }
        .profile-header { margin-bottom: 32px; }
        .profile-header h1 { font-size: 26px; color: #0f172a; margin-bottom: 4px; }
        .profile-header p { font-size: 14px; color: #64748b; }

        /* Request Section UI */
        .card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); margin-bottom: 24px; }
        .card-title-group { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; color: #0f172a; }
        .card-title { font-size: 15px; font-weight: 700; }
        .card-desc { font-size: 13px; color: #64748b; margin-bottom: 16px; }
        
        .search-container { display: flex; gap: 12px; }
        .search-input { flex: 1; padding: 12px 16px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 14px; outline: none; }
        .search-input:focus { border-color: #0e7490; }
        .submit-btn { padding: 12px 24px; background-color: #0e7490; color: #ffffff; border: none; border-radius: 6px; font-size: 14px; font-weight: 600; cursor: pointer; }
        .submit-btn:hover { background-color: #0c5e75; }

        /* Table Rendering */
        .table-section-title { font-size: 16px; font-weight: 700; color: #0f172a; margin-bottom: 16px; }
        .patient-table { width: 100%; border-collapse: collapse; background: #ffffff; border-radius: 12px; overflow: hidden; border: 1px solid #e2e8f0; }
        .patient-table th { background-color: #f8fafc; padding: 14px 20px; text-align: left; font-size: 12px; font-weight: 600; color: #64748b; border-bottom: 1px solid #e2e8f0; }
        .patient-table td { padding: 16px 20px; border-bottom: 1px solid #e2e8f0; font-size: 14px; vertical-align: middle; }
        .patient-name { font-weight: 600; color: #0f172a; }
        .patient-id { font-size: 12px; color: #64748b; margin-top: 2px; }
        .status-badge { display: inline-block; padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: 500; text-align: center; }
        .status-follow-up { background-color: #ecfdf5; color: #047857; }
        .status-stable { background-color: #f0fdf4; color: #16a34a; }
    </style>
</head>
<body>

    <div class="sidebar">
        <div>
            <div class="brand">
                <div class="brand-icon">H</div>
                <div>
                    <span class="brand-title">Practitioner</span>
                    <span class="brand-subtitle">Portal</span>
                </div>
            </div>
            <nav>
                <button class="menu-btn active">Request Patient Data</button>
                <button class="menu-btn">My Patients</button>
                <button class="menu-btn">Update Records</button>
            </nav>
        </div>
        <a href="logout.php" class="logout-btn">← Sign Out</a>
    </div>

    <div class="main-content">
        <div class="profile-header">
            <h1><?php echo htmlspecialchars($practitioner_name); ?></h1>
            <p>Physician ID: <?php echo htmlspecialchars($practitioner_id); ?></p>
        </div>

        <div class="card">
            <div class="card-title-group">
                <span style="color: #0e7490;">🔍</span>
                <span class="card-title">Request Patient Summary</span>
            </div>
            <p class="card-desc">Request patient data from insurance system and other hospitals</p>
            <form class="search-container" method="GET" action="request-patient.php">
                <input type="text" name="patient_id" class="search-input" placeholder="Enter Patient ID (e.g., PT-2024-5619)" required>
                <button type="submit" class="submit-btn">Request Data</button>
            </form>
        </div>

        <h2 class="table-section-title">Recent Patients</h2>
        <table class="patient-table">
            <thead>
                <tr>
                    <th>Patient Details</th>
                    <th style="text-align: right;">Activity Info</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>
                        <div class="patient-name">Emma Wilson</div>
                        <div class="patient-id">PT-2024-4782</div>
                    </td>
                    <td style="text-align: right;">
                        <div style="font-size: 13px; color: #64748b;">Last seen: 2024-05-14</div>
                        <div style="margin-top: 4px;"><span class="status-badge status-follow-up">Follow-up needed</span></div>
                    </td>
                </tr>
                <tr>
                    <td>
                        <div class="patient-name">Robert Martinez</div>
                        <div class="patient-id">PT-2024-3901</div>
                    </td>
                    <td style="text-align: right;">
                        <div style="font-size: 13px; color: #64748b;">Last seen: 2024-05-13</div>
                        <div style="margin-top: 4px;"><span class="status-badge status-stable">Stable</span></div>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

</body>
</html>