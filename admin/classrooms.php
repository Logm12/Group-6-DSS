<?php
// htdocs/DSS/admin/classrooms.php

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db_connect.php'; // $conn

require_role(['admin'], '../login.php');

$page_title = "Manage Classrooms";

$action = $_GET['action'] ?? 'list';
$classroom_id_to_manage = isset($_GET['id']) ? (int)$_GET['id'] : null;

$feedback_message = '';
$feedback_type = '';

// Form data prefill and for errors
$form_data = [
    'ClassroomID' => '', // Only for edit display
    'RoomCode' => '',
    'Capacity' => '',
    'Type' => 'Theory' // Default type
];

// --- Process POST requests for add/edit ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_classroom'])) {
        $action = 'add_submit';
    } elseif (isset($_POST['edit_classroom']) && $classroom_id_to_manage) {
        $action = 'edit_submit';
    }
}

if ($conn) {
    switch ($action) {
        case 'add_submit':
            $room_code = trim($_POST['RoomCode'] ?? '');
            $capacity = isset($_POST['Capacity']) && is_numeric($_POST['Capacity']) ? (int)$_POST['Capacity'] : null;
            $type = trim($_POST['Type'] ?? 'Theory');

            $form_data = $_POST; // Repopulate

            if (empty($room_code) || $capacity === null || $capacity < 1) {
                $feedback_message = "Room Code and a valid Capacity (>=1) are required.";
                $feedback_type = "danger"; $action = 'add';
            } else {
                $stmt_check = $conn->prepare("SELECT ClassroomID FROM Classrooms WHERE RoomCode = ?");
                if (!$stmt_check) throw new Exception("DB Error (check RoomCode): " . $conn->error);
                $stmt_check->bind_param("s", $room_code);
                $stmt_check->execute();
                if ($stmt_check->get_result()->num_rows > 0) {
                    $feedback_message = "Room Code '{$room_code}' already exists.";
                    $feedback_type = "danger"; $action = 'add';
                }
                $stmt_check->close();

                if ($action !== 'add') { // Proceed if RoomCode is unique
                    $stmt_insert = $conn->prepare("INSERT INTO Classrooms (RoomCode, Capacity, Type) VALUES (?, ?, ?)");
                    if (!$stmt_insert) throw new Exception("DB Error (insert classroom): " . $conn->error);
                    $stmt_insert->bind_param("sis", $room_code, $capacity, $type);

                    if ($stmt_insert->execute()) {
                        set_flash_message('classroom_success', "Classroom '{$room_code}' added successfully.", 'success');
                        redirect('admin/classrooms.php');
                    } else {
                        $feedback_message = "Error adding classroom: " . $stmt_insert->error;
                        $feedback_type = "danger"; $action = 'add';
                    }
                    $stmt_insert->close();
                }
            }
            break;

        case 'edit_submit':
            if ($classroom_id_to_manage) {
                $room_code_edited = trim($_POST['RoomCode'] ?? '');
                $capacity_edited = isset($_POST['Capacity']) && is_numeric($_POST['Capacity']) ? (int)$_POST['Capacity'] : null;
                $type_edited = trim($_POST['Type'] ?? 'Theory');

                $form_data = $_POST; $form_data['ClassroomID'] = $classroom_id_to_manage;

                if (empty($room_code_edited) || $capacity_edited === null || $capacity_edited < 1) {
                    $feedback_message = "Room Code and a valid Capacity (>=1) are required.";
                    $feedback_type = "danger"; $action = 'edit';
                } else {
                    // Check if new RoomCode conflicts with ANOTHER classroom
                    $stmt_check_edit = $conn->prepare("SELECT ClassroomID FROM Classrooms WHERE RoomCode = ? AND ClassroomID != ?");
                    if (!$stmt_check_edit) throw new Exception("DB Error (check RoomCode edit): " . $conn->error);
                    $stmt_check_edit->bind_param("si", $room_code_edited, $classroom_id_to_manage);
                    $stmt_check_edit->execute();
                    if ($stmt_check_edit->get_result()->num_rows > 0) {
                        $feedback_message = "The Room Code '{$room_code_edited}' already exists for another classroom.";
                        $feedback_type = "danger"; $action = 'edit';
                    }
                    $stmt_check_edit->close();

                    if ($action !== 'edit') { // Proceed if no validation errors
                        $stmt_update = $conn->prepare("UPDATE Classrooms SET RoomCode = ?, Capacity = ?, Type = ? WHERE ClassroomID = ?");
                        if (!$stmt_update) throw new Exception("DB Error (update classroom): " . $conn->error);
                        $stmt_update->bind_param("sisi", $room_code_edited, $capacity_edited, $type_edited, $classroom_id_to_manage);

                        if ($stmt_update->execute()) {
                            set_flash_message('classroom_success', "Classroom '{$room_code_edited}' updated successfully.", 'success');
                            redirect('admin/classrooms.php');
                        } else {
                            $feedback_message = "Error updating classroom: " . $stmt_update->error;
                            $feedback_type = "danger"; $action = 'edit';
                        }
                        $stmt_update->close();
                    }
                }
            } else {
                set_flash_message('classroom_error', 'Invalid classroom for editing.', 'danger');
                redirect('admin/classrooms.php');
            }
            break;

        case 'delete':
            if ($classroom_id_to_manage) {
                $stmt_delete = $conn->prepare("DELETE FROM Classrooms WHERE ClassroomID = ?");
                if ($stmt_delete) {
                    $stmt_delete->bind_param("i", $classroom_id_to_manage); // ClassroomID is INT
                    if ($stmt_delete->execute()) {
                        if ($stmt_delete->affected_rows > 0) {
                            set_flash_message('classroom_success', 'Classroom deleted successfully.', 'success');
                        } else {
                            set_flash_message('classroom_error', 'Classroom not found or already deleted.', 'warning');
                        }
                    } else {
                        if ($conn->errno == 1451) {
                             set_flash_message('classroom_error', 'Cannot delete classroom: It is currently assigned in schedules. Please remove assignments first.', 'danger');
                        } else {
                            set_flash_message('classroom_error', 'Error deleting classroom: ' . $stmt_delete->error, 'danger');
                        }
                    }
                    $stmt_delete->close();
                } else {set_flash_message('db_error', 'DB error preparing delete for classroom.', 'danger');}
            } else { set_flash_message('classroom_error', 'Invalid Classroom ID for deletion.', 'danger'); }
            redirect('admin/classrooms.php');
            break;
    }
} elseif (!$conn && $action !== 'list') {
    $feedback_message = "Database connection error."; $feedback_type = "danger";
}

