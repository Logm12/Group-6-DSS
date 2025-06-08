<?php
// htdocs/DSS/admin/courses.php

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db_connect.php'; // $conn

require_role(['admin'], '../login.php'); // Only admins

$page_title = "Manage Courses";

$action = $_GET['action'] ?? 'list'; // Default action
$course_id_to_manage = isset($_GET['id']) ? sanitize_input($_GET['id']) : null; // CourseID is VARCHAR

$feedback_message = '';
$feedback_type = '';

// Form data prefill and error handling
$form_data = [
    'CourseID' => '',
    'CourseName' => '',
    'Credits' => '',
    'ExpectedStudents' => '',
    'SessionDurationSlots' => 1, // Default to 1 as per previous discussions
    'MajorCategory' => ''
];

// --- Process POST requests for add/edit ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_course'])) {
        $action = 'add_submit';
    } elseif (isset($_POST['edit_course']) && !empty($_POST['original_course_id'])) { // Use a hidden field for original ID on edit
        $action = 'edit_submit';
        $course_id_to_manage = sanitize_input($_POST['original_course_id']); // Ensure we are editing the correct course
    }
}

if ($conn) {
    switch ($action) {
        case 'add_submit':
            $course_id_new = trim($_POST['CourseID'] ?? '');
            $course_name_new = trim($_POST['CourseName'] ?? '');
            $credits_new = isset($_POST['Credits']) && is_numeric($_POST['Credits']) ? (int)$_POST['Credits'] : null;
            $expected_students_new = isset($_POST['ExpectedStudents']) && is_numeric($_POST['ExpectedStudents']) ? (int)$_POST['ExpectedStudents'] : null;
            $session_duration_new = isset($_POST['SessionDurationSlots']) && is_numeric($_POST['SessionDurationSlots']) ? (int)$_POST['SessionDurationSlots'] : 1;
            $major_category_new = trim($_POST['MajorCategory'] ?? '');

            // Repopulate form data
            $form_data = $_POST;

            if (empty($course_id_new) || empty($course_name_new) || $credits_new === null || $credits_new < 0 || $expected_students_new === null || $expected_students_new < 0) {
                $feedback_message = "Course ID, Name, valid Credits, and valid Expected Students are required.";
                $feedback_type = "danger"; $action = 'add';
            } else {
                $stmt_check = $conn->prepare("SELECT CourseID FROM Courses WHERE CourseID = ?");
                if (!$stmt_check) throw new Exception("DB Error (check course ID): " . $conn->error);
                $stmt_check->bind_param("s", $course_id_new);
                $stmt_check->execute();
                if ($stmt_check->get_result()->num_rows > 0) {
                    $feedback_message = "Course ID '{$course_id_new}' already exists.";
                    $feedback_type = "danger"; $action = 'add';
                }
                $stmt_check->close();

                if ($action !== 'add') { // Proceed if CourseID is unique
                    $stmt_insert = $conn->prepare("INSERT INTO Courses (CourseID, CourseName, Credits, ExpectedStudents, SessionDurationSlots, MajorCategory) VALUES (?, ?, ?, ?, ?, ?)");
                    if (!$stmt_insert) throw new Exception("DB Error (insert course): " . $conn->error);
                    $stmt_insert->bind_param("ssiiis", $course_id_new, $course_name_new, $credits_new, $expected_students_new, $session_duration_new, $major_category_new);

                    if ($stmt_insert->execute()) {
                        set_flash_message('course_success', "Course '{$course_name_new}' added successfully.", 'success');
                        redirect('admin/courses.php');
                    } else {
                        $feedback_message = "Error adding course: " . $stmt_insert->error;
                        $feedback_type = "danger"; $action = 'add';
                    }
                    $stmt_insert->close();
                }
            }
            break;

        case 'edit_submit':
            if ($course_id_to_manage) { // This is original_course_id from hidden field
                $course_id_edited = trim($_POST['CourseID'] ?? ''); // New CourseID from form (if allowed to change)
                $course_name_edited = trim($_POST['CourseName'] ?? '');
                $credits_edited = isset($_POST['Credits']) && is_numeric($_POST['Credits']) ? (int)$_POST['Credits'] : null;
                $expected_students_edited = isset($_POST['ExpectedStudents']) && is_numeric($_POST['ExpectedStudents']) ? (int)$_POST['ExpectedStudents'] : null;
                $session_duration_edited = isset($_POST['SessionDurationSlots']) && is_numeric($_POST['SessionDurationSlots']) ? (int)$_POST['SessionDurationSlots'] : 1;
                $major_category_edited = trim($_POST['MajorCategory'] ?? '');

                $form_data = $_POST; // Repopulate
                $form_data['OriginalCourseID'] = $course_id_to_manage;


                if (empty($course_id_edited) || empty($course_name_edited) || $credits_edited === null || $credits_edited < 0 || $expected_students_edited === null || $expected_students_edited < 0) {
                    $feedback_message = "Course ID, Name, valid Credits, and valid Expected Students are required.";
                    $feedback_type = "danger"; $action = 'edit';
                } else {
                    // Check if new CourseID conflicts with another course (if ID was changed)
                    if ($course_id_edited !== $course_id_to_manage) {
                        $stmt_check_id_edit = $conn->prepare("SELECT CourseID FROM Courses WHERE CourseID = ?");
                        if (!$stmt_check_id_edit) throw new Exception("DB Error (check course ID edit): " . $conn->error);
                        $stmt_check_id_edit->bind_param("s", $course_id_edited);
                        $stmt_check_id_edit->execute();
                        if ($stmt_check_id_edit->get_result()->num_rows > 0) {
                            $feedback_message = "The new Course ID '{$course_id_edited}' already exists for another course.";
                            $feedback_type = "danger"; $action = 'edit';
                        }
                        $stmt_check_id_edit->close();
                    }

                    if ($action !== 'edit') { // Proceed if no validation errors
                        $stmt_update = $conn->prepare("UPDATE Courses SET CourseID = ?, CourseName = ?, Credits = ?, ExpectedStudents = ?, SessionDurationSlots = ?, MajorCategory = ? WHERE CourseID = ?");
                        if (!$stmt_update) throw new Exception("DB Error (update course): " . $conn->error);
                        // Use $course_id_to_manage (original) for the WHERE clause
                        $stmt_update->bind_param("ssiiiss", $course_id_edited, $course_name_edited, $credits_edited, $expected_students_edited, $session_duration_edited, $major_category_edited, $course_id_to_manage);

                        if ($stmt_update->execute()) {
                            set_flash_message('course_success', "Course '{$course_name_edited}' updated successfully.", 'success');
                            redirect('admin/courses.php');
                        } else {
                            $feedback_message = "Error updating course: " . $stmt_update->error;
                            $feedback_type = "danger"; $action = 'edit';
                        }
                        $stmt_update->close();
                    }
                }
            } else {
                set_flash_message('course_error', 'Invalid course for editing.', 'danger');
                redirect('admin/courses.php');
            }
            break;

        case 'delete':
            if ($course_id_to_manage) {
                $stmt_delete = $conn->prepare("DELETE FROM Courses WHERE CourseID = ?");
                if ($stmt_delete) {
                    $stmt_delete->bind_param("s", $course_id_to_manage);
                    if ($stmt_delete->execute()) {
                        if ($stmt_delete->affected_rows > 0) {
                            set_flash_message('course_success', 'Course deleted successfully.', 'success');
                        } else {
                            set_flash_message('course_error', 'Course not found or already deleted.', 'warning');
                        }
                    } else {
                        if ($conn->errno == 1451) { // Foreign key constraint violation
                             set_flash_message('course_error', 'Cannot delete course: It is currently used in schedules or enrollments. Please remove those associations first.', 'danger');
                        } else {
                            set_flash_message('course_error', 'Error deleting course: ' . $stmt_delete->error, 'danger');
                        }
                    }
                    $stmt_delete->close();
                } else {set_flash_message('db_error', 'DB error preparing delete.', 'danger');}
            } else { set_flash_message('course_error', 'Invalid Course ID for deletion.', 'danger'); }
            redirect('admin/courses.php'); // Always redirect to list view
            break;
    }
} elseif (!$conn && $action !== 'list') {
    $feedback_message = "Database connection error. Cannot perform action."; $feedback_type = "danger";
}

