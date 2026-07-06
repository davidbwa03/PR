<?php
require_once 'db.php';

function safeCount(PDO $pdo, string $sql): int
{
  try {
    return (int) $pdo->query($sql)->fetchColumn();
  } catch (PDOException $e) {
    return 0;
  }
}

function hasColumn(PDO $pdo, string $table, string $column): bool
{
  try {
    $stmt = $pdo->prepare("SHOW COLUMNS FROM {$table} LIKE ?");
    $stmt->execute([$column]);
    return (bool) $stmt->fetch();
  } catch (PDOException $e) {
    return false;
  }
}

function hasTable(PDO $pdo, string $table): bool
{
  try {
    $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
  } catch (PDOException $e) {
    return false;
  }
}

function firstExistingTable(PDO $pdo, array $candidates): ?string
{
  foreach ($candidates as $table) {
    if (hasTable($pdo, $table)) {
      return $table;
    }
  }
  return null;
}

function firstExistingColumn(PDO $pdo, string $table, array $candidates): ?string
{
  foreach ($candidates as $column) {
    if (hasColumn($pdo, $table, $column)) {
      return $column;
    }
  }
  return null;
}

function mapStatusClass(string $status): string
{
  $value = strtolower(trim($status));
  if ($value === 'approved') {
    return 'approved';
  }
  if ($value === 'rejected' || $value === 'declined') {
    return 'rejected';
  }
  return 'pending';
}

function getRecentClaims(PDO $pdo, int $limit = 5): array
{
  $table = firstExistingTable($pdo, ['claims', 'insurance_claims', 'hospital_claims']);
  if ($table === null) {
    return [];
  }

  $claimIdCol = firstExistingColumn($pdo, $table, ['claim_id', 'claim_number', 'reference_no', 'id']);
  $hospitalCol = firstExistingColumn($pdo, $table, ['hospital_name', 'hospital', 'medical_facility', 'facility_name']);
  $hospitalIdCol = firstExistingColumn($pdo, $table, ['hospital_id']);
  $amountCol = firstExistingColumn($pdo, $table, ['amount', 'claim_amount', 'total_amount']);
  $statusCol = firstExistingColumn($pdo, $table, ['status', 'claim_status']);
  $patientCol = firstExistingColumn($pdo, $table, ['patient_name', 'patient']);
  $patientIdCol = firstExistingColumn($pdo, $table, ['patient_id']);
  $dateCol = firstExistingColumn($pdo, $table, ['created_at', 'submitted_at', 'claim_date', 'updated_at']);

  if ($claimIdCol === null || $amountCol === null || $statusCol === null) {
    return [];
  }

  $orderCol = $dateCol ?? $claimIdCol;

  $hospitalSelect = "'N/A'";
  $joinSql = '';
  if ($hospitalCol !== null) {
    $hospitalSelect = "`{$hospitalCol}`";
  } elseif ($hospitalIdCol !== null && hasTable($pdo, 'hospitals')) {
    $hospitalSelect = "COALESCE(h.name, 'N/A')";
    $joinSql = " LEFT JOIN hospitals h ON c.`{$hospitalIdCol}` = h.id";
  }

  $sql = "SELECT c.`{$claimIdCol}` AS claim_id, {$hospitalSelect} AS hospital_name, c.`{$amountCol}` AS claim_amount, c.`{$statusCol}` AS claim_status";
  if ($patientCol !== null) {
    $sql .= ", c.`{$patientCol}` AS patient_name";
  }
  if ($patientIdCol !== null) {
    $sql .= ", c.`{$patientIdCol}` AS patient_id";
  }
  $sql .= " FROM `{$table}` c{$joinSql} ORDER BY c.`{$orderCol}` DESC LIMIT " . (int) $limit;

  try {
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
  } catch (PDOException $e) {
    return [];
  }
}

