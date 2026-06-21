<?php
session_start();
require_once 'db.php'; 

// 1. Authentication Check
if (!isset($_SESSION['doctor_logged_in']) || $_SESSION['doctor_logged_in'] !== true) {
    header("Location: login.php");
    exit;
}

// 2. Fetch the logged-in doctor's information dynamically
// We use the email stored during the login process to find the doctor's record
$doctor_email = $_SESSION['doctor_email']; 
$stmt = $conn->prepare("SELECT id, name FROM doctors WHERE email = ?");
$stmt->bind_param("s", $doctor_email);
$stmt->execute();
$doctor = $stmt->get_result()->fetch_assoc();

// 3. Fetch patient list
$query = "SELECT id, name, created_at FROM patients ORDER BY created_at DESC LIMIT 5";
$result = $conn->query($query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Practitioner Portal</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: system-ui, sans-serif; }
        body { display: flex; min-height: 100vh; background-color: #f8fafc; }
        
        /* Sidebar styling */
        .sidebar { width: 280px; background: #ffffff; border-right: 1px solid #e2e8f0; padding: 20px; display: flex; flex-direction: column; }
        .logo-icon { background-color: #0e7490; color: #ffffff; width: 45px; height: 45px; display: flex; align-items: center; justify-content: center; border-radius: 8px; font-weight: bold; margin-bottom: 20px; }
        .nav-btn { width: 100%; padding: 15px; margin-bottom: 10px; border: none; border-radius: 6px; background-color: #0e7490; color: white; text-align: left; font-weight: 600; cursor: pointer; }
        .sign-out { margin-top: auto; padding-top: 20px; color: #64748b; text-decoration: none; display: flex; align-items: center; gap: 8px; }

        /* Main Content */
        .main { flex: 1; padding: 40px; }
        h1 { color: #1e293b; margin-bottom: 5px; }
        .card { background: #ffffff; padding: 25px; border-radius: 8px; border: 1px solid #e2e8f0; margin-bottom: 25px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .form-row { display: flex; gap: 10px; margin-top: 15px; }
        input { padding: 12px; border: 1px solid #cbd5e1; border-radius: 6px; flex: 1; }
        .btn-submit { padding: 12px 20px; background: #0e7490; color: white; border: none; border-radius: 6px; cursor: pointer; }
        .row { padding: 15px 0; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; }
    </style>
</head>
<body>

<div class="sidebar">
    <div class="logo-icon">H</div>
    <p style="margin-bottom: 20px; font-weight: bold;">Practitioner Portal</p>
    <button class="nav-btn">Request Patient Data</button>
    <button class="nav-btn">My Patients</button>
    <button class="nav-btn">Update Records</button>
    <a href="logout.php" class="sign-out">← Sign Out</a>
</div>

<div class="main">
    <h1>Dr. <?php echo htmlspecialchars($doctor['name'] ?? 'Doctor'); ?></h1>
    <p style="color: #64748b;">Physician ID: MD-20<?php echo htmlspecialchars($doctor['id'] ?? '000'); ?></p>

    <div class="card">
        <h3>Request Patient Summary</h3>
        <p style="font-size: 14px; color: #64748b; margin-top: 5px;">Request patient data from insurance system and other hospitals</p>
        <form method="GET" class="form-row">
            <input type="text" name="pid" placeholder="Enter Patient ID" required>
            <button type="submit" class="btn-submit">Request Data</button>
        </form>
    </div>

    <div class="card">
        <h3>Recent Patients</h3>
        <?php while($row = $result->fetch_assoc()): ?>
        <div class="row">
            <div>
                <strong><?php echo htmlspecialchars($row['name']); ?></strong><br>
                <small style="color: #64748b;"><?php echo htmlspecialchars($row['id']); ?></small>
            </div>
            <div>
                <small style="color: #64748b;">Registered: <?php echo htmlspecialchars($row['created_at']); ?></small>
            </div>
        </div>
        <?php endwhile; ?>
    </div>
</div>

</body>
</html>