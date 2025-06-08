<?php
// htdocs/DSS/admin/index.php

// Stage 1: Include necessary files and check permissions
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db_connect.php'; // $conn should be available after this
require_role(['admin'], '../login.php'); // Redirect to login if not admin

// --- STAGE 2: FETCH DATA FOR THE DASHBOARD ---
$page_title = "Admin Dashboard"; // Page title, used by the layout file
$current_user_fullname_page = get_current_user_fullname();

// Initialize data structures for the dashboard
$stats = [
    'total_instructors' => 0, 'total_students' => 0, 'total_courses' => 0,
    'total_classrooms' => 0, 'scheduled_classes_current_semester' => 0,
    'active_semester_name' => 'N/A',
];
$new_courses_data = []; // For "Recently Added Courses" section

// Data for charts
$course_activity_labels_js = []; $course_activity_data_js = [];
$room_utilization_labels_js = ['Used Rooms', 'Available Rooms']; $room_utilization_data_js = [0, 0];
$instructor_load_labels_js = []; $instructor_load_data_js = [];

// Data for calendar widget
$calendar_data = [
    'year' => date('Y'), 'month' => date('m'), 'month_name' => date('F Y'),
    'days_in_month' => 0, 'first_day_of_week' => 0, 'scheduled_dates' => []
];

$current_semester_id = null;
$active_semester_start_date = null;
$active_semester_end_date = null;

