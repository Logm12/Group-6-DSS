<?php
// htdocs/DSS/student/student_schedule_builder.php
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/functions.php';
include_once __DIR__ . '/../includes/admin_sidebar_menu.php'; 
require_role('student', '../login.php');

$page_title = "Xây dựng Lịch học Cá nhân";
$current_student_id = get_current_user_linked_entity_id();

if (!$current_student_id) {
    set_flash_message('error', 'Không tìm thấy thông tin sinh viên.', 'danger');
    redirect('index.php');
}

$selected_semester_id = null;
$all_courses_in_semester_with_classes = [];
$student_selected_course_ids_from_post = []; // Lưu các CourseID SV đã chủ động chọn lớp từ POST
$schedule_options_for_selected_courses = [];
$generated_schedules = []; 
$best_schedule_display = null;
$info_message = '';
$error_message_builder = '';

$semesters_query = $conn->query("SELECT SemesterID, SemesterName FROM Semesters WHERE EndDate >= CURDATE() ORDER BY StartDate DESC");
$available_semesters = [];
while($row = $semesters_query->fetch_assoc()){
    $available_semesters[] = $row;
}

if (isset($_GET['semester_id']) || isset($_POST['semester_id_hidden'])) {
    $selected_semester_id = intval($_GET['semester_id'] ?? $_POST['semester_id_hidden']);

    $stmt_all_courses = $conn->prepare(
        "SELECT DISTINCT c.CourseID, c.CourseName, c.Credits, 
               sc.ScheduleID, t.DayOfWeek, t.SessionDate, t.StartTime, t.EndTime, 
               cr.RoomCode, l.LecturerName
         FROM Courses c
         JOIN ScheduledClasses sc ON c.CourseID = sc.CourseID
         JOIN TimeSlots t ON sc.TimeSlotID = t.TimeSlotID
         JOIN Classrooms cr ON sc.ClassroomID = cr.ClassroomID
         JOIN Lecturers l ON sc.LecturerID = l.LecturerID
         WHERE sc.SemesterID = ?
         ORDER BY c.CourseName ASC, t.SessionDate ASC, t.StartTime ASC"
    );

    if($stmt_all_courses){
        $stmt_all_courses->bind_param("i", $selected_semester_id);
        $stmt_all_courses->execute();
        $result_all_courses = $stmt_all_courses->get_result();
        while($row = $result_all_courses->fetch_assoc()){
            $all_courses_in_semester_with_classes[$row['CourseID']]['info'] = ['CourseName' => $row['CourseName'], 'Credits' => $row['Credits']];
            $all_courses_in_semester_with_classes[$row['CourseID']]['classes'][] = $row;
        }
        $stmt_all_courses->close();
    } else {
        $error_message_builder = "Lỗi lấy danh sách môn học và lớp: " . $conn->error;
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['build_schedule']) && $selected_semester_id) {
    $posted_selected_classes = $_POST['selected_class'] ?? []; // Dạng [CourseID => ScheduleID]
    
    if (empty($posted_selected_classes)) {
        $error_message_builder = "Vui lòng chọn lớp cho ít nhất một môn học.";
    } else {
        foreach ($posted_selected_classes as $course_id => $schedule_id_selected) {
            if (empty($schedule_id_selected)) continue; // SV chọn "Không chọn môn này"

            $student_selected_course_ids_from_post[] = $course_id; // Các môn SV thực sự muốn xếp

            if (isset($all_courses_in_semester_with_classes[$course_id])) {
                foreach ($all_courses_in_semester_with_classes[$course_id]['classes'] as $class_option) {
                    if ($class_option['ScheduleID'] == $schedule_id_selected) {
                        // Thêm CourseName vào $class_option để generate_possible_schedules_for_student có thể dùng
                        $class_option['CourseName'] = $all_courses_in_semester_with_classes[$course_id]['info']['CourseName'];
                        $schedule_options_for_selected_courses[$course_id][] = $class_option; 
                        break;
                    }
                }
            }
        }

        if (empty($schedule_options_for_selected_courses)) {
            $error_message_builder = "Bạn chưa chọn lớp hợp lệ cho bất kỳ môn nào.";
        } else {
            $all_possible_schedules = [];
            // Gọi hàm generate_possible_schedules_for_student với các lớp mà SV đã CHỌN
            // Hàm này đã được sửa trong functions.php để xử lý đúng
            generate_possible_schedules_for_student(
                $schedule_options_for_selected_courses, // Chỉ chứa các lớp SV đã chọn cho từng môn
                [], 0, $all_possible_schedules
            );
            $generated_schedules = $all_possible_schedules;

            if (!empty($generated_schedules)) {
                $best_schedule_display = select_best_schedule_for_student($generated_schedules);
                
                if (!$best_schedule_display && !empty($generated_schedules)) {
                    $best_schedule_display = $generated_schedules[0]; // Lấy cái đầu tiên nếu select_best không tìm được
                }

                 if($best_schedule_display){
                    $num_courses_in_best_schedule = count($best_schedule_display);
                    $num_courses_student_wanted = count(array_keys($schedule_options_for_selected_courses)); // Số môn SV đã chọn 1 lớp

                    if($num_courses_in_best_schedule == $num_courses_student_wanted){
                        set_flash_message('success', 'Đã tạo lịch đề xuất không trùng cho các lựa chọn của bạn!', 'success');
                    } else {
                        $missing_count = $num_courses_student_wanted - $num_courses_in_best_schedule;
                        set_flash_message('warning', "Đã tạo lịch, nhưng có {$missing_count} môn không thể xếp được do xung đột với các lựa chọn khác của bạn.", 'warning');
                    }
                } else { // $generated_schedules rỗng nhưng $schedule_options_for_selected_courses không rỗng
                     $error_message_builder = "Không thể tạo lịch không trùng từ các lớp bạn đã chọn. Vui lòng kiểm tra lại các lựa chọn xem có bị xung đột về Thứ-Ca học không.";
                }
            } else { // $generated_schedules rỗng ngay từ đầu
                $error_message_builder = "Không thể tạo lịch không trùng từ các lớp bạn đã chọn. Các lựa chọn của bạn có thể đang bị xung đột về Thứ-Ca học.";
            }
        }
    }
}