// Data for display
$classrooms_list = [];
$classroom_to_edit = null;

if ($conn) {
    if ($action === 'list') {
        $search_term = isset($_GET['search']) ? trim($_GET['search']) : '';
        $sql_list = "SELECT ClassroomID, RoomCode, Capacity, Type FROM Classrooms";
        $params = []; $types = "";
        if (!empty($search_term)) {
            $sql_list .= " WHERE RoomCode LIKE ? OR Type LIKE ?"; // Search by RoomCode or Type
            $like_search = "%" . $search_term . "%";
            array_push($params, $like_search, $like_search);
            $types = "ss";
        }
        $sql_list .= " ORDER BY RoomCode ASC";
        $stmt_list = $conn->prepare($sql_list);
        if ($stmt_list) {
            if (!empty($params)) $stmt_list->bind_param($types, ...$params);
            if($stmt_list->execute()){
                $result_list = $stmt_list->get_result();
                while($row = $result_list->fetch_assoc()) $classrooms_list[] = $row;
                $result_list->free();
            } else { set_flash_message('db_error', "Error fetching classrooms: " . $stmt_list->error, 'danger'); }
            $stmt_list->close();
        } else { set_flash_message('db_error', "Error preparing classroom list: " . $conn->error, 'danger');}

    } elseif ($action === 'edit' && $classroom_id_to_manage) {
        $stmt_edit_data = $conn->prepare("SELECT ClassroomID, RoomCode, Capacity, Type FROM Classrooms WHERE ClassroomID = ?");
        if($stmt_edit_data){
            $stmt_edit_data->bind_param("i", $classroom_id_to_manage);
            if($stmt_edit_data->execute()){
                $result_edit = $stmt_edit_data->get_result();
                $classroom_to_edit = $result_edit->fetch_assoc();
                if (!$classroom_to_edit) {
                    set_flash_message('classroom_error', 'Classroom not found.', 'warning'); redirect('admin/classrooms.php');
                } else {
                    $form_data = array_merge($form_data, $classroom_to_edit); // Prefill
                }
            } else {set_flash_message('db_error', "Error fetching classroom: ".$stmt_edit_data->error, 'danger'); $action='list';}
            $stmt_edit_data->close();
        } else {set_flash_message('db_error', "Error preparing to fetch classroom: ".$conn->error, 'danger'); $action='list';}
    }
}

