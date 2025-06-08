<?php
// htdocs/DSS/instructor/index.php

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db_connect.php'; // $conn

require_role(['instructor'], '../login.php');

$page_specific_title = "Instructor Dashboard";

$current_instructor_id_db = get_current_user_linked_entity_id();
$current_instructor_name = get_current_user_fullname();

$overview_stats = [
    'current_classes_teaching' => 0,         // Total scheduled class instances
    'distinct_courses_assigned' => 0,        // Number of unique course codes
    'total_actual_teaching_hours' => 0.0,    // Calculated from timeslot durations
    'average_students_per_class' => 0.0,
    'classes_morning' => 0,
    'classes_afternoon' => 0,
    // 'avg_break_time_minutes' => 0.0 // This is more complex, might do later or omit
];
$teaching_load_by_day_chart_data_json = '{}'; // Default to empty JSON object for Chart.js
$schedule_events_for_calendar_widget_json = '[]';
$active_semester_name = "N/A";
$active_semester_id = null;
$semester_start_date_for_calendar = null;
$semester_end_date_for_calendar = null;


if (isset($conn) && $conn instanceof mysqli && $current_instructor_id_db) {
    // 1. Determine the currently active semester
    $today = date('Y-m-d');
    $sql_active_semester = "SELECT SemesterID, SemesterName, StartDate, EndDate FROM Semesters
                            WHERE ('$today' BETWEEN StartDate AND EndDate) OR StartDate > '$today'
                            ORDER BY StartDate ASC LIMIT 1";
    $result_active_sem = $conn->query($sql_active_semester);
    if ($result_active_sem && $result_active_sem->num_rows > 0) {
        $active_semester_row = $result_active_sem->fetch_assoc();
        $active_semester_id = (int)$active_semester_row['SemesterID'];
        $active_semester_name = $active_semester_row['SemesterName'];
        $semester_start_date_for_calendar = $active_semester_row['StartDate'];
        $semester_end_date_for_calendar = $active_semester_row['EndDate'];
    }
    if($result_active_sem) $result_active_sem->free();


    if ($active_semester_id) {
        // 2. Fetch Overview Statistics for the active semester
        $stmt_overview = $conn->prepare(
            "SELECT 
                COUNT(sc.ScheduleID) AS total_classes,
                COUNT(DISTINCT sc.CourseID) AS distinct_courses,
                SUM(TIME_TO_SEC(TIMEDIFF(t.EndTime, t.StartTime))) / 3600 AS total_hours, 
                AVG(sc.NumStudents) AS avg_students 
             FROM ScheduledClasses sc
             JOIN TimeSlots t ON sc.TimeSlotID = t.TimeSlotID
             WHERE sc.LecturerID = ? AND sc.SemesterID = ?"
        );
        if ($stmt_overview) {
            $stmt_overview->bind_param("ii", $current_instructor_id_db, $active_semester_id);
            if ($stmt_overview->execute()) {
                $res_overview = $stmt_overview->get_result();
                if ($row_overview = $res_overview->fetch_assoc()) {
                    $overview_stats['current_classes_teaching'] = (int)$row_overview['total_classes'];
                    $overview_stats['distinct_courses_assigned'] = (int)$row_overview['distinct_courses'];
                    $overview_stats['total_actual_teaching_hours'] = round((float)($row_overview['total_hours'] ?? 0), 1);
                    $overview_stats['average_students_per_class'] = round((float)($row_overview['avg_students'] ?? 0), 1);
                }
            } else { error_log("InstructorDashboard: Error executing overview_stats query: " . $stmt_overview->error); }
            $stmt_overview->close();
        } else { error_log("InstructorDashboard: Error preparing overview_stats query: " . $conn->error); }

        // Distribution of classes (Morning/Afternoon) - Example: Morning ends at 12:30
        $stmt_session_dist = $conn->prepare(
            "SELECT 
                SUM(CASE WHEN t.StartTime < '12:30:00' THEN 1 ELSE 0 END) as morning_classes,
                SUM(CASE WHEN t.StartTime >= '12:30:00' THEN 1 ELSE 0 END) as afternoon_classes
             FROM ScheduledClasses sc
             JOIN TimeSlots t ON sc.TimeSlotID = t.TimeSlotID
             WHERE sc.LecturerID = ? AND sc.SemesterID = ?"
        );
        if ($stmt_session_dist) {
            $stmt_session_dist->bind_param("ii", $current_instructor_id_db, $active_semester_id);
            if ($stmt_session_dist->execute()) {
                $res_dist = $stmt_session_dist->get_result();
                if ($row_dist = $res_dist->fetch_assoc()) {
                    $overview_stats['classes_morning'] = (int)($row_dist['morning_classes'] ?? 0);
                    $overview_stats['classes_afternoon'] = (int)($row_dist['afternoon_classes'] ?? 0);
                }
            } else { error_log("InstructorDashboard: Error executing session_dist query: " . $stmt_session_dist->error); }
            $stmt_session_dist->close();
        } else { error_log("InstructorDashboard: Error preparing session_dist query: " . $conn->error); }


        // 3. Fetch data for "Teaching Load by Day" chart (same as before)
        $stmt_load_by_day = $conn->prepare(
            "SELECT t.DayOfWeek, COUNT(sc.ScheduleID) as class_count
             FROM ScheduledClasses sc
             JOIN TimeSlots t ON sc.TimeSlotID = t.TimeSlotID
             WHERE sc.LecturerID = ? AND sc.SemesterID = ?
             GROUP BY t.DayOfWeek
             ORDER BY FIELD(t.DayOfWeek, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday')"
        );
        if ($stmt_load_by_day) {
            $stmt_load_by_day->bind_param("ii", $current_instructor_id_db, $active_semester_id);
            $chart_data_points_load = ['Monday'=>0, 'Tuesday'=>0, 'Wednesday'=>0, 'Thursday'=>0, 'Friday'=>0, 'Saturday'=>0, 'Sunday'=>0];
            if ($stmt_load_by_day->execute()) {
                $res_load_by_day = $stmt_load_by_day->get_result();
                while ($row_lbd = $res_load_by_day->fetch_assoc()) {
                    if (isset($chart_data_points_load[$row_lbd['DayOfWeek']])) {
                        $chart_data_points_load[$row_lbd['DayOfWeek']] = (int)$row_lbd['class_count'];
                    }
                }
                $teaching_load_by_day_chart_data_json = json_encode([
                    'labels' => array_keys($chart_data_points_load),
                    'data' => array_values($chart_data_points_load)
                ]);
                if($teaching_load_by_day_chart_data_json === false) $teaching_load_by_day_chart_data_json = '{}';
            } else { error_log("InstructorDashboard: Error executing load_by_day query: " . $stmt_load_by_day->error); }
            $stmt_load_by_day->close();
        } else { error_log("InstructorDashboard: Error preparing load_by_day query: " . $conn->error); }


        // 4. Fetch schedule events for FullCalendar widget (same as before)
        $stmt_calendar_events = $conn->prepare(
            "SELECT sc.ScheduleID, c.CourseID, c.CourseName, cr.RoomCode, t.DayOfWeek, t.StartTime, t.EndTime
             FROM ScheduledClasses sc
             JOIN Courses c ON sc.CourseID = c.CourseID
             JOIN Classrooms cr ON sc.ClassroomID = cr.ClassroomID
             JOIN TimeSlots t ON sc.TimeSlotID = t.TimeSlotID
             WHERE sc.LecturerID = ? AND sc.SemesterID = ?"
        ); // ORDER BY is not strictly necessary for calendar events if processing all days
        if ($stmt_calendar_events) {
            $stmt_calendar_events->bind_param("ii", $current_instructor_id_db, $active_semester_id);
            $calendar_events_temp_array = [];
            if ($stmt_calendar_events->execute()) {
                $res_calendar_events = $stmt_calendar_events->get_result();
                if (isset($semester_start_date_for_calendar) && isset($semester_end_date_for_calendar)) {
                    try {
                        $sem_start_dt = new DateTime($semester_start_date_for_calendar);
                        $sem_end_dt = new DateTime($semester_end_date_for_calendar);
                        $sem_end_dt->modify('+1 day');
                        $interval = new DateInterval('P1D');
                        $period = new DatePeriod($sem_start_dt, $interval, $sem_end_dt);

                        while ($row_cal = $res_calendar_events->fetch_assoc()) {
                            foreach ($period as $dt_in_period) {
                                if ($dt_in_period->format('l') === $row_cal['DayOfWeek']) {
                                    $calendar_events_temp_array[] = [
                                        'id' => $row_cal['ScheduleID'] . '_' . $dt_in_period->format('Ymd'),
                                        'title' => ($row_cal['CourseID'] ?? '') . " - " . ($row_cal['CourseName'] ?? 'Class'),
                                        'start' => $dt_in_period->format('Y-m-d') . ' ' . $row_cal['StartTime'],
                                        'end' => $dt_in_period->format('Y-m-d') . ' ' . $row_cal['EndTime'],
                                        'extendedProps' => [ 'room' => $row_cal['RoomCode'] ?? 'N/A', /* ... */ ]
                                    ];
                                }
                            }
                        }
                    } catch (Exception $e) { error_log("InstructorDashboard: Calendar date processing error: " . $e->getMessage());}
                }
                $schedule_events_for_calendar_widget_json = json_encode($calendar_events_temp_array);
                if ($schedule_events_for_calendar_widget_json === false) $schedule_events_for_calendar_widget_json = '[]';
                if($res_calendar_events) $res_calendar_events->free();
            } else { error_log("InstructorDashboard: Error exec calendar_events query: " . $stmt_calendar_events->error); }
            $stmt_calendar_events->close();
        } else { error_log("InstructorDashboard: Error prep calendar_events query: " . $conn->error); }
    } // end if ($active_semester_id)
} // end if ($conn)
$menu_items_layout = [
    ['label' => 'Dashboard', 'icon' => 'fas fa-tachometer-alt', 'link' => 'instructor/index.php', 'id' => 'instr_dashboard'],
    ['label' => 'My Schedule', 'icon' => 'fas fa-calendar-alt', 'link' => 'instructor/my_schedule.php', 'id' => 'instr_my_schedule'],
    // Add other instructor-specific menu items here, e.g., for unavailable slots
    // ['label' => 'Set Unavailability', 'icon' => 'fas fa-user-clock', 'link' => 'instructor/unavailable_slots.php', 'id' => 'instr_unavailability'],
];
require_once __DIR__ . '/../includes/admin_sidebar_menu.php'; // Using the shared layout
?>
<!-- START: Page-specific content for instructor/index.php -->
<div class="container-fluid pt-3">

    <div class="row mb-4">
        <div class="col-12">
            <h1 class="h3 mb-0 text-gray-800">Welcome back, <?php echo htmlspecialchars($current_instructor_name ?? 'Instructor'); ?>!</h1>
            <?php if ($active_semester_id): ?>
                <p class="text-muted">Here's an overview of your activities for semester: <strong><?php echo htmlspecialchars($active_semester_name); ?></strong>.</p>
            <?php else: ?>
                <p class="text-muted">No active or upcoming semester found to display statistics.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Overview Stats - Updated and New Cards -->
    <div class="row">
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-primary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Classes Teaching</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $overview_stats['current_classes_teaching']; ?></div>
                        </div>
                        <div class="col-auto"><i class="fas fa-chalkboard-teacher fa-2x text-gray-300"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-success shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Total Teaching Hours</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo htmlspecialchars($overview_stats['total_actual_teaching_hours']); ?> hrs</div>
                        </div>
                        <div class="col-auto"><i class="fas fa-user-clock fa-2x text-gray-300"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-info shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Distinct Courses</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $overview_stats['distinct_courses_assigned']; ?></div>
                        </div>
                        <div class="col-auto"><i class="fas fa-book-open fa-2x text-gray-300"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-secondary shadow h-100 py-2"> <!-- Changed color -->
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-secondary text-uppercase mb-1">Avg. Students / Class</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo htmlspecialchars($overview_stats['average_students_per_class']); ?></div>
                        </div>
                        <div class="col-auto"><i class="fas fa-users fa-2x text-gray-300"></i></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content Row: Chart and Calendar Widget -->
    <div class="row">
        <div class="col-xl-<?php echo ($overview_stats['classes_morning'] > 0 || $overview_stats['classes_afternoon'] > 0) ? '5' : '7'; ?> col-lg-<?php echo ($overview_stats['classes_morning'] > 0 || $overview_stats['classes_afternoon'] > 0) ? '6' : '7'; ?> mb-4">
            <div class="card shadow h-100">
                <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary">Teaching Load by Day</h6></div>
                <div class="card-body"><div class="chart-area" style="height: 320px;"><canvas id="teachingHoursByDayChart"></canvas></div></div>
            </div>
        </div>

        <?php if ($overview_stats['classes_morning'] > 0 || $overview_stats['classes_afternoon'] > 0): ?>
        <div class="col-xl-2 col-lg-6 mb-4"> <!-- Smaller column for Pie chart -->
            <div class="card shadow h-100">
                <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary">Session Distribution</h6></div>
                <div class="card-body d-flex align-items-center justify-content-center">
                    <div class="chart-pie pt-4" style="height: 280px; width:100%;">
                        <canvas id="sessionDistributionPieChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>


        <div class="col-xl-<?php echo ($overview_stats['classes_morning'] > 0 || $overview_stats['classes_afternoon'] > 0) ? '5' : '5'; ?> col-lg-12 mb-4"> <!-- Adjusted col size for calendar -->
            <div class="card shadow h-100">
                <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary">My Schedule (Current Week)</h6></div>
                <div class="card-body p-2">
                    <div id="instructorCalendarWidget" style="max-height: 365px; overflow: auto;">
                         <?php if (empty($schedule_events_for_calendar_widget_json) || $schedule_events_for_calendar_widget_json === '[]'): ?>
                            <div class="alert alert-light text-center m-3" role="alert">
                                <i class="fas fa-calendar-times me-2"></i> No classes scheduled.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal for Event Details (similar to student's my_schedule) -->
<div class="modal fade" id="instructorEventModal" tabindex="-1" aria-labelledby="instructorEventModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title" id="instructorEventModalLabel">Class Details</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p><strong class="text-primary">Course:</strong> <span id="modalInsCourseName"></span> (<span id="modalInsCourseCode"></span>)</p>
        <p><strong class="text-primary">Time:</strong> <span id="modalInsTime"></span></p>
        <p><strong class="text-primary">Day:</strong> <span id="modalInsDay"></span></p>
        <p><strong class="text-primary">Room:</strong> <span id="modalInsRoom"></span></p>
        <!-- Add more details if needed, e.g., number of students -->
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>


<!-- Nối tiếp từ Part 2/N của instructor/index.php (phần HTML body) -->
<style>
    /* CSS from your previous submission, kept as is */
    /* ... (Toàn bộ CSS Cậu đã cung cấp cho instructor/index.php) ... */
    .card .card-header { padding: 0.75rem 1.25rem; }
    .border-left-primary { border-left: .25rem solid var(--bs-primary)!important; }
    .border-left-success { border-left: .25rem solid var(--bs-success)!important; }
    .border-left-info    { border-left: .25rem solid var(--bs-info)!important; }
    .border-left-warning { border-left: .25rem solid var(--bs-warning)!important; }
    .text-xs { font-size: .7rem; }
    .text-gray-300 { color: #dddfeb!important; }
    .text-gray-800 { color: #5a5c69!important; }
    .font-weight-bold { font-weight: 700!important; }
    #instructorCalendarWidget .fc-toolbar.fc-header-toolbar { margin-bottom: 0.5rem !important; font-size: 0.8em; }
    #instructorCalendarWidget .fc-toolbar-title { font-size: 1.2em !important; }
    #instructorCalendarWidget .fc-event { font-size: 0.75em !important; padding: 2px 4px !important; }
    #instructorCalendarWidget .fc-daygrid-day-number,
    #instructorCalendarWidget .fc-col-header-cell-cushion { font-size: 0.8em; }
    #instructorCalendarWidget { min-height: 320px; }
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<!-- Using local FullCalendar files as discussed -->
<link href='<?php echo BASE_URL; ?>assets/fullcalendar/main.min.css' rel='stylesheet' />
<script src='<?php echo BASE_URL; ?>assets/fullcalendar/index.global.min.js'></script>
<!-- Bootstrap Bundle JS (should be loaded by layout, ensure it's available for Modals) -->
<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script> -->


<script>
document.addEventListener('DOMContentLoaded', function() {
    // Sidebar toggle JS (if not handled by a global layout script)
    // ... (Assume this logic is present or handled globally)

    // 1. Teaching Hours by Day Chart (Bar Chart)
    const teachingHoursCtx = document.getElementById('teachingHoursByDayChart');
    if (teachingHoursCtx) {
        let chartDataLoad = null;
        let rawChartDataLoad = <?php echo $teaching_load_by_day_chart_data_json ?: 'null'; ?>;

        if (typeof rawChartDataLoad === 'string' && rawChartDataLoad.trim() !== '' && rawChartDataLoad.trim() !== '{}') {
            try {
                chartDataLoad = JSON.parse(rawChartDataLoad);
            } catch (e) {
                console.error("Error parsing teaching_hours_by_day_chart_data_json:", e, "Raw data:", rawChartDataLoad);
                chartDataLoad = null;
            }
        } else if (typeof rawChartDataLoad === 'object' && rawChartDataLoad !== null) {
            chartDataLoad = rawChartDataLoad;
        }

        if (chartDataLoad && chartDataLoad.labels && chartDataLoad.labels.length > 0 && chartDataLoad.data && chartDataLoad.data.length > 0) {
            new Chart(teachingHoursCtx, {
                type: 'bar',
                data: {
                    labels: chartDataLoad.labels,
                    datasets: [{
                        label: 'Classes per Day',
                        data: chartDataLoad.data,
                        backgroundColor: 'rgba(0, 92, 158, 0.7)',
                        borderColor: 'rgba(0, 92, 158, 1)',
                        borderWidth: 1,
                        hoverBackgroundColor: 'rgba(0, 75, 128, 0.9)'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: { y: { beginAtZero: true, ticks: { stepSize: 1, callback: function(value) { if (Number.isInteger(value)) { return value; } } } } },
                    plugins: { legend: { display: false }, tooltip: { callbacks: { label: function(context) { return `Classes: ${context.raw}`; } } } }
                }
            });
        } else {
            if(teachingHoursCtx.getContext('2d')) {
                const ctx = teachingHoursCtx.getContext('2d');
                ctx.font = "14px Segoe UI"; // Slightly smaller font for placeholder
                ctx.fillStyle = "#6c757d"; // Bootstrap text-muted color
                ctx.textAlign = "center";
                ctx.fillText("No teaching load data available for this semester.", teachingHoursCtx.width / 2, teachingHoursCtx.height / 2);
            }
        }
    }

    // 2. Session Distribution Chart (Pie Chart)
    const sessionDistCtx = document.getElementById('sessionDistributionPieChart');
    if (sessionDistCtx) {
        const morningClasses = <?php echo $overview_stats['classes_morning'] ?: 0; ?>;
        const afternoonClasses = <?php echo $overview_stats['classes_afternoon'] ?: 0; ?>;

        if (morningClasses > 0 || afternoonClasses > 0) {
            new Chart(sessionDistCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Morning Sessions', 'Afternoon Sessions'],
                    datasets: [{
                        data: [morningClasses, afternoonClasses],
                        backgroundColor: ['rgba(255, 159, 64, 0.7)', 'rgba(54, 162, 235, 0.7)'],
                        borderColor: ['rgba(255, 159, 64, 1)', 'rgba(54, 162, 235, 1)'],
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'top', labels: { font: { size: 10 } } }, // Smaller legend font
                        tooltip: { callbacks: { label: function(context) {
                            let label = context.label || '';
                            if (label) { label += ': '; }
                            if (context.parsed !== null) { label += context.parsed; }
                            return label + ' classes';
                        }}}
                    }
                }
            });
        } else {
            const parentCardPie = sessionDistCtx.closest('.card');
            if (parentCardPie) { // Hide the card if no data for pie chart
                // parentCardPie.style.display = 'none';
                 if(sessionDistCtx.getContext('2d')) {
                    const ctxPie = sessionDistCtx.getContext('2d');
                    ctxPie.font = "14px Segoe UI";
                    ctxPie.fillStyle = "#6c757d";
                    ctxPie.textAlign = "center";
                    ctxPie.fillText("No session data.", sessionDistCtx.width / 2, sessionDistCtx.height / 2);
                }
            }
        }
    }

    // 3. Instructor Calendar Widget (FullCalendar)
    const calendarWidgetEl = document.getElementById('instructorCalendarWidget');
    var calendarWidget = null; // Declare here to make it accessible in broader scope if needed later

    if (calendarWidgetEl) {
        if (typeof FullCalendar === 'undefined' || typeof FullCalendar.Calendar === 'undefined') {
            console.error("FullCalendar library is not loaded for Instructor Dashboard!");
            calendarWidgetEl.innerHTML = '<p class="text-danger text-center p-3 fw-bold"><i class="fas fa-exclamation-circle me-1"></i> Calendar library could not be loaded. Please check setup.</p>';
        } else {
            let rawCalendarEventsPHP = <?php echo $schedule_events_for_calendar_widget_json ?: '[]'; ?>;
            let instructorCalendarEventsData = [];

            if (typeof rawCalendarEventsPHP === 'string' && rawCalendarEventsPHP.trim() !== '') {
                try {
                    instructorCalendarEventsData = JSON.parse(rawCalendarEventsPHP);
                    if (!Array.isArray(instructorCalendarEventsData)) {
                        instructorCalendarEventsData = [];
                    }
                } catch (e) {
                    console.error("Error parsing instructor_schedule_events_json:", e, "Raw:", rawCalendarEventsPHP);
                }
            } else if (Array.isArray(rawCalendarEventsPHP)) {
                instructorCalendarEventsData = rawCalendarEventsPHP;
            }

            const calendarPlaceholderWidget = calendarWidgetEl.querySelector('.alert'); // PHP might render this
            
            if (instructorCalendarEventsData.length > 0) {
                if (calendarPlaceholderWidget) calendarPlaceholderWidget.style.display = 'none'; // Hide placeholder

                calendarWidget = new FullCalendar.Calendar(calendarWidgetEl, { // Assign to the pre-declared variable
                    initialView: 'timeGridWeek',
                    headerToolbar: {
                        left: 'prev,next',
                        center: 'title',
                        right: 'today timeGridWeek,dayGridMonth,listWeek' // Added listWeek
                    },
                    events: instructorCalendarEventsData,
                    editable: false,
                    selectable: false,
                    allDaySlot: false,
                    slotMinTime: "07:00:00",
                    slotMaxTime: "19:00:00",
                    height: 'auto', // Will respect contentHeight or parent's height
                    contentHeight: 350, // Explicit height for the scrollable area
                    eventTimeFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
                    slotLabelFormat: { hour: 'numeric', minute: '2-digit', meridiem: false, hour12: false }, // e.g. 7:00
                    nowIndicator: true, // Show current time indicator
                    navLinks: true, // Click day/week names to navigate views
                    eventClick: function(info) {
                        const modalInsSchCourseName = document.getElementById('modalInsSchCourseName');
                        const modalInsSchCourseCode = document.getElementById('modalInsSchCourseCode');
                        const modalInsSchTime = document.getElementById('modalInsSchTime');
                        const modalInsSchDay = document.getElementById('modalInsSchDay');
                        const modalInsSchRoom = document.getElementById('modalInsSchRoom');

                        if(modalInsSchCourseName) modalInsSchCourseName.textContent = info.event.extendedProps.full_course_name || 'N/A';
                        if(modalInsSchCourseCode) modalInsSchCourseCode.textContent = info.event.extendedProps.course_code || 'N/A';
                        
                        let start = info.event.start ? info.event.start.toLocaleTimeString([],{hour:'2-digit',minute:'2-digit',hour12:false}) : 'N/A';
                        let end = info.event.end ? info.event.end.toLocaleTimeString([],{hour:'2-digit',minute:'2-digit',hour12:false}) : 'N/A';
                        if(modalInsSchTime) modalInsSchTime.textContent = `${start} - ${end}`;
                        
                        if(modalInsSchDay) modalInsSchDay.textContent = info.event.start ? info.event.start.toLocaleDateString(undefined, { weekday: 'long' }) : 'N/A';
                        if(modalInsSchRoom) modalInsSchRoom.textContent = info.event.extendedProps.room || 'N/A';
                        
                        var eventModalEl = document.getElementById('instructorScheduleEventModal');
                        if (eventModalEl) {
                            var eventModal = new bootstrap.Modal(eventModalEl);
                            eventModal.show();
                        }
                    }
                });
                calendarWidget.render();
            } else {
                // If PHP didn't render a placeholder and events are empty, ensure widget shows a message
                if (!calendarPlaceholderWidget) { // PHP did not output a placeholder
                    calendarWidgetEl.innerHTML = '<p class="text-muted text-center p-3">No scheduled classes found for this semester in the calendar.</p>';
                }
            }
        }
    }
});
</script>
</body>
</html>