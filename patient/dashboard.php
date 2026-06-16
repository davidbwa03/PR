<?php
session_start();

// Strict Gatekeeper Check: Kick out anyone who hasn't verified via 2FA
if (!isset($_SESSION['2fa_verified']) || $_SESSION['2fa_verified'] !== true) {
    header("Location: login.php");
    exit();
}

// Safely pull details from active session memory
$patient_email = $_SESSION['patient'];
$patient_name = $_SESSION['patient_name'] ?? 'John Davis';
$patient_id = "PT-2024-5619"; // Static placeholder matching your exact mockup image 2.png
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Portal | Healthcare Middleware</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --bg-body: #f8fafc;
            --sidebar-bg: #ffffff;
            --card-bg: #ffffff;
            --primary-blue: #007A9B;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --border-light: #e2e8f0;
            --green-toggle: #22c55e;
        }

        * {
            box-sizing: border-box;
            font-family: system-ui, -apple-system, sans-serif;
        }

        body, html {
            margin: 0;
            padding: 0;
            background-color: var(--bg-body);
            color: var(--text-main);
            min-height: 100vh;
        }

        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }

        /* --- SIDEBAR NAV PANEL --- */
        .sidebar {
            width: 260px;
            background-color: var(--sidebar-bg);
            border-right: 1px solid var(--border-light);
            display: flex;
            flex-direction: column;
            padding: 24px 16px;
            position: fixed;
            height: 100vh;
        }

        .brand-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 35px;
            padding-left: 8px;
        }

        .avatar-box {
            background-color: #e0f2fe;
            color: #0284c7;
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }

        .brand-text h3 {
            margin: 0;
            font-size: 16px;
            font-weight: 600;
        }

        .brand-text span {
            font-size: 12px;
            color: var(--text-muted);
        }

        .nav-menu {
            display: flex;
            flex-direction: column;
            gap: 8px;
            flex-grow: 1;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            text-decoration: none;
            color: var(--text-main);
            font-weight: 500;
            font-size: 14px;
            border-radius: 8px;
            transition: all 0.2s ease;
        }

        .nav-item:hover {
            background-color: #f1f5f9;
        }

        .nav-item.active {
            background-color: var(--primary-blue);
            color: #ffffff;
        }

        .logout-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            text-decoration: none;
            color: var(--text-muted);
            font-weight: 500;
            font-size: 14px;
            border-radius: 8px;
            margin-top: auto;
            transition: color 0.2s;
        }

        .logout-link:hover {
            color: #ef4444;
        }

        /* --- MAIN WORKSPACE --- */
        .main-content {
            flex-grow: 1;
            margin-left: 260px; /* Offset width of the fixed sidebar */
            padding: 40px;
            max-width: 1200px;
        }

        .welcome-header {
            margin-bottom: 30px;
        }

        .welcome-header h1 {
            margin: 0 0 6px 0;
            font-size: 28px;
            font-weight: 700;
        }

        .patient-tag {
            margin: 0;
            font-size: 14px;
            color: var(--text-muted);
        }

        /* --- COMPONENT CARDS --- */
        .card {
            background-color: var(--card-bg);
            border: 1px solid var(--border-light);
            border-radius: 12px;
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

        .card-subtitle {
            margin: 0 0 24px 0;
            color: var(--text-muted);
            font-size: 14px;
        }

        /* --- PRIVACY GRID & SLIDERS (2.png) --- */
        .privacy-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
        }

        .privacy-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background-color: #fafafa;
            border: 1px solid var(--border-light);
            padding: 14px 20px;
            border-radius: 10px;
        }

        .privacy-label {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 500;
            font-size: 14px;
        }

        .icon-visible { color: var(--green-toggle); }
        .icon-hidden { color: #94a3b8; }

        /* Custom Switch CSS Toggle */
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

        /* --- SPLIT GRID AREA --- */
        .split-row {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 24px;
        }

        .medication-box {
            background-color: #fafafa;
            border: 1px solid var(--border-light);
            border-radius: 8px;
            padding: 16px;
        }

        .medication-box h4 {
            margin: 0 0 4px 0;
            font-size: 15px;
            color: var(--text-main);
        }

        .medication-box p {
            margin: 2px 0;
            font-size: 13px;
            color: var(--text-muted);
        }

        .badge-container {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .badge {
            background-color: #fee2e2;
            color: #ef4444;
            padding: 6px 14px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
        }
    </style>
</head>
<body>

<div class="dashboard-container">

    <aside class="sidebar">
        <div class="brand-header">
            <div class="avatar-box">
                <i class="fa-solid fa-user"></i>
            </div>
            <div class="brand-text">
                <h3>Patient</h3>
                <span>Portal</span>
            </div>
        </div>

        <nav class="nav-menu">
            <a href="#" class="nav-item"><i class="fa-regular fa-file-lines"></i> Medical Records</a>
            <a href="#" class="nav-item active"><i class="fa-solid fa-shield-halved"></i> Privacy Settings</a>
            <a href="#" class="nav-item"><i class="fa-solid fa-heart-pulse"></i> Current Health</a>
        </nav>

        <a href="logout.php" class="logout-link">
            <i class="fa-solid fa-arrow-right-from-bracket"></i> Sign Out
        </a>
    </aside>

    <main class="main-content">
        <header class="welcome-header">
            <h1>Welcome, <?php echo htmlspecialchars($patient_name); ?></h1>
            <p class="patient-tag">Patient ID: <?php echo htmlspecialchars($patient_id); ?></p>
        </header>

        <section class="card">
            <div class="card-header-title">
                <i class="fa-regular fa-shield" style="color: var(--primary-blue)"></i> Privacy Controls
            </div>
            <p class="card-subtitle">Control what medical information is visible to doctors and hospitals</p>

            <div class="privacy-grid">
                <div class="privacy-item">
                    <div class="privacy-label"><i class="fa-regular fa-eye icon-visible"></i> Allergies</div>
                    <label class="switch"><input type="checkbox" checked><span class="slider"></span></label>
                </div>
                
                <div class="privacy-item">
                    <div class="privacy-label"><i class="fa-regular fa-eye icon-visible"></i> Medications</div>
                    <label class="switch"><input type="checkbox" checked><span class="slider"></span></label>
                </div>

                <div class="privacy-item">
                    <div class="privacy-label"><i class="fa-regular fa-eye icon-visible"></i> Chronic Conditions</div>
                    <label class="switch"><input type="checkbox" checked><span class="slider"></span></label>
                </div>

                <div class="privacy-item">
                    <div class="privacy-label"><i class="fa-regular fa-eye-slash icon-hidden"></i> Mental Health</div>
                    <label class="switch"><input type="checkbox"><span class="slider"></span></label>
                </div>

                <div class="privacy-item">
                    <div class="privacy-label"><i class="fa-regular fa-eye icon-visible"></i> Surgical History</div>
                    <label class="switch"><input type="checkbox" checked><span class="slider"></span></label>
                </div>
            </div>
        </section>

        <div class="split-row">
            <section class="card">
                <div class="card-header-title" style="font-size: 16px;">Current Medications</div>
                <div style="margin-top: 15px;">
                
                </div>
            </section>

            <section class="card">
                <div class="card-header-title" style="font-size: 16px;">Allergies</div>
                <div style="margin-top: 15px;" class="badge-container">
                    <span class="badge">Penicillin</span>
                    <span class="badge">Peanuts</span>
                </div>
            </section>
        </div>

    </main>
</div>

</body>
</html>