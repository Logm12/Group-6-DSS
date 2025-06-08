<?php

// --- Database Configuration ---
define('DB_SERVER', 'localhost');
define('DB_USERNAME', 'root');
define('DB_PASSWORD', ''); // Replace with your database password
define('DB_NAME', 'dss');

// --- Attempt to Connect to MySQL Database ---
$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// --- Check Connection ---
if ($conn->connect_error) {
    die("ERROR: Could not connect. " . $conn->connect_error);
}

if (!$conn->set_charset("utf8mb4")) {
    error_log("Error loading character set utf8mb4: " . $conn->error);
}

date_default_timezone_set('Asia/Ho_Chi_Minh'); 
$conn->query("SET time_zone = '+07:00'");    

?>