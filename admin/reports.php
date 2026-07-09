<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

$doctorHasHospitalName = false;
try {
    $doctorHospitalColumn = $pdo->query("SHOW COLUMNS FROM doctors LIKE 'hospital_name'");
    $doctorHasHospitalName = $doctorHospitalColumn && (bool) $doctorHospitalColumn->fetchColumn();
} catch (PDOException $e) {
    $doctorHasHospitalName = false;
}

$doctorHasPhone = false;
try {
    $doctorPhoneColumn = $pdo->query("SHOW COLUMNS FROM doctors LIKE 'phone'");
    $doctorHasPhone = $doctorPhoneColumn && (bool) $doctorPhoneColumn->fetchColumn();
} catch (PDOException $e) {
    $doctorHasPhone = false;
}

if (isset($_GET['download']) && $_GET['download'] === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="system-report-' . date('Y-m-d') . '.csv"');

    $output = fopen('php://output', 'w');
    if ($output === false) {
        http_response_code(500);
        exit('Unable to generate CSV report.');
    }

    // UTF-8 BOM helps spreadsheet apps render characters correctly.
    fwrite($output, "\xEF\xBB\xBF");

    fputcsv($output, ['System Reports and Overview']);
    fputcsv($output, ['Generated At', date('Y-m-d H:i:s')]);
    fputcsv($output, []);

    $hospitals = (int) $pdo->query("SELECT COUNT(*) FROM hospitals")->fetchColumn();
    $doctors = (int) $pdo->query("SELECT COUNT(*) FROM doctors")->fetchColumn();
    $patients = (int) $pdo->query("SELECT COUNT(*) FROM patients")->fetchColumn();

    fputcsv($output, ['Summary']);
    fputcsv($output, ['Total Hospitals', $hospitals]);
    fputcsv($output, ['Total Doctors', $doctors]);
    fputcsv($output, ['Total Patients', $patients]);
    fputcsv($output, []);

    fputcsv($output, ['Recent Doctor Registrations']);
    fputcsv($output, ['Doctor Name', 'Specialty', 'Hospital', 'Phone', 'Status']);

    $doctorHospitalSelect = $doctorHasHospitalName
        ? "COALESCE(NULLIF(TRIM(hospital_name), ''), 'N/A') AS hospital_name"
        : "'N/A' AS hospital_name";
    $doctorPhoneSelect = $doctorHasPhone
        ? "COALESCE(NULLIF(TRIM(phone), ''), 'N/A') AS phone"
        : "'N/A' AS phone";
    $latestDoctorsStmt = $pdo->query("SELECT name, specialty, {$doctorHospitalSelect}, {$doctorPhoneSelect}, status FROM doctors ORDER BY created_at DESC LIMIT 5");
    $latestDoctorsRows = $latestDoctorsStmt ? $latestDoctorsStmt->fetchAll(PDO::FETCH_ASSOC) : [];

    if (empty($latestDoctorsRows)) {
        fputcsv($output, ['No recent doctor registrations', '', '', '', '']);
    } else {
        foreach ($latestDoctorsRows as $docRow) {
            fputcsv($output, [
                (string) ($docRow['name'] ?? ''),
                (string) ($docRow['specialty'] ?? ''),
                (string) ($docRow['hospital_name'] ?? 'N/A'),
                (string) ($docRow['phone'] ?? 'N/A'),
                (string) ($docRow['status'] ?? ''),
            ]);
        }
    }

    fclose($output);
    exit();
}

// Fetch some aggregate data for the report
$stats = [
    'hospitals' => $pdo->query("SELECT COUNT(*) FROM hospitals")->fetchColumn(),
    'doctors'   => $pdo->query("SELECT COUNT(*) FROM doctors")->fetchColumn(),
    'patients'  => $pdo->query("SELECT COUNT(*) FROM patients")->fetchColumn()
];

// Fetch list of latest records for a summary table
$doctorHospitalSelect = $doctorHasHospitalName
    ? "COALESCE(NULLIF(TRIM(hospital_name), ''), 'N/A') AS hospital_name"
    : "'N/A' AS hospital_name";
