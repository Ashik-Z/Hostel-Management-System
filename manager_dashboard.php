<?php
session_start();
require_once('config/db_connection.php');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'manager') {
    header("Location: login.php"); exit();
}

$manager_id   = $_SESSION['manager_id'];
$manager_name = $_SESSION['name'];
$message  = htmlspecialchars($_GET['msg'] ?? "");
$msg_type = in_array($_GET['mt'] ?? '', ['success','error']) ? $_GET['mt'] : "";
$active_tab   = $_GET['tab'] ?? 'registrations';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if (in_array($action, ['approve_client','reject_client']) && isset($_POST['client_id'])) {
        $cid    = intval($_POST['client_id']);
        $status = $action === 'approve_client' ? 'Approved' : 'Rejected';
        $stmt   = mysqli_prepare($conn, "UPDATE Client SET Status=?, Approved_by=?, Approval_Date=CURDATE() WHERE ID=? AND Status='Pending'");
        mysqli_stmt_bind_param($stmt, "sii", $status, $manager_id, $cid);
        if (mysqli_stmt_execute($stmt) && mysqli_stmt_affected_rows($stmt) > 0) {
            header("Location: ?tab=registrations&filter=" . urlencode($_GET['filter'] ?? 'Pending') . "&msg=" . urlencode("Client $status successfully.") . "&mt=" . ($status==='Approved'?'success':'error'));
            exit();
        } else { $message = "Could not update. Already processed."; $msg_type = "error"; }
    }

    if ($action === 'approve_visitor' && isset($_POST['visitor_id'])) {
        $vid   = intval($_POST['visitor_id']);
        $entry = trim($_POST['entry_time'] ?? "") ?: null;
        $exit  = trim($_POST['exit_time']  ?? "") ?: null;
        $stmt  = mysqli_prepare($conn, "UPDATE Visitors_log SET Status='Approved', Entry_time=?, Exit_time=? WHERE Visitor_ID=? AND Status='Pending'");
        mysqli_stmt_bind_param($stmt, "ssi", $entry, $exit, $vid);
        if (mysqli_stmt_execute($stmt) && mysqli_stmt_affected_rows($stmt) > 0) {
            header("Location: ?tab=visitors&vfilter=" . urlencode($_GET['vfilter'] ?? 'Pending') . "&msg=" . urlencode("Visitor approved.") . "&mt=success");
            exit();
        }
        else { $message = "Could not approve. Already processed."; $msg_type = "error"; }
    }
    if ($action === 'reject_visitor' && isset($_POST['visitor_id'])) {
        $vid  = intval($_POST['visitor_id']);
        $stmt = mysqli_prepare($conn, "UPDATE Visitors_log SET Status='Rejected' WHERE Visitor_ID=? AND Status='Pending'");
        mysqli_stmt_bind_param($stmt, "i", $vid);
        if (mysqli_stmt_execute($stmt) && mysqli_stmt_affected_rows($stmt) > 0) {
            header("Location: ?tab=visitors&vfilter=" . urlencode($_GET['vfilter'] ?? 'Pending') . "&msg=" . urlencode("Visitor rejected.") . "&mt=error");
            exit();
        }
        else { $message = "Could not reject. Already processed."; $msg_type = "error"; }
    }

    if ($action === 'allocate_room' && isset($_POST['booking_id'])) {
        $booking_id  = intval($_POST['booking_id']);
        $floor_num   = intval($_POST['floor_num']);
        $room_pos    = intval($_POST['room_num']);         // position within floor (1–20)
        $room_num    = $floor_num * 100 + $room_pos;      // combined: e.g. floor 1 pos 1 → 101
        $bed_num     = intval($_POST['bed_num']);
        $checkin     = trim($_POST['check_in_date'] ?? "") ?: date('Y-m-d');
        $alloc_cid   = intval($_POST['alloc_client_id']);

        $room_stmt = mysqli_prepare($conn, "SELECT r.Room_type, r.Capacity, r.Status, ba.Room_type_requested,
            (SELECT COUNT(*) FROM Stays_IN WHERE Floor_NUM=r.Floor_num AND Room_NUm=r.Room_num) AS occupants
            FROM Room r
            INNER JOIN Booking_Allocation ba ON ba.Booking_ID = ?
            WHERE r.Floor_num=? AND r.Room_num=? LIMIT 1");
        mysqli_stmt_bind_param($room_stmt, "iii", $booking_id, $floor_num, $room_num);
        mysqli_stmt_execute($room_stmt);
        $room = mysqli_fetch_assoc(mysqli_stmt_get_result($room_stmt));

        if (!$room) {
            $message = "Room not found."; $msg_type = "error";
        } elseif ($room['Status'] === 'Unavailable') {
            $message = "That room is marked Unavailable."; $msg_type = "error";
        } elseif ($room['Room_type'] !== $room['Room_type_requested']) {
            $message = "Room type mismatch. Client requested {$room['Room_type_requested']}."; $msg_type = "error";
        } elseif ($room['occupants'] >= $room['Capacity']) {
            $message = "Room is already at full capacity ({$room['Capacity']} clients)."; $msg_type = "error";
        } else {
            mysqli_begin_transaction($conn);
            try {
                $upd = mysqli_prepare($conn, "UPDATE Booking_Allocation SET Booking_status='Allocated', Floor_num=?, Room_num=?, Bed_num=?, Check_in_date=? WHERE Booking_ID=?");
                mysqli_stmt_bind_param($upd, "iissi", $floor_num, $room_num, $bed_num, $checkin, $booking_id);
                if (!mysqli_stmt_execute($upd)) throw new Exception(mysqli_error($conn));

                $ins = mysqli_prepare($conn, "INSERT IGNORE INTO Stays_IN (client_id, Floor_NUM, Room_NUm) VALUES (?,?,?)");
                mysqli_stmt_bind_param($ins, "iii", $alloc_cid, $floor_num, $room_num);
                if (!mysqli_stmt_execute($ins)) throw new Exception(mysqli_error($conn));

                $occ_res = mysqli_prepare($conn, "SELECT COUNT(*) AS c FROM Stays_IN WHERE Floor_NUM=? AND Room_NUm=?");
                mysqli_stmt_bind_param($occ_res, "ii", $floor_num, $room_num);
                mysqli_stmt_execute($occ_res);
                $new_occ = mysqli_fetch_assoc(mysqli_stmt_get_result($occ_res))['c'];
                if ($new_occ >= $room['Capacity']) {
                    $upd_room = mysqli_prepare($conn, "UPDATE Room SET Status='Unavailable' WHERE Floor_num=? AND Room_num=?");
                    mysqli_stmt_bind_param($upd_room, "ii", $floor_num, $room_num);
                    mysqli_stmt_execute($upd_room);
                }

                mysqli_commit($conn);
                header("Location: ?tab=rooms&rtab=bookings&bfilter=" . urlencode($_GET['bfilter'] ?? 'Pending') . "&msg=" . urlencode("Room allocated successfully — Floor $floor_num, Room $room_num, Bed $bed_num.") . "&mt=success");
                exit();
            } catch (Exception $e) {
                mysqli_rollback($conn);
                $message = "Allocation failed: " . $e->getMessage(); $msg_type = "error";
            }
        }
    }

    
    if ($action === 'save_room') {
        $fl    = intval($_POST['floor_num']);
        $rm    = intval($_POST['room_num']);   
        $rtype = trim($_POST['room_type']);
        $cap   = intval($_POST['capacity']);
        $rst   = trim($_POST['room_status']);

        $rm_combined = $fl * 100 + $rm;       // uniquely identifying room by floor num and room num.

        if ($fl<1||$fl>6||$rm<1||$rm>20) {
            $message = "Floor must be 1–6, room position must be 1–20."; $msg_type = "error";
        } else {
            $stmt = mysqli_prepare($conn, "INSERT INTO Room (Floor_num, Room_num, Room_type, Capacity, Status) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE Room_type=VALUES(Room_type), Capacity=VALUES(Capacity), Status=VALUES(Status)");
            mysqli_stmt_bind_param($stmt, "iisis", $fl, $rm_combined, $rtype, $cap, $rst);
            if (mysqli_stmt_execute($stmt)) {
                header("Location: ?tab=rooms&rtab=manage&msg=" . urlencode("Room Floor $fl / Room $rm_combined saved.") . "&mt=success");
                exit();
            }
            else { $message = "Failed: " . mysqli_error($conn); $msg_type = "error"; }
        }
    }
}


