<?php
// htdocs/DSS/admin/scheduler.php
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/functions.php'; 
include_once __DIR__ . '/../includes/admin_sidebar_menu.php';
require_role('admin', '../login.php');

// --- Config ---
$python_executable = 'python'; 
$python_script_name = 'main_solver.py';
$project_root_dss = dirname(__DIR__);
$python_algorithm_path = $project_root_dss . '/python_algorithm/';

// --- Định nghĩa các giá trị DEFAULT cho các mức tối ưu ---
// Mức "Nhanh"
define('PRESET_FAST_CP_TIME', 30);
define('PRESET_FAST_GA_GENS', 30);
define('PRESET_FAST_GA_POP', 20);

// Mức "Cân bằng" (Mặc định)
define('PRESET_BALANCED_CP_TIME', 60);
define('PRESET_BALANCED_GA_GENS', 50);
define('PRESET_BALANCED_GA_POP', 30);

// Mức "Chất lượng cao"
define('PRESET_QUALITY_CP_TIME', 120);
define('PRESET_QUALITY_GA_GENS', 100);
define('PRESET_QUALITY_GA_POP', 50);

// Các default khác (ít thay đổi bởi người dùng cuối)
$default_ga_crossover_rate = 0.85;
$default_ga_mutation_rate = 0.15;
$default_ga_tournament_size = 5;
$default_ga_allow_hc_violations = false;

// Giá trị khởi tạo cho form (sẽ được ghi đè bởi preset hoặc POST)
$form_cp_time_limit = PRESET_BALANCED_CP_TIME;
$form_ga_generations = PRESET_BALANCED_GA_GENS;
$form_ga_population_size = PRESET_BALANCED_GA_POP;
$form_selected_preset = 'balanced'; // Mặc định