function getClaimsStatusBreakdown(PDO $pdo): array
{
  $result = [
    'approved' => 0,
    'pending' => 0,
    'rejected' => 0,
    'total' => 0,
  ];

  $table = firstExistingTable($pdo, ['claims', 'insurance_claims', 'hospital_claims']);
  if ($table === null) {
    return $result;
  }

  $statusCol = firstExistingColumn($pdo, $table, ['status', 'claim_status']);
  if ($statusCol === null) {
    return $result;
  }

  $sql = "SELECT `{$statusCol}` AS claim_status, COUNT(*) AS total FROM `{$table}` GROUP BY `{$statusCol}`";

  try {
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
      $count = (int) ($row['total'] ?? 0);
      $bucket = mapStatusClass((string) ($row['claim_status'] ?? 'pending'));
      if (!isset($result[$bucket])) {
        $bucket = 'pending';
      }
      $result[$bucket] += $count;
      $result['total'] += $count;
    }
  } catch (PDOException $e) {
    return $result;
  }

  return $result;
}

function getClaimMetrics(PDO $pdo): array
{
  $metrics = [
    'total' => 0,
    'pending' => 0,
    'approved' => 0,
    'rejected' => 0,
    'total_amount' => 0.0,
    'this_month' => 0,
    'has_claims' => false,
  ];

  $table = firstExistingTable($pdo, ['claims', 'insurance_claims', 'hospital_claims']);
  if ($table === null) {
    return $metrics;
  }

  $metrics['has_claims'] = true;
  $statusCol = firstExistingColumn($pdo, $table, ['status', 'claim_status']);
  $amountCol = firstExistingColumn($pdo, $table, ['amount', 'claim_amount', 'total_amount']);
  $dateCol = firstExistingColumn($pdo, $table, ['created_at', 'submitted_at', 'claim_date', 'updated_at']);

  $metrics['total'] = safeCount($pdo, "SELECT COUNT(*) FROM `{$table}`");

  if ($statusCol !== null) {
    try {
      $sql = "SELECT `{$statusCol}` AS claim_status, COUNT(*) AS total FROM `{$table}` GROUP BY `{$statusCol}`";
      $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
      foreach ($rows as $row) {
        $bucket = mapStatusClass((string) ($row['claim_status'] ?? 'pending'));
        $count = (int) ($row['total'] ?? 0);
        if (isset($metrics[$bucket])) {
          $metrics[$bucket] += $count;
        }
      }
    } catch (PDOException $e) {
      // Keep defaults on query issues.
    }
  }

  if ($amountCol !== null) {
    try {
      if ($statusCol !== null) {
        $sumSql = "SELECT COALESCE(SUM(CASE WHEN LOWER(TRIM(`{$statusCol}`)) = 'approved' THEN `{$amountCol}` ELSE 0 END), 0) FROM `{$table}`";
      } else {
        $sumSql = "SELECT 0";
      }
      $metrics['total_amount'] = (float) $pdo->query($sumSql)->fetchColumn();
    } catch (PDOException $e) {
      $metrics['total_amount'] = 0.0;
    }
  }

  if ($dateCol !== null) {
    try {
      $monthSql = "SELECT COUNT(*) FROM `{$table}` WHERE YEAR(`{$dateCol}`) = YEAR(CURDATE()) AND MONTH(`{$dateCol}`) = MONTH(CURDATE())";
      $metrics['this_month'] = (int) $pdo->query($monthSql)->fetchColumn();
    } catch (PDOException $e) {
      $metrics['this_month'] = 0;
    }
  }

  return $metrics;
}

function getTopHospitalsByClaims(PDO $pdo, int $limit = 4): array
{
  $table = firstExistingTable($pdo, ['claims', 'insurance_claims', 'hospital_claims']);
  if ($table === null) {
    return [];
  }

  $hospitalCol = firstExistingColumn($pdo, $table, ['hospital_name', 'hospital', 'medical_facility', 'facility_name']);
  $hospitalIdCol = firstExistingColumn($pdo, $table, ['hospital_id']);

  if ($hospitalCol === null && $hospitalIdCol === null) {
    return [];
  }

  if ($hospitalCol !== null) {
    $sql = "SELECT `{$hospitalCol}` AS hospital_name, COUNT(*) AS claim_total
            FROM `{$table}`
            WHERE `{$hospitalCol}` IS NOT NULL AND `{$hospitalCol}` <> ''
            GROUP BY `{$hospitalCol}`
            ORDER BY claim_total DESC
            LIMIT " . (int) $limit;
  } elseif (hasTable($pdo, 'hospitals')) {
    $sql = "SELECT COALESCE(h.name, 'N/A') AS hospital_name, COUNT(*) AS claim_total
            FROM `{$table}` c
            LEFT JOIN hospitals h ON c.`{$hospitalIdCol}` = h.id
            GROUP BY COALESCE(h.name, 'N/A')
            ORDER BY claim_total DESC
            LIMIT " . (int) $limit;
  } else {
    return [];
  }

  try {
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
  } catch (PDOException $e) {
    return [];
  }
}

