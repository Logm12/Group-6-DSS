<?php
// htdocs/DSS/admin/semesters.php

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db_connect.php'; // $conn

require_role(['admin'], '../login.php');

$page_title = "Manage Semesters"; // Default, will be overridden if viewing schedule

$action = $_GET['action'] ?? 'list';
$semester_id_param = isset($_GET['id']) ? (int)$_GET['id'] : null;

$feedback_message = '';
$feedback_type = '';

// Form data for add/edit semester
$form_data_semester = [ /* ... như cũ ... */ ];
$days_of_week_options = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday']; // For consistency if needed later

// Data for viewing schedule
$schedule_events_for_view_json = '[]';
$selected_semester_name_for_view = '';
$current_semester_details_for_calendar_view = null; // For date iteration when viewing schedule

// --- Process POST requests (add/edit semester) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_semester'])) {
        $action = 'add_submit';
    } elseif (isset($_POST['edit_semester']) && $semester_id_param) { // Use $semester_id_param for consistency
        $action = 'edit_submit';
    }
}

if ($conn) {
    switch ($action) {
        case 'add_submit':
            // ... (Logic thêm semester như cũ, không thay đổi) ...
            break;

        case 'edit_submit':
            if ($semester_id_param) { // Renamed for clarity
                // ... (Logic sửa semester như cũ, không thay đổi, dùng $semester_id_param) ...
            } else {
                set_flash_message('semester_error', 'Invalid semester for editing.', 'danger');
                redirect('admin/semesters.php');
            }
            break;

        case 'delete':
            if ($semester_id_param) {
                // ... (Logic xóa semester như cũ, có kiểm tra ràng buộc, dùng $semester_id_param) ...
            } else { set_flash_message('semester_error', 'Invalid Semester ID for deletion.', 'danger'); }
            redirect('admin/semesters.php');
            break;
        
        // --- NEW ACTION TO VIEW SCHEDULE ---
        case 'view_schedule':
            if ($semester_id_param) {
                $page_title = "View Schedule for Semester"; // Update page title

                // Fetch semester details (name, start/end dates) for display and calendar range
                $stmt_sem_details_view = $conn->prepare("SELECT SemesterID, SemesterName, StartDate, EndDate FROM Semesters WHERE SemesterID = ?");
                if ($stmt_sem_details_view) {
                    $stmt_sem_details_view->bind_param("i", $semester_id_param);
                    if ($stmt_sem_details_view->execute()) {
                        $res_sem_details = $stmt_sem_details_view->get_result();
                        if ($sem_details_row = $res_sem_details->fetch_assoc()) {
                            $current_semester_details_for_calendar_view = $sem_details_row;
                            $selected_semester_name_for_view = $sem_details_row['SemesterName'];
                            $page_title = "Schedule: " . htmlspecialchars($selected_semester_name_for_view); // More specific title
                        } else {
                            set_flash_message('semester_error', 'Selected semester not found.', 'danger');
                            redirect('admin/semesters.php'); // Redirect if semester ID is invalid
                        }
                    } else { error_log("SemestersPage: Error exec sem_details_view query: ".$stmt_sem_details_view->error); }
                    $stmt_sem_details_view->close();
                } else { error_log("SemestersPage: Error prep sem_details_view query: ".$conn->error); }

                if ($current_semester_details_for_calendar_view) {
                    // Fetch all scheduled classes for this semester
                    $sql_view_sched = "SELECT sc.ScheduleID, c.CourseID, c.CourseName, l.LecturerName, cr.RoomCode, 
                                              t.DayOfWeek, t.StartTime, t.EndTime
                                       FROM ScheduledClasses sc
                                       JOIN Courses c ON sc.CourseID = c.CourseID
                                       JOIN Lecturers l ON sc.LecturerID = l.LecturerID
                                       JOIN Classrooms cr ON sc.ClassroomID = cr.ClassroomID
                                       JOIN TimeSlots t ON sc.TimeSlotID = t.TimeSlotID
                                       WHERE sc.SemesterID = ?
                                       ORDER BY FIELD(t.DayOfWeek, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'), t.StartTime";
                    $stmt_view_sched = $conn->prepare($sql_view_sched);
                    if ($stmt_view_sched) {
                        $stmt_view_sched->bind_param("i", $semester_id_param);
                        $events_array_view = [];
                        if ($stmt_view_sched->execute()) {
                            $res_view_sched = $stmt_view_sched->get_result();
                            try {
                                $sem_start_dt_view = new DateTime($current_semester_details_for_calendar_view['StartDate']);
                                $sem_end_dt_view = new DateTime($current_semester_details_for_calendar_view['EndDate']);
                                $sem_end_dt_view->modify('+1 day');
                                $interval_view = new DateInterval('P1D');
                                $period_view = new DatePeriod($sem_start_dt_view, $interval_view, $sem_end_dt_view);

                                while ($row_vs = $res_view_sched->fetch_assoc()) {
                                    foreach ($period_view as $dt_vs) {
                                        if ($dt_vs->format('l') === $row_vs['DayOfWeek']) {
                                            $events_array_view[] = [
                                                'id' => $row_vs['ScheduleID'] . '_' . $dt_vs->format('Ymd'),
                                                'title' => ($row_vs['CourseID'] ?? '') . ": " . ($row_vs['CourseName'] ?? 'Class'),
                                                'start' => $dt_vs->format('Y-m-d') . ' ' . $row_vs['StartTime'],
                                                'end' => $dt_vs->format('Y-m-d') . ' ' . $row_vs['EndTime'],
                                                'extendedProps' => [
                                                    'lecturer' => $row_vs['LecturerName'] ?? 'N/A',
                                                    'room' => $row_vs['RoomCode'] ?? 'N/A',
                                                    'course_code' => $row_vs['CourseID'] ?? 'N/A',
                                                    'full_course_name' => $row_vs['CourseName'] ?? 'N/A'
                                                ]
                                            ];
                                        }
                                    }
                                }
                            } catch (Exception $e) { error_log("SemestersPage: Error processing dates for schedule view: " . $e->getMessage()); }
                            $schedule_events_for_view_json = json_encode($events_array_view);
                            if ($schedule_events_for_view_json === false) $schedule_events_for_view_json = '[]';
                            if($res_view_sched) $res_view_sched->free();
                        } else { error_log("SemestersPage: Error exec view_schedule query: " . $stmt_view_sched->error); }
                        $stmt_view_sched->close();
                    } else { error_log("SemestersPage: Error prep view_schedule query: " . $conn->error); }
                }
            } else {
                set_flash_message('semester_error', 'Please select a semester to view its schedule.', 'info');
                redirect('admin/semesters.php');
            }
            break;
    } // End switch $action
} elseif (!$conn && $action !== 'list') {
    $feedback_message = "Database connection error."; $feedback_type = "danger";
}

// Data for list display or edit form prefill
$semesters_list_display = [];
// $semester_to_edit variable is already being handled by $form_data for prefill in edit case

if ($conn) {
    if ($action === 'list') {
        // ... (Logic lấy danh sách học kỳ như cũ, không thay đổi) ...
    } elseif ($action === 'edit' && $semester_id_param) { // Use $semester_id_param
        // ... (Logic lấy thông tin học kỳ cần sửa như cũ, điền vào $form_data, dùng $semester_id_param) ...
    }
}

// Repopulate form on POST error (như cũ)
// ...

// Update the global $page_title if it was changed by 'view_schedule' action
if ($action === 'view_schedule' && !empty($selected_semester_name_for_view)) {
    // This ensures the layout uses the more specific title
} elseif ($action === 'add') {
    $page_title = "Add New Semester";
} elseif ($action === 'edit' && $semester_id_param && !empty($form_data['SemesterName'])) {
    $page_title = "Edit Semester: " . htmlspecialchars($form_data['SemesterName']);
}


require_once __DIR__ . '/../includes/admin_sidebar_menu.php';
?>
<!-- Nối tiếp từ Part 1 -->
<div class="container-fluid">
    <?php echo display_all_flash_messages(); ?>
    <?php if (!empty($feedback_message) && $action !== 'view_schedule'): // Don't show generic form feedback when viewing schedule ?>
        <div class="alert alert-<?php echo htmlspecialchars($feedback_type); ?> alert-dismissible fade show py-2" role="alert">
            <?php echo htmlspecialchars($feedback_message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php // --- FORM FOR ADDING/EDITING SEMESTER --- (Giữ nguyên như cũ)
    if ($action === 'add' || ($action === 'edit' && $semester_id_param && !empty($form_data['SemesterName']))):
    ?>
        <div class="card shadow mb-4">
            <!-- ... (Toàn bộ HTML của form thêm/sửa semester giữ nguyên) ... -->
        </div>
    <?php endif; ?>


    <?php // --- DISPLAY LIST OF SEMESTERS ---
    if ($action === 'list'): ?>
        <div class="card shadow mb-4">
            <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                <h6 class="m-0 fw-bold text-primary"><i class="fas fa-calendar-alt me-2"></i>Semesters List</h6>
                <a href="semesters.php?action=add" class="btn btn-success btn-sm">
                    <i class="fas fa-plus fa-sm"></i> Add New Semester
                </a>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-hover" id="semestersTable" width="100%" cellspacing="0">
                        <thead class="table-dark">
                            <tr>
                                <th>ID</th>
                                <th>Semester Name</th>
                                <th>Start Date</th>
                                <th>End Date</th>
                                <th class="text-center" style="width: 15%;">Actions</th> <!-- Increased width for new button -->
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($semesters_list_display)): ?>
                                <?php foreach ($semesters_list_display as $semester): ?>
                                    <tr>
                                        <td><?php echo $semester['SemesterID']; ?></td>
                                        <td><?php echo htmlspecialchars($semester['SemesterName']); ?></td>
                                        <td><?php echo htmlspecialchars(format_date_for_display($semester['StartDate'])); ?></td>
                                        <td><?php echo htmlspecialchars(format_date_for_display($semester['EndDate'])); ?></td>
                                        <td class="text-center">
                                            <a href="semesters.php?action=view_schedule&id=<?php echo $semester['SemesterID']; ?>" class="btn btn-sm btn-info me-1" title="View Schedule">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                            <a href="semesters.php?action=edit&id=<?php echo $semester['SemesterID']; ?>" class="btn btn-sm btn-warning me-1" title="Edit Semester">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <a href="semesters.php?action=delete&id=<?php echo $semester['SemesterID']; ?>" class="btn btn-sm btn-danger" title="Delete Semester"
                                               onclick="return confirm('DELETE SEMESTER: <?php echo htmlspecialchars(addslashes($semester['SemesterName']), ENT_QUOTES); ?>?\n\nWARNING: This action can be very impactful if the semester is in use and has associated schedules or enrollments. Ensure it is safe to delete.');">
                                                <i class="fas fa-trash-alt"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="5" class="text-center">No semesters found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; // End action list ?>


    <?php // --- DISPLAY SCHEDULE VIEW FOR A SEMESTER ---
    if ($action === 'view_schedule' && $semester_id_param && $current_semester_details_for_calendar_view):
    ?>
        <div class="card shadow mb-4">
            <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                 <h6 class="m-0 fw-bold text-primary">
                    <i class="fas fa-calendar-week me-2"></i>Schedule for: <?php echo htmlspecialchars($selected_semester_name_for_view); ?>
                 </h6>
                <a href="semesters.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i> Back to Semesters List</a>
            </div>
            <div class="card-body">
                <div id="adminViewScheduleCalendarContainer">
                    <div id='adminViewCalendar'>
                        <?php if (empty($schedule_events_for_view_json) || $schedule_events_for_view_json === '[]'): ?>
                            <div class="alert alert-info text-center" role="alert" id="adminCalendarPlaceholder">
                                <i class="fas fa-info-circle me-2"></i>
                                No scheduled classes found for '<?php echo htmlspecialchars($selected_semester_name_for_view); ?>'.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal for Event Details (similar to other calendar modals) -->
        <div class="modal fade" id="adminViewScheduleEventModal" tabindex="-1" aria-labelledby="adminViewScheduleEventModalLabel" aria-hidden="true">
          <div class="modal-dialog modal-dialog-centered modal-lg"> <!-- Larger modal -->
            <div class="modal-content">
              <div class="modal-header bg-info text-dark">
                <h5 class="modal-title" id="adminViewScheduleEventModalLabel">Class Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
              </div>
              <div class="modal-body">
                <p><strong class="text-info">Course:</strong> <span id="modalAdminViewCourseName"></span> (<span id="modalAdminViewCourseCode"></span>)</p>
                <p><strong class="text-info">Time:</strong> <span id="modalAdminViewTime"></span></p>
                <p><strong class="text-info">Day:</strong> <span id="modalAdminViewDay"></span></p>
                <p><strong class="text-info">Room:</strong> <span id="modalAdminViewRoom"></span></p>
                <p><strong class="text-info">Lecturer:</strong> <span id="modalAdminViewLecturer"></span></p>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
              </div>
            </div>
          </div>
        </div>
    <?php endif; // End action view_schedule ?>

</div> <!-- /.container-fluid -->

<!-- FullCalendar CSS and JS (if not already loaded by a global include) -->
<!-- Assuming admin_sidebar_menu.php includes Bootstrap JS -->
<script src='<?php echo BASE_URL; ?>assets/fullcalendar/index.global.min.js'></script>

<style>
    .table th, .table td { vertical-align: middle; font-size: 0.9rem; }
    #adminViewScheduleCalendarContainer { max-width: 100%; /* Full width */ margin: 10px auto; background-color: #fff; padding: 15px; border-radius: 0.25rem; box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.075); }
    #adminViewCalendar .fc-event { cursor: pointer; font-size: 0.75em !important; }
    #adminViewScheduleEventModal .modal-header { border-bottom: none; }
    #adminViewScheduleEventModal .modal-title { font-weight: 500; }
    #adminViewScheduleEventModal .modal-body p { margin-bottom: 0.6rem; }
    #adminViewScheduleEventModal .modal-body p strong { min-width: 80px; display: inline-block;}
</style>

<script>
// Bootstrap 5 form validation script (giữ nguyên)
(function () { /* ... */ })();

document.addEventListener('DOMContentLoaded', function() {
    const viewCalendarEl = document.getElementById('adminViewCalendar');
    const viewCalendarPlaceholder = document.getElementById('adminCalendarPlaceholder');

    if (viewCalendarEl && '<?php echo $action; ?>' === 'view_schedule') {
        if (typeof FullCalendar === 'undefined' || typeof FullCalendar.Calendar === 'undefined') {
            console.error("FullCalendar library not loaded for Admin Schedule View!");
            if(viewCalendarPlaceholder) {
                viewCalendarPlaceholder.innerHTML = '<i class="fas fa-exclamation-triangle me-2"></i> Calendar library could not be loaded.';
                viewCalendarPlaceholder.classList.add('alert-danger'); viewCalendarPlaceholder.classList.remove('alert-info');
            }
            return;
        }

        let scheduleEventsDataView = [];
        let rawJsonDataView = <?php echo $schedule_events_for_view_json ?: '[]'; ?>;
        if (typeof rawJsonDataView === 'string') {
            try { scheduleEventsDataView = JSON.parse(rawJsonDataView); } catch (e) { console.error("Error parsing schedule_events_for_view_json:", e); }
        } else if (Array.isArray(rawJsonDataView)) {
            scheduleEventsDataView = rawJsonDataView;
        }

        if (scheduleEventsDataView.length === 0) {
            if(viewCalendarPlaceholder) viewCalendarPlaceholder.style.display = 'block';
        } else {
            if(viewCalendarPlaceholder) viewCalendarPlaceholder.style.display = 'none';
            var adminScheduleViewCalendar = new FullCalendar.Calendar(viewCalendarEl, {
                initialView: 'timeGridWeek', // Default to week view
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek'
                },
                events: scheduleEventsDataView,
                editable: false,
                selectable: false,
                allDaySlot: false,
                slotMinTime: "07:00:00",
                slotMaxTime: "19:00:00",
                height: 'auto', // Adjust based on content
                contentHeight: 700, // Or a fixed height suitable for admin view
                eventTimeFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
                slotLabelFormat: { hour: 'numeric', minute: '2-digit', meridiem: false, hour12: false },
                eventClick: function(info) {
                    document.getElementById('modalAdminViewCourseName').textContent = info.event.extendedProps.full_course_name || 'N/A';
                    document.getElementById('modalAdminViewCourseCode').textContent = info.event.extendedProps.course_code || 'N/A';
                    let start = info.event.start ? info.event.start.toLocaleTimeString([],{hour:'2-digit',minute:'2-digit',hour12:false}) : 'N/A';
                    let end = info.event.end ? info.event.end.toLocaleTimeString([],{hour:'2-digit',minute:'2-digit',hour12:false}) : 'N/A';
                    document.getElementById('modalAdminViewTime').textContent = `${start} - ${end}`;
                    document.getElementById('modalAdminViewDay').textContent = info.event.start ? info.event.start.toLocaleDateString(undefined, { weekday: 'long' }) : 'N/A';
                    document.getElementById('modalAdminViewRoom').textContent = info.event.extendedProps.room || 'N/A';
                    document.getElementById('modalAdminViewLecturer').textContent = info.event.extendedProps.lecturer || 'N/A';

                    var eventModalInstance = new bootstrap.Modal(document.getElementById('adminViewScheduleEventModal'));
                    eventModalInstance.show();
                }
            });
            adminScheduleViewCalendar.render();
        }
    }
});
</script>
<!-- Layout file closes body and html -->