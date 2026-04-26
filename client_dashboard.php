<?php
session_start();
require_once('config/db_connection.php');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'client') {
    header("Location: login.php");
    exit();
}

$client_id   = $_SESSION['client_id'];
$user_id     = $_SESSION['user_id'];
$client_name = $_SESSION['name'];
$message     = "";
$msg_type    = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'request_visitor') {
    $visitor_name  = trim($_POST['visitor_name'] ?? "");
    $visitor_phone = trim($_POST['visitor_phone'] ?? "");
    $requested     = trim($_POST['requested_time'] ?? "");

    if ($visitor_name === "") {
        $message  = "Visitor name is required.";
        $msg_type = "error";
    } else {
        $sql  = "INSERT INTO Visitors_log (Client_id, Visitor_Name, Visitor_Phone, Status, Requested_time)
                 VALUES (?, ?, ?, 'Pending', ?)";
        $stmt = mysqli_prepare($conn, $sql);
        $req_time = $requested ?: null;
        mysqli_stmt_bind_param($stmt, "isss", $client_id, $visitor_name, $visitor_phone, $req_time);
        if (mysqli_stmt_execute($stmt)) {
            $message  = "Visitor request submitted. Awaiting manager approval.";
            $msg_type = "success";
        } else {
            $message  = "Failed to submit: " . mysqli_error($conn);
            $msg_type = "error";
        }
    }
}

$sql  = "SELECT u.F_name, u.L_name, u.Email, u.Gender, u.D_birth,
                u.Street, u.Area, u.Zip_code, p.Phone,
                c.Status AS Account_Status, c.Guardian_name,
                c.Guardian_Phone, c.Approval_Date
         FROM `User` u
         INNER JOIN Client c ON c.Student_ID = u.ID
         LEFT JOIN Phone p ON p.ID = u.Phone_ID
         WHERE u.ID = ? LIMIT 1";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$client = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

$vsql  = "SELECT Visitor_ID, Visitor_Name, Visitor_Phone,
                 Status, Requested_time, Entry_time, Exit_time
          FROM Visitors_log WHERE Client_id = ? ORDER BY Visitor_ID DESC";
$vstmt = mysqli_prepare($conn, $vsql);
mysqli_stmt_bind_param($vstmt, "i", $client_id);
mysqli_stmt_execute($vstmt);
$vres = mysqli_stmt_get_result($vstmt);
$visitor_rows = [];
while ($row = mysqli_fetch_assoc($vres)) $visitor_rows[] = $row;

$vc = ['Pending' => 0, 'Approved' => 0, 'Rejected' => 0];
foreach ($visitor_rows as $v) { if (isset($vc[$v['Status']])) $vc[$v['Status']]++; }

