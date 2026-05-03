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
$message     = htmlspecialchars($_GET['msg'] ?? "");
$msg_type    = in_array($_GET['mt'] ?? '', ['success','error']) ? $_GET['mt'] : "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'request_visitor') {
        $visitor_name  = trim($_POST['visitor_name'] ?? "");
        $visitor_phone = trim($_POST['visitor_phone'] ?? "");
        $requested     = trim($_POST['requested_time'] ?? "");

        if ($visitor_name === "") {
            $message  = "Visitor name is required.";
            $msg_type = "error";
        } else {
            $dup_chk = mysqli_prepare($conn, "SELECT Visitor_ID FROM Visitors_log WHERE Client_id = ? AND Visitor_Name = ? AND Status = 'Pending' LIMIT 1");
            mysqli_stmt_bind_param($dup_chk, "is", $client_id, $visitor_name);
            mysqli_stmt_execute($dup_chk);
            if (mysqli_fetch_assoc(mysqli_stmt_get_result($dup_chk))) {
                $message  = "A pending visitor request for \"" . htmlspecialchars($visitor_name) . "\" already exists.";
                $msg_type = "error";
            } else {
                $sql  = "INSERT INTO Visitors_log (Client_id, Visitor_Name, Visitor_Phone, Status, Requested_time)
                         VALUES (?, ?, ?, 'Pending', ?)";
                $stmt = mysqli_prepare($conn, $sql);
                $req_time = $requested ?: null;
                mysqli_stmt_bind_param($stmt, "isss", $client_id, $visitor_name, $visitor_phone, $req_time);
                if (mysqli_stmt_execute($stmt)) {
                    header("Location: ?tab=visitors&msg=" . urlencode("Visitor request submitted. Awaiting manager approval.") . "&mt=success");
                    exit();
                } else {
                    $message  = "Failed to submit: " . mysqli_error($conn);
                    $msg_type = "error";
                }
            }
        }
    }

    elseif ($_POST['action'] === 'book_room') {
        $room_type_req  = trim($_POST['room_type_requested'] ?? "");
        $preferred_date = trim($_POST['preferred_checkin'] ?? "") ?: date('Y-m-d');
        $duration       = intval($_POST['duration_months'] ?? 0);

        $ROOM_PACKAGES  = ['AC' => ['name' => 'Spacious', 'rate' => 400], 'Non-AC' => ['name' => 'Regular', 'rate' => 250]];

        if (!in_array($room_type_req, ['AC', 'Non-AC'])) {
            $message  = "Please select a valid room type.";
            $msg_type = "error";
        } elseif ($duration < 1 || $duration > 24) {
            $message  = "Duration must be between 1 and 24 months.";
            $msg_type = "error";
        } else {
            $chk = mysqli_prepare($conn, "SELECT Booking_ID, Booking_status FROM Booking_Allocation WHERE client_id = ? AND Booking_status IN ('Pending','Allocated') LIMIT 1");
            mysqli_stmt_bind_param($chk, "i", $client_id);
            mysqli_stmt_execute($chk);
            $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($chk));

            if ($existing) {
                $message  = "You already have a " . strtolower($existing['Booking_status']) . " booking request (Booking #" . $existing['Booking_ID'] . "). Please wait for it to be processed.";
                $msg_type = "error";
            } else {
                mysqli_begin_transaction($conn);
                try {
                    $ins = mysqli_prepare($conn, "INSERT INTO Booking_Allocation (Room_type_requested, Booking_date, Check_in_date, Booking_status, client_id) VALUES (?, CURDATE(), ?, 'Pending', ?)");
                    mysqli_stmt_bind_param($ins, "ssi", $room_type_req, $preferred_date, $client_id);
                    if (!mysqli_stmt_execute($ins)) throw new Exception(mysqli_error($conn));

                    $pkg      = $ROOM_PACKAGES[$room_type_req];
                    $price    = $pkg['rate'] * $duration;
                    $pkg_name = $pkg['name'];
                    $acc = mysqli_prepare($conn, "INSERT INTO Accountings (Package_Name, Room_Type, Duration, Price, Client_ID, Entry_Type, Entry_Date, Manager_ID) VALUES (?, ?, ?, ?, ?, 'Room', CURDATE(), NULL)");
                    mysqli_stmt_bind_param($acc, "ssdii", $pkg_name, $room_type_req, $duration, $price, $client_id);
                    if (!mysqli_stmt_execute($acc)) throw new Exception(mysqli_error($conn));

                    mysqli_commit($conn);
                    header("Location: ?tab=room&msg=" . urlencode("Room booking submitted! {$pkg_name} package — {$duration} month(s) at \${$price} added to your account.") . "&mt=success");
                    exit();
                } catch (Exception $e) {
                    mysqli_rollback($conn);
                    $message  = "Failed to submit booking: " . $e->getMessage();
                    $msg_type = "error";
                }
            }
        }
    }
}


