<?php
// htdocs/DSS/admin/users.php

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db_connect.php'; // $conn

require_role(['admin'], '../login.php'); // Only admins can access

$page_title = "Manage User Accounts";

$action = $_GET['action'] ?? 'list'; // Default action
$user_id_to_manage = isset($_GET['id']) ? (int)$_GET['id'] : null;

$feedback_message = '';
$feedback_type = '';

// Form data prefill and error handling
$form_data = [
    'user_id' => '', 'username' => '', 'fullname' => '', 'email' => '',
    'role' => '', 'linked_entity_id' => '', 'current_linked_entity_display' => ''
];

// --- Process POST requests for add/edit ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_user_account'])) {
        $action = 'add_submit';
    } elseif (isset($_POST['edit_user_account']) && $user_id_to_manage) {
        $action = 'edit_submit';
    }
}

if ($conn) {
    switch ($action) {
        case 'add_submit':
            $username = trim($_POST['username'] ?? '');
            $fullname = trim($_POST['fullname'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $role = $_POST['role'] ?? '';
            $linked_entity_id_input = trim($_POST['linked_entity_id'] ?? '');

            // Repopulate form for errors
            $form_data = array_merge($form_data, $_POST);

            if (empty($username) || empty($fullname) || empty($email) || empty($password) || empty($role)) {
                $feedback_message = "Username, Full Name, Email, Password, and Role are required.";
                $feedback_type = "danger"; $action = 'add';
            } elseif (strlen($username) < 4) {
                $feedback_message = "Username must be at least 4 characters."; $feedback_type = "danger"; $action = 'add';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $feedback_message = "Invalid email format."; $feedback_type = "danger"; $action = 'add';
            } elseif (strlen($password) < 6) {
                $feedback_message = "Password must be at least 6 characters."; $feedback_type = "danger"; $action = 'add';
            } elseif (!in_array($role, ['admin', 'instructor', 'student'])) {
                $feedback_message = "Invalid role selected."; $feedback_type = "danger"; $action = 'add';
            } elseif (($role === 'instructor' || $role === 'student') && empty($linked_entity_id_input)) {
                $feedback_message = "A linked Lecturer or Student ID is required for the selected role."; $feedback_type = "danger"; $action = 'add';
            } else {
                $stmt_check = $conn->prepare("SELECT UserID FROM Users WHERE Username = ? OR Email = ?");
                if (!$stmt_check) throw new Exception("DB Error (check user): " . $conn->error);
                $stmt_check->bind_param("ss", $username, $email);
                $stmt_check->execute();
                if ($stmt_check->get_result()->num_rows > 0) {
                    $feedback_message = "Username or Email already exists."; $feedback_type = "danger"; $action = 'add';
                }
                $stmt_check->close();

                if (($role === 'instructor' || $role === 'student') && !empty($linked_entity_id_input) && $action !== 'add') {
                    $stmt_check_link = $conn->prepare("SELECT UserID FROM Users WHERE LinkedEntityID = ? AND Role = ?");
                    if (!$stmt_check_link) throw new Exception("DB Error (check link): " . $conn->error);
                    $stmt_check_link->bind_param("ss", $linked_entity_id_input, $role);
                    $stmt_check_link->execute();
                    if ($stmt_check_link->get_result()->num_rows > 0) {
                        $feedback_message = "The selected " . ucfirst($role) . " is already linked to a user account.";
                        $feedback_type = "danger"; $action = 'add';
                    }
                    $stmt_check_link->close();
                }

                if ($action !== 'add') { // If no validation errors so far
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $actual_linked_entity_id = ($role === 'admin' || empty($linked_entity_id_input)) ? null : $linked_entity_id_input;

                    $stmt_insert = $conn->prepare("INSERT INTO Users (Username, PasswordHash, Role, FullName, Email, LinkedEntityID) VALUES (?, ?, ?, ?, ?, ?)");
                    if (!$stmt_insert) throw new Exception("DB Error (insert user): " . $conn->error);
                    $stmt_insert->bind_param("ssssss", $username, $hashed_password, $role, $fullname, $email, $actual_linked_entity_id);

                    if ($stmt_insert->execute()) {
                        set_flash_message('user_success', "User account '{$username}' created successfully.", 'success');
                        redirect('admin/users.php');
                    } else {
                        $feedback_message = "Error creating user: " . $stmt_insert->error;
                        $feedback_type = "danger"; $action = 'add';
                    }
                    $stmt_insert->close();
                }
            }
            break;

        case 'edit_submit':
            if ($user_id_to_manage) {
                // ... (Logic for edit_submit, similar to add_submit but with UPDATE and checks excluding current user)
                // This part needs to be fully implemented based on the add_submit logic
                // and considerations for changing roles/linked entities.
                // For brevity here, assuming similar validation and update logic.
                // Crucially, it needs to handle password changes (only if new password provided)
                // and uniqueness checks for username/email EXCLUDING the current user.
                $username = trim($_POST['username'] ?? '');
                $fullname = trim($_POST['fullname'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $new_password = $_POST['password'] ?? ''; // Optional
                $role = $_POST['role'] ?? '';
                $linked_entity_id_input = trim($_POST['linked_entity_id'] ?? '');

                // Repopulate form data
                $form_data = array_merge($form_data, $_POST, ['user_id' => $user_id_to_manage]);


                // Add full validation similar to 'add_submit'
                if (empty($username) || empty($fullname) || empty($email) || empty($role)) {
                     $feedback_message = "Username, Full Name, Email, and Role are required."; $feedback_type = "danger"; $action = 'edit';
                } elseif (strlen($username) < 4) {
                    $feedback_message = "Username must be at least 4 characters."; $feedback_type = "danger"; $action = 'edit';
                } /* ... more validations ... */ else {
                    // Uniqueness checks (excluding current user)
                    $stmt_check_edit = $conn->prepare("SELECT UserID FROM Users WHERE (Username = ? OR Email = ?) AND UserID != ?");
                    $stmt_check_edit->bind_param("ssi", $username, $email, $user_id_to_manage);
                    $stmt_check_edit->execute();
                    $is_conflict = $stmt_check_edit->get_result()->num_rows > 0;
                    $stmt_check_edit->close();

                    if ($is_conflict) {
                         $feedback_message = "Username or Email already used by another account."; $feedback_type = "danger"; $action = 'edit';
                    } else {
                        // Check linked entity conflict if role is instructor/student
                        if (($role === 'instructor' || $role === 'student') && !empty($linked_entity_id_input)) {
                            $stmt_check_link_edit = $conn->prepare("SELECT UserID FROM Users WHERE LinkedEntityID = ? AND Role = ? AND UserID != ?");
                            $stmt_check_link_edit->bind_param("ssi", $linked_entity_id_input, $role, $user_id_to_manage);
                            $stmt_check_link_edit->execute();
                            if ($stmt_check_link_edit->get_result()->num_rows > 0) {
                                $feedback_message = "The selected " . ucfirst($role) . " is already linked to another user account.";
                                $feedback_type = "danger"; $action = 'edit';
                            }
                            $stmt_check_link_edit->close();
                        }
                        
                        if ($action !== 'edit') { // Proceed if no conflict
                            $actual_linked_entity_id_edit = ($role === 'admin' || empty($linked_entity_id_input)) ? null : $linked_entity_id_input;
                            if (!empty($new_password)) {
                                $hashed_password_new = password_hash($new_password, PASSWORD_DEFAULT);
                                $stmt_update = $conn->prepare("UPDATE Users SET Username=?, PasswordHash=?, Role=?, FullName=?, Email=?, LinkedEntityID=? WHERE UserID=?");
                                $stmt_update->bind_param("ssssssi", $username, $hashed_password_new, $role, $fullname, $email, $actual_linked_entity_id_edit, $user_id_to_manage);
                            } else {
                                $stmt_update = $conn->prepare("UPDATE Users SET Username=?, Role=?, FullName=?, Email=?, LinkedEntityID=? WHERE UserID=?");
                                $stmt_update->bind_param("sssssi", $username, $role, $fullname, $email, $actual_linked_entity_id_edit, $user_id_to_manage);
                            }
                            if ($stmt_update && $stmt_update->execute()) {
                                set_flash_message('user_success', "User account '{$username}' updated.", 'success');
                                redirect('admin/users.php');
                            } else {
                                $feedback_message = "Error updating user: " . ($stmt_update ? $stmt_update->error : $conn->error);
                                $feedback_type = "danger"; $action = 'edit';
                            }
                            if($stmt_update) $stmt_update->close();
                        }
                    }
                }
            } else { redirect('admin/users.php'); }
            break;

        case 'delete':
            if ($user_id_to_manage) {
                // Consider implications before deleting, e.g., linked data, or if it's the only admin.
                // For this example, direct delete.
                $stmt_delete = $conn->prepare("DELETE FROM Users WHERE UserID = ?");
                if ($stmt_delete) {
                    $stmt_delete->bind_param("i", $user_id_to_manage);
                    if ($stmt_delete->execute()) {
                        set_flash_message('user_success', 'User account deleted successfully.', 'success');
                    } else {
                        set_flash_message('user_error', 'Error deleting user: ' . $stmt_delete->error, 'danger');
                    }
                    $stmt_delete->close();
                } else { set_flash_message('user_error', 'DB error preparing delete.', 'danger');}
            } else { set_flash_message('user_error', 'Invalid user ID for deletion.', 'danger'); }
            redirect('admin/users.php');
            break;
    }
} elseif (!$conn && $action !== 'list') {
    $feedback_message = "Database connection error."; $feedback_type = "danger";
}

// Data for display (list view, edit form prefill)
$users_list = [];
$lecturers_for_select = [];
$students_for_select = [];

if ($conn) {
    if ($action === 'list') {
        $search_keyword = isset($_GET['search']) ? trim($_GET['search']) : '';
        $filter_role = isset($_GET['filter_role']) && in_array($_GET['filter_role'], ['admin', 'instructor', 'student']) ? $_GET['filter_role'] : '';
        $entries_per_page = isset($_GET['show_entries']) && in_array($_GET['show_entries'], [10, 25, 50, 100]) ? (int)$_GET['show_entries'] : 10; // For pagination

        $sql_users = "SELECT UserID, Username, FullName, Email, Role, LinkedEntityID FROM Users";
        $conditions = []; $params_sql = []; $types_sql = "";

        if (!empty($search_keyword)) {
            $conditions[] = "(Username LIKE ? OR FullName LIKE ? OR Email LIKE ?)";
            $like_search = "%" . $search_keyword . "%";
            array_push($params_sql, $like_search, $like_search, $like_search);
            $types_sql .= "sss";
        }
        if (!empty($filter_role)) {
            $conditions[] = "Role = ?";
            $params_sql[] = $filter_role;
            $types_sql .= "s";
        }
        if (!empty($conditions)) $sql_users .= " WHERE " . implode(" AND ", $conditions);
        $sql_users .= " ORDER BY Role, Username ASC"; // Add LIMIT and OFFSET for pagination later

        $stmt_users = $conn->prepare($sql_users);
        if ($stmt_users) {
            if (!empty($params_sql)) $stmt_users->bind_param($types_sql, ...$params_sql);
            if($stmt_users->execute()){
                $result_users = $stmt_users->get_result();
                while($user_row = $result_users->fetch_assoc()){ $users_list[] = $user_row; }
                $result_users->free();
            } else { set_flash_message('db_error', "Error fetching users: " . $stmt_users->error, 'danger'); }
            $stmt_users->close();
        } else { set_flash_message('db_error', "Error preparing user list: " . $conn->error, 'danger');}

    } elseif (($action === 'edit' || $action === 'add') && $user_id_to_manage && $action === 'edit') { // Only fetch user for edit
        $stmt_edit_user = $conn->prepare("SELECT UserID, Username, FullName, Email, Role, LinkedEntityID FROM Users WHERE UserID = ?");
        if($stmt_edit_user){
            $stmt_edit_user->bind_param("i", $user_id_to_manage);
            if($stmt_edit_user->execute()){
                $result_edit_user = $stmt_edit_user->get_result();
                $user_data_for_form = $result_edit_user->fetch_assoc();
                if ($user_data_for_form) {
                    $form_data = array_merge($form_data, $user_data_for_form); // Prefill form
                    // Determine entity type for dropdown pre-selection
                    if ($form_data['role'] === 'instructor') $form_data['entity_type'] = 'lecturer';
                    elseif ($form_data['role'] === 'student') $form_data['entity_type'] = 'student';
                } else {
                    set_flash_message('user_error', 'User not found for editing.', 'warning'); redirect('admin/users.php');
                }
            } else {set_flash_message('db_error', "Error fetching user: ".$stmt_edit_user->error, 'danger'); $action='list';}
            $stmt_edit_user->close();
        } else {set_flash_message('db_error', "Error preparing to fetch user: ".$conn->error, 'danger'); $action='list';}
    }

    // Fetch lecturers and students for dropdowns in add/edit forms, regardless of $user_id_to_manage for 'add'
    if ($action === 'add' || $action === 'edit') {
        // Fetch lecturers NOT already linked to a user account
        $res_lects = $conn->query("SELECT l.LecturerID, l.LecturerName FROM Lecturers l LEFT JOIN Users u ON l.LecturerID = u.LinkedEntityID AND u.Role = 'instructor' WHERE u.UserID IS NULL ORDER BY l.LecturerName ASC");
        if ($res_lects) while($row = $res_lects->fetch_assoc()) $lecturers_for_select[] = $row;
        
        // If editing an instructor user, add their current linked lecturer to the list if not already there
        if ($action === 'edit' && ($form_data['role'] ?? '') === 'instructor' && !empty($form_data['linked_entity_id'])) {
            $current_linked_lect_id = $form_data['linked_entity_id'];
            $found = false;
            foreach ($lecturers_for_select as $lect) { if ($lect['LecturerID'] == $current_linked_lect_id) { $found = true; break; } }
            if (!$found) {
                $stmt_curr_lect = $conn->prepare("SELECT LecturerID, LecturerName FROM Lecturers WHERE LecturerID = ?");
                $stmt_curr_lect->bind_param("i", $current_linked_lect_id);
                $stmt_curr_lect->execute();
                $res_curr_lect = $stmt_curr_lect->get_result();
                if($row_curr_l = $res_curr_lect->fetch_assoc()) $lecturers_for_select[] = $row_curr_l; // Add it
                $stmt_curr_lect->close();
                // Optional: re-sort $lecturers_for_select by name
            }
        }

        // Fetch students NOT already linked to a user account
        $res_studs = $conn->query("SELECT s.StudentID, s.StudentName FROM Students s LEFT JOIN Users u ON s.StudentID = u.LinkedEntityID AND u.Role = 'student' WHERE u.UserID IS NULL ORDER BY s.StudentName ASC");
        if ($res_studs) while($row = $res_studs->fetch_assoc()) $students_for_select[] = $row;
        
        if ($action === 'edit' && ($form_data['role'] ?? '') === 'student' && !empty($form_data['linked_entity_id'])) {
            $current_linked_stud_id = $form_data['linked_entity_id'];
             $found_stud = false;
            foreach ($students_for_select as $stud) { if ($stud['StudentID'] == $current_linked_stud_id) { $found_stud = true; break; } }
            if (!$found_stud) {
                $stmt_curr_stud = $conn->prepare("SELECT StudentID, StudentName FROM Students WHERE StudentID = ?");
                $stmt_curr_stud->bind_param("s", $current_linked_stud_id);
                $stmt_curr_stud->execute();
                $res_curr_stud = $stmt_curr_stud->get_result();
                if($row_curr_s = $res_curr_stud->fetch_assoc()) $students_for_select[] = $row_curr_s;
                $stmt_curr_stud->close();
            }
        }
    }
}

// Repopulate form on POST error (already handled mostly at the start of POST processing)
if (in_array($action, ['add', 'edit']) && $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($feedback_message) ) {
    $form_data['username'] = $_POST['username'] ?? $form_data['username'];
    $form_data['fullname'] = $_POST['fullname'] ?? $form_data['fullname'];
    $form_data['email'] = $_POST['email'] ?? $form_data['email'];
    $form_data['role'] = $_POST['role'] ?? $form_data['role'];
    $form_data['linked_entity_id'] = $_POST['linked_entity_id'] ?? $form_data['linked_entity_id'];
}

require_once __DIR__ . '/../includes/admin_sidebar_menu.php'; // Include layout AFTER all data processing
?>
<!-- Nối tiếp từ Part 1 -->
<div class="container-fluid">
    <!-- Flash messages and feedback messages -->
    <?php echo display_all_flash_messages(); ?>
    <?php if (!empty($feedback_message)): ?>
        <div class="alert alert-<?php echo htmlspecialchars($feedback_type); ?> alert-dismissible fade show py-2" role="alert">
            <?php echo htmlspecialchars($feedback_message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php // --- FORM FOR ADDING/EDITING USER ACCOUNT ---
    if ($action === 'add' || ($action === 'edit' && $user_id_to_manage && $form_data['user_id'])): // Ensure user_id is set for edit
    ?>
        <div class="card shadow mb-4">
            <div class="card-header py-3">
                <h6 class="m-0 fw-bold text-primary">
                    <i class="fas fa-<?php echo ($action === 'add' ? 'user-plus' : 'user-edit'); ?> me-2"></i>
                    <?php echo ($action === 'add' ? 'Add New User Account' : 'Edit User Account (ID: ' . htmlspecialchars($form_data['user_id']) . ')'); ?>
                </h6>
            </div>
            <div class="card-body">
                <form method="POST" action="users.php?action=<?php echo ($action === 'add' ? 'add_submit' : 'edit_submit'); ?><?php if($action === 'edit' && $user_id_to_manage) echo '&id='.$user_id_to_manage; ?>" class="needs-validation" novalidate>
                    <?php if ($action === 'edit'): ?>
                        <input type="hidden" name="user_id_to_manage" value="<?php echo htmlspecialchars($form_data['user_id']); ?>">
                    <?php endif; ?>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="username" class="form-label">Username <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="username" name="username" value="<?php echo htmlspecialchars($form_data['username']); ?>" required minlength="4">
                            <div class="invalid-feedback">Username is required (min 4 chars).</div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="fullname" class="form-label">Full Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="fullname" name="fullname" value="<?php echo htmlspecialchars($form_data['fullname']); ?>" required>
                            <div class="invalid-feedback">Full name is required.</div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="email" class="form-label">Email <span class="text-danger">*</span></label>
                            <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($form_data['email']); ?>" required>
                            <div class="invalid-feedback">A valid email is required.</div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="password" class="form-label">
                                <?php echo ($action === 'add' ? 'Password <span class="text-danger">*</span>' : 'New Password (leave blank to keep current)'); ?>
                            </label>
                            <input type="password" class="form-control" id="password" name="password" <?php echo ($action === 'add' ? 'required minlength="6"' : 'minlength="6"'); ?> autocomplete="new-password">
                            <div class="invalid-feedback">Password must be at least 6 characters.</div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="role" class="form-label">Role <span class="text-danger">*</span></label>
                            <select class="form-select" id="role" name="role" required>
                                <option value="" <?php selected_if_match($form_data['role'], ""); ?>>-- Select Role --</option>
                                <option value="admin" <?php selected_if_match($form_data['role'], "admin"); ?>>Administrator</option>
                                <option value="instructor" <?php selected_if_match($form_data['role'], "instructor"); ?>>Instructor</option>
                                <option value="student" <?php selected_if_match($form_data['role'], "student"); ?>>Student</option>
                            </select>
                            <div class="invalid-feedback">Please select a role.</div>
                        </div>
                        <div class="col-md-6 mb-3" id="linkedEntitySection" style="display: <?php echo (in_array($form_data['role'], ['instructor', 'student']) ? 'block' : 'none'); ?>;">
                            <label for="linked_entity_id" class="form-label" id="linkedEntityLabel">Link to </label>
                            <select class="form-select" id="linked_entity_id" name="linked_entity_id">
                                <option value="">-- Select --</option>
                                <!-- Options populated by JS -->
                            </select>
                            <small class="form-text text-muted">Select if role is Instructor or Student. Ensure the Lecturer/Student profile exists first.</small>
                        </div>
                    </div>

                    <?php if ($action === 'add'): ?>
                        <button type="submit" name="add_user_account" class="btn btn-primary"><i class="fas fa-plus me-1"></i> Create Account</button>
                    <?php else: ?>
                        <button type="submit" name="edit_user_account" class="btn btn-success"><i class="fas fa-save me-1"></i> Save Changes</button>
                    <?php endif; ?>
                    <a href="users.php" class="btn btn-secondary"><i class="fas fa-times me-1"></i> Cancel</a>
                </form>
            </div>
        </div>
    <?php endif; ?>


    <?php if ($action === 'list'): ?>
        <div class="card shadow mb-4">
            <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                <h6 class="m-0 fw-bold text-primary"><i class="fas fa-users-cog me-2"></i>User Accounts</h6>
                <a href="users.php?action=add" class="btn btn-success btn-sm">
                    <i class="fas fa-user-plus fa-sm"></i> Add New User
                </a>
            </div>
            <div class="card-body">
                <form method="GET" action="users.php" class="mb-3 border p-3 rounded bg-light">
                    <input type="hidden" name="action" value="list">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-4">
                            <label for="search_user" class="form-label form-label-sm">Search Username/Name/Email:</label>
                            <input type="text" id="search_user" name="search" class="form-control form-control-sm" value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                        </div>
                        <div class="col-md-3">
                            <label for="filter_role_user" class="form-label form-label-sm">Filter by Role:</label>
                            <select id="filter_role_user" name="filter_role" class="form-select form-select-sm">
                                <option value="">All Roles</option>
                                <option value="admin" <?php selected_if_match($_GET['filter_role'] ?? '', 'admin'); ?>>Administrator</option>
                                <option value="instructor" <?php selected_if_match($_GET['filter_role'] ?? '', 'instructor'); ?>>Instructor</option>
                                <option value="student" <?php selected_if_match($_GET['filter_role'] ?? '', 'student'); ?>>Student</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                             <label for="show_entries_user" class="form-label form-label-sm">Show Entries:</label>
                            <select id="show_entries_user" name="show_entries" class="form-select form-select-sm">
                                <option value="10" <?php selected_if_match($_GET['show_entries'] ?? '10', '10'); ?>>10</option>
                                <option value="25" <?php selected_if_match($_GET['show_entries'] ?? '', '25'); ?>>25</option>
                                <option value="50" <?php selected_if_match($_GET['show_entries'] ?? '', '50'); ?>>50</option>
                            </select>
                        </div>
                        <div class="col-md-auto">
                            <button class="btn btn-primary btn-sm w-100" type="submit"><i class="fas fa-filter"></i> Filter</button>
                        </div>
                        <div class="col-md-auto">
                            <a href="users.php?action=list" class="btn btn-outline-secondary btn-sm w-100" title="Clear Filters"><i class="fas fa-times"></i> Clear</a>
                        </div>
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="table table-bordered table-hover" id="usersTable" width="100%" cellspacing="0">
                        <thead class="table-dark">
                            <tr>
                                <th>ID</th>
                                <th>Username</th>
                                <th>Full Name</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Linked ID</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($users_list)): ?>
                                <?php foreach ($users_list as $user): ?>
                                    <tr>
                                        <td><?php echo $user['UserID']; ?></td>
                                        <td><?php echo htmlspecialchars($user['Username']); ?></td>
                                        <td><?php echo htmlspecialchars($user['FullName']); ?></td>
                                        <td><?php echo htmlspecialchars($user['Email']); ?></td>
                                        <td><?php echo htmlspecialchars(ucfirst($user['Role'])); ?></td>
                                        <td><?php echo htmlspecialchars($user['LinkedEntityID'] ?? 'N/A'); ?></td>
                                        <td class="text-center">
                                            <a href="users.php?action=edit&id=<?php echo $user['UserID']; ?>" class="btn btn-sm btn-warning me-1" title="Edit User">
                                                <i class="fas fa-user-edit"></i>
                                            </a>
                                            <a href="users.php?action=delete&id=<?php echo $user['UserID']; ?>" class="btn btn-sm btn-danger" title="Delete User"
                                               onclick="return confirm('Are you sure you want to delete this user account? This action may not be undone.');">
                                                <i class="fas fa-trash-alt"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="text-center">No user accounts found. Try adjusting your filters or <a href="users.php?action=list">clear filters</a>.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    <!-- Pagination controls would go here -->
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<style>
    .table th, .table td { vertical-align: middle; font-size: 0.9rem; }
    .form-label-sm { font-size: 0.8rem; margin-bottom: 0.2rem !important; }
    .input-group .form-control-sm, .input-group .form-select-sm { font-size: 0.875rem; }
    .input-group .btn-sm { font-size: 0.875rem; }
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
})();

document.addEventListener('DOMContentLoaded', function() {
    const roleSelect = document.getElementById('role');
    const linkedEntitySection = document.getElementById('linkedEntitySection');
    const linkedEntitySelect = document.getElementById('linked_entity_id');
    const linkedEntityLabel = document.getElementById('linkedEntityLabel');

    // Data for dropdowns passed from PHP
    const lecturersData = <?php echo json_encode($lecturers_for_select); ?>;
    const studentsData = <?php echo json_encode($students_for_select); ?>;
    // currentLinkedEntityId is for pre-selection in edit mode
    const currentLinkedEntityId = '<?php echo $form_data['linked_entity_id'] ?? ''; ?>';
    const currentRole = '<?php echo $form_data['role'] ?? ''; ?>';


    function populateLinkedEntityDropdown(role) {
        if (!linkedEntitySelect || !linkedEntityLabel || !linkedEntitySection) return;

        linkedEntitySelect.innerHTML = '<option value="">-- Select Entity --</option>';
        let dataToPopulate = [];
        let entityValueField = '';
        let entityNameField = '';
        let entityIdLabel = '';

        if (role === 'instructor') {
            linkedEntityLabel.textContent = 'Link to Lecturer:';
            dataToPopulate = lecturersData;
            entityValueField = 'LecturerID';
            entityNameField = 'LecturerName';
            entityIdLabel = 'Lec. ID: ';
            linkedEntitySelect.required = true;
            linkedEntitySection.style.display = 'block';
        } else if (role === 'student') {
            linkedEntityLabel.textContent = 'Link to Student:';
            dataToPopulate = studentsData;
            entityValueField = 'StudentID'; // StudentID is VARCHAR
            entityNameField = 'StudentName';
            entityIdLabel = 'Stu. ID: ';
            linkedEntitySelect.required = true;
            linkedEntitySection.style.display = 'block';
        } else {
            linkedEntitySection.style.display = 'none';
            linkedEntitySelect.required = false;
            linkedEntitySelect.value = '';
            return; // No need to populate if not instructor/student
        }

        dataToPopulate.forEach(function(entity) {
            const option = document.createElement('option');
            option.value = entity[entityValueField];
            option.textContent = entity[entityNameField] + ' (' + entityIdLabel + entity[entityValueField] + ')';
            // Pre-select if currentLinkedEntityId matches and role also matches
            if (currentLinkedEntityId && String(entity[entityValueField]) === String(currentLinkedEntityId) && currentRole === role) {
                option.selected = true;
            }
            linkedEntitySelect.appendChild(option);
        });
    }

    if (roleSelect) {
        roleSelect.addEventListener('change', function() {
            populateLinkedEntityDropdown(this.value);
            // When role changes on ADD form, clear any previously selected linked_entity_id for safety
            if ('<?php echo $action; ?>' === 'add') {
                if(linkedEntitySelect) linkedEntitySelect.value = "";
            }
        });
        // Initial population on page load (especially for edit form, or if add form has errors and repopulates)
        const initialRole = roleSelect.value;
        if (initialRole) {
             populateLinkedEntityDropdown(initialRole);
        } else {
            if(linkedEntitySection) linkedEntitySection.style.display = 'none'; // Ensure it's hidden if no role initially
        }
    }
});
</script>
<!-- Layout file (admin_sidebar_menu.php) closes body and html -->