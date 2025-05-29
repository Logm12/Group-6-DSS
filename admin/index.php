<?php
// htdocs/DSS/admin/index.php

// Bước 1: Include file sidebar/header chung.
// Đảm bảo admin_sidebar_menu.php không có output nào trước khi session_start()
// và không có output trước khi header được gửi.
// File này sẽ cung cấp $conn, $user_fullname, $user_role, BASE_URL
// và phần mở đầu của HTML (head, body, sidebar, topbar, thẻ mở của main content)
include_once __DIR__ . '/../includes/admin_sidebar_menu.php';

// Bước 2: Kiểm tra vai trò CỤ THỂ cho trang này.
require_role(['admin'], '../login.php'); // Chỉ cho phép vai trò 'admin'

// --- Lấy dữ liệu cho các thẻ thống kê và biểu đồ ---
$stats = [
    'total_instructors' => 0,
    'total_students' => 0,
    'total_courses' => 0,
    'total_classrooms' => 0,
    'scheduled_classes_current_semester' => 0,
];
$new_courses_data = [];

// Dữ liệu cho biểu đồ (sẽ được điền từ CSDL)
$course_activity_labels = []; // Ví dụ: Các tháng
$course_activity_data = [];   // Ví dụ: Số lớp mỗi tháng
$room_utilization_labels = ['Used Rooms', 'Available Rooms']; // Hoặc 'Occupied Seats', 'Empty Seats'
$room_utilization_data = [0, 0];
$instructor_load_labels = []; // Tên giảng viên
$instructor_load_data = [];   // Số lớp/tiết của mỗi giảng viên


// Calendar data
$scheduled_dates_in_month = [];
$current_year_cal = date('Y');
$current_month_cal = date('m');
$days_in_month_cal = date('t');
$first_day_of_month_timestamp_cal = strtotime("{$current_year_cal}-{$current_month_cal}-01");
$first_day_of_week_cal = date('w', $first_day_of_month_timestamp_cal); // 0 (Sun) to 6 (Sat) for grid starting Sunday