// Data for display
$courses_list = [];
$course_to_edit = null;
$existing_major_categories = []; // For dropdown in forms

if ($conn) {
    // Fetch distinct major categories for the form dropdown
    $res_majors = $conn->query("SELECT DISTINCT MajorCategory FROM Courses WHERE MajorCategory IS NOT NULL AND MajorCategory != '' ORDER BY MajorCategory ASC");
    if ($res_majors) {
        while($row_mj = $res_majors->fetch_assoc()) $existing_major_categories[] = $row_mj['MajorCategory'];
        $res_majors->free();
    }


    if ($action === 'list') {
        $search_term = isset($_GET['search']) ? trim($_GET['search']) : '';
        $sql_list = "SELECT CourseID, CourseName, Credits, ExpectedStudents, SessionDurationSlots, MajorCategory FROM Courses";
        $params = []; $types = "";
        if (!empty($search_term)) {
            $sql_list .= " WHERE CourseName LIKE ? OR CourseID LIKE ? OR MajorCategory LIKE ?";
            $like_search = "%" . $search_term . "%";
            array_push($params, $like_search, $like_search, $like_search);
            $types = "sss";
        }
        $sql_list .= " ORDER BY MajorCategory, CourseName ASC";
        $stmt_list = $conn->prepare($sql_list);
        if ($stmt_list) {
            if (!empty($params)) $stmt_list->bind_param($types, ...$params);
            if($stmt_list->execute()){
                $result_list = $stmt_list->get_result();
                while($row = $result_list->fetch_assoc()) $courses_list[] = $row;
                $result_list->free();
            } else { set_flash_message('db_error', "Error fetching courses: " . $stmt_list->error, 'danger'); }
            $stmt_list->close();
        } else { set_flash_message('db_error', "Error preparing course list: " . $conn->error, 'danger');}

    } elseif ($action === 'edit' && $course_id_to_manage) {
        $stmt_edit_data = $conn->prepare("SELECT CourseID, CourseName, Credits, ExpectedStudents, SessionDurationSlots, MajorCategory FROM Courses WHERE CourseID = ?");
        if($stmt_edit_data){
            $stmt_edit_data->bind_param("s", $course_id_to_manage);
            if($stmt_edit_data->execute()){
                $result_edit = $stmt_edit_data->get_result();
                $course_to_edit = $result_edit->fetch_assoc();
                if (!$course_to_edit) {
                    set_flash_message('course_error', 'Course not found for editing.', 'warning'); redirect('admin/courses.php');
                } else {
                    $form_data = array_merge($form_data, $course_to_edit); // Prefill form
                    $form_data['OriginalCourseID'] = $course_to_edit['CourseID']; // Store original ID for update
                }
            } else {set_flash_message('db_error', "Error fetching course: ".$stmt_edit_data->error, 'danger'); $action='list';}
            $stmt_edit_data->close();
        } else {set_flash_message('db_error', "Error preparing to fetch course: ".$conn->error, 'danger'); $action='list';}
    }
}

