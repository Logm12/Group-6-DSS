<?php
// htdocs/DSS/admin/timeslots.php

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db_connect.php'; // $conn

require_role(['admin'], '../login.php'); // Only admins

$page_title = "Manage Time Slots";

$action = $_GET['action'] ?? 'list'; // Default action: list, edit
$timeslot_id_to_manage = isset($_GET['id']) ? (int)$_GET['id'] : null;

$feedback_message = '';
$feedback_type = '';

// Form data prefill for edit form
$form_data = [
    'TimeSlotID' => '',
    'DayOfWeek' => '',
    'StartTime' => '',
    'EndTime' => ''
];
$days_of_week_options = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];


// --- Process POST requests for edit ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_timeslot']) && $timeslot_id_to_manage) {
    $action = 'edit_submit';
}

if ($conn) {
    switch ($action) {
        case 'edit_submit':
            if ($timeslot_id_to_manage) {
                $day_of_week = $_POST['DayOfWeek'] ?? '';
                $start_time = $_POST['StartTime'] ?? '';
                $end_time = $_POST['EndTime'] ?? '';

                // Repopulate form data for error or prefill
                $form_data['TimeSlotID'] = $timeslot_id_to_manage;
                $form_data['DayOfWeek'] = $day_of_week;
                $form_data['StartTime'] = $start_time;
                $form_data['EndTime'] = $end_time;

                if (empty($day_of_week) || empty($start_time) || empty($end_time)) {
                    $feedback_message = "Day of Week, Start Time, and End Time are required.";
                    $feedback_type = "danger"; $action = 'edit';
                } elseif (!in_array($day_of_week, $days_of_week_options)) {
                    $feedback_message = "Invalid Day of Week selected.";
                    $feedback_type = "danger"; $action = 'edit';
                } elseif (strtotime($start_time) >= strtotime($end_time)) {
                    $feedback_message = "Start Time must be before End Time.";
                    $feedback_type = "danger"; $action = 'edit';
                } else {
                    // Check for conflicts: if this new (Day, Start, End) already exists for ANOTHER TimeSlotID
                    $stmt_check_conflict = $conn->prepare("SELECT TimeSlotID FROM TimeSlots WHERE DayOfWeek = ? AND StartTime = ? AND EndTime = ? AND TimeSlotID != ?");
                    if(!$stmt_check_conflict) throw new Exception("DB Error (check timeslot conflict): " . $conn->error);
                    $stmt_check_conflict->bind_param("sssi", $day_of_week, $start_time, $end_time, $timeslot_id_to_manage);
                    $stmt_check_conflict->execute();
                    if ($stmt_check_conflict->get_result()->num_rows > 0) {
                        $feedback_message = "This time slot (Day, Start, End) combination already exists for another entry.";
                        $feedback_type = "danger"; $action = 'edit';
                    }
                    $stmt_check_conflict->close();

                    if ($action !== 'edit') { // Proceed if no conflict
                        $stmt_update = $conn->prepare("UPDATE TimeSlots SET DayOfWeek = ?, StartTime = ?, EndTime = ? WHERE TimeSlotID = ?");
                        if (!$stmt_update) throw new Exception("DB Error (update timeslot): " . $conn->error);
                        $stmt_update->bind_param("sssi", $day_of_week, $start_time, $end_time, $timeslot_id_to_manage);

                        if ($stmt_update->execute()) {
                            set_flash_message('timeslot_success', "Time slot (ID: {$timeslot_id_to_manage}) updated successfully.", 'success');
                            redirect('admin/timeslots.php');
                        } else {
                            $feedback_message = "Error updating time slot: " . $stmt_update->error;
                            $feedback_type = "danger"; $action = 'edit';
                        }
                        $stmt_update->close();
                    }
                }
            } else {
                set_flash_message('timeslot_error', 'Invalid Time Slot ID for editing.', 'danger');
                redirect('admin/timeslots.php');
            }
            break;
        // No 'add' or 'delete' actions for now, to keep it simple and safe.
        // Admin can add/delete via database tools if absolutely necessary.
    }
} elseif (!$conn && $action !== 'list') {
    $feedback_message = "Database connection error."; $feedback_type = "danger";
}

// Data for display
$timeslots_list = [];