$selected_semester_id = null;
$scheduling_log = [];
$schedule_results = null;
$error_message = '';
$success_message = '';

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['start_scheduling'])) {
    if (!empty($_POST['semester_id'])) {
        $selected_semester_id = intval($_POST['semester_id']);
        $form_selected_preset = $_POST['optimization_preset'] ?? 'balanced';

        // Ưu tiên giá trị từ input trực tiếp, nếu không có thì lấy từ preset
        $cp_time_limit = isset($_POST['cp_time_limit_override']) && is_numeric($_POST['cp_time_limit_override']) ? intval($_POST['cp_time_limit_override']) : null;
        $ga_generations = isset($_POST['ga_generations_override']) && is_numeric($_POST['ga_generations_override']) ? intval($_POST['ga_generations_override']) : null;
        $ga_population_size = isset($_POST['ga_population_size_override']) && is_numeric($_POST['ga_population_size_override']) ? intval($_POST['ga_population_size_override']) : null;

        // Nếu các override không được đặt, sử dụng giá trị từ preset đã chọn
        if ($cp_time_limit === null) {
            switch ($form_selected_preset) {
                case 'fast': $cp_time_limit = PRESET_FAST_CP_TIME; break;
                case 'quality': $cp_time_limit = PRESET_QUALITY_CP_TIME; break;
                default: $cp_time_limit = PRESET_BALANCED_CP_TIME; // balanced
            }
        }
        if ($ga_generations === null) {
             switch ($form_selected_preset) {
                case 'fast': $ga_generations = PRESET_FAST_GA_GENS; break;
                case 'quality': $ga_generations = PRESET_QUALITY_GA_GENS; break;
                default: $ga_generations = PRESET_BALANCED_GA_GENS;
            }
        }
        if ($ga_population_size === null) {
            switch ($form_selected_preset) {
                case 'fast': $ga_population_size = PRESET_FAST_GA_POP; break;
                case 'quality': $ga_population_size = PRESET_QUALITY_GA_POP; break;
                default: $ga_population_size = PRESET_BALANCED_GA_POP;
            }
        }
        
        // Cập nhật lại giá trị form để hiển thị đúng sau khi submit (nếu dùng preset)
        $form_cp_time_limit = $cp_time_limit;
        $form_ga_generations = $ga_generations;
        $form_ga_population_size = $ga_population_size;


        $scheduling_log[] = "Bắt đầu quá trình xếp lịch cho học kỳ ID: " . $selected_semester_id;
        $scheduling_log[] = "Kịch bản tối ưu đã chọn: " . ucfirst($form_selected_preset);
        $scheduling_log[] = "Cấu hình thực tế: Thời gian tìm giải pháp ban đầu (CP) = " . $cp_time_limit . "s";
        $scheduling_log[] = "Tinh chỉnh giải pháp (GA): Số vòng lặp = " . $ga_generations . ", Số lượng giải pháp mỗi vòng = " . $ga_population_size;

        $input_config_data_for_python = [
            'semester_id_to_load' => $selected_semester_id,
            'cp_time_limit_seconds' => $cp_time_limit,
            'ga_generations' => $ga_generations,
            'ga_population_size' => $ga_population_size,
            'ga_crossover_rate' => $default_ga_crossover_rate, // Các tham số này ít khi người dùng cuối chỉnh
            'ga_mutation_rate' => $default_ga_mutation_rate,
            'ga_tournament_size' => $default_ga_tournament_size,
            'ga_allow_hard_constraint_violations' => $default_ga_allow_hc_violations
        ];
        $input_json_content = json_encode($input_config_data_for_python, JSON_PRETTY_PRINT);

        if ($input_json_content === false) {
            // ... (xử lý lỗi json encode)
            $error_message = "Lỗi tạo dữ liệu JSON đầu vào cho Python.";
            $scheduling_log[] = "[LỖI] " . $error_message;
        } else {
            // ... (phần gọi Python và xử lý kết quả như cũ)
            $scheduling_log[] = "Đã tạo file cấu hình đầu vào cho Python.";
            $scheduling_log[] = "Đang gọi thuật toán Python (" . $python_script_name . ")... Vui lòng đợi.";

            $python_input_filename = 'scheduler_input_config.json'; 
            $python_output_filename = 'final_schedule_output.json'; 
            $python_script_absolute_path = $python_algorithm_path . $python_script_name;
            
            $estimated_ga_time_per_gen = 0.5; 
            $estimated_ga_time = $ga_generations * $estimated_ga_time_per_gen;
            $php_timeout = $cp_time_limit + $estimated_ga_time + 90; // Tăng buffer một chút

            $python_result = call_python_scheduler(
                $python_executable,
                $python_script_absolute_path,
                $input_json_content,
                $python_input_filename,
                $python_output_filename,
                (int)$php_timeout
            );

            $scheduling_log[] = "Kết quả thực thi Python:";
            $scheduling_log[] = "Trạng thái hàm gọi Python: " . htmlspecialchars($python_result['status']);
            $scheduling_log[] = "Thông điệp hàm gọi Python: " . nl2br(htmlspecialchars($python_result['message']));
            
            if (isset($python_result['data']) && is_array($python_result['data'])) {
                 $scheduling_log[] = "Trạng thái chi tiết từ Script Python: " . htmlspecialchars($python_result['data']['status'] ?? 'N/A');
                 $scheduling_log[] = "Thông điệp chi tiết từ Script Python: " . nl2br(htmlspecialchars($python_result['data']['message'] ?? 'N/A'));
            }

            if (!empty($python_result['debug_stdout'])) {
                $scheduling_log[] = "Python STDOUT:<pre>" . htmlspecialchars($python_result['debug_stdout']) . "</pre>";
            }
            if (!empty($python_result['debug_stderr'])) {
                $scheduling_log[] = "Python STDERR:<pre>" . htmlspecialchars($python_result['debug_stderr']) . "</pre>";
            }

            if ($python_result['status'] === 'success' && isset($python_result['data']) && isset($python_result['data']['status']) && strpos($python_result['data']['status'], 'success') === 0) {
                $schedule_data_from_python = $python_result['data']; 

                if (isset($schedule_data_from_python['final_schedule']) && is_array($schedule_data_from_python['final_schedule'])) {
                    $schedule_results = $schedule_data_from_python['final_schedule']; 
                    $num_scheduled = count($schedule_results);
                    $num_cp_attempted = $schedule_data_from_python['num_events_cp_attempted_to_schedule'] ?? 'N/A';
                    $ga_penalty_val = $schedule_data_from_python['ga_final_penalty'] ?? 'N/A';
                    $scheduling_log[] = "Thuật toán hoàn thành. Số lớp được xếp: " . $num_scheduled . " (CP đã cố gắng xếp: " . $num_cp_attempted . "). Điểm tối ưu GA: " . $ga_penalty_val;


                    if ($num_scheduled > 0) {
                        // ... (phần INSERT vào DB như cũ)
                        $scheduling_log[] = "Đang lưu lịch vào cơ sở dữ liệu...";
                        $conn->begin_transaction();
                        try {
                            $stmt_delete = $conn->prepare("DELETE FROM ScheduledClasses WHERE SemesterID = ?");
                            if (!$stmt_delete) throw new Exception("Lỗi chuẩn bị xóa lịch cũ: " . $conn->error);
                            $stmt_delete->bind_param("i", $selected_semester_id);
                            if(!$stmt_delete->execute()) throw new Exception("Lỗi thực thi xóa lịch cũ: " . $stmt_delete->error);
                            $scheduling_log[] = "Đã xóa " . $stmt_delete->affected_rows . " lớp học cũ của học kỳ ID " . $selected_semester_id . ".";
                            $stmt_delete->close();

                            $stmt_insert = $conn->prepare("INSERT INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) VALUES (?, ?, ?, ?, ?)");
                            if (!$stmt_insert) throw new Exception("Lỗi chuẩn bị chèn lịch mới: " . $conn->error);
                            
                            $inserted_count = 0;
                            foreach ($schedule_results as $class_event) {
                                $course_id_str = $class_event['course_id'] ?? null;
                                $lecturer_id_int = isset($class_event['lecturer_id']) ? intval($class_event['lecturer_id']) : null;
                                $classroom_id_int = isset($class_event['classroom_id']) ? intval($class_event['classroom_id']) : null;
                                $timeslot_id_int = isset($class_event['timeslot_id']) ? intval($class_event['timeslot_id']) : null;

                                if ($course_id_str === null || $lecturer_id_int === null || $classroom_id_int === null || $timeslot_id_int === null) {
                                     $scheduling_log[] = "[LỖI DỮ LIỆU TỪ PYTHON] Thiếu thông tin ID cho một lớp học: " . htmlspecialchars(json_encode($class_event));
                                     continue;
                                }
                                $stmt_insert->bind_param("siiii", $course_id_str, $lecturer_id_int, $classroom_id_int, $timeslot_id_int, $selected_semester_id);
                                if ($stmt_insert->execute()) {
                                    $inserted_count++;
                                } else {
                                    $scheduling_log[] = "[LỖI INSERT] Không thể lưu lớp: C=" . htmlspecialchars($course_id_str) . 
                                                        ",L=" . htmlspecialchars($lecturer_id_int) . 
                                                        ",R=" . htmlspecialchars($classroom_id_int) . 
                                                        ",T=" . htmlspecialchars($timeslot_id_int) . 
                                                        ". Lỗi: " . htmlspecialchars($stmt_insert->error);
                                }
                            }
                            $stmt_insert->close();

                            if ($inserted_count == $num_scheduled && $num_scheduled > 0) {
                                $conn->commit();
                                $success_message = "Đã xếp lịch và lưu thành công " . $inserted_count . " lớp học cho học kỳ ID " . $selected_semester_id . ".";
                                $scheduling_log[] = $success_message;
                            } else if ($inserted_count > 0) { 
                                $conn->commit();
                                $warning_message = "Đã lưu được " . $inserted_count . "/" . $num_scheduled . " lớp. Một số lớp có thể đã bị lỗi khi lưu. Vui lòng kiểm tra log.";
                                $scheduling_log[] = $warning_message;
                                if(empty($error_message)) $error_message = $warning_message; else $error_message .= " " . $warning_message;
                            } else if ($num_scheduled > 0 && $inserted_count == 0) { 
                                 $conn->rollback();
                                 $error_message = "Thuật toán tạo ra lịch nhưng không thể lưu bất kỳ lớp nào vào CSDL. Vui lòng kiểm tra log lỗi insert.";
                                 $scheduling_log[] = "[LỖI] " . $error_message;
                            } else { 
                                $conn->rollback(); 
                                $error_message = "Không có lớp học nào được tạo bởi thuật toán hoặc không có lớp nào được lưu.";
                                $scheduling_log[] = "[THÔNG BÁO] " . $error_message;
                            }
                        } catch (Exception $e) {
                            $conn->rollback();
                            $error_message = "Lỗi khi lưu lịch vào CSDL: " . $e->getMessage();
                            $scheduling_log[] = "[LỖI CSDL] " . $error_message;
                        }
                    } else { 
                         $scheduling_log[] = "Không có lịch nào được trả về từ thuật toán để lưu.";
                         if(empty($error_message)) $error_message = $python_result['data']['message'] ?? "Thuật toán không tạo ra lịch trình nào.";
                    }
                } else {
                    $error_message = "Kết quả từ Python không có cấu trúc mong đợi.";
                    $scheduling_log[] = "[LỖI CẤU TRÚC OUTPUT PYTHON] " . $error_message;
                    if(isset($schedule_data_from_python)) $scheduling_log[] = "Dữ liệu trả về: <pre>" . htmlspecialchars(json_encode($schedule_data_from_python, JSON_PRETTY_PRINT)) . "</pre>";
                }
            } else { 
                // ... (xử lý lỗi Python như cũ)
                if (isset($python_result['data']) && isset($python_result['data']['message'])) {
                    $error_message = "Quá trình xếp lịch Python không thành công. Thông điệp từ Python: " . htmlspecialchars($python_result['data']['message']);
                } elseif (isset($python_result['message'])) {
                    $error_message = "Lỗi khi gọi Python: " . htmlspecialchars($python_result['message']);
                } else {
                    $error_message = "Lỗi không xác định khi thực thi script Python.";
                }
                $scheduling_log[] = "[LỖI THỰC THI PYTHON] " . $error_message;
            }
        }
    } elseif ($_SERVER["REQUEST_METHOD"] == "POST") {
        $error_message = "Vui lòng chọn một học kỳ.";
    }
}

