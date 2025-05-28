<?php
// htdocs/DSS/login.php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/db_connect.php'; // $conn
require_once __DIR__ . '/includes/functions.php'; // is_logged_in, get_current_user_role, redirect, sanitize_input, set_flash_message, display_all_flash_messages

// --- reCAPTCHA Configuration ---
define('RECAPTCHA_SITE_KEY', '6LcrlEUrAAAAAB7MIWwZuHcxGuwk7Wo3WLuD0gNw'); // Thay bằng Site Key của bạn
define('RECAPTCHA_SECRET_KEY', '6LcrlEUrAAAAAEaDm6SswQygwRqc-cANGOKst9Um'); // Thay bằng Secret Key của bạn

// Nếu đã đăng nhập, chuyển hướng tới dashboard tương ứng
if (is_logged_in()) {
    $role = get_current_user_role();
    $role_dir = $role ? htmlspecialchars($role) : '';
    if (in_array($role, ['admin', 'instructor', 'student'])) {
        redirect($role_dir . '/index.php');
    } else {
        // Trường hợp vai trò không xác định hoặc người dùng không có vai trò,
        // có thể chuyển về trang login hoặc một trang lỗi/thông báo chung.
        // Để an toàn, nếu không rõ vai trò, có thể logout và về trang login.
        // Hoặc nếu có trang index.php ở thư mục gốc:
        // redirect('index.php'); 
        session_destroy(); // Hủy session nếu vai trò không hợp lệ
        redirect('login.php'); 
    }
    exit();
}

$login_error = '';

/**
 * Function to verify reCAPTCHA response
 * @return bool True if verified, False otherwise
 */
function verify_recaptcha_login() { // Đổi tên hàm để tránh xung đột nếu functions.php có hàm tương tự
    if (isset($_POST['g-recaptcha-response']) && !empty($_POST['g-recaptcha-response'])) {
        $recaptcha_response = $_POST['g-recaptcha-response'];
        $verify_url = 'https://www.google.com/recaptcha/api/siteverify';
        $data = [
            'secret'   => RECAPTCHA_SECRET_KEY,
            'response' => $recaptcha_response,
            'remoteip' => $_SERVER['REMOTE_ADDR']
        ];

        $options = ['http' => ['header'  => "Content-type: application/x-www-form-urlencoded\r\n", 'method'  => 'POST', 'content' => http_build_query($data), 'timeout' => 5]];
        $context  = stream_context_create($options);
        $result_json = @file_get_contents($verify_url, false, $context);
        if($result_json === FALSE) return false; // Lỗi kết nối
        
        $response_data = json_decode($result_json, true);
        return isset($response_data['success']) && $response_data['success'] === true;
    }
    return false;
}