$active_tab = $_GET['tab'] ?? 'overview';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Dashboard — Hostel MS</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  :root {
    --bg: #0f0e0c; --surface: #1a1916; --surface2: #222120; --surface3: #292826;
    --border: #2e2d2a; --border2: #3a3936;
    --accent: #c9a96e; --accent2: #e8c98a;
    --text: #f0ede6; --muted: #8a8780; --muted2: #6a6865;
    --approved: #6dab7e; --approved-bg: rgba(109,171,126,.1);
    --pending:  #c9a96e; --pending-bg:  rgba(201,169,110,.1);
    --rejected: #d4655a; --rejected-bg: rgba(212,101,90,.1);
    --radius: 10px; --sidebar: 240px;
  }
  body { font-family: 'DM Sans', sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; display: flex; }

  .sidebar { width: var(--sidebar); min-height: 100vh; background: var(--surface); border-right: 1px solid var(--border); display: flex; flex-direction: column; position: fixed; top: 0; left: 0; z-index: 100; }
  .sidebar-logo { padding: 1.5rem; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 10px; }
  .logo-icon { width: 32px; height: 32px; background: var(--accent); border-radius: 7px; display: flex; align-items: center; justify-content: center; }
  .logo-icon svg { width: 18px; height: 18px; fill: #0f0e0c; }
  .logo-text { font-family: 'DM Serif Display', serif; font-size: 15px; line-height: 1.2; }
  .logo-sub  { font-size: 10px; color: var(--muted); letter-spacing: .08em; text-transform: uppercase; }
  .sidebar-nav { flex: 1; padding: 1rem 0; }
  .nav-label { font-size: 10px; font-weight: 600; letter-spacing: .1em; text-transform: uppercase; color: var(--muted2); padding: .5rem 1.25rem .25rem; }
  .nav-item { display: flex; align-items: center; gap: 10px; padding: .65rem 1.25rem; font-size: 13.5px; color: var(--muted); text-decoration: none; transition: color .15s, background .15s; }
  .nav-item svg { width: 16px; height: 16px; flex-shrink: 0; }
  .nav-item:hover { color: var(--text); background: var(--surface2); }
  .nav-item.active { color: var(--accent); background: rgba(201,169,110,.08); }
  .nbadge { margin-left: auto; background: var(--pending); color: #0f0e0c; font-size: 10px; font-weight: 600; border-radius: 20px; padding: 1px 7px; }
  .sidebar-footer { padding: 1rem 1.25rem; border-top: 1px solid var(--border); }
  .user-chip { display: flex; align-items: center; gap: 10px; padding: .6rem .75rem; background: var(--surface2); border: 1px solid var(--border); border-radius: var(--radius); }
  .avatar { width: 30px; height: 30px; background: var(--accent); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 600; color: #0f0e0c; flex-shrink: 0; }
  .user-info { flex: 1; min-width: 0; }
  .user-name { font-size: 12px; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .user-role { font-size: 10px; color: var(--accent); }
  .logout-btn { font-size: 11px; color: var(--muted); text-decoration: none; display: block; text-align: center; margin-top: .75rem; transition: color .15s; }
  .logout-btn:hover { color: var(--rejected); }

  .main { margin-left: var(--sidebar); flex: 1; padding: 2rem; }
  .page-title { font-family: 'DM Serif Display', serif; font-size: 24px; font-weight: 400; }
  .page-sub   { font-size: 13px; color: var(--muted); margin-top: 2px; margin-bottom: 1.75rem; }

  .tabs { display: flex; gap: 4px; border-bottom: 1px solid var(--border); margin-bottom: 2rem; }
  .tab { padding: .6rem 1.1rem; font-size: 13px; font-weight: 500; color: var(--muted); text-decoration: none; border-bottom: 2px solid transparent; margin-bottom: -1px; transition: color .15s, border-color .15s; }
  .tab:hover { color: var(--text); }
  .tab.active { color: var(--accent); border-bottom-color: var(--accent); }

  .toast { padding: 10px 16px; border-radius: var(--radius); font-size: 13px; margin-bottom: 1.5rem; }
  .toast.success { background: var(--approved-bg); border: 1px solid rgba(109,171,126,.3); color: var(--approved); }
  .toast.error   { background: var(--rejected-bg);  border: 1px solid rgba(212,101,90,.3);  color: var(--rejected); }

  .status-banner { border-radius: var(--radius); padding: 1rem 1.25rem; display: flex; align-items: center; gap: 10px; margin-bottom: 1.5rem; font-size: 13.5px; }
  .status-banner.approved { background: var(--approved-bg); border: 1px solid rgba(109,171,126,.3); color: var(--approved); }
  .status-banner.pending  { background: var(--pending-bg);  border: 1px solid rgba(201,169,110,.3); color: var(--pending); }
  .status-banner.rejected { background: var(--rejected-bg); border: 1px solid rgba(212,101,90,.3);  color: var(--rejected); }

  .cards-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
  .info-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 1.5rem; }
  .info-card h3 { font-size: 11px; font-weight: 600; letter-spacing: .08em; text-transform: uppercase; color: var(--accent); margin-bottom: 1rem; padding-bottom: .6rem; border-bottom: 1px solid var(--border); }
  .info-row { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: .6rem; font-size: 13.5px; gap: 1rem; }
  .info-row:last-child { margin-bottom: 0; }
  .info-label { color: var(--muted); flex-shrink: 0; }
  .info-value { font-weight: 500; text-align: right; }

  .vstats { display: flex; gap: .75rem; margin-bottom: 1.5rem; }
  .vstat  { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: .75rem 1.25rem; flex: 1; }
  .vstat-label { font-size: 11px; color: var(--muted); text-transform: uppercase; letter-spacing: .06em; margin-bottom: 4px; }
  .vstat-val   { font-family: 'DM Serif Display', serif; font-size: 26px; line-height: 1; }
  .vstat-val.p { color: var(--pending); } .vstat-val.a { color: var(--approved); } .vstat-val.r { color: var(--rejected); }

  .request-form-wrap { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 1.5rem; margin-bottom: 1.5rem; }
  .request-form-wrap h3 { font-size: 13px; font-weight: 600; color: var(--accent); margin-bottom: 1.25rem; }
  .form-row { display: grid; grid-template-columns: 1fr 1fr 1fr auto; gap: .75rem; align-items: flex-end; }
  .field label { display: block; font-size: 11px; font-weight: 500; color: var(--muted); text-transform: uppercase; letter-spacing: .06em; margin-bottom: 6px; }
  .field input  { width: 100%; background: var(--surface2); border: 1px solid var(--border); border-radius: var(--radius); padding: 9px 12px; color: var(--text); font-family: 'DM Sans', sans-serif; font-size: 13.5px; outline: none; transition: border-color .2s; }
  .field input:focus { border-color: var(--accent); }
  .field input::placeholder { color: var(--muted2); }
  .submit-btn { padding: 9px 18px; background: var(--accent); color: #0f0e0c; border: none; border-radius: var(--radius); font-family: 'DM Sans', sans-serif; font-size: 13px; font-weight: 600; cursor: pointer; white-space: nowrap; transition: background .2s; }
  .submit-btn:hover { background: var(--accent2); }

  .table-wrap { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; }
  table { width: 100%; border-collapse: collapse; }
  thead th { padding: .7rem 1.25rem; text-align: left; font-size: 11px; font-weight: 600; letter-spacing: .07em; text-transform: uppercase; color: var(--muted); background: var(--surface2); border-bottom: 1px solid var(--border); }
  tbody tr { border-bottom: 1px solid var(--border); transition: background .1s; }
  tbody tr:last-child { border-bottom: none; }
  tbody tr:hover { background: var(--surface2); }
  td { padding: .85rem 1.25rem; font-size: 13.5px; vertical-align: middle; }
  .cell-muted { font-size: 12px; color: var(--muted); }
  .fw500 { font-weight: 500; }

  .badge { display: inline-flex; align-items: center; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }
  .badge-pending  { background: var(--pending-bg);  color: var(--pending);  }
  .badge-approved { background: var(--approved-bg); color: var(--approved); }
  .badge-rejected { background: var(--rejected-bg); color: var(--rejected); }

  .empty-state { text-align: center; padding: 3rem 2rem; color: var(--muted); font-size: 13px; }
</style>
</head>
<body>

<aside class="sidebar">
  <div class="sidebar-logo">
    <div class="logo-icon">
      <svg viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22" style="fill:none;stroke:#0f0e0c;stroke-width:1.5"/></svg>
    </div>
    <div>
      <div class="logo-text">Hostel MS</div>
      <div class="logo-sub">Client Portal</div>
    </div>
  </div>
  <nav class="sidebar-nav">
    <div class="nav-label">Menu</div>
    <a class="nav-item <?= $active_tab === 'overview' ? 'active' : '' ?>" href="?tab=overview">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
      Overview
    </a>
    <a class="nav-item <?= $active_tab === 'visitors' ? 'active' : '' ?>" href="?tab=visitors">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>
      Visitors
      <?php if ($vc['Pending'] > 0): ?><span class="nbadge"><?= $vc['Pending'] ?></span><?php endif; ?>
    </a>
  </nav>
  <div class="sidebar-footer">
    <div class="user-chip">
      <div class="avatar"><?= strtoupper(substr($client_name, 0, 1)) ?></div>
      <div class="user-info">
        <div class="user-name"><?= htmlspecialchars($client_name) ?></div>
        <div class="user-role">Client</div>
      </div>
    </div>
    <a href="logout.php" class="logout-btn">Sign out</a>
  </div>
</aside>

<main class="main">
  <div class="page-title">Welcome, <?= htmlspecialchars($client['F_name']) ?></div>
  <div class="page-sub">Client Portal — Hostel Management System</div>

  <?php if ($message): ?>
    <div class="toast <?= $msg_type ?>"><?= htmlspecialchars($message) ?></div>
  <?php endif; ?>

  <div class="tabs">
    <a class="tab <?= $active_tab === 'overview' ? 'active' : '' ?>" href="?tab=overview">Overview</a>
    <a class="tab <?= $active_tab === 'visitors' ? 'active' : '' ?>" href="?tab=visitors">Visitors</a>
  </div>

  <?php if ($active_tab === 'overview'): ?>
    <?php
    $status = $client['Account_Status'];
    $msgs = [
      'Approved' => '✓ Your account is approved. You have full access to hostel services.',
      'Pending'  => '⏳ Your registration is pending manager approval.',
      'Rejected' => '✗ Your registration was not approved. Please contact management.',
    ];
    ?>
    <div class="status-banner <?= strtolower($status) ?>">
      <strong><?= $status ?>:</strong>&nbsp;<?= $msgs[$status] ?? '' ?>
    </div>
    <div class="cards-grid">
      <div class="info-card">
        <h3>Personal info</h3>
        <div class="info-row"><span class="info-label">Full name</span><span class="info-value"><?= htmlspecialchars(trim($client['F_name'] . ' ' . $client['L_name'])) ?></span></div>
        <div class="info-row"><span class="info-label">Email</span><span class="info-value"><?= htmlspecialchars($client['Email']) ?></span></div>
        <?php if ($client['Phone']): ?><div class="info-row"><span class="info-label">Phone</span><span class="info-value"><?= htmlspecialchars($client['Phone']) ?></span></div><?php endif; ?>
        <?php if ($client['Gender']): ?><div class="info-row"><span class="info-label">Gender</span><span class="info-value"><?= htmlspecialchars($client['Gender']) ?></span></div><?php endif; ?>
        <?php if ($client['D_birth']): ?><div class="info-row"><span class="info-label">Date of birth</span><span class="info-value"><?= date('d M Y', strtotime($client['D_birth'])) ?></span></div><?php endif; ?>
      </div>
      <div class="info-card">
        <h3>Guardian info</h3>
        <div class="info-row"><span class="info-label">Name</span><span class="info-value"><?= htmlspecialchars($client['Guardian_name']) ?></span></div>
        <?php if ($client['Guardian_Phone']): ?><div class="info-row"><span class="info-label">Phone</span><span class="info-value"><?= htmlspecialchars($client['Guardian_Phone']) ?></span></div><?php endif; ?>
      </div>
      <?php if ($client['Street'] || $client['Area'] || $client['Zip_code']): ?>
      <div class="info-card">
        <h3>Address</h3>
        <?php if ($client['Street']): ?><div class="info-row"><span class="info-label">Street</span><span class="info-value"><?= htmlspecialchars($client['Street']) ?></span></div><?php endif; ?>
        <?php if ($client['Area']): ?><div class="info-row"><span class="info-label">Area</span><span class="info-value"><?= htmlspecialchars($client['Area']) ?></span></div><?php endif; ?>
        <?php if ($client['Zip_code']): ?><div class="info-row"><span class="info-label">ZIP</span><span class="info-value"><?= htmlspecialchars($client['Zip_code']) ?></span></div><?php endif; ?>
      </div>
      <?php endif; ?>
      <div class="info-card">
        <h3>Account status</h3>
        <div class="info-row"><span class="info-label">Client ID</span><span class="info-value">#<?= $client_id ?></span></div>
        <div class="info-row"><span class="info-label">Status</span><span class="info-value"><?= $status ?></span></div>
        <?php if ($client['Approval_Date']): ?><div class="info-row"><span class="info-label">Decision date</span><span class="info-value"><?= date('d M Y', strtotime($client['Approval_Date'])) ?></span></div><?php endif; ?>
        <div class="info-row"><span class="info-label">Visitors pending</span><span class="info-value"><?= $vc['Pending'] ?></span></div>
        <div class="info-row"><span class="info-label">Visitors approved</span><span class="info-value"><?= $vc['Approved'] ?></span></div>
      </div>
    </div>

  <?php elseif ($active_tab === 'visitors'): ?>

    <div class="vstats">
      <div class="vstat"><div class="vstat-label">Pending</div><div class="vstat-val p"><?= $vc['Pending'] ?></div></div>
      <div class="vstat"><div class="vstat-label">Approved</div><div class="vstat-val a"><?= $vc['Approved'] ?></div></div>
      <div class="vstat"><div class="vstat-label">Rejected</div><div class="vstat-val r"><?= $vc['Rejected'] ?></div></div>
    </div>

    <div class="request-form-wrap">
      <h3>Request a visitor</h3>
      <form method="POST" action="?tab=visitors">
        <input type="hidden" name="action" value="request_visitor">
        <div class="form-row">
          <div class="field">
            <label>Visitor name</label>
            <input type="text" name="visitor_name" placeholder="Full name" required>
          </div>
          <div class="field">
            <label>Phone <span style="font-weight:300;text-transform:none;letter-spacing:0;">optional</span></label>
            <input type="text" name="visitor_phone" placeholder="+880XXXXXXXXXX">
          </div>
          <div class="field">
            <label>Requested time <span style="font-weight:300;text-transform:none;letter-spacing:0;">optional</span></label>
            <input type="time" name="requested_time">
          </div>
          <div>
            <button type="submit" class="submit-btn">Submit request</button>
          </div>
        </div>
      </form>
    </div>

    <div class="table-wrap">
      <?php if (empty($visitor_rows)): ?>
        <div class="empty-state">No visitor requests yet. Use the form above to submit one.</div>
      <?php else: ?>
        <table>
          <thead>
            <tr>
              <th>#</th>
              <th>Visitor</th>
              <th>Requested time</th>
              <th>Entry time</th>
              <th>Exit time</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($visitor_rows as $v): ?>
            <tr>
              <td class="cell-muted"><?= $v['Visitor_ID'] ?></td>
              <td>
                <div class="fw500"><?= htmlspecialchars($v['Visitor_Name']) ?></div>
                <?php if ($v['Visitor_Phone']): ?><div class="cell-muted"><?= htmlspecialchars($v['Visitor_Phone']) ?></div><?php endif; ?>
              </td>
              <td class="cell-muted"><?= $v['Requested_time'] ? substr($v['Requested_time'], 0, 5) : '—' ?></td>
              <td class="cell-muted"><?= $v['Entry_time']     ? substr($v['Entry_time'],     0, 5) : '—' ?></td>
              <td class="cell-muted"><?= $v['Exit_time']      ? substr($v['Exit_time'],      0, 5) : '—' ?></td>
              <td><span class="badge badge-<?= strtolower($v['Status']) ?>"><?= $v['Status'] ?></span></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

  <?php endif; ?>
</main>
</body>
</html>