?>
<style>
    /* ... (CSS giữ nguyên như trước) ... */
    .scheduler-form .form-control, .scheduler-form .form-select { margin-bottom: 1rem; }
    .scheduling-log-container { max-height: 400px; overflow-y: auto; background-color: #f8f9fa; border: 1px solid #dee2e6; padding: 15px; border-radius: 0.25rem; font-family: monospace; font-size: 0.9em; line-height: 1.4; }
    .scheduling-log-container pre { margin: 0; white-space: pre-wrap; word-break: break-all;}
    .schedule-results-table { margin-top: 20px; }
    .btn-primary { background-color: #007bff; border-color: #007bff; }
    .form-text { font-size: 0.875em; color: #6c757d; }
    #advanced-options-toggle { cursor: pointer; color: #007bff; text-decoration: none; }
    #advanced-options { display: none; /* Mặc định ẩn */ margin-top: 1rem; padding-top:1rem; border-top: 1px solid #eee; }
    #advanced-options.show { display: block; }
</style>

<div class="container-fluid px-4">
    <h1 class="mt-4">Xếp lịch tự động</h1>
    <ol class="breadcrumb mb-4">
        <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
        <li class="breadcrumb-item active">Xếp lịch</li>
    </ol>

    <?php if (!empty($error_message)): ?>
        <div class="alert alert-danger" role="alert"><?php echo $error_message; ?></div>
    <?php endif; ?>
    <?php if (!empty($success_message)): ?>
        <div class="alert alert-success" role="alert"><?php echo $success_message; ?></div>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-header">
            <i class="fas fa-cogs me-1"></i>
            Cấu hình và Chạy Xếp lịch
        </div>
        <div class="card-body scheduler-form">
            <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" id="schedulerForm">
                <div class="row">
                    <div class="col-md-6 col-lg-4">
                        <label for="semester_id" class="form-label fw-bold">Chọn Học kỳ:</label>
                        <select name="semester_id" id="semester_id" class="form-select" required>
                            <option value="">-- Chọn học kỳ --</option>
                            <?php
                            if (function_exists('generate_select_options')) {
                                echo generate_select_options($conn, 'Semesters', 'SemesterID', 'SemesterName', $selected_semester_id, "EndDate >= CURDATE()", "StartDate DESC");
                            } else {
                                echo "<option value=''>Lỗi: Hàm generate_select_options không tồn tại.</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div class="col-md-6 col-lg-4">
                        <label for="optimization_preset" class="form-label fw-bold">Mức độ tối ưu:</label>
                        <select name="optimization_preset" id="optimization_preset" class="form-select">
                            <option value="fast" <?php echo ($form_selected_preset === 'fast' ? 'selected' : ''); ?>>Nhanh (Chất lượng cơ bản)</option>
                            <option value="balanced" <?php echo ($form_selected_preset === 'balanced' ? 'selected' : ''); ?>>Cân bằng (Khuyến nghị)</option>
                            <option value="quality" <?php echo ($form_selected_preset === 'quality' ? 'selected' : ''); ?>>Chất lượng cao (Chạy lâu hơn)</option>
                            <option value="custom" <?php echo ($form_selected_preset === 'custom' ? 'selected' : ''); ?>>Tùy chỉnh nâng cao...</option>
                        </select>
                        <div class="form-text">Chọn một kịch bản có sẵn hoặc tùy chỉnh các thông số dưới đây.</div>
                    </div>
                     <div class="col-md-12 col-lg-2 align-self-end mt-3 mt-lg-0">
                        <button type="submit" name="start_scheduling" class="btn btn-primary w-100">
                            <i class="fas fa-play me-1"></i> Bắt đầu
                        </button>
                    </div>
                </div>
                
                <div id="advanced-options" class="<?php echo ($form_selected_preset === 'custom' ? 'show' : ''); ?>">
                    <h5 class="mt-3">Tùy chỉnh nâng cao:</h5>
                    <div class="row">
                        <div class="col-md-4">
                            <label for="cp_time_limit_override" class="form-label">Thời gian tìm giải pháp ban đầu (giây):</label>
                            <input type="number" name="cp_time_limit_override" id="cp_time_limit_override" class="form-control" value="<?php echo htmlspecialchars($form_cp_time_limit); ?>" min="10" step="10">
                            <div class="form-text">Thời gian tối đa cho giai đoạn tìm kiếm lịch cơ bản (CP).</div>
                        </div>
                        <div class="col-md-4">
                            <label for="ga_generations_override" class="form-label">Số vòng lặp tinh chỉnh (GA):</label>
                            <input type="number" name="ga_generations_override" id="ga_generations_override" class="form-control" value="<?php echo htmlspecialchars($form_ga_generations); ?>" min="10" step="10">
                            <div class="form-text">Số lần lặp lại để cải thiện lịch (GA). Nhiều hơn có thể tốt hơn nhưng lâu hơn.</div>
                        </div>
                         <div class="col-md-4">
                            <label for="ga_population_size_override" class="form-label">Số giải pháp mỗi vòng lặp (GA):</label>
                            <input type="number" name="ga_population_size_override" id="ga_population_size_override" class="form-control" value="<?php echo htmlspecialchars($form_ga_population_size); ?>" min="10" step="5">
                            <div class="form-text">Số lượng phương án lịch được xem xét trong mỗi vòng lặp tinh chỉnh.</div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Phần Log và Kết quả giữ nguyên -->
    <?php if (!empty($scheduling_log)): ?>
    <div class="card mb-4"><div class="card-header"><i class="fas fa-history me-1"></i>Nhật ký Quá trình Xếp lịch</div><div class="card-body"><div class="scheduling-log-container">
        <?php foreach ($scheduling_log as $log_entry): ?><div><?php echo $log_entry; ?></div><?php endforeach; ?>
    </div></div></div>
    <?php endif; ?>

    <?php if ($schedule_results !== null && is_array($schedule_results) && count($schedule_results) > 0): ?>
    <div class="card"><div class="card-header"><i class="fas fa-calendar-alt me-1"></i>Kết quả Lịch đã xếp (<?php echo count($schedule_results); ?> lớp)</div><div class="card-body"><div class="table-responsive">
        <table class="table table-bordered table-striped table-hover schedule-results-table" id="scheduleResultsTable">
            <thead><tr><th>STT</th><th>Mã Môn</th><th>Tên Môn</th><th>Giảng viên</th><th>Phòng</th><th>Thứ</th><th>Ngày</th><th>Bắt đầu</th><th>Kết thúc</th></tr></thead>
            <tbody>
                <?php
                $course_cache = []; $lecturer_cache = []; $classroom_cache = []; $timeslot_cache = []; $stt = 1;
                foreach ($schedule_results as $event):
                    $c_id_str = $event['course_id'] ?? 'N/A';
                    $l_id_int = isset($event['lecturer_id']) ? intval($event['lecturer_id']) : null; 
                    $r_id_int = isset($event['classroom_id']) ? intval($event['classroom_id']) : null; 
                    $t_id_int = isset($event['timeslot_id']) ? intval($event['timeslot_id']) : null; 

                    if (!isset($course_cache[$c_id_str])) {
                        $stmt_c = $conn->prepare("SELECT CourseName FROM Courses WHERE CourseID = ?");
                        if($stmt_c) { $stmt_c->bind_param("s", $c_id_str); $stmt_c->execute(); $res_c = $stmt_c->get_result(); $course_cache[$c_id_str] = ($res_c && $res_c->num_rows > 0) ? $res_c->fetch_assoc()['CourseName'] : $c_id_str; $stmt_c->close(); }
                        else { $course_cache[$c_id_str] = $c_id_str . " (DB Err)"; }
                    }
                    if ($l_id_int !== null && !isset($lecturer_cache[$l_id_int])) {
                        $stmt_l = $conn->prepare("SELECT LecturerName FROM Lecturers WHERE LecturerID = ?");
                        if($stmt_l) { $stmt_l->bind_param("i", $l_id_int); $stmt_l->execute(); $res_l = $stmt_l->get_result(); $lecturer_cache[$l_id_int] = ($res_l && $res_l->num_rows > 0) ? $res_l->fetch_assoc()['LecturerName'] : "ID: ".$l_id_int; $stmt_l->close(); }
                        else { $lecturer_cache[$l_id_int] = "ID: ".$l_id_int . " (DB Err)"; }
                    }
                    if ($r_id_int !== null && !isset($classroom_cache[$r_id_int])) {
                        $stmt_r = $conn->prepare("SELECT RoomCode FROM Classrooms WHERE ClassroomID = ?");
                        if($stmt_r) { $stmt_r->bind_param("i", $r_id_int); $stmt_r->execute(); $res_r = $stmt_r->get_result(); $classroom_cache[$r_id_int] = ($res_r && $res_r->num_rows > 0) ? $res_r->fetch_assoc()['RoomCode'] : "ID: ".$r_id_int; $stmt_r->close(); }
                         else { $classroom_cache[$r_id_int] = "ID: ".$r_id_int . " (DB Err)"; }
                    }
                    if ($t_id_int !== null && !isset($timeslot_cache[$t_id_int])) {
                        $stmt_t = $conn->prepare("SELECT DayOfWeek, SessionDate, StartTime, EndTime FROM TimeSlots WHERE TimeSlotID = ?");
                        if($stmt_t) { $stmt_t->bind_param("i", $t_id_int); $stmt_t->execute(); $res_t = $stmt_t->get_result(); $timeslot_cache[$t_id_int] = ($res_t && $res_t->num_rows > 0) ? $res_t->fetch_assoc() : null; $stmt_t->close(); }
                         else { $timeslot_cache[$t_id_int] = null; }
                    }
                ?>
                <tr>
                    <td><?php echo $stt++; ?></td>
                    <td><?php echo htmlspecialchars($c_id_str); ?></td>
                    <td><?php echo htmlspecialchars($course_cache[$c_id_str] ?? $c_id_str); ?></td>
                    <td><?php echo htmlspecialchars($lecturer_cache[$l_id_int] ?? 'N/A'); ?></td>
                    <td><?php echo htmlspecialchars($classroom_cache[$r_id_int] ?? 'N/A'); ?></td>
                    <td><?php echo isset($timeslot_cache[$t_id_int]) ? htmlspecialchars(get_vietnamese_day_of_week($timeslot_cache[$t_id_int]['DayOfWeek'])) : 'N/A'; ?></td>
                    <td><?php echo isset($timeslot_cache[$t_id_int]) ? htmlspecialchars(format_date_for_display($timeslot_cache[$t_id_int]['SessionDate'])) : 'N/A'; ?></td>
                    <td><?php echo isset($timeslot_cache[$t_id_int]) ? htmlspecialchars(format_time_for_display($timeslot_cache[$t_id_int]['StartTime'])) : 'N/A'; ?></td>
                    <td><?php echo isset($timeslot_cache[$t_id_int]) ? htmlspecialchars(format_time_for_display($timeslot_cache[$t_id_int]['EndTime'])) : 'N/A'; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody></table>
        </div></div>
    </div>
    <?php elseif ($schedule_results !== null && is_array($schedule_results) && count($schedule_results) == 0 && isset($_POST['start_scheduling'])): ?>
        <div class="alert alert-warning mt-4">Không tìm thấy lịch trình nào phù hợp với các ràng buộc, hoặc không có lịch nào được tạo.</div>
    <?php endif; ?>
</div>

</main>
</div> 

<link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
<script type="text/javascript" charset="utf8" src="https://code.jquery.com/jquery-3.7.0.js"></script>
<script type="text/javascript" charset="utf8" src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script type="text/javascript" charset="utf8" src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const presetSelect = document.getElementById('optimization_preset');
    const advancedOptionsDiv = document.getElementById('advanced-options');
    const cpTimeInput = document.getElementById('cp_time_limit_override');
    const gaGensInput = document.getElementById('ga_generations_override');
    const gaPopInput = document.getElementById('ga_population_size_override');

    const presets = {
        fast: { cp: <?php echo PRESET_FAST_CP_TIME; ?>, ga_gens: <?php echo PRESET_FAST_GA_GENS; ?>, ga_pop: <?php echo PRESET_FAST_GA_POP; ?> },
        balanced: { cp: <?php echo PRESET_BALANCED_CP_TIME; ?>, ga_gens: <?php echo PRESET_BALANCED_GA_GENS; ?>, ga_pop: <?php echo PRESET_BALANCED_GA_POP; ?> },
        quality: { cp: <?php echo PRESET_QUALITY_CP_TIME; ?>, ga_gens: <?php echo PRESET_QUALITY_GA_GENS; ?>, ga_pop: <?php echo PRESET_QUALITY_GA_POP; ?> }
    };

    function updateAdvancedFields(selectedPreset) {
        if (selectedPreset === 'custom') {
            advancedOptionsDiv.classList.add('show');
        } else {
            advancedOptionsDiv.classList.remove('show');
            if (presets[selectedPreset]) {
                cpTimeInput.value = presets[selectedPreset].cp;
                gaGensInput.value = presets[selectedPreset].ga_gens;
                gaPopInput.value = presets[selectedPreset].ga_pop;
            }
        }
    }

    if (presetSelect) {
        presetSelect.addEventListener('change', function() {
            updateAdvancedFields(this.value);
        });
        // Initial call to set fields based on loaded preset
        updateAdvancedFields(presetSelect.value);
    }

    // Sidebar toggle (nếu cần, vì admin_sidebar_menu.php có thể đã xử lý)
    const sidebarToggleMobileBtn = document.getElementById('sidebarToggleMobile');
    const mainSidebar = document.getElementById('mainSidebar');
    if (sidebarToggleMobileBtn && mainSidebar) {
        sidebarToggleMobileBtn.addEventListener('click', function() {
            mainSidebar.classList.toggle('active');
        });
    }

    // Initialize DataTables
    if (document.getElementById('scheduleResultsTable')) {
        try {
            new DataTable('#scheduleResultsTable', {
                pageLength: 25,
                language: { search: "Tìm kiếm:", lengthMenu: "Hiển thị _MENU_ mục", info: "Hiển thị _START_ đến _END_ của _TOTAL_ mục", infoEmpty: "Không có mục nào", infoFiltered: "(lọc từ _MAX_ mục)", paginate: { first: "Đầu", last: "Cuối", next: "Tiếp", previous: "Trước" } }
            });
        } catch (e) { console.error("Error initializing DataTable: ", e); }
    }
});
</script>
</body>
</html>