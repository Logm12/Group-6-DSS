<?php
// htdocs/DSS/student/index.php
// File này sẽ được include đầu tiên ở tất cả các trang trong thư mục /student
include_once __DIR__ . '/../includes/admin_sidebar_menu.php';

// Yêu cầu vai trò là 'student', nếu không sẽ redirect về login.php
// Hàm require_role() phải được định nghĩa trong functions.php (được admin_sidebar_menu.php include)
require_role('student', '../login.php');

// $page_title đã được admin_sidebar_menu.php đặt là "Sinh viên Dashboard"
// $user_fullname, $user_role, $current_student_id (là $user_linked_id) cũng đã có từ admin_sidebar_menu.php

// Lấy một số thông tin tóm tắt cho dashboard của sinh viên (ví dụ)
$total_enrolled_courses_current_semester = 0;
$upcoming_classes_today = [];
$next_class_info = null;

// Tìm học kỳ hiện tại hoặc học kỳ sắp tới gần nhất để hiển thị thông tin
$current_or_next_semester_id = null;
$stmt_current_sem = $conn->prepare("SELECT SemesterID FROM Semesters 
                                    WHERE StartDate <= CURDATE() AND EndDate >= CURDATE() 
                                    ORDER BY StartDate DESC LIMIT 1");
if ($stmt_current_sem) {
    $stmt_current_sem->execute();
    $res_current_sem = $stmt_current_sem->get_result();
    if ($res_current_sem->num_rows > 0) {
        $current_or_next_semester_id = $res_current_sem->fetch_assoc()['SemesterID'];
    }
    $stmt_current_sem->close();
}

if (!$current_or_next_semester_id) { // Nếu không có học kỳ hiện tại, tìm học kỳ sắp tới
    $stmt_next_sem = $conn->prepare("SELECT SemesterID FROM Semesters 
                                     WHERE StartDate > CURDATE() 
                                     ORDER BY StartDate ASC LIMIT 1");
    if ($stmt_next_sem) {
        $stmt_next_sem->execute();
        $res_next_sem = $stmt_next_sem->get_result();
        if ($res_next_sem->num_rows > 0) {
            $current_or_next_semester_id = $res_next_sem->fetch_assoc()['SemesterID'];
        }
        $stmt_next_sem->close();
    }
}


if ($current_student_id && $current_or_next_semester_id) {
    // Đếm số môn đã đăng ký trong học kỳ hiện tại/sắp tới
    $stmt_count = $conn->prepare("SELECT COUNT(DISTINCT CourseID) as total_courses 
                                  FROM StudentEnrollments 
                                  WHERE StudentID = ? AND SemesterID = ?");
    if ($stmt_count) {
        $stmt_count->bind_param("si", $current_student_id, $current_or_next_semester_id);
        $stmt_count->execute();
        $result_count = $stmt_count->get_result();
        if ($result_count->num_rows > 0) {
            $total_enrolled_courses_current_semester = $result_count->fetch_assoc()['total_courses'];
        }
        $stmt_count->close();
    }

    // Lấy các lớp học hôm nay
    $today_date = date('Y-m-d');
    $stmt_today = $conn->prepare("SELECT c.CourseName, t.StartTime, t.EndTime, cr.RoomCode
                                 FROM ScheduledClasses sc
                                 JOIN StudentEnrollments se ON sc.CourseID = se.CourseID AND sc.SemesterID = se.SemesterID
                                 JOIN Courses c ON sc.CourseID = c.CourseID
                                 JOIN TimeSlots t ON sc.TimeSlotID = t.TimeSlotID
                                 JOIN Classrooms cr ON sc.ClassroomID = cr.ClassroomID
                                 WHERE se.StudentID = ? AND sc.SemesterID = ? AND t.SessionDate = ?
                                 ORDER BY t.StartTime ASC");
    if ($stmt_today) {
        $stmt_today->bind_param("sis", $current_student_id, $current_or_next_semester_id, $today_date);
        $stmt_today->execute();
        $res_today = $stmt_today->get_result();
        while($row = $res_today->fetch_assoc()){
            $upcoming_classes_today[] = $row;
        }
        $stmt_today->close();
    }

    // Lấy lớp học kế tiếp (nếu có)
    $now_time = date('H:i:s');
    $stmt_next = $conn->prepare("SELECT c.CourseName, t.StartTime, t.EndTime, cr.RoomCode, t.DayOfWeek, t.SessionDate
                                FROM ScheduledClasses sc
                                JOIN StudentEnrollments se ON sc.CourseID = se.CourseID AND sc.SemesterID = se.SemesterID
                                JOIN Courses c ON sc.CourseID = c.CourseID
                                JOIN TimeSlots t ON sc.TimeSlotID = t.TimeSlotID
                                JOIN Classrooms cr ON sc.ClassroomID = cr.ClassroomID
                                WHERE se.StudentID = ? AND sc.SemesterID = ? 
                                AND ( (t.SessionDate = ? AND t.StartTime > ?) OR t.SessionDate > ? )
                                ORDER BY t.SessionDate ASC, t.StartTime ASC
                                LIMIT 1");
     if ($stmt_next) {
        $stmt_next->bind_param("sisss", $current_student_id, $current_or_next_semester_id, $today_date, $now_time, $today_date);
        $stmt_next->execute();
        $res_next = $stmt_next->get_result();
        if($res_next->num_rows > 0){
            $next_class_info = $res_next->fetch_assoc();
        }
        $stmt_next->close();
    }
}

?>

<div class="container-fluid px-4">
    <h1 class="mt-4">Chào mừng, <?php echo htmlspecialchars($user_fullname); ?>!</h1>
    <ol class="breadcrumb mb-4">
        <li class="breadcrumb-item active">Bảng điều khiển Sinh viên</li>
    </ol>

    <?php echo display_all_flash_messages(); // Hiển thị thông báo (nếu có) ?>

    <div class="row">
        <div class="col-xl-4 col-md-6">
            <div class="card bg-primary text-white mb-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-white-75 small">Môn đã đăng ký</div>
                            <div class="fs-4 fw-bold"><?php echo $total_enrolled_courses_current_semester; ?></div>
                        </div>
                        <i class="fas fa-book-open fa-3x opacity-50"></i>
                    </div>
                </div>
                <div class="card-footer d-flex align-items-center justify-content-between">
                    <a class="small text-white stretched-link" href="<?php echo BASE_URL; ?>student/my_schedule.php<?php if($current_or_next_semester_id) echo '?semester_id='.$current_or_next_semester_id; ?>">Xem lịch học chi tiết</a>
                    <div class="small text-white"><i class="fas fa-angle-right"></i></div>
                </div>
            </div>
        </div>
        <div class="col-xl-4 col-md-6">
            <div class="card bg-warning text-dark mb-4"> <!-- Đổi màu cho dễ phân biệt -->
                <div class="card-body">
                     <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-dark-75 small">Lớp học kế tiếp</div>
                            <?php if ($next_class_info): ?>
                                <div class="fs-5 fw-bold"><?php echo htmlspecialchars($next_class_info['CourseName']); ?></div>
                                <div class="small">
                                    <?php echo htmlspecialchars(get_vietnamese_day_of_week($next_class_info['DayOfWeek'])); ?>, 
                                    <?php echo htmlspecialchars(format_date_for_display($next_class_info['SessionDate'])); ?>
                                </div>
                                <div class="small">
                                    <?php echo htmlspecialchars(format_time_for_display($next_class_info['StartTime'])); ?> - 
                                    <?php echo htmlspecialchars(format_time_for_display($next_class_info['EndTime'])); ?>
                                    tại <?php echo htmlspecialchars($next_class_info['RoomCode']); ?>
                                </div>
                            <?php else: ?>
                                <div class="fs-5 fw-bold">Không có</div>
                                <div class="small">Không có lớp học nào sắp tới.</div>
                            <?php endif; ?>
                        </div>
                        <i class="fas fa-hourglass-half fa-3x opacity-50"></i>
                    </div>
                </div>
                 <div class="card-footer d-flex align-items-center justify-content-between">
                    <span class="small text-dark">Thông tin cập nhật</span>
                    <div class="small text-dark"><i class="fas fa-sync-alt"></i></div>
                </div>
            </div>
        </div>
        <div class="col-xl-4 col-md-6">
            <div class="card bg-success text-white mb-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-white-75 small">Thông báo mới</div>
                            <div class="fs-4 fw-bold">0</div> <!-- Thay bằng số thông báo thực tế -->
                        </div>
                         <i class="fas fa-bell fa-3x opacity-50"></i>
                    </div>
                </div>
                <div class="card-footer d-flex align-items-center justify-content-between">
                    <a class="small text-white stretched-link" href="#">Xem tất cả thông báo</a>
                    <div class="small text-white"><i class="fas fa-angle-right"></i></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-12">
            <div class="card mb-4">
                <div class="card-header">
                    <i class="fas fa-calendar-day me-1"></i>
                    Các lớp học hôm nay (<?php echo htmlspecialchars(format_date_for_display(date('Y-m-d'))); ?>)
                </div>
                <div class="card-body">
                    <?php if (!empty($upcoming_classes_today)): ?>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($upcoming_classes_today as $class): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <div>
                                        <span class="fw-bold"><?php echo htmlspecialchars(format_time_for_display($class['StartTime'])); ?> - <?php echo htmlspecialchars(format_time_for_display($class['EndTime'])); ?>:</span>
                                        <?php echo htmlspecialchars($class['CourseName']); ?>
                                        <span class="text-muted small">(Phòng: <?php echo htmlspecialchars($class['RoomCode']); ?>)</span>
                                    </div>
                                    <a href="<?php echo BASE_URL; ?>student/my_schedule.php?semester_id=<?php echo $current_or_next_semester_id; ?>#pills-<?php echo strtolower(date('l', strtotime($today_date))); ?>-tab" class="btn btn-sm btn-outline-primary">
                                        Xem chi tiết
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="text-muted">Bạn không có lớp học nào được lên lịch cho hôm nay.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Thêm các widget hoặc thông tin khác cho dashboard sinh viên nếu cần -->
    <!-- Ví dụ: Biểu đồ tiến độ học tập, Tin tức từ khoa,... -->

</div> <!-- container-fluid -->

</main> <!-- Đóng thẻ main từ admin_sidebar_menu.php -->
</div> <!-- Đóng thẻ div.main-content-wrapper từ admin_sidebar_menu.php -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // JS cho sidebar toggle (nếu admin_sidebar_menu.php không tự xử lý)
    document.addEventListener('DOMContentLoaded', function() {
        const sidebarToggleMobileBtn = document.getElementById('sidebarToggleMobile');
        const mainSidebar = document.getElementById('mainSidebar');
        if (sidebarToggleMobileBtn && mainSidebar) {
            sidebarToggleMobileBtn.addEventListener('click', function() {
                mainSidebar.classList.toggle('active');
            });
        }
    });
</script>
</body>
</html>