<?php
include 'config/db_connection.php';

echo "<h1>🔍 Data Insertion Test</h1>";

if ($conn->connect_error) {
    die("❌ Connection Failed: " . $conn->connect_error);
}

// Test 1: Insert Phone
echo "<h2>Test 1: Insert Phone</h2>";
$sql = "INSERT INTO Phone (Phone) VALUES ('+8801234567890')";
if ($conn->query($sql)) {
    echo "✅ Phone inserted successfully<br>";
    $phone_id = $conn->insert_id;
} else {
    echo "❌ Phone insert failed: " . $conn->error . "<br>";
    $phone_id = null;
}

// Test 2: Insert User
echo "<h2>Test 2: Insert User</h2>";
if ($phone_id) {
    $sql = "INSERT INTO User (Email, Reg_Date, Gender, D_birth, Phone_ID, F_name, M_name, L_name, Street, Area, Zip_code)
            VALUES ('ashik@example.com', '2026-04-22', 'Male', '2005-01-15', $phone_id, 'Ashik', 'Kumar', 'Zno', '123 Main St', 'Dhaka', '1205')";
    
    if ($conn->query($sql)) {
        echo "✅ User inserted successfully<br>";
        $user_id = $conn->insert_id;
    } else {
        echo "❌ User insert failed: " . $conn->error . "<br>";
        $user_id = null;
    }
}

// Test 3: Insert Client
echo "<h2>Test 3: Insert Client</h2>";
if ($user_id) {
    $sql = "INSERT INTO Client (Student_ID, Guardian_name, Guardian_Phone)
            VALUES ($user_id, 'Parent Name', '+8801987654321')";
    
    if ($conn->query($sql)) {
        echo "✅ Client inserted successfully<br>";
        $client_id = $conn->insert_id;
    } else {
        echo "❌ Client insert failed: " . $conn->error . "<br>";
        $client_id = null;
    }
}

// Test 4: Insert Manager
echo "<h2>Test 4: Insert Manager</h2>";
$sql = "INSERT INTO Manager (Salary, Hire_date) VALUES (50000, '2026-01-01')";
if ($conn->query($sql)) {
    echo "✅ Manager inserted successfully<br>";
    $manager_id = $conn->insert_id;
} else {
    echo "❌ Manager insert failed: " . $conn->error . "<br>";
}

// Test 5: Insert Complaint
echo "<h2>Test 5: Insert Complaint (with Foreign Keys)</h2>";
if ($client_id && $manager_id) {
    $sql = "INSERT INTO Complaints (Complaint_Date, Complaint_Text, Status, Manager_ID, Client_ID)
            VALUES ('2026-04-22', 'Room is dirty', 'Open', $manager_id, $client_id)";
    
    if ($conn->query($sql)) {
        echo "✅ Complaint inserted successfully<br>";
    } else {
        echo "❌ Complaint insert failed: " . $conn->error . "<br>";
    }
}

echo "<br><strong>All basic insertions completed!</strong>";

$conn->close();
?>