function getAverageProcessingTimeDays(PDO $pdo): array
{
  $result = [
    'days' => 0.0,
    'has_data' => false,
  ];

  $table = firstExistingTable($pdo, ['claims', 'insurance_claims', 'hospital_claims']);
  if ($table === null) {
    return $result;
  }

  $submittedCol = firstExistingColumn($pdo, $table, ['submitted_at', 'created_at', 'claim_date']);
  $processedCol = firstExistingColumn($pdo, $table, ['processed_at', 'approved_at', 'resolved_at', 'updated_at']);
  $statusCol = firstExistingColumn($pdo, $table, ['status', 'claim_status']);

  if ($submittedCol === null || $processedCol === null || $statusCol === null) {
    return $result;
  }

  $sql = "SELECT AVG(TIMESTAMPDIFF(SECOND, `{$submittedCol}`, `{$processedCol}`)) / 86400 AS avg_days
          FROM `{$table}`
          WHERE `{$submittedCol}` IS NOT NULL
            AND `{$processedCol}` IS NOT NULL
            AND LOWER(`{$statusCol}`) IN ('approved', 'rejected', 'declined')";

  try {
    $avgDays = $pdo->query($sql)->fetchColumn();
    if ($avgDays !== null) {
      $result['days'] = max(0.0, (float) $avgDays);
      $result['has_data'] = true;
    }
  } catch (PDOException $e) {
    return $result;
  }

  return $result;
}

$registeredHospitals = safeCount($pdo, "SELECT COUNT(*) FROM hospitals");
$newHospitalsThisMonth = 0;
$registeredHospitalsSubtext = "Auto-updated from hospitals table";

$registeredPatients = safeCount($pdo, "SELECT COUNT(*) FROM patients");
$newPatientsThisMonth = 0;
$registeredPatientsSubtext = "Auto-updated from patients table";

if (hasColumn($pdo, 'hospitals', 'created_at')) {
  $newHospitalsThisMonth = safeCount(
    $pdo,
    "SELECT COUNT(*) FROM hospitals WHERE YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE())"
  );
  $registeredHospitalsSubtext = '+' . number_format($newHospitalsThisMonth) . ' this month';
}

if (hasColumn($pdo, 'patients', 'created_at')) {
  $newPatientsThisMonth = safeCount(
    $pdo,
    "SELECT COUNT(*) FROM patients WHERE YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE())"
  );
  $registeredPatientsSubtext = '+' . number_format($newPatientsThisMonth) . ' this month';
}

$recentClaims = getRecentClaims($pdo, 5);
$claimMetrics = getClaimMetrics($pdo);
$totalClaims = max(0, (int) ($claimMetrics['total'] ?? 0));
$pendingClaims = max(0, (int) ($claimMetrics['pending'] ?? 0));
$approvedClaims = max(0, (int) ($claimMetrics['approved'] ?? 0));
$rejectedClaims = max(0, (int) ($claimMetrics['rejected'] ?? 0));
$totalClaimAmount = max(0, (float) ($claimMetrics['total_amount'] ?? 0));
$claimsThisMonth = max(0, (int) ($claimMetrics['this_month'] ?? 0));
$approvalRate = $totalClaims > 0 ? round(($approvedClaims / $totalClaims) * 100, 1) : 0;
$rejectionRate = $totalClaims > 0 ? round(($rejectedClaims / $totalClaims) * 100, 1) : 0;
$topHospitals = getTopHospitalsByClaims($pdo, 4);
$avgProcessing = getAverageProcessingTimeDays($pdo);
$avgProcessingDays = max(0.0, (float) ($avgProcessing['days'] ?? 0));
$avgProcessingSubtext = !empty($avgProcessing['has_data'])
  ? 'Auto-updated from processed claims'
  : 'No processed claims yet';

