<?php
// htdocs/DSS/admin/index.php

// Giai đoạn 1: Include các file cần thiết và kiểm tra quyền
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_role(['admin'], '../login.php');

// --- GIAI ĐOẠN 2: LẤY DỮ LIỆU CHO TRANG DASHBOARD ---
$page_title = "Admin Dashboard"; // Tiêu đề trang, sẽ được dùng bởi file layout
$current_user_fullname_page = get_current_user_fullname();

$stats = [
    'total_instructors' => 0, 'total_students' => 0, 'total_courses' => 0,
    'total_classrooms' => 0, 'scheduled_classes_current_semester' => 0,
    'active_semester_name' => 'N/A',
];
$new_courses_data = [];
$course_activity_labels_js = []; $course_activity_data_js = [];
$room_utilization_labels_js = ['Used Rooms', 'Available Rooms']; $room_utilization_data_js = [0, 0];
$instructor_load_labels_js = []; $instructor_load_data_js = [];
$calendar_data = [
    'year' => date('Y'), 'month' => date('m'), 'month_name' => date('F Y'),
    'days_in_month' => 0, 'first_day_of_week' => 0, 'scheduled_dates' => []
];
$current_semester_id = null;
$active_semester_start_date = null;
$active_semester_end_date = null;

if (isset($conn) && $conn instanceof mysqli) {
    // Lấy các thống kê tổng quan
    $queries_stats = [
        'total_instructors' => "SELECT COUNT(*) as total FROM Lecturers",
        'total_students' => "SELECT COUNT(*) as total FROM Students",
        'total_courses' => "SELECT COUNT(*) as total FROM Courses",
        'total_classrooms' => "SELECT COUNT(*) as total FROM Classrooms",
    ];
    foreach ($queries_stats as $key => $sql) {
        $result_stat = $conn->query($sql);
        if ($result_stat) $stats[$key] = $result_stat->fetch_assoc()['total'] ?? 0;
    }

    // Xác định học kỳ "active"
    $today_date = date('Y-m-d');
    $semester_found = false;
    $semester_queries = [
        "SELECT SemesterID, SemesterName, StartDate, EndDate FROM Semesters WHERE StartDate <= ? AND EndDate >= ? ORDER BY StartDate DESC LIMIT 1", // Current
        "SELECT SemesterID, SemesterName, StartDate, EndDate FROM Semesters WHERE StartDate > ? ORDER BY StartDate ASC LIMIT 1",      // Upcoming
        "SELECT SemesterID, SemesterName, StartDate, EndDate FROM Semesters ORDER BY EndDate DESC LIMIT 1"                             // Recent Past
    ];
    $params_sem = [["ss", $today_date, $today_date], ["s", $today_date], []];
    $status_suffix = ["", " (Upcoming)", " (Recent Past)"];

    for ($i = 0; $i < count($semester_queries) && !$semester_found; $i++) {
        $stmt_sem = $conn->prepare($semester_queries[$i]);
        if ($stmt_sem) {
            if (!empty($params_sem[$i])) {
                $stmt_sem->bind_param(...$params_sem[$i]);
            }
            $stmt_sem->execute();
            $res_sem = $stmt_sem->get_result();
            if ($row_sem = $res_sem->fetch_assoc()) {
                $current_semester_id = $row_sem['SemesterID'];
                $stats['active_semester_name'] = $row_sem['SemesterName'] . $status_suffix[$i];
                $active_semester_start_date = $row_sem['StartDate'];
                $active_semester_end_date = $row_sem['EndDate'];
                $semester_found = true;
            }
            $stmt_sem->close();
        }
    }

    if ($current_semester_id) {
        // Số lớp đã xếp lịch
        $stmt_scheduled = $conn->prepare("SELECT COUNT(ScheduleID) as total FROM ScheduledClasses WHERE SemesterID = ?");
        if($stmt_scheduled) {
            $stmt_scheduled->bind_param("i", $current_semester_id); $stmt_scheduled->execute();
            $stats['scheduled_classes_current_semester'] = $stmt_scheduled->get_result()->fetch_assoc()['total'] ?? 0;
            $stmt_scheduled->close();
        }

        // Dữ liệu cho Lịch "Events Overview" (tháng đầu tiên của học kỳ active)
        if ($active_semester_start_date) {
            try {
                $sem_start_dt = new DateTime($active_semester_start_date);
                $calendar_data['year'] = $sem_start_dt->format('Y');
                $calendar_data['month'] = $sem_start_dt->format('m');
                $calendar_data['month_name'] = $sem_start_dt->format('F Y');
            } catch (Exception $e) { /* Dùng default */ }
        }
        $calendar_data['days_in_month'] = date('t', strtotime("{$calendar_data['year']}-{$calendar_data['month']}-01"));
        $calendar_data['first_day_of_week'] = date('w', strtotime("{$calendar_data['year']}-{$calendar_data['month']}-01"));

        $sql_cal_events = "SELECT DISTINCT DAY(CONVERT_TZ(DATE_ADD(sem.StartDate, 
                            INTERVAL MOD( (7 + DAYOFWEEK(DATE_ADD(sem.StartDate, INTERVAL tộc.idx DAY)) - ts.MySQLDayOfWeekValue), 7) DAY), sem_tz.tz, 'SYSTEM')) as EventDay
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
                               SELECT 84 UNION ALL SELECT 85 UNION ALL SELECT 86 UNION ALL SELECT 87 UNION ALL SELECT 88 UNION ALL SELECT 89 UNION ALL SELECT 90 -- Max days in a short semester
                           ) tộc
                           CROSS JOIN (SELECT @@session.time_zone as tz) sem_tz -- Get session timezone
                           WHERE sc.SemesterID = ?
                             AND DATE_ADD(sem.StartDate, INTERVAL tộc.idx DAY) <= sem.EndDate -- Ensure date is within semester
                             AND MONTH(CONVERT_TZ(DATE_ADD(sem.StartDate, 
                                INTERVAL MOD( (7 + DAYOFWEEK(DATE_ADD(sem.StartDate, INTERVAL tộc.idx DAY)) - ts.MySQLDayOfWeekValue), 7) DAY), sem_tz.tz, 'SYSTEM')) = ?
                             AND YEAR(CONVERT_TZ(DATE_ADD(sem.StartDate, 
                                INTERVAL MOD( (7 + DAYOFWEEK(DATE_ADD(sem.StartDate, INTERVAL tộc.idx DAY)) - ts.MySQLDayOfWeekValue), 7) DAY), sem_tz.tz, 'SYSTEM')) = ?
                             AND ts.MySQLDayOfWeekValue IS NOT NULL"; // Add a column MySQLDayOfWeekValue (1=Sun, 2=Mon,...) to TimeSlots
        $stmt_cal_events = $conn->prepare($sql_cal_events);
        if ($stmt_cal_events) {
            $cal_month_int = (int)$calendar_data['month'];
            $cal_year_int = (int)$calendar_data['year'];
            $stmt_cal_events->bind_param("iii", $current_semester_id, $cal_month_int, $cal_year_int);
            $stmt_cal_events->execute();
            $res_cal_events = $stmt_cal_events->get_result();
            while ($row_cal = $res_cal_events->fetch_assoc()) {
                $calendar_data['scheduled_dates'][] = (int)$row_cal['EventDay'];
            }
            $calendar_data['scheduled_dates'] = array_unique($calendar_data['scheduled_dates']);
            $stmt_cal_events->close();
        } else { error_log("Calendar event query prepare failed: " . $conn->error); }


        // Dữ liệu cho Biểu đồ "Scheduled Classes Activity"
        if ($active_semester_start_date && $active_semester_end_date) {
             $sql_course_activity = "SELECT 
                                        MONTH(CONVERT_TZ(DATE_ADD(sem.StartDate, 
                                            INTERVAL MOD( (7 + DAYOFWEEK(DATE_ADD(sem.StartDate, INTERVAL tộc.idx DAY)) - ts.MySQLDayOfWeekValue), 7) DAY), sem_tz.tz, 'SYSTEM')) as EventMonth, 
                                        COUNT(DISTINCT sc.ScheduleID) as ClassCount
                                    FROM ScheduledClasses sc
                                    JOIN TimeSlots ts ON sc.TimeSlotID = ts.TimeSlotID
                                    JOIN Semesters sem ON sc.SemesterID = sem.SemesterID
                                    CROSS JOIN ( SELECT 0 idx UNION ALL ... ) tộc -- (như trên)
                                    CROSS JOIN (SELECT @@session.time_zone as tz) sem_tz
                                    WHERE sc.SemesterID = ? 
                                      AND DATE_ADD(sem.StartDate, INTERVAL tộc.idx DAY) <= sem.EndDate
                                      AND ts.MySQLDayOfWeekValue IS NOT NULL
                                    GROUP BY EventMonth ORDER BY EventMonth ASC";
            $stmt_course_activity = $conn->prepare($sql_course_activity);
            if ($stmt_course_activity) {
                $stmt_course_activity->bind_param("i", $current_semester_id);
                $stmt_course_activity->execute();
                $res_course_activity = $stmt_course_activity->get_result();
                $monthly_counts_from_db = [];
                while ($row = $res_course_activity->fetch_assoc()) {
                    $monthly_counts_from_db[(int)$row['EventMonth']] = (int)$row['ClassCount'];
                }
                $stmt_course_activity->close();
                $month_names_short = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                $course_activity_labels_js = $month_names_short;
                $course_activity_data_js = array_fill(0, 12, 0);
                for ($m = 1; $m <= 12; $m++) {
                    if (isset($monthly_counts_from_db[$m])) {
                        $course_activity_data_js[$m - 1] = $monthly_counts_from_db[$m];
                    }
                }
            } else { error_log("Course activity query prepare failed: " . $conn->error); }
        }

        // Room Utilization & Instructor Load (giữ nguyên logic, chỉ đảm bảo $current_semester_id được dùng)
        $total_classrooms = $stats['total_classrooms'];
        $used_classrooms_count = 0;
        if ($total_classrooms > 0) {
            $stmt_used_rooms = $conn->prepare("SELECT COUNT(DISTINCT ClassroomID) as used_count FROM ScheduledClasses WHERE SemesterID = ? AND ClassroomID IS NOT NULL");
            if ($stmt_used_rooms) {
                $stmt_used_rooms->bind_param("i", $current_semester_id); $stmt_used_rooms->execute();
                $used_classrooms_count = $stmt_used_rooms->get_result()->fetch_assoc()['used_count'] ?? 0;
                $stmt_used_rooms->close();
            }
            $room_utilization_data_js = [$used_classrooms_count, max(0, $total_classrooms - $used_classrooms_count)];
        }

        $sql_instr_load = "SELECT l.LecturerName, COUNT(sc.ScheduleID) as class_count 
                           FROM ScheduledClasses sc JOIN Lecturers l ON sc.LecturerID = l.LecturerID
                           WHERE sc.SemesterID = ? GROUP BY sc.LecturerID, l.LecturerName
                           HAVING COUNT(sc.ScheduleID) > 0 ORDER BY class_count DESC LIMIT 10";
        $stmt_instr_load = $conn->prepare($sql_instr_load);
        if($stmt_instr_load){
            $stmt_instr_load->bind_param("i", $current_semester_id); $stmt_instr_load->execute();
            $res_instr_load = $stmt_instr_load->get_result();
            while($row_load = $res_instr_load->fetch_assoc()){
                $instructor_load_labels_js[] = $row_load['LecturerName'];
                $instructor_load_data_js[] = (int)$row_load['class_count'];
            }
            $stmt_instr_load->close();
        }
    }

    $new_courses_query = "SELECT CourseID, CourseName, Credits, ExpectedStudents FROM Courses ORDER BY CourseID DESC LIMIT 3";
    $new_courses_result = $conn->query($new_courses_query);
    if ($new_courses_result) {
        while ($row = $new_courses_result->fetch_assoc()) $new_courses_data[] = $row;
    }
}

