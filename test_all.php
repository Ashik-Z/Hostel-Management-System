<?php
include 'config/db_connection.php';

echo "<h1>✅ COMPLETE DATABASE TESTING SUITE</h1>";
echo "<hr>";

$tests_passed = 0;
$tests_failed = 0;

// Test 1: Connection
echo "<h2>Test 1: Database Connection</h2>";
if ($conn->connect_error) {
    echo "❌ FAILED: " . $conn->connect_error;
    $tests_failed++;
} else {
    echo "✅ PASSED: Connected to " . DB_NAME;
    $tests_passed++;
}

// Test 2: Tables
echo "<h2><br>Test 2: Table Existence</h2>";
$expected_tables = [
    'Phone', 'User', 'Client', 'Manager', 'Visitors_log',
    'Room', 'Meal_Booking', 'Room_Swap_Req', 'Stays_IN',
    'Booking_Allocation', 'Accountings', 'Payment_Record', 'Complaints'
];

$result = $conn->query("SHOW TABLES");
$actual_tables = [];
while ($row = $result->fetch_row()) {
    $actual_tables[] = $row[0];
}

if (count($actual_tables) === count($expected_tables)) {
    echo "✅ PASSED: All " . count($expected_tables) . " tables exist";
    $tests_passed++;
} else {
    echo "❌ FAILED: Expected " . count($expected_tables) . " tables, found " . count($actual_tables);
    $tests_failed++;
}

// Test 3: Insert Test
echo "<h2><br>Test 3: Data Insertion</h2>";
$sql = "INSERT INTO Phone (Phone) VALUES ('+8801111111111')";
if ($conn->query($sql)) {
    echo "✅ PASSED: Can insert data";
    $tests_passed++;
} else {
    echo "❌ FAILED: " . $conn->error;
    $tests_failed++;
}

// Test 4: Retrieve Test
echo "<h2><br>Test 4: Data Retrieval</h2>";
$result = $conn->query("SELECT * FROM Phone LIMIT 1");
if ($result && $result->num_rows > 0) {
    echo "✅ PASSED: Can retrieve data";
    $tests_passed++;
} else {
    echo "❌ FAILED: Cannot retrieve data";
    $tests_failed++;
}

// Summary
echo "<h2><br>📊 TEST SUMMARY</h2>";
echo "<strong>Passed: " . $tests_passed . "</strong><br>";
echo "<strong>Failed: " . $tests_failed . "</strong><br>";

if ($tests_failed === 0) {
    echo "<br><h3>✅ ALL TESTS PASSED! Database is ready for development.</h3>";
} else {
    echo "<br><h3>❌ Some tests failed. Please fix the issues above.</h3>";
}

$conn->close();
?>