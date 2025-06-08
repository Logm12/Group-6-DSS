<?php
// htdocs/DSS/admin/students.php

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db_connect.php'; // $conn

require_role(['admin'], '../login.php');

$page_title = "Manage Students";

$action = $_GET['action'] ?? 'list';
$student_id_to_manage = isset($_GET['id']) ? sanitize_input($_GET['id']) : null; // StudentID is VARCHAR

$feedback_message = '';
$feedback_type = '';

// Form data prefill and for errors
$form_data = [
    'StudentID' => '',
    'StudentName' => '',
    'Email' => '',
    'Program' => ''
];

// --- Process POST requests for add/edit ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_student'])) {
        $action = 'add_submit';
    } elseif (isset($_POST['edit_student']) && !empty($_POST['original_student_id'])) {
        $action = 'edit_submit';
        $student_id_to_manage = sanitize_input($_POST['original_student_id']);
    }
}

if ($conn) {
    switch ($action) {
        case 'add_submit':
            $student_id_new = trim($_POST['StudentID'] ?? '');
            $student_name_new = trim($_POST['StudentName'] ?? '');
            $email_new = trim($_POST['Email'] ?? '');
            $program_new = trim($_POST['Program'] ?? '');

            $form_data = $_POST; // Repopulate

            if (empty($student_id_new) || empty($student_name_new) || empty($email_new)) {
                $feedback_message = "Student ID, Full Name, and Email are required.";
                $feedback_type = "danger"; $action = 'add';
            } elseif (!filter_var($email_new, FILTER_VALIDATE_EMAIL)) {
                $feedback_message = "Invalid email format.";
                $feedback_type = "danger"; $action = 'add';
            } else {
                // Check if StudentID or Email already exists
                $stmt_check = $conn->prepare("SELECT StudentID FROM Students WHERE StudentID = ? OR Email = ?");
                if (!$stmt_check) throw new Exception("DB Error (check student): " . $conn->error);
                $stmt_check->bind_param("ss", $student_id_new, $email_new);
                $stmt_check->execute();
                $result_check = $stmt_check->get_result();
                if ($result_check->num_rows > 0) {
                    $existing = $result_check->fetch_assoc();
                    if ($existing['StudentID'] == $student_id_new) {
                        $feedback_message = "Student ID '{$student_id_new}' already exists.";
                    } else {
                        $feedback_message = "Email '{$email_new}' is already registered for another student.";
                    }
                    $feedback_type = "danger"; $action = 'add';
                }
                $stmt_check->close();

                if ($action !== 'add') { // Proceed if unique
                    $stmt_insert = $conn->prepare("INSERT INTO Students (StudentID, StudentName, Email, Program) VALUES (?, ?, ?, ?)");
                    if (!$stmt_insert) throw new Exception("DB Error (insert student): " . $conn->error);
                    $stmt_insert->bind_param("ssss", $student_id_new, $student_name_new, $email_new, $program_new);

                    if ($stmt_insert->execute()) {
                        set_flash_message('student_success', "Student '{$student_name_new}' added successfully.", 'success');
                        redirect('admin/students.php');
                    } else {
                        $feedback_message = "Error adding student: " . $stmt_insert->error;
                        $feedback_type = "danger"; $action = 'add';
                    }
                    $stmt_insert->close();
                }
            }
            break;

        case 'edit_submit':
            if ($student_id_to_manage) { // This is original_student_id
                $student_id_edited = trim($_POST['StudentID'] ?? ''); // New StudentID from form
                $student_name_edited = trim($_POST['StudentName'] ?? '');
                $email_edited = trim($_POST['Email'] ?? '');
                $program_edited = trim($_POST['Program'] ?? '');

                $form_data = $_POST; // Repopulate
                $form_data['original_student_id'] = $student_id_to_manage;


                if (empty($student_id_edited) || empty($student_name_edited) || empty($email_edited)) {
                    $feedback_message = "Student ID, Full Name, and Email are required.";
                    $feedback_type = "danger"; $action = 'edit';
                } elseif (!filter_var($email_edited, FILTER_VALIDATE_EMAIL)) {
                    $feedback_message = "Invalid email format.";
                    $feedback_type = "danger"; $action = 'edit';
                } else {
                    // Check if new StudentID or Email conflicts with ANOTHER student
                    if ($student_id_edited !== $student_id_to_manage) { // If StudentID was changed
                        $stmt_check_id = $conn->prepare("SELECT StudentID FROM Students WHERE StudentID = ?");
                        $stmt_check_id->bind_param("s", $student_id_edited);
                        $stmt_check_id->execute();
                        if ($stmt_check_id->get_result()->num_rows > 0) {
                            $feedback_message = "The new Student ID '{$student_id_edited}' already exists.";
                            $feedback_type = "danger"; $action = 'edit';
                        }
                        $stmt_check_id->close();
                    }
                    if ($action !== 'edit') { // Continue if ID is fine
                        $stmt_check_email = $conn->prepare("SELECT StudentID FROM Students WHERE Email = ? AND StudentID != ?");
                        $stmt_check_email->bind_param("ss", $email_edited, $student_id_to_manage); // Check email against others
                        $stmt_check_email->execute();
                        if ($stmt_check_email->get_result()->num_rows > 0) {
                            $feedback_message = "The new Email '{$email_edited}' is already used by another student.";
                            $feedback_type = "danger"; $action = 'edit';
                        }
                        $stmt_check_email->close();
                    }

                    if ($action !== 'edit') { // Proceed if no validation errors
                        $stmt_update = $conn->prepare("UPDATE Students SET StudentID = ?, StudentName = ?, Email = ?, Program = ? WHERE StudentID = ?");
                        if (!$stmt_update) throw new Exception("DB Error (update student): " . $conn->error);
                        // Bind new values, and original StudentID for the WHERE clause
                        $stmt_update->bind_param("sssss", $student_id_edited, $student_name_edited, $email_edited, $program_edited, $student_id_to_manage);

                        if ($stmt_update->execute()) {
                            set_flash_message('student_success', "Student '{$student_name_edited}' updated successfully.", 'success');
                            redirect('admin/students.php');
                        } else {
                            $feedback_message = "Error updating student: " . $stmt_update->error;
                            $feedback_type = "danger"; $action = 'edit';
                        }
                        $stmt_update->close();
                    }
                }
            } else {
                set_flash_message('student_error', 'Invalid student for editing.', 'danger');
                redirect('admin/students.php');
            }
            break;

        case 'delete':
            if ($student_id_to_manage) {
                $stmt_delete = $conn->prepare("DELETE FROM Students WHERE StudentID = ?");
                if ($stmt_delete) {
                    $stmt_delete->bind_param("s", $student_id_to_manage);
                    if ($stmt_delete->execute()) {
                        if ($stmt_delete->affected_rows > 0) {
                            set_flash_message('student_success', 'Student deleted successfully.', 'success');
                        } else {
                            set_flash_message('student_error', 'Student not found or already deleted.', 'warning');
                        }
                    } else {
                         if ($conn->errno == 1451) { // Foreign key constraint (e.g., student has enrollments or user account)
                             set_flash_message('student_error', 'Cannot delete student: They have existing enrollments or a user account. Please remove those associations first.', 'danger');
                        } else {
                            set_flash_message('student_error', 'Error deleting student: ' . $stmt_delete->error, 'danger');
                        }
                    }
                    $stmt_delete->close();
                } else {set_flash_message('db_error', 'DB error preparing delete for student.', 'danger');}
            } else { set_flash_message('student_error', 'Invalid Student ID for deletion.', 'danger'); }
            redirect('admin/students.php');
            break;
    } // End switch $action
} elseif (!$conn && $action !== 'list') {
    $feedback_message = "Database connection error."; $feedback_type = "danger";
}

