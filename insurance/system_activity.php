<?php
// insurance/system_activity.php
session_start();
require_once 'db.php';

if (!isset($_SESSION['insurer_2fa_verified']) || $_SESSION['insurer_2fa_verified'] !== true) {
    header("Location: insurer-login.php");
    exit();
}

$insurer_name    = $_SESSION['insurer_name']    ?? 'Reviewer';
$insurer_company = $_SESSION['insurer_company'] ?? 'Insurance Portal';

// ── Helpers (same pattern as dashboard.php) ─────────────────
function safeCount(PDO $pdo, string $sql): int {
    try { return (int) $pdo->query($sql)->fetchColumn(); }
    catch (PDOException $e) { return 0; }
}

function hasTable(PDO $pdo, string $table): bool {
    try {
        $s = $pdo->prepare("SHOW TABLES LIKE ?");
        $s->execute([$table]);
        return (bool) $s->fetchColumn();
    } catch (PDOException $e) { return false; }
}

function hasColumn(PDO $pdo, string $table, string $column): bool {
    try {
        $s = $pdo->prepare("SHOW COLUMNS FROM {$table} LIKE ?");
        $s->execute([$column]);
        return (bool) $s->fetch();
    } catch (PDOException $e) { return false; }
}

function mapStatusClass(string $status): string {
    $v = strtolower(trim($status));
    if ($v === 'approved')                      return 'approved';
    if ($v === 'rejected' || $v === 'declined') return 'rejected';
    return 'pending';
}

// ── Filters ─────────────────────────────────────────────────
$filter_status = trim($_GET['status'] ?? '');
$filter_search = trim($_GET['search'] ?? '');
$page          = max(1, (int)($_GET['page'] ?? 1));
$per_page      = 15;
$offset        = ($page - 1) * $per_page;

$allowed = ['pending', 'approved', 'rejected'];
if (!in_array($filter_status, $allowed, true)) $filter_status = '';

// ── Counts ──────────────────────────────────────────────────
$count_all      = safeCount($pdo, "SELECT COUNT(*) FROM claims");
$count_pending  = safeCount($pdo, "SELECT COUNT(*) FROM claims WHERE LOWER(status) = 'pending'");
$count_approved = safeCount($pdo, "SELECT COUNT(*) FROM claims WHERE LOWER(status) = 'approved'");
$count_rejected = safeCount($pdo, "SELECT COUNT(*) FROM claims WHERE LOWER(status) IN ('rejected','declined')");
$total_approved_amount = 0.0;
try {
    $total_approved_amount = (float) $pdo->query("SELECT COALESCE(SUM(claim_amount),0) FROM claims WHERE LOWER(status)='approved'")->fetchColumn();
} catch (PDOException $e) {}

$total_pct = $count_all > 0 ? $count_all : 1;
$approved_pct = round(($count_approved / $total_pct) * 100);
$pending_pct  = round(($count_pending  / $total_pct) * 100);
$rejected_pct = round(($count_rejected / $total_pct) * 100);

// ── Paginated claims ─────────────────────────────────────────
$claims      = [];
$total_rows  = 0;
$where_parts = [];
$params      = [];

if ($filter_status !== '') {
    if ($filter_status === 'rejected') {
        $where_parts[] = "LOWER(c.status) IN ('rejected','declined')";
    } else {
        $where_parts[] = "LOWER(c.status) = ?";
        $params[] = $filter_status;
    }
}
if ($filter_search !== '') {
    $where_parts[] = "(c.claim_number LIKE ? OR c.claim_reason LIKE ? OR p.national_id LIKE ? OR p.email LIKE ? OR p.name LIKE ?)";
    $like = '%' . $filter_search . '%';
    array_push($params, $like, $like, $like, $like, $like);
}
$where_sql = $where_parts ? 'WHERE ' . implode(' AND ', $where_parts) : '';

try {
    $cs = $pdo->prepare("SELECT COUNT(*) FROM claims c LEFT JOIN patients p ON p.id = c.patient_id $where_sql");
    $cs->execute($params);
    $total_rows = (int) $cs->fetchColumn();

    $ds = $pdo->prepare(
        "SELECT c.claim_id, c.claim_number, c.claim_amount, c.claim_reason, c.status,
                c.submitted_date, c.reviewed_by,
                COALESCE(p.name,'Unknown') AS patient_name,
                COALESCE(p.national_id,'—') AS national_id,
                COALESCE(h.name,'—') AS hospital_name
         FROM claims c
         LEFT JOIN patients  p ON p.id = c.patient_id
         LEFT JOIN hospitals h ON h.id = c.hospital_id
         $where_sql
         ORDER BY c.submitted_date DESC
         LIMIT $per_page OFFSET $offset"
    );
    $ds->execute($params);
    $claims = $ds->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $claims = []; }