// Repopulate form on POST error
if (in_array($action, ['add', 'edit']) && $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($feedback_message) ) {
    $form_data = array_merge($form_data, $_POST); // Repopulate with submitted values
    if ($action === 'edit' && $course_id_to_manage) {
        $form_data['OriginalCourseID'] = $course_id_to_manage; // Ensure original ID is kept for edit form action
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

    <?php // --- FORM FOR ADDING/EDITING COURSE ---
    if ($action === 'add' || ($action === 'edit' && $course_id_to_manage && $form_data['CourseID'])): ?>
        <div class="card shadow mb-4">
            <div class="card-header py-3">
                <h6 class="m-0 fw-bold text-primary">
                    <i class="fas fa-<?php echo ($action === 'add' ? 'plus-circle' : 'edit'); ?> me-2"></i>
                    <?php echo ($action === 'add' ? 'Add New Course' : 'Edit Course: ' . htmlspecialchars($form_data['CourseName'])); ?>
                </h6>
            </div>
            <div class="card-body">
                <form method="POST" action="courses.php?action=<?php echo ($action === 'add' ? 'add_submit' : 'edit_submit'); ?>" class="needs-validation" novalidate>
                    <?php if ($action === 'edit'): ?>
                        <input type="hidden" name="original_course_id" value="<?php echo htmlspecialchars($form_data['CourseID']); ?>">
                    <?php endif; ?>

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="CourseID" class="form-label">Course ID <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="CourseID" name="CourseID"
                                   value="<?php echo htmlspecialchars($form_data['CourseID']); ?>" 
                                   <?php echo ($action === 'edit' ? '' : ''); // Consider making CourseID readonly on edit if it should not be changed, or handle PK change carefully ?>
                                   required maxlength="20">
                            <div class="invalid-feedback">Course ID is required.</div>
                        </div>
                        <div class="col-md-8 mb-3">
                            <label for="CourseName" class="form-label">Course Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="CourseName" name="CourseName"
                                   value="<?php echo htmlspecialchars($form_data['CourseName']); ?>" required maxlength="255">
                            <div class="invalid-feedback">Course Name is required.</div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="Credits" class="form-label">Credits <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="Credits" name="Credits"
                                   value="<?php echo htmlspecialchars($form_data['Credits']); ?>" required min="0" max="10">
                            <div class="invalid-feedback">Please enter valid credits (0-10).</div>
                        </div>
                         <div class="col-md-4 mb-3">
                            <label for="ExpectedStudents" class="form-label">Expected Students <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="ExpectedStudents" name="ExpectedStudents"
                                   value="<?php echo htmlspecialchars($form_data['ExpectedStudents']); ?>" required min="0" max="500">
                            <div class="invalid-feedback">Please enter a valid number of expected students.</div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="SessionDurationSlots" class="form-label">Session Duration (Slots) <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="SessionDurationSlots" name="SessionDurationSlots"
                                   value="<?php echo htmlspecialchars($form_data['SessionDurationSlots']); ?>" required min="1" max="5" title="Number of standard time slots this course session occupies. Usually 1.">
                            <div class="invalid-feedback">Must be at least 1.</div>
                        </div>
                    </div>
                     <div class="mb-3">
                        <label for="MajorCategory" class="form-label">Major Category</label>
                        <input list="major_categories_list" class="form-control" id="MajorCategory" name="MajorCategory"
                               value="<?php echo htmlspecialchars($form_data['MajorCategory']); ?>" maxlength="100" placeholder="E.g., Kinh tế, Công nghệ">
                        <datalist id="major_categories_list">
                            <?php foreach ($existing_major_categories as $category): ?>
                                <option value="<?php echo htmlspecialchars($category); ?>">
                            <?php endforeach; ?>
                        </datalist>
                        <small class="form-text text-muted">You can select an existing category or type a new one.</small>
                    </div>


                    <?php if ($action === 'add'): ?>
                        <button type="submit" name="add_course" class="btn btn-primary"><i class="fas fa-plus me-1"></i> Add Course</button>
                    <?php else: ?>
                        <button type="submit" name="edit_course" class="btn btn-success"><i class="fas fa-save me-1"></i> Save Changes</button>
                    <?php endif; ?>
                    <a href="courses.php" class="btn btn-secondary"><i class="fas fa-times me-1"></i> Cancel</a>
                </form>
            </div>
        </div>
    <?php endif; ?>


    <?php if ($action === 'list'): ?>
        <div class="card shadow mb-4">
            <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                <h6 class="m-0 fw-bold text-primary"><i class="fas fa-book-open me-2"></i>Courses List</h6>
                <a href="courses.php?action=add" class="btn btn-success btn-sm">
                    <i class="fas fa-plus fa-sm"></i> Add New Course
                </a>
            </div>
            <div class="card-body">
                <form method="GET" action="courses.php" class="mb-3 border p-3 rounded bg-light">
                    <input type="hidden" name="action" value="list">
                    <div class="input-group">
                        <input type="text" name="search" class="form-control form-control-sm" placeholder="Search by ID, Name, or Major..." value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                        <button class="btn btn-primary btn-sm" type="submit"><i class="fas fa-search"></i></button>
                         <?php if (!empty($_GET['search'])): ?>
                            <a href="courses.php?action=list" class="btn btn-outline-secondary btn-sm" title="Clear Search"><i class="fas fa-times"></i></a>
                        <?php endif; ?>
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="table table-bordered table-hover" id="coursesTable" width="100%" cellspacing="0">
                        <thead class="table-dark">
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Credits</th>
                                <th>Exp. Students</th>
                                <th>Slots/Session</th>
                                <th>Major Category</th>
                                <th class="text-center" style="width: 12%;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($courses_list)): ?>
                                <?php foreach ($courses_list as $course): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($course['CourseID']); ?></td>
                                        <td><?php echo htmlspecialchars($course['CourseName']); ?></td>
                                        <td><?php echo htmlspecialchars($course['Credits']); ?></td>
                                        <td><?php echo htmlspecialchars($course['ExpectedStudents']); ?></td>
                                        <td><?php echo htmlspecialchars($course['SessionDurationSlots']); ?></td>
                                        <td><?php echo htmlspecialchars($course['MajorCategory'] ?: 'N/A'); ?></td>
                                        <td class="text-center">
                                            <a href="courses.php?action=edit&id=<?php echo urlencode($course['CourseID']); ?>" class="btn btn-sm btn-warning me-1" title="Edit Course">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <a href="courses.php?action=delete&id=<?php echo urlencode($course['CourseID']); ?>" class="btn btn-sm btn-danger" title="Delete Course"
                                               onclick="return confirm('Are you sure you want to delete this course? This may affect existing schedules and enrollments.');">
                                                <i class="fas fa-trash-alt"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="text-center">No courses found.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<style>
    .table th, .table td { vertical-align: middle; font-size: 0.9rem; }
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
<!-- Layout file (admin_sidebar_menu.php) closes body and html -->