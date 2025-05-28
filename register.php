<?php
// htdocs/DSS/register.php

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Sử dụng $conn từ db_connect.php thay vì $pdo nếu bạn đang dùng MySQLi
require_once __DIR__ . '/includes/db_connect.php'; // Cung cấp biến $conn (MySQLi)
require_once __DIR__ . '/includes/functions.php';

define('RECAPTCHA_SITE_KEY', '6LcrlEUrAAAAAB7MIWwZuHcxGuwk7Wo3WLuD0gNw'); // Thay bằng Site Key của bạn
define('RECAPTCHA_SECRET_KEY', '6LcrlEUrAAAAAEaDm6SswQygwRqc-cANGOKst9Um'); // Thay bằng Secret Key của bạn

if (is_logged_in()) {
    // Chuyển hướng người dùng đã đăng nhập về dashboard của họ
    $role_dir = get_current_user_role(); // 'admin', 'instructor', 'student'
    if ($role_dir) {
        redirect($role_dir . '/index.php');
    } else {
        redirect('index.php'); // Fallback
    }
    exit();
}

$msg = '';
$alert_type = 'danger';
$form_data = [
    'fullname' => '',
    'username' => '',
    'email' => '',
    'role' => ''
];
$can_register_admin = true;

// Kiểm tra xem đã có admin nào chưa (sử dụng $conn - MySQLi)
try {
    $result_check_admin = $conn->query("SELECT COUNT(*) as admin_count FROM Users WHERE Role = 'admin'");
    if ($result_check_admin) {
        $admin_row = $result_check_admin->fetch_assoc();
        if ($admin_row['admin_count'] > 0) {
            $can_register_admin = false;
        }
        $result_check_admin->free();
    } else {
        throw new Exception("Lỗi truy vấn kiểm tra admin: " . $conn->error);
    }
} catch (Exception $e) {
    error_log("Error checking for existing admin: " . $e->getMessage());
    // Nếu có lỗi CSDL, tạm thời vẫn cho đăng ký admin để tránh khóa hệ thống
    // hoặc bạn có thể đặt $can_register_admin = false; để an toàn hơn
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form_data['fullname'] = trim($_POST['fullname'] ?? '');
    $form_data['username'] = trim($_POST['username'] ?? '');
    $form_data['email'] = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $form_data['role'] = $_POST['role'] ?? '';
    $recaptcha_response = $_POST['g-recaptcha-response'] ?? '';

    // --- VALIDATION ---
    if (empty($form_data['fullname']) || empty($form_data['username']) || empty($form_data['email']) || empty($password) || empty($form_data['role'])) {
        $msg = "Vui lòng điền đầy đủ các trường bắt buộc.";
    } elseif (strlen($form_data['username']) < 4) {
        $msg = "Tên đăng nhập phải có ít nhất 4 ký tự.";
    } elseif (!filter_var($form_data['email'], FILTER_VALIDATE_EMAIL)) {
        $msg = "Định dạng email không hợp lệ.";
    } elseif (strlen($password) < 6) {
        $msg = "Mật khẩu phải có ít nhất 6 ký tự.";
    } elseif ($password !== $confirm_password) {
        $msg = "Mật khẩu và xác nhận mật khẩu không khớp.";
    } elseif (!in_array($form_data['role'], ['admin', 'instructor', 'student'])) {
        $msg = "Vai trò đã chọn không hợp lệ.";
    } elseif ($form_data['role'] === 'admin' && !$can_register_admin) {
        $msg = "Tài khoản quản trị viên đã tồn tại. Không thể đăng ký thêm quản trị viên.";
    } elseif (empty($recaptcha_response)) {
        $msg = "Vui lòng xác minh bạn không phải là robot.";
    } else {
        // --- reCAPTCHA Verification ---
        $recaptcha_verify_url = 'https://www.google.com/recaptcha/api/siteverify';
        $recaptcha_data = ['secret' => RECAPTCHA_SECRET_KEY, 'response' => $recaptcha_response, 'remoteip' => $_SERVER['REMOTE_ADDR']];
        $options = ['http' => ['header'  => "Content-type: application/x-www-form-urlencoded\r\n", 'method'  => 'POST', 'content' => http_build_query($recaptcha_data), 'timeout' => 5]];
        $context  = stream_context_create($options);
        $verify_result_json = @file_get_contents($recaptcha_verify_url, false, $context);

        if ($verify_result_json === FALSE) {
            $msg = "Không thể kết nối đến dịch vụ reCAPTCHA. Vui lòng thử lại sau.";
        } else {
            $verify_result = json_decode($verify_result_json);
            if (!$verify_result || !isset($verify_result->success) || $verify_result->success !== true) {
                $msg = "Xác minh reCAPTCHA thất bại. Vui lòng thử lại.";
                // Log lỗi từ reCAPTCHA (nếu có) để debug
                if (isset($verify_result->{'error-codes'})) {
                    error_log("reCAPTCHA error codes: " . implode(', ', $verify_result->{'error-codes'}));
                }
            } else {
                // --- Database Operations ---
                try {
                    $username_exists = false;
                    $email_exists = false;

                    // Kiểm tra Username tồn tại (MySQLi)
                    $stmt_check_username = $conn->prepare("SELECT UserID FROM Users WHERE Username = ? LIMIT 1");
                    if(!$stmt_check_username) throw new Exception("Lỗi chuẩn bị kiểm tra username: " . $conn->error);
                    $stmt_check_username->bind_param("s", $form_data['username']);
                    $stmt_check_username->execute();
                    $result_username = $stmt_check_username->get_result();
                    if ($result_username->num_rows > 0) {
                        $username_exists = true;
                    }
                    $stmt_check_username->close();

                    // Kiểm tra Email tồn tại (MySQLi)
                    $stmt_check_email = $conn->prepare("SELECT UserID FROM Users WHERE Email = ? LIMIT 1");
                    if(!$stmt_check_email) throw new Exception("Lỗi chuẩn bị kiểm tra email: " . $conn->error);
                    $stmt_check_email->bind_param("s", $form_data['email']);
                    $stmt_check_email->execute();
                    $result_email = $stmt_check_email->get_result();
                    if ($result_email->num_rows > 0) {
                        $email_exists = true;
                    }
                    $stmt_check_email->close();

                    if ($username_exists && $email_exists) {
                        $msg = "Tên đăng nhập và Email đã tồn tại.";
                    } elseif ($username_exists) {
                        $msg = "Tên đăng nhập đã tồn tại.";
                    } elseif ($email_exists) {
                        $msg = "Email đã tồn tại.";
                    } else {
                        // --- HASH PASSWORD ---
                        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                        $linked_entity_id = null; 

                        $conn->begin_transaction(); // Bắt đầu transaction

                        // --- XỬ LÝ TẠO STUDENT HOẶC LECTURER ---
                        if ($form_data['role'] === 'student') {
                            $student_id_generated = false;
                            $student_id = '';
                            $max_tries = 10; // Tránh vòng lặp vô hạn nếu có vấn đề
                            $try_count = 0;

                            do {
                                $year_part = "2" . rand(1, 4); // Năm từ 21xx đến 24xx
                                $department_code = "07"; // Mã khoa/ngành (ví dụ)
                                $random_suffix = str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);
                                $student_id = $year_part . $department_code . $random_suffix; // Ví dụ: 21070001

                                $stmt_check_sid = $conn->prepare("SELECT StudentID FROM Students WHERE StudentID = ?");
                                if(!$stmt_check_sid) throw new Exception("Lỗi chuẩn bị kiểm tra StudentID: " . $conn->error);
                                $stmt_check_sid->bind_param("s", $student_id);
                                $stmt_check_sid->execute();
                                $result_sid = $stmt_check_sid->get_result();
                                if ($result_sid->num_rows == 0) {
                                    $student_id_generated = true;
                                }
                                $stmt_check_sid->close();
                                $try_count++;
                            } while (!$student_id_generated && $try_count < $max_tries);

                            if (!$student_id_generated) {
                                throw new Exception("Không thể tạo mã sinh viên duy nhất sau $max_tries lần thử.");
                            }

                            $insert_student_sql = "INSERT INTO Students (StudentID, StudentName, Email) VALUES (?, ?, ?)";
                            $stmt_insert_student = $conn->prepare($insert_student_sql);
                            if(!$stmt_insert_student) throw new Exception("Lỗi chuẩn bị tạo sinh viên: " . $conn->error);
                            $stmt_insert_student->bind_param("sss", $student_id, $form_data['fullname'], $form_data['email']);
                            if(!$stmt_insert_student->execute()) throw new Exception("Lỗi thực thi tạo sinh viên: " . $stmt_insert_student->error);
                            $stmt_insert_student->close();
                            $linked_entity_id = $student_id; // LinkedEntityID là StudentID (VARCHAR)

                        } elseif ($form_data['role'] === 'instructor') {
                            // Bảng Lecturers có LecturerID là INT AUTO_INCREMENT
                            // Nên chúng ta insert vào Lecturers trước để lấy LecturerID (INT)
                            $default_department = 'Khoa CNTT'; // Hoặc để trống/NULL nếu DB cho phép
                            $insert_lecturer_sql = "INSERT INTO Lecturers (LecturerName, Email, Department) VALUES (?, ?, ?)";
                            $stmt_insert_lecturer = $conn->prepare($insert_lecturer_sql);
                            if(!$stmt_insert_lecturer) throw new Exception("Lỗi chuẩn bị tạo giảng viên: " . $conn->error);
                            $stmt_insert_lecturer->bind_param("sss", $form_data['fullname'], $form_data['email'], $default_department);
                            if(!$stmt_insert_lecturer->execute()) throw new Exception("Lỗi thực thi tạo giảng viên: " . $stmt_insert_lecturer->error);
                            $linked_entity_id = $conn->insert_id; // Lấy LecturerID (INT) vừa được tạo
                            $stmt_insert_lecturer->close();
                            // LinkedEntityID cho instructor sẽ là INT LecturerID này, nhưng cột Users.LinkedEntityID là VARCHAR(20)
                            // Chuyển INT sang string để lưu.
                            $linked_entity_id = strval($linked_entity_id); 
                        }

                        // --- INSERT VÀO BẢNG USERS ---
                        $sql_insert_user = "INSERT INTO Users (Username, PasswordHash, Role, FullName, Email, LinkedEntityID) 
                                            VALUES (?, ?, ?, ?, ?, ?)";
                        $stmt_insert_user = $conn->prepare($sql_insert_user);
                        if(!$stmt_insert_user) throw new Exception("Lỗi chuẩn bị tạo người dùng: " . $conn->error);
                        
                        // LinkedEntityID có thể là NULL (cho admin), hoặc string (cho student/instructor)
                        $stmt_insert_user->bind_param("ssssss", 
                            $form_data['username'], 
                            $hashed_password, 
                            $form_data['role'], 
                            $form_data['fullname'], 
                            $form_data['email'], 
                            $linked_entity_id // Luôn là string hoặc null
                        );

                        if ($stmt_insert_user->execute()) {
                            $conn->commit(); // Hoàn tất transaction
                            set_flash_message('register_success', 'Đăng ký thành công! Bây giờ bạn có thể đăng nhập.', 'success');
                            redirect('login.php'); // Chuyển hướng đến trang đăng nhập
                            exit();
                        } else {
                            $conn->rollback();
                            throw new Exception("Lỗi thực thi tạo người dùng: " . $stmt_insert_user->error);
                        }
                        $stmt_insert_user->close();
                    } // end if username/email not exists
                } catch (Exception $e) { // Bắt cả PDOException và Exception thường
                    if ($conn->server_info && $conn->thread_id) { // Kiểm tra xem kết nối có còn không
                         if (method_exists($conn, 'rollback')) $conn->rollback(); // Chỉ rollback nếu $conn là MySQLi và hỗ trợ
                    }
                    error_log("Lỗi Đăng ký: " . $e->getMessage() . " --- Trace: " . $e->getTraceAsString());
                    // Kiểm tra mã lỗi CSDL cụ thể cho duplicate entry (MySQLi)
                    if ($conn->errno == 1062) { // Mã lỗi cho duplicate entry
                        if (strpos(strtolower($e->getMessage()), 'username') !== false) {
                             $msg = "Tên đăng nhập đã tồn tại. Vui lòng chọn tên khác.";
                        } elseif (strpos(strtolower($e->getMessage()), 'email') !== false) {
                             $msg = "Địa chỉ email đã được đăng ký.";
                        } else {
                             $msg = "Lỗi đăng ký: Thông tin bạn cung cấp có thể đã được sử dụng.";
                        }
                    } else {
                        $msg = "Đã xảy ra lỗi hệ thống trong quá trình đăng ký. Vui lòng thử lại sau. Chi tiết: " . $e->getMessage();
                    }
                } // end try-catch DB operations
            } // end reCAPTCHA success
        } // end reCAPTCHA connected
    } // end basic validation
} // end POST request
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng Ký - Cổng DSS Đại Học</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    <style>
        /* CSS giữ nguyên như bạn cung cấp (giống trang login) */
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; }
        body.login-page-bsb { background-color: #0d6efd; min-height: 100vh; display: flex; overflow-x: hidden; }
        .login-bsb-wrapper { width: 100%; display: flex; }
        .login-branding-bsb { background-color: #0d6efd; color: #ffffff; padding: 4rem 3rem; display: flex; flex-direction: column; justify-content: center; }
        .login-branding-bsb .logo-bsb { display: flex; align-items: center; margin-bottom: 2rem; }
        .login-branding-bsb .logo-bsb img { height: 40px; margin-right: 0.75rem; }
        .login-branding-bsb .logo-bsb .logo-text-bsb { font-size: 1.75rem; font-weight: 700; letter-spacing: 0.5px; }
        .login-branding-bsb .logo-bsb i { font-size: 2.5rem; margin-right: 0.75rem; }
        .login-branding-bsb .title-bsb { font-size: 2.75rem; font-weight: 700; line-height: 1.3; margin-bottom: 1.5rem; }
        .login-branding-bsb .description-bsb { font-size: 1.1rem; line-height: 1.7; margin-bottom: 2.5rem; max-width: 480px; opacity: 0.9; }
        .login-branding-bsb .dots-bsb { font-size: 2rem; letter-spacing: 0.6rem; opacity: 0.6; }
        .login-form-area-bsb { background-color: #ffffff; display: flex; align-items: center; justify-content: center; padding: 2rem; }
        .login-form-card-bsb { width: 100%; max-width: 480px; padding: 2.5rem; }
        .login-form-card-bsb .form-title-bsb { font-size: 2rem; font-weight: 700; color: #212529; margin-bottom: 0.75rem; }
        .login-form-card-bsb .form-subtitle-bsb { font-size: 0.95rem; color: #6c757d; margin-bottom: 1.5rem; }
        .login-form-card-bsb .form-subtitle-bsb a { color: #0d6efd; text-decoration: none; font-weight: 500; }
        .login-form-card-bsb .form-subtitle-bsb a:hover { text-decoration: underline; }
        .login-form-card-bsb .form-label { font-weight: 500; font-size: 0.9rem; margin-bottom: 0.3rem; color: #495057; }
        .login-form-card-bsb .form-control, .login-form-card-bsb .form-select { border-radius: 0.375rem; padding: 0.85rem 1rem; border: 1px solid #ced4da; font-size: 0.95rem; background-color: #f8f9fa; }
        .login-form-card-bsb .form-control:focus, .login-form-card-bsb .form-select:focus { border-color: #86b7fe; box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25); background-color: #fff; }
        .login-form-card-bsb .btn-login-submit-bsb { background-color: #0d6efd; border-color: #0d6efd; color: white; padding: 0.85rem 1.5rem; font-size: 1rem; font-weight: 500; border-radius: 0.375rem; width: 100%; transition: background-color 0.15s ease-in-out, border-color 0.15s ease-in-out; }
        .login-form-card-bsb .btn-login-submit-bsb:hover { background-color: #0b5ed7; border-color: #0a58ca; }
        @media (max-width: 991.98px) { .login-branding-bsb { display: none !important; } .login-form-area-bsb { background-color: #0d6efd; } .login-form-card-bsb { background-color: #ffffff; border-radius: 0.75rem; box-shadow: 0 0.5rem 1.5rem rgba(0,0,0,0.15); } }
        @media (max-width: 575.98px) { .login-form-area-bsb { padding: 1rem; } .login-form-card-bsb { padding: 1.5rem; } }
        .g-recaptcha > div { margin: 0 auto !important; }
        .alert { margin-bottom: 1rem; }
    </style>
</head>
<body class="login-page-bsb">

    <div class="container-fluid login-bsb-wrapper p-0">
        <div class="row g-0 h-100">
            
            <div class="col-lg-7 d-none d-lg-flex login-branding-bsb">
                <div>
                    <div class="logo-bsb">
                        <i class="fas fa-university fa-2x"></i> <!-- Thay brain bằng university cho phù hợp hơn -->
                        <span class="logo-text-bsb">UniDSS</span>
                    </div>
                    <h1 class="title-bsb">Tham gia Cổng DSS Đại Học</h1>
                    <p class="description-bsb">
                        Đăng ký để truy cập Hệ thống Hỗ trợ Quyết định thông minh cho việc Xếp lịch Khóa học Đại học. Tối ưu hóa nguồn lực và tinh giản kế hoạch học tập của bạn.
                    </p>
                    <div class="dots-bsb">...... ......</div>
                </div>
            </div>

            <div class="col-lg-5 col-md-12 login-form-area-bsb">
                <div class="login-form-card-bsb">
                    <h2 class="form-title-bsb">Tạo tài khoản</h2>
                    <p class="form-subtitle-bsb">
                        Đã có tài khoản? 
                        <a href="<?php echo htmlspecialchars(BASE_URL); ?>login.php">Đăng nhập</a>
                    </p>

                    <?php if (!empty($msg)): ?>
                        <div class="alert alert-<?php echo htmlspecialchars($alert_type); ?> py-2 mb-3" role="alert">
                            <?php echo htmlspecialchars($msg); ?>
                        </div>
                    <?php endif; ?>
                     <?php echo display_all_flash_messages(); // Hiển thị flash message nếu có (ví dụ từ redirect) ?>


                    <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); // Action là chính trang này ?>">
                        <div class="mb-3">
                            <label for="fullname" class="form-label">Họ và Tên</label>
                            <input type="text" class="form-control" id="fullname" name="fullname" placeholder="Nhập họ và tên đầy đủ" required value="<?php echo htmlspecialchars($form_data['fullname']); ?>">
                        </div>
                        <div class="mb-3">
                            <label for="username" class="form-label">Tên đăng nhập</label>
                            <input type="text" class="form-control" id="username" name="username" placeholder="Chọn tên đăng nhập (ít nhất 4 ký tự)" required value="<?php echo htmlspecialchars($form_data['username']); ?>">
                        </div>
                        <div class="mb-3">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" class="form-control" id="email" name="email" placeholder="Nhập địa chỉ email" required value="<?php echo htmlspecialchars($form_data['email']); ?>">
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">Mật khẩu</label>
                            <input type="password" class="form-control" id="password" name="password" placeholder="Tạo mật khẩu (ít nhất 6 ký tự)" required>
                        </div>
                        <div class="mb-3">
                            <label for="confirm_password" class="form-label">Xác nhận Mật khẩu</label>
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" placeholder="Nhập lại mật khẩu" required>
                        </div>
                        <div class="mb-3">
                            <label for="role" class="form-label">Đăng ký với vai trò</label>
                            <select class="form-select" id="role" name="role" required>
                                <option value="" <?php if (empty($form_data['role'])) echo 'selected'; ?>>-- Chọn vai trò --</option>
                                <option value="student" <?php if ($form_data['role'] === 'student') echo 'selected'; ?>>Sinh viên</option>
                                <option value="instructor" <?php if ($form_data['role'] === 'instructor') echo 'selected'; ?>>Giảng viên</option>
                                <?php if ($can_register_admin): ?>
                                <option value="admin" <?php if ($form_data['role'] === 'admin') echo 'selected'; ?>>Quản trị viên</option> 
                                <?php endif; ?>
                            </select>
                        </div>

                        <div class="g-recaptcha mb-3" data-sitekey="<?php echo RECAPTCHA_SITE_KEY; ?>"></div>
                        
                        <button type="submit" class="btn btn-login-submit-bsb">Đăng Ký</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>