if (isset($conn) && $conn instanceof mysqli) {
    // 1. Stats cards
    $queries = [
        'total_instructors' => "SELECT COUNT(*) as total FROM Lecturers",
        'total_students' => "SELECT COUNT(*) as total FROM Students",
        'total_courses' => "SELECT COUNT(*) as total FROM Courses",
        'total_classrooms' => "SELECT COUNT(*) as total FROM Classrooms",
    ];
    foreach ($queries as $key => $sql) {
        $result = $conn->query($sql);
        if ($result) $stats[$key] = $result->fetch_assoc()['total'] ?? 0;
    }

    // Xác định học kỳ hiện tại (hoặc gần nhất)
    $current_semester_id = null;
    $today = date('Y-m-d');
    $stmt_sem = $conn->prepare("SELECT SemesterID FROM Semesters WHERE StartDate <= ? AND EndDate >= ? ORDER BY StartDate DESC LIMIT 1");
    if ($stmt_sem) {
        $stmt_sem->bind_param("ss", $today, $today); $stmt_sem->execute();
        $res_sem = $stmt_sem->get_result();
        if ($res_sem && $res_sem->num_rows > 0) $current_semester_id = $res_sem->fetch_assoc()['SemesterID'];
        $stmt_sem->close();
    }
    if (!$current_semester_id) { /* ... logic tìm học kỳ tương lai/quá khứ (giữ nguyên) ... */ }

    if ($current_semester_id) {
        $stmt_scheduled = $conn->prepare("SELECT COUNT(ScheduleID) as total FROM ScheduledClasses WHERE SemesterID = ?");
        if($stmt_scheduled) {
            $stmt_scheduled->bind_param("i", $current_semester_id); $stmt_scheduled->execute();
            $res_scheduled = $stmt_scheduled->get_result();
            if ($res_scheduled) $stats['scheduled_classes_current_semester'] = $res_scheduled->fetch_assoc()['total'] ?? 0;
            $stmt_scheduled->close();
        }
    }

    // 2. New courses data
    $new_courses_query = "SELECT CourseID, CourseName, Credits, ExpectedStudents FROM Courses ORDER BY CourseID DESC LIMIT 3"; // Lấy 3 môn mới nhất
    $new_courses_result = $conn->query($new_courses_query);
    if ($new_courses_result) {
        while ($row = $new_courses_result->fetch_assoc()) $new_courses_data[] = $row;
    }

    // 3. Data for Course Activity Chart (Ví dụ: Số lớp được xếp mỗi tháng trong năm nay cho học kỳ hiện tại)
    if ($current_semester_id) {
        $course_activity_labels = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        $course_activity_data = array_fill(0, 12, 0); // Khởi tạo mảng 12 tháng với giá trị 0
        
        // Cần join với bảng TimeSlots để lấy DayOfWeek, sau đó tính toán ngày cụ thể
        // Giả sử mỗi bản ghi ScheduledClasses có một ngày cụ thể (cần điều chỉnh nếu không phải vậy)
        // Ví dụ đơn giản hóa: Đếm số lớp theo tháng tạo bản ghi (nếu có cột CreatedAt trong ScheduledClasses)
        // Hoặc, nếu bạn có bảng `ClassSessions` với ngày cụ thể, thì query từ đó.
        // Vì cấu trúc hiện tại không có ngày cụ thể dễ dàng, chúng ta sẽ để dữ liệu mẫu hoặc bạn cần cung cấp logic query phức tạp hơn.
        // Giả sử chúng ta có thể query được:
        // $sql_activity = "SELECT MONTH(sc.ActualScheduledDate) as month, COUNT(sc.ScheduleID) as count 
        //                  FROM ScheduledClasses sc 
        //                  WHERE sc.SemesterID = ? AND YEAR(sc.ActualScheduledDate) = YEAR(CURDATE())
        //                  GROUP BY MONTH(sc.ActualScheduledDate) ORDER BY month ASC";
        // $stmt_activity = $conn->prepare($sql_activity);
        // if($stmt_activity){
        //     $stmt_activity->bind_param("i", $current_semester_id);
        //     $stmt_activity->execute();
        //     $res_activity = $stmt_activity->get_result();
        //     while($row_act = $res_activity->fetch_assoc()){
        //         if($row_act['month'] >= 1 && $row_act['month'] <= 12){
        //             $course_activity_data[$row_act['month']-1] = (int)$row_act['count'];
        //         }
        //     }
        //     $stmt_activity->close();
        // } else { // Dữ liệu mẫu nếu query phức tạp
            $course_activity_data = [10, 12, 8, 15, $stats['scheduled_classes_current_semester'], 18, 20, 17, 16, 19, 22, 25]; // Thay thế bằng dữ liệu thật
        // }
    }


    // 4. Data for Room Utilization Chart
    $total_classrooms = $stats['total_classrooms'];
    $used_classrooms_count = 0; // Cần query để lấy số phòng thực sự được sử dụng trong học kỳ hiện tại
    if ($current_semester_id && $total_classrooms > 0) {
        $stmt_used_rooms = $conn->prepare("SELECT COUNT(DISTINCT ClassroomID) as used_count FROM ScheduledClasses WHERE SemesterID = ? AND ClassroomID IS NOT NULL");
        if ($stmt_used_rooms) {
            $stmt_used_rooms->bind_param("i", $current_semester_id);
            $stmt_used_rooms->execute();
            $res_used_rooms = $stmt_used_rooms->get_result();
            if($res_used_rooms) $used_classrooms_count = $res_used_rooms->fetch_assoc()['used_count'] ?? 0;
            $stmt_used_rooms->close();
        }
        $room_utilization_data = [$used_classrooms_count, $total_classrooms - $used_classrooms_count];
    } else if ($total_classrooms > 0) {
        $room_utilization_data = [0, $total_classrooms];
    }


    // 5. Data for Instructor Load Chart (Số lớp mỗi giảng viên trong kỳ hiện tại)
    if ($current_semester_id) {
        $sql_instr_load = "SELECT l.LecturerName, COUNT(sc.ScheduleID) as class_count 
                           FROM ScheduledClasses sc
                           JOIN Lecturers l ON sc.LecturerID = l.LecturerID
                           WHERE sc.SemesterID = ?
                           GROUP BY sc.LecturerID, l.LecturerName
                           ORDER BY class_count DESC
                           LIMIT 10"; // Lấy top 10 giảng viên có nhiều lớp nhất
        $stmt_instr_load = $conn->prepare($sql_instr_load);
        if($stmt_instr_load){
            $stmt_instr_load->bind_param("i", $current_semester_id);
            $stmt_instr_load->execute();
            $res_instr_load = $stmt_instr_load->get_result();
            while($row_load = $res_instr_load->fetch_assoc()){
                $instructor_load_labels[] = $row_load['LecturerName'];
                $instructor_load_data[] = (int)$row_load['class_count'];
            }
            $stmt_instr_load->close();
        }
    }
}
?>