// --- GIAI ĐOẠN 3: RENDER LAYOUT VÀ NỘI DUNG ---

// File layout sẽ render <head>, <body>, sidebar, topbar, và mở <main>
// Nó sẽ sử dụng $page_title, và các biến session (qua các hàm get_current_user_*())
// CSS và JS chung của layout (Bootstrap, FontAwesome, sidebar toggle JS) cũng do nó quản lý.
require_once __DIR__ . '/../includes/admin_sidebar_menu.php';
?>

<!-- Bắt đầu nội dung chính của trang Dashboard (sẽ được chèn vào <main class="content-area"> của layout) -->
<div class="container-fluid px-0">
    <h1 class="mt-0 mb-4">Welcome, <?php echo htmlspecialchars($current_user_fullname_page ?: "Admin"); ?>!</h1>
    <ol class="breadcrumb mb-4">
        <li class="breadcrumb-item active">System Overview - Semester: <?php echo htmlspecialchars($stats['active_semester_name']); ?></li>
    </ol>

    <div class="row">
        <div class="col-xl-3 col-md-6"><div class="stat-card"><div class="card-body"><div class="icon-circle bg-primary-light"><i class="fas fa-chalkboard-teacher"></i></div><div class="stat-text"><h6>Total Instructors</h6><div class="stat-number"><?php echo $stats['total_instructors']; ?></div></div></div></div></div>
        <div class="col-xl-3 col-md-6"><div class="stat-card"><div class="card-body"><div class="icon-circle bg-success-light"><i class="fas fa-user-graduate"></i></div><div class="stat-text"><h6>Total Students</h6><div class="stat-number"><?php echo $stats['total_students']; ?></div></div></div></div></div>
        <div class="col-xl-3 col-md-6"><div class="stat-card"><div class="card-body"><div class="icon-circle bg-warning-light"><i class="fas fa-book"></i></div><div class="stat-text"><h6>Total Courses</h6><div class="stat-number"><?php echo $stats['total_courses']; ?></div></div></div></div></div>
        <div class="col-xl-3 col-md-6"><div class="stat-card"><div class="card-body"><div class="icon-circle bg-info-light"><i class="fas fa-school"></i></div><div class="stat-text"><h6>Total Classrooms</h6><div class="stat-number"><?php echo $stats['total_classrooms']; ?></div></div></div></div></div>
        <div class="col-xl-3 col-md-6"><div class="stat-card"><div class="card-body"><div class="icon-circle bg-danger-light"><i class="fas fa-calendar-check"></i></div><div class="stat-text"><h6>Scheduled Classes (Active Sem.)</h6><div class="stat-number"><?php echo $stats['scheduled_classes_current_semester']; ?></div></div></div></div></div>
    </div>

    <div class="row">
        <div class="col-xl-8 col-lg-7 mb-4">
            <h5 class="dashboard-section-title">Scheduled Classes Activity (<?php echo htmlspecialchars($stats['active_semester_name']);?>)</h5>
            <div class="chart-container">
                <canvas id="courseActivityChart"></canvas>
            </div>
        </div>
        <div class="col-xl-4 col-lg-5 mb-4">
            <h5 class="dashboard-section-title">Room Utilization (<?php echo htmlspecialchars($stats['active_semester_name']);?>)</h5>
            <div class="chart-container d-flex align-items-center justify-content-center">
                <canvas id="roomUtilizationChart" style="max-width: 280px; max-height: 280px;"></canvas>
            </div>
        </div>
    </div>
    
    <div class="row">
        <div class="col-lg-12 mb-4">
            <h5 class="dashboard-section-title">Instructor Workload (Top 10 by Class Count, <?php echo htmlspecialchars($stats['active_semester_name']);?>)</h5>
            <div class="chart-container">
                <canvas id="instructorLoadChart"></canvas>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-7 mb-4">
            <h5 class="dashboard-section-title">Recently Added Courses</h5>
            <?php if (!empty($new_courses_data)): ?>
                <?php foreach ($new_courses_data as $course): ?>
                <div class="course-card">
                    <img src="<?php echo BASE_URL; ?>assets/images/course_placeholder_<?php echo rand(1,3);?>.png" alt="<?php echo htmlspecialchars($course['CourseName']); ?>" class="course-thumbnail">
                    <div class="course-info">
                        <h5><?php echo htmlspecialchars($course['CourseName']); ?></h5>
                        <p><i class="fas fa-users"></i> Expected: <?php echo htmlspecialchars($course['ExpectedStudents'] ?? 'N/A'); ?> | <i class="fas fa-star"></i> Credits: <?php echo htmlspecialchars($course['Credits'] ?? 'N/A'); ?></p>
                        <p class="text-muted"><i class="fas fa-tags"></i> Code: <?php echo htmlspecialchars($course['CourseID']); ?></p>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p>No new course data available.</p>
            <?php endif; ?>
        </div>

        <div class="col-lg-5 mb-4">
            <div class="calendar-widget">
                <div class="calendar-header">
                    <h5>Events Overview</h5>
                    <span><?php echo htmlspecialchars($calendar_data['month_name']); ?></span>
                </div>
                <div class="calendar-grid">
                    <div class="day-name">Sun</div><div class="day-name">Mon</div><div class="day-name">Tue</div><div class="day-name">Wed</div><div class="day-name">Thu</div><div class="day-name">Fri</div><div class="day-name">Sat</div>
                    <?php
                        for ($i_cal = 0; $i_cal < $calendar_data['first_day_of_week']; $i_cal++) { echo "<div class='date-cell empty'></div>"; }
                        for ($day_cal_loop = 1; $day_cal_loop <= $calendar_data['days_in_month']; $day_cal_loop++) {
                            $class_cal = "date-cell";
                            if ($day_cal_loop == date('j') && $calendar_data['month'] == date('m') && $calendar_data['year'] == date('Y')) { $class_cal .= " today"; }
                            if (in_array($day_cal_loop, $calendar_data['scheduled_dates'])) { $class_cal .= " has-event"; }
                            echo "<div class='{$class_cal}'>{$day_cal_loop}</div>";
                        }
                        $total_cells_cal = $calendar_data['first_day_of_week'] + $calendar_data['days_in_month'];
                        $remaining_cells_cal = (7 - ($total_cells_cal % 7)) % 7;
                        for ($i_cal_rem = 0; $i_cal_rem < $remaining_cells_cal; $i_cal_rem++) { echo "<div class='date-cell empty'></div>"; }
                    ?>
                </div>
            </div>
        </div>
    </div>
