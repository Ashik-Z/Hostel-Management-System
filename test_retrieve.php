<?php
include 'config/db_connection.php';

echo "<h1>🔍 Data Retrieval Test</h1>";

if ($conn->connect_error) {
    die("❌ Connection Failed: " . $conn->connect_error);
}

// Query 1: Get Users with Phone
echo "<h2>Users with Phone Info:</h2>";
$sql = "SELECT u.ID, u.F_name, u.L_name, u.Email, p.Phone 
        FROM User u 
        LEFT JOIN Phone p ON u.Phone_ID = p.ID";

$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
    echo "<table border='1' cellpadding='10'>";
    echo "<tr><th>ID</th><th>Name</th><th>Email</th><th>Phone</th></tr>";
    
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . $row['ID'] . "</td>";
        echo "<td>" . $row['F_name'] . " " . $row['L_name'] . "</td>";
        echo "<td>" . $row['Email'] . "</td>";
        echo "<td>" . ($row['Phone'] ?? 'N/A') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "⚠️ No users found";
}

// Query 2: Get Complaints with Manager and Client
echo "<h2><br>Complaints with Details:</h2>";
$sql = "SELECT c.Complaint_ID, c.Complaint_Text, c.Status, m.ID as Manager_ID, cl.Guardian_name
        FROM Complaints c
        JOIN Manager m ON c.Manager_ID = m.ID
        JOIN Client cl ON c.Client_ID = cl.ID";

$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
    echo "<table border='1' cellpadding='10'>";
    echo "<tr><th>ID</th><th>Complaint</th><th>Status</th><th>Manager</th><th>Guardian</th></tr>";
    
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . $row['Complaint_ID'] . "</td>";
        echo "<td>" . $row['Complaint_Text'] . "</td>";
        echo "<td>" . $row['Status'] . "</td>";
        echo "<td>" . $row['Manager_ID'] . "</td>";
        echo "<td>" . $row['Guardian_name'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "⚠️ No complaints found";
}

$conn->close();
?>