// --- Data Fetching Logic (if $conn is valid) ---
if (isset($conn) && $conn instanceof mysqli) {
    // Fetch general statistics
    $queries_stats = [
        'total_instructors' => "SELECT COUNT(*) as total FROM Lecturers",
        'total_students' => "SELECT COUNT(*) as total FROM Students",
        'total_courses' => "SELECT COUNT(*) as total FROM Courses",
        'total_classrooms' => "SELECT COUNT(*) as total FROM Classrooms",
    ];
    foreach ($queries_stats as $key => $sql) {
        $result_stat = $conn->query($sql);
        if ($result_stat && $result_stat->num_rows > 0) {
            $stats[$key] = $result_stat->fetch_assoc()['total'] ?? 0;
        }
    }

    // Determine the "active" semester
    $today_date = date('Y-m-d');
    $semester_found = false;
    // Prioritize: 1. Current, 2. Upcoming, 3. Most Recent Past
    $semester_queries = [
        "SELECT SemesterID, SemesterName, StartDate, EndDate FROM Semesters WHERE StartDate <= ? AND EndDate >= ? ORDER BY StartDate DESC LIMIT 1", // Current
        "SELECT SemesterID, SemesterName, StartDate, EndDate FROM Semesters WHERE StartDate > ? ORDER BY StartDate ASC LIMIT 1",      // Upcoming
        "SELECT SemesterID, SemesterName, StartDate, EndDate FROM Semesters ORDER BY EndDate DESC LIMIT 1"                             // Recent Past
    ];
    $params_sem_types = ["ss", "s", ""]; // Parameter types for bind_param
    $params_sem_values = [[$today_date, $today_date], [$today_date], []]; // Parameter values
    $status_suffix = ["", " (Upcoming)", " (Recent Past)"];

    for ($i_sem_q = 0; $i_sem_q < count($semester_queries) && !$semester_found; $i_sem_q++) {
        $stmt_sem = $conn->prepare($semester_queries[$i_sem_q]);
        if ($stmt_sem) {
            if (!empty($params_sem_types[$i_sem_q])) {
                $stmt_sem->bind_param($params_sem_types[$i_sem_q], ...$params_sem_values[$i_sem_q]);
            }
            if ($stmt_sem->execute()) {
                $res_sem = $stmt_sem->get_result();
                if ($row_sem = $res_sem->fetch_assoc()) {
                    $current_semester_id = (int)$row_sem['SemesterID'];
                    $stats['active_semester_name'] = htmlspecialchars($row_sem['SemesterName']) . $status_suffix[$i_sem_q];
                    $active_semester_start_date = $row_sem['StartDate'];
                    $active_semester_end_date = $row_sem['EndDate'];
                    $semester_found = true;
                }
            } else {
                error_log("Admin Dashboard: Semester query execution failed: " . $stmt_sem->error);
            }
            $stmt_sem->close();
        } else {
            error_log("Admin Dashboard: Semester query preparation failed: " . $conn->error);
        }
    }

    if ($current_semester_id) {
        // Number of scheduled classes for the active semester
        $stmt_scheduled_count = $conn->prepare("SELECT COUNT(ScheduleID) as total FROM ScheduledClasses WHERE SemesterID = ?");
        if($stmt_scheduled_count) {
            $stmt_scheduled_count->bind_param("i", $current_semester_id); 
            if ($stmt_scheduled_count->execute()) {
                $stats['scheduled_classes_current_semester'] = $stmt_scheduled_count->get_result()->fetch_assoc()['total'] ?? 0;
            } else {
                error_log("Admin Dashboard: Scheduled classes count query execution failed: " . $stmt_scheduled_count->error);
            }
            $stmt_scheduled_count->close();
        } else {
            error_log("Admin Dashboard: Scheduled classes count query preparation failed: " . $conn->error);
        }

        // Data for "Events Overview" Calendar (first month of the active semester)
        if ($active_semester_start_date) {
            try {
                $semester_start_datetime = new DateTime($active_semester_start_date);
                $calendar_data['year'] = $semester_start_datetime->format('Y');
                $calendar_data['month'] = $semester_start_datetime->format('m'); // month as 01-12
                $calendar_data['month_name'] = $semester_start_datetime->format('F Y');
            } catch (Exception $e) { 
                error_log("Admin Dashboard: Error parsing semester start date for calendar: " . $e->getMessage());
                // Defaults will be used
            }
        }
        $calendar_data['days_in_month'] = (int)date('t', strtotime("{$calendar_data['year']}-{$calendar_data['month']}-01"));
        $calendar_data['first_day_of_week'] = (int)date('w', strtotime("{$calendar_data['year']}-{$calendar_data['month']}-01")); // 0 (Sun) to 6 (Sat)

        // SQL to get days with scheduled events for the calendar month
        // IMPORTANT: The ` tộc` derived table is a trick to iterate through days. Ensure it covers enough days for a semester.
        // Ensure TimeSlots table has MySQLDayOfWeekValue (1=Sunday, 2=Monday, ..., 7=Saturday) column.
        // This query can be complex and might need optimization for very large datasets.
        $sql_calendar_events = "
            SELECT DISTINCT DAY(computed_date) as EventDay
            FROM (
                SELECT 
                    DATE_ADD(sem.StartDate, INTERVAL 
                        (ts.MySQLDayOfWeekValue - DAYOFWEEK(DATE_ADD(sem.StartDate, INTERVAL tộc.idx DAY)) + 7 + tộc.idx) % 7 DAY
                    ) as computed_date
                FROM ScheduledClasses sc
                JOIN TimeSlots ts ON sc.TimeSlotID = ts.TimeSlotID
                JOIN Semesters sem ON sc.SemesterID = sem.SemesterID
                CROSS JOIN (
                    SELECT 0 idx UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL
                    SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9 UNION ALL SELECT 10 UNION ALL SELECT 11 UNION ALL SELECT 12 UNION ALL SELECT 13 UNION ALL
                    SELECT 14 UNION ALL SELECT 15 UNION ALL SELECT 16 UNION ALL SELECT 17 UNION ALL SELECT 18 UNION ALL SELECT 19 UNION ALL SELECT 20 UNION ALL
                    SELECT 21 UNION ALL SELECT 22 UNION ALL SELECT 23 UNION ALL SELECT 24 UNION ALL SELECT 25 UNION ALL SELECT 26 UNION ALL SELECT 27 UNION ALL
                    SELECT 28 UNION ALL SELECT 29 UNION ALL SELECT 30 UNION ALL SELECT 31 UNION ALL SELECT 32 UNION ALL SELECT 33 UNION ALL SELECT 34 UNION ALL
                    SELECT 35 UNION ALL SELECT 36 UNION ALL SELECT 37 UNION ALL SELECT 38 UNION ALL SELECT 39 UNION ALL SELECT 40 UNION ALL SELECT 41 UNION ALL
                    SELECT 42 UNION ALL SELECT 43 UNION ALL SELECT 44 UNION ALL SELECT 45 UNION ALL SELECT 46 UNION ALL SELECT 47 UNION ALL SELECT 48 UNION ALL
                    SELECT 49 UNION ALL SELECT 50 UNION ALL SELECT 51 UNION ALL SELECT 52 UNION ALL SELECT 53 UNION ALL SELECT 54 UNION ALL SELECT 55 UNION ALL
                    SELECT 56 UNION ALL SELECT 57 UNION ALL SELECT 58 UNION ALL SELECT 59 UNION ALL SELECT 60 UNION ALL SELECT 61 UNION ALL SELECT 62 UNION ALL
                    SELECT 63 UNION ALL SELECT 64 UNION ALL SELECT 65 UNION ALL SELECT 66 UNION ALL SELECT 67 UNION ALL SELECT 68 UNION ALL SELECT 69 UNION ALL
                    SELECT 70 UNION ALL SELECT 71 UNION ALL SELECT 72 UNION ALL SELECT 73 UNION ALL SELECT 74 UNION ALL SELECT 75 UNION ALL SELECT 76 UNION ALL
                    SELECT 77 UNION ALL SELECT 78 UNION ALL SELECT 79 UNION ALL SELECT 80 UNION ALL SELECT 81 UNION ALL SELECT 82 UNION ALL SELECT 83 UNION ALL
                    SELECT 84 UNION ALL SELECT 85 UNION ALL SELECT 86 UNION ALL SELECT 87 UNION ALL SELECT 88 UNION ALL SELECT 89 UNION ALL SELECT 90
                ) tộc
                WHERE sc.SemesterID = ?
                  AND ts.MySQLDayOfWeekValue IS NOT NULL
                  AND DATE_ADD(sem.StartDate, INTERVAL tộc.idx DAY) BETWEEN sem.StartDate AND sem.EndDate
            ) AS daily_events
            WHERE MONTH(computed_date) = ? AND YEAR(computed_date) = ?
        ";

        // A simpler alternative for calendar if the above is too complex or slow,
        // assuming TimeSlots.DayOfWeek maps to actual dates within semester:
        // This requires pre-calculating event dates or a different table structure.
        // For now, using the complex query logic as provided initially.
        // You might need to add a 'MySQLDayOfWeekValue' (1=Sun, 2=Mon,...) column to your TimeSlots table.
        // Example values for MySQLDayOfWeekValue: Sunday=1, Monday=2, ..., Saturday=7.
        // This can be added with: ALTER TABLE TimeSlots ADD COLUMN MySQLDayOfWeekValue INT;
        // Then update it: UPDATE TimeSlots SET MySQLDayOfWeekValue = DAYOFWEEK(STR_TO_DATE(DayOfWeek, '%W')); (Adjust based on DayOfWeek format)
        // Or more robustly:
        // UPDATE TimeSlots SET MySQLDayOfWeekValue = CASE DayOfWeek 
        //    WHEN 'Sunday' THEN 1 WHEN 'Monday' THEN 2 WHEN 'Tuesday' THEN 3 
        //    WHEN 'Wednesday' THEN 4 WHEN 'Thursday' THEN 5 WHEN 'Friday' THEN 6 
        //    WHEN 'Saturday' THEN 7 ELSE NULL END;

        $stmt_calendar_events = $conn->prepare($sql_calendar_events);
        if ($stmt_calendar_events) {
            $calendar_month_int = (int)$calendar_data['month'];
            $calendar_year_int = (int)$calendar_data['year'];
            $stmt_calendar_events->bind_param("iii", $current_semester_id, $calendar_month_int, $calendar_year_int);
            if ($stmt_calendar_events->execute()) {
                $res_calendar_events = $stmt_calendar_events->get_result();
                while ($row_cal_event = $res_calendar_events->fetch_assoc()) {
                    $calendar_data['scheduled_dates'][] = (int)$row_cal_event['EventDay'];
                }
                $calendar_data['scheduled_dates'] = array_unique($calendar_data['scheduled_dates']);
            } else {
                error_log("Admin Dashboard: Calendar events query execution failed: " . $stmt_calendar_events->error);
            }
            $stmt_calendar_events->close();
        } else { 
            error_log("Admin Dashboard: Calendar events query preparation failed: " . $conn->error . " | SQL: " . $sql_calendar_events); 
        }

        // Data for "Scheduled Classes Activity" Chart
        if ($active_semester_start_date && $active_semester_end_date) {
            // This query also relies on ` tộc` and `MySQLDayOfWeekValue`
            $sql_course_activity_chart = "
                SELECT 
                    MONTH(computed_date) as EventMonth, 
                    COUNT(DISTINCT sc.ScheduleID) as ClassCount
                FROM (
                    SELECT 
                        sc.ScheduleID,
                        DATE_ADD(sem.StartDate, INTERVAL 
                            (ts.MySQLDayOfWeekValue - DAYOFWEEK(DATE_ADD(sem.StartDate, INTERVAL tộc.idx DAY)) + 7 + tộc.idx) % 7 DAY
                        ) as computed_date
                    FROM ScheduledClasses sc
                    JOIN TimeSlots ts ON sc.TimeSlotID = ts.TimeSlotID
                    JOIN Semesters sem ON sc.SemesterID = sem.SemesterID
                    CROSS JOIN (
                        SELECT 0 idx UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL
                        SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9 UNION ALL SELECT 10 UNION ALL SELECT 11 UNION ALL SELECT 12 UNION ALL SELECT 13 UNION ALL
                        SELECT 14 UNION ALL SELECT 15 UNION ALL SELECT 16 UNION ALL SELECT 17 UNION ALL SELECT 18 UNION ALL SELECT 19 UNION ALL SELECT 20 UNION ALL
                        SELECT 21 UNION ALL SELECT 22 UNION ALL SELECT 23 UNION ALL SELECT 24 UNION ALL SELECT 25 UNION ALL SELECT 26 UNION ALL SELECT 27 UNION ALL
                        SELECT 28 UNION ALL SELECT 29 UNION ALL SELECT 30 UNION ALL SELECT 31 UNION ALL SELECT 32 UNION ALL SELECT 33 UNION ALL SELECT 34 UNION ALL
                        SELECT 35 UNION ALL SELECT 36 UNION ALL SELECT 37 UNION ALL SELECT 38 UNION ALL SELECT 39 UNION ALL SELECT 40 UNION ALL SELECT 41 UNION ALL
                        SELECT 42 UNION ALL SELECT 43 UNION ALL SELECT 44 UNION ALL SELECT 45 UNION ALL SELECT 46 UNION ALL SELECT 47 UNION ALL SELECT 48 UNION ALL
                        SELECT 49 UNION ALL SELECT 50 UNION ALL SELECT 51 UNION ALL SELECT 52 UNION ALL SELECT 53 UNION ALL SELECT 54 UNION ALL SELECT 55 UNION ALL
                        SELECT 56 UNION ALL SELECT 57 UNION ALL SELECT 58 UNION ALL SELECT 59 UNION ALL SELECT 60 UNION ALL SELECT 61 UNION ALL SELECT 62 UNION ALL
                        SELECT 63 UNION ALL SELECT 64 UNION ALL SELECT 65 UNION ALL SELECT 66 UNION ALL SELECT 67 UNION ALL SELECT 68 UNION ALL SELECT 69 UNION ALL
                        SELECT 70 UNION ALL SELECT 71 UNION ALL SELECT 72 UNION ALL SELECT 73 UNION ALL SELECT 74 UNION ALL SELECT 75 UNION ALL SELECT 76 UNION ALL
                        SELECT 77 UNION ALL SELECT 78 UNION ALL SELECT 79 UNION ALL SELECT 80 UNION ALL SELECT 81 UNION ALL SELECT 82 UNION ALL SELECT 83 UNION ALL
                        SELECT 84 UNION ALL SELECT 85 UNION ALL SELECT 86 UNION ALL SELECT 87 UNION ALL SELECT 88 UNION ALL SELECT 89 UNION ALL SELECT 90
                    ) tộc
                    WHERE sc.SemesterID = ?
                      AND ts.MySQLDayOfWeekValue IS NOT NULL
                      AND DATE_ADD(sem.StartDate, INTERVAL tộc.idx DAY) BETWEEN sem.StartDate AND sem.EndDate
                ) AS monthly_activity
                GROUP BY EventMonth ORDER BY EventMonth ASC";
            $stmt_course_activity_chart = $conn->prepare($sql_course_activity_chart);
            if ($stmt_course_activity_chart) {
                $stmt_course_activity_chart->bind_param("i", $current_semester_id);
                if ($stmt_course_activity_chart->execute()) {
                    $res_course_activity_chart = $stmt_course_activity_chart->get_result();
                    $monthly_counts_from_db = [];
                    while ($row_chart = $res_course_activity_chart->fetch_assoc()) {
                        $monthly_counts_from_db[(int)$row_chart['EventMonth']] = (int)$row_chart['ClassCount'];
                    }
                    
                    $month_names_short = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                    $course_activity_labels_js = $month_names_short; // Use all 12 months for labels
                    $course_activity_data_js = array_fill(0, 12, 0); // Initialize with zeros

                    // Populate data for the months within the semester range
                    $start_month = (int)(new DateTime($active_semester_start_date))->format('n');
                    $end_month = (int)(new DateTime($active_semester_end_date))->format('n');
                    
                    // Handle semesters spanning across year-end if necessary (more complex)
                    // For simplicity, this example assumes semester within one calendar year or focuses on first year part.
                    for ($m_idx = 0; $m_idx < 12; $m_idx++) {
                        $current_month_loop = $m_idx + 1;
                        // Only populate if month is within semester (simple check for same-year semester)
                        // if ($current_month_loop >= $start_month && $current_month_loop <= $end_month) {
                           if (isset($monthly_counts_from_db[$current_month_loop])) {
                               $course_activity_data_js[$m_idx] = $monthly_counts_from_db[$current_month_loop];
                           }
                        // }
                    }
                } else {
                    error_log("Admin Dashboard: Course activity chart query execution failed: " . $stmt_course_activity_chart->error);
                }
                $stmt_course_activity_chart->close();
            } else { 
                error_log("Admin Dashboard: Course activity chart query preparation failed: " . $conn->error . " | SQL: " . $sql_course_activity_chart);
            }
        }

        // Room Utilization Chart Data
        $total_classrooms_stat = $stats['total_classrooms'];
        $used_classrooms_count_chart = 0;
        if ($total_classrooms_stat > 0) {
            $stmt_used_rooms_chart = $conn->prepare("SELECT COUNT(DISTINCT ClassroomID) as used_count FROM ScheduledClasses WHERE SemesterID = ? AND ClassroomID IS NOT NULL");
            if ($stmt_used_rooms_chart) {
                $stmt_used_rooms_chart->bind_param("i", $current_semester_id); 
                if ($stmt_used_rooms_chart->execute()) {
                    $used_classrooms_count_chart = $stmt_used_rooms_chart->get_result()->fetch_assoc()['used_count'] ?? 0;
                } else {
                     error_log("Admin Dashboard: Used rooms chart query execution failed: " . $stmt_used_rooms_chart->error);
                }
                $stmt_used_rooms_chart->close();
            } else {
                 error_log("Admin Dashboard: Used rooms chart query preparation failed: " . $conn->error);
            }
            $room_utilization_data_js = [$used_classrooms_count_chart, max(0, $total_classrooms_stat - $used_classrooms_count_chart)];
        }

        // Instructor Load Chart Data (Top 10)
        $sql_instructor_load_chart = "SELECT l.LecturerName, COUNT(sc.ScheduleID) as class_count 
                               FROM ScheduledClasses sc JOIN Lecturers l ON sc.LecturerID = l.LecturerID
                               WHERE sc.SemesterID = ? GROUP BY sc.LecturerID, l.LecturerName
                               HAVING COUNT(sc.ScheduleID) > 0 ORDER BY class_count DESC LIMIT 10";
        $stmt_instructor_load_chart = $conn->prepare($sql_instructor_load_chart);
        if($stmt_instructor_load_chart){
            $stmt_instructor_load_chart->bind_param("i", $current_semester_id); 
            if ($stmt_instructor_load_chart->execute()) {
                $res_instructor_load_chart = $stmt_instructor_load_chart->get_result();
                while($row_load_chart = $res_instructor_load_chart->fetch_assoc()){
                    $instructor_load_labels_js[] = htmlspecialchars($row_load_chart['LecturerName']);
                    $instructor_load_data_js[] = (int)$row_load_chart['class_count'];
                }
            } else {
                error_log("Admin Dashboard: Instructor load chart query execution failed: " . $stmt_instructor_load_chart->error);
            }
            $stmt_instructor_load_chart->close();
        } else {
            error_log("Admin Dashboard: Instructor load chart query preparation failed: " . $conn->error);
        }
    } // end if ($current_semester_id)

    // Recently Added Courses (not semester-specific)
    $new_courses_query_sql = "SELECT CourseID, CourseName, Credits, ExpectedStudents FROM Courses ORDER BY CourseID DESC LIMIT 3"; // Assuming CourseID can be used for recency
    $new_courses_result_sql = $conn->query($new_courses_query_sql);
    if ($new_courses_result_sql) {
        while ($row_new_course = $new_courses_result_sql->fetch_assoc()) {
            $new_courses_data[] = $row_new_course;
        }
        $new_courses_result_sql->free();
    } else {
        error_log("Admin Dashboard: Failed to fetch new courses: " . $conn->error);
    }

} else {
    // $conn is not valid, set a flash message or log error
    set_flash_message('db_error', 'Database connection is not available. Dashboard data cannot be loaded.', 'danger');
    error_log("Admin Dashboard: Database connection not available.");
}
require_once __DIR__ . '/../includes/admin_sidebar_menu.php'; 