$MEAL_MENU = [
    'Breakfast' => [
        'Light'  => ['items' => ['Bread', 'Jam', 'Butter'],                         'price' => 25.00],
        'Medium' => ['items' => ['Rice', 'Curry', 'Egg'],                            'price' => 60.00],
        'Full'   => ['items' => ['Rice', 'Meat', 'Vegetables', 'Curry', 'Egg'],      'price' => 140.00],
    ],
    'Lunch' => [
        'Light'  => ['items' => ['Bread', 'Jam', 'Butter'],                         'price' => 25.00],
        'Medium' => ['items' => ['Rice', 'Curry'],                                   'price' => 60.00],
        'Full'   => ['items' => ['Rice', 'Meat', 'Vegetables', 'Curry', 'Salad'],    'price' => 140.00],
    ],
    'Snacks' => [
        'Light'  => ['items' => ['Bread', 'Butter'],                                'price' => 25.00],
        'Medium' => ['items' => ['Bread', 'Jam', 'Butter', 'Tea / Coffee'],          'price' => 60.00],
        'Full'   => ['items' => ['Bread', 'Jam', 'Butter', 'Tea / Coffee', 'Egg'],   'price' => 140.00],
    ],
    'Dinner' => [
        'Light'  => ['items' => ['Bread', 'Jam', 'Butter'],                         'price' => 25.00],
        'Medium' => ['items' => ['Rice', 'Curry'],                                   'price' => 60.00],
        'Full'   => ['items' => ['Rice', 'Meat', 'Vegetables', 'Curry'],             'price' => 140.00],
    ],
];
$VALID_CATS = ['Breakfast', 'Lunch', 'Snacks', 'Dinner'];
$VALID_SUBS = ['Light', 'Medium', 'Full'];


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'book_meal') {
    $meal_cat  = trim($_POST['meal_category']    ?? '');
    $meal_sub  = trim($_POST['meal_subcategory'] ?? '');
    $meal_date = trim($_POST['meal_date']        ?? '');

    if (!in_array($meal_cat, $VALID_CATS)) {
        $message = 'Please select a valid meal category.'; $msg_type = 'error';
    } elseif (!in_array($meal_sub, $VALID_SUBS)) {
        $message = 'Please select a meal size.'; $msg_type = 'error';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $meal_date) || $meal_date < date('Y-m-d')) {
        $message = 'Please pick today or a future date.'; $msg_type = 'error';
    } else {
        $dup = mysqli_prepare($conn, "SELECT Meal_Booking_ID FROM Meal_Booking WHERE Client_ID = ? AND Type LIKE ? AND Date = ? LIMIT 1");
        $like = $meal_cat . ' -%';
        mysqli_stmt_bind_param($dup, 'iss', $client_id, $like, $meal_date);
        mysqli_stmt_execute($dup);
        if (mysqli_fetch_assoc(mysqli_stmt_get_result($dup))) {
            $message = "You already have a $meal_cat booked for that date."; $msg_type = 'error';
        } else {
            $price    = $MEAL_MENU[$meal_cat][$meal_sub]['price'];
            $type_str = "$meal_cat - $meal_sub";
            mysqli_begin_transaction($conn);
            try {
                $ins = mysqli_prepare($conn, "INSERT INTO Meal_Booking (Type, Date, Total_cost, Client_ID) VALUES (?, ?, ?, ?)");
                mysqli_stmt_bind_param($ins, 'ssdi', $type_str, $meal_date, $price, $client_id);
                if (!mysqli_stmt_execute($ins)) throw new Exception(mysqli_error($conn));

                $acc = mysqli_prepare($conn, "INSERT INTO Accountings (Package_Name, Room_Type, Duration, Price, Client_ID, Entry_Type, Entry_Date, Manager_ID) VALUES (?, NULL, 1, ?, ?, 'Meal', ?, NULL)");
                mysqli_stmt_bind_param($acc, 'sdis', $type_str, $price, $client_id, $meal_date);
                if (!mysqli_stmt_execute($acc)) throw new Exception(mysqli_error($conn));

                mysqli_commit($conn);
                header("Location: ?tab=my_meal&meal_cat=" . urlencode($meal_cat) . "&msg=" . urlencode("Booked: $type_str on " . date('d M Y', strtotime($meal_date)) . " — \$$price") . "&mt=success");
                exit();
            } catch (Exception $e) {
                mysqli_rollback($conn);
                $message = 'DB error: ' . $e->getMessage(); $msg_type = 'error';
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_meal') {
    $meal_id = intval($_POST['meal_id'] ?? 0);
    $chk = mysqli_prepare($conn, "SELECT Date FROM Meal_Booking WHERE Meal_Booking_ID = ? AND Client_ID = ? LIMIT 1");
    mysqli_stmt_bind_param($chk, 'ii', $meal_id, $client_id);
    mysqli_stmt_execute($chk);
    $mrow = mysqli_fetch_assoc(mysqli_stmt_get_result($chk));
    if (!$mrow) {
        $message = 'Booking not found.'; $msg_type = 'error';
    } elseif ($mrow['Date'] < date('Y-m-d')) {
        $message = 'Past meal bookings cannot be cancelled.'; $msg_type = 'error';
    } else {
        mysqli_begin_transaction($conn);
        $mtype_res = mysqli_prepare($conn, "SELECT Type, Date FROM Meal_Booking WHERE Meal_Booking_ID = ? AND Client_ID = ? LIMIT 1");
        mysqli_stmt_bind_param($mtype_res, 'ii', $meal_id, $client_id);
        mysqli_stmt_execute($mtype_res);
        $mtype_row = mysqli_fetch_assoc(mysqli_stmt_get_result($mtype_res));

        $del = mysqli_prepare($conn, "DELETE FROM Meal_Booking WHERE Meal_Booking_ID = ? AND Client_ID = ?");
        mysqli_stmt_bind_param($del, 'ii', $meal_id, $client_id);
        mysqli_stmt_execute($del);

        if ($mtype_row) {
            $dacc = mysqli_prepare($conn, "DELETE FROM Accountings WHERE Client_ID = ? AND Package_Name = ? AND Entry_Date = ? AND Entry_Type = 'Meal' LIMIT 1");
            mysqli_stmt_bind_param($dacc, 'iss', $client_id, $mtype_row['Type'], $mtype_row['Date']);
            mysqli_stmt_execute($dacc);
        }
        mysqli_commit($conn);
        header("Location: ?tab=my_meal&msg=" . urlencode("Meal booking cancelled.") . "&mt=success");
        exit();
    }
}

$mstmt = mysqli_prepare($conn, "SELECT Meal_Booking_ID, Type, Date, Total_cost FROM Meal_Booking WHERE Client_ID = ? ORDER BY Date DESC, Meal_Booking_ID DESC");
mysqli_stmt_bind_param($mstmt, 'i', $client_id);
mysqli_stmt_execute($mstmt);
$meal_rows = mysqli_fetch_all(mysqli_stmt_get_result($mstmt), MYSQLI_ASSOC);

$meal_by_date = [];
foreach ($meal_rows as $mr) { $meal_by_date[$mr['Date']][] = $mr; }

$upcoming_meals = 0;
foreach ($meal_rows as $mr) { if ($mr['Date'] >= date('Y-m-d')) $upcoming_meals++; }

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

$bsql  = "SELECT ba.Booking_ID, ba.Room_type_requested, ba.Booking_date,
                 ba.Check_in_date, ba.Booking_status,
                 ba.Floor_num, ba.Room_num, ba.Bed_num
          FROM Booking_Allocation ba
          WHERE ba.client_id = ?
          ORDER BY ba.Booking_ID DESC";
$bstmt = mysqli_prepare($conn, $bsql);
mysqli_stmt_bind_param($bstmt, "i", $client_id);
mysqli_stmt_execute($bstmt);
$bres = mysqli_stmt_get_result($bstmt);
$my_bookings = [];
while ($row = mysqli_fetch_assoc($bres)) $my_bookings[] = $row;

$active_booking = null;
foreach ($my_bookings as $b) {
    if (in_array($b['Booking_status'], ['Pending', 'Allocated'])) {
        $active_booking = $b;
        break;
    }
}

$room_grid = [];
$rg = mysqli_query($conn, "SELECT r.Floor_num, r.Room_num, r.Room_type, r.Capacity, r.Status,
    (SELECT COUNT(*) FROM Stays_IN si WHERE si.Floor_NUM = r.Floor_num AND si.Room_NUm = r.Room_num) AS occupants
    FROM Room r ORDER BY r.Floor_num, r.Room_num");
while ($row = mysqli_fetch_assoc($rg)) $room_grid[$row['Floor_num']][$row['Room_num']] = $row;

$ac_avail  = 0; $ac_total  = 0;
$nac_avail = 0; $nac_total = 0;
foreach ($room_grid as $floor) {
    foreach ($floor as $room) {
        if ($room['Room_type'] === 'AC')     { $ac_total++;  if ($room['Status'] === 'Available') $ac_avail++;  }
        if ($room['Room_type'] === 'Non-AC') { $nac_total++; if ($room['Status'] === 'Available') $nac_avail++; }
    }
}

$stays_sql = "SELECT
        si.Floor_NUM        AS floor,
        si.Room_NUm         AS room,
        r.Room_type,
        r.Capacity,
        r.Status            AS room_status,
        (SELECT COUNT(*) FROM Stays_IN s2
         WHERE s2.Floor_NUM = si.Floor_NUM AND s2.Room_NUm = si.Room_NUm)
                            AS total_occupants,
        ba.Booking_ID,
        ba.Bed_num,
        ba.Check_in_date,
        ba.Booking_status
    FROM Stays_IN si
    INNER JOIN Room r ON r.Floor_num = si.Floor_NUM AND r.Room_num = si.Room_NUm
    LEFT  JOIN Booking_Allocation ba
           ON  ba.client_id = si.client_id
           AND ba.Floor_num  = si.Floor_NUM
           AND ba.Room_num   = si.Room_NUm
           AND ba.Booking_status = 'Allocated'
    WHERE si.client_id = ?
    LIMIT 1";
$stays_stmt = mysqli_prepare($conn, $stays_sql);
mysqli_stmt_bind_param($stays_stmt, "i", $client_id);
mysqli_stmt_execute($stays_stmt);
$my_room = mysqli_fetch_assoc(mysqli_stmt_get_result($stays_stmt));

$roommates = [];
if ($my_room) {
    $rm_sql  = "SELECT u.F_name, u.L_name, ba.Bed_num
                FROM Stays_IN si
                INNER JOIN Client  c  ON c.ID         = si.client_id
                INNER JOIN `User`  u  ON u.ID         = c.Student_ID
                LEFT  JOIN Booking_Allocation ba
                       ON  ba.client_id      = si.client_id
                       AND ba.Floor_num      = si.Floor_NUM
                       AND ba.Room_num       = si.Room_NUm
                       AND ba.Booking_status = 'Allocated'
                WHERE si.Floor_NUM  = ?
                  AND si.Room_NUm   = ?
                  AND si.client_id != ?";
    $rm_stmt = mysqli_prepare($conn, $rm_sql);
    mysqli_stmt_bind_param($rm_stmt, "iii", $my_room['floor'], $my_room['room'], $client_id);
    mysqli_stmt_execute($rm_stmt);
    $rm_res = mysqli_stmt_get_result($rm_stmt);
    while ($row = mysqli_fetch_assoc($rm_res)) $roommates[] = $row;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'make_payment') {
    $pay_method   = trim($_POST['payment_method'] ?? '');
    $valid_methods = ['Cash', 'Card', 'bKash'];
    if (!in_array($pay_method, $valid_methods)) {
        $message = "Please select a valid payment method."; $msg_type = "error";
    } else {
        $tot_stmt = mysqli_prepare($conn, "SELECT COALESCE(SUM(Price),0) AS total FROM Accountings WHERE Client_ID = ?");
        mysqli_stmt_bind_param($tot_stmt, 'i', $client_id);
        mysqli_stmt_execute($tot_stmt);
        $total_charges = (float) mysqli_fetch_assoc(mysqli_stmt_get_result($tot_stmt))['total'];

        $paid_stmt = mysqli_prepare($conn, "SELECT COALESCE(SUM(Payment_Amount),0) AS paid FROM Payment_Record WHERE Client_ID = ?");
        mysqli_stmt_bind_param($paid_stmt, 'i', $client_id);
        mysqli_stmt_execute($paid_stmt);
        $total_paid = (float) mysqli_fetch_assoc(mysqli_stmt_get_result($paid_stmt))['paid'];

        $outstanding = $total_charges - $total_paid;
        if ($outstanding <= 0) {
            $message = "No outstanding balance to pay."; $msg_type = "error";
        } else {
            $pins = mysqli_prepare($conn, "INSERT INTO Payment_Record (Payment_Amount, Payment_Method, Payment_Date, Student_ID, Client_ID) VALUES (?, ?, CURDATE(), ?, ?)");
            mysqli_stmt_bind_param($pins, 'dsii', $outstanding, $pay_method, $user_id, $client_id);
            if (mysqli_stmt_execute($pins)) {
                $tx_id = mysqli_insert_id($conn);
                header("Location: ?tab=payment&msg=" . urlencode("Payment of \$$outstanding received via $pay_method. Receipt #$tx_id.") . "&mt=success");
                exit();
            } else {
                $message = "Payment failed: " . mysqli_error($conn); $msg_type = "error";
            }
        }
    }
}

$acc_stmt = mysqli_prepare($conn, "SELECT Package_ID, Package_Name, Room_Type, Duration, Price, Entry_Type, Entry_Date FROM Accountings WHERE Client_ID = ? ORDER BY Entry_Date ASC, Package_ID ASC");
mysqli_stmt_bind_param($acc_stmt, 'i', $client_id);
mysqli_stmt_execute($acc_stmt);
$acc_rows = mysqli_fetch_all(mysqli_stmt_get_result($acc_stmt), MYSQLI_ASSOC);

$acc_room_rows  = array_filter($acc_rows, fn($r) => $r['Entry_Type'] === 'Room');
$acc_meal_rows  = array_filter($acc_rows, fn($r) => $r['Entry_Type'] === 'Meal');
$total_charges  = array_sum(array_column($acc_rows, 'Price'));

$pay_stmt = mysqli_prepare($conn, "SELECT TX_ID, Payment_Amount, Payment_Method, Payment_Date FROM Payment_Record WHERE Client_ID = ? ORDER BY Payment_Date DESC, TX_ID DESC");
mysqli_stmt_bind_param($pay_stmt, 'i', $client_id);
mysqli_stmt_execute($pay_stmt);
$pay_rows   = mysqli_fetch_all(mysqli_stmt_get_result($pay_stmt), MYSQLI_ASSOC);
$total_paid = array_sum(array_column($pay_rows, 'Payment_Amount'));
$outstanding = $total_charges - $total_paid;
$room_total  = array_sum(array_column(array_values($acc_room_rows), 'Price'));
$meal_total  = array_sum(array_column(array_values($acc_meal_rows), 'Price'));

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
  .nbadge-green { margin-left: auto; background: var(--approved); color: #0f0e0c; font-size: 10px; font-weight: 600; border-radius: 20px; padding: 1px 7px; }
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

  .tabs { display: flex; gap: 4px; border-bottom: 1px solid var(--border); margin-bottom: 2rem; flex-wrap: wrap; }
  .tab { padding: .6rem 1.1rem; font-size: 13px; font-weight: 500; color: var(--muted); text-decoration: none; border-bottom: 2px solid transparent; margin-bottom: -1px; transition: color .15s, border-color .15s; white-space: nowrap; }
  .tab:hover { color: var(--text); }
  .tab.active { color: var(--accent); border-bottom-color: var(--accent); }

  .toast { padding: 10px 16px; border-radius: var(--radius); font-size: 13px; margin-bottom: 1.5rem; }
  .toast.success { background: var(--approved-bg); border: 1px solid rgba(109,171,126,.3); color: var(--approved); }
  .toast.error   { background: var(--rejected-bg);  border: 1px solid rgba(212,101,90,.3);  color: var(--rejected); }

  .status-banner { border-radius: var(--radius); padding: 1rem 1.25rem; display: flex; align-items: center; gap: 10px; margin-bottom: 1.5rem; font-size: 13.5px; }
  .status-banner.approved { background: var(--approved-bg); border: 1px solid rgba(109,171,126,.3); color: var(--approved); }
  .status-banner.pending  { background: var(--pending-bg);  border: 1px solid rgba(201,169,110,.3); color: var(--pending); }
  .status-banner.rejected { background: var(--rejected-bg); border: 1px solid rgba(212,101,90,.3);  color: var(--rejected); }

  /* ── Cards ── */
  .cards-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
  .info-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 1.5rem; }
  .info-card h3 { font-size: 11px; font-weight: 600; letter-spacing: .08em; text-transform: uppercase; color: var(--accent); margin-bottom: 1rem; padding-bottom: .6rem; border-bottom: 1px solid var(--border); }
  .info-row { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: .6rem; font-size: 13.5px; gap: 1rem; }
  .info-row:last-child { margin-bottom: 0; }
  .info-label { color: var(--muted); flex-shrink: 0; }
  .info-value { font-weight: 500; text-align: right; }
  .info-value.pending  { color: var(--pending); }
  .info-value.approved { color: var(--approved); }
  .info-value.allocated { color: var(--approved); }
  .info-value.rejected { color: var(--rejected); }

  /* ── Visitor stats ── */
  .vstats { display: flex; gap: .75rem; margin-bottom: 1.5rem; }
  .vstat  { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: .75rem 1.25rem; flex: 1; }
  .vstat-label { font-size: 11px; color: var(--muted); text-transform: uppercase; letter-spacing: .06em; margin-bottom: 4px; }
  .vstat-val   { font-family: 'DM Serif Display', serif; font-size: 26px; line-height: 1; }
  .vstat-val.p { color: var(--pending); } .vstat-val.a { color: var(--approved); } .vstat-val.r { color: var(--rejected); }

  /* ── Request form (visitors) ── */
  .request-form-wrap { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 1.5rem; margin-bottom: 1.5rem; }
  .request-form-wrap h3 { font-size: 13px; font-weight: 600; color: var(--accent); margin-bottom: 1.25rem; }
  .form-row { display: grid; grid-template-columns: 1fr 1fr 1fr auto; gap: .75rem; align-items: flex-end; }
  .field label { display: block; font-size: 11px; font-weight: 500; color: var(--muted); text-transform: uppercase; letter-spacing: .06em; margin-bottom: 6px; }
  .field input, .field select  { width: 100%; background: var(--surface2); border: 1px solid var(--border); border-radius: var(--radius); padding: 9px 12px; color: var(--text); font-family: 'DM Sans', sans-serif; font-size: 13.5px; outline: none; transition: border-color .2s; appearance: none; }
  .field input:focus, .field select:focus { border-color: var(--accent); }
  .field input::placeholder { color: var(--muted2); }
  .field select option { background: var(--surface2); }
  .submit-btn { padding: 9px 18px; background: var(--accent); color: #0f0e0c; border: none; border-radius: var(--radius); font-family: 'DM Sans', sans-serif; font-size: 13px; font-weight: 600; cursor: pointer; white-space: nowrap; transition: background .2s; }
  .submit-btn:hover { background: var(--accent2); }

  /* ── Tables ── */
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
  .badge-pending   { background: var(--pending-bg);  color: var(--pending);  }
  .badge-approved  { background: var(--approved-bg); color: var(--approved); }
  .badge-rejected  { background: var(--rejected-bg); color: var(--rejected); }
  .badge-allocated { background: var(--approved-bg); color: var(--approved); }
  .badge-ac    { background: rgba(100,160,220,.12); color: #78b4e0; border: 1px solid rgba(100,160,220,.25); }
  .badge-nonac { background: rgba(160,130,200,.12); color: #b49ad4; border: 1px solid rgba(160,130,200,.25); }

  .empty-state { text-align: center; padding: 3rem 2rem; color: var(--muted); font-size: 13px; }

  /* ── Room Booking tab ── */
  .booking-section { display: grid; grid-template-columns: 380px 1fr; gap: 1.5rem; align-items: start; }
  .booking-form-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 1.75rem; }
  .booking-form-card h3 { font-size: 13px; font-weight: 600; color: var(--accent); text-transform: uppercase; letter-spacing: .08em; margin-bottom: 1.5rem; padding-bottom: .75rem; border-bottom: 1px solid var(--border); }
  .booking-field { margin-bottom: 1.1rem; }
  .booking-field label { display: block; font-size: 11px; font-weight: 500; color: var(--muted); text-transform: uppercase; letter-spacing: .06em; margin-bottom: 6px; }
  .booking-field input, .booking-field select { width: 100%; background: var(--surface2); border: 1px solid var(--border); border-radius: var(--radius); padding: 10px 14px; color: var(--text); font-family: 'DM Sans', sans-serif; font-size: 13.5px; outline: none; transition: border-color .2s; appearance: none; }
  .booking-field input:focus, .booking-field select:focus { border-color: var(--accent); }
  .booking-field select option { background: var(--surface2); }
  .booking-submit { width: 100%; padding: 11px; background: var(--accent); color: #0f0e0c; border: none; border-radius: var(--radius); font-family: 'DM Sans', sans-serif; font-size: 14px; font-weight: 600; cursor: pointer; margin-top: .5rem; transition: background .2s; }
  .booking-submit:hover { background: var(--accent2); }
  .booking-submit:disabled { background: var(--surface3); color: var(--muted2); cursor: not-allowed; }

  /* room type toggle */
  .type-toggle { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 1.1rem; }
  .type-option { position: relative; }
  .type-option input[type=radio] { position: absolute; opacity: 0; width: 0; height: 0; }
  .type-label {
    display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px;
    padding: 1rem .5rem; background: var(--surface2); border: 1px solid var(--border);
    border-radius: var(--radius); cursor: pointer; transition: all .2s; text-align: center;
  }
  .type-label .tl-icon { font-size: 20px; }
  .type-label .tl-name { font-size: 13px; font-weight: 600; }
  .type-label .tl-desc { font-size: 11px; color: var(--muted); }
  .type-option input[type=radio]:checked + .type-label {
    background: rgba(201,169,110,.1); border-color: var(--accent); color: var(--accent);
  }
  .type-option input[type=radio]:checked + .type-label .tl-desc { color: var(--pending); }

  /* availability panel */
  .avail-panel { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 1.5rem; }
  .avail-panel h3 { font-size: 13px; font-weight: 600; color: var(--accent); text-transform: uppercase; letter-spacing: .08em; margin-bottom: 1.25rem; padding-bottom: .75rem; border-bottom: 1px solid var(--border); }
  .avail-summary { display: grid; grid-template-columns: 1fr 1fr; gap: .75rem; margin-bottom: 1.5rem; }
  .avail-stat { background: var(--surface2); border: 1px solid var(--border); border-radius: var(--radius); padding: 1rem 1.25rem; }
  .avail-stat-label { font-size: 11px; color: var(--muted); text-transform: uppercase; letter-spacing: .06em; margin-bottom: 4px; }
  .avail-stat-val { font-family: 'DM Serif Display', serif; font-size: 24px; line-height: 1; }
  .avail-stat-val.g { color: var(--approved); }
  .avail-stat-sub { font-size: 11px; color: var(--muted2); margin-top: 3px; }

  /* floor mini-grid */
  .floor-filter { display: flex; gap: 6px; margin-bottom: 1rem; flex-wrap: wrap; }
  .floor-pill { padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 500; border: 1px solid var(--border); color: var(--muted); background: var(--surface2); cursor: pointer; transition: all .15s; }
  .floor-pill.active, .floor-pill:hover { border-color: var(--accent); color: var(--accent); background: rgba(201,169,110,.08); }
  .floor-section { margin-bottom: 1.25rem; }
  .floor-label { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .07em; color: var(--muted); margin-bottom: .6rem; }
  .room-cells { display: grid; grid-template-columns: repeat(10, 1fr); gap: .35rem; }
  .room-cell {
    background: var(--surface2); border: 1px solid var(--border); border-radius: 5px;
    padding: .35rem .15rem; text-align: center; position: relative;
  }
  .room-cell.available  { border-color: rgba(109,171,126,.3); }
  .room-cell.full       { background: rgba(212,101,90,.07); border-color: rgba(212,101,90,.3); }
  .room-cell.unavailable { background: var(--surface3); opacity: .5; }
  .rc-num  { font-size: 11px; font-weight: 600; }
  .rc-occ  { font-size: 9px; color: var(--muted); margin-top: 1px; }
  .rc-type { font-size: 8px; color: var(--muted2); }
  .rc-dot  { width: 5px; height: 5px; border-radius: 50%; margin: 2px auto 0; background: var(--muted2); }
  .rc-dot.g { background: var(--approved); }
  .rc-dot.y { background: var(--pending);  }
  .rc-dot.r { background: var(--rejected); }
  .room-legend { display: flex; gap: .75rem; flex-wrap: wrap; margin-top: 1rem; }
  .legend-item { display: flex; align-items: center; gap: 5px; font-size: 11px; color: var(--muted); }
  .legend-dot { width: 7px; height: 7px; border-radius: 50%; }

  /* active booking alert */
  .booking-alert { background: var(--pending-bg); border: 1px solid rgba(201,169,110,.3); border-radius: var(--radius); padding: 1rem 1.25rem; margin-bottom: 1.5rem; }
  .booking-alert.allocated { background: var(--approved-bg); border-color: rgba(109,171,126,.3); }
  .booking-alert-title { font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .06em; color: var(--pending); margin-bottom: .5rem; }
  .booking-alert.allocated .booking-alert-title { color: var(--approved); }
  .booking-alert-body { font-size: 13.5px; display: flex; flex-wrap: wrap; gap: .75rem 2rem; }
  .booking-alert-item { display: flex; flex-direction: column; gap: 2px; }
  .booking-alert-item span:first-child { font-size: 10px; color: var(--muted); text-transform: uppercase; letter-spacing: .05em; }
  .booking-alert-item span:last-child  { font-weight: 500; }

  /* My Bookings tab */
  .bookings-header { margin-bottom: 1.25rem; }
  .section-title { font-size: 13px; font-weight: 600; color: var(--accent); text-transform: uppercase; letter-spacing: .08em; }
  .room-detail-chip { display: inline-flex; align-items: center; gap: 5px; background: var(--approved-bg); border: 1px solid rgba(109,171,126,.25); border-radius: 6px; padding: 4px 10px; font-size: 12px; color: var(--approved); font-weight: 500; }

  /* ── My Room tab ── */
  .room-hero { display: flex; align-items: center; justify-content: space-between; background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 1.5rem 1.75rem; margin-bottom: 1.25rem; }
  .room-hero-left { display: flex; align-items: center; gap: 1.25rem; }
  .room-hero-icon { font-size: 36px; line-height: 1; }
  .room-hero-label { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .08em; color: var(--muted); margin-bottom: 3px; }
  .room-hero-title { font-family: 'DM Serif Display', serif; font-size: 26px; font-weight: 400; line-height: 1.1; }
  .room-hero-sub { font-size: 13px; color: var(--muted); margin-top: 3px; }
  .room-status-badge { padding: 5px 14px; border-radius: 20px; font-size: 12px; font-weight: 600; }
  .rs-avail { background: var(--approved-bg); border: 1px solid rgba(109,171,126,.3); color: var(--approved); }
  .rs-full  { background: var(--rejected-bg); border: 1px solid rgba(212,101,90,.3);  color: var(--rejected); }

  .room-stats-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 1.25rem; }
  .room-stat-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 1rem 1.25rem; display: flex; align-items: center; gap: .875rem; }
  .room-stat-icon { font-size: 22px; flex-shrink: 0; }
  .room-stat-label { font-size: 11px; color: var(--muted); text-transform: uppercase; letter-spacing: .06em; margin-bottom: 2px; }
  .room-stat-val { font-family: 'DM Serif Display', serif; font-size: 22px; line-height: 1; }

  .room-detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }

  /* Occupancy bar */
  .occ-bar-track { background: var(--surface3); border-radius: 4px; height: 6px; overflow: hidden; }
  .occ-bar-fill { height: 100%; border-radius: 4px; transition: width .4s ease; }
  .occ-bar-fill.low  { background: var(--approved); }
  .occ-bar-fill.mid  { background: var(--pending);  }
  .occ-bar-fill.full { background: var(--rejected);  }

  /* Bed map */
  .bed-map { display: flex; gap: .5rem; flex-wrap: wrap; margin-bottom: .5rem; }
  .bed-cell { display: flex; flex-direction: column; align-items: center; gap: 2px; padding: .6rem .8rem; border-radius: 8px; border: 1px solid var(--border); min-width: 56px; text-align: center; }
  .bed-cell.bed-mine  { background: rgba(201,169,110,.12); border-color: var(--accent); }
  .bed-cell.bed-taken { background: rgba(212,101,90,.08);  border-color: rgba(212,101,90,.3); }
  .bed-cell.bed-free  { background: var(--surface2); }
  .bed-icon { font-size: 18px; }
  .bed-num  { font-size: 11px; font-weight: 600; color: var(--text); }
  .bed-tag  { font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: var(--accent); }
  .bed-legend { display: flex; gap: 1rem; font-size: 11px; color: var(--muted); margin-top: .4rem; }
  .bed-legend-item { display: flex; align-items: center; gap: 5px; }
  .bed-dot { width: 8px; height: 8px; border-radius: 50%; }
  .bed-dot.mine  { background: var(--accent); }
  .bed-dot.taken { background: var(--rejected); }
  .bed-dot.free  { background: var(--muted2); }

  /* Roommates */
  .roommate-row { display: flex; align-items: center; gap: .75rem; padding: .6rem .75rem; background: var(--surface2); border: 1px solid var(--border); border-radius: var(--radius); }
  .rm-avatar { width: 32px; height: 32px; background: var(--surface3); border: 1px solid var(--border2); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 600; color: var(--accent); flex-shrink: 0; }
  .rm-name { font-size: 13.5px; font-weight: 500; }
  .rm-bed  { font-size: 11px; color: var(--muted); }

  /* Building visual */
  .building-visual { display: flex; flex-direction: column; gap: 3px; }
  .bv-floor { display: flex; align-items: center; gap: 6px; padding: 3px 0; }
  .bv-floor.bv-current .bv-floor-label { color: var(--accent); font-weight: 700; }
  .bv-floor-label { font-size: 10px; font-weight: 500; color: var(--muted2); width: 20px; flex-shrink: 0; text-align: right; }
  .bv-rooms { display: flex; gap: 2px; }
  .bv-room { width: 9px; height: 12px; background: var(--surface3); border: 1px solid var(--border); border-radius: 1px; }
  .bv-room.bv-mine { background: var(--accent); border-color: var(--accent2); }

  /* ── My Meal tab ── */
  .meal-layout       { display: grid; grid-template-columns: 1fr 380px; gap: 1.5rem; align-items: start; }
  .meal-right-col    { display: flex; flex-direction: column; gap: 1rem; }

  /* Category strip */
  .cat-strip         { display: grid; grid-template-columns: repeat(4,1fr); gap: .75rem; margin-bottom: 1.5rem; }
  .cat-card          { background: var(--surface); border: 2px solid var(--border); border-radius: var(--radius);
                       padding: 1.1rem .75rem; text-align: center; cursor: pointer; transition: all .2s;
                       user-select: none; text-decoration: none; display: block; }
  .cat-card:hover    { border-color: var(--accent); }
  .cat-card.active   { border-color: var(--accent); background: rgba(201,169,110,.08); }
  .cat-card .cn      { font-size: 13px; font-weight: 600; text-transform: uppercase; letter-spacing: .07em; color: var(--muted); display: block; }
  .cat-card.active .cn { color: var(--accent); }

  /* Sub-category + items */
  .sub-grid          { display: grid; grid-template-columns: repeat(3,1fr); gap: .75rem; margin-bottom: 1.5rem; }
  .sub-card          { background: var(--surface); border: 2px solid var(--border); border-radius: var(--radius);
                       padding: 1.1rem 1rem; cursor: pointer; transition: all .2s; user-select: none;
                       text-decoration: none; display: block; }
  .sub-card:hover    { border-color: var(--border2); }
  .sub-card.active-light  { border-color: #6dab7e; background: rgba(109,171,126,.07); }
  .sub-card.active-medium { border-color: #c9a96e; background: rgba(201,169,110,.07); }
  .sub-card.active-full   { border-color: #d4655a; background: rgba(212,101,90,.07);  }
  .sub-badge         { display: inline-block; padding: 2px 9px; border-radius: 20px; font-size: 10px; font-weight: 700;
                       text-transform: uppercase; letter-spacing: .07em; margin-bottom: .6rem; }
  .sb-light          { background: rgba(109,171,126,.15); color: #6dab7e; }
  .sb-medium         { background: rgba(201,169,110,.15); color: #c9a96e; }
  .sb-full           { background: rgba(212,101,90,.15);  color: #d4655a; }
  .sub-price         { font-family: 'DM Serif Display', serif; font-size: 22px; line-height: 1; margin-bottom: .5rem; }
  .sub-price span    { font-family: 'DM Sans', sans-serif; font-size: 11px; color: var(--muted); font-weight: 400; }
  .sub-items         { list-style: none; display: flex; flex-direction: column; gap: 3px; margin-top: .5rem; }
  .sub-items li      { font-size: 12px; color: var(--muted); display: flex; align-items: center; gap: 5px; }
  .sub-items li::before { content: ''; width: 4px; height: 4px; border-radius: 50%; background: var(--border2); flex-shrink: 0; }

  /* Booking form card */
  .meal-form-card    { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 1.5rem; }
  .meal-form-card h3 { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .08em;
                       color: var(--accent); margin-bottom: 1.25rem; padding-bottom: .7rem; border-bottom: 1px solid var(--border); }
  .meal-summary-row  { display: flex; justify-content: space-between; align-items: center;
                       font-size: 13px; padding: .45rem 0; border-bottom: 1px solid var(--border); }
  .meal-summary-row:last-of-type { border-bottom: none; }
  .meal-summary-row .msr-label { color: var(--muted); }
  .meal-summary-row .msr-val   { font-weight: 600; }
  .meal-total-row    { display: flex; justify-content: space-between; align-items: center;
                       margin-top: 1rem; padding-top: .8rem; border-top: 1px solid var(--border2); }
  .meal-total-label  { font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .07em; color: var(--muted); }
  .meal-total-price  { font-family: 'DM Serif Display', serif; font-size: 26px; color: var(--accent); }
  .meal-date-field   { margin: 1rem 0; }
  .meal-date-field label { display: block; font-size: 11px; font-weight: 500; text-transform: uppercase;
                           letter-spacing: .06em; color: var(--muted); margin-bottom: 6px; }
  .meal-date-field input { width: 100%; background: var(--surface2); border: 1px solid var(--border);
                           border-radius: var(--radius); padding: 9px 12px; color: var(--text);
                           font-family: 'DM Sans', sans-serif; font-size: 13.5px; outline: none; transition: border-color .2s; }
  .meal-date-field input:focus { border-color: var(--accent); }
  .meal-submit       { width: 100%; padding: 11px; background: var(--accent); color: #0f0e0c; border: none;
                       border-radius: var(--radius); font-family: 'DM Sans', sans-serif; font-size: 13.5px;
                       font-weight: 600; cursor: pointer; transition: background .2s; margin-top: .25rem; }
  .meal-submit:hover    { background: var(--accent2); }
  .meal-submit:disabled { background: var(--surface3); color: var(--muted2); cursor: not-allowed; }
  .meal-placeholder  { font-size: 13px; color: var(--muted); line-height: 1.6; padding: .5rem 0; }

  /* History table inside right col */
  .meal-hist-card    { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; }
  .meal-hist-head    { padding: .85rem 1.25rem; font-size: 11px; font-weight: 600; text-transform: uppercase;
                       letter-spacing: .08em; color: var(--accent); border-bottom: 1px solid var(--border); background: var(--surface2); }
  .meal-hist-card table { width: 100%; border-collapse: collapse; }
  .meal-hist-card thead th { padding: .6rem 1rem; text-align: left; font-size: 10px; font-weight: 600;
                              letter-spacing: .07em; text-transform: uppercase; color: var(--muted);
                              background: var(--surface2); border-bottom: 1px solid var(--border); }
  .meal-hist-card tbody tr { border-bottom: 1px solid var(--border); }
  .meal-hist-card tbody tr:last-child { border-bottom: none; }
  .meal-hist-card tbody tr:hover { background: var(--surface2); }
  .meal-hist-card td { padding: .75rem 1rem; font-size: 13px; vertical-align: middle; }
  .cancel-btn        { padding: 3px 10px; background: var(--rejected-bg); border: 1px solid rgba(212,101,90,.3);
                       color: var(--rejected); border-radius: 6px; font-size: 11px; font-weight: 600;
                       cursor: pointer; font-family: 'DM Sans', sans-serif; transition: background .15s; }
  .cancel-btn:hover  { background: rgba(212,101,90,.2); }

  /* Weekly planner */
  .week-grid         { display: grid; grid-template-columns: repeat(7,1fr); gap: .35rem; margin-top: .5rem; }
  .week-cell         { background: var(--surface2); border: 1px solid var(--border); border-radius: 6px;
                       padding: .5rem .3rem; text-align: center; min-height: 80px; }
  .week-cell.today   { border-color: var(--accent); }
  .week-day-label    { font-size: 9px; font-weight: 600; text-transform: uppercase; color: var(--muted2); margin-bottom: 3px; }
  .week-date-num     { font-size: 12px; font-weight: 600; margin-bottom: 4px; }
  .week-date-num.today-num { color: var(--accent); }
  .week-meal-dot     { font-size: 9px; color: var(--muted); line-height: 1.4; word-break: break-word; }
  .week-meal-pip     { display: inline-block; width: 6px; height: 6px; border-radius: 50%; margin: 1px; }
  .wmp-light         { background: #6dab7e; }
  .wmp-medium        { background: #c9a96e; }
  .wmp-full          { background: #d4655a; }

  /* ── Accounting tab ── */
  .acc-layout        { display: grid; grid-template-columns: 1fr 320px; gap: 1.5rem; align-items: start; }
  .acc-card          { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; margin-bottom: 1rem; }
  .acc-card-head     { padding: .85rem 1.25rem; font-size: 11px; font-weight: 600; text-transform: uppercase;
                       letter-spacing: .08em; color: var(--accent); border-bottom: 1px solid var(--border); background: var(--surface2);
                       display: flex; justify-content: space-between; align-items: center; }
  .acc-card table    { width: 100%; border-collapse: collapse; }
  .acc-card thead th { padding: .6rem 1.25rem; text-align: left; font-size: 10px; font-weight: 600;
                       letter-spacing: .07em; text-transform: uppercase; color: var(--muted);
                       background: var(--surface2); border-bottom: 1px solid var(--border); }
  .acc-card tbody tr { border-bottom: 1px solid var(--border); }
  .acc-card tbody tr:last-child { border-bottom: none; }
  .acc-card tbody tr:hover { background: var(--surface2); }
  .acc-card td       { padding: .8rem 1.25rem; font-size: 13.5px; vertical-align: middle; }

  /* Summary panel */
  .acc-summary       { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 1.5rem; position: sticky; top: 1.5rem; }
  .acc-summary h3    { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .08em;
                       color: var(--accent); margin-bottom: 1.25rem; padding-bottom: .75rem; border-bottom: 1px solid var(--border); }
  .acc-sum-row       { display: flex; justify-content: space-between; font-size: 13px; padding: .45rem 0; border-bottom: 1px solid var(--border); }
  .acc-sum-row:last-of-type { border-bottom: none; }
  .acc-sum-label     { color: var(--muted); }
  .acc-total-row     { display: flex; justify-content: space-between; align-items: center;
                       margin-top: 1rem; padding-top: .8rem; border-top: 2px solid var(--border2); }
  .acc-total-label   { font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .07em; color: var(--muted); }
  .acc-total-val     { font-family: 'DM Serif Display', serif; font-size: 28px; color: var(--accent); }
  .acc-outstanding   { margin-top: .75rem; padding: .75rem 1rem; border-radius: var(--radius);
                       background: rgba(212,101,90,.08); border: 1px solid rgba(212,101,90,.2); }
  .acc-outstanding.zero { background: rgba(109,171,126,.08); border-color: rgba(109,171,126,.2); }
  .acc-out-label     { font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: .06em; color: var(--rejected); margin-bottom: 3px; }
  .acc-outstanding.zero .acc-out-label { color: var(--approved); }
  .acc-out-val       { font-family: 'DM Serif Display', serif; font-size: 22px; color: var(--rejected); }
  .acc-outstanding.zero .acc-out-val   { color: var(--approved); }
  .pay-now-btn       { display: block; width: 100%; padding: 11px; background: var(--accent); color: #0f0e0c;
                       border: none; border-radius: var(--radius); font-family: 'DM Sans', sans-serif;
                       font-size: 14px; font-weight: 600; cursor: pointer; margin-top: 1rem; text-align: center;
                       text-decoration: none; transition: background .2s; }
  .pay-now-btn:hover { background: var(--accent2); }

  /* ── Payment tab ── */
  .pay-layout        { display: grid; grid-template-columns: 1fr 340px; gap: 1.5rem; align-items: start; }
  .pay-breakdown     { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; }
  .pay-breakdown-head { padding: .85rem 1.25rem; font-size: 11px; font-weight: 600; text-transform: uppercase;
                        letter-spacing: .08em; color: var(--accent); border-bottom: 1px solid var(--border); background: var(--surface2); }
  .pay-breakdown table { width: 100%; border-collapse: collapse; }
  .pay-breakdown thead th { padding: .6rem 1.25rem; text-align: left; font-size: 10px; font-weight: 600;
                             letter-spacing: .07em; text-transform: uppercase; color: var(--muted);
                             background: var(--surface2); border-bottom: 1px solid var(--border); }
  .pay-breakdown tbody tr { border-bottom: 1px solid var(--border); }
  .pay-breakdown tbody tr:last-child { border-bottom: none; }
  .pay-breakdown td  { padding: .8rem 1.25rem; font-size: 13.5px; }
  .pay-form-card     { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 1.5rem; position: sticky; top: 1.5rem; }
  .pay-form-card h3  { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .08em;
                       color: var(--accent); margin-bottom: 1.25rem; padding-bottom: .75rem; border-bottom: 1px solid var(--border); }
  .pay-total-display { font-family: 'DM Serif Display', serif; font-size: 32px; color: var(--accent); margin-bottom: 1.25rem; }
  .pay-total-display span { font-family: 'DM Sans', sans-serif; font-size: 12px; color: var(--muted); font-weight: 400; display: block; margin-bottom: 4px; }
  .pay-method-grid   { display: grid; grid-template-columns: repeat(3,1fr); gap: 8px; margin-bottom: 1.25rem; }
  .pay-method-opt    { position: relative; }
  .pay-method-opt input[type=radio] { position: absolute; opacity: 0; width: 0; height: 0; }
  .pay-method-label  { display: flex; flex-direction: column; align-items: center; justify-content: center;
                       padding: .9rem .5rem; background: var(--surface2); border: 2px solid var(--border);
                       border-radius: var(--radius); cursor: pointer; transition: all .2s; text-align: center; font-size: 13px; font-weight: 600; gap: 4px; }
  .pay-method-opt input[type=radio]:checked + .pay-method-label { background: rgba(201,169,110,.1); border-color: var(--accent); color: var(--accent); }
  .pay-method-sub    { font-size: 10px; font-weight: 400; color: var(--muted); }
  .pay-confirm-btn   { width: 100%; padding: 12px; background: var(--accent); color: #0f0e0c; border: none;
                       border-radius: var(--radius); font-family: 'DM Sans', sans-serif; font-size: 14px;
                       font-weight: 600; cursor: pointer; transition: background .2s; }
  .pay-confirm-btn:hover    { background: var(--accent2); }
  .pay-confirm-btn:disabled { background: var(--surface3); color: var(--muted2); cursor: not-allowed; }

  /* Receipt rows */
  .receipt-card      { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; margin-top: 1rem; }
  .receipt-head      { padding: .85rem 1.25rem; font-size: 11px; font-weight: 600; text-transform: uppercase;
                       letter-spacing: .08em; color: var(--accent); border-bottom: 1px solid var(--border); background: var(--surface2); }
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
    <a class="nav-item <?= $active_tab === 'room' ? 'active' : '' ?>" href="?tab=room">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><path d="M9 22V12h6v10"/></svg>
      Room Booking
      <?php if ($active_booking && $active_booking['Booking_status'] === 'Pending'): ?>
        <span class="nbadge">1</span>
      <?php elseif ($active_booking && $active_booking['Booking_status'] === 'Allocated'): ?>
        <span class="nbadge-green">✓</span>
      <?php endif; ?>
    </a>
    <a class="nav-item <?= $active_tab === 'my_room' ? 'active' : '' ?>" href="?tab=my_room">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v2"/><line x1="12" y1="12" x2="12" y2="16"/><line x1="10" y1="14" x2="14" y2="14"/></svg>
      My Room
      <?php if ($my_room): ?><span class="nbadge-green">✓</span><?php endif; ?>
    </a>
    <a class="nav-item <?= $active_tab === 'my_meal' ? 'active' : '' ?>" href="?tab=my_meal">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M18 8h1a4 4 0 010 8h-1"/><path d="M2 8h16v9a4 4 0 01-4 4H6a4 4 0 01-4-4V8z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/></svg>
      My Meals
      <?php if ($upcoming_meals > 0): ?><span class="nbadge-green"><?= $upcoming_meals ?></span><?php endif; ?>
    </a>
    <a class="nav-item <?= $active_tab === 'visitors' ? 'active' : '' ?>" href="?tab=visitors">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>
      Visitors
      <?php if ($vc['Pending'] > 0): ?><span class="nbadge"><?= $vc['Pending'] ?></span><?php endif; ?>
    </a>
    <a class="nav-item <?= $active_tab === 'accounting' ? 'active' : '' ?>" href="?tab=accounting">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="3" width="20" height="18" rx="2"/><path d="M8 7h8M8 11h8M8 15h5"/></svg>
      Accounting
      <?php if ($outstanding > 0): ?><span class="nbadge">$<?= number_format($outstanding,0) ?></span><?php endif; ?>
    </a>
    <a class="nav-item <?= $active_tab === 'payment' ? 'active' : '' ?>" href="?tab=payment">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>
      Payment
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
    <a class="tab <?= $active_tab === 'overview'    ? 'active' : '' ?>" href="?tab=overview">Overview</a>
    <a class="tab <?= $active_tab === 'room'        ? 'active' : '' ?>" href="?tab=room">Room Booking</a>
    <a class="tab <?= $active_tab === 'my_room'     ? 'active' : '' ?>" href="?tab=my_room">My Room</a>
    <a class="tab <?= $active_tab === 'my_meal'     ? 'active' : '' ?>" href="?tab=my_meal">My Meals</a>
    <a class="tab <?= $active_tab === 'visitors'    ? 'active' : '' ?>" href="?tab=visitors">Visitors</a>
    <a class="tab <?= $active_tab === 'accounting'  ? 'active' : '' ?>" href="?tab=accounting">Accounting</a>
    <a class="tab <?= $active_tab === 'payment'     ? 'active' : '' ?>" href="?tab=payment">Payment</a>
  </div>

  <?php /* ══════════════════ OVERVIEW ══════════════════ */ ?>
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

    <?php if ($active_booking): ?>
      <div class="booking-alert <?= $active_booking['Booking_status'] === 'Allocated' ? 'allocated' : '' ?>">
        <div class="booking-alert-title">
          <?= $active_booking['Booking_status'] === 'Allocated' ? '✓ Room Allocated' : '⏳ Room Booking Pending' ?>
        </div>
        <div class="booking-alert-body">
          <div class="booking-alert-item">
            <span>Booking #</span><span>#<?= $active_booking['Booking_ID'] ?></span>
          </div>
          <div class="booking-alert-item">
            <span>Type</span><span><?= htmlspecialchars($active_booking['Room_type_requested']) ?></span>
          </div>
          <div class="booking-alert-item">
            <span>Booked on</span><span><?= date('d M Y', strtotime($active_booking['Booking_date'])) ?></span>
          </div>
          <?php if ($active_booking['Booking_status'] === 'Allocated'): ?>
            <div class="booking-alert-item">
              <span>Room</span>
              <span>Floor <?= $active_booking['Floor_num'] ?>, Room <?= $active_booking['Room_num'] ?>, Bed <?= $active_booking['Bed_num'] ?></span>
            </div>
            <?php if ($active_booking['Check_in_date']): ?>
            <div class="booking-alert-item">
              <span>Check-in</span><span><?= date('d M Y', strtotime($active_booking['Check_in_date'])) ?></span>
            </div>
            <?php endif; ?>
          <?php else: ?>
            <div class="booking-alert-item">
              <span>Status</span><span style="color:var(--pending)">Awaiting manager allocation</span>
            </div>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

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
        <?php if ($active_booking): ?>
        <div class="info-row">
          <span class="info-label">Room booking</span>
          <span class="info-value <?= strtolower($active_booking['Booking_status']) ?>"><?= $active_booking['Booking_status'] ?></span>
        </div>
        <?php endif; ?>
        <?php if ($my_room): ?>
        <div class="info-row">
          <span class="info-label">Room assigned</span>
          <span class="info-value approved">Floor <?= $my_room['floor'] ?> · Room <?= $my_room['room'] ?></span>
        </div>
        <div class="info-row">
          <span class="info-label">Bed number</span>
          <span class="info-value"><?= $my_room['Bed_num'] ?? '—' ?></span>
        </div>
        <?php endif; ?>
      </div>
    </div>

  <?php /* ══════════════════ ROOM BOOKING ══════════════════ */ ?>
  <?php elseif ($active_tab === 'room'): ?>

    <?php if ($client['Account_Status'] !== 'Approved'): ?>
      <div class="status-banner pending">
        ⏳ Room booking is only available for approved clients. Your account is currently <strong><?= $client['Account_Status'] ?></strong>.
      </div>

    <?php else: ?>

      <?php if ($active_booking && $active_booking['Booking_status'] === 'Pending'): ?>
        <div class="booking-alert" style="margin-bottom:1.5rem;">
          <div class="booking-alert-title">⏳ Booking Request Pending</div>
          <div class="booking-alert-body">
            <div class="booking-alert-item"><span>Booking #</span><span>#<?= $active_booking['Booking_ID'] ?></span></div>
            <div class="booking-alert-item"><span>Type requested</span><span><?= htmlspecialchars($active_booking['Room_type_requested']) ?></span></div>
            <div class="booking-alert-item"><span>Submitted</span><span><?= date('d M Y', strtotime($active_booking['Booking_date'])) ?></span></div>
            <div class="booking-alert-item"><span>Preferred check-in</span><span><?= $active_booking['Check_in_date'] ? date('d M Y', strtotime($active_booking['Check_in_date'])) : '—' ?></span></div>
          </div>
          <div style="margin-top:.75rem; font-size:12.5px; color:var(--muted);">
            A manager will review your request and allocate a room. You will see the details here once allocated.
          </div>
        </div>
      <?php endif; ?>

      <?php if ($active_booking && $active_booking['Booking_status'] === 'Allocated'): ?>
        <div class="booking-alert allocated" style="margin-bottom:1.5rem;">
          <div class="booking-alert-title">✓ Room Allocated</div>
          <div class="booking-alert-body">
            <div class="booking-alert-item"><span>Floor</span><span><?= $active_booking['Floor_num'] ?></span></div>
            <div class="booking-alert-item"><span>Room</span><span><?= $active_booking['Room_num'] ?></span></div>
            <div class="booking-alert-item"><span>Bed</span><span><?= $active_booking['Bed_num'] ?></span></div>
            <div class="booking-alert-item"><span>Type</span><span><?= htmlspecialchars($active_booking['Room_type_requested']) ?></span></div>
            <?php if ($active_booking['Check_in_date']): ?>
            <div class="booking-alert-item"><span>Check-in</span><span><?= date('d M Y', strtotime($active_booking['Check_in_date'])) ?></span></div>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>

      <div class="booking-section">

        <!-- LEFT: booking form -->
        <div>
          <div class="booking-form-card">
            <h3><?= ($active_booking && in_array($active_booking['Booking_status'], ['Pending','Allocated'])) ? 'Booking History' : 'Book a Room' ?></h3>

            <?php if (!$active_booking || !in_array($active_booking['Booking_status'], ['Pending','Allocated'])): ?>
              <form method="POST" action="?tab=room">
                <input type="hidden" name="action" value="book_room">

                <div style="margin-bottom:1.1rem;">
                  <div style="font-size:11px;font-weight:500;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px;">Room type</div>
                  <div class="type-toggle">
                    <div class="type-option">
                      <input type="radio" name="room_type_requested" id="type_ac" value="AC" required>
                      <label for="type_ac" class="type-label">
                        <span class="tl-name">AC</span>
                        <span class="tl-pkg" style="font-size:11px;color:var(--accent);font-weight:600;">Spacious</span>
                        <span class="tl-desc">$400 / month · <?= $ac_avail ?> available</span>
                      </label>
                    </div>
                    <div class="type-option">
                      <input type="radio" name="room_type_requested" id="type_nonac" value="Non-AC" required>
                      <label for="type_nonac" class="type-label">
                        <span class="tl-name">Non-AC</span>
                        <span class="tl-pkg" style="font-size:11px;color:var(--accent);font-weight:600;">Regular</span>
                        <span class="tl-desc">$250 / month · <?= $nac_avail ?> available</span>
                      </label>
                    </div>
                  </div>
                </div>

                <div class="booking-field">
                  <label>Duration (months)</label>
                  <input type="number" name="duration_months" min="1" max="24" placeholder="e.g. 3" required
                         value="<?= intval($_POST['duration_months'] ?? 1) ?>">
                  <div style="font-size:11px;color:var(--muted);margin-top:5px;">Between 1 and 24 months.</div>
                </div>

                <div class="booking-field">
                  <label>Preferred check-in date</label>
                  <input type="date" name="preferred_checkin" value="<?= date('Y-m-d') ?>" min="<?= date('Y-m-d') ?>">
                </div>

                <button type="submit" class="booking-submit" onclick="return confirm('Submit room booking request?')">
                  Submit Booking Request
                </button>
              </form>

            <?php else: ?>
              <p style="font-size:13px;color:var(--muted);line-height:1.6;">
                You have an active booking request. Once processed, you can find your full booking history in the table below.
              </p>
            <?php endif; ?>
          </div>

          <!-- My booking history -->
          <?php if (!empty($my_bookings)): ?>
          <div style="margin-top:1.25rem;">
            <div class="bookings-header"><div class="section-title">My Booking History</div></div>
            <div class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>#</th>
                    <th>Type</th>
                    <th>Booked</th>
                    <th>Check-in</th>
                    <th>Room</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($my_bookings as $b): ?>
                  <tr>
                    <td class="cell-muted"><?= $b['Booking_ID'] ?></td>
                    <td>
                      <span class="badge <?= $b['Room_type_requested'] === 'AC' ? 'badge-ac' : 'badge-nonac' ?>">
                        <?= htmlspecialchars($b['Room_type_requested']) ?>
                      </span>
                    </td>
                    <td class="cell-muted"><?= date('d M Y', strtotime($b['Booking_date'])) ?></td>
                    <td class="cell-muted"><?= $b['Check_in_date'] ? date('d M Y', strtotime($b['Check_in_date'])) : '—' ?></td>
                    <td>
                      <?php if ($b['Booking_status'] === 'Allocated'): ?>
                        <span class="room-detail-chip">F<?= $b['Floor_num'] ?> · R<?= $b['Room_num'] ?> · B<?= $b['Bed_num'] ?></span>
                      <?php else: ?>
                        <span class="cell-muted">—</span>
                      <?php endif; ?>
                    </td>
                    <td><span class="badge badge-<?= strtolower($b['Booking_status']) ?>"><?= $b['Booking_status'] ?></span></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
          <?php endif; ?>
        </div>

        <!-- RIGHT: room availability viewer -->
        <div class="avail-panel">
          <h3>Room Availability</h3>

          <div class="avail-summary">
            <div class="avail-stat">
              <div class="avail-stat-label">❄️ AC Rooms</div>
              <div class="avail-stat-val g"><?= $ac_avail ?></div>
              <div class="avail-stat-sub">available of <?= $ac_total ?> total</div>
            </div>
            <div class="avail-stat">
              <div class="avail-stat-label">🌀 Non-AC Rooms</div>
              <div class="avail-stat-val g"><?= $nac_avail ?></div>
              <div class="avail-stat-sub">available of <?= $nac_total ?> total</div>
            </div>
          </div>

          <?php if (empty($room_grid)): ?>
            <div class="empty-state">No rooms have been configured yet. Please check back later.</div>
          <?php else: ?>
            <div class="floor-filter" id="floorFilter">
              <span class="floor-pill active" onclick="filterFloor('all', this)">All Floors</span>
              <?php for ($fl = 1; $fl <= 6; $fl++): ?>
                <?php if (!empty($room_grid[$fl])): ?>
                  <span class="floor-pill" onclick="filterFloor(<?= $fl ?>, this)">Floor <?= $fl ?></span>
                <?php endif; ?>
              <?php endfor; ?>
            </div>

            <?php for ($fl = 1; $fl <= 6; $fl++): ?>
              <?php if (empty($room_grid[$fl])) continue; ?>
              <div class="floor-section" data-floor="<?= $fl ?>">
                <div class="floor-label">
                  Floor <?= $fl ?>
                  &nbsp;·&nbsp;
                  AC: <?= array_reduce(array_filter($room_grid[$fl], fn($r) => $r['Room_type'] === 'AC' && $r['Status'] === 'Available'), fn($c) => $c + 1, 0) ?> avail
                  &nbsp;·&nbsp;
                  Non-AC: <?= array_reduce(array_filter($room_grid[$fl], fn($r) => $r['Room_type'] === 'Non-AC' && $r['Status'] === 'Available'), fn($c) => $c + 1, 0) ?> avail
                </div>
                <div class="room-cells">
                  <?php foreach ($room_grid[$fl] as $rm => $cell):
                    $occ = $cell['occupants']; $cap = $cell['Capacity'];
                    $is_full = ($occ >= $cap) || $cell['Status'] === 'Unavailable';
                    $partial = ($occ > 0 && $occ < $cap);
                    $cell_cls = $is_full ? 'full' : ($cell['Status'] === 'Unavailable' ? 'unavailable' : 'available');
                    $dot_cls  = $is_full ? 'r' : ($partial ? 'y' : 'g');
                    $type_short = $cell['Room_type'] === 'AC' ? 'AC' : 'N-AC';
                    $tooltip = "Floor $fl · Room $rm · {$cell['Room_type']} · $occ/$cap occupied · {$cell['Status']}";
                  ?>
                    <div class="room-cell <?= $cell_cls ?>" title="<?= htmlspecialchars($tooltip) ?>">
                      <div class="rc-num"><?= $rm ?></div>
                      <div class="rc-occ"><?= $occ ?>/<?= $cap ?></div>
                      <div class="rc-type"><?= $type_short ?></div>
                      <div class="rc-dot <?= $dot_cls ?>"></div>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endfor; ?>

            <div class="room-legend">
              <div class="legend-item"><div class="legend-dot" style="background:var(--approved)"></div>Available</div>
              <div class="legend-item"><div class="legend-dot" style="background:var(--pending)"></div>Partially occupied</div>
              <div class="legend-item"><div class="legend-dot" style="background:var(--rejected)"></div>Full / Unavailable</div>
            </div>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

  <?php /* ══════════════════ MY ROOM ══════════════════ */ ?>
  <?php elseif ($active_tab === 'my_room'): ?>

    <?php if (!$my_room): ?>
      <?php if ($client['Account_Status'] !== 'Approved'): ?>
        <div class="status-banner pending">
          ⏳ Your account is not yet approved. Room assignment information will appear here once your account is approved and a room is allocated by a manager.
        </div>
      <?php elseif (!$active_booking): ?>
        <div class="status-banner pending">
          🏠 You haven't submitted a room booking request yet.
          <a href="?tab=room" style="color:var(--accent);font-weight:600;margin-left:6px;">Book a room →</a>
        </div>
      <?php elseif ($active_booking['Booking_status'] === 'Pending'): ?>
        <div class="status-banner pending">
          ⏳ Your booking request (#<?= $active_booking['Booking_ID'] ?>) is pending. A manager will assign your room shortly. Check back here once allocated.
        </div>
      <?php else: ?>
        <div class="status-banner pending">
          ⏳ No room assignment found yet. Please contact management if this persists after your booking was marked Allocated.
        </div>
      <?php endif; ?>

    <?php else: ?>

      <?php
        $occ      = $my_room['total_occupants'];
        $cap      = $my_room['Capacity'];
        $free     = max(0, $cap - $occ);
        $pct      = $cap > 0 ? round(($occ / $cap) * 100) : 0;
        $type     = $my_room['Room_type'];
        $is_ac    = $type === 'AC';
        $type_icon = $is_ac ? '❄️' : '🌀';
      ?>

      <!-- Room identity hero card -->
      <div class="room-hero">
        <div class="room-hero-left">
          <div class="room-hero-icon"><?= $type_icon ?></div>
          <div>
            <div class="room-hero-label">Your Room</div>
            <div class="room-hero-title">Floor <?= $my_room['floor'] ?> · Room <?= $my_room['room'] ?></div>
            <div class="room-hero-sub"><?= htmlspecialchars($type) ?> · Bed #<?= $my_room['Bed_num'] ?? '—' ?></div>
          </div>
        </div>
        <div class="room-hero-right">
          <div class="room-status-badge <?= $my_room['room_status'] === 'Available' ? 'rs-avail' : 'rs-full' ?>">
            <?= $my_room['room_status'] ?>
          </div>
        </div>
      </div>

      <!-- Stats row -->
      <div class="room-stats-row">
        <div class="room-stat-card">
          <div class="room-stat-icon">🏠</div>
          <div class="room-stat-body">
            <div class="room-stat-label">Floor</div>
            <div class="room-stat-val"><?= $my_room['floor'] ?></div>
          </div>
        </div>
        <div class="room-stat-card">
          <div class="room-stat-icon">🚪</div>
          <div class="room-stat-body">
            <div class="room-stat-label">Room Number</div>
            <div class="room-stat-val"><?= $my_room['room'] ?></div>
          </div>
        </div>
        <div class="room-stat-card">
          <div class="room-stat-icon">🛏️</div>
          <div class="room-stat-body">
            <div class="room-stat-label">Your Bed</div>
            <div class="room-stat-val"><?= $my_room['Bed_num'] ?? '—' ?></div>
          </div>
        </div>
        <div class="room-stat-card">
          <div class="room-stat-icon"><?= $type_icon ?></div>
          <div class="room-stat-body">
            <div class="room-stat-label">Room Type</div>
            <div class="room-stat-val"><?= $is_ac ? 'AC' : 'Non-AC' ?></div>
          </div>
        </div>
      </div>

      <!-- Two-column detail section -->
      <div class="room-detail-grid">

        <!-- Room details card -->
        <div class="info-card">
          <h3>Room Details</h3>
          <div class="info-row">
            <span class="info-label">Location</span>
            <span class="info-value">Floor <?= $my_room['floor'] ?>, Room <?= $my_room['room'] ?></span>
          </div>
          <div class="info-row">
            <span class="info-label">Room type</span>
            <span class="info-value"><?= htmlspecialchars($type) ?></span>
          </div>
          <div class="info-row">
            <span class="info-label">Capacity</span>
            <span class="info-value"><?= $cap ?> beds</span>
          </div>
          <div class="info-row">
            <span class="info-label">Occupied</span>
            <span class="info-value"><?= $occ ?> / <?= $cap ?></span>
          </div>
          <div class="info-row">
            <span class="info-label">Free beds</span>
            <span class="info-value <?= $free > 0 ? 'approved' : 'rejected' ?>"><?= $free ?></span>
          </div>
          <div class="info-row">
            <span class="info-label">Room status</span>
            <span class="info-value <?= $my_room['room_status'] === 'Available' ? 'approved' : 'rejected' ?>">
              <?= $my_room['room_status'] ?>
            </span>
          </div>

          <!-- Occupancy bar -->
          <div style="margin-top:1rem;">
            <div style="font-size:11px;color:var(--muted);margin-bottom:5px;">Occupancy — <?= $occ ?>/<?= $cap ?> (<?= $pct ?>%)</div>
            <div class="occ-bar-track">
              <div class="occ-bar-fill <?= $pct >= 100 ? 'full' : ($pct >= 50 ? 'mid' : 'low') ?>"
                   style="width:<?= $pct ?>%"></div>
            </div>
          </div>

          <!-- Bed map -->
          <div style="margin-top:1.25rem;">
            <div style="font-size:11px;color:var(--muted);margin-bottom:.6rem;">Bed map</div>
            <div class="bed-map">
              <?php for ($b = 1; $b <= $cap; $b++):
                $is_mine = ($b == $my_room['Bed_num']);
                // Check if bed taken by a roommate
                $taken = false;
                foreach ($roommates as $rm) { if ($rm['Bed_num'] == $b) { $taken = true; break; } }
                $cls = $is_mine ? 'bed-mine' : ($taken ? 'bed-taken' : 'bed-free');
              ?>
                <div class="bed-cell <?= $cls ?>" title="Bed <?= $b ?><?= $is_mine ? ' (Yours)' : ($taken ? ' (Occupied)' : ' (Free)') ?>">
                  <span class="bed-icon">🛏️</span>
                  <span class="bed-num"><?= $b ?></span>
                  <?php if ($is_mine): ?><span class="bed-tag">You</span><?php endif; ?>
                </div>
              <?php endfor; ?>
            </div>
            <div class="bed-legend">
              <span class="bed-legend-item"><span class="bed-dot mine"></span>Your bed</span>
              <span class="bed-legend-item"><span class="bed-dot taken"></span>Occupied</span>
              <span class="bed-legend-item"><span class="bed-dot free"></span>Free</span>
            </div>
          </div>
        </div>

        <!-- Booking & roommates -->
        <div style="display:flex;flex-direction:column;gap:1rem;">

          <!-- Booking info card -->
          <div class="info-card">
            <h3>Booking Details</h3>
            <?php if ($my_room['Booking_ID']): ?>
              <div class="info-row">
                <span class="info-label">Booking ID</span>
                <span class="info-value">#<?= $my_room['Booking_ID'] ?></span>
              </div>
              <div class="info-row">
                <span class="info-label">Status</span>
                <span class="info-value approved">Allocated</span>
              </div>
              <?php if ($my_room['Check_in_date']): ?>
              <div class="info-row">
                <span class="info-label">Check-in date</span>
                <span class="info-value"><?= date('d M Y', strtotime($my_room['Check_in_date'])) ?></span>
              </div>
              <?php endif; ?>
              <div class="info-row">
                <span class="info-label">Assigned bed</span>
                <span class="info-value">Bed #<?= $my_room['Bed_num'] ?? '—' ?></span>
              </div>
            <?php else: ?>
              <div class="info-row"><span class="info-label" style="color:var(--muted)">Booking details not linked.</span></div>
            <?php endif; ?>
          </div>

          <!-- Roommates card -->
          <div class="info-card">
            <h3>Roommates <span style="font-weight:400;font-size:10px;color:var(--muted2);">(<?= count($roommates) ?> other<?= count($roommates) !== 1 ? 's' : '' ?>)</span></h3>
            <?php if (empty($roommates)): ?>
              <div style="font-size:13px;color:var(--muted);padding:.5rem 0;">
                <?= $occ <= 1 ? "You're the only resident in this room so far." : "No other residents found." ?>
              </div>
            <?php else: ?>
              <div style="display:flex;flex-direction:column;gap:.6rem;">
                <?php foreach ($roommates as $i => $rm): ?>
                  <div class="roommate-row">
                    <div class="rm-avatar"><?= strtoupper(substr($rm['F_name'], 0, 1)) ?></div>
                    <div class="rm-info">
                      <div class="rm-name"><?= htmlspecialchars($rm['F_name'] . ' ' . $rm['L_name']) ?></div>
                      <div class="rm-bed">Bed #<?= $rm['Bed_num'] ?? '?' ?></div>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>

          <!-- Room location visual -->
          <div class="info-card">
            <h3>Location in Building</h3>
            <div class="building-visual">
              <?php for ($fl = 6; $fl >= 1; $fl--): ?>
                <div class="bv-floor <?= $fl === intval($my_room['floor']) ? 'bv-current' : '' ?>">
                  <div class="bv-floor-label">F<?= $fl ?></div>
                  <div class="bv-rooms">
                    <?php for ($rm = 1; $rm <= 20; $rm++):
                      $is_my_room = ($fl === intval($my_room['floor']) && $rm === intval($my_room['room']));
                    ?>
                      <div class="bv-room <?= $is_my_room ? 'bv-mine' : '' ?>"
                           title="<?= $is_my_room ? 'Your room' : "Floor $fl · Room $rm" ?>"></div>
                    <?php endfor; ?>
                  </div>
                </div>
              <?php endfor; ?>
              <div style="font-size:10px;color:var(--muted);margin-top:.5rem;display:flex;gap:1rem;">
                <span><span style="display:inline-block;width:8px;height:8px;background:var(--accent);border-radius:2px;margin-right:4px;"></span>Your room</span>
                <span><span style="display:inline-block;width:8px;height:8px;background:var(--surface3);border:1px solid var(--border);border-radius:2px;margin-right:4px;"></span>Other rooms</span>
              </div>
            </div>
          </div>

        </div>
      </div>

    <?php endif; ?>

  <?php /* ================== MY MEAL ================== */ ?>
  <?php elseif ($active_tab === 'my_meal'): ?>

    <?php
    // Category and size are carried via GET so clicking cards does a full PHP round-trip.
    // After a successful POST booking, we fall back to GET values (or defaults).
    $sel_cat = trim($_GET['meal_cat'] ?? ($_POST['meal_category']    ?? 'Breakfast'));
    $sel_sub = trim($_GET['meal_sub'] ?? ($_POST['meal_subcategory'] ?? ''));
    if (!in_array($sel_cat, $VALID_CATS)) $sel_cat = 'Breakfast';
    if (!in_array($sel_sub, $VALID_SUBS)) $sel_sub = '';
    ?>

    <div class="meal-layout">

      <!-- ── LEFT: menu selector ── -->
      <div>

        <!-- Category strip: each card is a plain link -->
        <div class="cat-strip">
          <?php foreach ($MEAL_MENU as $cat => $data): ?>
            <a class="cat-card <?= $cat === $sel_cat ? 'active' : '' ?>"
               href="?tab=my_meal&meal_cat=<?= urlencode($cat) ?>">
              <span class="cn"><?= htmlspecialchars($cat) ?></span>
            </a>
          <?php endforeach; ?>
        </div>

        <!-- Sub-category cards for the selected category only -->
        <?php if (isset($MEAL_MENU[$sel_cat])): ?>
          <div class="sub-grid">
            <?php foreach ($VALID_SUBS as $sub):
              $entry  = $MEAL_MENU[$sel_cat][$sub];
              $sub_lc = strtolower($sub);
              $active_cls = ($sub === $sel_sub) ? "active-$sub_lc" : '';
            ?>
              <a class="sub-card <?= $active_cls ?>"
                 href="?tab=my_meal&meal_cat=<?= urlencode($sel_cat) ?>&meal_sub=<?= urlencode($sub) ?>">
                <div class="sub-badge sb-<?= $sub_lc ?>"><?= $sub ?></div>
                <div class="sub-price">$<?= number_format($entry['price'], 0) ?> <span>/ meal</span></div>
                <ul class="sub-items">
                  <?php foreach ($entry['items'] as $item): ?>
                    <li><?= htmlspecialchars($item) ?></li>
                  <?php endforeach; ?>
                </ul>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <!-- Weekly planner -->
        <div class="info-card" style="margin-top:1.5rem;">
          <h3 style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:var(--accent);margin-bottom:1rem;padding-bottom:.7rem;border-bottom:1px solid var(--border);">
            This Week's Meals
          </h3>
          <div class="week-grid">
            <?php
            for ($d = 0; $d < 7; $d++):
              $ts      = strtotime("+$d days");
              $dkey    = date('Y-m-d', $ts);
              $dlabel  = date('D', $ts);
              $dnum    = date('j', $ts);
              $is_today = ($dkey === date('Y-m-d'));
              $day_meals = $meal_by_date[$dkey] ?? [];
            ?>
              <div class="week-cell <?= $is_today ? 'today' : '' ?>">
                <div class="week-day-label"><?= $dlabel ?></div>
                <div class="week-date-num <?= $is_today ? 'today-num' : '' ?>"><?= $dnum ?></div>
                <?php if (empty($day_meals)): ?>
                  <div class="week-meal-dot" style="color:var(--border2);">—</div>
                <?php else: ?>
                  <?php foreach ($day_meals as $dm):
                    [$dmcat, $dmsub] = array_pad(explode(' - ', $dm['Type']), 2, '');
                    $pip_cls = 'wmp-' . strtolower($dmsub ?: 'light');
                  ?>
                    <div title="<?= htmlspecialchars($dm['Type']) ?> — $<?= $dm['Total_cost'] ?>">
                      <span class="week-meal-pip <?= $pip_cls ?>"></span>
                      <span class="week-meal-dot"><?= htmlspecialchars($dmcat) ?></span>
                    </div>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>
            <?php endfor; ?>
          </div>
          <div style="display:flex;gap:1rem;margin-top:.75rem;flex-wrap:wrap;">
            <span style="font-size:11px;color:var(--muted);display:flex;align-items:center;gap:4px;"><span class="week-meal-pip wmp-light"></span>Light</span>
            <span style="font-size:11px;color:var(--muted);display:flex;align-items:center;gap:4px;"><span class="week-meal-pip wmp-medium"></span>Medium</span>
            <span style="font-size:11px;color:var(--muted);display:flex;align-items:center;gap:4px;"><span class="week-meal-pip wmp-full"></span>Full</span>
          </div>
        </div>

      </div><!-- end left col -->

      <!-- ── RIGHT: booking form + history ── -->
      <div class="meal-right-col">

        <!-- Booking form -->
        <div class="meal-form-card">
          <h3>Order Summary</h3>

          <div class="meal-placeholder"
               style="<?= $sel_sub ? 'display:none;' : '' ?>">
            Select a category above, then choose a size to build your order.
          </div>

          <div style="<?= !$sel_sub ? 'display:none;' : '' ?>">
            <div class="meal-summary-row">
              <span class="msr-label">Category</span>
              <span class="msr-val"><?= htmlspecialchars($sel_cat) ?></span>
            </div>
            <div class="meal-summary-row">
              <span class="msr-label">Size</span>
              <span class="msr-val"><?= htmlspecialchars($sel_sub) ?></span>
            </div>
            <div class="meal-summary-row">
              <span class="msr-label">Includes</span>
              <span class="msr-val" style="text-align:right;font-size:12px;">
                <?php if ($sel_sub && isset($MEAL_MENU[$sel_cat][$sel_sub])): ?>
                  <?= implode(', ', $MEAL_MENU[$sel_cat][$sel_sub]['items']) ?>
                <?php endif; ?>
              </span>
            </div>
            <div class="meal-total-row">
              <span class="meal-total-label">Total</span>
              <span class="meal-total-price">
                $<?= ($sel_sub && isset($MEAL_MENU[$sel_cat][$sel_sub])) ? number_format($MEAL_MENU[$sel_cat][$sel_sub]['price'], 0) : '0' ?>
              </span>
            </div>
          </div>

          <form method="POST" action="?tab=my_meal&meal_cat=<?= urlencode($sel_cat) ?>&meal_sub=<?= urlencode($sel_sub) ?>">
            <input type="hidden" name="action" value="book_meal">
            <input type="hidden" name="meal_category"    value="<?= htmlspecialchars($sel_cat) ?>">
            <input type="hidden" name="meal_subcategory" value="<?= htmlspecialchars($sel_sub) ?>">

            <div class="meal-date-field">
              <label>Date</label>
              <input type="date" name="meal_date"
                     value="<?= date('Y-m-d') ?>"
                     min="<?= date('Y-m-d') ?>" required>
            </div>

            <button type="submit" class="meal-submit"
                    <?= !$sel_sub ? 'disabled' : '' ?>>
              <?= $sel_sub ? "Confirm Booking" : "Select a size above" ?>
            </button>
          </form>
        </div>

        <!-- Booking history -->
        <div class="meal-hist-card">
          <div class="meal-hist-head">Booking History</div>
          <?php if (empty($meal_rows)): ?>
            <div class="empty-state">No meal bookings yet.</div>
          <?php else: ?>
            <table>
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Meal</th>
                  <th>Cost</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($meal_rows as $mr):
                  [$mcat, $msub] = array_pad(explode(' - ', $mr['Type']), 2, '');
                  $msub_lc  = strtolower($msub ?: 'light');
                  $is_past  = $mr['Date'] < date('Y-m-d');
                ?>
                  <tr>
                    <td>
                      <div style="font-weight:600;font-size:12px;"><?= date('d M', strtotime($mr['Date'])) ?></div>
                      <div class="cell-muted" style="font-size:11px;"><?= date('Y', strtotime($mr['Date'])) ?></div>
                    </td>
                    <td>
                      <span class="sub-badge sb-<?= $msub_lc ?>" style="margin-bottom:0;"><?= htmlspecialchars($msub) ?></span>
                      <div style="font-size:12px;color:var(--muted);margin-top:3px;"><?= htmlspecialchars($mcat) ?></div>
                    </td>
                    <td style="font-weight:600;color:var(--accent);">$<?= number_format($mr['Total_cost'], 0) ?></td>
                    <td>
                      <?php if (!$is_past): ?>
                        <form method="POST" action="?tab=my_meal" style="margin:0;"
                              onsubmit="return confirm('Cancel this meal booking?')">
                          <input type="hidden" name="action"  value="cancel_meal">
                          <input type="hidden" name="meal_id" value="<?= $mr['Meal_Booking_ID'] ?>">
                          <button type="submit" class="cancel-btn">Cancel</button>
                        </form>
                      <?php else: ?>
                        <span style="font-size:11px;color:var(--muted2);">Past</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>

      </div><!-- end right col -->
    </div><!-- end meal-layout -->

  <?php /* ══════════════════ VISITORS ══════════════════ */ ?>
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

  <?php /* ══════════════════ ACCOUNTING ══════════════════ */ ?>
  <?php if ($active_tab === 'accounting'): ?>

    <div class="acc-layout">

      <!-- LEFT: ledger -->
      <div>

        <!-- Room charges -->
        <div class="acc-card">
          <div class="acc-card-head">
            <span>Room</span>
            <span style="color:var(--text);font-family:'DM Serif Display',serif;font-size:16px;">
              $<?= number_format($room_total, 2) ?>
            </span>
          </div>
          <?php if (empty($acc_room_rows)): ?>
            <div class="empty-state" style="padding:1.5rem;">No room charges yet.</div>
          <?php else: ?>
            <table>
              <thead><tr><th>Package</th><th>Type</th><th>Duration</th><th>Date</th><th style="text-align:right;">Amount</th></tr></thead>
              <tbody>
                <?php foreach ($acc_room_rows as $r): ?>
                <tr>
                  <td class="fw500"><?= htmlspecialchars($r['Package_Name']) ?></td>
                  <td><span class="badge <?= $r['Room_Type'] === 'AC' ? 'badge-ac' : 'badge-nonac' ?>"><?= htmlspecialchars($r['Room_Type']) ?></span></td>
                  <td class="cell-muted"><?= $r['Duration'] ?> month<?= $r['Duration'] > 1 ? 's' : '' ?></td>
                  <td class="cell-muted"><?= date('d M Y', strtotime($r['Entry_Date'])) ?></td>
                  <td style="text-align:right;font-weight:600;">$<?= number_format($r['Price'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>

        <!-- Meal charges -->
        <div class="acc-card">
          <div class="acc-card-head">
            <span>Meals</span>
            <span style="color:var(--text);font-family:'DM Serif Display',serif;font-size:16px;">
              $<?= number_format($meal_total, 2) ?>
            </span>
          </div>
          <?php if (empty($acc_meal_rows)): ?>
            <div class="empty-state" style="padding:1.5rem;">No meal charges yet.</div>
          <?php else: ?>
            <table>
              <thead><tr><th>Meal</th><th>Date</th><th style="text-align:right;">Amount</th></tr></thead>
              <tbody>
                <?php foreach ($acc_meal_rows as $r): ?>
                <tr>
                  <td class="fw500"><?= htmlspecialchars($r['Package_Name']) ?></td>
                  <td class="cell-muted"><?= date('d M Y', strtotime($r['Entry_Date'])) ?></td>
                  <td style="text-align:right;font-weight:600;">$<?= number_format($r['Price'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>

        <!-- Payment history -->
        <?php if (!empty($pay_rows)): ?>
        <div class="acc-card">
          <div class="acc-card-head"><span>Payment History</span></div>
          <table>
            <thead><tr><th>Receipt #</th><th>Method</th><th>Date</th><th style="text-align:right;">Amount Paid</th></tr></thead>
            <tbody>
              <?php foreach ($pay_rows as $p): ?>
              <tr>
                <td class="cell-muted">#<?= $p['TX_ID'] ?></td>
                <td><?= htmlspecialchars($p['Payment_Method']) ?></td>
                <td class="cell-muted"><?= date('d M Y', strtotime($p['Payment_Date'])) ?></td>
                <td style="text-align:right;color:var(--approved);font-weight:600;">-$<?= number_format($p['Payment_Amount'], 2) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>

      </div>

      <!-- RIGHT: summary -->
      <div class="acc-summary">
        <h3>Account Summary</h3>
        <div class="acc-sum-row"><span class="acc-sum-label">Room charges</span><span>$<?= number_format($room_total, 2) ?></span></div>
        <div class="acc-sum-row"><span class="acc-sum-label">Meal charges</span><span>$<?= number_format($meal_total, 2) ?></span></div>
        <div class="acc-sum-row"><span class="acc-sum-label">Total paid</span><span style="color:var(--approved);">-$<?= number_format($total_paid, 2) ?></span></div>
        <div class="acc-total-row">
          <span class="acc-total-label">Total charges</span>
          <span class="acc-total-val">$<?= number_format($total_charges, 2) ?></span>
        </div>
        <div class="acc-outstanding <?= $outstanding <= 0 ? 'zero' : '' ?>">
          <div class="acc-out-label"><?= $outstanding > 0 ? 'Outstanding' : 'Settled' ?></div>
          <div class="acc-out-val">$<?= number_format(max(0, $outstanding), 2) ?></div>
        </div>
        <?php if ($outstanding > 0): ?>
          <a href="?tab=payment" class="pay-now-btn">Make Payment</a>
        <?php endif; ?>
      </div>

    </div>

  <?php /* ══════════════════ PAYMENT ══════════════════ */ ?>
  <?php elseif ($active_tab === 'payment'): ?>

    <div class="pay-layout">

      <!-- LEFT: full breakdown -->
      <div>
        <div class="pay-breakdown">
          <div class="pay-breakdown-head">Charges Breakdown</div>
          <?php if (empty($acc_rows)): ?>
            <div class="empty-state" style="padding:2rem;">No charges on your account yet.</div>
          <?php else: ?>
            <table>
              <thead><tr><th>Description</th><th>Type</th><th>Date</th><th style="text-align:right;">Amount</th></tr></thead>
              <tbody>
                <?php foreach ($acc_rows as $r): ?>
                <tr>
                  <td class="fw500">
                    <?= htmlspecialchars($r['Package_Name']) ?>
                    <?php if ($r['Entry_Type'] === 'Room'): ?>
                      <div class="cell-muted"><?= $r['Duration'] ?> month<?= $r['Duration'] > 1 ? 's' : '' ?></div>
                    <?php endif; ?>
                  </td>
                  <td><span class="badge <?= $r['Entry_Type'] === 'Room' ? 'badge-allocated' : 'badge-pending' ?>"><?= $r['Entry_Type'] ?></span></td>
                  <td class="cell-muted"><?= date('d M Y', strtotime($r['Entry_Date'])) ?></td>
                  <td style="text-align:right;font-weight:600;">$<?= number_format($r['Price'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
                <tr style="background:var(--surface2);">
                  <td colspan="3" style="font-weight:600;font-size:13px;">Total Charges</td>
                  <td style="text-align:right;font-weight:700;font-size:15px;">$<?= number_format($total_charges, 2) ?></td>
                </tr>
                <?php if ($total_paid > 0): ?>
                <tr style="background:var(--surface2);">
                  <td colspan="3" style="color:var(--approved);font-size:13px;">Already Paid</td>
                  <td style="text-align:right;color:var(--approved);font-weight:600;">-$<?= number_format($total_paid, 2) ?></td>
                </tr>
                <tr style="background:var(--surface2);">
                  <td colspan="3" style="font-weight:700;font-size:13px;">Outstanding Balance</td>
                  <td style="text-align:right;font-weight:700;font-size:16px;color:var(--<?= $outstanding > 0 ? 'rejected' : 'approved' ?>);">$<?= number_format(max(0,$outstanding), 2) ?></td>
                </tr>
                <?php endif; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>

        <!-- Past receipts -->
        <?php if (!empty($pay_rows)): ?>
        <div class="receipt-card">
          <div class="receipt-head">Payment Receipts</div>
          <table style="width:100%;border-collapse:collapse;">
            <thead><tr>
              <th style="padding:.6rem 1.25rem;text-align:left;font-size:10px;font-weight:600;letter-spacing:.07em;text-transform:uppercase;color:var(--muted);background:var(--surface2);border-bottom:1px solid var(--border);">Receipt #</th>
              <th style="padding:.6rem 1.25rem;text-align:left;font-size:10px;font-weight:600;letter-spacing:.07em;text-transform:uppercase;color:var(--muted);background:var(--surface2);border-bottom:1px solid var(--border);">Method</th>
              <th style="padding:.6rem 1.25rem;text-align:left;font-size:10px;font-weight:600;letter-spacing:.07em;text-transform:uppercase;color:var(--muted);background:var(--surface2);border-bottom:1px solid var(--border);">Date</th>
              <th style="padding:.6rem 1.25rem;text-align:right;font-size:10px;font-weight:600;letter-spacing:.07em;text-transform:uppercase;color:var(--muted);background:var(--surface2);border-bottom:1px solid var(--border);">Amount</th>
            </tr></thead>
            <tbody>
              <?php foreach ($pay_rows as $p): ?>
              <tr style="border-bottom:1px solid var(--border);">
                <td style="padding:.8rem 1.25rem;font-size:13.5px;color:var(--muted);">#<?= $p['TX_ID'] ?></td>
                <td style="padding:.8rem 1.25rem;font-size:13.5px;"><?= htmlspecialchars($p['Payment_Method']) ?></td>
                <td style="padding:.8rem 1.25rem;font-size:13px;color:var(--muted);"><?= date('d M Y', strtotime($p['Payment_Date'])) ?></td>
                <td style="padding:.8rem 1.25rem;font-size:13.5px;text-align:right;font-weight:600;color:var(--approved);">$<?= number_format($p['Payment_Amount'], 2) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>

      <!-- RIGHT: payment form -->
      <div class="pay-form-card">
        <h3>Make Payment</h3>
        <?php if ($outstanding > 0): ?>
          <div class="pay-total-display">
            <span>Outstanding balance</span>
            $<?= number_format($outstanding, 2) ?>
          </div>
          <form method="POST" action="?tab=payment">
            <input type="hidden" name="action" value="make_payment">
            <div style="font-size:11px;font-weight:500;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px;">Payment method</div>
            <div class="pay-method-grid">
              <div class="pay-method-opt">
                <input type="radio" name="payment_method" id="pm_cash" value="Cash" required>
                <label for="pm_cash" class="pay-method-label">
                  Cash
                  <span class="pay-method-sub">In person</span>
                </label>
              </div>
              <div class="pay-method-opt">
                <input type="radio" name="payment_method" id="pm_card" value="Card">
                <label for="pm_card" class="pay-method-label">
                  Card
                  <span class="pay-method-sub">Debit / Credit</span>
                </label>
              </div>
              <div class="pay-method-opt">
                <input type="radio" name="payment_method" id="pm_bkash" value="bKash">
                <label for="pm_bkash" class="pay-method-label">
                  bKash
                  <span class="pay-method-sub">Mobile</span>
                </label>
              </div>
            </div>
            <button type="submit" class="pay-confirm-btn"
                    onclick="return confirm('Confirm payment of $<?= number_format($outstanding, 2) ?>?')">
              Confirm Payment
            </button>
          </form>
        <?php else: ?>
          <div style="color:var(--approved);font-size:13.5px;line-height:1.6;padding:.5rem 0;">
            Your account is fully settled. No outstanding balance.
          </div>
        <?php endif; ?>
      </div>

    </div>

  <?php endif; ?>
</main>

<script>
/* ── Room Booking: floor filter ─────────────────────────────────── */
function filterFloor(floor, el) {
  document.querySelectorAll('.floor-pill').forEach(function(p) { p.classList.remove('active'); });
  el.classList.add('active');
  document.querySelectorAll('.floor-section[data-floor]').forEach(function(sec) {
    sec.style.display = (floor === 'all' || sec.dataset.floor == floor) ? '' : 'none';
  });
}
</script>
</body>
</html>