$total_pages = max(1, (int) ceil($total_rows / $per_page));

// ── Recent access requests ───────────────────────────────────
$access_requests = [];
try {
    $access_requests = $pdo->query(
        "SELECT ar.id, ar.doctor_name, ar.medical_facility, ar.request_status, ar.requested_at,
                COALESCE(p.name,'Unknown') AS patient_name
         FROM access_requests ar
         LEFT JOIN patients p ON p.id = ar.patient_id
         ORDER BY ar.requested_at DESC LIMIT 6"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// ── Top hospitals ─────────────────────────────────────────────
$top_hospitals = [];
try {
    $top_hospitals = $pdo->query(
        "SELECT COALESCE(h.name,'Unknown') AS hospital_name, COUNT(*) AS claim_total
         FROM claims c LEFT JOIN hospitals h ON h.id = c.hospital_id
         GROUP BY h.name ORDER BY claim_total DESC LIMIT 4"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

function buildUrl(array $overrides = []): string {
    $base = array_filter([
        'status' => $_GET['status'] ?? '',
        'search' => $_GET['search'] ?? '',
        'page'   => $_GET['page']   ?? '',
    ]);
    return 'system_activity.php?' . http_build_query(array_merge($base, $overrides));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>System Activity – <?= htmlspecialchars($insurer_company); ?></title>
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
  .sidebar{
    width:230px;
    background:var(--sidebar-bg);
    color:#fff;
    display:flex;
    flex-direction:column;
    padding:20px 14px;
    flex-shrink:0;
  }
  .brand{display:flex;align-items:center;gap:10px;padding:6px 10px 22px 10px;}
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
    text-decoration:none;
    display:block;
  }
  .card-top{display:flex;align-items:center;justify-content:space-between;}
  .card-label{font-size:12.5px;color:var(--text-secondary);}
  .card-icon{
    width:30px;height:30px;border-radius:7px;
    display:flex;align-items:center;justify-content:center;
  }
  .card-icon svg{width:16px;height:16px;}
  .card-value{font-size:25px;font-weight:600;margin-top:10px;color:var(--text-primary);}
  .card-sub{font-size:11.5px;color:var(--text-muted);margin-top:4px;}
  .card-sub.up{color:var(--green);}
  .card.active-filter{border-color:var(--accent);}
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
  /* Filter bar */
  .filter-bar{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin:0 0 16px;}
  .filter-bar input{font-size:13px;padding:8px 14px;border:1px solid var(--border);border-radius:7px;outline:none;color:var(--text-primary);background:#fff;}
  .filter-bar input:focus{border-color:var(--accent);}
  .btn-filter{font-size:13px;font-weight:600;padding:8px 18px;border-radius:7px;background:var(--accent);color:#fff;border:none;cursor:pointer;}
  .btn-clear{font-size:13px;padding:8px 14px;border-radius:7px;background:#f1f5f9;color:var(--text-secondary);border:none;cursor:pointer;text-decoration:none;}
  .results-count{font-size:12px;color:var(--text-muted);margin-left:auto;}
  table{width:100%;border-collapse:collapse;font-size:13px;}
  th{
    text-align:left;color:var(--text-muted);font-weight:500;
    font-size:11.5px;text-transform:uppercase;letter-spacing:.03em;
    border-bottom:1px solid var(--border);padding:0 0 8px 0;
  }
  td{padding:11px 0;border-bottom:1px solid var(--border);vertical-align:middle;}
  tr:last-child td{border-bottom:none;}
  td+td{padding-left:12px;}
  th+th{padding-left:12px;}
  .claim-id{font-weight:500;}
  .claim-sub{font-size:11.5px;color:var(--text-secondary);}
  .badge{
    display:inline-block;padding:3px 10px;border-radius:20px;
    font-size:11px;font-weight:600;
  }
  .badge.approved{background:var(--green-bg);color:var(--green);}
  .badge.pending{background:var(--amber-bg);color:var(--amber);}
  .badge.rejected{background:var(--red-bg);color:var(--red);}
  .btn-approve{font-size:11px;font-weight:600;padding:4px 10px;border-radius:5px;background:var(--green-bg);color:var(--green);border:none;cursor:pointer;}
  .btn-reject{font-size:11px;font-weight:600;padding:4px 10px;border-radius:5px;background:var(--red-bg);color:var(--red);border:none;cursor:pointer;margin-left:4px;}
  .status-list{display:flex;flex-direction:column;gap:14px;}
  .status-row{display:flex;align-items:center;justify-content:space-between;}
  .status-left{display:flex;align-items:center;gap:10px;}
  .dot{width:8px;height:8px;border-radius:50%;}
  .status-name{font-size:13px;}
  .status-count{font-size:13px;font-weight:600;}
  .bar-track{height:6px;background:#eef1f3;border-radius:4px;margin-top:6px;overflow:hidden;}
  .bar-fill{height:100%;border-radius:4px;}
  .req-row{padding:11px 0;border-bottom:1px solid var(--border);}
  .req-row:last-child{border-bottom:none;}
  .req-name{font-size:13px;font-weight:500;}
  .req-sub{font-size:11.5px;color:var(--text-secondary);margin-top:2px;}
  .hospital-row{display:flex;align-items:center;justify-content:space-between;padding:9px 0;border-bottom:1px solid var(--border);}
  .hospital-row:last-child{border-bottom:none;}
  .hospital-name{font-size:13px;font-weight:500;}
  .hospital-sub{font-size:11.5px;color:var(--text-secondary);}
  .hospital-count{font-size:13px;font-weight:600;color:var(--text-primary);}
  .pager{display:flex;gap:6px;flex-wrap:wrap;margin-top:16px;}
  .pager a,.pager span{padding:5px 12px;border-radius:6px;font-size:12.5px;font-weight:600;text-decoration:none;border:1px solid var(--border);color:var(--text-secondary);}
  .pager a:hover{background:#f1f5f9;color:var(--accent);}
  .pager .current{background:var(--accent);color:#fff;border-color:var(--accent);}
  .pager .disabled{opacity:0.4;pointer-events:none;}
  .empty{text-align:center;color:var(--text-secondary);padding:22px 0;font-size:13px;}
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
    <a href="data_requests.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 2h6l1 4H8l1-4Z"/><path d="M5 6h14l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6Z"/></svg>
      Data Requests
    </a>
    <a href="dashboard.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
      Claims Dashboard
    </a>
    <a href="system_activity.php" class="active">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.87l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.7 1.7 0 0 0-1.87-.34 1.7 1.7 0 0 0-1.04 1.56V21a2 2 0 1 1-4 0v-.09A1.7 1.7 0 0 0 9 19.4a1.7 1.7 0 0 0-1.87.34l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.7 1.7 0 0 0 4.6 9a1.7 1.7 0 0 0-1.56-1.04H3a2 2 0 1 1 0-4h.09A1.7 1.7 0 0 0 4.6 9"/></svg>
      System Activity
    </a>
  </div>
  <div class="sidebar-footer">
    <a href="logout.php" class="signout">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/></svg>
      Sign Out
    </a>
  </div>
</div>

<div class="main">
  <div class="header">
    <h1>System Activity</h1>
    <p>Full claims log, access requests, and audit trail</p>
  </div>

  <!-- Stat cards -->
  <div class="cards-grid">
    <a href="system_activity.php" class="card <?= $filter_status === '' ? 'active-filter' : ''; ?>">
      <div class="card-top">
        <span class="card-label">Total Claims</span>
        <div class="card-icon" style="background:var(--blue-bg);">
          <svg viewBox="0 0 24 24" fill="none" stroke="var(--blue)" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6Z"/><path d="M14 2v6h6"/></svg>
        </div>
      </div>
      <div class="card-value"><?= number_format($count_all); ?></div>
      <div class="card-sub">All time</div>
    </a>

    <a href="<?= buildUrl(['status'=>'pending','page'=>1]); ?>" class="card <?= $filter_status === 'pending' ? 'active-filter' : ''; ?>">
      <div class="card-top">
        <span class="card-label">Pending</span>
        <div class="card-icon" style="background:var(--amber-bg);">
          <svg viewBox="0 0 24 24" fill="none" stroke="var(--amber)" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
        </div>
      </div>
      <div class="card-value"><?= number_format($count_pending); ?></div>
      <div class="card-sub">Awaiting review</div>
    </a>

    <a href="<?= buildUrl(['status'=>'approved','page'=>1]); ?>" class="card <?= $filter_status === 'approved' ? 'active-filter' : ''; ?>">
      <div class="card-top">
        <span class="card-label">Approved</span>
        <div class="card-icon" style="background:var(--green-bg);">
          <svg viewBox="0 0 24 24" fill="none" stroke="var(--green)" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m22 4-10 10-3-3"/></svg>
        </div>
      </div>
      <div class="card-value"><?= number_format($count_approved); ?></div>
      <div class="card-sub up">KES <?= number_format($total_approved_amount, 2); ?> paid out</div>
    </a>

    <a href="<?= buildUrl(['status'=>'rejected','page'=>1]); ?>" class="card <?= $filter_status === 'rejected' ? 'active-filter' : ''; ?>">
      <div class="card-top">
        <span class="card-label">Rejected</span>
        <div class="card-icon" style="background:var(--red-bg);">
          <svg viewBox="0 0 24 24" fill="none" stroke="var(--red)" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6M9 9l6 6"/></svg>
        </div>
      </div>
      <div class="card-value"><?= number_format($count_rejected); ?></div>
      <div class="card-sub"><?= $rejected_pct; ?>% of total</div>
    </a>
  </div>

  <!-- Main panels -->
  <div class="panels">

    <!-- Claims table -->
    <div class="panel">
      <div class="panel-head">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6Z"/><path d="M14 2v6h6"/></svg>
        <span class="panel-title">Claims Activity Log</span>
      </div>
      <p class="panel-desc">All insurance claims submitted by hospitals &mdash; paginated, searchable</p>

      <form method="GET" action="system_activity.php">
        <input type="hidden" name="status" value="<?= htmlspecialchars($filter_status); ?>">
        <div class="filter-bar">
          <input type="text" name="search" value="<?= htmlspecialchars($filter_search); ?>" placeholder="Claim number, patient, reason…" style="min-width:220px;">
          <button type="submit" class="btn-filter">Search</button>
          <?php if ($filter_search !== '' || $filter_status !== ''): ?>
            <a href="system_activity.php" class="btn-clear">Clear</a>
          <?php endif; ?>
          <span class="results-count"><?= number_format($total_rows); ?> result<?= $total_rows !== 1 ? 's' : ''; ?></span>
        </div>
      </form>

      <?php if (!empty($claims)): ?>
        <table>
          <thead>
            <tr>
              <th>Claim</th>
              <th>Patient</th>
              <th>Hospital</th>
              <th>Amount (KES)</th>
              <th>Status</th>
              <th>Submitted</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($claims as $c): ?>
              <?php
                $status     = strtolower($c['status'] ?? 'pending');
                $badge_cls  = mapStatusClass($status);
                $is_pending = $status === 'pending';
              ?>
              <tr>
                <td>
                  <div class="claim-id"><?= htmlspecialchars($c['claim_number'] ?? ('CLM-' . $c['claim_id'])); ?></div>
                  <div class="claim-sub" style="max-width:160px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?= htmlspecialchars($c['claim_reason']); ?>">
                    <?= htmlspecialchars($c['claim_reason']); ?>
                  </div>
                </td>
                <td>
                  <div class="claim-id"><?= htmlspecialchars($c['patient_name']); ?></div>
                  <div class="claim-sub"><?= htmlspecialchars($c['national_id']); ?></div>
                </td>
                <td><?= htmlspecialchars($c['hospital_name']); ?></td>
                <td><strong><?= number_format((float)$c['claim_amount'], 2); ?></strong></td>
                <td><span class="badge <?= $badge_cls; ?>"><?= ucfirst($status); ?></span></td>
                <td><?= date('M d, Y', strtotime($c['submitted_date'])); ?></td>
                <td>
                  <?php if ($is_pending): ?>
                    <form method="POST" action="review_claims.php" style="display:inline;">
                      <input type="hidden" name="claim_id" value="<?= (int)$c['claim_id']; ?>">
                      <button type="submit" name="action" value="approved" class="btn-approve">Approve</button>
                    </form>
                    <form method="POST" action="review_claims.php" style="display:inline;">
                      <input type="hidden" name="claim_id" value="<?= (int)$c['claim_id']; ?>">
                      <button type="submit" name="action" value="rejected" class="btn-reject">Reject</button>
                    </form>
                  <?php else: ?>
                    <span class="claim-sub"><?= htmlspecialchars($c['reviewed_by'] ?? '—'); ?></span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <?php if ($total_pages > 1): ?>
          <div class="pager">
            <a href="<?= buildUrl(['page' => $page - 1]); ?>" class="<?= $page <= 1 ? 'disabled' : ''; ?>">&larr;</a>
            <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
              <?php if ($i === $page): ?>
                <span class="current"><?= $i; ?></span>
              <?php else: ?>
                <a href="<?= buildUrl(['page' => $i]); ?>"><?= $i; ?></a>
              <?php endif; ?>
            <?php endfor; ?>
            <a href="<?= buildUrl(['page' => $page + 1]); ?>" class="<?= $page >= $total_pages ? 'disabled' : ''; ?>">&rarr;</a>
          </div>
        <?php endif; ?>

      <?php else: ?>
        <div class="empty">No claims match the current filter.</div>
      <?php endif; ?>
    </div>

    <!-- Right column -->
    <div>

      <!-- Status breakdown -->
      <div class="panel">
        <div class="panel-head">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3v18h18"/><path d="M18 17V9M13 17V5M8 17v-4"/></svg>
          <span class="panel-title">Claims by Status</span>
        </div>
        <p class="panel-desc">Breakdown across all submitted claims</p>
        <div class="status-list">
          <div>
            <div class="status-row">
              <div class="status-left"><span class="dot" style="background:var(--green);"></span><span class="status-name">Approved</span></div>
              <span class="status-count"><?= number_format($count_approved); ?></span>
            </div>
            <div class="bar-track"><div class="bar-fill" style="width:<?= $approved_pct; ?>%;background:var(--green);"></div></div>
          </div>
          <div>
            <div class="status-row">
              <div class="status-left"><span class="dot" style="background:var(--amber);"></span><span class="status-name">Pending</span></div>
              <span class="status-count"><?= number_format($count_pending); ?></span>
            </div>
            <div class="bar-track"><div class="bar-fill" style="width:<?= $pending_pct; ?>%;background:var(--amber);"></div></div>
          </div>
          <div>
            <div class="status-row">
              <div class="status-left"><span class="dot" style="background:var(--red);"></span><span class="status-name">Rejected</span></div>
              <span class="status-count"><?= number_format($count_rejected); ?></span>
            </div>
            <div class="bar-track"><div class="bar-fill" style="width:<?= $rejected_pct; ?>%;background:var(--red);"></div></div>
          </div>
        </div>
      </div>

      <!-- Recent access requests -->
      <div class="panel" style="margin-top:18px;">
        <div class="panel-head">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/></svg>
          <span class="panel-title">Recent Access Requests</span>
        </div>
        <p class="panel-desc">Latest doctor data requests across hospitals</p>
        <?php if (!empty($access_requests)): ?>
          <?php foreach ($access_requests as $r): ?>
            <?php $rs = mapStatusClass($r['request_status'] ?? 'pending'); ?>
            <div class="req-row">
              <div style="display:flex;justify-content:space-between;align-items:center;">
                <div class="req-name"><?= htmlspecialchars($r['patient_name']); ?></div>
                <span class="badge <?= $rs; ?>"><?= ucfirst(strtolower($r['request_status'] ?? 'pending')); ?></span>
              </div>
              <div class="req-sub">
                Dr. <?= htmlspecialchars($r['doctor_name']); ?> &middot; <?= htmlspecialchars($r['medical_facility']); ?> &middot; <?= date('M d, Y', strtotime($r['requested_at'])); ?>
              </div>
            </div>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="empty">No access requests yet.</div>
        <?php endif; ?>
      </div>

      <!-- Top hospitals -->
      <div class="panel" style="margin-top:18px;">
        <div class="panel-head">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 22V4a2 2 0 0 0-2-2H7a2 2 0 0 0-2 2v18"/><path d="M2 22h20"/></svg>
          <span class="panel-title">Top Hospitals by Claims</span>
        </div>
        <p class="panel-desc">Highest claim volume submitted</p>
        <?php if (!empty($top_hospitals)): ?>
          <?php foreach ($top_hospitals as $h): ?>
            <div class="hospital-row">
              <div>
                <div class="hospital-name"><?= htmlspecialchars($h['hospital_name']); ?></div>
                <div class="hospital-sub">Claims submitted</div>
              </div>
              <div class="hospital-count"><?= number_format((int)$h['claim_total']); ?></div>
            </div>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="empty">No hospital data yet.</div>
        <?php endif; ?>
      </div>

    </div>
  </div>
</div>

</body>
</html>