?>
<!-- Main content for the Dashboard page (will be inserted into <main class="content-area"> of the layout) -->
<div class="container-fluid px-0"> <!-- Use px-0 if content-area already has padding -->
    <h1 class="mt-0 mb-4">Welcome, <?php echo htmlspecialchars($current_user_fullname_page ?: "Admin"); ?>!</h1>
    <ol class="breadcrumb mb-4 bg-light p-2 border rounded">
        <li class="breadcrumb-item active">System Overview - Semester: <?php echo $stats['active_semester_name']; // Already HTML escaped if from DB and then concatenated ?></li>
    </ol>

    <!-- Statistics Cards -->
    <div class="row">
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="stat-card">
                <div class="card-body">
                    <div class="icon-circle bg-primary-light"><i class="fas fa-chalkboard-teacher"></i></div>
                    <div class="stat-text"><h6>Total Instructors</h6><div class="stat-number"><?php echo $stats['total_instructors']; ?></div></div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="stat-card">
                <div class="card-body">
                    <div class="icon-circle bg-success-light"><i class="fas fa-user-graduate"></i></div>
                    <div class="stat-text"><h6>Total Students</h6><div class="stat-number"><?php echo $stats['total_students']; ?></div></div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="stat-card">
                <div class="card-body">
                    <div class="icon-circle bg-warning-light"><i class="fas fa-book"></i></div>
                    <div class="stat-text"><h6>Total Courses</h6><div class="stat-number"><?php echo $stats['total_courses']; ?></div></div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="stat-card">
                <div class="card-body">
                    <div class="icon-circle bg-info-light"><i class="fas fa-school"></i></div>
                    <div class="stat-text"><h6>Total Classrooms</h6><div class="stat-number"><?php echo $stats['total_classrooms']; ?></div></div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="stat-card">
                <div class="card-body">
                    <div class="icon-circle bg-danger-light"><i class="fas fa-calendar-check"></i></div>
                    <div class="stat-text"><h6>Scheduled Classes (Active Sem.)</h6><div class="stat-number"><?php echo $stats['scheduled_classes_current_semester']; ?></div></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts Row 1 -->
    <div class="row">
        <div class="col-xl-8 col-lg-7 mb-4">
            <div class="card shadow-sm">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">Scheduled Classes Activity (<?php echo $stats['active_semester_name']; ?>)</h6>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="courseActivityChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-4 col-lg-5 mb-4">
            <div class="card shadow-sm">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">Room Utilization (<?php echo $stats['active_semester_name']; ?>)</h6>
                </div>
                <div class="card-body">
                    <div class="chart-container d-flex align-items-center justify-content-center">
                        <canvas id="roomUtilizationChart" style="max-width: 280px; max-height: 280px;"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Charts Row 2 -->
    <div class="row">
        <div class="col-lg-12 mb-4">
            <div class="card shadow-sm">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">Instructor Workload (Top 10 by Class Count, <?php echo $stats['active_semester_name']; ?>)</h6>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="instructorLoadChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Other Info Row -->
    <div class="row">
        <div class="col-lg-7 mb-4">
            <div class="card shadow-sm">
                <div class="card-header py-3">
                     <h6 class="m-0 font-weight-bold text-primary">Recently Added Courses</h6>
                </div>
                <div class="card-body">
                    <?php if (!empty($new_courses_data)): ?>
                        <?php foreach ($new_courses_data as $course): ?>
                        <div class="course-card">
                            <img src="<?php echo BASE_URL; ?>assets/images/course_placeholder_<?php echo rand(1,3);?>.png" alt="<?php echo htmlspecialchars($course['CourseName']); ?>" class="course-thumbnail">
                            <div class="course-info">
                                <h5><?php echo htmlspecialchars($course['CourseName']); ?></h5>
                                <p class="mb-1"><small><i class="fas fa-users text-muted me-1"></i> Expected: <?php echo htmlspecialchars($course['ExpectedStudents'] ?? 'N/A'); ?> | <i class="fas fa-star text-muted me-1"></i> Credits: <?php echo htmlspecialchars($course['Credits'] ?? 'N/A'); ?></small></p>
                                <p class="text-muted mb-0"><small><i class="fas fa-tags me-1"></i> Code: <?php echo htmlspecialchars($course['CourseID']); ?></small></p>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-center text-muted">No new course data available.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-5 mb-4">
            <div class="card shadow-sm calendar-widget-card">
                 <div class="card-header py-3">
                     <h6 class="m-0 font-weight-bold text-primary">Events Overview - <?php echo htmlspecialchars($calendar_data['month_name']); ?></h6>
                </div>
                <div class="card-body">
                    <div class="calendar-widget">
                        <div class="calendar-grid">
                            <div class="day-name">Sun</div><div class="day-name">Mon</div><div class="day-name">Tue</div><div class="day-name">Wed</div><div class="day-name">Thu</div><div class="day-name">Fri</div><div class="day-name">Sat</div>
                            <?php
                                for ($i_cal_empty_start = 0; $i_cal_empty_start < $calendar_data['first_day_of_week']; $i_cal_empty_start++) { 
                                    echo "<div class='date-cell empty'></div>"; 
                                }
                                for ($day_calendar = 1; $day_calendar <= $calendar_data['days_in_month']; $day_calendar++) {
                                    $cell_classes = "date-cell";
                                    if ($day_calendar == date('j') && $calendar_data['month'] == date('m') && $calendar_data['year'] == date('Y')) { 
                                        $cell_classes .= " today"; 
                                    }
                                    if (in_array($day_calendar, $calendar_data['scheduled_dates'])) { 
                                        $cell_classes .= " has-event"; 
                                    }
                                    echo "<div class='{$cell_classes}'>{$day_calendar}</div>";
                                }
                                $total_cells_rendered = $calendar_data['first_day_of_week'] + $calendar_data['days_in_month'];
                                $remaining_cells_to_fill_grid = (7 - ($total_cells_rendered % 7)) % 7;
                                for ($i_cal_empty_end = 0; $i_cal_empty_end < $remaining_cells_to_fill_grid; $i_cal_empty_end++) { 
                                    echo "<div class='date-cell empty'></div>"; 
                                }
                            ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div> 
