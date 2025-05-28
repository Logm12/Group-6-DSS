<?php
// htdocs/DSS/student/my_schedule.php
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/functions.php';
// Giả sử admin_sidebar_menu.php xử lý việc hiển thị menu dựa trên vai trò
// và cũng khởi tạo session. Nếu không, bạn cần session_start() ở đây.
include_once __DIR__ . '/../includes/admin_sidebar_menu.php'; 
require_role('student', '../login.php'); // Đảm bảo chỉ sinh viên mới truy cập được

$page_title = "Thời Khóa Biểu Cá Nhân";

// Lấy StudentID của sinh viên đang đăng nhập
$current_student_id = get_current_user_linked_entity_id();
if (!$current_student_id) {
    // Xử lý trường hợp không tìm thấy StudentID (ví dụ: tài khoản chưa liên kết)
    set_flash_message('error', 'Không tìm thấy thông tin sinh viên liên kết với tài khoản của bạn.', 'danger');
    // Có thể redirect về trang profile hoặc dashboard của sinh viên
    redirect(BASE_URL . 'student/index.php'); 
}

$selected_semester_id = null;
$student_schedule = []; // Mảng chứa các lớp học của sinh viên
$error_message_schedule = '';

// Lấy danh sách các học kỳ mà sinh viên có đăng ký môn học hoặc đã có lịch
// Điều này giúp dropdown chỉ hiển thị các học kỳ liên quan đến sinh viên.
// Cách 1: Lấy các học kỳ mà SV có đăng ký môn
$semesters_query_sql = "SELECT DISTINCT s.SemesterID, s.SemesterName 
                        FROM Semesters s
                        JOIN StudentEnrollments se ON s.SemesterID = se.SemesterID
                        WHERE se.StudentID = ?
                        ORDER BY s.StartDate DESC";
// Cách 2: Lấy các học kỳ mà SV có lịch học (nếu lịch đã được xếp)
// $semesters_query_sql = "SELECT DISTINCT s.SemesterID, s.SemesterName
//                         FROM Semesters s
//                         JOIN ScheduledClasses sc ON s.SemesterID = sc.SemesterID
//                         JOIN StudentEnrollments se ON sc.CourseID = se.CourseID AND sc.SemesterID = se.SemesterID
//                         WHERE se.StudentID = ?
//                         ORDER BY s.StartDate DESC";

$stmt_semesters = $conn->prepare($semesters_query_sql);
if(!$stmt_semesters) {
    die("Lỗi chuẩn bị truy vấn học kỳ: " . $conn->error);
}
$stmt_semesters->bind_param("s", $current_student_id);
$stmt_semesters->execute();
$semesters_result = $stmt_semesters->get_result();
$available_semesters = [];
while($row = $semesters_result->fetch_assoc()){
    $available_semesters[] = $row;
}
$stmt_semesters->close();


