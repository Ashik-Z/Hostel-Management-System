<?php
session_start();
require_once('config/db_connection.php');

if (isset($_POST['role']) && isset($_POST['userid']) && isset($_POST['password'])) {

    $role = $_POST['role'];
    $login_id = trim($_POST['userid']);
    $password = trim($_POST['password']);

    if ($role == "" || $login_id == "" || $password == "") {
        echo "Please select role and enter ID and password.";
        exit();
    }

    if (!preg_match('/^[0-9]{11}$/', $login_id)) {
        echo "ID must be exactly 11 digits.";
        exit();
    }

    if (strlen($password) < 4 || strlen($password) > 255) {
        echo "Password must be between 4 and 255 characters.";
        exit();
    }

    
    $real_id = intval($login_id);

    if ($real_id <= 0) {
        echo "Invalid ID.";
        exit();
    }

    if ($role == "client") {

        $sql = "SELECT 
                    u.ID AS User_ID,
                    u.F_name,
                    u.L_name,
                    u.Password,
                    c.ID AS Client_ID,
                    c.Status
                FROM `User` u
                INNER JOIN Client c ON c.Student_ID = u.ID
                WHERE u.ID = ?
                LIMIT 1";

        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "i", $real_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);

        if (mysqli_num_rows($result) > 0) {

            $row = mysqli_fetch_assoc($result);

            // if ($password != $row['Password'])
            if (!password_verify($password, $row['Password'])) {
                echo "Wrong client ID or password.";
                exit();
            }

            if ($row['Status'] != "Approved") {
                echo "Your account is not approved yet. Please wait for manager approval.";
                exit();
            }

            $_SESSION['role'] = "client";
            $_SESSION['user_id'] = $row['User_ID'];
            $_SESSION['client_id'] = $row['Client_ID'];
            $_SESSION['name'] = $row['F_name'] . " " . $row['L_name'];

            header("Location: client_dashboard.php");
            exit();

        } else {
            echo "Client account not found. Please register first.";
            exit();
        }

    } elseif ($role == "manager") {

        $sql = "SELECT
                m.ID AS Manager_ID,
                u.Password,
                u.F_name,
                u.L_name
            FROM Manager m
            INNER JOIN User u ON u.ID = m.User_ID
            WHERE u.ID = ?
            LIMIT 1";

        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "i", $real_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);

        if ($result && mysqli_num_rows($result) > 0) {

            $row = mysqli_fetch_assoc($result);

            // if ($password != $row['Password'])
            if (!password_verify($password, $row['Password'])) {
                echo "Wrong manager ID or password.";
                exit();
            }

            $_SESSION['role'] = "manager";
            $_SESSION['manager_id'] = $row['Manager_ID'];
            $_SESSION['user_id'] = $row['User_ID'];
            $_SESSION['name'] = $row['F_name'] . " " . $row['L_name'];

            header("Location: manager_dashboard.php");
            exit();

        } else {
            echo "Manager account not found. Please register first.";
            exit();
        }

    } else {
        echo "Invalid role selected.";
        exit();

    }
}

?>
