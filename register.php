<?php
session_start();
require_once('config/db_connection.php');

$message = "";

if (isset($_POST['role']) && isset($_POST['userid']) && isset($_POST['password'])) {

    $role = trim($_POST['role']);
    $login_id = trim($_POST['userid']);
    $password = trim($_POST['password']);

    if ($role == "" || $login_id == "" || $password == "") {
        $message = "Please select role and enter ID and password.";
    } elseif (!preg_match('/^[0-9]{11}$/', $login_id)) {
        $message = "ID must be exactly 11 digits.";
    } elseif (strlen($password) < 4 || strlen($password) > 255) {
        $message = "Password must be between 4 and 255 characters.";
    } else {

        $real_id = intval($login_id);

        if ($real_id <= 0) {
            $message = "Invalid ID.";
        } elseif ($real_id > 2147483647) {
            $message = "ID is too large for your database. Use ID like 00000000001.";
        } else {

            $email = trim($_POST['email'] ?? "");
            $phone = trim($_POST['phone'] ?? "");
            $gender = trim($_POST['gender'] ?? "");
            $dob = trim($_POST['dob'] ?? "");

            $fname = trim($_POST['fname'] ?? "");
            $mname = trim($_POST['mname'] ?? "");
            $lname = trim($_POST['lname'] ?? "");

            $street = trim($_POST['street'] ?? "");
            $area = trim($_POST['area'] ?? "");
            $zip = trim($_POST['zip'] ?? "");

            if ($email == "" || $fname == "") {
                $message = "Email and first name are required.";
            } else {

                if ($dob == "") {
                    $dob = null;
                }

                mysqli_begin_transaction($conn);

                try {
                    $check_user_sql = "SELECT ID FROM `User` WHERE ID = ? OR Email = ? LIMIT 1";
                    $check_user_stmt = mysqli_prepare($conn, $check_user_sql);
                    mysqli_stmt_bind_param($check_user_stmt, "is", $real_id, $email);
                    mysqli_stmt_execute($check_user_stmt);
                    $check_user_result = mysqli_stmt_get_result($check_user_stmt);

                    if ($check_user_result && mysqli_num_rows($check_user_result) > 0) {
                        throw new Exception("This ID or email already exists.");
                    }

                    $phone_id = null;

                    if ($phone != "") {
                        $phone_check_sql = "SELECT ID FROM Phone WHERE Phone = ? LIMIT 1";
                        $phone_check_stmt = mysqli_prepare($conn, $phone_check_sql);
                        mysqli_stmt_bind_param($phone_check_stmt, "s", $phone);
                        mysqli_stmt_execute($phone_check_stmt);
                        $phone_check_result = mysqli_stmt_get_result($phone_check_stmt);

                        if ($phone_check_result && mysqli_num_rows($phone_check_result) > 0) {
                            $phone_row = mysqli_fetch_assoc($phone_check_result);
                            $phone_id = $phone_row['ID'];
                        } else {
                            $phone_sql = "INSERT INTO Phone (Phone) VALUES (?)";
                            $phone_stmt = mysqli_prepare($conn, $phone_sql);
                            mysqli_stmt_bind_param($phone_stmt, "s", $phone);

                            if (!mysqli_stmt_execute($phone_stmt)) {
                                throw new Exception(mysqli_error($conn));
                            }

                            $phone_id = mysqli_insert_id($conn);
                        }
                    }

                    $user_sql = "INSERT INTO `User`
                        (ID, Email, Password, Reg_Date, Gender, D_birth, Phone_ID, F_name, M_name, L_name, Street, Area, Zip_code)
                        VALUES (?, ?, ?, CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, ?)";

                    $user_stmt = mysqli_prepare($conn, $user_sql);

                    mysqli_stmt_bind_param(
                        $user_stmt,
                        "issssissssss",
                        $real_id,
                        $email,
                        $password,
                        $gender,
                        $dob,
                        $phone_id,
                        $fname,
                        $mname,
                        $lname,
                        $street,
                        $area,
                        $zip
                    );

                    if (!mysqli_stmt_execute($user_stmt)) {
                        throw new Exception(mysqli_error($conn));
                    }

                    if ($role == "manager") {

                        $salary = trim($_POST['salary'] ?? "");
                        $hire_date = trim($_POST['hire_date'] ?? "");

                        if ($salary == "") {
                            $salary = 0;
                        }

                        if ($hire_date == "") {
                            $hire_date = date("Y-m-d");
                        }

                        $manager_sql = "INSERT INTO Manager
                            (User_ID, Salary, Hire_date)
                            VALUES (?, ?, ?)";

                        $manager_stmt = mysqli_prepare($conn, $manager_sql);
                        mysqli_stmt_bind_param($manager_stmt, "ids", $real_id, $salary, $hire_date);

                        if (!mysqli_stmt_execute($manager_stmt)) {
                            throw new Exception(mysqli_error($conn));
                        }

                        mysqli_commit($conn);

                        $_SESSION['role'] = "manager";
                        $_SESSION['user_id'] = $real_id;
                        $_SESSION['manager_id'] = mysqli_insert_id($conn);
                        $_SESSION['name'] = $fname . " " . $lname;

                        header("Location: manager_dashboard.php");
                        exit();

                    } elseif ($role == "client") {

                        $guardian_name = trim($_POST['guardian_name'] ?? "");
                        $guardian_phone = trim($_POST['guardian_phone'] ?? "");

                        if ($guardian_name == "") {
                            throw new Exception("Guardian name is required for client registration.");
                        }

                        $client_sql = "INSERT INTO Client
                            (Student_ID, Guardian_name, Guardian_Phone, Status)
                            VALUES (?, ?, ?, 'Pending')";

                        $client_stmt = mysqli_prepare($conn, $client_sql);
                        mysqli_stmt_bind_param($client_stmt, "iss", $real_id, $guardian_name, $guardian_phone);

                        if (!mysqli_stmt_execute($client_stmt)) {
                            throw new Exception(mysqli_error($conn));
                        }

                        mysqli_commit($conn);

                        $message = "Client registration successful. Please wait for manager approval before logging in.";

                    } else {
                        throw new Exception("Invalid role selected.");
                    }

                } catch (Exception $e) {
                    mysqli_rollback($conn);
                    $message = "Registration failed: " . $e->getMessage();
                }
            }
        }
    }
}
?>