// Xử lý khi người dùng chọn một học kỳ
if ($_SERVER["REQUEST_METHOD"] == "GET" && isset($_GET['semester_id'])) {
    $selected_semester_id = intval($_GET['semester_id']);

    // Truy vấn lịch học của sinh viên cho học kỳ đã chọn
    // Lấy các lớp mà sinh viên đã đăng ký (StudentEnrollments) và đã được xếp lịch (ScheduledClasses)
    $sql = "SELECT 
                sc.CourseID,
                c.CourseName,
                sc.LecturerID,
                l.LecturerName,
                sc.ClassroomID,
                cr.RoomCode,
                sc.TimeSlotID,
                t.DayOfWeek,
                t.SessionDate,
                t.StartTime,
                t.EndTime
            FROM ScheduledClasses sc
            JOIN Courses c ON sc.CourseID = c.CourseID
            JOIN Lecturers l ON sc.LecturerID = l.LecturerID
            JOIN Classrooms cr ON sc.ClassroomID = cr.ClassroomID
            JOIN TimeSlots t ON sc.TimeSlotID = t.TimeSlotID
            JOIN StudentEnrollments se ON sc.CourseID = se.CourseID AND sc.SemesterID = se.SemesterID
            WHERE se.StudentID = ? AND sc.SemesterID = ?
            ORDER BY t.SessionDate ASC, t.StartTime ASC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        $error_message_schedule = "Lỗi chuẩn bị truy vấn lịch học: " . $conn->error;
    } else {
        $stmt->bind_param("si", $current_student_id, $selected_semester_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $student_schedule[] = $row;
            }
        } else {
            if($selected_semester_id && !empty($available_semesters)){ // Chỉ thông báo nếu đã chọn học kỳ hợp lệ
                 $error_message_schedule = "Không tìm thấy lịch học cho bạn trong học kỳ này, hoặc lịch chưa được công bố.";
            }
        }
        $stmt->close();
    }
} elseif (!empty($available_semesters) && $selected_semester_id === null) {
    // Nếu có học kỳ khả dụng nhưng chưa chọn, có thể tự động chọn học kỳ gần nhất
    // $selected_semester_id = $available_semesters[0]['SemesterID'];
    // Hoặc hiển thị thông báo yêu cầu chọn
    if (count($available_semesters) == 1) { // Nếu chỉ có 1 học kỳ, tự động chọn
        $selected_semester_id = $available_semesters[0]['SemesterID'];
        // Chạy lại logic lấy lịch với semester_id này (hoặc redirect)
        // Để đơn giản, bạn có thể copy logic từ khối if ở trên, hoặc thực hiện redirect:
        // header("Location: " . $_SERVER['PHP_SELF'] . "?semester_id=" . $selected_semester_id);
        // exit();
        // Tuy nhiên, để tránh vòng lặp redirect, tốt hơn là thực hiện query ngay tại đây
        // (Copy logic query ở trên, nhưng cẩn thận để không lặp code quá nhiều)
        // Hoặc chỉ cần thông báo:
        $error_message_schedule = "Vui lòng chọn một học kỳ để xem lịch.";

    } else if (count($available_semesters) > 1) {
        $error_message_schedule = "Vui lòng chọn một học kỳ để xem lịch.";
    }
}


// Sắp xếp lịch cuối cùng theo ngày và giờ để hiển thị (nếu có)
if (!empty($student_schedule)) {
    usort($student_schedule, function($a, $b){
        $date_comp = strcmp($a['SessionDate'], $b['SessionDate']);
        if ($date_comp == 0) {
            return strtotime($a['StartTime']) - strtotime($b['StartTime']);
        }
        return $date_comp;
    });
}

