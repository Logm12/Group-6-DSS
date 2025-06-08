<?php
// htdocs/DSS/admin/instructors.php

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db_connect.php'; // $conn

require_role(['admin'], '../login.php');

$page_title = "Manage Instructors";

$action = $_GET['action'] ?? 'list';
$instructor_id = isset($_GET['id']) ? (int)$_GET['id'] : null;

$feedback_message = '';
$feedback_type = '';

// Form data prefill for add/edit lecturer AND for create user form
$form_lecturer_name = '';
$form_lecturer_email = '';
$form_user_username = ''; // For the username when creating a user account

// --- Process POST requests ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_instructor'])) {
        $action = 'add_submit';
    } elseif (isset($_POST['edit_instructor']) && $instructor_id) {
        $action = 'edit_submit';
    } elseif (isset($_POST['create_user_for_instructor']) && $instructor_id) { // New POST action
        $action = 'create_user_submit';
    }
}

if ($conn) {
    switch ($action) {
        case 'add_submit':
            // ... (Logic for adding a new lecturer - from your previous version)
            // This part now assumes that adding a lecturer here DOES NOT automatically create a User account.
            // User account creation will be a separate step or via a different interface as per Hướng 1.
            // OR, if you want 'add_submit' to also create a user, this logic needs to be merged carefully
            // with 'create_user_submit' logic.
            // For clarity of Hướng 1, let's keep 'add_submit' focused on Lecturers table only.
            $lecturer_name_add = trim($_POST['lecturer_name'] ?? '');
            $lecturer_email_add = trim($_POST['lecturer_email'] ?? '');

            if (empty($lecturer_name_add) || empty($lecturer_email_add)) {
                $feedback_message = "Full name and email are required."; $feedback_type = "danger"; $action = 'add';
                $form_lecturer_name = $lecturer_name_add; $form_lecturer_email = $lecturer_email_add;
            } elseif (!filter_var($lecturer_email_add, FILTER_VALIDATE_EMAIL)) {
                $feedback_message = "Invalid email format."; $feedback_type = "danger"; $action = 'add';
                $form_lecturer_name = $lecturer_name_add; $form_lecturer_email = $lecturer_email_add;
            } else {
                $stmt_check_email = $conn->prepare("SELECT LecturerID FROM Lecturers WHERE Email = ?");
                $stmt_check_email->bind_param("s", $lecturer_email_add);
                $stmt_check_email->execute();
                if ($stmt_check_email->get_result()->num_rows > 0) {
                    $feedback_message = "Email already exists for another instructor."; $feedback_type = "danger"; $action = 'add';
                    $form_lecturer_name = $lecturer_name_add; $form_lecturer_email = $lecturer_email_add;
                } else {
                    $stmt_insert = $conn->prepare("INSERT INTO Lecturers (LecturerName, Email) VALUES (?, ?)");
                    $stmt_insert->bind_param("ss", $lecturer_name_add, $lecturer_email_add);
                    if ($stmt_insert->execute()) {
                        set_flash_message('instructor_success', 'Instructor added successfully! You can now create a user account for them if needed.', 'success');
                        redirect('admin/instructors.php');
                    } else {
                        $feedback_message = "Error adding instructor: " . $stmt_insert->error; $feedback_type = "danger"; $action = 'add';
                    }
                    $stmt_insert->close();
                }
                $stmt_check_email->close();
            }
            break;

        case 'edit_submit':
            // ... (Logic for editing lecturer - from your previous version)
            // This also only focuses on the Lecturers table. User account editing would be separate.
            if ($instructor_id) {
                $lecturer_name_edit = trim($_POST['lecturer_name'] ?? '');
                $lecturer_email_edit = trim($_POST['lecturer_email'] ?? '');
                 if (empty($lecturer_name_edit) || empty($lecturer_email_edit)) {
                    $feedback_message = "Full name and email are required."; $feedback_type = "danger"; $action = 'edit';
                    $form_lecturer_name = $lecturer_name_edit; $form_lecturer_email = $lecturer_email_edit;
                } elseif (!filter_var($lecturer_email_edit, FILTER_VALIDATE_EMAIL)) {
                    $feedback_message = "Invalid email format."; $feedback_type = "danger"; $action = 'edit';
                    $form_lecturer_name = $lecturer_name_edit; $form_lecturer_email = $lecturer_email_edit;
                } else {
                    $stmt_check_email_edit = $conn->prepare("SELECT LecturerID FROM Lecturers WHERE Email = ? AND LecturerID != ?");
                    $stmt_check_email_edit->bind_param("si", $lecturer_email_edit, $instructor_id);
                    $stmt_check_email_edit->execute();
                    if ($stmt_check_email_edit->get_result()->num_rows > 0) {
                         $feedback_message = "Email already used by another instructor."; $feedback_type = "danger"; $action = 'edit';
                         $form_lecturer_name = $lecturer_name_edit; $form_lecturer_email = $lecturer_email_edit;
                    } else {
                        $stmt_update = $conn->prepare("UPDATE Lecturers SET LecturerName = ?, Email = ? WHERE LecturerID = ?");
                        $stmt_update->bind_param("ssi", $lecturer_name_edit, $lecturer_email_edit, $instructor_id);
                        if ($stmt_update->execute()) {
                            set_flash_message('instructor_success', 'Instructor updated successfully!', 'success');
                            redirect('admin/instructors.php');
                        } else {
                            $feedback_message = "Error updating instructor: " . $stmt_update->error; $feedback_type = "danger"; $action = 'edit';
                        }
                        $stmt_update->close();
                    }
                    $stmt_check_email_edit->close();
                }
            } else { redirect('admin/instructors.php');}
            break;

        case 'create_user_submit': // New action to handle user creation for an existing lecturer
            if ($instructor_id) {
                $username = trim($_POST['user_username'] ?? '');
                $password = $_POST['user_password'] ?? '';
                $user_fullname = trim($_POST['user_fullname'] ?? ''); // Should be prefilled from LecturerName
                $user_email = trim($_POST['user_email'] ?? '');     // Should be prefilled from Lecturer Email

                // Repopulate form data for create_user action on error
                $form_lecturer_name = $user_fullname; // Used for prefilling if form re-shows
                $form_lecturer_email = $user_email;
                $form_user_username = $username;

                if (empty($username) || empty($password) || empty($user_fullname) || empty($user_email)) {
                    $feedback_message = "Username, Password, Full Name, and Email are required to create the user account.";
                    $feedback_type = "danger"; $action = 'create_user'; // Go back to create_user form
                } elseif (strlen($username) < 4) {
                    $feedback_message = "Username must be at least 4 characters.";
                    $feedback_type = "danger"; $action = 'create_user';
                } elseif (strlen($password) < 6) {
                    $feedback_message = "Password must be at least 6 characters.";
                    $feedback_type = "danger"; $action = 'create_user';
                } elseif (!filter_var($user_email, FILTER_VALIDATE_EMAIL)) {
                     $feedback_message = "Invalid email format for user account.";
                     $feedback_type = "danger"; $action = 'create_user';
                }else {
                    // Check if username or email already exists in Users table
                    $stmt_check_user_exist = $conn->prepare("SELECT UserID FROM Users WHERE Username = ? OR Email = ?");
                    if (!$stmt_check_user_exist) throw new Exception("DB Error (check user existence): " . $conn->error);
                    $stmt_check_user_exist->bind_param("ss", $username, $user_email);
                    $stmt_check_user_exist->execute();
                    if ($stmt_check_user_exist->get_result()->num_rows > 0) {
                        $feedback_message = "The chosen Username or the Email is already registered for another user account.";
                        $feedback_type = "danger"; $action = 'create_user';
                    }
                    $stmt_check_user_exist->close();

                    // Check if this lecturer already has a user account
                    if ($action !== 'create_user') { // Proceed if no errors so far
                        $stmt_check_link = $conn->prepare("SELECT UserID FROM Users WHERE LinkedEntityID = ? AND Role = 'instructor'");
                        if (!$stmt_check_link) throw new Exception("DB Error (check link): " . $conn->error);
                        $instructor_id_str_check = strval($instructor_id);
                        $stmt_check_link->bind_param("s", $instructor_id_str_check);
                        $stmt_check_link->execute();
                        if ($stmt_check_link->get_result()->num_rows > 0) {
                            $feedback_message = "This instructor already has an associated user account.";
                            $feedback_type = "warning"; $action = 'list'; // Go back to list, no form needed
                        }
                        $stmt_check_link->close();
                    }


                    if ($action !== 'create_user' && $action !== 'list') { // Proceed if no errors
                        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                        $linked_entity_id_str = strval($instructor_id);

                        $stmt_insert_usr = $conn->prepare("INSERT INTO Users (Username, PasswordHash, Role, FullName, Email, LinkedEntityID) VALUES (?, ?, 'instructor', ?, ?, ?)");
                        if (!$stmt_insert_usr) throw new Exception("DB Error (insert user for lecturer): " . $conn->error);
                        $stmt_insert_usr->bind_param("sssss", $username, $hashed_password, $user_fullname, $user_email, $linked_entity_id_str);

                        if ($stmt_insert_usr->execute()) {
                            set_flash_message('instructor_success', "User account '{$username}' created successfully for instructor '{$user_fullname}'.", 'success');
                            redirect('admin/instructors.php');
                        } else {
                            $feedback_message = "Error creating user account: " . $stmt_insert_usr->error;
                            $feedback_type = "danger"; $action = 'create_user';
                        }
                        $stmt_insert_usr->close();
                    }
                }
            } else {
                set_flash_message('instructor_error', 'Invalid instructor ID for user creation.', 'danger');
                redirect('admin/instructors.php');
            }
            break;

        case 'delete':
            // ... (Logic for deleting lecturer - from your previous version)
            // IMPORTANT: If you delete a Lecturer, you should decide what happens to their Users account.
            // Current logic in your previous full `instructors.php` for delete also deleted the User.
            // If that's desired, the logic should be:
            if ($instructor_id) {
                $conn->begin_transaction();
                try {
                    $instructor_id_str_del_user = strval($instructor_id);
                    $stmt_del_user = $conn->prepare("DELETE FROM Users WHERE LinkedEntityID = ? AND Role = 'instructor'");
                    if($stmt_del_user){
                        $stmt_del_user->bind_param("s", $instructor_id_str_del_user);
                        $stmt_del_user->execute(); // Execute even if no user, won't error
                        $stmt_del_user->close();
                    } else { throw new Exception("DB Error (prepare delete user for instructor): " . $conn->error); }

                    $stmt_del_lect = $conn->prepare("DELETE FROM Lecturers WHERE LecturerID = ?");
                     if(!$stmt_del_lect) throw new Exception("DB Error (prepare delete lecturer): " . $conn->error);
                    $stmt_del_lect->bind_param("i", $instructor_id);
                    if (!$stmt_del_lect->execute()) {
                        if ($conn->errno == 1451) { // FK constraint
                            throw new Exception('Cannot delete: Instructor is assigned to scheduled classes.');
                        }
                        throw new Exception("DB Error (executing delete lecturer): " . $stmt_del_lect->error);
                    }
                    $stmt_del_lect->close();
                    $conn->commit();
                    set_flash_message('instructor_success', 'Instructor and associated user account (if any) deleted.', 'success');
                } catch (Exception $e) {
                    $conn->rollback();
                    set_flash_message('instructor_error', 'Error deleting: ' . $e->getMessage(), 'danger');
                }
            } else { set_flash_message('instructor_error', 'Invalid ID for deletion.', 'danger'); }
            redirect('admin/instructors.php');
            break;
    } // End switch $action
} elseif (!$conn && $action !== 'list') {
    $feedback_message = "Database connection error."; $feedback_type = "danger";
}