function qcount($conn, $sql) { return mysqli_fetch_assoc(mysqli_query($conn,$sql))['c']; }
$s = [
    'cpending'  => qcount($conn,"SELECT COUNT(*) AS c FROM Client WHERE Status='Pending'"),
    'capproved' => qcount($conn,"SELECT COUNT(*) AS c FROM Client WHERE Status='Approved'"),
    'crejected' => qcount($conn,"SELECT COUNT(*) AS c FROM Client WHERE Status='Rejected'"),
    'ctotal'    => qcount($conn,"SELECT COUNT(*) AS c FROM Client"),
    'vpending'  => qcount($conn,"SELECT COUNT(*) AS c FROM Visitors_log WHERE Status='Pending'"),
    'vapproved' => qcount($conn,"SELECT COUNT(*) AS c FROM Visitors_log WHERE Status='Approved'"),
    'vrejected' => qcount($conn,"SELECT COUNT(*) AS c FROM Visitors_log WHERE Status='Rejected'"),
    'bpending'  => qcount($conn,"SELECT COUNT(*) AS c FROM Booking_Allocation WHERE Booking_status='Pending'"),
    'ballocated'=> qcount($conn,"SELECT COUNT(*) AS c FROM Booking_Allocation WHERE Booking_status='Allocated'"),
    'rac_avail' => qcount($conn,"SELECT COUNT(*) AS c FROM Room WHERE Room_type='AC' AND Status='Available'"),
    'rna_avail' => qcount($conn,"SELECT COUNT(*) AS c FROM Room WHERE Room_type='Non-AC' AND Status='Available'"),
    'rac_total' => qcount($conn,"SELECT COUNT(*) AS c FROM Room WHERE Room_type='AC'"),
    'rna_total' => qcount($conn,"SELECT COUNT(*) AS c FROM Room WHERE Room_type='Non-AC'"),
    'acc_unpaid' => qcount($conn,"SELECT COUNT(*) AS c FROM (SELECT c.ID FROM Client c LEFT JOIN Accountings a ON a.Client_ID=c.ID LEFT JOIN Payment_Record pr ON pr.Client_ID=c.ID WHERE c.Status='Approved' GROUP BY c.ID HAVING COALESCE(SUM(a.Price),0)-COALESCE(SUM(pr.Payment_Amount),0)>0) x"),
    'acc_paid'   => qcount($conn,"SELECT COUNT(*) AS c FROM (SELECT c.ID FROM Client c LEFT JOIN Accountings a ON a.Client_ID=c.ID LEFT JOIN Payment_Record pr ON pr.Client_ID=c.ID WHERE c.Status='Approved' GROUP BY c.ID HAVING COALESCE(SUM(a.Price),0)-COALESCE(SUM(pr.Payment_Amount),0)<=0) x"),
];


$cf = $_GET['filter'] ?? 'Pending';
if (!in_array($cf,['Pending','Approved','Rejected','All'])) $cf='Pending';
if ($cf==='All') {
    $cr = mysqli_query($conn,"SELECT c.ID AS Client_ID,c.Status,c.Guardian_name,c.Guardian_Phone,c.Approval_Date,u.F_name,u.M_name,u.L_name,u.Email,u.Reg_Date,p.Phone AS Phone_num FROM Client c INNER JOIN `User` u ON u.ID=c.Student_ID LEFT JOIN Phone p ON p.ID=u.Phone_ID ORDER BY FIELD(c.Status,'Pending','Approved','Rejected'),c.ID DESC");
} else {
    $cst=mysqli_prepare($conn,"SELECT c.ID AS Client_ID,c.Status,c.Guardian_name,c.Guardian_Phone,c.Approval_Date,u.F_name,u.M_name,u.L_name,u.Email,u.Reg_Date,p.Phone AS Phone_num FROM Client c INNER JOIN `User` u ON u.ID=c.Student_ID LEFT JOIN Phone p ON p.ID=u.Phone_ID WHERE c.Status=? ORDER BY c.ID DESC");
    mysqli_stmt_bind_param($cst,"s",$cf); mysqli_stmt_execute($cst); $cr=mysqli_stmt_get_result($cst);
}
$clients=[]; while($row=mysqli_fetch_assoc($cr)) $clients[]=$row;


$vf = $_GET['vfilter'] ?? 'Pending';
if (!in_array($vf,['Pending','Approved','Rejected','All'])) $vf='Pending';
if ($vf==='All') {
    $vr=mysqli_query($conn,"SELECT v.Visitor_ID,v.Visitor_Name,v.Visitor_Phone,v.Status,v.Requested_time,v.Entry_time,v.Exit_time,u.F_name,u.L_name,c.ID AS Client_ID FROM Visitors_log v INNER JOIN Client c ON c.ID=v.Client_id INNER JOIN `User` u ON u.ID=c.Student_ID ORDER BY FIELD(v.Status,'Pending','Approved','Rejected'),v.Visitor_ID DESC");
} else {
    $vst=mysqli_prepare($conn,"SELECT v.Visitor_ID,v.Visitor_Name,v.Visitor_Phone,v.Status,v.Requested_time,v.Entry_time,v.Exit_time,u.F_name,u.L_name,c.ID AS Client_ID FROM Visitors_log v INNER JOIN Client c ON c.ID=v.Client_id INNER JOIN `User` u ON u.ID=c.Student_ID WHERE v.Status=? ORDER BY v.Visitor_ID DESC");
    mysqli_stmt_bind_param($vst,"s",$vf); mysqli_stmt_execute($vst); $vr=mysqli_stmt_get_result($vst);
}
$visitors=[]; while($row=mysqli_fetch_assoc($vr)) $visitors[]=$row;