if ($conn) {
    if ($action === 'list') {
        $sql_list = "SELECT TimeSlotID, DayOfWeek, StartTime, EndTime 
                     FROM TimeSlots 
                     ORDER BY FIELD(DayOfWeek, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'), StartTime ASC";
        $result_list = $conn->query($sql_list);
        if ($result_list) {
            while($row = $result_list->fetch_assoc()) $timeslots_list[] = $row;
            $result_list->free();
        } else {
            set_flash_message('db_error', "Error fetching time slots: " . $conn->error, 'danger');
        }
    } elseif ($action === 'edit' && $timeslot_id_to_manage) {
        $stmt_edit_data = $conn->prepare("SELECT TimeSlotID, DayOfWeek, StartTime, EndTime FROM TimeSlots WHERE TimeSlotID = ?");
        if($stmt_edit_data){
            $stmt_edit_data->bind_param("i", $timeslot_id_to_manage);
            if($stmt_edit_data->execute()){
                $result_edit = $stmt_edit_data->get_result();
                $timeslot_to_edit = $result_edit->fetch_assoc();
                if (!$timeslot_to_edit) {
                    set_flash_message('timeslot_error', 'Time Slot not found.', 'warning');
                    redirect('admin/timeslots.php');
                } else {
                    $form_data = $timeslot_to_edit; // Prefill form
                }
            } else {set_flash_message('db_error', "Error fetching time slot: ".$stmt_edit_data->error, 'danger'); $action='list';}
            $stmt_edit_data->close();
        } else {set_flash_message('db_error', "Error preparing to fetch time slot: ".$conn->error, 'danger'); $action='list';}
    }
}

// Repopulate form on POST error if action was 'edit'
if ($action === 'edit' && $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($feedback_message) ) {
    $form_data['DayOfWeek'] = $_POST['DayOfWeek'] ?? $form_data['DayOfWeek'];
    $form_data['StartTime'] = $_POST['StartTime'] ?? $form_data['StartTime'];
    $form_data['EndTime'] = $_POST['EndTime'] ?? $form_data['EndTime'];
    // TimeSlotID is from GET param, not POST for edit identification
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

    <?php // --- FORM FOR EDITING TIMESLOT ---
    if ($action === 'edit' && $timeslot_id_to_manage && !empty($form_data['TimeSlotID'])):
    ?>
        <div class="card shadow mb-4">
            <div class="card-header py-3">
                <h6 class="m-0 fw-bold text-primary">
                    <i class="fas fa-edit me-2"></i>
                    Edit Time Slot (ID: <?php echo htmlspecialchars($form_data['TimeSlotID']); ?>)
                </h6>
            </div>
            <div class="card-body">
                <form method="POST" action="timeslots.php?action=edit_submit&id=<?php echo $timeslot_id_to_manage; ?>" class="needs-validation" novalidate>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="DayOfWeek" class="form-label">Day of Week <span class="text-danger">*</span></label>
                            <select class="form-select" id="DayOfWeek" name="DayOfWeek" required>
                                <?php foreach ($days_of_week_options as $day): ?>
                                    <option value="<?php echo $day; ?>" <?php selected_if_match($form_data['DayOfWeek'], $day); ?>>
                                        <?php echo $day; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="invalid-feedback">Please select a day.</div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="StartTime" class="form-label">Start Time <span class="text-danger">*</span></label>
                            <input type="time" class="form-control" id="StartTime" name="StartTime"
                                   value="<?php echo htmlspecialchars($form_data['StartTime']); ?>" required>
                            <div class="invalid-feedback">Please enter a valid start time.</div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="EndTime" class="form-label">End Time <span class="text-danger">*</span></label>
                            <input type="time" class="form-control" id="EndTime" name="EndTime"
                                   value="<?php echo htmlspecialchars($form_data['EndTime']); ?>" required>
                            <div class="invalid-feedback">Please enter a valid end time.</div>
                        </div>
                    </div>
                    <button type="submit" name="edit_timeslot" class="btn btn-success"><i class="fas fa-save me-1"></i> Save Changes</button>
                    <a href="timeslots.php" class="btn btn-secondary"><i class="fas fa-times me-1"></i> Cancel</a>
                </form>
            </div>
        </div>
    <?php endif; ?>


    <?php if ($action === 'list'): ?>
        <div class="card shadow mb-4">
            <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                <h6 class="m-0 fw-bold text-primary"><i class="fas fa-clock me-2"></i>Standard Time Slots</h6>
                <!-- Add button can be added here if needed in future -->
                <!-- <a href="timeslots.php?action=add" class="btn btn-success btn-sm"><i class="fas fa-plus fa-sm"></i> Add New Slot</a> -->
            </div>
            <div class="card-body">
                <p class="text-muted small">These are the standard time slots used for scheduling. Modifying these can impact existing and future schedules.</p>
                <div class="table-responsive">
                    <table class="table table-bordered table-hover" id="timeslotsTable" width="100%" cellspacing="0">
                        <thead class="table-dark">
                            <tr>
                                <th>ID</th>
                                <th>Day of Week</th>
                                <th>Start Time</th>
                                <th>End Time</th>
                                <th class="text-center" style="width: 10%;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($timeslots_list)): ?>
                                <?php foreach ($timeslots_list as $slot): ?>
                                    <tr>
                                        <td><?php echo $slot['TimeSlotID']; ?></td>
                                        <td><?php echo htmlspecialchars($slot['DayOfWeek']); ?></td>
                                        <td><?php echo htmlspecialchars(format_time_for_display($slot['StartTime'])); ?></td>
                                        <td><?php echo htmlspecialchars(format_time_for_display($slot['EndTime'])); ?></td>
                                        <td class="text-center">
                                            <a href="timeslots.php?action=edit&id=<?php echo $slot['TimeSlotID']; ?>" class="btn btn-sm btn-warning" title="Edit Time Slot">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <!-- Delete action for timeslots is generally risky and not implemented by default -->
                                            <!-- <a href="timeslots.php?action=delete&id=<?php //echo $slot['TimeSlotID']; ?>" class="btn btn-sm btn-danger ms-1" title="Delete Slot"
                                               onclick="return confirm('DELETING A STANDARD TIMESLOT IS RISKY AND CAN BREAK SCHEDULES. Are you absolutely sure?');">
                                                <i class="fas fa-trash-alt"></i>
                                            </a> -->
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="text-center">No time slots found.</td>
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
<!-- Layout file closes body and html -->