// Data for display
$lecturers_list_with_user_status = [];
$lecturer_for_user_creation = null; // For prefilling create_user form

if ($conn) {
    if ($action === 'list') {
        $search_term_list = isset($_GET['search']) ? trim($_GET['search']) : '';
        $sql_lect_list = "SELECT l.LecturerID, l.LecturerName, l.Email, u.UserID as LinkedUserID, u.Username as LinkedUsername
                          FROM Lecturers l
                          LEFT JOIN Users u ON l.LecturerID = u.LinkedEntityID AND u.Role = 'instructor'";
        $params_lect_list = [];
        $types_lect_list = "";
        if (!empty($search_term_list)) {
            $sql_lect_list .= " WHERE l.LecturerName LIKE ? OR l.Email LIKE ?";
            $search_like_lect = "%" . $search_term_list . "%";
            $params_lect_list[] = $search_like_lect; $params_lect_list[] = $search_like_lect;
            $types_lect_list = "ss";
        }
        $sql_lect_list .= " ORDER BY l.LecturerName ASC";
        $stmt_lect_list = $conn->prepare($sql_lect_list);
        if ($stmt_lect_list) {
            if(!empty($params_lect_list)) $stmt_lect_list->bind_param($types_lect_list, ...$params_lect_list);
            if($stmt_lect_list->execute()){
                $result_lect_list = $stmt_lect_list->get_result();
                while($row_l = $result_lect_list->fetch_assoc()) $lecturers_list_with_user_status[] = $row_l;
                $result_lect_list->free();
            } else { set_flash_message('db_error', "Error fetching lecturers: " . $stmt_lect_list->error, 'danger');}
            $stmt_lect_list->close();
        } else { set_flash_message('db_error', "Error preparing list: " . $conn->error, 'danger');}

    } elseif (($action === 'edit' || $action === 'create_user') && $instructor_id) {
        // For 'edit' action (editing lecturer's name/email)
        // For 'create_user' action (prefill lecturer's name/email into user creation form)
        $stmt_data = $conn->prepare("SELECT LecturerID, LecturerName, Email FROM Lecturers WHERE LecturerID = ?");
        if($stmt_data){
            $stmt_data->bind_param("i", $instructor_id);
            if($stmt_data->execute()){
                $result_data = $stmt_data->get_result();
                $lecturer_data_for_form = $result_data->fetch_assoc();
                if (!$lecturer_data_for_form) {
                    set_flash_message('instructor_error', 'Instructor not found.', 'warning');
                    redirect('admin/instructors.php');
                } else {
                    if ($action === 'edit') $lecturer_to_edit = $lecturer_data_for_form; // Deprecated usage, use form_data
                    $form_lecturer_name = $lecturer_data_for_form['LecturerName']; // Prefill for both forms
                    $form_lecturer_email = $lecturer_data_for_form['Email'];
                    
                    // If editing, and that form has username, try to get it (though user edit is separate now)
                    // If action is create_user, we don't prefill username from existing Users table.
                }
            } else {set_flash_message('db_error', "Error fetching data for form: ".$stmt_data->error, 'danger'); $action = 'list';}
            $stmt_data->close();
        } else {set_flash_message('db_error', "Error preparing to fetch data: ".$conn->error, 'danger'); $action = 'list';}
    }
}

