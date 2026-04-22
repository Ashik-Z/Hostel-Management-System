<?php

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASSWORD', '');
define('DB_NAME', 'hostel_management');

$conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD);

if ($conn->connect_error) {
    die("Connection Failed: " . $conn->connect_error);
}

$sql_file = 'hostel_management_database.sql';

if (!file_exists($sql_file)) {
    die("Error: SQL file not found at: " . realpath($sql_file));
}

$sql = file_get_contents($sql_file);

if ($conn->multi_query($sql)) {
    echo "Database setup completed successfully!<br><br>";
    echo "Created tables:<br>";
    
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
    
    $result = $conn->query("SHOW TABLES");
    $count = 0;
    
    while ($row = $result->fetch_row()) {
        echo "✓ " . $row[0] . "<br>";
        $count++;
    }
    
    echo "<br><strong>Total Tables: " . $count . "</strong><br><br>";
    echo "<a href='test_connection.php'>Click here to test connection</a>";
} else {
    echo "Error executing SQL: <br>";
    echo $conn->error;
}

$conn->close();
?>