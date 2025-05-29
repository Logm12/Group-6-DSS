<?php
// htdocs/DSS/login.php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/functions.php';

// Bỏ reCAPTCHA
// define('RECAPTCHA_SITE_KEY', 'YOUR_SITE_KEY_HERE'); 
// define('RECAPTCHA_SECRET_KEY', 'YOUR_SECRET_KEY_HERE'); 

if (is_logged_in()) {
    $role = get_current_user_role();
    $role_dir = $role ? htmlspecialchars($role) : '';
    if (in_array($role, ['admin', 'instructor', 'student'])) {
        redirect($role_dir . '/index.php');
    } else {
        session_destroy(); 
        redirect('login.php'); 
    }
    exit();
}

$login_error = '';

// Bỏ hàm verify reCAPTCHA

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['login'])) {
    // Bỏ kiểm tra reCAPTCHA
    $username_or_email_input = isset($_POST['username_or_email']) ? trim($_POST['username_or_email']) : '';
    $password_input = isset($_POST['password']) ? $_POST['password'] : ''; 

    if ($username_or_email_input === '' || $password_input === '') {
        $login_error = "Please enter your username/email and password.";
    } else {
        $username_or_email_sanitized = sanitize_input($username_or_email_input);
        $sql = "SELECT UserID, Username, PasswordHash, Role, FullName, Email, LinkedEntityID FROM Users WHERE Username = ? OR Email = ?";
        $stmt = $conn->prepare($sql);
        
        if ($stmt) {
            $stmt->bind_param("ss", $username_or_email_sanitized, $username_or_email_sanitized);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows == 1) {
                $user = $result->fetch_assoc();
                if (password_verify($password_input, $user['PasswordHash'])) {
                    $_SESSION['user_id'] = $user['UserID'];
                    $_SESSION['username'] = $user['Username'];
                    $_SESSION['fullname'] = $user['FullName'];
                    $_SESSION['role'] = $user['Role'];
                    $_SESSION['email'] = $user['Email'];
                    $_SESSION['linked_entity_id'] = $user['LinkedEntityID'];

                    $role_dir = $user['Role'] ? htmlspecialchars($user['Role']) : '';
                     if (in_array($user['Role'], ['admin', 'instructor', 'student'])) {
                        if(isset($_SESSION['redirect_url'])){
                            $redirect_to = $_SESSION['redirect_url'];
                            unset($_SESSION['redirect_url']);
                            if (strpos($redirect_to, 'http') !== 0 && defined('BASE_URL')) {
                                if (substr(BASE_URL, -1) === '/' && substr($redirect_to, 0, 1) === '/') {
                                    $redirect_to_final = BASE_URL . ltrim($redirect_to, '/');
                                } else if (substr(BASE_URL, -1) !== '/' && substr($redirect_to, 0, 1) !== '/'){
                                    $redirect_to_final = BASE_URL . '/' . $redirect_to;
                                } else {
                                    $redirect_to_final = BASE_URL . $redirect_to;
                                }
                                header("Location: " . $redirect_to_final);
                            } else {
                                header("Location: " . $redirect_to); 
                            }
                            exit();
                        } else {
                            redirect($role_dir . '/index.php'); 
                        }
                    } else { 
                        $login_error = "User role is not supported."; 
                    }
                } else {
                    $login_error = "Invalid username/email or password. (PV failed)";
                }
            } else {
                $login_error = "Invalid username/email or password. (No user)";
            }
            $stmt->close();
        } else {
            $login_error = "System error. Please try again later. (DB Prepare Error)";
            error_log("Login Prepare Error: " . $conn->error); 
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - University DSS Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <!-- Bỏ script reCAPTCHA -->
    <style>
        /* CSS giữ nguyên */
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; }
        body.login-page-bsb { background-color: #0d6efd; min-height: 100vh; display: flex; overflow-x: hidden; }
        .login-bsb-wrapper { width: 100%; display: flex; }
        .login-branding-bsb { background-color: #0d6efd; color: #ffffff; padding: 4rem 3rem; display: flex; flex-direction: column; justify-content: center; }
        .login-branding-bsb .logo-bsb { display: flex; align-items: center; margin-bottom: 2rem; }
        .login-branding-bsb .logo-bsb i { font-size: 2.5rem; margin-right: 0.75rem; } 
        .login-branding-bsb .logo-bsb .logo-text-bsb { font-size: 1.75rem; font-weight: 700; letter-spacing: 0.5px; }
        .login-branding-bsb .title-bsb { font-size: 2.75rem; font-weight: 700; line-height: 1.3; margin-bottom: 1.5rem; }
        .login-branding-bsb .description-bsb { font-size: 1.1rem; line-height: 1.7; margin-bottom: 2.5rem; max-width: 480px; opacity: 0.9; }
        .login-branding-bsb .dots-bsb { font-size: 2rem; letter-spacing: 0.6rem; opacity: 0.6; }
        .login-form-area-bsb { background-color: #ffffff; display: flex; align-items: center; justify-content: center; padding: 2rem; }
        .login-form-card-bsb { width: 100%; max-width: 420px; padding: 2.5rem; }
        .login-form-card-bsb .form-title-bsb { font-size: 2rem; font-weight: 700; color: #212529; margin-bottom: 0.75rem; }
        .login-form-card-bsb .form-subtitle-bsb { font-size: 0.95rem; color: #6c757d; margin-bottom: 1.5rem; }
        .login-form-card-bsb .form-subtitle-bsb a { color: #0d6efd; text-decoration: none; font-weight: 500; }
        .login-form-card-bsb .form-subtitle-bsb a:hover { text-decoration: underline; }
        .login-form-card-bsb .form-label { font-weight: 500; font-size: 0.9rem; margin-bottom: 0.3rem; color: #495057; }
        .login-form-card-bsb .form-control { border-radius: 0.375rem; padding: 0.85rem 1rem; border: 1px solid #ced4da; font-size: 0.95rem; background-color: #f8f9fa; }
        .login-form-card-bsb .form-control:focus { border-color: #86b7fe; box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25); background-color: #fff; }
        .login-form-card-bsb .btn-login-submit-bsb { background-color: #0d6efd; border-color: #0d6efd; color: white; padding: 0.85rem 1.5rem; font-size: 1rem; font-weight: 500; border-radius: 0.375rem; width: 100%; transition: background-color 0.15s ease-in-out, border-color 0.15s ease-in-out; }
        .login-form-card-bsb .btn-login-submit-bsb:hover { background-color: #0b5ed7; border-color: #0a58ca; }
        .extra-links-group { text-align: center; margin-top: 1.5rem; font-size: 0.9rem; } /* Group for register and forgot password */
        .extra-links-group a { color: #007bff; text-decoration: none; margin: 0 0.5rem; }
        .extra-links-group a:hover { text-decoration: underline; }
        .alert { border-radius: .375rem; }

        @media (max-width: 991.98px) { 
            .login-branding-bsb { display: none !important; } 
            .login-form-area-bsb { background-color: #0d6efd; } 
            .login-form-card-bsb { background-color: #ffffff; border-radius: 0.75rem; box-shadow: 0 0.5rem 1.5rem rgba(0,0,0,0.15); margin: auto; } 
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
                    <h1 class="title-bsb">Welcome to the DSS Portal</h1>
                    <p class="description-bsb">
                        An intelligent Decision Support System for University Course Scheduling. Please log in to continue.
                    </p>
                    <div class="dots-bsb">...... ......</div>
                </div>
            </div>
            <div class="col-lg-5 col-md-12 login-form-area-bsb">
                <div class="login-form-card-bsb">
                    <h2 class="form-title-bsb">Login</h2>
                    <p class="form-subtitle-bsb">
                        Please enter your account details.
                    </p>

                    <?php if (!empty($login_error)): ?>
                        <div class="alert alert-danger py-2" role="alert">
                            <?php echo htmlspecialchars($login_error); ?>
                        </div>
                    <?php endif; ?>
                    <?php echo display_all_flash_messages(); ?>

                    <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" novalidate>
                        <div class="form-floating mb-3">
                            <input type="text" class="form-control" id="username_or_email" name="username_or_email" placeholder="Username or Email" required value="<?php echo isset($_POST['username_or_email']) ? htmlspecialchars($_POST['username_or_email']) : ''; ?>">
                            <label for="username_or_email">Username or Email</label>
                        </div>
                        <div class="form-floating mb-3">
                            <input type="password" class="form-control" id="password" name="password" placeholder="Password" required>
                            <label for="password">Password</label>
                        </div>
                        
                        <!-- Bỏ reCAPTCHA -->

                        <div class="d-flex justify-content-between align-items-center mb-4"> <!-- Tăng mb-4 -->
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="remember_me" name="remember_me">
                                <label class="form-check-label" for="remember_me">Remember me</label>
                            </div>
                            <!-- Link Quên mật khẩu đã có, nhưng để dễ thấy hơn, ta có thể tách ra -->
                        </div>
                        <button type="submit" name="login" class="btn btn-login-submit-bsb mb-3">Login</button> <!-- Thêm mb-3 -->
                    </form>
                    
                    <div class="extra-links-group"> <!-- Nhóm các link lại -->
                        <a href="#">Forgot password?</a>
                        <span class="mx-1">|</span> <!-- Dấu phân cách -->
                        <a href="<?php echo htmlspecialchars(BASE_URL . 'register.php'); ?>">Don't have an account? Register here</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>