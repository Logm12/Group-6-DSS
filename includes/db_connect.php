<?php
// htdocs/university_dss/includes/db_connect.php

// --- Database Configuration ---
define('DB_SERVER', 'localhost');       // Hoặc IP của DB server nếu khác
define('DB_USERNAME', 'root');          // Thay thế bằng username CSDL của bạn
define('DB_PASSWORD', '');              // Thay thế bằng password CSDL của bạn
define('DB_NAME', 'dss');               // Tên database đã được cung cấp

// --- Attempt to Connect to MySQL Database ---
$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// --- Check Connection ---
if ($conn->connect_error) {
    // Nếu có lỗi kết nối, hiển thị thông báo lỗi và dừng script
    // Trong môi trường production, bạn có thể muốn log lỗi này thay vì hiển thị trực tiếp
    die("ERROR: Could not connect. " . $conn->connect_error);
}

// --- Set Character Set to UTF-8 ---
// Quan trọng để đảm bảo dữ liệu tiếng Việt hiển thị và lưu trữ đúng cách
if (!$conn->set_charset("utf8mb4")) {
    // Trong môi trường production, bạn có thể muốn log lỗi nà
     printf("Error loading character set utf8mb4: %s\n", $conn->error);
}

// --- Optional: Set Timezone for the Database Session (if needed) ---
// date_default_timezone_set('Asia/Ho_Chi_Minh'); // Đặt múi giờ cho PHP
// $conn->query("SET time_zone = '+07:00'");    // Đặt múi giờ cho session MySQL

/*
echo "Successfully connected to the database: " . DB_NAME; // Dòng này để test, xóa hoặc comment lại sau khi test
*/

// Biến $conn bây giờ đã sẵn sàng để sử dụng trong các file khác sau khi include file này.
?>