if ($best_schedule_display) {
    usort($best_schedule_display, function($a, $b){
        // Sắp xếp theo thứ tự ngày trong tuần, rồi đến ngày cụ thể, rồi đến giờ bắt đầu
        $days_order = ['Monday'=>1, 'Tuesday'=>2, 'Wednesday'=>3, 'Thursday'=>4, 'Friday'=>5, 'Saturday'=>6, 'Sunday'=>7];
        $day_a_order = $days_order[$a['DayOfWeek']] ?? 8;
        $day_b_order = $days_order[$b['DayOfWeek']] ?? 8;
        if ($day_a_order != $day_b_order) {
            return $day_a_order - $day_b_order;
        }
        $date_comp = strcmp($a['SessionDate'], $b['SessionDate']);
        if ($date_comp == 0) { return strtotime($a['StartTime']) - strtotime($b['StartTime']); }
        return $date_comp;
    });
}
?>
<style>
    /* ... CSS đã có ... */
    .course-block { margin-bottom: 20px; border: 1px solid #eee; padding: 15px; border-radius: 5px; background-color:#fff; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
    .course-block h5 { color: #005c9e; }
    .class-option { padding: 8px 12px; border-bottom: 1px dotted #f0f0f0; transition: background-color 0.2s ease; }
    .class-option:last-child { border-bottom: none; }
    .class-option:hover { background-color: #f8f9fa; }
    .class-option label { display: block; width: 100%; cursor: pointer; font-weight: normal; }
    .class-option input[type="radio"] { margin-right: 10px; vertical-align: middle;}
    .class-option .class-time-info { font-weight: 500; color: #333; }
    .class-option .details { font-size: 0.85em; color: #555; margin-left: 28px; line-height: 1.4; }
    .nav-pills .nav-link.active { background-color: #005c9e; }
    .nav-pills .nav-link { color: #005c9e; }
    .schedule-table th { background-color: #e9ecef; }
</style>

<div class="container-fluid px-4">
    <h1 class="mt-4"><?php echo htmlspecialchars($page_title); ?></h1>
    <ol class="breadcrumb mb-4">
        <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>student/index.php">Dashboard</a></li>
        <li class="breadcrumb-item active">Xây dựng lịch học</li>
    </ol>

    <?php echo display_all_flash_messages(); ?>
    <?php if (!empty($error_message_builder)): ?>
        <div class="alert alert-danger"><?php echo $error_message_builder; ?></div>
    <?php endif; ?>
    <?php if (!empty($info_message)): ?>
        <div class="alert alert-info"><?php echo nl2br(htmlspecialchars($info_message)); ?></div>
    <?php endif; ?>

    <form method="GET" action="" class="row g-3 align-items-end mb-3">
        <div class="col-md-5">
            <label for="semester_id_select" class="form-label fw-bold">1. Chọn học kỳ:</label>
            <select name="semester_id" id="semester_id_select" class="form-select" onchange="this.form.submit()">
                <option value="">-- Chọn học kỳ --</option>
                <?php foreach ($available_semesters as $semester): ?>
                    <option value="<?php echo $semester['SemesterID']; ?>" <?php echo ($selected_semester_id == $semester['SemesterID'] ? 'selected' : ''); ?>>
                        <?php echo htmlspecialchars($semester['SemesterName']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>

    <?php if ($selected_semester_id && !empty($all_courses_in_semester_with_classes)): ?>
    <form method="POST" action="?semester_id=<?php echo $selected_semester_id; // Giữ semester_id trên URL khi POST ?>">
        <input type="hidden" name="semester_id_hidden" value="<?php echo $selected_semester_id; ?>">
        <div class="card mb-4">
            <div class="card-header"><i class="fas fa-tasks me-1"></i> 2. Chọn một lớp cho mỗi môn học bạn muốn đăng ký</div>
            <div class="card-body">
                <p>Hệ thống sẽ cố gắng tạo lịch không trùng từ các lựa chọn của bạn dựa trên <strong>Thứ và Ca học</strong>. Ngày học cụ thể vẫn được hiển thị để bạn tham khảo.</p>
                <div class="row">
                <?php foreach ($all_courses_in_semester_with_classes as $course_id => $course_data): ?>
                    <div class="col-md-6 col-lg-4">
                        <div class="course-block">
                            <h5><?php echo htmlspecialchars($course_data['info']['CourseName']); ?> (<?php echo htmlspecialchars($course_id); ?>)</h5>
                            <small class="text-muted"><?php echo $course_data['info']['Credits']; ?> tín chỉ</small>
                            <hr class="my-2">
                            <?php if (empty($course_data['classes'])): ?>
                                <p class="text-danger"><em>Môn này hiện không có lớp nào được mở.</em></p>
                            <?php else: ?>
                                <?php foreach ($course_data['classes'] as $index => $class_item): ?>
                                    <div class="class-option form-check">
                                        <input class="form-check-input" type="radio" 
                                               name="selected_class[<?php echo htmlspecialchars($course_id); ?>]" 
                                               id="class_<?php echo htmlspecialchars($course_id); ?>_<?php echo $class_item['ScheduleID']; ?>" 
                                               value="<?php echo $class_item['ScheduleID']; ?>"
                                               <?php 
                                               // Giữ lại lựa chọn nếu form được POST lại (ví dụ khi có lỗi và hiển thị lại form)
                                               if(isset($_POST['selected_class'][$course_id]) && $_POST['selected_class'][$course_id] == $class_item['ScheduleID']) echo 'checked';
                                               ?>
                                               >
                                        <label class="form-check-label" for="class_<?php echo htmlspecialchars($course_id); ?>_<?php echo $class_item['ScheduleID']; ?>">
                                            <span class="class-time-info">
                                                <?php echo get_vietnamese_day_of_week($class_item['DayOfWeek']); ?> - 
                                                <?php echo htmlspecialchars(get_period_string_from_times($class_item['StartTime'], $class_item['EndTime'])); // Sử dụng hàm mới ?>
                                            </span>
                                            <div class="details">
                                                Ngày: <?php echo format_date_for_display($class_item['SessionDate']); ?><br>
                                                Phòng: <?php echo htmlspecialchars($class_item['RoomCode']); ?>,
                                                GV: <?php echo htmlspecialchars($class_item['LecturerName']); ?>
                                            </div>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                                 <div class="form-check mt-2 class-option">
                                     <input class="form-check-input" type="radio" 
                                            name="selected_class[<?php echo htmlspecialchars($course_id); ?>]" 
                                            id="class_<?php echo htmlspecialchars($course_id); ?>_none" 
                                            value="" 
                                            <?php 
                                            // Check nếu không có lựa chọn nào cho môn này hoặc giá trị là rỗng
                                            if (!isset($_POST['selected_class'][$course_id]) || empty($_POST['selected_class'][$course_id])) echo 'checked'; 
                                            ?>
                                            >
                                     <label class="form-check-label" for="class_<?php echo htmlspecialchars($course_id); ?>_none">
                                         <em>Không chọn môn này / Bỏ chọn</em>
                                     </label>
                                 </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>
            </div>
            <div class="card-footer text-end">
                <button type="submit" name="build_schedule" class="btn btn-primary btn-lg">
                    <i class="fas fa-cogs me-1"></i> Tạo Lịch Đề Xuất
                </button>
            </div>
        </div>
    </form>
    <?php elseif($selected_semester_id && empty($all_courses_in_semester_with_classes)): ?>
        <div class="alert alert-info">Không có môn học nào được mở lớp trong học kỳ này để bạn lựa chọn.</div>
    <?php endif; ?>

    <?php if ($best_schedule_display): ?>
        <div class="card mt-4">
            <div class="card-header"><i class="fas fa-calendar-check me-1"></i>Lịch học đề xuất</div>
            <div class="card-body">
                <p>Lịch này được tạo dựa trên các lựa chọn của bạn và không có xung đột về <strong>Thứ - Ca học</strong>. 
                   Nó cũng được ưu tiên để giảm số ngày đi học trong tuần và thời gian chờ giữa các ca.
                   (Ngày học cụ thể của từng buổi được hiển thị để bạn tham khảo.)
                </p>
                <?php
                $days_of_week_order = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
                $schedule_by_day_display = [];
                foreach ($days_of_week_order as $day) { $schedule_by_day_display[$day] = []; }
                foreach ($best_schedule_display as $event) {
                    $schedule_by_day_display[$event['DayOfWeek']][] = $event;
                }
                ?>
                <ul class="nav nav-pills mb-3" id="pills-tab-optimized" role="tablist">
                    <?php $first_day_tab_opt = true; ?>
                    <?php foreach ($days_of_week_order as $day_name_eng_opt): ?>
                        <?php if (!empty($schedule_by_day_display[$day_name_eng_opt])): ?>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link <?php if ($first_day_tab_opt) { echo 'active'; $first_day_tab_opt = false; } ?>" 
                                        id="pills-opt-<?php echo strtolower($day_name_eng_opt); ?>-tab" 
                                        data-bs-toggle="pill" 
                                        data-bs-target="#pills-opt-<?php echo strtolower($day_name_eng_opt); ?>" 
                                        type="button" role="tab">
                                    <?php echo get_vietnamese_day_of_week($day_name_eng_opt); ?>
                                </button>
                            </li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </ul>
                <div class="tab-content" id="pills-tabContent-optimized">
                    <?php $first_day_content_opt = true; ?>
                    <?php foreach ($days_of_week_order as $day_name_eng_opt): ?>
                        <?php if (!empty($schedule_by_day_display[$day_name_eng_opt])): ?>
                            <div class="tab-pane fade <?php if ($first_day_content_opt) { echo 'show active'; $first_day_content_opt = false; } ?>" 
                                 id="pills-opt-<?php echo strtolower($day_name_eng_opt); ?>" role="tabpanel"
                                 aria-labelledby="pills-opt-<?php echo strtolower($day_name_eng_opt); ?>-tab">
                                <div class="table-responsive">
                                    <table class="table table-bordered table-hover schedule-table">
                                        <thead><tr><th>Môn học (Mã)</th><th>Thứ - Tiết học</th><th>Phòng</th><th>Giảng viên</th><th>Ngày cụ thể</th></tr></thead>
                                        <tbody>
                                            <?php foreach ($schedule_by_day_display[$day_name_eng_opt] as $event): ?>
                                                <tr>
                                                    <td>
                                                        <div class="course-name-display"><?php echo htmlspecialchars($event['CourseName']); ?></div>
                                                        <div class="details-text">(<?php echo htmlspecialchars($event['CourseID']); ?>)</div>
                                                    </td>
                                                    <td><?php echo htmlspecialchars(get_vietnamese_day_of_week($event['DayOfWeek'])); ?> - <?php echo htmlspecialchars(get_period_string_from_times($event['StartTime'], $event['EndTime'])); ?></td>
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
    <?php elseif ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['build_schedule']) && empty($error_message_builder) && empty($info_message)): ?>
        <div class="alert alert-warning mt-4">Không tìm thấy tổ hợp lịch nào phù hợp cho các lựa chọn của bạn. Điều này có thể do các lớp bạn chọn bị trùng Thứ - Ca học.</div>
    <?php endif; ?>
    
    <?php 
    // Optional: Hiển thị các lịch khả thi khác (nếu có và bạn muốn)
    // if (!empty($generated_schedules) && count($generated_schedules) > 1 && $best_schedule_display){ ... } 
    ?>

</div>

</main>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>