?>
<style>
    /* Thêm CSS nếu cần, hoặc dùng CSS chung của template */
    .schedule-table th, .schedule-table td {
        text-align: center;
        vertical-align: middle;
    }
    .nav-pills .nav-link.active {
        background-color: #007bff;
        color: white;
    }
    .nav-pills .nav-link {
        color: #007bff;
    }
    .course-name-display { font-weight: bold; }
    .details-text { font-size: 0.9em; color: #555; }
</style>

<div class="container-fluid px-4">
    <h1 class="mt-4"><?php echo $page_title; ?></h1>
    <ol class="breadcrumb mb-4">
        <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>student/index.php">Dashboard</a></li>
        <li class="breadcrumb-item active">Thời khóa biểu</li>
    </ol>

    <?php echo display_all_flash_messages(); ?>

    <div class="card mb-4">
        <div class="card-header">
            <i class="fas fa-filter me-1"></i>
            Chọn học kỳ
        </div>
        <div class="card-body">
            <form method="GET" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                <div class="row">
                    <div class="col-md-6">
                        <select name="semester_id" id="semester_id" class="form-select" onchange="this.form.submit();">
                            <option value="">-- Chọn học kỳ --</option>
                            <?php if (!empty($available_semesters)): ?>
                                <?php foreach ($available_semesters as $semester): ?>
                                    <option value="<?php echo $semester['SemesterID']; ?>" <?php echo ($selected_semester_id == $semester['SemesterID'] ? 'selected' : ''); ?>>
                                        <?php echo htmlspecialchars($semester['SemesterName']); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option value="" disabled>Không có học kỳ nào có đăng ký của bạn.</option>
                            <?php endif; ?>
                        </select>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <?php if (!empty($error_message_schedule)): ?>
        <div class="alert alert-info"><?php echo $error_message_schedule; ?></div>
    <?php endif; ?>

    <?php if ($selected_semester_id && !empty($student_schedule)): ?>
        <?php
        // Chuẩn bị dữ liệu để hiển thị theo tab các thứ trong tuần
        $days_of_week_order = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        $schedule_by_day = [];
        foreach ($days_of_week_order as $day) {
            $schedule_by_day[$day] = [];
        }
        foreach ($student_schedule as $event) {
            $schedule_by_day[$event['DayOfWeek']][] = $event;
        }
        ?>
        <div class="card">
            <div class="card-header">
                <i class="fas fa-calendar-alt me-1"></i>
                Thời khóa biểu chi tiết
            </div>
            <div class="card-body">
                <ul class="nav nav-pills mb-3" id="pills-tab-schedule" role="tablist">
                    <?php $first_day_tab = true; ?>
                    <?php foreach ($days_of_week_order as $day_name_eng): ?>
                        <?php if (!empty($schedule_by_day[$day_name_eng])): // Chỉ hiển thị tab nếu có lịch ?>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link <?php if ($first_day_tab) { echo 'active'; $first_day_tab = false; } ?>" 
                                        id="pills-<?php echo strtolower($day_name_eng); ?>-tab" 
                                        data-bs-toggle="pill" 
                                        data-bs-target="#pills-<?php echo strtolower($day_name_eng); ?>" 
                                        type="button" role="tab" 
                                        aria-controls="pills-<?php echo strtolower($day_name_eng); ?>" 
                                        aria-selected="<?php echo $first_day_tab ? 'true' : 'false'; ?>">
                                    <?php echo get_vietnamese_day_of_week($day_name_eng); ?>
                                </button>
                            </li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </ul>

                <div class="tab-content" id="pills-tabContent-schedule">
                    <?php $first_day_content_tab = true; ?>
                    <?php foreach ($days_of_week_order as $day_name_eng): ?>
                        <?php if (!empty($schedule_by_day[$day_name_eng])): ?>
                            <div class="tab-pane fade <?php if ($first_day_content_tab) { echo 'show active'; $first_day_content_tab = false; } ?>" 
                                 id="pills-<?php echo strtolower($day_name_eng); ?>" 
                                 role="tabpanel" 
                                 aria-labelledby="pills-<?php echo strtolower($day_name_eng); ?>-tab">
                                
                                <div class="table-responsive">
                                    <table class="table table-bordered table-hover schedule-table">
                                        <thead>
                                            <tr>
                                                <th>Môn học (Mã)</th>
                                                <th>Thời gian</th>
                                                <th>Phòng</th>
                                                <th>Giảng viên</th>
                                                <th>Ngày cụ thể</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($schedule_by_day[$day_name_eng] as $event): ?>
                                                <tr>
                                                    <td>
                                                        <div class="course-name-display"><?php echo htmlspecialchars($event['CourseName']); ?></div>
                                                        <div class="details-text">(<?php echo htmlspecialchars($event['CourseID']); ?>)</div>
                                                    </td>
                                                    <td><?php echo htmlspecialchars(format_time_for_display($event['StartTime'])); ?> - <?php echo htmlspecialchars(format_time_for_display($event['EndTime'])); ?></td>
                                                    <td><?php echo htmlspecialchars($event['RoomCode']); ?></td>
                                                    <td><?php echo htmlspecialchars($event['LecturerName']); ?></td>
                                                    <td><?php echo htmlspecialchars(format_date_for_display($event['SessionDate'])); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php elseif($selected_semester_id && empty($error_message_schedule)): // Đã chọn học kỳ nhưng không có lịch và không có lỗi nào khác ?>
         <div class="alert alert-info">Không có lịch học nào được tìm thấy cho bạn trong học kỳ này.</div>
    <?php endif; ?>

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