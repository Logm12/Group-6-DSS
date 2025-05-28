<?php
// htdocs/DSS/logout.php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/includes/functions.php'; // Để dùng hàm redirect

// Hủy tất cả các biến session
$_SESSION = array();

// Nếu muốn hủy session hoàn toàn, hãy xóa cả cookie session.
// Lưu ý: Điều này sẽ phá hủy session, và không chỉ dữ liệu session!
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Cuối cùng, hủy session.
session_destroy();

// Chuyển hướng về trang đăng nhập
redirect('login.php'); // Giả sử login.php ở thư mục gốc DSS
exit;
?>