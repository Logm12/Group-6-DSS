<?php
// htdocs/DSS/instructor/unavailable_slots.php

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db_connect.php'; // $conn

require_role(['instructor'], '../login.php'); // Only instructors

$page_specific_title = "My Unavailable Time Slots";

$current_instructor_id_db = get_current_user_linked_entity_id();
if (!$current_instructor_id_db) {
    // Should not happen if require_role works and LinkedEntityID is set up correctly
    set_flash_message('error', 'Could not determine your instructor profile. Please re-login or contact support.', 'danger');
    redirect('instructor/index.php');
    exit;
}

$feedback_message = '';
$feedback_type = '';

// Handle POST request for adding a new unavailable slot
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_unavailable_slot'])) {
    $busy_day = $_POST['busy_day_of_week'] ?? '';
    $busy_start_time = $_POST['busy_start_time'] ?? '';
    $busy_end_time = $_POST['busy_end_time'] ?? '';
    $reason = trim($_POST['reason'] ?? '');
    $apply_semester_id = !empty($_POST['apply_semester_id']) ? (int)$_POST['apply_semester_id'] : null; // NULL for all semesters

    // Basic Validation
    if (empty($busy_day) || empty($busy_start_time) || empty($busy_end_time)) {
        $feedback_message = "Day of week, start time, and end time are required.";
        $feedback_type = "danger";
    } elseif (strtotime($busy_start_time) >= strtotime($busy_end_time)) {
        $feedback_message = "Start time must be before end time.";
        $feedback_type = "danger";
    } elseif (!in_array($busy_day, ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'])) {
        $feedback_message = "Invalid day of the week selected.";
        $feedback_type = "danger";
    } else {
        if ($conn) {
            // Check for overlapping unavailability for the same lecturer, day, and semester (if specified)
            // This check can be complex due to time overlaps.
            // For simplicity, we might initially allow overlaps and let the user manage,
            // or add a more robust overlap check here.
            // A basic check: ensure no exact same slot is added.

            $stmt_insert = $conn->prepare(
                "INSERT INTO InstructorUnavailableSlots (LecturerID, BusyDayOfWeek, BusyStartTime, BusyEndTime, Reason, SemesterID) 
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            if ($stmt_insert) {
                $stmt_insert->bind_param("issssi", 
                    $current_instructor_id_db, 
                    $busy_day, 
                    $busy_start_time, 
                    $busy_end_time, 
                    $reason, 
                    $apply_semester_id
                );
                if ($stmt_insert->execute()) {
                    set_flash_message('unavailable_slot_success', 'Unavailable slot added successfully.', 'success');
                    // No redirect, page will reload showing the new entry
                } else {
                    $feedback_message = "Error adding unavailable slot: " . $stmt_insert->error;
                    // Check for specific errors, e.g., if a unique constraint on (LecturerID, Day, Start, End, SemesterID) exists
                    if ($conn->errno == 1062) {
                         $feedback_message = "This exact unavailable slot might already exist for the selected semester.";
                    }
                    $feedback_type = "danger";
                }
                $stmt_insert->close();
            } else {
                $feedback_message = "Database error preparing to add slot: " . $conn->error;
                $feedback_type = "danger";
            }
        } else {
            $feedback_message = "Database connection not available.";
            $feedback_type = "danger";
        }
    }
}

// Handle GET request for deleting an unavailable slot
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['unavailable_id'])) {
    $unavailable_id_to_delete = (int)$_GET['unavailable_id'];
    if ($unavailable_id_to_delete > 0 && $conn) {
        // Ensure the slot belongs to the current instructor before deleting
        $stmt_delete = $conn->prepare("DELETE FROM InstructorUnavailableSlots WHERE UnavailableID = ? AND LecturerID = ?");
        if ($stmt_delete) {
            $stmt_delete->bind_param("ii", $unavailable_id_to_delete, $current_instructor_id_db);
            if ($stmt_delete->execute()) {
                if ($stmt_delete->affected_rows > 0) {
                    set_flash_message('unavailable_slot_success', 'Unavailable slot deleted successfully.', 'success');
                } else {
                    set_flash_message('unavailable_slot_error', 'Could not delete slot or slot not found for your account.', 'warning');
                }
            } else {
                set_flash_message('unavailable_slot_error', 'Error deleting slot: ' . $stmt_delete->error, 'danger');
            }
            $stmt_delete->close();
        } else {
            set_flash_message('unavailable_slot_error', 'Database error preparing to delete slot.', 'danger');
        }
        // Redirect to the same page to remove GET parameters and show flash message
        redirect('instructor/unavailable_slots.php');
        exit;
    }
}