// Data for display
$students_list = [];
$student_to_edit = null;
$programs_for_filter_dropdown = [];

if ($conn) {
    // Fetch distinct programs for the filter dropdown
    $res_programs = $conn->query("SELECT DISTINCT Program FROM Students WHERE Program IS NOT NULL AND Program != '' ORDER BY Program ASC");
    if ($res_programs) {
        while($row_p = $res_programs->fetch_assoc()) $programs_for_filter_dropdown[] = $row_p['Program'];
        $res_programs->free();
    }

    if ($action === 'list') {
        $search_keyword_list = isset($_GET['search']) ? trim($_GET['search']) : '';
        $filter_program_list = isset($_GET['program']) ? trim($_GET['program']) : '';

        $sql_list_stud = "SELECT StudentID, StudentName, Email, Program FROM Students";
        $conditions_stud = []; $params_stud = []; $types_stud = "";

        if (!empty($search_keyword_list)) {
            $conditions_stud[] = "(StudentID LIKE ? OR StudentName LIKE ? OR Email LIKE ?)";
            $like_s = "%" . $search_keyword_list . "%";
            array_push($params_stud, $like_s, $like_s, $like_s);
            $types_stud .= "sss";
        }
        if (!empty($filter_program_list)) {
            $conditions_stud[] = "Program = ?";
            $params_stud[] = $filter_program_list;
            $types_stud .= "s";
        }
        if (!empty($conditions_stud)) $sql_list_stud .= " WHERE " . implode(" AND ", $conditions_stud);
        $sql_list_stud .= " ORDER BY StudentName ASC";

        $stmt_list_stud = $conn->prepare($sql_list_stud);
        if ($stmt_list_stud) {
            if (!empty($params_stud)) $stmt_list_stud->bind_param($types_stud, ...$params_stud);
            if($stmt_list_stud->execute()){
                $result_list_stud = $stmt_list_stud->get_result();
                while($row_stud = $result_list_stud->fetch_assoc()) $students_list[] = $row_stud;
                $result_list_stud->free();
            } else { set_flash_message('db_error', "Error fetching students: " . $stmt_list_stud->error, 'danger'); }
            $stmt_list_stud->close();
        } else { set_flash_message('db_error', "Error preparing student list: " . $conn->error, 'danger');}

    } elseif ($action === 'edit' && $student_id_to_manage) {
        $stmt_edit_data = $conn->prepare("SELECT StudentID, StudentName, Email, Program FROM Students WHERE StudentID = ?");
        if($stmt_edit_data){
            $stmt_edit_data->bind_param("s", $student_id_to_manage);
            if($stmt_edit_data->execute()){
                $result_edit = $stmt_edit_data->get_result();
                $student_to_edit = $result_edit->fetch_assoc();
                if (!$student_to_edit) {
                    set_flash_message('student_error', 'Student not found for editing.', 'warning'); redirect('admin/students.php');
                } else {
                    $form_data = array_merge($form_data, $student_to_edit); // Prefill form
                    $form_data['original_student_id'] = $student_to_edit['StudentID']; // Keep original ID reference
                }
            } else {set_flash_message('db_error', "Error fetching student: ".$stmt_edit_data->error, 'danger'); $action='list';}
            $stmt_edit_data->close();
        } else {set_flash_message('db_error', "Error preparing to fetch student: ".$conn->error, 'danger'); $action='list';}
    }
}

