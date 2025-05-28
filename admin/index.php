<?php
// htdocs/DSS/admin/index.php

// Bước 1: Include file sidebar/header chung.
include_once __DIR__ . '/../includes/admin_sidebar_menu.php';

// Bước 2: Kiểm tra vai trò CỤ THỂ cho trang này.
require_role('admin', '../login.php');

// --- Lấy dữ liệu cho các thẻ thống kê ---
$stats = [
    'total_instructors' => 0,
    'total_students' => 0,
    'total_courses' => 0,
    'total_classrooms' => 0,
    'scheduled_classes_current_semester' => 0,
];
$new_courses_data = [];
$scheduled_dates_in_month = [];
$current_year_cal = date('Y');
$current_month_cal = date('m');
$days_in_month_cal = date('t'); // Lấy số ngày trong tháng hiện tại
$first_day_of_month_timestamp_cal = strtotime("{$current_year_cal}-{$current_month_cal}-01");
$first_day_of_week_cal = date('N', $first_day_of_month_timestamp_cal);

// Đảm bảo $conn đã được khởi tạo từ db_connect.php (thông qua admin_sidebar_menu.php)
// và $user_fullname, $user_role cũng đã có.
if (isset($conn) && $conn instanceof mysqli) {
    $res_instructors = $conn->query("SELECT COUNT(*) as total FROM Lecturers");
    if ($res_instructors) $stats['total_instructors'] = $res_instructors->fetch_assoc()['total'] ?? 0;

    $res_students = $conn->query("SELECT COUNT(*) as total FROM Students");
    if ($res_students) $stats['total_students'] = $res_students->fetch_assoc()['total'] ?? 0;

    $res_courses = $conn->query("SELECT COUNT(*) as total FROM Courses");
    if ($res_courses) $stats['total_courses'] = $res_courses->fetch_assoc()['total'] ?? 0;

    $res_classrooms = $conn->query("SELECT COUNT(*) as total FROM Classrooms");
    if ($res_classrooms) $stats['total_classrooms'] = $res_classrooms->fetch_assoc()['total'] ?? 0;

    $current_semester_id = null;
    $today = date('Y-m-d');
    $res_current_semester = $conn->query("SELECT SemesterID FROM Semesters WHERE StartDate <= '{$today}' AND EndDate >= '{$today}' ORDER BY StartDate DESC LIMIT 1");
    if ($res_current_semester && $res_current_semester->num_rows > 0) {
        $current_semester_id = $res_current_semester->fetch_assoc()['SemesterID'];
    } else {
        $res_current_semester_future = $conn->query("SELECT SemesterID FROM Semesters WHERE StartDate > '{$today}' ORDER BY StartDate ASC LIMIT 1");
        if ($res_current_semester_future && $res_current_semester_future->num_rows > 0) {
            $current_semester_id = $res_current_semester_future->fetch_assoc()['SemesterID'];
        } else {
            $res_current_semester_past = $conn->query("SELECT SemesterID FROM Semesters WHERE EndDate < '{$today}' ORDER BY EndDate DESC LIMIT 1");
            if($res_current_semester_past && $res_current_semester_past->num_rows > 0) {
                 $current_semester_id = $res_current_semester_past->fetch_assoc()['SemesterID'];
            }
        }
    }

    if ($current_semester_id) {
        $stmt_scheduled = $conn->prepare("SELECT COUNT(DISTINCT sc.CourseID, sc.TimeSlotID) as total FROM ScheduledClasses sc WHERE sc.SemesterID = ?");
        if($stmt_scheduled) {
            $stmt_scheduled->bind_param("i", $current_semester_id);
            $stmt_scheduled->execute();
            $res_scheduled = $stmt_scheduled->get_result();
            if ($res_scheduled) $stats['scheduled_classes_current_semester'] = $res_scheduled->fetch_assoc()['total'] ?? 0;
            $stmt_scheduled->close();
        }
    }

    $new_courses_query = "SELECT CourseID, CourseName, Credits, ExpectedStudents FROM Courses ORDER BY CourseID DESC LIMIT 2";
    $new_courses_result = $conn->query($new_courses_query);
    if ($new_courses_result) {
        while ($row = $new_courses_result->fetch_assoc()) {
            $new_courses_data[] = $row;
        }
    }

    if($current_semester_id) {
        $stmt_dates = $conn->prepare("SELECT DISTINCT DATE(ts.SessionDate) as schedule_date 
                                      FROM ScheduledClasses sc
                                      JOIN TimeSlots ts ON sc.TimeSlotID = ts.TimeSlotID
                                      WHERE sc.SemesterID = ? AND YEAR(ts.SessionDate) = ? AND MONTH(ts.SessionDate) = ?");
        if($stmt_dates){
            $current_year_int = (int)$current_year_cal;
            $current_month_int = (int)$current_month_cal;
            $stmt_dates->bind_param("iii", $current_semester_id, $current_year_int, $current_month_int);
            $stmt_dates->execute();
            $res_scheduled_dates = $stmt_dates->get_result();
            if($res_scheduled_dates) {
                while($row_date = $res_scheduled_dates->fetch_assoc()) {
                    $scheduled_dates_in_month[] = date('j', strtotime($row_date['schedule_date']));
                }
            }
            $stmt_dates->close();
        }
    }
} else {
    // Ghi log hoặc hiển thị lỗi kết nối CSDL một cách an toàn hơn nếu cần
    // echo "<div class='alert alert-danger'>Lỗi kết nối CSDL.</div>";
}
?>

<!-- Nội dung HTML của Dashboard Admin -->
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
    .dashboard-section-title { font-size: 1.25rem; font-weight: 500; margin-bottom: 1rem; color: #333; }
    .course-card { background-color: #fff; border-radius: 0.5rem; box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.075); padding: 1rem; display: flex; margin-bottom: 1rem; }
    .course-card img.course-thumbnail { width: 100px; height: 100px; object-fit: cover; border-radius: 0.375rem; margin-right: 1rem; }
    .course-card .course-info h5 { font-size: 1rem; font-weight: 600; margin-bottom: 0.25rem; }
    .course-card .course-info p { font-size: 0.85rem; color: #6c757d; margin-bottom: 0.25rem; }
    .course-card .course-info .btn-enroll { font-size: 0.8rem; padding: 0.25rem 0.75rem;}
    .calendar-widget { background-color: #fff; border-radius: 0.5rem; padding: 1.5rem; box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.075); }
    .calendar-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;}
    .calendar-header h5 { margin: 0; font-size: 1.1rem; }
    .calendar-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 5px; text-align: center; }
    .calendar-grid div { padding: 0.5em; font-size: 0.85rem; }
    .calendar-grid .day-name { font-weight: 600; color: #6c757d; }
    .calendar-grid .date-cell { border-radius: 0.25rem; cursor: pointer; transition: background-color 0.2s; }
    .calendar-grid .date-cell:hover:not(.empty) { background-color: #e9ecef; }
    .calendar-grid .date-cell.today { background-color: var(--primary-blue, #0d6efd); color: white; font-weight: bold; }
    .calendar-grid .date-cell.has-event { background-color: #cfe2ff; border: 1px solid var(--primary-blue, #0d6efd); }
    .calendar-grid .date-cell.empty { visibility: hidden; }
    .chart-container { background-color: #fff; padding: 1.5rem; border-radius: 0.5rem; box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.075); height: 350px; }
</style>

<div class="container-fluid px-4">
    <h1 class="mt-4">Chào mừng, <?php echo htmlspecialchars($user_fullname ?: "Admin"); ?>!</h1>
    <ol class="breadcrumb mb-4">
        <li class="breadcrumb-item active">Tổng quan hệ thống</li>
    </ol>

    <div class="row">
        <!-- Các thẻ thống kê -->
        <div class="col-xl-3 col-md-6"><div class="stat-card"><div class="card-body"><div class="icon-circle bg-primary-light"><i class="fas fa-chalkboard-teacher"></i></div><div class="stat-text"><h6>Tổng Giảng viên</h6><div class="stat-number"><?php echo $stats['total_instructors']; ?></div></div></div></div></div>
        <div class="col-xl-3 col-md-6"><div class="stat-card"><div class="card-body"><div class="icon-circle bg-success-light"><i class="fas fa-user-graduate"></i></div><div class="stat-text"><h6>Tổng Sinh viên</h6><div class="stat-number"><?php echo $stats['total_students']; ?></div></div></div></div></div>
        <div class="col-xl-3 col-md-6"><div class="stat-card"><div class="card-body"><div class="icon-circle bg-warning-light"><i class="fas fa-book"></i></div><div class="stat-text"><h6>Tổng Môn học</h6><div class="stat-number"><?php echo $stats['total_courses']; ?></div></div></div></div></div>
        <div class="col-xl-3 col-md-6"><div class="stat-card"><div class="card-body"><div class="icon-circle bg-info-light"><i class="fas fa-school"></i></div><div class="stat-text"><h6>Tổng Phòng học</h6><div class="stat-number"><?php echo $stats['total_classrooms']; ?></div></div></div></div></div>
        <div class="col-xl-3 col-md-6"><div class="stat-card"><div class="card-body"><div class="icon-circle bg-danger-light"><i class="fas fa-calendar-check"></i></div><div class="stat-text"><h6>Lớp đã xếp (Kỳ này)</h6><div class="stat-number"><?php echo $stats['scheduled_classes_current_semester']; ?></div></div></div></div></div>
    </div>

    <div class="row">
        <div class="col-lg-8 mb-4">
            <h5 class="dashboard-section-title">Các Môn học Mới (Ví dụ)</h5>
            <?php if (!empty($new_courses_data)): ?>
                <?php foreach ($new_courses_data as $course): ?>
                <div class="course-card">
                    <img src="<?php echo BASE_URL; ?>assets/images/course_placeholder.png" alt="<?php echo htmlspecialchars($course['CourseName']); ?>" class="course-thumbnail">
                    <div class="course-info">
                        <h5><?php echo htmlspecialchars($course['CourseName']); ?></h5>
                        <p><i class="fas fa-users"></i> SV dự kiến: <?php echo htmlspecialchars($course['ExpectedStudents']); ?> | <i class="fas fa-star"></i> Tín chỉ: <?php echo htmlspecialchars($course['Credits']); ?></p>
                        <p class="text-muted"><i class="fas fa-tags"></i> Mã MH: <?php echo htmlspecialchars($course['CourseID']); ?></p>
                        <a href="<?php echo BASE_URL; ?>admin/courses.php?action=view&id=<?php echo htmlspecialchars($course['CourseID']); ?>" class="btn btn-sm btn-outline-primary btn-enroll">Xem chi tiết</a>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p>Chưa có dữ liệu môn học mới.</p>
            <?php endif; ?>
        </div>

        <div class="col-lg-4 mb-4">
            <div class="calendar-widget">
                <div class="calendar-header">
                    <h5>Lịch làm việc</h5>
                    <span><?php echo date('F Y', $first_day_of_month_timestamp_cal); ?></span>
                </div>
                <div class="calendar-grid">
                    <div class="day-name">CN</div><div class="day-name">T2</div><div class="day-name">T3</div><div class="day-name">T4</div><div class="day-name">T5</div><div class="day-name">T6</div><div class="day-name">T7</div>
                    <?php
                        $first_day_idx_cal = ($first_day_of_week_cal == 7) ? 0 : $first_day_of_week_cal;
                        for ($i = 0; $i < $first_day_idx_cal; $i++) { echo "<div class='date-cell empty'></div>"; }
                        for ($day = 1; $day <= $days_in_month_cal; $day++) {
                            $class = "date-cell";
                            if ($day == date('j') && $current_month_cal == date('m') && $current_year_cal == date('Y')) { $class .= " today"; }
                            if (in_array($day, $scheduled_dates_in_month)) { $class .= " has-event"; }
                            echo "<div class='{$class}'>{$day}</div>";
                        }
                    ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-7 mb-4">
            <h5 class="dashboard-section-title">Hoạt động Khóa học (Ví dụ Số lớp được xếp)</h5>
            <div class="chart-container"><canvas id="courseActivityChart"></canvas></div>
        </div>
        <div class="col-lg-5 mb-4">
            <h5 class="dashboard-section-title">Tỷ lệ sử dụng phòng (Ví dụ)</h5>
             <div class="chart-container d-flex align-items-center justify-content-center">
                <canvas id="dailyActivityChart" style="max-width: 250px; max-height: 250px;"></canvas>
            </div>
        </div>
    </div>
</div> <!-- Kết thúc .container-fluid -->

</main> <!-- Đóng thẻ main.content-area được mở trong admin_sidebar_menu.php -->
</div> <!-- Đóng thẻ div.main-content-wrapper được mở trong admin_sidebar_menu.php -->

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<?php
// Chỉ load Chart.js và script của chart nếu $user_role là 'admin' và đang ở trang dashboard
// $user_role được định nghĩa trong admin_sidebar_menu.php
if (isset($user_role) && $user_role === 'admin' && basename($_SERVER['PHP_SELF']) === 'index.php'):
?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@3.7.0/dist/chart.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var ctxLine = document.getElementById('courseActivityChart')?.getContext('2d');
    if(ctxLine) {
        new Chart(ctxLine, {
            type: 'line',
            data: {
                labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
                datasets: [{
                    label: 'Số lớp được lên lịch', data: [10, 12, 8, 15, 13, 18, 20, 17, 16, 19, 22, 25],
                    borderColor: 'rgba(0, 92, 158, 1)', backgroundColor: 'rgba(0, 92, 158, 0.1)',
                    fill: true, tension: 0.3
                }]
            },
            options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true } } }
        });
    }
    var ctxDoughnut = document.getElementById('dailyActivityChart')?.getContext('2d');
    if(ctxDoughnut) {
        new Chart(ctxDoughnut, {
            type: 'doughnut',
            data: {
                labels: ['Phòng đã sử dụng', 'Phòng còn trống'],
                datasets: [{
                    label: 'Tình trạng phòng học', data: [75, 25],
                    backgroundColor: ['rgba(0, 92, 158, 0.8)', 'rgba(200, 200, 200, 0.7)'],
                    hoverOffset: 4
                }]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: true, position: 'bottom' }, tooltip: { callbacks: { label: function(context) { return (context.label || '') + ': ' + (context.parsed !== null ? context.parsed + '%' : ''); } } } } }
        });
    }
});
</script>
<?php endif; ?>
<script>
    // JS chung cho sidebar toggle (nếu có)
    document.addEventListener('DOMContentLoaded', function() {
        const sidebarToggleMobileBtn = document.getElementById('sidebarToggleMobile');
        const mainSidebar = document.getElementById('mainSidebar'); // Đảm bảo sidebar có id="mainSidebar" trong admin_sidebar_menu.php
        const mainContentWrapper = document.getElementById('mainContentWrapper'); // Đảm bảo wrapper có id

        if (sidebarToggleMobileBtn && mainSidebar && mainContentWrapper) {
            sidebarToggleMobileBtn.addEventListener('click', function() {
                mainSidebar.classList.toggle('active'); // Class 'active' sẽ làm transform: translateX(0);
                // mainContentWrapper.classList.toggle('sidebar-collapsed'); // Nếu muốn content thay đổi margin
            });
        }
    });
</script>
</body>
</html>