$bf = $_GET['bfilter'] ?? 'Pending';
if (!in_array($bf,['Pending','Allocated','All'])) $bf='Pending';
if ($bf==='All') {
    $br=mysqli_query($conn,"SELECT ba.Booking_ID,ba.Booking_status,ba.Room_type_requested,ba.Booking_date,ba.Check_in_date,ba.Bed_num,ba.Floor_num,ba.Room_num,u.F_name,u.L_name,u.Email,c.ID AS Client_ID FROM Booking_Allocation ba INNER JOIN Client c ON c.ID=ba.client_id INNER JOIN `User` u ON u.ID=c.Student_ID ORDER BY FIELD(ba.Booking_status,'Pending','Allocated'),ba.Booking_ID DESC");
} else {
    $bst=mysqli_prepare($conn,"SELECT ba.Booking_ID,ba.Booking_status,ba.Room_type_requested,ba.Booking_date,ba.Check_in_date,ba.Bed_num,ba.Floor_num,ba.Room_num,u.F_name,u.L_name,u.Email,c.ID AS Client_ID FROM Booking_Allocation ba INNER JOIN Client c ON c.ID=ba.client_id INNER JOIN `User` u ON u.ID=c.Student_ID WHERE ba.Booking_status=? ORDER BY ba.Booking_ID DESC");
    mysqli_stmt_bind_param($bst,"s",$bf); mysqli_stmt_execute($bst); $br=mysqli_stmt_get_result($bst);
}
$bookings=[]; while($row=mysqli_fetch_assoc($br)) $bookings[]=$row;


$room_grid = [];
$rg = mysqli_query($conn,"SELECT r.Floor_num,r.Room_num,r.Room_type,r.Capacity,r.Status,(SELECT COUNT(*) FROM Stays_IN si WHERE si.Floor_NUM=r.Floor_num AND si.Room_NUm=r.Room_num) AS occupants FROM Room r ORDER BY r.Floor_num,r.Room_num");
while ($row=mysqli_fetch_assoc($rg)) $room_grid[$row['Floor_num']][$row['Room_num']] = $row;


$acc_filter = $_GET['afilter'] ?? 'All';
if (!in_array($acc_filter, ['All','Unpaid','Paid'])) $acc_filter = 'All';

$acc_sql = "
    SELECT
        c.ID                                        AS Client_ID,
        u.F_name, u.L_name, u.Email,
        COALESCE(SUM(a.Price), 0)                   AS total_charges,
        COALESCE(pr.total_paid, 0)                  AS total_paid,
        COALESCE(SUM(a.Price), 0)
            - COALESCE(pr.total_paid, 0)            AS outstanding,
        COUNT(CASE WHEN a.Entry_Type='Room' THEN 1 END) AS room_entries,
        COUNT(CASE WHEN a.Entry_Type='Meal' THEN 1 END) AS meal_entries,
        pr.last_payment                             AS last_payment_date
    FROM Client c
    INNER JOIN `User` u ON u.ID = c.Student_ID
    LEFT JOIN Accountings a ON a.Client_ID = c.ID
    LEFT JOIN (
        SELECT Client_ID,
               SUM(Payment_Amount) AS total_paid,
               MAX(Payment_Date)   AS last_payment
        FROM Payment_Record
        GROUP BY Client_ID
    ) pr ON pr.Client_ID = c.ID
    WHERE c.Status = 'Approved'
    GROUP BY c.ID, u.F_name, u.L_name, u.Email, pr.total_paid, pr.last_payment
    HAVING " . ($acc_filter === 'Unpaid' ? "outstanding > 0" : ($acc_filter === 'Paid' ? "outstanding <= 0" : "1=1")) . "
    ORDER BY outstanding DESC, c.ID ASC";

$acc_rows = [];
$acc_res = mysqli_query($conn, $acc_sql);
while ($row = mysqli_fetch_assoc($acc_res)) $acc_rows[] = $row;

