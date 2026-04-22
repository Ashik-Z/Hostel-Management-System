<?php
include '../config/db_connection.php';

if ($conn->connect_error) {
    die("Connection Failed: " . $conn->connect_error);
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Hostel Management System - Dashboard</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .container { max-width: 1200px; margin: 0 auto; }
        h1 { color: #333; }
        .success { color: green; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Hostel Management System</h1>
        <p class="success">Database connected successfully!</p>
        
        <h2>Database Information</h2>
        <p><strong>Database:</strong> <?php echo DB_NAME; ?></p>
        <p><strong>Host:</strong> <?php echo DB_HOST; ?></p>
        
        <h2>Tables</h2>
        <ul>
            <?php
            $result = $conn->query("SHOW TABLES");
            while ($row = $result->fetch_row()) {
                echo "<li>" . $row[0] . "</li>";
            }
            ?>
        </ul>

        <hr>
        <p><a href="../test_connection.php">Test Connection Details</a></p>
    </div>
</body>
</html>