$doctorPhoneSelect = $doctorHasPhone
    ? "COALESCE(NULLIF(TRIM(phone), ''), 'N/A') AS phone"
    : "'N/A' AS phone";
$latest_doctors = $pdo->query("SELECT name, specialty, {$doctorHospitalSelect}, {$doctorPhoneSelect}, status FROM doctors ORDER BY created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>System Reports | HMS Admin</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/5.3.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #f4f7f8; }
        .sidebar {
            width: 260px;
            min-height: 100vh;
            background: #fff;
            border-right: 1px solid #e0e6e8;
            position: fixed;
            top: 0;
            left: 0;
            overflow-y: auto;
        }
        .sidebar .brand {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid #e0e6e8;
        }
        .sidebar .brand h5 {
            color: #107c91;
            margin: 0;
        }
        .sidebar .nav-section {
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #9aa5a8;
            padding: 1rem 1.5rem 0.4rem;
        }
        .sidebar .nav-link {
            color: #4a5658;
            padding: 0.6rem 1.5rem;
            font-size: 0.92rem;
            border-left: 3px solid transparent;
        }
        .sidebar .nav-link i {
            width: 20px;
            color: #8a9598;
        }
        .sidebar .nav-link:hover {
            background-color: #f0f7f8;
            color: #107c91;
        }
        .sidebar .nav-link:hover i {
            color: #107c91;
        }
        .sidebar .nav-link.active {
            background-color: #e6f3f5;
            color: #107c91;
            border-left-color: #107c91;
            font-weight: 500;
        }
        .sidebar .nav-link.active i {
            color: #107c91;
        }
        .main-content { margin-left: 260px; padding: 2rem; }
        .stat-card { background: #fff; padding: 1.5rem; border-radius: 10px; border: 1px solid #e0e6e8; text-align: center; }
        .stat-card h3 { color: #107c91; margin: 0; }
    </style>
</head>
<body>

<div class="sidebar">
    <div class="brand"><h5><i class="fa-solid fa-hospital me-2"></i>HMS Admin</h5></div>
    <nav class="nav flex-column py-2">
        <a class="nav-link" href="dashboard.php"><i class="fa-solid fa-gauge me-2"></i>Dashboard</a>

        <div class="nav-section">Hospitals</div>
        <a class="nav-link" href="add_hospital.php"><i class="fa-solid fa-plus me-2"></i>Add Hospital</a>
        <a class="nav-link" href="edit_hospitals.php"><i class="fa-solid fa-pen me-2"></i>Edit Hospitals</a>
        <a class="nav-link" href="manage_hospitals.php"><i class="fa-solid fa-list me-2"></i>Manage Hospitals</a>
        <a class="nav-link" href="delete_hospitals.php"><i class="fa-solid fa-trash me-2"></i>Delete Hospitals</a>

        <div class="nav-section">Directory</div>
        <a class="nav-link" href="view_doctors.php"><i class="fa-solid fa-user-doctor me-2"></i>View All Doctors</a>
        <a class="nav-link" href="view_patients.php"><i class="fa-solid fa-hospital-user me-2"></i>View All Patients</a>

        <div class="nav-section">Reports & Requests</div>
        <a class="nav-link active" href="reports.php"><i class="fa-solid fa-chart-line me-2"></i>Generate Reports</a>
        <a class="nav-link" href="access_requests.php"><i class="fa-solid fa-folder-open me-2"></i>View Access Requests</a>

        <hr class="mx-3">
        <a class="nav-link text-danger" href="logout.php"><i class="fa-solid fa-right-from-bracket me-2"></i>Logout</a>
    </nav>
</div>

<div class="main-content">
    <h4 class="mb-4">System Reports & Overview</h4>

    <div class="row mb-4">
        <div class="col-md-4">
            <div class="stat-card">
                <h3><?= $stats['hospitals'] ?></h3>
                <p class="text-muted mb-0">Total Hospitals</p>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card">
                <h3><?= $stats['doctors'] ?></h3>
                <p class="text-muted mb-0">Total Doctors</p>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card">
                <h3><?= $stats['patients'] ?></h3>
                <p class="text-muted mb-0">Total Patients</p>
            </div>
        </div>
    </div>

    <div class="card p-4">
        <h5>Recent Doctor Registrations</h5>
        <table class="table mt-3">
            <thead>
                <tr>
                    <th>Doctor Name</th>
                    <th>Specialty</th>
                    <th>Hospital</th>
                    <th>Phone</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($latest_doctors as $doc): ?>
                <tr>
                    <td><?= htmlspecialchars($doc['name']) ?></td>
                    <td><?= htmlspecialchars($doc['specialty']) ?></td>
                    <td><?= htmlspecialchars($doc['hospital_name'] ?? 'N/A') ?></td>
                    <td><?= htmlspecialchars($doc['phone'] ?? 'N/A') ?></td>
                    <td><span class="badge bg-info"><?= $doc['status'] ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div class="mt-3">
            <button type="button" class="btn btn-primary" onclick="downloadReportPdf()">
                Download Report PDF
            </button>
            
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
<script>
function downloadReportPdf() {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF({ orientation: 'portrait', unit: 'pt', format: 'a4' });

    const stats = {
        hospitals: <?= (int) $stats['hospitals'] ?>,
        doctors: <?= (int) $stats['doctors'] ?>,
        patients: <?= (int) $stats['patients'] ?>
    };

    const recentDoctors = <?= json_encode($latest_doctors, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    const generatedAt = new Date();
    const formattedDate = generatedAt.toLocaleString();

    doc.setFont('helvetica', 'bold');
    doc.setFontSize(18);
    doc.text('System Reports and Overview', 40, 50);

    doc.setFont('helvetica', 'normal');
    doc.setFontSize(10);
    doc.text('Generated: ' + formattedDate, 40, 70);

    doc.setFont('helvetica', 'bold');
    doc.setFontSize(12);
    doc.text('Summary', 40, 100);

    const summaryCards = [
        { label: 'Total Hospitals', value: Number(stats.hospitals || 0).toLocaleString() },
        { label: 'Total Doctors', value: Number(stats.doctors || 0).toLocaleString() },
        { label: 'Total Patients', value: Number(stats.patients || 0).toLocaleString() }
    ];

    const cardStartX = 40;
    const cardStartY = 112;
    const cardGap = 12;
    const contentWidth = 515;
    const cardWidth = (contentWidth - (cardGap * 2)) / 3;
    const cardHeight = 82;

    summaryCards.forEach(function (card, index) {
        const x = cardStartX + (index * (cardWidth + cardGap));

        doc.setFillColor(255, 255, 255);
        doc.setDrawColor(224, 230, 232);
        doc.roundedRect(x, cardStartY, cardWidth, cardHeight, 8, 8, 'FD');

        doc.setFont('helvetica', 'normal');
        doc.setFontSize(10);
        doc.setTextColor(106, 117, 120);
        doc.text(card.label, x + 12, cardStartY + 24);

        doc.setFont('helvetica', 'bold');
        doc.setFontSize(23);
        doc.setTextColor(16, 124, 145);
        doc.text(card.value, x + 12, cardStartY + 58);
    });

    doc.setTextColor(28, 39, 51);
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(12);
    doc.text('Recent Doctor Registrations', 40, 224);

    const tableRows = recentDoctors.map(function (docRow) {
        return [
            docRow.name || '',
            docRow.specialty || '',
            docRow.hospital_name || 'N/A',
            docRow.phone || 'N/A',
            docRow.status || ''
        ];
    });

    doc.autoTable({
        startY: 236,
        head: [['Doctor Name', 'Specialty', 'Hospital', 'Phone', 'Status']],
        body: tableRows.length ? tableRows : [['No recent doctor registrations', '', '', '', '']],
        styles: { font: 'helvetica', fontSize: 10 },
        headStyles: { fillColor: [16, 124, 145] }
    });

    const fileDate = generatedAt.toISOString().slice(0, 10);
    doc.save('system-report-' + fileDate + '.pdf');
}
</script>

</body>
</html>