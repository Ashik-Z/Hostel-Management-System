<?php
include 'config/db_connection.php';

echo "<h1>🔍 Foreign Key Relationships Test</h1>";

if ($conn->connect_error) {
    die("❌ Connection Failed: " . $conn->connect_error);
}

// Expected relationships
$expected_fks = [
    'User' => 'Phone_ID -> Phone(ID)',
    'Client' => 'Student_ID -> User(ID)',
    'Visitors_log' => 'Client_id -> Client(ID)',
    'Room_Swap_Req' => 'Manager_ID -> Manager(ID)',
    'Meal_Booking' => 'Client_ID -> Client(ID)',
    'Stays_IN' => 'client_id -> Client(ID), (Floor_NUM, Room_Num) -> Room',
    'Booking_Allocation' => 'client_id -> Client(ID)',
    'Accountings' => 'Manager_ID -> Manager(ID)',
    'Payment_Record' => 'Student_ID -> User(ID), Client_ID -> Client(ID)',
    'Complaints' => 'Manager_ID -> Manager(ID), Client_ID -> Client(ID)'
];

echo "<table border='1' cellpadding='10'>";
echo "<tr><th>Table</th><th>Expected Foreign Keys</th></tr>";

foreach ($expected_fks as $table => $fks) {
    $query = "SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME 
              FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
              WHERE TABLE_NAME = '$table' AND REFERENCED_TABLE_NAME IS NOT NULL";
    
    $result = $conn->query($query);
    $found_fks = [];
    
    while ($row = $result->fetch_assoc()) {
        $found_fks[] = $row['COLUMN_NAME'] . " -> " . $row['REFERENCED_TABLE_NAME'] . "(" . $row['REFERENCED_COLUMN_NAME'] . ")";
    }
    
    if (count($found_fks) > 0) {
        echo "<tr><td>$table</td><td>✅ " . implode(", ", $found_fks) . "</td></tr>";
    } else {
        echo "<tr><td>$table</td><td>⚠️ Check manually</td></tr>";
    }
}

echo "</table>";

$conn->close();
?>