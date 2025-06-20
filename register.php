<?php
// htdocs/DSS/register.php

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
// error_reporting(E_ALL); // Enable for development, disable for production
// ini_set('display_errors', 1); // Enable for development

require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/functions.php';

if (is_logged_in()) {
    $role = get_current_user_role();
    $role_dir = $role ? htmlspecialchars($role) : '';
    if (in_array($role, ['admin', 'instructor', 'student']) && !empty($role_dir)) {
        redirect($role_dir . '/index.php');
    } else {
        redirect('login.php'); // Default redirect if role is unclear or no specific dir
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
$can_register_admin = true; // Default to true, check DB below

// Check if an admin account already exists
if (isset($conn) && $conn instanceof mysqli) {
    try {
        $result_check_admin = $conn->query("SELECT COUNT(*) as admin_count FROM Users WHERE Role = 'admin'");
        if ($result_check_admin) {
            $admin_row = $result_check_admin->fetch_assoc();
            if ($admin_row && $admin_row['admin_count'] > 0) {
                $can_register_admin = false;
            }
            $result_check_admin->free();
        } else {
            // Log DB error but don't necessarily halt registration for other roles
            error_log("RegisterPage: DB query error checking for existing admin: " . $conn->error);
        }
    } catch (Exception $e) {
        error_log("RegisterPage CRITICAL: Error checking for existing admin account: " . $e->getMessage());
        // Potentially set $can_register_admin to false as a safety measure if check fails
    }
} else {
    error_log("RegisterPage CRITICAL: Database connection not available for admin check.");
    // Decide behavior if DB is down: maybe disable admin registration or show generic error
    $msg = "A system error occurred. Please try again later."; // Generic message
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form_data['fullname'] = trim($_POST['fullname'] ?? '');
    $form_data['username'] = trim($_POST['username'] ?? '');
    $form_data['email'] = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $form_data['role'] = $_POST['role'] ?? '';

    if (empty($form_data['fullname']) || empty($form_data['username']) || empty($form_data['email']) || empty($password) || empty($form_data['role'])) {
        $msg = "Please fill in all required fields.";
    } elseif (strlen($form_data['username']) < 4) {
        $msg = "Username must be at least 4 characters long.";
    } elseif (!filter_var($form_data['email'], FILTER_VALIDATE_EMAIL)) {
        $msg = "Invalid email format.";
    } elseif (strlen($password) < 6) {
        $msg = "Password must be at least 6 characters long.";
    } elseif ($password !== $confirm_password) {
        $msg = "Passwords do not match.";
    } elseif (!in_array($form_data['role'], ['admin', 'instructor', 'student'])) {
        $msg = "Invalid role selected.";
    } elseif ($form_data['role'] === 'admin' && !$can_register_admin) {
        $msg = "An administrator account already exists. New admin registration is disabled.";
    } else {
        $s_fullname = sanitize_input($form_data['fullname']);
        $s_username = sanitize_input($form_data['username']);
        $s_email = sanitize_input($form_data['email']);
        $s_role = sanitize_input($form_data['role']);

        if (!$conn) { // Re-check connection before proceeding
            $msg = "Database connection error. Please try again later.";
        } else {
            try {
                $username_exists = false; $email_exists = false;

                $stmt_check_username = $conn->prepare("SELECT UserID FROM Users WHERE Username = ? LIMIT 1");
                if(!$stmt_check_username) throw new Exception("DB error (prepare username check): " . $conn->error);
                $stmt_check_username->bind_param("s", $s_username);
                $stmt_check_username->execute();
                if ($stmt_check_username->get_result()->num_rows > 0) $username_exists = true;
                $stmt_check_username->close();

                $stmt_check_email = $conn->prepare("SELECT UserID FROM Users WHERE Email = ? LIMIT 1");
                if(!$stmt_check_email) throw new Exception("DB error (prepare email check): " . $conn->error);
                $stmt_check_email->bind_param("s", $s_email);
                $stmt_check_email->execute();
                if ($stmt_check_email->get_result()->num_rows > 0) $email_exists = true;
                $stmt_check_email->close();

                if ($username_exists && $email_exists) {
                    $msg = "Username and Email already exist.";
                } elseif ($username_exists) {
                    $msg = "Username already exists.";
                } elseif ($email_exists) {
                    $msg = "Email address is already registered.";
                } else {
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $linked_entity_id = null;

                    $conn->begin_transaction();

                    if ($s_role === 'student') {
                        $student_id_generated = false; $student_id = ''; $max_tries = 10; $try_count = 0;
                        do {
                            $year_part = date('y');
                            $unique_part = str_pad(rand(0, 99999), 5, '0', STR_PAD_LEFT);
                            $student_id = "SV" . $year_part . $unique_part;

                            $stmt_check_sid = $conn->prepare("SELECT StudentID FROM Students WHERE StudentID = ?");
                            if(!$stmt_check_sid) throw new Exception("Error preparing StudentID check: " . $conn->error);
                            $stmt_check_sid->bind_param("s", $student_id);
                            $stmt_check_sid->execute();
                            if ($stmt_check_sid->get_result()->num_rows == 0) $student_id_generated = true;
                            $stmt_check_sid->close();
                            $try_count++;
                        } while (!$student_id_generated && $try_count < $max_tries);
                        if (!$student_id_generated) throw new Exception("Could not generate a unique Student ID.");

                        $stmt_insert_student = $conn->prepare("INSERT INTO Students (StudentID, StudentName, Email) VALUES (?, ?, ?)");
                        if(!$stmt_insert_student) throw new Exception("Error preparing student creation: " . $conn->error);
                        $stmt_insert_student->bind_param("sss", $student_id, $s_fullname, $s_email);
                        if(!$stmt_insert_student->execute()) throw new Exception("Error executing student creation: " . $stmt_insert_student->error);
                        $stmt_insert_student->close();
                        $linked_entity_id = $student_id;

                    } elseif ($s_role === 'instructor') {
                        // Corrected INSERT statement for Lecturers table
                        $stmt_insert_lecturer = $conn->prepare("INSERT INTO Lecturers (LecturerName, Email) VALUES (?, ?)");
                        if(!$stmt_insert_lecturer) throw new Exception("Error preparing instructor creation: " . $conn->error);
                        // Bind only two parameters: FullName for LecturerName, and Email
                        $stmt_insert_lecturer->bind_param("ss", $s_fullname, $s_email);
                        if(!$stmt_insert_lecturer->execute()) throw new Exception("Error executing instructor creation: " . $stmt_insert_lecturer->error);
                        
                        $new_lecturer_id = $conn->insert_id;
                        $stmt_insert_lecturer->close();
                        $linked_entity_id = strval($new_lecturer_id);
                    }

                    $stmt_insert_user = $conn->prepare("INSERT INTO Users (Username, PasswordHash, Role, FullName, Email, LinkedEntityID) VALUES (?, ?, ?, ?, ?, ?)");
                    if(!$stmt_insert_user) throw new Exception("Error preparing user creation: " . $conn->error);
                    $stmt_insert_user->bind_param("ssssss", $s_username, $hashed_password, $s_role, $s_fullname, $s_email, $linked_entity_id);

                    if ($stmt_insert_user->execute()) {
                        $conn->commit();
                        set_flash_message('register_success', 'Registration successful! You can now log in.', 'success');
                        redirect('login.php');
                        exit();
                    } else {
                        $conn->rollback();
                        throw new Exception("Error executing user creation: " . $stmt_insert_user->error);
                    }
                }
            } catch (Exception $e) {
                if ($conn->ping() && $conn->in_transaction) { // Check if in transaction before rollback
                     $conn->rollback();
                }
                $error_message_for_log = "Registration Error: " . $e->getMessage();
                // Avoid logging full trace to user, but log it server-side
                error_log($error_message_for_log . " --- Trace: " . $e->getTraceAsString());

                if ($conn && $conn->errno == 1062) { // MySQL error code for duplicate entry
                    if (stripos($e->getMessage(), 'username') !== false || stripos($e->getMessage(), $conn->get_charset()->csname . "'UQ_Username'") !== false) {
                         $msg = "Username already exists. Please choose a different one.";
                    } elseif (stripos($e->getMessage(), 'email') !== false || stripos($e->getMessage(), $conn->get_charset()->csname . "'UQ_Email'") !== false) { // Assuming UQ_Email on Users.Email
                         $msg = "Email address is already registered.";
                    } else {
                         $msg = "Registration failed: Some information you provided might already be in use or is invalid.";
                    }
                } else {
                    $msg = "A system error occurred during registration. Please try again later or contact support.";
                }
                $alert_type = 'danger';
            }
        } // end else ($conn check)
    } // end else (basic validation)
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - University DSS Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        /* CSS from your original file - kept as is */
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
        @media (max-width: 991.98px) { .login-branding-bsb { display: none !important; } .login-form-area-bsb { background-color: #0d6efd; } .login-form-card-bsb { background-color: #ffffff; border-radius: 0.75rem; box-shadow: 0 0.5rem 1.5rem rgba(0,0,0,0.15); margin: auto; } }
        @media (max-width: 575.98px) { .login-form-area-bsb { padding: 1rem; } .login-form-card-bsb { padding: 1.5rem; } }
        .alert { margin-bottom: 1rem; }
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
                    <h1 class="title-bsb">Join the University DSS Portal</h1>
                    <p class="description-bsb">
                        Register to access the intelligent Decision Support System for University Course Scheduling.
                    </p>
                    <div class="dots-bsb">...... ......</div>
                </div>
            </div>
            <div class="col-lg-5 col-md-12 login-form-area-bsb">
                <div class="login-form-card-bsb">
                    <h2 class="form-title-bsb">Create Account</h2>
                    <p class="form-subtitle-bsb">
                        Already have an account?
                        <a href="<?php echo htmlspecialchars(BASE_URL); ?>login.php">Login here</a>
                    </p>

                    <?php if (!empty($msg)): ?>
                        <div class="alert alert-<?php echo htmlspecialchars($alert_type); ?> py-2 mb-3" role="alert">
                            <?php echo htmlspecialchars($msg); ?>
                        </div>
                    <?php endif; ?>
                    <?php echo display_all_flash_messages(); // From functions.php ?>

                    <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" novalidate>
                        <div class="mb-3">
                            <label for="fullname" class="form-label">Full Name</label>
                            <input type="text" class="form-control" id="fullname" name="fullname" placeholder="Your full name" required value="<?php echo htmlspecialchars($form_data['fullname']); ?>">
                        </div>
                        <div class="mb-3">
                            <label for="username" class="form-label">Username</label>
                            <input type="text" class="form-control" id="username" name="username" placeholder="Username (min. 4 chars)" required value="<?php echo htmlspecialchars($form_data['username']); ?>">
                        </div>
                        <div class="mb-3">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" class="form-control" id="email" name="email" placeholder="Your email address" required value="<?php echo htmlspecialchars($form_data['email']); ?>">
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">Password</label>
                            <input type="password" class="form-control" id="password" name="password" placeholder="Password (min. 6 chars)" required>
                        </div>
                        <div class="mb-3">
                            <label for="confirm_password" class="form-label">Confirm Password</label>
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" placeholder="Re-enter password" required>
                        </div>
                        <div class="mb-3">
                            <label for="role" class="form-label">Register as</label>
                            <select class="form-select" id="role" name="role" required>
                                <option value="" <?php selected_if_match($form_data['role'], ""); ?>>-- Select role --</option>
                                <option value="student" <?php selected_if_match($form_data['role'], "student"); ?>>Student</option>
                                <option value="instructor" <?php selected_if_match($form_data['role'], "instructor"); ?>>Instructor</option>
                                <?php if ($can_register_admin): ?>
                                <option value="admin" <?php selected_if_match($form_data['role'], "admin"); ?>>Administrator</option>
                                <?php endif; ?>
                            </select>
                        </div>

                        <button type="submit" class="btn btn-login-submit-bsb">Register</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>