<!-- CSS được tích hợp trực tiếp -->
<style>
    /* CSS cho admin/index.php */
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
    .course-card .course-info .btn-enroll { font-size: 0.8rem; padding: 0.25rem 0.75rem;}

    .calendar-widget { background-color: #fff; border-radius: 0.5rem; padding: 1.5rem; box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.075); height: 100%;}
    .calendar-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;}
    .calendar-header h5 { margin: 0; font-size: 1.1rem; }
    .calendar-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 5px; text-align: center; }
    .calendar-grid div { padding: 0.5em 0.2em; font-size: 0.8rem; } /* Giảm padding ngang */
    .calendar-grid .day-name { font-weight: 600; color: #6c757d; font-size:0.75rem; }
    .calendar-grid .date-cell { border-radius: 0.25rem; cursor: pointer; transition: background-color 0.2s; aspect-ratio: 1 / 1; display:flex; align-items:center; justify-content:center;}
    .calendar-grid .date-cell:hover:not(.empty) { background-color: #e9ecef; }
    .calendar-grid .date-cell.today { background-color: var(--primary-blue, #0d6efd); color: white; font-weight: bold; }
    .calendar-grid .date-cell.has-event { background-color: #cfe2ff; /* border: 1px solid var(--primary-blue, #0d6efd); */ position:relative; }
    .calendar-grid .date-cell.has-event::after { content: ''; display: block; width: 6px; height: 6px; background-color: var(--primary-blue); border-radius: 50%; position: absolute; bottom: 4px; left: 50%; transform: translateX(-50%);}
    .calendar-grid .date-cell.empty { visibility: hidden; }

    .chart-container { background-color: #fff; padding: 1.5rem; border-radius: 0.5rem; box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.075); height: 380px; /* Tăng chiều cao biểu đồ */ }
</style>

<div class="container-fluid px-4">
    <!-- Chào mừng và Breadcrumb (giữ nguyên) -->
    <h1 class="mt-4">Welcome, <?php echo htmlspecialchars($user_fullname ?: "Admin"); ?>!</h1>
    <ol class="breadcrumb mb-4">
        <li class="breadcrumb-item active">System Overview</li>
    </ol>

    <!-- Stat Cards -->
    <div class="row">
        <div class="col-xl-3 col-md-6"><div class="stat-card"><div class="card-body"><div class="icon-circle bg-primary-light"><i class="fas fa-chalkboard-teacher"></i></div><div class="stat-text"><h6>Total Instructors</h6><div class="stat-number"><?php echo $stats['total_instructors']; ?></div></div></div></div></div>
        <div class="col-xl-3 col-md-6"><div class="stat-card"><div class="card-body"><div class="icon-circle bg-success-light"><i class="fas fa-user-graduate"></i></div><div class="stat-text"><h6>Total Students</h6><div class="stat-number"><?php echo $stats['total_students']; ?></div></div></div></div></div>
        <div class="col-xl-3 col-md-6"><div class="stat-card"><div class="card-body"><div class="icon-circle bg-warning-light"><i class="fas fa-book"></i></div><div class="stat-text"><h6>Total Courses</h6><div class="stat-number"><?php echo $stats['total_courses']; ?></div></div></div></div></div>
        <div class="col-xl-3 col-md-6"><div class="stat-card"><div class="card-body"><div class="icon-circle bg-info-light"><i class="fas fa-school"></i></div><div class="stat-text"><h6>Total Classrooms</h6><div class="stat-number"><?php echo $stats['total_classrooms']; ?></div></div></div></div></div>
        <!-- Thẻ này có thể thay bằng số lịch trình đã tạo hoặc số lỗi cần chú ý -->
        <div class="col-xl-3 col-md-6"><div class="stat-card"><div class="card-body"><div class="icon-circle bg-danger-light"><i class="fas fa-calendar-check"></i></div><div class="stat-text"><h6>Scheduled Classes (Current Sem.)</h6><div class="stat-number"><?php echo $stats['scheduled_classes_current_semester']; ?></div></div></div></div></div>
    </div>

    <div class="row">
        <!-- Biểu đồ Hoạt động Khóa học -->
        <div class="col-xl-8 col-lg-7 mb-4">
            <h5 class="dashboard-section-title">Scheduled Classes Activity (Current Semester)</h5>
            <div class="chart-container">
                <canvas id="courseActivityChart"></canvas>
            </div>
        </div>
        <!-- Biểu đồ Tỷ lệ Sử dụng Phòng -->
        <div class="col-xl-4 col-lg-5 mb-4">
            <h5 class="dashboard-section-title">Room Utilization (Current Semester)</h5>
             <div class="chart-container d-flex align-items-center justify-content-center">
                <canvas id="roomUtilizationChart" style="max-width: 300px; max-height: 300px;"></canvas> <!-- Đổi ID -->
            </div>
        </div>
    </div>
    
    <div class="row">
         <!-- Biểu đồ Phân bổ Giảng viên -->
        <div class="col-lg-12 mb-4">
            <h5 class="dashboard-section-title">Instructor Workload (Top 10 by Class Count, Current Semester)</h5>
            <div class="chart-container">
                <canvas id="instructorLoadChart"></canvas>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Danh sách các khóa học mới -->
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
                        <a href="<?php echo BASE_URL; ?>admin/courses.php?action=view&id=<?php echo htmlspecialchars($course['CourseID']); ?>" class="btn btn-sm btn-outline-primary">View Details</a>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p>No new course data available.</p>
            <?php endif; ?>
        </div>

        <!-- Calendar Widget (Đã được sửa ở phần PHP, cần logic lấy $scheduled_dates_in_month chính xác) -->
        <div class="col-lg-5 mb-4">
            <div class="calendar-widget">
                <div class="calendar-header">
                    <h5>Events Calendar</h5>
                    <span><?php echo date('F Y', $first_day_of_month_timestamp_cal); ?></span>
                </div>
                <div class="calendar-grid">
                    <div class="day-name">Sun</div><div class="day-name">Mon</div><div class="day-name">Tue</div><div class="day-name">Wed</div><div class="day-name">Thu</div><div class="day-name">Fri</div><div class="day-name">Sat</div>
                    <?php
                        for ($i = 0; $i < $first_day_of_week_cal; $i++) { echo "<div class='date-cell empty'></div>"; }
                        for ($day = 1; $day <= $days_in_month_cal; $day++) {
                            $class = "date-cell";
                            if ($day == date('j') && $current_month_cal == date('m') && $current_year_cal == date('Y')) { $class .= " today"; }
                            if (in_array($day, $scheduled_dates_in_month)) { $class .= " has-event"; } // $scheduled_dates_in_month cần được điền đúng
                            echo "<div class='{$class}'>{$day}</div>";
                        }
                        // Lấp đầy các ô trống còn lại của tuần cuối cùng
                        $total_cells = $first_day_of_week_cal + $days_in_month_cal;
                        $remaining_cells = (7 - ($total_cells % 7)) % 7;
                        for ($i = 0; $i < $remaining_cells; $i++) { echo "<div class='date-cell empty'></div>"; }
                    ?>
                </div>
            </div>
        </div>
    </div>

</div> <!-- Kết thúc .container-fluid -->

<!-- Script được tích hợp trực tiếp -->
<!-- Bootstrap JS (đã có trong admin_sidebar_menu.php nếu nó là template bao quanh) -->
<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script> -->

<!-- Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script> 
<!-- Lưu ý: Phiên bản Chart.js có thể cần @3.7.0 như code gốc của bạn nếu có vấn đề tương thích -->

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Dữ liệu từ PHP cho biểu đồ
    const courseActivityLabels = <?php echo json_encode($course_activity_labels); ?>;
    const courseActivityData = <?php echo json_encode($course_activity_data); ?>;
    
    const roomUtilizationLabels = <?php echo json_encode($room_utilization_labels); ?>;
    const roomUtilizationData = <?php echo json_encode($room_utilization_data); ?>;

    const instructorLoadLabels = <?php echo json_encode($instructor_load_labels); ?>;
    const instructorLoadData = <?php echo json_encode($instructor_load_data); ?>;

    // 1. Biểu đồ Hoạt động Khóa học (Line Chart)
    var ctxCourseActivity = document.getElementById('courseActivityChart')?.getContext('2d');
    if(ctxCourseActivity && courseActivityLabels.length > 0 && courseActivityData.length > 0) {
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
            options: { 
                responsive: true, 
                maintainAspectRatio: false, 
                scales: { 
                    y: { beginAtZero: true, ticks: { stepSize: 5 } }, // Điều chỉnh stepSize nếu cần
                    x: { title: { display: true, text: 'Month' }}
                },
                plugins: { legend: { display: true, position: 'top'} }
            }
        });
    } else if(ctxCourseActivity) {
        ctxCourseActivity.font = "16px Arial";
        ctxCourseActivity.fillText("No activity data for current semester.", 10, 50);
    }

    // 2. Biểu đồ Tỷ lệ Sử dụng Phòng (Doughnut Chart)
    var ctxRoomUtilization = document.getElementById('roomUtilizationChart')?.getContext('2d');
    if(ctxRoomUtilization && roomUtilizationData.some(d => d > 0)) { // Chỉ vẽ nếu có dữ liệu
        new Chart(ctxRoomUtilization, {
            type: 'doughnut',
            data: {
                labels: roomUtilizationLabels,
                datasets: [{
                    label: 'Room Status', 
                    data: roomUtilizationData,
                    backgroundColor: ['rgba(0, 92, 158, 0.8)', 'rgba(200, 200, 200, 0.7)'], // Màu cho Used, Available
                    borderColor: ['#FFFFFF', '#FFFFFF'],
                    borderWidth: 2,
                    hoverOffset: 4
                }]
            },
            options: { 
                responsive: true, 
                maintainAspectRatio: false, 
                plugins: { 
                    legend: { display: true, position: 'bottom' },
                    tooltip: { 
                        callbacks: { 
                            label: function(context) { 
                                let label = context.label || '';
                                if (label) { label += ': '; }
                                if (context.parsed !== null) { label += context.parsed + ' rooms'; }
                                return label;
                            } 
                        } 
                    } 
                } 
            }
        });
    } else if(ctxRoomUtilization) {
        ctxRoomUtilization.font = "16px Arial";
        ctxRoomUtilization.fillText("No room utilization data.", 10, 50);
    }

    // 3. Biểu đồ Phân bổ Giảng viên (Bar Chart)
    var ctxInstructorLoad = document.getElementById('instructorLoadChart')?.getContext('2d');
    if(ctxInstructorLoad && instructorLoadLabels.length > 0 && instructorLoadData.length > 0) {
        new Chart(ctxInstructorLoad, {
            type: 'bar',
            data: {
                labels: instructorLoadLabels,
                datasets: [{
                    label: 'Number of Classes Assigned',
                    data: instructorLoadData,
                    backgroundColor: 'rgba(75, 192, 192, 0.6)',
                    borderColor: 'rgba(75, 192, 192, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y', // Hiển thị thanh ngang để dễ đọc tên giảng viên dài
                scales: {
                    x: { beginAtZero: true, title: { display: true, text: 'Number of Classes' } },
                    y: { ticks: { autoSkip: false } } // Đảm bảo tất cả tên giảng viên được hiển thị
                },
                plugins: { legend: { display: false } } // Có thể ẩn legend nếu chỉ có 1 dataset
            }
        });
    } else if(ctxInstructorLoad) {
        ctxInstructorLoad.font = "16px Arial";
        ctxInstructorLoad.fillText("No instructor load data available for current semester.", 10, 50);
    }

    // JavaScript cho sidebar toggle (nếu cần và chưa có trong admin_sidebar_menu.php)
    const sidebarToggleMobileBtn = document.getElementById('sidebarToggleMobile');
    const mainSidebar = document.getElementById('mainSidebar');
    if (sidebarToggleMobileBtn && mainSidebar) {
        sidebarToggleMobileBtn.addEventListener('click', function() {
            mainSidebar.classList.toggle('active');
        });
    }
});
</script>
<?php
// File này là nội dung chính, nên không có thẻ đóng </body> hay </html> ở đây
// Chúng sẽ được đóng bởi file template footer (hoặc cuối file admin_sidebar_menu.php)
?>