// Repopulate form on POST error if action was reset
if (in_array($action, ['add', 'edit', 'create_user']) && $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($feedback_message) ) {
    $form_lecturer_name = $_POST['lecturer_name'] ?? $form_lecturer_name;
    $form_lecturer_email = $_POST['lecturer_email'] ?? $form_lecturer_email;
    $form_user_username = $_POST['user_username'] ?? $form_user_username; // This field is specific to create_user form
}

require_once __DIR__ . '/../includes/admin_sidebar_menu.php';
?>
<!-- Nối tiếp từ Part 1 -->
<div class="container-fluid">
    <?php echo display_all_flash_messages(); ?>
    <?php if (!empty($feedback_message)): ?>
        <div class="alert alert-<?php echo htmlspecialchars($feedback_type); ?> alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($feedback_message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php // --- FORM FOR ADDING/EDITING LECTURER (NOT USER ACCOUNT) ---
    if ($action === 'add' || ($action === 'edit' && $instructor_id)): ?>
        <div class="card shadow mb-4">
            <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-chalkboard-teacher me-2"></i><?php echo ($action === 'add' ? 'Add New Instructor Profile' : 'Edit Instructor Profile'); ?></h6></div>
            <div class="card-body">
                <form method="POST" action="instructors.php?action=<?php echo ($action === 'add' ? 'add_submit' : 'edit_submit'); ?><?php if($action === 'edit' && $instructor_id) echo '&id='.$instructor_id; ?>" class="needs-validation" novalidate>
                    <div class="mb-3">
                        <label for="lecturer_name" class="form-label">Full Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="lecturer_name" name="lecturer_name" value="<?php echo htmlspecialchars($form_lecturer_name); ?>" required>
                        <div class="invalid-feedback">Please enter the instructor's full name.</div>
                    </div>
                    <div class="mb-3">
                        <label for="lecturer_email" class="form-label">Email Address <span class="text-danger">*</span></label>
                        <input type="email" class="form-control" id="lecturer_email" name="lecturer_email" value="<?php echo htmlspecialchars($form_lecturer_email); ?>" required>
                        <div class="invalid-feedback">Please enter a valid email address.</div>
                    </div>
                    <?php if ($action === 'add'): ?>
                        <button type="submit" name="add_instructor" class="btn btn-primary"><i class="fas fa-plus me-1"></i> Add Instructor Profile</button>
                    <?php else: ?>
                        <button type="submit" name="edit_instructor" class="btn btn-success"><i class="fas fa-save me-1"></i> Save Profile Changes</button>
                    <?php endif; ?>
                    <a href="instructors.php" class="btn btn-secondary"><i class="fas fa-times me-1"></i> Cancel</a>
                </form>
            </div>
        </div>
    <?php endif; ?>


    <?php // --- FORM FOR CREATING USER ACCOUNT FOR EXISTING LECTURER ---
    if ($action === 'create_user' && $instructor_id): ?>
        <div class="card shadow mb-4">
            <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-user-plus me-2"></i>Create User Account for: <?php echo htmlspecialchars($form_lecturer_name); ?></h6></div>
            <div class="card-body">
                <form method="POST" action="instructors.php?action=create_user_submit&id=<?php echo $instructor_id; ?>" class="needs-validation" novalidate>
                    <div class="alert alert-info small">
                        You are creating a user login account for instructor: <strong><?php echo htmlspecialchars($form_lecturer_name); ?></strong>
                        (Email: <?php echo htmlspecialchars($form_lecturer_email); ?>).
                    </div>
                    <input type="hidden" name="user_fullname" value="<?php echo htmlspecialchars($form_lecturer_name); ?>">
                    <input type="hidden" name="user_email" value="<?php echo htmlspecialchars($form_lecturer_email); ?>">

                    <div class="mb-3">
                        <label for="user_username_create" class="form-label">Username <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="user_username_create" name="user_username" value="<?php echo htmlspecialchars($form_user_username); ?>" required minlength="4">
                        <div class="invalid-feedback">Username is required (min 4 chars).</div>
                    </div>
                    <div class="mb-3">
                        <label for="user_password_create" class="form-label">Password <span class="text-danger">*</span></label>
                        <input type="password" class="form-control" id="user_password_create" name="user_password" required minlength="6">
                        <div class="invalid-feedback">Password is required (min 6 chars).</div>
                    </div>
                    <button type="submit" name="create_user_for_instructor" class="btn btn-primary"><i class="fas fa-user-shield me-1"></i> Create User Account</button>
                    <a href="instructors.php" class="btn btn-secondary"><i class="fas fa-times me-1"></i> Cancel</a>
                </form>
            </div>
        </div>
    <?php endif; ?>


    <?php if ($action === 'list'): ?>
        <div class="card shadow mb-4">
            <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-chalkboard-teacher me-2"></i>Instructors Profiles</h6>
                <a href="instructors.php?action=add" class="btn btn-success btn-sm">
                    <i class="fas fa-plus fa-sm text-white-50"></i> Add New Instructor Profile
                </a>
            </div>
            <div class="card-body">
                <form method="GET" action="instructors.php" class="mb-3">
                    <input type="hidden" name="action" value="list">
                    <div class="input-group">
                        <input type="text" name="search" class="form-control form-control-sm" placeholder="Search by name or email..." value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                        <button class="btn btn-primary btn-sm" type="submit"><i class="fas fa-search"></i></button>
                         <?php if (!empty($_GET['search'])): ?>
                            <a href="instructors.php?action=list" class="btn btn-outline-secondary btn-sm" title="Clear Search"><i class="fas fa-times"></i></a>
                        <?php endif; ?>
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="table table-bordered table-hover" id="instructorsTable" width="100%" cellspacing="0">
                        <thead class="table-dark">
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Email</th>
                                <th class="text-center">User Account</th>
                                <th class="text-center" style="width: 18%;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($lecturers_list_with_user_status)): ?>
                                <?php foreach ($lecturers_list_with_user_status as $lecturer): ?>
                                    <tr>
                                        <td><?php echo $lecturer['LecturerID']; ?></td>
                                        <td><?php echo htmlspecialchars($lecturer['LecturerName']); ?></td>
                                        <td><?php echo htmlspecialchars($lecturer['Email']); ?></td>
                                        <td class="text-center">
                                            <?php if (!empty($lecturer['LinkedUserID'])): ?>
                                                <span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>Linked: <?php echo htmlspecialchars($lecturer['LinkedUsername']); ?></span>
                                            <?php else: ?>
                                                <a href="instructors.php?action=create_user&id=<?php echo $lecturer['LecturerID']; ?>" class="btn btn-sm btn-info">
                                                    <i class="fas fa-user-plus"></i> Create Account
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <a href="instructors.php?action=edit&id=<?php echo $lecturer['LecturerID']; ?>" class="btn btn-sm btn-warning me-1" title="Edit Instructor Profile">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <!-- Link to edit user account (if exists) could be added here, pointing to users.php -->
                                            <!-- <a href="users.php?action=edit&id=<?php //echo $lecturer['LinkedUserID']; ?>" class="btn btn-sm btn-secondary me-1" title="Edit User Account">
                                                <i class="fas fa-user-cog"></i>
                                            </a> -->
                                            <a href="instructors.php?action=delete&id=<?php echo $lecturer['LecturerID']; ?>" class="btn btn-sm btn-danger" title="Delete Instructor & Account"
                                               onclick="return confirm('Are you sure you want to delete this instructor and their associated user account (if any)? This action might not be undone if they are assigned to classes.');">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="text-center">No instructors found.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<style> /* Minimal specific styles */
    .table th, .table td { vertical-align: middle; }
</style>

<script>
// Bootstrap 5 form validation
(function () {
  'use strict'
  var forms = document.querySelectorAll('.needs-validation')
  Array.prototype.slice.call(forms)
    .forEach(function (form) {
      form.addEventListener('submit', function (event) {
        if (!form.checkValidity()) {
          event.preventDefault()
          event.stopPropagation()
        }
        form.classList.add('was-validated')
      }, false)
    })
})()
</script>
<!-- Layout file closes body and html -->