$acc_total_charges    = array_sum(array_column($acc_rows, 'total_charges'));
$acc_total_paid       = array_sum(array_column($acc_rows, 'total_paid'));
$acc_total_outstanding = array_sum(array_column($acc_rows, 'outstanding'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Manager Dashboard — Hostel MS</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
  *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
  :root{--bg:#0f0e0c;--surface:#1a1916;--surface2:#222120;--surface3:#292826;--border:#2e2d2a;--border2:#3a3936;--accent:#c9a96e;--accent2:#e8c98a;--text:#f0ede6;--muted:#8a8780;--muted2:#6a6865;--approved:#6dab7e;--approved-bg:rgba(109,171,126,.1);--pending:#c9a96e;--pending-bg:rgba(201,169,110,.1);--rejected:#d4655a;--rejected-bg:rgba(212,101,90,.1);--radius:10px;--sidebar:240px}
  body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;display:flex}
  .sidebar{width:var(--sidebar);min-height:100vh;background:var(--surface);border-right:1px solid var(--border);display:flex;flex-direction:column;position:fixed;top:0;left:0;z-index:100;overflow-y:auto}
  .sidebar-logo{padding:1.5rem;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px}
  .logo-icon{width:32px;height:32px;background:var(--accent);border-radius:7px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
  .logo-icon svg{width:18px;height:18px;fill:#0f0e0c}
  .logo-text{font-family:'DM Serif Display',serif;font-size:15px;line-height:1.2}
  .logo-sub{font-size:10px;color:var(--muted);letter-spacing:.08em;text-transform:uppercase}
  .sidebar-nav{flex:1;padding:1rem 0}
  .nav-label{font-size:10px;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:var(--muted2);padding:.5rem 1.25rem .25rem}
  .nav-item{display:flex;align-items:center;gap:10px;padding:.65rem 1.25rem;font-size:13.5px;color:var(--muted);text-decoration:none;transition:color .15s,background .15s}
  .nav-item svg{width:16px;height:16px;flex-shrink:0}
  .nav-item:hover{color:var(--text);background:var(--surface2)}
  .nav-item.active{color:var(--accent);background:rgba(201,169,110,.08)}
  .nbadge{margin-left:auto;background:var(--pending);color:#0f0e0c;font-size:10px;font-weight:600;border-radius:20px;padding:1px 7px}
  .sidebar-footer{padding:1rem 1.25rem;border-top:1px solid var(--border)}
  .user-chip{display:flex;align-items:center;gap:10px;padding:.6rem .75rem;background:var(--surface2);border:1px solid var(--border);border-radius:var(--radius)}
  .avatar{width:30px;height:30px;background:var(--accent);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:600;color:#0f0e0c;flex-shrink:0}
  .user-info{flex:1;min-width:0}
  .user-name{font-size:12px;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .user-role{font-size:10px;color:var(--accent)}
  .logout-btn{font-size:11px;color:var(--muted);text-decoration:none;display:block;text-align:center;margin-top:.75rem;transition:color .15s}
  .logout-btn:hover{color:var(--rejected)}
  .main{margin-left:var(--sidebar);flex:1;padding:2rem}
  .page-title{font-family:'DM Serif Display',serif;font-size:24px;font-weight:400}
  .page-sub{font-size:13px;color:var(--muted);margin-top:2px;margin-bottom:1.75rem}
  .tabs{display:flex;gap:4px;border-bottom:1px solid var(--border);margin-bottom:2rem;flex-wrap:wrap}
  .tab{padding:.6rem 1.1rem;font-size:13px;font-weight:500;color:var(--muted);text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-1px;transition:color .15s,border-color .15s;white-space:nowrap}
  .tab:hover{color:var(--text)}
  .tab.active{color:var(--accent);border-bottom-color:var(--accent)}
  .toast{padding:10px 16px;border-radius:var(--radius);font-size:13px;margin-bottom:1.5rem}
  .toast.success{background:var(--approved-bg);border:1px solid rgba(109,171,126,.3);color:var(--approved)}
  .toast.error{background:var(--rejected-bg);border:1px solid rgba(212,101,90,.3);color:var(--rejected)}
  .stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:2rem}
  .stat-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:1.25rem 1.5rem}
  .stat-label{font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:.5rem}
  .stat-val{font-family:'DM Serif Display',serif;font-size:32px;line-height:1}
  .stat-val.p{color:var(--pending)}.stat-val.a{color:var(--approved)}.stat-val.r{color:var(--rejected)}
  .filter-bar{display:flex;gap:6px;margin-bottom:1.25rem;flex-wrap:wrap}
  .ftab{padding:5px 14px;border-radius:20px;font-size:12px;font-weight:500;text-decoration:none;color:var(--muted);border:1px solid var(--border);transition:all .15s}
  .ftab:hover{color:var(--text)}
  .ftab.fp{background:var(--pending-bg);border-color:var(--pending);color:var(--pending)}
  .ftab.fa{background:var(--approved-bg);border-color:var(--approved);color:var(--approved)}
  .ftab.fr{background:var(--rejected-bg);border-color:var(--rejected);color:var(--rejected)}
  .ftab.fall{background:var(--surface2);border-color:var(--border2);color:var(--text)}
  .table-wrap{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden}
  table{width:100%;border-collapse:collapse}
  thead th{padding:.7rem 1.25rem;text-align:left;font-size:11px;font-weight:600;letter-spacing:.07em;text-transform:uppercase;color:var(--muted);background:var(--surface2);border-bottom:1px solid var(--border)}
  tbody tr{border-bottom:1px solid var(--border);transition:background .1s}
  tbody tr:last-child{border-bottom:none}
  tbody tr:hover{background:var(--surface2)}
  td{padding:.85rem 1.25rem;font-size:13.5px;vertical-align:middle}
  .cell-muted{font-size:12px;color:var(--muted)}
  .fw500{font-weight:500}
  .badge{display:inline-flex;align-items:center;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600}
  .badge-pending{background:var(--pending-bg);color:var(--pending)}
  .badge-approved{background:var(--approved-bg);color:var(--approved)}
  .badge-rejected{background:var(--rejected-bg);color:var(--rejected)}
  .badge-allocated{background:var(--approved-bg);color:var(--approved)}
  .badge-available{background:var(--approved-bg);color:var(--approved)}
  .badge-unavailable{background:var(--rejected-bg);color:var(--rejected)}
  .btn{padding:5px 12px;border-radius:6px;font-size:12px;font-weight:500;border:none;cursor:pointer;font-family:'DM Sans',sans-serif;transition:opacity .15s}
  .btn-approve{background:var(--approved-bg);color:var(--approved);border:1px solid rgba(109,171,126,.3)}
  .btn-approve:hover{background:rgba(109,171,126,.2)}
  .btn-reject{background:var(--rejected-bg);color:var(--rejected);border:1px solid rgba(212,101,90,.3)}
  .btn-reject:hover{background:rgba(212,101,90,.2)}
  .btn-disabled{background:var(--surface3);color:var(--muted2);border:1px solid var(--border);cursor:default}
  .action-group{display:flex;gap:6px;align-items:center;flex-wrap:wrap}
  .time-label{font-size:10px;color:var(--muted);margin-bottom:3px}
  .time-input{background:var(--surface2);border:1px solid var(--border);border-radius:6px;padding:4px 8px;color:var(--text);font-family:'DM Sans',sans-serif;font-size:12px;outline:none;width:90px}
  .time-input:focus{border-color:var(--accent)}
  .empty-state{text-align:center;padding:3rem 2rem;color:var(--muted);font-size:13px}
  /* Room grid */
  .room-summary{display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:2rem}
  .rsm-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:1.25rem 1.5rem}
  .rsm-label{font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:.5rem}
  .rsm-val{font-family:'DM Serif Display',serif;font-size:28px;line-height:1}
  .rsm-val.g{color:var(--approved)}.rsm-val.r{color:var(--rejected)}
  .floor-section{margin-bottom:2rem}
  .floor-header{display:flex;align-items:center;gap:.75rem;margin-bottom:.75rem}
  .floor-title{font-family:'DM Serif Display',serif;font-size:17px;font-weight:400}
  .floor-sub{font-size:12px;color:var(--muted)}
  .room-cells{display:grid;grid-template-columns:repeat(10,1fr);gap:.4rem}
  .room-cell{background:var(--surface2);border:1px solid var(--border);border-radius:6px;padding:.5rem .25rem;text-align:center;cursor:default;transition:border-color .15s;position:relative}
  .room-cell.available{border-color:rgba(109,171,126,.25)}
  .room-cell.full{background:rgba(212,101,90,.07);border-color:rgba(212,101,90,.3)}
  .room-cell.unavailable{background:var(--surface3);border-color:var(--border);opacity:.6}
  .room-cell:hover{border-color:var(--accent)}
  .rc-num{font-size:12px;font-weight:600;color:var(--text)}
  .rc-occ{font-size:10px;color:var(--muted);margin-top:2px}
  .rc-type{font-size:9px;color:var(--muted2)}
  .rc-dot{width:6px;height:6px;border-radius:50%;margin:3px auto 0;background:var(--muted2)}
  .rc-dot.g{background:var(--approved)}.rc-dot.r{background:var(--rejected)}.rc-dot.y{background:var(--pending)}
  .room-legend{display:flex;gap:1rem;margin-bottom:1.25rem;flex-wrap:wrap}
  .legend-item{display:flex;align-items:center;gap:5px;font-size:12px;color:var(--muted)}
  .legend-dot{width:8px;height:8px;border-radius:50%}
  /* Allocation form */
  .alloc-form-wrap{background:var(--surface2);border:1px solid var(--border);border-radius:var(--radius);padding:1.25rem;margin-top:.75rem}
  .alloc-form-wrap h4{font-size:11px;font-weight:600;color:var(--accent);text-transform:uppercase;letter-spacing:.08em;margin-bottom:1rem}
  .alloc-grid{display:grid;grid-template-columns:repeat(5,1fr) auto;gap:.6rem;align-items:flex-end}
  .alloc-field label{display:block;font-size:10px;font-weight:500;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:5px}
  .alloc-field select,.alloc-field input[type=date],.alloc-field input[type=number]{width:100%;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:7px 10px;color:var(--text);font-family:'DM Sans',sans-serif;font-size:13px;outline:none;transition:border-color .2s;appearance:none}
  .alloc-field select:focus,.alloc-field input:focus{border-color:var(--accent)}
  .alloc-field select option{background:var(--surface2)}
  .alloc-btn{padding:7px 16px;background:var(--accent);color:#0f0e0c;border:none;border-radius:var(--radius);font-family:'DM Sans',sans-serif;font-size:12px;font-weight:600;cursor:pointer;white-space:nowrap;transition:background .2s}
  .alloc-btn:hover{background:var(--accent2)}
  /* Room edit form */
  .room-edit-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:1.75rem;max-width:560px;margin-bottom:2rem}
  .room-edit-card h3{font-size:13px;font-weight:600;color:var(--accent);text-transform:uppercase;letter-spacing:.08em;margin-bottom:1.25rem}
  .edit-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:.75rem;margin-bottom:1rem}
  .edit-field label{display:block;font-size:11px;font-weight:500;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px}
  .edit-field select,.edit-field input[type=number]{width:100%;background:var(--surface2);border:1px solid var(--border);border-radius:var(--radius);padding:9px 12px;color:var(--text);font-family:'DM Sans',sans-serif;font-size:13.5px;outline:none;transition:border-color .2s;appearance:none}
  .edit-field select:focus,.edit-field input:focus{border-color:var(--accent)}
  .edit-field select option{background:var(--surface2)}
  .save-btn{padding:9px 22px;background:var(--accent);color:#0f0e0c;border:none;border-radius:var(--radius);font-family:'DM Sans',sans-serif;font-size:13px;font-weight:600;cursor:pointer;transition:background .2s}
  .save-btn:hover{background:var(--accent2)}
  /* Accounting tab */
  .acc-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:2rem}
  .badge-paid{background:var(--approved-bg);color:var(--approved)}
  .badge-unpaid{background:var(--rejected-bg);color:var(--rejected)}
  .badge-settled{background:var(--approved-bg);color:var(--approved)}
  .facc-unpaid{background:var(--rejected-bg);border-color:var(--rejected);color:var(--rejected)}
  .facc-paid{background:var(--approved-bg);border-color:var(--approved);color:var(--approved)}
  .facc-all{background:var(--surface2);border-color:var(--border2);color:var(--text)}
</style>
</head>
<body>
<aside class="sidebar">
  <div class="sidebar-logo">
    <div class="logo-icon"><svg viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22" style="fill:none;stroke:#0f0e0c;stroke-width:1.5"/></svg></div>
    <div><div class="logo-text">Hostel MS</div><div class="logo-sub">Manager Portal</div></div>
  </div>
  <nav class="sidebar-nav">
    <div class="nav-label">Management</div>
    <a class="nav-item <?=$active_tab==='registrations'?'active':''?>" href="?tab=registrations">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>Registrations
      <?php if($s['cpending']>0):?><span class="nbadge"><?=$s['cpending']?></span><?php endif;?>
    </a>
    <a class="nav-item <?=$active_tab==='rooms'?'active':''?>" href="?tab=rooms">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><path d="M9 22V12h6v10"/></svg>Rooms
      <?php if($s['bpending']>0):?><span class="nbadge"><?=$s['bpending']?></span><?php endif;?>
    </a>
    <a class="nav-item <?=$active_tab==='visitors'?'active':''?>" href="?tab=visitors">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>Visitor Requests
      <?php if($s['vpending']>0):?><span class="nbadge"><?=$s['vpending']?></span><?php endif;?>
    </a>
    <a class="nav-item <?=$active_tab==='accounting'?'active':''?>" href="?tab=accounting">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="3" width="20" height="18" rx="2"/><path d="M8 7h8M8 11h8M8 15h5"/></svg>Accounting
      <?php if($s['acc_unpaid']>0):?><span class="nbadge"><?=$s['acc_unpaid']?></span><?php endif;?>
    </a>
  </nav>
  <div class="sidebar-footer">
    <div class="user-chip">
      <div class="avatar"><?=strtoupper(substr($manager_name,0,1))?></div>
      <div class="user-info"><div class="user-name"><?=htmlspecialchars($manager_name)?></div><div class="user-role">Manager</div></div>
    </div>
    <a href="logout.php" class="logout-btn">Sign out</a>
  </div>
</aside>

<main class="main">
  <div class="page-title">
    <?php if($active_tab==='registrations') echo 'Client Registrations';
    elseif($active_tab==='rooms')       echo 'Room Management';
    elseif($active_tab==='accounting')  echo 'Accounting';
    else echo 'Visitor Requests'; ?>
  </div>
  <div class="page-sub">
    <?php if($active_tab==='registrations') echo 'Approve or reject client account applications';
    elseif($active_tab==='rooms')       echo 'View room availability, allocate rooms to clients, and manage room settings';
    elseif($active_tab==='accounting')  echo 'Overview of all client charges, payments, and outstanding balances';
    else echo 'Approve or reject visitor requests from clients'; ?>
  </div>
  <?php if($message):?><div class="toast <?=$msg_type?>"><?=htmlspecialchars($message)?></div><?php endif;?>
  <div class="tabs">
    <a class="tab <?=$active_tab==='registrations'?'active':''?>" href="?tab=registrations">Registrations</a>
    <a class="tab <?=$active_tab==='rooms'?'active':''?>"         href="?tab=rooms">Rooms</a>
    <a class="tab <?=$active_tab==='visitors'?'active':''?>"      href="?tab=visitors">Visitor Requests</a>
    <a class="tab <?=$active_tab==='accounting'?'active':''?>"    href="?tab=accounting">Accounting</a>
  </div>

  <!-- ══ REGISTRATIONS ══ -->
  <?php if($active_tab==='registrations'):?>
    <div class="stats-grid">
      <div class="stat-card"><div class="stat-label">Total</div><div class="stat-val"><?=$s['ctotal']?></div></div>
      <div class="stat-card"><div class="stat-label">Pending</div><div class="stat-val p"><?=$s['cpending']?></div></div>
      <div class="stat-card"><div class="stat-label">Approved</div><div class="stat-val a"><?=$s['capproved']?></div></div>
      <div class="stat-card"><div class="stat-label">Rejected</div><div class="stat-val r"><?=$s['crejected']?></div></div>
    </div>
    <div class="filter-bar">
      <?php foreach(['Pending'=>'fp','Approved'=>'fa','Rejected'=>'fr','All'=>'fall'] as $lbl=>$cls):?>
        <a class="ftab <?=$cf===$lbl?$cls:''?>" href="?tab=registrations&filter=<?=$lbl?>"><?=$lbl?><?=$lbl==='Pending'&&$s['cpending']>0?' ('.$s['cpending'].')':''?></a>
      <?php endforeach;?>
    </div>
    <div class="table-wrap">
      <?php if(empty($clients)):?><div class="empty-state">No <?=strtolower($cf)?> registrations found.</div>
      <?php else:?>
        <table>
          <thead><tr><th>#</th><th>Client</th><th>Guardian</th><th>Registered</th><th>Status</th><th>Actions</th></tr></thead>
          <tbody>
            <?php foreach($clients as $c):
              $full=trim($c['F_name'].' '.$c['M_name'].' '.$c['L_name']); $st=$c['Status'];?>
            <tr>
              <td class="cell-muted"><?=$c['Client_ID']?></td>
              <td><div class="fw500"><?=htmlspecialchars($full)?></div><div class="cell-muted"><?=htmlspecialchars($c['Email'])?></div><?php if($c['Phone_num']):?><div class="cell-muted"><?=htmlspecialchars($c['Phone_num'])?></div><?php endif;?></td>
              <td><div><?=htmlspecialchars($c['Guardian_name'])?></div><?php if($c['Guardian_Phone']):?><div class="cell-muted"><?=htmlspecialchars($c['Guardian_Phone'])?></div><?php endif;?></td>
              <td class="cell-muted"><?=$c['Reg_Date']?date('d M Y',strtotime($c['Reg_Date'])):'—'?><?php if($c['Approval_Date']):?><div><?=date('d M Y',strtotime($c['Approval_Date']))?></div><?php endif;?></td>
              <td><span class="badge badge-<?=strtolower($st)?>"><?=$st?></span></td>
              <td>
                <?php if($st==='Pending'):?>
                  <div class="action-group">
                    <form method="POST" action="?tab=registrations&filter=<?=urlencode($cf)?>" style="display:inline">
                      <input type="hidden" name="action" value="approve_client"><input type="hidden" name="client_id" value="<?=$c['Client_ID']?>">
                      <button class="btn btn-approve" onclick="return confirm('Approve <?=htmlspecialchars(addslashes($full))?>?')">Approve</button>
                    </form>
                    <form method="POST" action="?tab=registrations&filter=<?=urlencode($cf)?>" style="display:inline">
                      <input type="hidden" name="action" value="reject_client"><input type="hidden" name="client_id" value="<?=$c['Client_ID']?>">
                      <button class="btn btn-reject" onclick="return confirm('Reject <?=htmlspecialchars(addslashes($full))?>?')">Reject</button>
                    </form>
                  </div>
                <?php else:?><span class="btn btn-disabled">No action</span><?php endif;?>
              </td>
            </tr>
            <?php endforeach;?>
          </tbody>
        </table>
      <?php endif;?>
    </div>

  <!-- ══ ROOMS ══ -->
  <?php elseif($active_tab==='rooms'):?>

    <!-- Room summary stats -->
    <div class="room-summary">
      <div class="rsm-card"><div class="rsm-label">AC Available</div><div class="rsm-val g"><?=$s['rac_avail']?></div><div style="font-size:11px;color:var(--muted);margin-top:4px;">of <?=$s['rac_total']?> total</div></div>
      <div class="rsm-card"><div class="rsm-label">Non-AC Available</div><div class="rsm-val g"><?=$s['rna_avail']?></div><div style="font-size:11px;color:var(--muted);margin-top:4px;">of <?=$s['rna_total']?> total</div></div>
      <div class="rsm-card"><div class="rsm-label">Pending Bookings</div><div class="rsm-val p"><?=$s['bpending']?></div></div>
      <div class="rsm-card"><div class="rsm-label">Allocated</div><div class="rsm-val a"><?=$s['ballocated']?></div></div>
    </div>

    <!-- Sub tabs -->
    <div class="tabs" style="margin-bottom:1.5rem;">
      <?php $rt=$_GET['rtab']??'overview';?>
      <a class="tab <?=$rt==='overview'?'active':''?>" href="?tab=rooms&rtab=overview">Room Overview</a>
      <a class="tab <?=$rt==='bookings'?'active':''?>" href="?tab=rooms&rtab=bookings">Booking Requests <?php if($s['bpending']>0):?><span style="background:var(--pending);color:#0f0e0c;font-size:10px;padding:1px 6px;border-radius:20px;margin-left:4px;"><?=$s['bpending']?></span><?php endif;?></a>
      <a class="tab <?=$rt==='manage'?'active':''?>"   href="?tab=rooms&rtab=manage">Edit Room</a>
    </div>

    <?php if($rt==='overview'):?>
      <!-- Legend -->
      <div class="room-legend">
        <div class="legend-item"><div class="legend-dot" style="background:var(--approved)"></div>Available</div>
        <div class="legend-item"><div class="legend-dot" style="background:var(--pending)"></div>Partially occupied</div>
        <div class="legend-item"><div class="legend-dot" style="background:var(--rejected)"></div>Full / Unavailable</div>
      </div>
      <?php for($fl=1;$fl<=6;$fl++):?>
        <div class="floor-section">
          <div class="floor-header">
            <div class="floor-title">Floor <?=$fl?></div>
            <div class="floor-sub">
              AC: <?=array_reduce(array_filter($room_grid[$fl]??[],fn($r)=>$r['Room_type']==='AC'&&$r['Status']==='Available'),fn($c)=>$c+1,0)?> available &nbsp;·&nbsp;
              Non-AC: <?=array_reduce(array_filter($room_grid[$fl]??[],fn($r)=>$r['Room_type']==='Non-AC'&&$r['Status']==='Available'),fn($c)=>$c+1,0)?> available
            </div>
          </div>
          <div class="room-cells">
            <?php for($rm=1;$rm<=20;$rm++):
              $cell=$room_grid[$fl][$rm]??null;
              if(!$cell){echo "<div class='room-cell unavailable'><div class='rc-num'>$rm</div><div class='rc-type'>—</div></div>";continue;}
              $occ=$cell['occupants']; $cap=$cell['Capacity'];
              $is_full=($occ>=$cap)||$cell['Status']==='Unavailable';
              $partial=($occ>0&&$occ<$cap);
              $cls=$is_full?'full':($cell['Status']==='Unavailable'?'unavailable':'available');
              $dot_cls=$is_full?'r':($partial?'y':'g');
            ?>
              <div class="room-cell <?=$cls?>" title="Floor <?=$fl?> Room <?=$rm?> — <?=$cell['Room_type']?> — <?=$occ?>/<?=$cap?> occupied">
                <div class="rc-num"><?=$rm?></div>
                <div class="rc-occ"><?=$occ?>/<?=$cap?></div>
                <div class="rc-type"><?=$cell['Room_type']==='AC'?'AC':'N-AC'?></div>
                <div class="rc-dot <?=$dot_cls?>"></div>
              </div>
            <?php endfor;?>
          </div>
        </div>
      <?php endfor;?>

    <?php elseif($rt==='bookings'):?>
      <div class="filter-bar">
        <?php foreach(['Pending'=>'fp','Allocated'=>'fa','All'=>'fall'] as $lbl=>$cls):?>
          <a class="ftab <?=$bf===$lbl?$cls:''?>" href="?tab=rooms&rtab=bookings&bfilter=<?=$lbl?>"><?=$lbl?><?=$lbl==='Pending'&&$s['bpending']>0?' ('.$s['bpending'].')':''?></a>
        <?php endforeach;?>
      </div>
      <div class="table-wrap">
        <?php if(empty($bookings)):?><div class="empty-state">No <?=strtolower($bf)?> booking requests.</div>
        <?php else:?>
          <table>
            <thead><tr><th>#</th><th>Client</th><th>Type requested</th><th>Booked on</th><th>Status</th><th>Allocation</th></tr></thead>
            <tbody>
              <?php foreach($bookings as $b):
                $bfull=htmlspecialchars($b['F_name'].' '.$b['L_name']); $bst=$b['Booking_status'];?>
              <tr>
                <td class="cell-muted"><?=$b['Booking_ID']?></td>
                <td><div class="fw500"><?=$bfull?></div><div class="cell-muted"><?=htmlspecialchars($b['Email'])?></div><div class="cell-muted">Client #<?=$b['Client_ID']?></div></td>
                <td><?=htmlspecialchars($b['Room_type_requested'])?></td>
                <td class="cell-muted"><?=date('d M Y',strtotime($b['Booking_date']))?></td>
                <td><span class="badge badge-<?=strtolower($bst)?>"><?=$bst?></span></td>
                <td>
                  <?php if($bst==='Pending'):?>
                    <div class="alloc-form-wrap">
                      <h4>Allocate room</h4>
                      <form method="POST" action="?tab=rooms&rtab=bookings&bfilter=<?=urlencode($bf)?>">
                        <input type="hidden" name="action" value="allocate_room">
                        <input type="hidden" name="booking_id" value="<?=$b['Booking_ID']?>">
                        <input type="hidden" name="alloc_client_id" value="<?=$b['Client_ID']?>">
                        <div class="alloc-grid">
                          <div class="alloc-field">
                            <label>Floor</label>
                            <select name="floor_num" required>
                              <option value="">Floor</option>
                              <?php for($f=1;$f<=6;$f++):?><option value="<?=$f?>"><?=$f?></option><?php endfor;?>
                            </select>
                          </div>
                          <div class="alloc-field">
                            <label>Room no.</label>
                            <select name="room_num" required id="rnum_<?=$b['Booking_ID']?>">
                              <option value="">Room</option>
                              <?php
                              $rng=$b['Room_type_requested']==='AC'?[1,10]:[11,20];
                              for($r=$rng[0];$r<=$rng[1];$r++):?><option value="<?=$r?>"><?=$r?></option><?php endfor;?>
                            </select>
                          </div>
                          <div class="alloc-field">
                            <label>Bed no.</label>
                            <select name="bed_num" required>
                              <option value="">Bed</option>
                              <?php $max_bed=$b['Room_type_requested']==='AC'?2:3; for($bd=1;$bd<=$max_bed;$bd++):?><option value="<?=$bd?>"><?=$bd?></option><?php endfor;?>
                            </select>
                          </div>
                          <div class="alloc-field">
                            <label>Check-in date</label>
                            <input type="date" name="check_in_date" value="<?=date('Y-m-d')?>">
                          </div>
                          <div class="alloc-field" style="align-self:flex-end;">
                            <button type="submit" class="alloc-btn" onclick="return confirm('Allocate this room?')">Allocate</button>
                          </div>
                        </div>
                      </form>
                    </div>
                  <?php else:?>
                    <div class="cell-muted">Floor <?=$b['Floor_num']?> · Room <?=$b['Room_num']?> · Bed <?=$b['Bed_num']?></div>
                    <div class="cell-muted">Check-in: <?=$b['Check_in_date']?date('d M Y',strtotime($b['Check_in_date'])):'—'?></div>
                  <?php endif;?>
                </td>
              </tr>
              <?php endforeach;?>
            </tbody>
          </table>
        <?php endif;?>
      </div>

    <?php elseif($rt==='manage'):?>
      <div class="room-edit-card">
        <h3>Edit / update a room</h3>
        <form method="POST" action="?tab=rooms&rtab=manage">
          <input type="hidden" name="action" value="save_room">
          <div class="edit-grid">
            <div class="edit-field">
              <label>Floor (1–6)</label>
              <select name="floor_num" required>
                <?php for($f=1;$f<=6;$f++):?><option value="<?=$f?>"><?=$f?></option><?php endfor;?>
              </select>
            </div>
            <div class="edit-field">
              <label>Room no. (1–20)</label>
              <input type="number" name="room_num" min="1" max="20" required placeholder="1–20">
            </div>
            <div class="edit-field">
              <label>Room type</label>
              <select name="room_type" required>
                <option value="AC">AC</option>
                <option value="Non-AC">Non-AC</option>
              </select>
            </div>
            <div class="edit-field">
              <label>Capacity</label>
              <input type="number" name="capacity" min="1" max="10" required placeholder="2 or 3">
            </div>
            <div class="edit-field">
              <label>Status</label>
              <select name="room_status" required>
                <option value="Available">Available</option>
                <option value="Unavailable">Unavailable</option>
              </select>
            </div>
          </div>
          <button type="submit" class="save-btn">Save room</button>
        </form>
      </div>
      <!-- Room list table -->
      <div class="table-wrap">
        <table>
          <thead><tr><th>Floor</th><th>Room</th><th>Type</th><th>Capacity</th><th>Occupants</th><th>Status</th></tr></thead>
          <tbody>
            <?php foreach($room_grid as $fl=>$rooms): foreach($rooms as $rm=>$cell): $occ=$cell['occupants'];?>
            <tr>
              <td><?=$fl?></td>
              <td class="fw500"><?=$rm?></td>
              <td><?=$cell['Room_type']?></td>
              <td><?=$cell['Capacity']?></td>
              <td><?=$occ?>/<?=$cell['Capacity']?></td>
              <td><span class="badge badge-<?=strtolower($cell['Status'])?>"><?=$cell['Status']?></span></td>
            </tr>
            <?php endforeach; endforeach;?>
          </tbody>
        </table>
      </div>
    <?php endif;?>

  <!-- ══ VISITORS ══ -->
  <?php elseif($active_tab==='visitors'):?>
    <div class="stats-grid">
      <div class="stat-card"><div class="stat-label">Total</div><div class="stat-val"><?=$s['vpending']+$s['vapproved']+$s['vrejected']?></div></div>
      <div class="stat-card"><div class="stat-label">Pending</div><div class="stat-val p"><?=$s['vpending']?></div></div>
      <div class="stat-card"><div class="stat-label">Approved</div><div class="stat-val a"><?=$s['vapproved']?></div></div>
      <div class="stat-card"><div class="stat-label">Rejected</div><div class="stat-val r"><?=$s['vrejected']?></div></div>
    </div>
    <div class="filter-bar">
      <?php foreach(['Pending'=>'fp','Approved'=>'fa','Rejected'=>'fr','All'=>'fall'] as $lbl=>$cls):?>
        <a class="ftab <?=$vf===$lbl?$cls:''?>" href="?tab=visitors&vfilter=<?=$lbl?>"><?=$lbl?><?=$lbl==='Pending'&&$s['vpending']>0?' ('.$s['vpending'].')':''?></a>
      <?php endforeach;?>
    </div>
    <div class="table-wrap">
      <?php if(empty($visitors)):?><div class="empty-state">No <?=strtolower($vf)?> visitor requests.</div>
      <?php else:?>
        <table>
          <thead><tr><th>#</th><th>Visitor</th><th>Client</th><th>Requested</th><th>Entry / Exit</th><th>Status</th><th>Actions</th></tr></thead>
          <tbody>
            <?php foreach($visitors as $v): $vst=$v['Status'];?>
            <tr>
              <td class="cell-muted"><?=$v['Visitor_ID']?></td>
              <td><div class="fw500"><?=htmlspecialchars($v['Visitor_Name'])?></div><?php if($v['Visitor_Phone']):?><div class="cell-muted"><?=htmlspecialchars($v['Visitor_Phone'])?></div><?php endif;?></td>
              <td><div><?=htmlspecialchars($v['F_name'].' '.$v['L_name'])?></div><div class="cell-muted">Client #<?=$v['Client_ID']?></div></td>
              <td class="cell-muted"><?=$v['Requested_time']?substr($v['Requested_time'],0,5):'—'?></td>
              <td class="cell-muted"><?=$vst==='Approved'?(($v['Entry_time']?substr($v['Entry_time'],0,5):'—').' / '.($v['Exit_time']?substr($v['Exit_time'],0,5):'—')):'—'?></td>
              <td><span class="badge badge-<?=strtolower($vst)?>"><?=$vst?></span></td>
              <td>
                <?php if($vst==='Pending'):?>
                  <form method="POST" action="?tab=visitors&vfilter=<?=urlencode($vf)?>">
                    <input type="hidden" name="action" value="approve_visitor">
                    <input type="hidden" name="visitor_id" value="<?=$v['Visitor_ID']?>">
                    <div class="action-group">
                      <div><div class="time-label">Entry</div><input class="time-input" type="time" name="entry_time" value="<?=htmlspecialchars($v['Requested_time']??'')?>"></div>
                      <div><div class="time-label">Exit</div><input class="time-input" type="time" name="exit_time"></div>
                      <div style="padding-top:16px;"><button class="btn btn-approve" type="submit">Approve</button></div>
                    </div>
                  </form>
                  <form method="POST" action="?tab=visitors&vfilter=<?=urlencode($vf)?>" style="margin-top:6px;">
                    <input type="hidden" name="action" value="reject_visitor">
                    <input type="hidden" name="visitor_id" value="<?=$v['Visitor_ID']?>">
                    <button class="btn btn-reject" onclick="return confirm('Reject this visitor?')">Reject</button>
                  </form>
                <?php else:?><span class="btn btn-disabled">No action</span><?php endif;?>
              </td>
            </tr>
            <?php endforeach;?>
          </tbody>
        </table>
      <?php endif;?>
    </div>
  <!-- ══ ACCOUNTING ══ -->
  <?php elseif($active_tab==='accounting'):?>

    <!-- Stats row -->
    <div class="acc-stats">
      <div class="stat-card">
        <div class="stat-label">Approved Clients</div>
        <div class="stat-val"><?=$s['capproved']?></div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Total Charged</div>
        <div class="stat-val" style="font-size:24px;">$<?=number_format($acc_total_charges,2)?></div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Total Collected</div>
        <div class="stat-val a" style="font-size:24px;">$<?=number_format($acc_total_paid,2)?></div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Total Outstanding</div>
        <div class="stat-val r" style="font-size:24px;">$<?=number_format(max(0,$acc_total_outstanding),2)?></div>
      </div>
    </div>

    <!-- Filter bar -->
    <div class="filter-bar">
      <a class="ftab <?=$acc_filter==='All'   ?'facc-all':''?>"    href="?tab=accounting&afilter=All">All Clients</a>
      <a class="ftab <?=$acc_filter==='Unpaid'?'facc-unpaid':''?>" href="?tab=accounting&afilter=Unpaid">
        Unpaid<?=$s['acc_unpaid']>0?' ('.$s['acc_unpaid'].')':''?>
      </a>
      <a class="ftab <?=$acc_filter==='Paid'  ?'facc-paid':''?>"   href="?tab=accounting&afilter=Paid">
        Settled<?=$s['acc_paid']>0?' ('.$s['acc_paid'].')':''?>
      </a>
    </div>

    <!-- Accounts table -->
    <div class="table-wrap">
      <?php if(empty($acc_rows)):?>
        <div class="empty-state">No <?=strtolower($acc_filter)==='all'?'approved':strtolower($acc_filter)?> client accounts found.</div>
      <?php else:?>
        <table>
          <thead>
            <tr>
              <th>#</th>
              <th>Client</th>
              <th>Charges</th>
              <th>Room</th>
              <th>Meals</th>
              <th>Paid</th>
              <th>Outstanding</th>
              <th>Last Payment</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($acc_rows as $a):
              $is_settled = $a['outstanding'] <= 0;
            ?>
            <tr>
              <td class="cell-muted"><?=$a['Client_ID']?></td>
              <td>
                <div class="fw500"><?=htmlspecialchars($a['F_name'].' '.$a['L_name'])?></div>
                <div class="cell-muted"><?=htmlspecialchars($a['Email'])?></div>
              </td>
              <td class="fw500">$<?=number_format($a['total_charges'],2)?></td>
              <td class="cell-muted">
                <?=$a['room_entries']?> entr<?=$a['room_entries']===1?'y':'ies'?>
              </td>
              <td class="cell-muted">
                <?=$a['meal_entries']?> meal<?=$a['meal_entries']===1?'':'s'?>
              </td>
              <td style="color:var(--approved);font-weight:500;">
                $<?=number_format($a['total_paid'],2)?>
              </td>
              <td>
                <?php if($is_settled):?>
                  <span style="color:var(--approved);font-weight:500;">$0.00</span>
                <?php else:?>
                  <span style="color:var(--rejected);font-weight:600;">$<?=number_format($a['outstanding'],2)?></span>
                <?php endif;?>
              </td>
              <td class="cell-muted">
                <?=$a['last_payment_date']?date('d M Y',strtotime($a['last_payment_date'])):'—'?>
              </td>
              <td>
                <span class="badge <?=$is_settled?'badge-paid':'badge-unpaid'?>">
                  <?=$is_settled?'Settled':'Unpaid'?>
                </span>
              </td>
            </tr>
            <?php endforeach;?>
          </tbody>
        </table>
      <?php endif;?>
    </div>

  <?php endif;?>
</main>
</body>
</html>