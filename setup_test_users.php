<?php
include 'config/db_connection.php';

echo "<h1>Create Test Users</h1>";

// ─── Test Client User ───────────────────────────────────────────
$user_id = 1234567890;
$email = 'testclient@example.com'; // ✅ FIXED: must be assigned BEFORE bind_param
$password = password_hash('password123', PASSWORD_BCRYPT);
$f_name = 'Test';
$l_name = 'Client';
$reg_date = date('Y-m-d');

$sql = "INSERT IGNORE INTO User (ID, Email, Password, F_name, L_name, Reg_Date) 
        VALUES (?, ?, ?, ?, ?, ?)";

$stmt = $conn->prepare($sql);
$stmt->bind_param("isssss", $user_id, $email, $password, $f_name, $l_name, $reg_date);

if ($stmt->execute()) {
    echo "✅ Test user created (ID: 1234567890)<br>";

    $sql2 = "INSERT IGNORE INTO Client (Student_ID, Guardian_name, Guardian_Phone, Status) 
             VALUES (?, 'Parent', '+8801234567890', 'Approved')";
    $stmt2 = $conn->prepare($sql2);
    $stmt2->bind_param("i", $user_id);

    if ($stmt2->execute()) {
        echo "✅ Client profile created<br>";
    } else {
        echo "❌ Error creating client profile: " . $conn->error . "<br>";
    }
} else {
    echo "❌ Error creating test user: " . $conn->error . "<br>";
}

// ─── Test Manager User ──────────────────────────────────────────
$manager_user_id = 1000000001;
$manager_email = 'testmanager@example.com'; // ✅ FIXED: managers need a User record too
$manager_password = password_hash('manager123', PASSWORD_BCRYPT);
$manager_reg_date = date('Y-m-d');

// ✅ FIXED: Create User record first, then Manager record linked via User_ID
$sql3 = "INSERT IGNORE INTO User (ID, Email, Password, F_name, L_name, Reg_Date)
         VALUES (?, ?, ?, 'Test', 'Manager', ?)";
$stmt3 = $conn->prepare($sql3);
$stmt3->bind_param("isss", $manager_user_id, $manager_email, $manager_password, $manager_reg_date);

if ($stmt3->execute()) {
    echo "✅ Manager user account created<br>";

    // ✅ FIXED: Insert into Manager using User_ID, not a separate ID + Password
    $sql4 = "INSERT IGNORE INTO Manager (User_ID, Salary, Hire_date)
             VALUES (?, 50000, '2026-01-01')";
    $stmt4 = $conn->prepare($sql4);
    $stmt4->bind_param("i", $manager_user_id);

    if ($stmt4->execute()) {
        echo "✅ Manager profile created (Manager ID: " . $conn->insert_id . ")<br>";
    } else {
        echo "❌ Error creating manager profile: " . $conn->error . "<br>";
    }
} else {
    echo "❌ Error creating manager user account: " . $conn->error . "<br>";
}

echo "<br><strong>Test Credentials:</strong><br>";
echo "Client ID: 1234567890<br>";
echo "Client Password: password123<br><br>";
echo "Manager User ID: 1000000001<br>";
echo "Manager Password: manager123<br>";

$conn->close();
?>