// Fetch existing unavailable slots for the current instructor
$unavailable_slots_list = [];
$semesters_for_dropdown_list = []; // For the "Apply to Semester" dropdown in the form

if (isset($conn) && $conn instanceof mysqli) {
    // Fetch semesters for the dropdown (current and future, or all)
    $sql_sem_dropdown = "SELECT SemesterID, SemesterName FROM Semesters ORDER BY StartDate DESC"; // Or filter for current/future
    $res_sem_dropdown = $conn->query($sql_sem_dropdown);
    if ($res_sem_dropdown) {
        while($row = $res_sem_dropdown->fetch_assoc()) $semesters_for_dropdown_list[] = $row;
    }

    // Fetch the instructor's unavailable slots
    $stmt_fetch_slots = $conn->prepare(
        "SELECT ius.UnavailableID, ius.BusyDayOfWeek, ius.BusyStartTime, ius.BusyEndTime, ius.Reason, s.SemesterName 
         FROM InstructorUnavailableSlots ius
         LEFT JOIN Semesters s ON ius.SemesterID = s.SemesterID
         WHERE ius.LecturerID = ?
         ORDER BY ius.SemesterID, FIELD(ius.BusyDayOfWeek, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'), ius.BusyStartTime"
    );
    if ($stmt_fetch_slots) {
        $stmt_fetch_slots->bind_param("i", $current_instructor_id_db);
        if ($stmt_fetch_slots->execute()) {
            $result_slots = $stmt_fetch_slots->get_result();
            while ($row_slot = $result_slots->fetch_assoc()) {
                $unavailable_slots_list[] = $row_slot;
            }
            $result_slots->free();
        } else {
            $feedback_message = "Error fetching your unavailable slots: " . $stmt_fetch_slots->error;
            $feedback_type = "danger";
        }
        $stmt_fetch_slots->close();
    } else {
        $feedback_message = "Database error preparing to fetch slots: " . $conn->error;
        $feedback_type = "danger";
    }
}

// Layout specific variables
$menu_items_layout = [
    ['label' => 'Dashboard', 'icon' => 'fas fa-tachometer-alt', 'link' => 'instructor/index.php', 'id' => 'instr_dashboard'],
    ['label' => 'My Schedule', 'icon' => 'fas fa-calendar-alt', 'link' => 'instructor/my_schedule.php', 'id' => 'instr_my_schedule'],
    ['label' => 'Set Unavailability', 'icon' => 'fas fa-user-clock', 'link' => 'instructor/unavailable_slots.php', 'id' => 'instr_unavailability'],
];
require_once __DIR__ . '/../includes/admin_sidebar_menu.php'; // Using the shared layout
?>
<!-- Nối tiếp từ Part 1 -->
<div class="container-fluid pt-3">

    <?php echo display_all_flash_messages(); ?>
    <?php if (!empty($feedback_message)): ?>
        <div class="alert alert-<?php echo htmlspecialchars($feedback_type); ?> alert-dismissible fade show py-2" role="alert">
            <?php echo htmlspecialchars($feedback_message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Form to Add New Unavailable Slot -->
    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-plus-circle me-2"></i>Add New Unavailable Time Slot</h6>
        </div>
        <div class="card-body">
            <form method="POST" action="unavailable_slots.php" class="needs-validation" novalidate>
                <div class="row">
                    <div class="col-md-3 mb-3">
                        <label for="busy_day_of_week" class="form-label">Day of Week <span class="text-danger">*</span></label>
                        <select class="form-select" id="busy_day_of_week" name="busy_day_of_week" required>
                            <option value="">-- Select Day --</option>
                            <option value="Monday">Monday</option>
                            <option value="Tuesday">Tuesday</option>
                            <option value="Wednesday">Wednesday</option>
                            <option value="Thursday">Thursday</option>
                            <option value="Friday">Friday</option>
                            <option value="Saturday">Saturday</option>
                            <option value="Sunday">Sunday</option>
                        </select>
                        <div class="invalid-feedback">Please select a day.</div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label for="busy_start_time" class="form-label">Start Time <span class="text-danger">*</span></label>
                        <input type="time" class="form-control" id="busy_start_time" name="busy_start_time" required>
                        <div class="invalid-feedback">Please enter a start time.</div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label for="busy_end_time" class="form-label">End Time <span class="text-danger">*</span></label>
                        <input type="time" class="form-control" id="busy_end_time" name="busy_end_time" required>
                        <div class="invalid-feedback">Please enter an end time.</div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label for="apply_semester_id" class="form-label">Apply to Semester</label>
                        <select class="form-select" id="apply_semester_id" name="apply_semester_id">
                            <option value="">All Semesters (General)</option>
                            <?php foreach ($semesters_for_dropdown_list as $semester): ?>
                                <option value="<?php echo $semester['SemesterID']; ?>">
                                    <?php echo htmlspecialchars($semester['SemesterName']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="form-text text-muted">Leave blank if this unavailability applies generally.</small>
                    </div>
                </div>
                <div class="mb-3">
                    <label for="reason" class="form-label">Reason (Optional)</label>
                    <input type="text" class="form-control" id="reason" name="reason" placeholder="E.g., Regular Meeting, Research Time">
                </div>
                <button type="submit" name="add_unavailable_slot" class="btn btn-primary"><i class="fas fa-save me-1"></i> Add Slot</button>
            </form>
        </div>
    </div>

    <!-- Table of Existing Unavailable Slots -->
    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-list-alt me-2"></i>My Registered Unavailable Slots</h6>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-hover" id="unavailableSlotsTable" width="100%" cellspacing="0">
                    <thead class="table-dark">
                        <tr>
                            <th>Day of Week</th>
                            <th>Start Time</th>
                            <th>End Time</th>
                            <th>Reason</th>
                            <th>Applied Semester</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($unavailable_slots_list)): ?>
                            <?php foreach ($unavailable_slots_list as $slot): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($slot['BusyDayOfWeek']); ?></td>
                                    <td><?php echo htmlspecialchars(format_time_for_display($slot['BusyStartTime'])); ?></td>
                                    <td><?php echo htmlspecialchars(format_time_for_display($slot['BusyEndTime'])); ?></td>
                                    <td><?php echo htmlspecialchars($slot['Reason'] ?: '-'); ?></td>
                                    <td><?php echo htmlspecialchars($slot['SemesterName'] ?: 'All Semesters'); ?></td>
                                    <td class="text-center">
                                        <a href="unavailable_slots.php?action=delete&unavailable_id=<?php echo $slot['UnavailableID']; ?>" 
                                           class="btn btn-danger btn-sm" title="Delete Slot"
                                           onclick="return confirm('Are you sure you want to delete this unavailable slot?');">
                                            <i class="fas fa-trash-alt"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted">You have not registered any unavailable time slots yet.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div> <!-- /.container-fluid -->

<style>
    /* Add any page-specific styles if needed */
    .table th, .table td {
        vertical-align: middle;
    }
</style>

<script>
// Bootstrap 5 form validation script (same as used in other admin pages)
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