<?php
// This part is for page-specific CSS and JavaScript.
// It's assumed that admin_sidebar_menu.php handles the closing </body> and </html> tags.
// If admin_sidebar_menu.php is designed to accept $additional_head_content and $additional_body_scripts,
// this content could be assigned to those variables. Otherwise, output directly here.
?>

<style>
    /* CSS for admin/index.php (Dashboard) */
    .stat-card { 
        border-radius: 0.5rem; 
        box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.075); 
        background-color: #fff; 
        transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
    }
    .stat-card:hover { 
        transform: translateY(-5px); 
        box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.1);
    }
    .stat-card .card-body { display: flex; align-items: center; padding: 1.25rem; }
    .stat-card .icon-circle { 
        width: 48px; height: 48px; 
        border-radius: 50%; 
        display: flex; align-items: center; justify-content: center; 
        margin-right: 1rem; font-size: 1.4rem; 
    }
    .stat-card .icon-circle.bg-primary-light { background-color: #cfe2ff; color: var(--primary-blue, #005c9e); }
    .stat-card .icon-circle.bg-success-light { background-color: #d1e7dd; color: #146c43; }
    .stat-card .icon-circle.bg-warning-light { background-color: #fff3cd; color: #b28b00; }
    .stat-card .icon-circle.bg-info-light { background-color: #cff4fc; color: #087990; }
    .stat-card .icon-circle.bg-danger-light { background-color: #f8d7da; color: #a52834; }
    
    .stat-card .stat-text h6 { 
        color: #6c757d; font-size: 0.8rem; 
        text-transform: uppercase; margin-bottom: 0.2rem; font-weight: 500;
    }
    .stat-card .stat-text .stat-number { 
        font-size: 1.6rem; font-weight: 700; color: #343a40; 
    }
    
    .card.shadow-sm { box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.075) !important; }
    .card-header.py-3 { background-color: #f8f9fc; border-bottom: 1px solid #e3e6f0; }
    .card-header h6.text-primary { color: var(--primary-blue, #005c9e) !important; }

    .course-card { 
        background-color: #fff; 
        border-radius: 0.375rem; 
        /* box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.075);  Using card's shadow now */
        padding: 0.8rem; display: flex; margin-bottom: 1rem;
        border: 1px solid #e3e6f0;
    }
    .course-card img.course-thumbnail { 
        width: 70px; height: 70px; 
        object-fit: cover; border-radius: 0.25rem; margin-right: 1rem; 
    }
    .course-card .course-info h5 { font-size: 0.95rem; font-weight: 600; margin-bottom: 0.2rem; }
    .course-card .course-info p { font-size: 0.8rem; color: #5a5c69; margin-bottom: 0.1rem; }

    .calendar-widget-card .card-body { padding: 1rem; }
    .calendar-widget { 
        background-color: transparent; /* background is on card now */
        /* padding: 1.5rem; */
        height: 100%;
    }
    .calendar-widget .calendar-grid { 
        display: grid; grid-template-columns: repeat(7, 1fr); 
        gap: 4px; text-align: center; 
    }
    .calendar-widget .calendar-grid div { padding: 0.4em 0.15em; font-size: 0.75rem; }
    .calendar-widget .calendar-grid .day-name { 
        font-weight: 600; color: #858796; font-size:0.7rem; text-transform: uppercase;
    }
    .calendar-widget .calendar-grid .date-cell { 
        border-radius: 0.25rem; cursor: default; 
        transition: background-color 0.2s; 
        aspect-ratio: 1 / 1; display:flex; align-items:center; justify-content:center;
        border: 1px solid #f0f0f0;
    }
    .calendar-widget .calendar-grid .date-cell.today { 
        background-color: var(--primary-blue, #005c9e); color: white; font-weight: bold; 
        border-color: var(--primary-blue, #005c9e);
    }
    .calendar-widget .calendar-grid .date-cell.has-event { 
        background-color: #eaf3ff; /* Light blue for events */
        position:relative; 
        font-weight: 500;
    }
    .calendar-widget .calendar-grid .date-cell.has-event::after { 
        content: ''; display: block; 
        width: 5px; height: 5px; 
        background-color: var(--primary-blue, #005c9e); 
        border-radius: 50%; 
        position: absolute; bottom: 3px; left: 50%; transform: translateX(-50%);
    }
    .calendar-widget .calendar-grid .date-cell.empty { visibility: hidden; }

    .chart-container { 
        /* background-color: #fff; */ /* background on card now */
        padding: 1rem; 
        /* border-radius: 0.5rem; */
        /* box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.075); */
        min-height: 350px; 
        display: flex; flex-direction: column; justify-content: center; align-items: center;
    }
    .chart-container canvas {max-width: 100%;}
    .breadcrumb.bg-light { background-color: #f8f9fc !important; }
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    // Chart.js configuration and instantiation
    const commonChartOptions = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                display: true,
                position: 'top',
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: { 
                    precision: 0 
                }
            }
        }
    };

    const courseActivityLabels = <?php echo json_encode($course_activity_labels_js, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;
    const courseActivityData = <?php echo json_encode($course_activity_data_js, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;
    
    var ctxCourseActivity = document.getElementById('courseActivityChart')?.getContext('2d');
    if(ctxCourseActivity) {
        if (courseActivityData.some(d => d > 0)) {
            new Chart(ctxCourseActivity, { 
                type: 'line', 
                data: { 
                    labels: courseActivityLabels, 
                    datasets: [{ 
                        label: 'Scheduled Classes', 
                        data: courseActivityData, 
                        borderColor: 'rgba(0, 92, 158, 1)', 
                        backgroundColor: 'rgba(0, 92, 158, 0.1)', 
                        fill: true, 
                        tension: 0.3 
                    }] 
                },
                options: { ...commonChartOptions, scales: { ...commonChartOptions.scales, x: { title: { display: true, text: 'Month' }} } }
            });
        } else { 
            ctxCourseActivity.canvas.style.display = 'none'; 
            const noDataMsgCourse = document.createElement('p'); 
            noDataMsgCourse.textContent = "No class activity data for the active semester."; 
            noDataMsgCourse.className = "text-center text-muted p-5";
            ctxCourseActivity.canvas.parentElement.appendChild(noDataMsgCourse);
        }
    }

    const roomUtilizationLabels = <?php echo json_encode($room_utilization_labels_js, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;
    const roomUtilizationData = <?php echo json_encode($room_utilization_data_js, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;

    var ctxRoomUtilization = document.getElementById('roomUtilizationChart')?.getContext('2d');
    if(ctxRoomUtilization) {
        if (roomUtilizationData.some(d => d > 0)) {
            new Chart(ctxRoomUtilization, { 
                type: 'doughnut', 
                data: { 
                    labels: roomUtilizationLabels, 
                    datasets: [{ 
                        label: 'Room Status', 
                        data: roomUtilizationData, 
                        backgroundColor: ['rgba(0, 92, 158, 0.8)', 'rgba(200, 200, 200, 0.7)'], 
                        borderColor: ['#FFFFFF', '#FFFFFF'], 
                        borderWidth: 2, 
                        hoverOffset: 4 
                    }] 
                },
                options: { 
                    responsive: true, maintainAspectRatio: false, 
                    plugins: { 
                        legend: { display: true, position: 'bottom' }, 
                        tooltip: { callbacks: { label: function(context) { let label = context.label || ''; if (label) { label += ': '; } if (context.parsed !== null) { label += context.parsed + ' rooms'; } return label; } } } 
                    } 
                }
            });
        } else { 
            ctxRoomUtilization.canvas.style.display = 'none'; 
            const noDataMsgRoom = document.createElement('p'); 
            noDataMsgRoom.textContent = "No room utilization data available."; 
            noDataMsgRoom.className = "text-center text-muted p-5";
            ctxRoomUtilization.canvas.parentElement.appendChild(noDataMsgRoom);
        }
    }

    const instructorLoadLabels = <?php echo json_encode($instructor_load_labels_js, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;
    const instructorLoadData = <?php echo json_encode($instructor_load_data_js, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;

    var ctxInstructorLoad = document.getElementById('instructorLoadChart')?.getContext('2d');
    if(ctxInstructorLoad) { 
        if (instructorLoadLabels.length > 0 && instructorLoadData.length > 0) {
            new Chart(ctxInstructorLoad, { 
                type: 'bar', 
                data: { 
                    labels: instructorLoadLabels, 
                    datasets: [{ 
                        label: 'Number of Classes Assigned', 
                        data: instructorLoadData, 
                        backgroundColor: 'rgba(75, 192, 192, 0.7)', 
                        borderColor: 'rgba(75, 192, 192, 1)', 
                        borderWidth: 1 
                    }] 
                },
                options: { 
                    ...commonChartOptions, 
                    indexAxis: 'y', 
                    scales: { 
                        ...commonChartOptions.scales, 
                        x: { ...commonChartOptions.scales.x, title: { display: true, text: 'Number of Classes' } },
                        y: { ...commonChartOptions.scales.y, ticks: { autoSkip: false } } 
                    }, 
                    plugins: { legend: { display: false } } 
                }
            });
        } else { 
            ctxInstructorLoad.canvas.style.display = 'none'; 
            const noDataMsgInstr = document.createElement('p'); 
            noDataMsgInstr.textContent = "No instructor load data for the active semester."; 
            noDataMsgInstr.className = "text-center text-muted p-5";
            ctxInstructorLoad.canvas.parentElement.appendChild(noDataMsgInstr);
        }
    }
});
</script>

<?php

?>