// Repopulate form on POST error
if (in_array($action, ['add', 'edit']) && $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($feedback_message) ) {
    $form_data = array_merge($form_data, $_POST);
    if ($action === 'edit' && $classroom_id_to_manage) { // Ensure original ID for edit form action isn't lost
        $form_data['ClassroomID'] = $classroom_id_to_manage;
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

    <?php // --- FORM FOR ADDING/EDITING CLASSROOM ---
    if ($action === 'add' || ($action === 'edit' && $classroom_id_to_manage && !empty($form_data['RoomCode']))): // Check if form_data is populated for edit
    ?>
        <div class="card shadow mb-4">
            <div class="card-header py-3">
                <h6 class="m-0 fw-bold text-primary">
                    <i class="fas fa-<?php echo ($action === 'add' ? 'plus-square' : 'edit'); ?> me-2"></i>
                    <?php echo ($action === 'add' ? 'Add New Classroom' : 'Edit Classroom: ' . htmlspecialchars($form_data['RoomCode'])); ?>
                </h6>
            </div>
            <div class="card-body">
                <form method="POST" action="classrooms.php?action=<?php echo ($action === 'add' ? 'add_submit' : 'edit_submit'); ?><?php if($action === 'edit' && $classroom_id_to_manage) echo '&id='.$classroom_id_to_manage; ?>" class="needs-validation" novalidate>
                     <?php if ($action === 'edit'): ?>
                        <!-- ClassroomID is usually not changed. If it were, it would be complex. -->
                        <!-- For edit, we use the ID from URL/session, not a form field for ID. -->
                    <?php endif; ?>

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="RoomCode" class="form-label">Room Code/Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="RoomCode" name="RoomCode"
                                   value="<?php echo htmlspecialchars($form_data['RoomCode']); ?>" required maxlength="10">
                            <div class="invalid-feedback">Room Code is required (e.g., A101, B203).</div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="Capacity" class="form-label">Capacity <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="Capacity" name="Capacity"
                                   value="<?php echo htmlspecialchars($form_data['Capacity']); ?>" required min="1" max="500">
                            <div class="invalid-feedback">Please enter a valid capacity (e.g., 1-500).</div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="Type" class="form-label">Room Type</label>
                            <select class="form-select" id="Type" name="Type">
                                <option value="Theory" <?php selected_if_match($form_data['Type'], 'Theory'); ?>>Theory</option>
                                <option value="Lab" <?php selected_if_match($form_data['Type'], 'Lab'); ?>>Lab</option>
                                <option value="Workshop" <?php selected_if_match($form_data['Type'], 'Workshop'); ?>>Workshop</option>
                                <option value="Seminar" <?php selected_if_match($form_data['Type'], 'Seminar'); ?>>Seminar Room</option>
                                <option value="Other" <?php selected_if_match($form_data['Type'], 'Other'); ?>>Other</option>
                            </select>
                        </div>
                    </div>

                    <?php if ($action === 'add'): ?>
                        <button type="submit" name="add_classroom" class="btn btn-primary"><i class="fas fa-plus me-1"></i> Add Classroom</button>
                    <?php else: // Edit mode ?>
                        <button type="submit" name="edit_classroom" class="btn btn-success"><i class="fas fa-save me-1"></i> Save Changes</button>
                    <?php endif; ?>
                    <a href="classrooms.php" class="btn btn-secondary"><i class="fas fa-times me-1"></i> Cancel</a>
                </form>
            </div>
        </div>
    <?php endif; ?>


    <?php if ($action === 'list'): ?>
        <div class="card shadow mb-4">
            <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                <h6 class="m-0 fw-bold text-primary"><i class="fas fa-person-booth me-2"></i>Classrooms List</h6>
                <a href="classrooms.php?action=add" class="btn btn-success btn-sm">
                    <i class="fas fa-plus fa-sm"></i> Add New Classroom
                </a>
            </div>
            <div class="card-body">
                <form method="GET" action="classrooms.php" class="mb-3 border p-3 rounded bg-light">
                    <input type="hidden" name="action" value="list">
                    <div class="input-group">
                        <input type="text" name="search" class="form-control form-control-sm" placeholder="Search by Room Code or Type..." value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                        <button class="btn btn-primary btn-sm" type="submit"><i class="fas fa-search"></i></button>
                         <?php if (!empty($_GET['search'])): ?>
                            <a href="classrooms.php?action=list" class="btn btn-outline-secondary btn-sm" title="Clear Search"><i class="fas fa-times"></i></a>
                        <?php endif; ?>
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="table table-bordered table-hover" id="classroomsTable" width="100%" cellspacing="0">
                        <thead class="table-dark">
                            <tr>
                                <th>ID</th>
                                <th>Room Code</th>
                                <th>Capacity</th>
                                <th>Type</th>
                                <th class="text-center" style="width: 12%;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($classrooms_list)): ?>
                                <?php foreach ($classrooms_list as $classroom): ?>
                                    <tr>
                                        <td><?php echo $classroom['ClassroomID']; ?></td>
                                        <td><?php echo htmlspecialchars($classroom['RoomCode']); ?></td>
                                        <td><?php echo htmlspecialchars($classroom['Capacity']); ?></td>
                                        <td><?php echo htmlspecialchars($classroom['Type']); ?></td>
                                        <td class="text-center">
                                            <a href="classrooms.php?action=edit&id=<?php echo $classroom['ClassroomID']; ?>" class="btn btn-sm btn-warning me-1" title="Edit Classroom">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <a href="classrooms.php?action=delete&id=<?php echo $classroom['ClassroomID']; ?>" class="btn btn-sm btn-danger" title="Delete Classroom"
                                               onclick="return confirm('Are you sure you want to delete this classroom? This may affect existing schedules.');">
                                                <i class="fas fa-trash-alt"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="text-center">No classrooms found.</td>
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