// Repopulate form on POST error
if (in_array($action, ['add', 'edit']) && $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($feedback_message) ) {
    $form_data = array_merge($form_data, $_POST);
    if ($action === 'edit' && $student_id_to_manage) {
        $form_data['original_student_id'] = $student_id_to_manage;
    }
}

require_once __DIR__ . '/../includes/admin_sidebar_menu.php';
?>
<!-- Nối tiếp từ Part 1 -->
<div class="container-fluid">
    <?php echo display_all_flash_messages(); ?>
    <?php if (!empty($feedback_message)): ?>
        <div class="alert alert-<?php echo htmlspecialchars($feedback_type); ?> alert-dismissible fade show py-2" role="alert">
            <?php echo htmlspecialchars($feedback_message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php // --- FORM FOR ADDING/EDITING STUDENT ---
    if ($action === 'add' || ($action === 'edit' && $student_id_to_manage && $form_data['StudentID'])):
    ?>
        <div class="card shadow mb-4">
            <div class="card-header py-3">
                <h6 class="m-0 fw-bold text-primary">
                    <i class="fas fa-<?php echo ($action === 'add' ? 'user-plus' : 'user-edit'); ?> me-2"></i>
                    <?php echo ($action === 'add' ? 'Add New Student' : 'Edit Student: ' . htmlspecialchars($form_data['StudentName'])); ?>
                </h6>
            </div>
            <div class="card-body">
                <form method="POST" action="students.php?action=<?php echo ($action === 'add' ? 'add_submit' : 'edit_submit'); ?>" class="needs-validation" novalidate>
                    <?php if ($action === 'edit'): ?>
                        <input type="hidden" name="original_student_id" value="<?php echo htmlspecialchars($form_data['StudentID']); ?>">
                    <?php endif; ?>

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="StudentID" class="form-label">Student ID <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="StudentID" name="StudentID"
                                   value="<?php echo htmlspecialchars($form_data['StudentID']); ?>"
                                   <?php echo ($action === 'edit' ? '' : ''); // Consider readonly for StudentID on edit if it should not be changed ?>
                                   required maxlength="20">
                            <div class="invalid-feedback">Student ID is required.</div>
                        </div>
                        <div class="col-md-8 mb-3">
                            <label for="StudentName" class="form-label">Full Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="StudentName" name="StudentName"
                                   value="<?php echo htmlspecialchars($form_data['StudentName']); ?>" required maxlength="100">
                            <div class="invalid-feedback">Full Name is required.</div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="Email" class="form-label">Email Address <span class="text-danger">*</span></label>
                            <input type="email" class="form-control" id="Email" name="Email"
                                   value="<?php echo htmlspecialchars($form_data['Email']); ?>" required maxlength="100">
                            <div class="invalid-feedback">A valid email is required.</div>
                        </div>
                         <div class="col-md-6 mb-3">
                            <label for="Program" class="form-label">Program/Major</label>
                            <input type="text" class="form-control" id="Program" name="Program"
                                   value="<?php echo htmlspecialchars($form_data['Program']); ?>" maxlength="100" placeholder="E.g., Computer Science, Business Administration">
                        </div>
                    </div>

                    <?php if ($action === 'add'): ?>
                        <button type="submit" name="add_student" class="btn btn-primary"><i class="fas fa-plus me-1"></i> Add Student</button>
                    <?php else: ?>
                        <button type="submit" name="edit_student" class="btn btn-success"><i class="fas fa-save me-1"></i> Save Changes</button>
                    <?php endif; ?>
                    <a href="students.php" class="btn btn-secondary"><i class="fas fa-times me-1"></i> Cancel</a>
                </form>
            </div>
        </div>
    <?php endif; ?>


    <?php if ($action === 'list'): ?>
        <div class="card shadow mb-4">
            <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                <h6 class="m-0 fw-bold text-primary"><i class="fas fa-user-graduate me-2"></i>Students List</h6>
                <a href="students.php?action=add" class="btn btn-success btn-sm">
                    <i class="fas fa-user-plus fa-sm"></i> Add New Student
                </a>
            </div>
            <div class="card-body">
                <form method="GET" action="students.php" class="mb-3 border p-3 rounded bg-light">
                    <input type="hidden" name="action" value="list">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-5">
                             <label for="search_student" class="form-label form-label-sm">Search ID/Name/Email:</label>
                            <input type="text" id="search_student" name="search" class="form-control form-control-sm" value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                        </div>
                        <div class="col-md-4">
                            <label for="filter_program" class="form-label form-label-sm">Filter by Program:</label>
                            <select id="filter_program" name="program" class="form-select form-select-sm">
                                <option value="">-- All Programs --</option>
                                <?php foreach ($programs_for_filter_dropdown as $prog): ?>
                                    <option value="<?php echo htmlspecialchars($prog); ?>" <?php selected_if_match($_GET['program'] ?? '', $prog); ?>>
                                        <?php echo htmlspecialchars($prog); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-auto">
                            <button class="btn btn-primary btn-sm w-100" type="submit"><i class="fas fa-filter"></i> Filter</button>
                        </div>
                         <div class="col-md-auto">
                            <a href="students.php?action=list" class="btn btn-outline-secondary btn-sm w-100" title="Clear Filters"><i class="fas fa-times"></i> Clear</a>
                        </div>
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="table table-bordered table-hover" id="studentsTable" width="100%" cellspacing="0">
                        <thead class="table-dark">
                            <tr>
                                <th>Student ID</th>
                                <th>Full Name</th>
                                <th>Email</th>
                                <th>Program</th>
                                <th class="text-center" style="width: 12%;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($students_list)): ?>
                                <?php foreach ($students_list as $student): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($student['StudentID']); ?></td>
                                        <td><?php echo htmlspecialchars($student['StudentName']); ?></td>
                                        <td><?php echo htmlspecialchars($student['Email']); ?></td>
                                        <td><?php echo htmlspecialchars($student['Program'] ?: 'N/A'); ?></td>
                                        <td class="text-center">
                                            <a href="students.php?action=edit&id=<?php echo urlencode($student['StudentID']); ?>" class="btn btn-sm btn-warning me-1" title="Edit Student">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <a href="students.php?action=delete&id=<?php echo urlencode($student['StudentID']); ?>" class="btn btn-sm btn-danger" title="Delete Student"
                                               onclick="return confirm('Are you sure you want to delete this student? This may affect enrollments and user accounts.');">
                                                <i class="fas fa-trash-alt"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="text-center">No students found.</td>
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
    .table th, .table td { vertical-align: middle; font-size: 0.9rem; }
    .form-label-sm { font-size: 0.8rem; margin-bottom: 0.2rem !important; }
</style>

<script>
// Bootstrap 5 form validation script
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