$claimsStatus = getClaimsStatusBreakdown($pdo);
$claimsTotal = max(0, (int) ($claimsStatus['total'] ?? 0));
$approvedCount = max(0, (int) ($claimsStatus['approved'] ?? 0));
$pendingCount = max(0, (int) ($claimsStatus['pending'] ?? 0));
$rejectedCount = max(0, (int) ($claimsStatus['rejected'] ?? 0));
$approvedPct = $claimsTotal > 0 ? round(($approvedCount / $claimsTotal) * 100) : 0;
$pendingPct = $claimsTotal > 0 ? round(($pendingCount / $claimsTotal) * 100) : 0;
$rejectedPct = $claimsTotal > 0 ? round(($rejectedCount / $claimsTotal) * 100) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Insurance Claims Dashboard</title>
<style>
  :root{
    --sidebar-bg:#0f2c3d;
    --sidebar-bg-light:#16384c;
    --accent:#1a7fa3;
    --accent-light:#e3f1f6;
    --page-bg:#f4f6f8;
    --card-bg:#ffffff;
    --border:#e3e7eb;
    --text-primary:#1c2733;
    --text-secondary:#6b7785;
    --text-muted:#94a0ab;
    --green:#1f9d6b;
    --green-bg:#e6f7ef;
    --amber:#c98a14;
    --amber-bg:#fdf3e0;
    --red:#d6453d;
    --red-bg:#fbe9e8;
    --blue:#2563a6;
    --blue-bg:#e8f1fa;
  }
  *{box-sizing:border-box;margin:0;padding:0;}
  body{
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
    background:var(--page-bg);
    color:var(--text-primary);
    display:flex;
    min-height:100vh;
  }
  /* Sidebar */
  .sidebar{
    width:230px;
    background:var(--sidebar-bg);
    color:#fff;
    display:flex;
    flex-direction:column;
    padding:20px 14px;
    flex-shrink:0;
  }
  .brand{
    display:flex;
    align-items:center;
    gap:10px;
    padding:6px 10px 22px 10px;
  }
  .brand-icon{
    width:30px;height:30px;border-radius:7px;
    background:var(--accent);
    display:flex;align-items:center;justify-content:center;
    font-weight:700;font-size:14px;
  }
  .brand-text div:first-child{font-size:14px;font-weight:600;}
  .brand-text div:last-child{font-size:11px;color:#9fb3c0;}
  .nav{display:flex;flex-direction:column;gap:6px;margin-top:6px;}
  .nav a{
    display:flex;align-items:center;gap:10px;
    color:#c7d6df;
    text-decoration:none;
    font-size:13.5px;
    padding:10px 12px;
    border-radius:7px;
  }
  .nav a svg{width:16px;height:16px;flex-shrink:0;}
  .nav a:hover{background:var(--sidebar-bg-light);}
  .nav a.active{background:var(--accent);color:#fff;font-weight:500;}
  .sidebar-footer{margin-top:auto;}
  .signout{
    display:flex;align-items:center;gap:8px;
    color:#9fb3c0;font-size:13px;
    padding:10px 12px;cursor:pointer;
  }

  /* Main */
  .main{flex:1;padding:28px 36px;overflow-x:hidden;}
  .header h1{font-size:21px;font-weight:600;}
  .header p{font-size:13px;color:var(--text-secondary);margin-top:4px;}

  .cards-grid{
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:16px;
    margin-top:24px;
  }
  .card{
    background:var(--card-bg);
    border:1px solid var(--border);
    border-radius:10px;
    padding:16px 18px;
  }
  .card-top{display:flex;align-items:center;justify-content:space-between;}
  .card-label{font-size:12.5px;color:var(--text-secondary);}
  .card-icon{
    width:30px;height:30px;border-radius:7px;
    display:flex;align-items:center;justify-content:center;
  }
  .card-icon svg{width:16px;height:16px;}
  .card-value{font-size:25px;font-weight:600;margin-top:10px;}
  .card-sub{font-size:11.5px;color:var(--text-muted);margin-top:4px;}
  .card-sub.up{color:var(--green);}

  .panels{
    display:grid;
    grid-template-columns:1.4fr 1fr;
    gap:18px;
    margin-top:22px;
  }
  .panel{
    background:var(--card-bg);
    border:1px solid var(--border);
    border-radius:10px;
    padding:18px 20px;
  }
  .panel-head{display:flex;align-items:center;gap:8px;margin-bottom:2px;}
  .panel-head svg{width:16px;height:16px;color:var(--accent);}
  .panel-title{font-size:14.5px;font-weight:600;}
  .panel-desc{font-size:12.5px;color:var(--text-secondary);margin:6px 0 16px 0;}

  table{width:100%;border-collapse:collapse;font-size:13px;}
  th{
    text-align:left;color:var(--text-muted);font-weight:500;
    font-size:11.5px;text-transform:uppercase;letter-spacing:.03em;
    border-bottom:1px solid var(--border);padding:0 0 8px 0;
  }
  td{padding:11px 0;border-bottom:1px solid var(--border);vertical-align:middle;}
  tr:last-child td{border-bottom:none;}
  .claim-id{font-weight:500;}
  .claim-sub{font-size:11.5px;color:var(--text-secondary);}
  .badge{
    display:inline-block;padding:3px 10px;border-radius:20px;
    font-size:11px;font-weight:600;
  }
  .badge.approved{background:var(--green-bg);color:var(--green);}
  .badge.pending{background:var(--amber-bg);color:var(--amber);}
  .badge.rejected{background:var(--red-bg);color:var(--red);}

  .status-list{display:flex;flex-direction:column;gap:14px;}
  .status-row{display:flex;align-items:center;justify-content:space-between;}
  .status-left{display:flex;align-items:center;gap:10px;}
  .dot{width:8px;height:8px;border-radius:50%;}
  .status-name{font-size:13px;}
  .status-count{font-size:13px;font-weight:600;}
  .bar-track{height:6px;background:#eef1f3;border-radius:4px;margin-top:6px;overflow:hidden;}
  .bar-fill{height:100%;border-radius:4px;}

  .hospitals{margin-top:18px;}
  .hospital-row{display:flex;align-items:center;justify-content:space-between;padding:9px 0;border-bottom:1px solid var(--border);}
  .hospital-row:last-child{border-bottom:none;}
  .hospital-name{font-size:13px;font-weight:500;}
  .hospital-sub{font-size:11.5px;color:var(--text-secondary);}
  .hospital-count{font-size:13px;font-weight:600;color:var(--text-primary);}
</style>
</head>
<body>

<div class="sidebar">
  <div class="brand">
    <div class="brand-icon">I</div>
    <div class="brand-text">
      <div>Insurance</div>
      <div>Portal</div>
    </div>
  </div>
  <div class="nav">
    <a href="data_requests.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 2h6l1 4H8l1-4Z"/><path d="M5 6h14l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6Z"/></svg>Data Requests</a>
    <a href="dashboard.php" class="active"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>Claims Dashboard</a>
    <a href="system_activity.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.87l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.7 1.7 0 0 0-1.87-.34 1.7 1.7 0 0 0-1.04 1.56V21a2 2 0 1 1-4 0v-.09A1.7 1.7 0 0 0 9 19.4a1.7 1.7 0 0 0-1.87.34l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.7 1.7 0 0 0 .34-1.87 1.7 1.7 0 0 0-1.56-1.04H3a2 2 0 1 1 0-4h.09A1.7 1.7 0 0 0 4.6 9a1.7 1.7 0 0 0-.34-1.87l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1.04-1.56V3a2 2 0 1 1 4 0v.09c0 .68.39 1.3 1.04 1.56.6.24 1.31.12 1.87-.34l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06c-.46.46-.58 1.17-.34 1.87.24.6.86 1.04 1.56 1.04H21a2 2 0 1 1 0 4h-.09c-.68 0-1.3.39-1.56 1.04Z"/></svg>System Activity</a>
  </div>
  <div class="sidebar-footer">
    <a href="logout.php" class="signout">
    <div class="signout">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/></svg>
      Sign Out
</a>
    </div>

  </div>
</div>

<div class="main">
  <div class="header">
    <h1>Insurance Claims Dashboard</h1>
    <p>Overview of claims activity across hospitals and patients · Organization ID: INS-GOV-2024</p>
  </div>

  <div class="cards-grid">
    <div class="card">
      <div class="card-top">
        <span class="card-label">Total Claims Received</span>
        <div class="card-icon" style="background:var(--blue-bg);">
          <svg viewBox="0 0 24 24" fill="none" stroke="var(--blue)" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6Z"/><path d="M14 2v6h6"/></svg>
        </div>
      </div>
      <div class="card-value"><?php echo number_format($totalClaims); ?></div>
      <div class="card-sub up">+<?php echo number_format($claimsThisMonth); ?> this month</div>
    </div>

    <div class="card">
      <div class="card-top">
        <span class="card-label">Pending Claims</span>
        <div class="card-icon" style="background:var(--amber-bg);">
          <svg viewBox="0 0 24 24" fill="none" stroke="var(--amber)" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
        </div>
      </div>
      <div class="card-value"><?php echo number_format($pendingClaims); ?></div>
      <div class="card-sub">Awaiting review</div>
    </div>

    <div class="card">
      <div class="card-top">
        <span class="card-label">Approved Claims</span>
        <div class="card-icon" style="background:var(--green-bg);">
          <svg viewBox="0 0 24 24" fill="none" stroke="var(--green)" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m22 4-10 10-3-3"/></svg>
        </div>
      </div>
      <div class="card-value"><?php echo number_format($approvedClaims); ?></div>
      <div class="card-sub up"><?php echo number_format($approvalRate, 1); ?>% approval rate</div>
    </div>

    <div class="card">
      <div class="card-top">
        <span class="card-label">Rejected Claims</span>
        <div class="card-icon" style="background:var(--red-bg);">
          <svg viewBox="0 0 24 24" fill="none" stroke="var(--red)" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6M9 9l6 6"/></svg>
        </div>
      </div>
      <div class="card-value"><?php echo number_format($rejectedClaims); ?></div>
      <div class="card-sub"><?php echo number_format($rejectionRate, 1); ?>% of total claims</div>
    </div>

    <div class="card">
      <div class="card-top">
        <span class="card-label">Registered Hospitals</span>
        <div class="card-icon" style="background:var(--accent-light);">
          <svg viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="2"><path d="M12 6v6m-3-3h6"/><path d="M19 22V4a2 2 0 0 0-2-2H7a2 2 0 0 0-2 2v18"/><path d="M2 22h20"/></svg>
        </div>
      </div>
      <div class="card-value"><?php echo number_format($registeredHospitals); ?></div>
      <div class="card-sub up"><?php echo htmlspecialchars($registeredHospitalsSubtext, ENT_QUOTES, 'UTF-8'); ?></div>
    </div>

    <div class="card">
      <div class="card-top">
        <span class="card-label">Registered Patients</span>
        <div class="card-icon" style="background:var(--blue-bg);">
          <svg viewBox="0 0 24 24" fill="none" stroke="var(--blue)" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        </div>
      </div>
      <div class="card-value"><?php echo number_format($registeredPatients); ?></div>
      <div class="card-sub up"><?php echo htmlspecialchars($registeredPatientsSubtext, ENT_QUOTES, 'UTF-8'); ?></div>
    </div>

    <div class="card">
      <div class="card-top">
        <span class="card-label">Total Amount Claimed</span>
        <div class="card-icon" style="background:var(--green-bg);">
          <svg viewBox="0 0 24 24" fill="none" stroke="var(--green)" stroke-width="2"><path d="M12 1v22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
        </div>
      </div>
      <div class="card-value">KES <?php echo number_format($totalClaimAmount, 2); ?></div>
      <div class="card-sub">Approved claims only</div>
    </div>

    <div class="card">
      <div class="card-top">
        <span class="card-label">Avg. Processing Time</span>
        <div class="card-icon" style="background:var(--amber-bg);">
          <svg viewBox="0 0 24 24" fill="none" stroke="var(--amber)" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
        </div>
      </div>
      <div class="card-value"><?php echo number_format($avgProcessingDays, 1); ?> days</div>
      <div class="card-sub up"><?php echo htmlspecialchars($avgProcessingSubtext, ENT_QUOTES, 'UTF-8'); ?></div>
    </div>
  </div>

  <div class="panels">
    <div class="panel">
      <div class="panel-head">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6Z"/><path d="M14 2v6h6"/></svg>
        <span class="panel-title">Recent Claims</span>
      </div>
      <p class="panel-desc">Latest claims submitted by hospitals on behalf of patients</p>
      <table>
        <thead>
          <tr><th>Claim</th><th>Hospital</th><th>Amount</th><th>Status</th></tr>
        </thead>
        <tbody>
          <?php if (!empty($recentClaims)): ?>
            <?php foreach ($recentClaims as $claim): ?>
              <?php
                $claimId = (string)($claim['claim_id'] ?? '');
                $patientName = trim((string)($claim['patient_name'] ?? ''));
                $patientId = trim((string)($claim['patient_id'] ?? ''));
                $claimSub = '';
                if ($patientName !== '' || $patientId !== '') {
                  $claimSub = 'Patient: ' . ($patientName !== '' ? $patientName : 'N/A') . ($patientId !== '' ? ' · ' . $patientId : '');
                }
                $hospitalName = (string)($claim['hospital_name'] ?? 'N/A');
                $amountRaw = $claim['claim_amount'] ?? 0;
                $amountText = is_numeric($amountRaw)
                  ? 'KES ' . number_format((float)$amountRaw, 2)
                  : (string)$amountRaw;
                $statusText = ucfirst(strtolower((string)($claim['claim_status'] ?? 'Pending')));
                $statusClass = mapStatusClass((string)($claim['claim_status'] ?? 'pending'));
              ?>
              <tr>
                <td>
                  <div class="claim-id"><?php echo htmlspecialchars($claimId, ENT_QUOTES, 'UTF-8'); ?></div>
                  <?php if ($claimSub !== ''): ?>
                    <div class="claim-sub"><?php echo htmlspecialchars($claimSub, ENT_QUOTES, 'UTF-8'); ?></div>
                  <?php endif; ?>
                </td>
                <td><?php echo htmlspecialchars($hospitalName, ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars($amountText, ENT_QUOTES, 'UTF-8'); ?></td>
                <td><span class="badge <?php echo htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($statusText, ENT_QUOTES, 'UTF-8'); ?></span></td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="4" style="text-align:center;color:var(--text-secondary);padding:22px 0;">
                No claims submitted yet.
              </td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div>
      <div class="panel">
        <div class="panel-head">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3v18h18"/><path d="M18 17V9M13 17V5M8 17v-4"/></svg>
          <span class="panel-title">Claims by Status</span>
        </div>
        <p class="panel-desc">Breakdown of all claims this quarter</p>
        <div class="status-list">
          <div>
            <div class="status-row">
              <div class="status-left"><span class="dot" style="background:var(--green);"></span><span class="status-name">Approved</span></div>
              <span class="status-count"><?php echo number_format($approvedCount); ?></span>
            </div>
            <div class="bar-track"><div class="bar-fill" style="width:<?php echo $approvedPct; ?>%;background:var(--green);"></div></div>
          </div>
          <div>
            <div class="status-row">
              <div class="status-left"><span class="dot" style="background:var(--amber);"></span><span class="status-name">Pending</span></div>
              <span class="status-count"><?php echo number_format($pendingCount); ?></span>
            </div>
            <div class="bar-track"><div class="bar-fill" style="width:<?php echo $pendingPct; ?>%;background:var(--amber);"></div></div>
          </div>
          <div>
            <div class="status-row">
              <div class="status-left"><span class="dot" style="background:var(--red);"></span><span class="status-name">Rejected</span></div>
              <span class="status-count"><?php echo number_format($rejectedCount); ?></span>
            </div>
            <div class="bar-track"><div class="bar-fill" style="width:<?php echo $rejectedPct; ?>%;background:var(--red);"></div></div>
          </div>
        </div>
      </div>

      <div class="panel hospitals">
        <div class="panel-head">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 22V4a2 2 0 0 0-2-2H7a2 2 0 0 0-2 2v18"/><path d="M2 22h20"/></svg>
          <span class="panel-title">Top Hospitals by Claims</span>
        </div>
        <p class="panel-desc">Highest claim volume this month</p>
        <?php if (!empty($topHospitals)): ?>
          <?php foreach ($topHospitals as $hospital): ?>
            <div class="hospital-row">
              <div>
                <div class="hospital-name"><?php echo htmlspecialchars((string) ($hospital['hospital_name'] ?? 'N/A'), ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="hospital-sub">Claims submitted</div>
              </div>
              <div class="hospital-count"><?php echo number_format((int) ($hospital['claim_total'] ?? 0)); ?></div>
            </div>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="hospital-row">
            <div>
              <div class="hospital-name">No hospital claims yet</div>
              <div class="hospital-sub">Waiting for submissions</div>
            </div>
            <div class="hospital-count">0</div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

</body>
</html>