// Xử lý Login
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['login'])) {
    if (!verify_recaptcha_login()) {
        $login_error = "Xác minh reCAPTCHA không thành công. Vui lòng thử lại.";
    } else {
        $username_or_email = sanitize_input($_POST['username_or_email']);
        $password = $_POST['password']; // Không sanitize mật khẩu ở đây, chỉ khi hiển thị

        if (empty($username_or_email) || empty($password)) {
            $login_error = "Vui lòng nhập tên đăng nhập/email và mật khẩu.";
        } else {
            $sql = "SELECT UserID, Username, PasswordHash, Role, FullName, Email, LinkedEntityID FROM Users WHERE Username = ? OR Email = ?";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param("ss", $username_or_email, $username_or_email);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows == 1) {
                    $user = $result->fetch_assoc();
                    if (password_verify($password, $user['PasswordHash'])) {
                        // Đăng nhập thành công, thiết lập session
                        $_SESSION['user_id'] = $user['UserID'];
                        $_SESSION['username'] = $user['Username'];
                        $_SESSION['fullname'] = $user['FullName'];
                        $_SESSION['role'] = $user['Role'];
                        $_SESSION['email'] = $user['Email'];
                        $_SESSION['linked_entity_id'] = $user['LinkedEntityID'];

                        // Chuyển hướng dựa trên vai trò
                        $role_dir = $user['Role'] ? htmlspecialchars($user['Role']) : '';
                         if (in_array($user['Role'], ['admin', 'instructor', 'student'])) {
                            // Kiểm tra xem có URL redirect từ trước không (ví dụ: bị require_role đẩy về)
                            if(isset($_SESSION['redirect_url'])){
                                $redirect_to = $_SESSION['redirect_url'];
                                unset($_SESSION['redirect_url']);
                                header("Location: " . $redirect_to); // Redirect tuyệt đối
                                exit();
                            } else {
                                redirect($role_dir . '/index.php');
                            }
                        } else { 
                            $login_error = "Vai trò người dùng không được hỗ trợ."; 
                            // Không redirect ngay, để hiển thị lỗi
                        }
                    } else {
                        $login_error = "Tên đăng nhập/email hoặc mật khẩu không đúng.";
                    }
                } else {
                    $login_error = "Tên đăng nhập/email hoặc mật khẩu không đúng.";
                }
                $stmt->close();
            } else {
                $login_error = "Lỗi hệ thống. Vui lòng thử lại sau. (Lỗi CSDL)";
                error_log("Login Prepare Error: " . $conn->error); // Ghi log lỗi CSDL
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng nhập - Cổng DSS Đại Học</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    <style>
        /* CSS giống như trang register.php bạn đã cung cấp (phần branding và form card) */
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; }
        body.login-page-bsb { background-color: #0d6efd; min-height: 100vh; display: flex; overflow-x: hidden; } /* Thêm overflow-x: hidden */
        .login-bsb-wrapper { width: 100%; display: flex; }
        .login-branding-bsb { background-color: #0d6efd; color: #ffffff; padding: 4rem 3rem; display: flex; flex-direction: column; justify-content: center; }
        .login-branding-bsb .logo-bsb { display: flex; align-items: center; margin-bottom: 2rem; }
        .login-branding-bsb .logo-bsb img { height: 40px; margin-right: 0.75rem; }
        .login-branding-bsb .logo-bsb .logo-text-bsb { font-size: 1.75rem; font-weight: 700; letter-spacing: 0.5px; }
        .login-branding-bsb .logo-bsb i { font-size: 2.5rem; margin-right: 0.75rem; } /* Giữ icon cho logo */
        .login-branding-bsb .title-bsb { font-size: 2.75rem; font-weight: 700; line-height: 1.3; margin-bottom: 1.5rem; }
        .login-branding-bsb .description-bsb { font-size: 1.1rem; line-height: 1.7; margin-bottom: 2.5rem; max-width: 480px; opacity: 0.9; }
        .login-branding-bsb .dots-bsb { font-size: 2rem; letter-spacing: 0.6rem; opacity: 0.6; }
        .login-form-area-bsb { background-color: #ffffff; display: flex; align-items: center; justify-content: center; padding: 2rem; }
        .login-form-card-bsb { width: 100%; max-width: 420px; padding: 2.5rem; } /* Giảm max-width một chút cho form login */
        .login-form-card-bsb .form-title-bsb { font-size: 2rem; font-weight: 700; color: #212529; margin-bottom: 0.75rem; }
        .login-form-card-bsb .form-subtitle-bsb { font-size: 0.95rem; color: #6c757d; margin-bottom: 1.5rem; }
        .login-form-card-bsb .form-subtitle-bsb a { color: #0d6efd; text-decoration: none; font-weight: 500; }
        .login-form-card-bsb .form-subtitle-bsb a:hover { text-decoration: underline; }
        .login-form-card-bsb .form-label { font-weight: 500; font-size: 0.9rem; margin-bottom: 0.3rem; color: #495057; }
        .login-form-card-bsb .form-control { border-radius: 0.375rem; padding: 0.85rem 1rem; border: 1px solid #ced4da; font-size: 0.95rem; background-color: #f8f9fa; }
        .login-form-card-bsb .form-control:focus { border-color: #86b7fe; box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25); background-color: #fff; }
        .login-form-card-bsb .btn-login-submit-bsb { background-color: #0d6efd; border-color: #0d6efd; color: white; padding: 0.85rem 1.5rem; font-size: 1rem; font-weight: 500; border-radius: 0.375rem; width: 100%; transition: background-color 0.15s ease-in-out, border-color 0.15s ease-in-out; }
        .login-form-card-bsb .btn-login-submit-bsb:hover { background-color: #0b5ed7; border-color: #0a58ca; }
        .recaptcha-container { display: flex; justify-content: center; margin-bottom: 1rem; }
        .extra-links { text-align: center; margin-top: 1rem; font-size: 0.9rem; }
        .extra-links a { color: #007bff; text-decoration: none; }
        .extra-links a:hover { text-decoration: underline; }
        .alert { border-radius: .375rem; }

        @media (max-width: 991.98px) { 
            .login-branding-bsb { display: none !important; } 
            .login-form-area-bsb { background-color: #0d6efd; /* Giữ nền xanh cho toàn màn hình */ } 
            .login-form-card-bsb { background-color: #ffffff; border-radius: 0.75rem; box-shadow: 0 0.5rem 1.5rem rgba(0,0,0,0.15); margin: auto; /* Căn giữa form trên mobile */ } 
        }
        @media (max-width: 575.98px) { 
            .login-form-area-bsb { padding: 1rem; } 
            .login-form-card-bsb { padding: 1.5rem; } 
        }
    </style>
</head>
<body class="login-page-bsb">

    <div class="container-fluid login-bsb-wrapper p-0">
        <div class="row g-0 h-100">
            
            <div class="col-lg-7 d-none d-lg-flex login-branding-bsb">
                <div>
                    <div class="logo-bsb">
                        <i class="fas fa-university fa-2x"></i> 
                        <span class="logo-text-bsb">UniDSS</span>
                    </div>
                    <h1 class="title-bsb">Chào mừng đến với Cổng DSS</h1>
                    <p class="description-bsb">
                        Hệ thống Hỗ trợ Quyết định thông minh cho việc Xếp lịch Khóa học Đại học. Đăng nhập để tiếp tục.
                    </p>
                    <div class="dots-bsb">...... ......</div>
                </div>
            </div>

            <div class="col-lg-5 col-md-12 login-form-area-bsb">
                <div class="login-form-card-bsb">
                    <h2 class="form-title-bsb">Đăng Nhập</h2>
                    <p class="form-subtitle-bsb">
                        Vui lòng nhập thông tin tài khoản của bạn.
                    </p>

                    <?php if (!empty($login_error)): ?>
                        <div class="alert alert-danger py-2" role="alert">
                            <?php echo htmlspecialchars($login_error); ?>
                        </div>
                    <?php endif; ?>
                    <?php echo display_all_flash_messages(); // Hiển thị flash từ register.php sau khi redirect ?>


                    <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                        <div class="form-floating mb-3">
                            <input type="text" class="form-control" id="username_or_email" name="username_or_email" placeholder="Tên đăng nhập hoặc Email" required value="<?php echo isset($_POST['username_or_email']) ? htmlspecialchars($_POST['username_or_email']) : ''; ?>">
                            <label for="username_or_email">Tên đăng nhập hoặc Email</label>
                        </div>
                        <div class="form-floating mb-3">
                            <input type="password" class="form-control" id="password" name="password" placeholder="Mật khẩu" required>
                            <label for="password">Mật khẩu</label>
                        </div>
                        
                        <div class="recaptcha-container">
                            <div class="g-recaptcha" data-sitekey="<?php echo RECAPTCHA_SITE_KEY; ?>"></div>
                        </div>

                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="remember_me" name="remember_me">
                                <label class="form-check-label" for="remember_me">Ghi nhớ tôi</label>
                            </div>
                            <a href="#" class="extra-links">Quên mật khẩu?</a>
                        </div>
                        <button type="submit" name="login" class="btn btn-login-submit-bsb">Đăng nhập</button>
                    </form>
                    
                    <div class="extra-links mt-3">
                        Chưa có tài khoản? <a href="<?php echo htmlspecialchars(BASE_URL . 'register.php'); ?>">Đăng ký tại đây</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>