</div> 
<!-- Kết thúc nội dung chính của trang Dashboard -->

<?php
// CSS và JS cụ thể cho trang dashboard này sẽ được include ở đây
// bởi file layout admin_sidebar_menu.php nếu nó được thiết kế để nhận
// các biến $additional_head_content và $additional_body_scripts.
// Hoặc, nếu admin_sidebar_menu.php KHÔNG xử lý CSS/JS của trang con,
// thì chúng ta đặt trực tiếp ở đây.
?>

<style>
    /* CSS cho admin/index.php (Dashboard) - NẾU admin_sidebar_menu.php KHÔNG LOAD NÓ */
    /* (giữ nguyên CSS bạn đã cung cấp cho stat-card, dashboard-section-title, etc.) */
    .stat-card { border-radius: 0.5rem; box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.075); margin-bottom: 1.5rem; background-color: #fff; transition: transform 0.2s ease-in-out; }
    .stat-card:hover { transform: translateY(-5px); }
    .stat-card .card-body { display: flex; align-items: center; padding: 1.5rem; }
    .stat-card .icon-circle { width: 50px; height: 50px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-right: 1rem; font-size: 1.5rem; }
    .stat-card .icon-circle.bg-primary-light { background-color: #cfe2ff; color: var(--primary-blue, #0d6efd); }
    .stat-card .icon-circle.bg-success-light { background-color: #d1e7dd; color: #198754; }
    .stat-card .icon-circle.bg-warning-light { background-color: #fff3cd; color: #ffc107; }
    .stat-card .icon-circle.bg-info-light { background-color: #cff4fc; color: #0dcaf0; }
    .stat-card .icon-circle.bg-danger-light { background-color: #f8d7da; color: #dc3545; }
    .stat-card .stat-text h6 { color: #6c757d; font-size: 0.8rem; text-transform: uppercase; margin-bottom: 0.25rem; }
    .stat-card .stat-text .stat-number { font-size: 1.75rem; font-weight: 700; color: #343a40; }
    
    .dashboard-section-title { font-size: 1.25rem; font-weight: 500; margin-bottom: 1rem; color: #333; padding-top: 1rem; border-top: 1px solid #eee; margin-top: 1.5rem;}
    .dashboard-section-title:first-of-type { border-top: none; margin-top: 0; padding-top:0;}

    .course-card { background-color: #fff; border-radius: 0.5rem; box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.075); padding: 1rem; display: flex; margin-bottom: 1rem; }
    .course-card img.course-thumbnail { width: 80px; height: 80px; object-fit: cover; border-radius: 0.375rem; margin-right: 1rem; }
    .course-card .course-info h5 { font-size: 1rem; font-weight: 600; margin-bottom: 0.25rem; }
    .course-card .course-info p { font-size: 0.85rem; color: #6c757d; margin-bottom: 0.25rem; }

    .calendar-widget { background-color: #fff; border-radius: 0.5rem; padding: 1.5rem; box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.075); height: 100%;}
    .calendar-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;}
    .calendar-header h5 { margin: 0; font-size: 1.1rem; }
    .calendar-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 5px; text-align: center; }
    .calendar-grid div { padding: 0.5em 0.2em; font-size: 0.8rem; }
    .calendar-grid .day-name { font-weight: 600; color: #6c757d; font-size:0.75rem; }
    .calendar-grid .date-cell { border-radius: 0.25rem; cursor: default; transition: background-color 0.2s; aspect-ratio: 1 / 1; display:flex; align-items:center; justify-content:center;}
    .calendar-grid .date-cell.today { background-color: var(--primary-blue, #0d6efd); color: white; font-weight: bold; }
    .calendar-grid .date-cell.has-event { background-color: #cfe2ff; position:relative; }
    .calendar-grid .date-cell.has-event::after { content: ''; display: block; width: 6px; height: 6px; background-color: var(--primary-blue); border-radius: 50%; position: absolute; bottom: 4px; left: 50%; transform: translateX(-50%);}
    .calendar-grid .date-cell.empty { visibility: hidden; }

    .chart-container { background-color: #fff; padding: 1.5rem; border-radius: 0.5rem; box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.075); min-height: 380px; display: flex; flex-direction: column; justify-content: center; align-items: center;}
    .chart-container canvas {max-width: 100%;}
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    // JavaScript cho biểu đồ (giữ nguyên như bạn đã cung cấp)
    const courseActivityLabels = <?php echo json_encode($course_activity_labels_js, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;
    const courseActivityData = <?php echo json_encode($course_activity_data_js, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;
    
    const roomUtilizationLabels = <?php echo json_encode($room_utilization_labels_js, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;
    const roomUtilizationData = <?php echo json_encode($room_utilization_data_js, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;

    const instructorLoadLabels = <?php echo json_encode($instructor_load_labels_js, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;
    const instructorLoadData = <?php echo json_encode($instructor_load_data_js, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;

    var ctxCourseActivity = document.getElementById('courseActivityChart')?.getContext('2d');
    if(ctxCourseActivity && courseActivityData.some(d => d > 0)) { // Kiểm tra có dữ liệu trước khi render
        new Chart(ctxCourseActivity, { /* ... config biểu đồ ... */ 
            type: 'line', data: { labels: courseActivityLabels, datasets: [{ label: 'Scheduled Classes', data: courseActivityData, borderColor: 'rgba(0, 92, 158, 1)', backgroundColor: 'rgba(0, 92, 158, 0.1)', fill: true, tension: 0.3 }] },
            options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, ticks: { stepSize: 5, precision: 0 } }, x: { title: { display: true, text: 'Month' }} }, plugins: { legend: { display: true, position: 'top'} } }
        });
    } else if(ctxCourseActivity) { /* ... xử lý không có dữ liệu ... */ 
        ctxCourseActivity.canvas.style.display = 'none'; const noDataMsgCourse = document.createElement('p'); noDataMsgCourse.textContent = "No class activity data for the active semester."; noDataMsgCourse.style.textAlign="center"; noDataMsgCourse.style.paddingTop="50px"; ctxCourseActivity.canvas.parentElement.appendChild(noDataMsgCourse);
    }

    var ctxRoomUtilization = document.getElementById('roomUtilizationChart')?.getContext('2d');
    if(ctxRoomUtilization && roomUtilizationData.some(d => d > 0)) { /* ... config biểu đồ ... */ 
        new Chart(ctxRoomUtilization, { type: 'doughnut', data: { labels: roomUtilizationLabels, datasets: [{ label: 'Room Status', data: roomUtilizationData, backgroundColor: ['rgba(0, 92, 158, 0.8)', 'rgba(200, 200, 200, 0.7)'], borderColor: ['#FFFFFF', '#FFFFFF'], borderWidth: 2, hoverOffset: 4 }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: true, position: 'bottom' }, tooltip: { callbacks: { label: function(context) { let label = context.label || ''; if (label) { label += ': '; } if (context.parsed !== null) { label += context.parsed + ' rooms'; } return label; } } } } }
        });
    } else if(ctxRoomUtilization) { /* ... xử lý không có dữ liệu ... */ 
        ctxRoomUtilization.canvas.style.display = 'none'; const noDataMsgRoom = document.createElement('p'); noDataMsgRoom.textContent = "No room utilization data available."; noDataMsgRoom.style.textAlign="center"; noDataMsgRoom.style.paddingTop="50px"; ctxRoomUtilization.canvas.parentElement.appendChild(noDataMsgRoom);
    }

    var ctxInstructorLoad = document.getElementById('instructorLoadChart')?.getContext('2d');
    if(ctxInstructorLoad && instructorLoadLabels.length > 0 && instructorLoadData.length > 0) { /* ... config biểu đồ ... */ 
        new Chart(ctxInstructorLoad, { type: 'bar', data: { labels: instructorLoadLabels, datasets: [{ label: 'Number of Classes Assigned', data: instructorLoadData, backgroundColor: 'rgba(75, 192, 192, 0.6)', borderColor: 'rgba(75, 192, 192, 1)', borderWidth: 1 }] },
            options: { responsive: true, maintainAspectRatio: false, indexAxis: 'y', scales: { x: { beginAtZero: true, title: { display: true, text: 'Number of Classes' }, ticks: { precision: 0 } }, y: { ticks: { autoSkip: false } } }, plugins: { legend: { display: false } } }
        });
    } else if(ctxInstructorLoad) { /* ... xử lý không có dữ liệu ... */ 
        ctxInstructorLoad.canvas.style.display = 'none'; const noDataMsgInstr = document.createElement('p'); noDataMsgInstr.textContent = "No instructor load data for the active semester."; noDataMsgInstr.style.textAlign="center"; noDataMsgInstr.style.paddingTop="50px"; ctxInstructorLoad.canvas.parentElement.appendChild(noDataMsgInstr);
    }
    // JavaScript cho sidebar toggle ĐÃ được cung cấp bởi admin_sidebar_menu.php (giả định)
});
</script>

<?php
// File layout admin_sidebar_menu.php SẼ chịu trách nhiệm đóng các thẻ </body> và </html>
?>