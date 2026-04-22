<?php
include 'config/db_connection.php';

if ($conn->connect_error) {
    echo "Connection Failed: " . $conn->connect_error;
} else {
    echo "Connected to database successfully!<br><br>";
    echo "Database: " . DB_NAME . "<br>";
    echo "Host: " . DB_HOST . "<br><br>";
    
    echo "<strong>Tables Created:</strong><br>";
    $result = $conn->query("SHOW TABLES");
    
    if ($result) {
        while ($row = $result->fetch_row()) {
            echo "✓ " . $row[0] . "<br>";
        }
    }
}
$conn->close();
?>