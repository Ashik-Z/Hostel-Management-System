<?php
include 'config/db_connection.php';

echo "<h1>🔍 Database Tables & Structure Test</h1>";

if ($conn->connect_error) {
    die("❌ Connection Failed: " . $conn->connect_error);
}

echo "<h2>All Tables in Database:</h2>";
$result = $conn->query("SHOW TABLES");

if (!$result) {
    die("❌ Error: " . $conn->error);
}

$tables = [];
while ($row = $result->fetch_row()) {
    $tables[] = $row[0];
}

// Expected 13 tables
$expected_tables = [
    'Phone', 'User', 'Client', 'Manager', 'Visitors_log',
    'Room', 'Meal_Booking', 'Room_Swap_Req', 'Stays_IN',
    'Booking_Allocation', 'Accountings', 'Payment_Record', 'Complaints'
];

echo "<table border='1' cellpadding='10'>";
echo "<tr><th>Expected Table</th><th>Status</th></tr>";

$missing_tables = [];
foreach ($expected_tables as $table) {
    if (in_array($table, $tables)) {
        echo "<tr><td>$table</td><td>✅ Found</td></tr>";
    } else {
        echo "<tr><td>$table</td><td>❌ Missing</td></tr>";
        $missing_tables[] = $table;
    }
}
echo "</table>";

echo "<br><strong>Total Tables: " . count($tables) . " / " . count($expected_tables) . "</strong><br>";

if (count($missing_tables) > 0) {
    echo "<br>Missing Tables: " . implode(", ", $missing_tables);
} else {
    echo "<br>All tables created successfully!";
}

// Show table structure
echo "<h2>Table Structures:</h2>";
foreach ($expected_tables as $table) {
    if (in_array($table, $tables)) {
        echo "<h3>$table Table:</h3>";
        $columns = $conn->query("DESCRIBE $table");
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>Column</th><th>Type</th><th>Null</th><th>Key</th></tr>";
        while ($col = $columns->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . $col['Field'] . "</td>";
            echo "<td>" . $col['Type'] . "</td>";
            echo "<td>" . $col['Null'] . "</td>";
            echo "<td>" . $col['Key'] . "</td>";
            echo "</tr>";
        }
        echo "</table><br>";
    }
}

$conn->close();
?>