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
    <a href="#"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19V5a2 2 0 0 1 2-2h8l6 6v10a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2Z"/></svg>Data Repository</a>
    <a href="#"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 2h6l1 4H8l1-4Z"/><path d="M5 6h14l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6Z"/></svg>Data Requests</a>
    <a href="#" class="active"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>Claims Dashboard</a>
    <a href="#"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.87l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.7 1.7 0 0 0-1.87-.34 1.7 1.7 0 0 0-1.04 1.56V21a2 2 0 1 1-4 0v-.09A1.7 1.7 0 0 0 9 19.4a1.7 1.7 0 0 0-1.87.34l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.7 1.7 0 0 0 .34-1.87 1.7 1.7 0 0 0-1.56-1.04H3a2 2 0 1 1 0-4h.09A1.7 1.7 0 0 0 4.6 9a1.7 1.7 0 0 0-.34-1.87l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1.04-1.56V3a2 2 0 1 1 4 0v.09c0 .68.39 1.3 1.04 1.56.6.24 1.31.12 1.87-.34l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06c-.46.46-.58 1.17-.34 1.87.24.6.86 1.04 1.56 1.04H21a2 2 0 1 1 0 4h-.09c-.68 0-1.3.39-1.56 1.04Z"/></svg>System Activity</a>
    <a href="#"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3v18h18"/><path d="M18 17V9M13 17V5M8 17v-4"/></svg>Analytics</a>
  </div>
  <div class="sidebar-footer">
    <div class="signout">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/></svg>
      Sign Out
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
      <div class="card-value">6,184</div>
      <div class="card-sub up">+212 this month</div>
    </div>

    <div class="card">
      <div class="card-top">
        <span class="card-label">Pending Claims</span>
        <div class="card-icon" style="background:var(--amber-bg);">
          <svg viewBox="0 0 24 24" fill="none" stroke="var(--amber)" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
        </div>
      </div>
      <div class="card-value">742</div>
      <div class="card-sub">Awaiting review</div>
    </div>

    <div class="card">
      <div class="card-top">
        <span class="card-label">Approved Claims</span>
        <div class="card-icon" style="background:var(--green-bg);">
          <svg viewBox="0 0 24 24" fill="none" stroke="var(--green)" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m22 4-10 10-3-3"/></svg>
        </div>
      </div>
      <div class="card-value">5,021</div>
      <div class="card-sub up">81.2% approval rate</div>
    </div>

    <div class="card">
      <div class="card-top">
        <span class="card-label">Rejected Claims</span>
        <div class="card-icon" style="background:var(--red-bg);">
          <svg viewBox="0 0 24 24" fill="none" stroke="var(--red)" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6M9 9l6 6"/></svg>
        </div>
      </div>
      <div class="card-value">421</div>
      <div class="card-sub">6.8% of total claims</div>
    </div>

    <div class="card">
      <div class="card-top">
        <span class="card-label">Registered Hospitals</span>
        <div class="card-icon" style="background:var(--accent-light);">
          <svg viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="2"><path d="M12 6v6m-3-3h6"/><path d="M19 22V4a2 2 0 0 0-2-2H7a2 2 0 0 0-2 2v18"/><path d="M2 22h20"/></svg>
        </div>
      </div>
      <div class="card-value">186</div>
      <div class="card-sub up">+4 this month</div>
    </div>

    <div class="card">
      <div class="card-top">
        <span class="card-label">Registered Patients</span>
        <div class="card-icon" style="background:var(--blue-bg);">
          <svg viewBox="0 0 24 24" fill="none" stroke="var(--blue)" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        </div>
      </div>
      <div class="card-value">8,432</div>
      <div class="card-sub up">+156 this month</div>
    </div>

    <div class="card">
      <div class="card-top">
        <span class="card-label">Total Amount Claimed</span>
        <div class="card-icon" style="background:var(--green-bg);">
          <svg viewBox="0 0 24 24" fill="none" stroke="var(--green)" stroke-width="2"><path d="M12 1v22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
        </div>
      </div>
      <div class="card-value">KES 184.6M</div>
      <div class="card-sub">Across all claims</div>
    </div>

    <div class="card">
      <div class="card-top">
        <span class="card-label">Avg. Processing Time</span>
        <div class="card-icon" style="background:var(--amber-bg);">
          <svg viewBox="0 0 24 24" fill="none" stroke="var(--amber)" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
        </div>
      </div>
      <div class="card-value">3.4 days</div>
      <div class="card-sub up">-0.6 days vs last month</div>
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
          <tr>
            <td><div class="claim-id">CLM-2024-8831</div><div class="claim-sub">Patient: John Davis · PT-2024-5619</div></td>
            <td>Central Medical Center</td>
            <td>KES 142,000</td>
            <td><span class="badge approved">Approved</span></td>
          </tr>
          <tr>
            <td><div class="claim-id">CLM-2024-8830</div><div class="claim-sub">Patient: Amina Yusuf · PT-2024-5618</div></td>
            <td>Nairobi General Hospital</td>
            <td>KES 58,500</td>
            <td><span class="badge pending">Pending</span></td>
          </tr>
          <tr>
            <td><div class="claim-id">CLM-2024-8829</div><div class="claim-sub">Patient: Brian Otieno · PT-2024-5612</div></td>
            <td>St. Mary's Hospital</td>
            <td>KES 21,300</td>
            <td><span class="badge rejected">Rejected</span></td>
          </tr>
          <tr>
            <td><div class="claim-id">CLM-2024-8828</div><div class="claim-sub">Patient: Grace Mwangi · PT-2024-5601</div></td>
            <td>Central Medical Center</td>
            <td>KES 97,750</td>
            <td><span class="badge approved">Approved</span></td>
          </tr>
          <tr>
            <td><div class="claim-id">CLM-2024-8827</div><div class="claim-sub">Patient: Peter Kamau · PT-2024-5598</div></td>
            <td>Coast General Hospital</td>
            <td>KES 33,900</td>
            <td><span class="badge pending">Pending</span></td>
          </tr>
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
              <span class="status-count">5,021</span>
            </div>
            <div class="bar-track"><div class="bar-fill" style="width:81%;background:var(--green);"></div></div>
          </div>
          <div>
            <div class="status-row">
              <div class="status-left"><span class="dot" style="background:var(--amber);"></span><span class="status-name">Pending</span></div>
              <span class="status-count">742</span>
            </div>
            <div class="bar-track"><div class="bar-fill" style="width:12%;background:var(--amber);"></div></div>
          </div>
          <div>
            <div class="status-row">
              <div class="status-left"><span class="dot" style="background:var(--red);"></span><span class="status-name">Rejected</span></div>
              <span class="status-count">421</span>
            </div>
            <div class="bar-track"><div class="bar-fill" style="width:7%;background:var(--red);"></div></div>
          </div>
        </div>
      </div>

      <div class="panel hospitals">
        <div class="panel-head">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 22V4a2 2 0 0 0-2-2H7a2 2 0 0 0-2 2v18"/><path d="M2 22h20"/></svg>
          <span class="panel-title">Top Hospitals by Claims</span>
        </div>
        <p class="panel-desc">Highest claim volume this month</p>
        <div class="hospital-row">
          <div><div class="hospital-name">Central Medical Center</div><div class="hospital-sub">Nairobi</div></div>
          <div class="hospital-count">812</div>
        </div>
        <div class="hospital-row">
          <div><div class="hospital-name">Nairobi General Hospital</div><div class="hospital-sub">Nairobi</div></div>
          <div class="hospital-count">654</div>
        </div>
        <div class="hospital-row">
          <div><div class="hospital-name">Coast General Hospital</div><div class="hospital-sub">Mombasa</div></div>
          <div class="hospital-count">498</div>
        </div>
        <div class="hospital-row">
          <div><div class="hospital-name">St. Mary's Hospital</div><div class="hospital-sub">Kisumu</div></div>
          <div class="hospital-count">371</div>
        </div>
      </div>
    </div>
  </div>
</div>

</body>
</html>