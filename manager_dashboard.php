<?php
session_start();
require_once('config/db_connection.php');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'manager') {
    header("Location: login.php");
    exit();
}

$manager_id   = $_SESSION['manager_id'];
$manager_name = $_SESSION['name'];
$message      = "";
$msg_type     = "";
$active_tab   = $_GET['tab'] ?? 'registrations';

// ── Handle actions ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // Client registration approve/reject
    if (in_array($action, ['approve_client', 'reject_client']) && isset($_POST['client_id'])) {
        $client_id  = intval($_POST['client_id']);
        $new_status = $action === 'approve_client' ? 'Approved' : 'Rejected';
        $sql  = "UPDATE Client SET Status = ?, Approved_by = ?, Approval_Date = CURDATE()
                 WHERE ID = ? AND Status = 'Pending'";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "sii", $new_status, $manager_id, $client_id);
        if (mysqli_stmt_execute($stmt) && mysqli_stmt_affected_rows($stmt) > 0) {
            $message  = "Client " . strtolower($new_status) . " successfully.";
            $msg_type = $new_status === 'Approved' ? "success" : "error";
        } else {
            $message  = "Could not update client. Already processed.";
            $msg_type = "error";
        }
    }

    // Visitor approve
    if ($action === 'approve_visitor' && isset($_POST['visitor_id'])) {
        $visitor_id = intval($_POST['visitor_id']);
        $entry_time = trim($_POST['entry_time'] ?? "") ?: null;
        $exit_time  = trim($_POST['exit_time']  ?? "") ?: null;
        $sql  = "UPDATE Visitors_log SET Status = 'Approved', Entry_time = ?, Exit_time = ?
                 WHERE Visitor_ID = ? AND Status = 'Pending'";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "ssi", $entry_time, $exit_time, $visitor_id);
        if (mysqli_stmt_execute($stmt) && mysqli_stmt_affected_rows($stmt) > 0) {
            $message  = "Visitor approved.";
            $msg_type = "success";
        } else {
            $message  = "Could not approve visitor. Already processed.";
            $msg_type = "error";
        }
    }

    // Visitor reject
    if ($action === 'reject_visitor' && isset($_POST['visitor_id'])) {
        $visitor_id = intval($_POST['visitor_id']);
        $sql  = "UPDATE Visitors_log SET Status = 'Rejected'
                 WHERE Visitor_ID = ? AND Status = 'Pending'";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "i", $visitor_id);
        if (mysqli_stmt_execute($stmt) && mysqli_stmt_affected_rows($stmt) > 0) {
            $message  = "Visitor rejected.";
            $msg_type = "error";
        } else {
            $message  = "Could not reject visitor. Already processed.";
            $msg_type = "error";
        }
    }
}

// ── Stats ────────────────────────────────────────────────────────
$s = [];
foreach (['Pending','Approved','Rejected'] as $st) {
    $r = mysqli_query($conn, "SELECT COUNT(*) AS c FROM Client WHERE Status = '$st'");
    $s['client_' . strtolower($st)] = mysqli_fetch_assoc($r)['c'];
}
$r = mysqli_query($conn, "SELECT COUNT(*) AS c FROM Client");
$s['client_total'] = mysqli_fetch_assoc($r)['c'];

foreach (['Pending','Approved','Rejected'] as $st) {
    $r = mysqli_query($conn, "SELECT COUNT(*) AS c FROM Visitors_log WHERE Status = '$st'");
    $s['visitor_' . strtolower($st)] = mysqli_fetch_assoc($r)['c'];
}

// ── Fetch clients ────────────────────────────────────────────────
$cf = $_GET['filter'] ?? 'Pending';
if (!in_array($cf, ['Pending','Approved','Rejected','All'])) $cf = 'Pending';

