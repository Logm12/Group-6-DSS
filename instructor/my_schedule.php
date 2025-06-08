<?php
// htdocs/DSS/instructor/my_schedule.php

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db_connect.php'; // $conn

require_role(['instructor'], '../login.php'); // Authenticate: only instructors allowed

$page_specific_title = "My Teaching Schedule";

$current_instructor_id_db = get_current_user_linked_entity_id(); // This is the LecturerID

$selected_semester_id = null;
$semesters_list = []; // To populate semester dropdown
$schedule_events_json = '[]'; // Default for FullCalendar
$current_semester_details_for_calendar = null; // For date iteration

if (isset($conn) && $conn instanceof mysqli) {
    // 1. Get list of all semesters for the dropdown
    $result_semesters_list = $conn->query("SELECT SemesterID, SemesterName, StartDate, EndDate FROM Semesters ORDER BY StartDate DESC");
    if ($result_semesters_list) {
        while ($row_sem = $result_semesters_list->fetch_assoc()) {
            $semesters_list[] = $row_sem;
        }
        $result_semesters_list->free();
    } else {
        error_log("InstructorMySchedule: Failed to fetch semesters list: " . $conn->error);
    }

    // 2. Determine the selected semester
    if (isset($_GET['semester_id']) && filter_var($_GET['semester_id'], FILTER_VALIDATE_INT)) {
        $selected_semester_id = (int)$_GET['semester_id'];
    } elseif (!empty($semesters_list)) { // Default to current or latest if not specified
        $today_date = date('Y-m-d');
        foreach ($semesters_list as $semester_item) {
            if ($today_date >= $semester_item['StartDate'] && $today_date <= $semester_item['EndDate']) {
                $selected_semester_id = (int)$semester_item['SemesterID'];
                break;
            }
        }
        if (!$selected_semester_id && isset($semesters_list[0]['SemesterID'])) {
            $selected_semester_id = (int)$semesters_list[0]['SemesterID']; // Fallback to the most recent one
        }
    }

    // 3. Fetch details of the selected semester (needed for FullCalendar date range)
    if ($selected_semester_id) {
        foreach($semesters_list as $s_detail_item){
            if($s_detail_item['SemesterID'] == $selected_semester_id){
                $current_semester_details_for_calendar = $s_detail_item;
                break;
            }
        }
    }

    // 4. Fetch instructor's schedule for the selected semester
    if ($current_instructor_id_db && $selected_semester_id && $current_semester_details_for_calendar) {
        $sql_instructor_schedule = "SELECT 
                                        sc.ScheduleID, 
                                        c.CourseID, 
                                        c.CourseName, 
                                        cr.RoomCode, 
                                        t.DayOfWeek, 
                                        t.StartTime, 
                                        t.EndTime
                                    FROM ScheduledClasses sc
                                    JOIN Courses c ON sc.CourseID = c.CourseID
                                    JOIN Classrooms cr ON sc.ClassroomID = cr.ClassroomID
                                    JOIN TimeSlots t ON sc.TimeSlotID = t.TimeSlotID
                                    WHERE sc.LecturerID = ? AND sc.SemesterID = ?
                                    ORDER BY FIELD(t.DayOfWeek, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'), t.StartTime";
        
        $stmt_schedule = $conn->prepare($sql_instructor_schedule);
        if ($stmt_schedule) {
            $stmt_schedule->bind_param("ii", $current_instructor_id_db, $selected_semester_id);
            if ($stmt_schedule->execute()) {
                $result_schedule = $stmt_schedule->get_result();
                $events_for_calendar = [];

                try {
                    $semester_start_dt = new DateTime($current_semester_details_for_calendar['StartDate']);
                    $semester_end_dt = new DateTime($current_semester_details_for_calendar['EndDate']);
                    $semester_end_dt->modify('+1 day'); // Include the end date in period
                    $date_interval = new DateInterval('P1D');
                    $date_period = new DatePeriod($semester_start_dt, $date_interval, $semester_end_dt);

                    while ($row = $result_schedule->fetch_assoc()) {
                        $event_day_name = $row['DayOfWeek'];
                        foreach ($date_period as $date_in_sem) {
                            if ($date_in_sem->format('l') === $event_day_name) { // 'l' gives full day name e.g. Monday
                                $events_for_calendar[] = [
                                    'id' => $row['ScheduleID'] . '_' . $date_in_sem->format('Ymd'),
                                    'title' => ($row['CourseID'] ?? 'N/A') . ": " . ($row['CourseName'] ?? 'Scheduled Class'),
                                    'start' => $date_in_sem->format('Y-m-d') . ' ' . $row['StartTime'],
                                    'end' => $date_in_sem->format('Y-m-d') . ' ' . $row['EndTime'],
                                    'extendedProps' => [
                                        'room' => $row['RoomCode'] ?? 'N/A',
                                        'course_code' => $row['CourseID'] ?? 'N/A',
                                        'full_course_name' => $row['CourseName'] ?? 'N/A'
                                        // Lecturer name is known (it's the current user)
                                    ]
                                    // Consider adding specific colors for different courses or types if desired
                                    // 'backgroundColor' => '#dc3545',
                                    // 'borderColor' => '#b02a37'
                                ];
                            }
                        }
                    }
                } catch (Exception $e) {
                    error_log("InstructorMySchedule: Error processing dates for calendar events: " . $e->getMessage());
                }
                
                $schedule_events_json = json_encode($events_for_calendar);
                if ($schedule_events_json === false) {
                    error_log("InstructorMySchedule: Failed to JSON encode schedule events. Error: " . json_last_error_msg());
                    $schedule_events_json = '[]'; // Fallback
                }
                $result_schedule->free();
            } else { error_log("InstructorMySchedule: Failed to execute schedule query: " . $stmt_schedule->error); }
            $stmt_schedule->close();
        } else { error_log("InstructorMySchedule: Failed to prepare schedule query: " . $conn->error); }
    }
}

// --- PHP Logic for Layout (Menu definition, etc.) ---
$menu_items_layout = [
    ['label' => 'Dashboard', 'icon' => 'fas fa-tachometer-alt', 'link' => 'instructor/index.php', 'id' => 'instr_dashboard'],
    ['label' => 'My Schedule', 'icon' => 'fas fa-calendar-alt', 'link' => 'instructor/my_schedule.php', 'id' => 'instr_my_schedule'],
    // ['label' => 'Set Unavailability', 'icon' => 'fas fa-user-clock', 'link' => 'instructor/unavailable_slots.php', 'id' => 'instr_unavailability'],
];
require_once __DIR__ . '/../includes/admin_sidebar_menu.php'; // Or your shared layout file
?>
<!-- Nối tiếp từ Part 1 (sau khi require_once layout) -->
<!-- START: Page-specific content for instructor/my_schedule.php -->
<div class="container-fluid pt-3">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
        <div></div> <!-- Placeholder for title alignment -->
        <form method="GET" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" class="d-flex align-items-center ms-md-auto">
            <label for="semester_select_instr" class="form-label me-2 mb-0 text-nowrap visually-hidden">Semester:</label>
            <select name="semester_id" id="semester_select_instr" class="form-select form-select-sm" style="min-width: 220px;" onchange="this.form.submit()" aria-label="Select semester">
                <?php if (empty($semesters_list)): ?>
                    <option value="">No semesters available</option>
                <?php else: ?>
                    <?php foreach ($semesters_list as $semester_item_sel): ?>
                        <option value="<?php echo $semester_item_sel['SemesterID']; ?>" <?php if ($semester_item_sel['SemesterID'] == $selected_semester_id) echo 'selected'; ?>>
                            <?php echo htmlspecialchars($semester_item_sel['SemesterName']); ?>
                            (<?php echo htmlspecialchars(format_date_for_display($semester_item_sel['StartDate'], 'd M Y')); ?> - <?php echo htmlspecialchars(format_date_for_display($semester_item_sel['EndDate'], 'd M Y')); ?>)
                        </option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>
        </form>
    </div>

    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">My Teaching Schedule - Weekly View</h6>
        </div>
        <div class="card-body">
            <div id="calendarContainerInstructor">
                <div id='calendarInstructor'>
                    <?php if (empty($schedule_events_json) || $schedule_events_json === '[]'): ?>
                        <div class="alert alert-info text-center" role="alert" id="calendarPlaceholderInstructor">
                            <i class="fas fa-calendar-times me-2"></i>
                            No teaching schedule found for you in the selected semester.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<!-- END: Page-specific content -->

<!-- Modal for Event Details -->
<div class="modal fade" id="instructorScheduleEventModal" tabindex="-1" aria-labelledby="instructorScheduleEventModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title" id="instructorScheduleEventModalLabel">Class Details</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p><strong class="text-primary">Course:</strong> <span id="modalInsSchCourseName"></span> (<span id="modalInsSchCourseCode"></span>)</p>
        <p><strong class="text-primary">Time:</strong> <span id="modalInsSchTime"></span></p>
        <p><strong class="text-primary">Day:</strong> <span id="modalInsSchDay"></span></p>
        <p><strong class="text-primary">Room:</strong> <span id="modalInsSchRoom"></span></p>
        <!-- Instructor's name is known, so not needed in modal unless showing for other instructors -->
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- Include FullCalendar JS and CSS. Ensure Bootstrap JS is also loaded (usually by layout) -->
<!-- Using local files as discussed -->
<link href='<?php echo BASE_URL; ?>assets/fullcalendar/main.min.css' rel='stylesheet' />
<script src='<?php echo BASE_URL; ?>assets/fullcalendar/index.global.min.js'></script> 
<!-- <script src='<?php //echo BASE_URL; ?>assets/fullcalendar/fullcalendar.min.js'></script> if you renamed it -->


<style>
    /* Shared layout CSS is inherited */
    /* Page-specific CSS for instructor/my_schedule.php */
    #calendarContainerInstructor {
        max-width: 1100px; margin: 10px auto;
        background-color: #fff; padding: 15px;
        border-radius: 0.375rem;
        box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.075);
    }
    #calendarInstructor .fc-event { cursor: pointer; font-size: 0.78em !important; }
    #calendarInstructor .fc .fc-daygrid-day.fc-day-today { background-color: var(--bs-info-bg-subtle, #eaf3ff) !important; }
    #instructorScheduleEventModal .modal-header { background-color: var(--primary-blue); color: white; }
    /* Add other FullCalendar specific styles if needed, similar to student's my_schedule */
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Sidebar toggle JS (should be handled by the main layout)
    // ... (If not, copy from previous student/my_schedule.php example)

    var calendarElInstructor = document.getElementById('calendarInstructor');
    var calendarPlaceholderInstructor = document.getElementById('calendarPlaceholderInstructor');

    if (calendarElInstructor) {
        // Check if FullCalendar library is loaded
        if (typeof FullCalendar === 'undefined' || typeof FullCalendar.Calendar === 'undefined') {
            console.error("FullCalendar library is not loaded for Instructor Schedule!");
            if(calendarPlaceholderInstructor) {
                calendarPlaceholderInstructor.innerHTML = '<i class="fas fa-exclamation-triangle me-2"></i> Calendar library could not be loaded.';
                calendarPlaceholderInstructor.classList.remove('alert-info');
                calendarPlaceholderInstructor.classList.add('alert-danger');
                calendarPlaceholderInstructor.style.display = 'block';
            }
            return;
        }

        var calendarEventsJsonStr = <?php echo $schedule_events_json ?: '[]'; ?>;
        var instructorCalendarEvents = [];

        if (typeof calendarEventsJsonStr === 'string') {
            try {
                instructorCalendarEvents = JSON.parse(calendarEventsJsonStr);
            } catch (e) {
                console.error("Error parsing instructor_schedule_events_json:", e, "Raw:", calendarEventsJsonStr);
            }
        } else if (Array.isArray(calendarEventsJsonStr)) { // Should not happen if PHP json_encode works
            instructorCalendarEvents = calendarEventsJsonStr;
        }

        if (instructorCalendarEvents.length === 0) {
            if (calendarPlaceholderInstructor && !calendarPlaceholderInstructor.classList.contains('alert-danger')) {
                calendarPlaceholderInstructor.style.display = 'block';
            }
        } else {
            if (calendarPlaceholderInstructor) calendarPlaceholderInstructor.style.display = 'none';

            var defaultInstructorView = window.innerWidth < 768 ? 'listWeek' : 'timeGridWeek';
            var instructorCalendar = new FullCalendar.Calendar(calendarElInstructor, {
                initialView: defaultInstructorView,
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek'
                },
                events: instructorCalendarEvents,
                editable: false,
                selectable: false,
                allDaySlot: false,
                slotMinTime: "07:00:00",
                slotMaxTime: "19:00:00", // Adjust as needed
                height: 'auto',
                contentHeight: 650, // Or 'auto' or aspectRatio
                eventTimeFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
                eventClick: function(info) {
                    document.getElementById('modalInsSchCourseName').textContent = info.event.extendedProps.full_course_name || 'N/A';
                    document.getElementById('modalInsSchCourseCode').textContent = info.event.extendedProps.course_code || 'N/A';
                    let start = info.event.start ? info.event.start.toLocaleTimeString([],{hour:'2-digit',minute:'2-digit',hour12:false}) : 'N/A';
                    let end = info.event.end ? info.event.end.toLocaleTimeString([],{hour:'2-digit',minute:'2-digit',hour12:false}) : 'N/A';
                    document.getElementById('modalInsSchTime').textContent = `${start} - ${end}`;
                    document.getElementById('modalInsSchDay').textContent = info.event.start ? info.event.start.toLocaleDateString(undefined, { weekday: 'long' }) : 'N/A';
                    document.getElementById('modalInsSchRoom').textContent = info.event.extendedProps.room || 'N/A';
                    
                    var eventModal = new bootstrap.Modal(document.getElementById('instructorScheduleEventModal'));
                    eventModal.show();
                }
            });
            instructorCalendar.render();
        }
    }
});
</script>
</body>
</html>