<?php

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASSWORD', '');
define('DB_NAME', 'hostel_management');

$conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);

if ($conn->connect_error) {
    header("Location: setup.php");
    exit;
}

header("Location: pages/dashboard.php");
exit;
?>