if ($cf === 'All') {
    $cr = mysqli_query($conn,
        "SELECT c.ID AS Client_ID, c.Status, c.Guardian_name, c.Guardian_Phone, c.Approval_Date,
                u.F_name, u.M_name, u.L_name, u.Email, u.Reg_Date, p.Phone AS Phone_num
         FROM Client c
         INNER JOIN `User` u ON u.ID = c.Student_ID
         LEFT JOIN Phone p ON p.ID = u.Phone_ID
         ORDER BY FIELD(c.Status,'Pending','Approved','Rejected'), c.ID DESC");
} else {
    $cstmt = mysqli_prepare($conn,
        "SELECT c.ID AS Client_ID, c.Status, c.Guardian_name, c.Guardian_Phone, c.Approval_Date,
                u.F_name, u.M_name, u.L_name, u.Email, u.Reg_Date, p.Phone AS Phone_num
         FROM Client c
         INNER JOIN `User` u ON u.ID = c.Student_ID
         LEFT JOIN Phone p ON p.ID = u.Phone_ID
         WHERE c.Status = ? ORDER BY c.ID DESC");
    mysqli_stmt_bind_param($cstmt, "s", $cf);
    mysqli_stmt_execute($cstmt);
    $cr = mysqli_stmt_get_result($cstmt);
}
$clients = [];
while ($row = mysqli_fetch_assoc($cr)) $clients[] = $row;

// ── Fetch visitors ───────────────────────────────────────────────
$vf = $_GET['vfilter'] ?? 'Pending';
if (!in_array($vf, ['Pending','Approved','Rejected','All'])) $vf = 'Pending';

if ($vf === 'All') {
    $vr = mysqli_query($conn,
        "SELECT v.Visitor_ID, v.Visitor_Name, v.Visitor_Phone,
                v.Status, v.Requested_time, v.Entry_time, v.Exit_time,
                u.F_name, u.L_name, c.ID AS Client_ID
         FROM Visitors_log v
         INNER JOIN Client c ON c.ID = v.Client_id
         INNER JOIN `User` u ON u.ID = c.Student_ID
         ORDER BY FIELD(v.Status,'Pending','Approved','Rejected'), v.Visitor_ID DESC");
} else {
    $vstmt = mysqli_prepare($conn,
        "SELECT v.Visitor_ID, v.Visitor_Name, v.Visitor_Phone,
                v.Status, v.Requested_time, v.Entry_time, v.Exit_time,
                u.F_name, u.L_name, c.ID AS Client_ID
         FROM Visitors_log v
         INNER JOIN Client c ON c.ID = v.Client_id
         INNER JOIN `User` u ON u.ID = c.Student_ID
         WHERE v.Status = ? ORDER BY v.Visitor_ID DESC");
    mysqli_stmt_bind_param($vstmt, "s", $vf);
    mysqli_stmt_execute($vstmt);
    $vr = mysqli_stmt_get_result($vstmt);
}
$visitors = [];
while ($row = mysqli_fetch_assoc($vr)) $visitors[] = $row;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Manager Dashboard — Hostel MS</title>
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

  /* ── Sidebar ── */
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

  /* ── Main ── */
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

  /* Stats */
  .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 2rem; }
  .stat-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 1.25rem 1.5rem; }
  .stat-label { font-size: 11px; color: var(--muted); text-transform: uppercase; letter-spacing: .07em; margin-bottom: .5rem; }
  .stat-value { font-family: 'DM Serif Display', serif; font-size: 32px; line-height: 1; }
  .stat-value.p { color: var(--pending); } .stat-value.a { color: var(--approved); } .stat-value.r { color: var(--rejected); }

  /* Filter tabs */
  .filter-bar { display: flex; gap: 6px; margin-bottom: 1.25rem; }
  .ftab { padding: 5px 14px; border-radius: 20px; font-size: 12px; font-weight: 500; text-decoration: none; color: var(--muted); border: 1px solid var(--border); transition: all .15s; }
  .ftab:hover { color: var(--text); }
  .ftab.fp { background: var(--pending-bg);  border-color: var(--pending);  color: var(--pending);  }
  .ftab.fa { background: var(--approved-bg); border-color: var(--approved); color: var(--approved); }
  .ftab.fr { background: var(--rejected-bg); border-color: var(--rejected); color: var(--rejected); }
  .ftab.fall { background: var(--surface2);  border-color: var(--border2);  color: var(--text);     }

  /* Table */
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

  .action-group { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
  .btn { padding: 5px 12px; border-radius: 6px; font-size: 12px; font-weight: 500; border: none; cursor: pointer; font-family: 'DM Sans', sans-serif; transition: opacity .15s; }
  .btn-approve { background: var(--approved-bg); color: var(--approved); border: 1px solid rgba(109,171,126,.3); }
  .btn-approve:hover { background: rgba(109,171,126,.2); }
  .btn-reject  { background: var(--rejected-bg);  color: var(--rejected);  border: 1px solid rgba(212,101,90,.3); }
  .btn-reject:hover  { background: rgba(212,101,90,.2); }
  .btn-disabled { background: var(--surface3); color: var(--muted2); border: 1px solid var(--border); cursor: default; }

  /* Time inputs inline */
  .time-inline { display: flex; gap: 4px; align-items: center; }
  .time-inline input[type=time] { background: var(--surface2); border: 1px solid var(--border); border-radius: 6px; padding: 4px 8px; color: var(--text); font-family: 'DM Sans', sans-serif; font-size: 12px; outline: none; width: 90px; }
  .time-inline input[type=time]:focus { border-color: var(--accent); }
  .time-label { font-size: 10px; color: var(--muted); }

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
      <div class="logo-sub">Manager Portal</div>
    </div>
  </div>
  <nav class="sidebar-nav">
    <div class="nav-label">Management</div>
    <a class="nav-item <?= $active_tab === 'registrations' ? 'active' : '' ?>" href="?tab=registrations">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>
      Registrations
      <?php if ($s['client_pending'] > 0): ?><span class="nbadge"><?= $s['client_pending'] ?></span><?php endif; ?>
    </a>
    <a class="nav-item <?= $active_tab === 'visitors' ? 'active' : '' ?>" href="?tab=visitors">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
      Visitor Requests
      <?php if ($s['visitor_pending'] > 0): ?><span class="nbadge"><?= $s['visitor_pending'] ?></span><?php endif; ?>
    </a>
  </nav>
  <div class="sidebar-footer">
    <div class="user-chip">
      <div class="avatar"><?= strtoupper(substr($manager_name, 0, 1)) ?></div>
      <div class="user-info">
        <div class="user-name"><?= htmlspecialchars($manager_name) ?></div>
        <div class="user-role">Manager</div>
      </div>
    </div>
    <a href="logout.php" class="logout-btn">Sign out</a>
  </div>
</aside>

<main class="main">
  <div class="page-title">
    <?= $active_tab === 'registrations' ? 'Client Registrations' : 'Visitor Requests' ?>
  </div>
  <div class="page-sub">
    <?= $active_tab === 'registrations'
      ? 'Approve or reject client account applications'
      : 'Approve or reject visitor requests from clients' ?>
  </div>

  <?php if ($message): ?>
    <div class="toast <?= $msg_type ?>"><?= htmlspecialchars($message) ?></div>
  <?php endif; ?>

  <div class="tabs">
    <a class="tab <?= $active_tab === 'registrations' ? 'active' : '' ?>" href="?tab=registrations">Registrations</a>
    <a class="tab <?= $active_tab === 'visitors'      ? 'active' : '' ?>" href="?tab=visitors">Visitor Requests</a>
  </div>

  <!-- ── REGISTRATIONS TAB ── -->
  <?php if ($active_tab === 'registrations'): ?>

    <div class="stats-grid">
      <div class="stat-card"><div class="stat-label">Total</div><div class="stat-value"><?= $s['client_total'] ?></div></div>
      <div class="stat-card"><div class="stat-label">Pending</div><div class="stat-value p"><?= $s['client_pending'] ?></div></div>
      <div class="stat-card"><div class="stat-label">Approved</div><div class="stat-value a"><?= $s['client_approved'] ?></div></div>
      <div class="stat-card"><div class="stat-label">Rejected</div><div class="stat-value r"><?= $s['client_rejected'] ?></div></div>
    </div>

    <?php
    $ctabs = ['Pending' => 'fp', 'Approved' => 'fa', 'Rejected' => 'fr', 'All' => 'fall'];
    ?>
    <div class="filter-bar">
      <?php foreach ($ctabs as $label => $cls): ?>
        <a class="ftab <?= $cf === $label ? $cls : '' ?>"
           href="?tab=registrations&filter=<?= $label ?>">
          <?= $label ?><?= $label === 'Pending' && $s['client_pending'] > 0 ? ' (' . $s['client_pending'] . ')' : '' ?>
        </a>
      <?php endforeach; ?>
    </div>

    <div class="table-wrap">
      <?php if (empty($clients)): ?>
        <div class="empty-state">No <?= strtolower($cf) ?> registrations found.</div>
      <?php else: ?>
        <table>
          <thead>
            <tr><th>#</th><th>Client</th><th>Guardian</th><th>Registered</th><th>Status</th><th>Actions</th></tr>
          </thead>
          <tbody>
            <?php foreach ($clients as $c):
              $full = trim($c['F_name'] . ' ' . $c['M_name'] . ' ' . $c['L_name']);
              $st   = $c['Status'];
            ?>
            <tr>
              <td class="cell-muted"><?= $c['Client_ID'] ?></td>
              <td>
                <div class="fw500"><?= htmlspecialchars($full) ?></div>
                <div class="cell-muted"><?= htmlspecialchars($c['Email']) ?></div>
                <?php if ($c['Phone_num']): ?><div class="cell-muted"><?= htmlspecialchars($c['Phone_num']) ?></div><?php endif; ?>
              </td>
              <td>
                <div><?= htmlspecialchars($c['Guardian_name']) ?></div>
                <?php if ($c['Guardian_Phone']): ?><div class="cell-muted"><?= htmlspecialchars($c['Guardian_Phone']) ?></div><?php endif; ?>
              </td>
              <td class="cell-muted">
                <?= $c['Reg_Date'] ? date('d M Y', strtotime($c['Reg_Date'])) : '—' ?>
                <?php if ($c['Approval_Date']): ?><div><?= date('d M Y', strtotime($c['Approval_Date'])) ?></div><?php endif; ?>
              </td>
              <td><span class="badge badge-<?= strtolower($st) ?>"><?= $st ?></span></td>
              <td>
                <?php if ($st === 'Pending'): ?>
                  <div class="action-group">
                    <form method="POST" action="?tab=registrations&filter=<?= urlencode($cf) ?>" style="display:inline;">
                      <input type="hidden" name="action" value="approve_client">
                      <input type="hidden" name="client_id" value="<?= $c['Client_ID'] ?>">
                      <button class="btn btn-approve" onclick="return confirm('Approve <?= htmlspecialchars(addslashes($full)) ?>?')">Approve</button>
                    </form>
                    <form method="POST" action="?tab=registrations&filter=<?= urlencode($cf) ?>" style="display:inline;">
                      <input type="hidden" name="action" value="reject_client">
                      <input type="hidden" name="client_id" value="<?= $c['Client_ID'] ?>">
                      <button class="btn btn-reject" onclick="return confirm('Reject <?= htmlspecialchars(addslashes($full)) ?>?')">Reject</button>
                    </form>
                  </div>
                <?php else: ?>
                  <span class="btn btn-disabled">No action</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

  <!-- ── VISITORS TAB ── -->
  <?php elseif ($active_tab === 'visitors'): ?>

    <div class="stats-grid">
      <div class="stat-card"><div class="stat-label">Total requests</div><div class="stat-value"><?= $s['visitor_pending'] + $s['visitor_approved'] + $s['visitor_rejected'] ?></div></div>
      <div class="stat-card"><div class="stat-label">Pending</div><div class="stat-value p"><?= $s['visitor_pending'] ?></div></div>
      <div class="stat-card"><div class="stat-label">Approved</div><div class="stat-value a"><?= $s['visitor_approved'] ?></div></div>
      <div class="stat-card"><div class="stat-label">Rejected</div><div class="stat-value r"><?= $s['visitor_rejected'] ?></div></div>
    </div>

    <?php $vtabs = ['Pending' => 'fp', 'Approved' => 'fa', 'Rejected' => 'fr', 'All' => 'fall']; ?>
    <div class="filter-bar">
      <?php foreach ($vtabs as $label => $cls): ?>
        <a class="ftab <?= $vf === $label ? $cls : '' ?>"
           href="?tab=visitors&vfilter=<?= $label ?>">
          <?= $label ?><?= $label === 'Pending' && $s['visitor_pending'] > 0 ? ' (' . $s['visitor_pending'] . ')' : '' ?>
        </a>
      <?php endforeach; ?>
    </div>

    <div class="table-wrap">
      <?php if (empty($visitors)): ?>
        <div class="empty-state">No <?= strtolower($vf) ?> visitor requests found.</div>
      <?php else: ?>
        <table>
          <thead>
            <tr><th>#</th><th>Visitor</th><th>Client</th><th>Requested time</th><th>Entry / Exit</th><th>Status</th><th>Actions</th></tr>
          </thead>
          <tbody>
            <?php foreach ($visitors as $v):
              $st        = $v['Status'];
              $cname     = htmlspecialchars($v['F_name'] . ' ' . $v['L_name']);
            ?>
            <tr>
              <td class="cell-muted"><?= $v['Visitor_ID'] ?></td>
              <td>
                <div class="fw500"><?= htmlspecialchars($v['Visitor_Name']) ?></div>
                <?php if ($v['Visitor_Phone']): ?><div class="cell-muted"><?= htmlspecialchars($v['Visitor_Phone']) ?></div><?php endif; ?>
              </td>
              <td>
                <div><?= $cname ?></div>
                <div class="cell-muted">Client #<?= $v['Client_ID'] ?></div>
              </td>
              <td class="cell-muted"><?= $v['Requested_time'] ? substr($v['Requested_time'], 0, 5) : '—' ?></td>
              <td class="cell-muted">
                <?php if ($st === 'Approved'): ?>
                  <?= $v['Entry_time'] ? substr($v['Entry_time'], 0, 5) : '—' ?> /
                  <?= $v['Exit_time']  ? substr($v['Exit_time'],  0, 5) : '—' ?>
                <?php else: ?>
                  —
                <?php endif; ?>
              </td>
              <td><span class="badge badge-<?= strtolower($st) ?>"><?= $st ?></span></td>
              <td>
                <?php if ($st === 'Pending'): ?>
                  <form method="POST" action="?tab=visitors&vfilter=<?= urlencode($vf) ?>">
                    <input type="hidden" name="action" value="approve_visitor">
                    <input type="hidden" name="visitor_id" value="<?= $v['Visitor_ID'] ?>">
                    <div class="action-group">
                      <div>
                        <div class="time-label" style="margin-bottom:3px;">Entry</div>
                        <div class="time-inline">
                          <input type="time" name="entry_time" value="<?= htmlspecialchars($v['Requested_time'] ?? '') ?>">
                        </div>
                      </div>
                      <div>
                        <div class="time-label" style="margin-bottom:3px;">Exit</div>
                        <div class="time-inline">
                          <input type="time" name="exit_time">
                        </div>
                      </div>
                      <div style="padding-top:18px;">
                        <button class="btn btn-approve" type="submit">Approve</button>
                      </div>
                    </div>
                  </form>
                  <form method="POST" action="?tab=visitors&vfilter=<?= urlencode($vf) ?>" style="margin-top:6px;">
                    <input type="hidden" name="action" value="reject_visitor">
                    <input type="hidden" name="visitor_id" value="<?= $v['Visitor_ID'] ?>">
                    <button class="btn btn-reject" type="submit" onclick="return confirm('Reject this visitor request?')">Reject</button>
                  </form>
                <?php else: ?>
                  <span class="btn btn-disabled">No action</span>
                <?php endif; ?>
              </td>
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
