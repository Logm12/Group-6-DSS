<?php
// htdocs/DSS/admin/run_scheduler_ajax.php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db_connect.php';

$default_python_executable = 'python'; // Trên Windows, thường là 'python' hoặc đường dẫn đầy đủ đến python.exe
                                        // 'python3' có thể không được nhận dạng là command trực tiếp trên Windows trừ khi có alias
$default_cp_time_limit = 30.0;
$default_ga_pop_size = 50;
$default_ga_generations = 100;
$default_ga_crossover_rate = 0.8;
$default_ga_mutation_rate = 0.2;
$default_ga_tournament_size = 5;
$default_ga_allow_hc_violations = true; 
$default_priority_value = 'medium';

$response = ['status' => 'error_initial', 'message' => 'Script setup error.', 'log_file' => '', 'output_file' => ''];

try {
    if (!is_logged_in() || get_current_user_role() !== 'admin') {
        throw new Exception("Unauthorized access.");
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Invalid request method. Only POST is accepted.");
    }
    
    unset($_SESSION['scheduler_log_file'], $_SESSION['scheduler_output_file'], $_SESSION['scheduler_status'], $_SESSION['scheduler_progress_percent'], $_SESSION['fake_progress_increment']);

    $raw_input = file_get_contents('php://input');
    if ($raw_input === false) throw new Exception("Could not read request body.");
    
    $config_data_from_js = json_decode($raw_input, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid JSON data received. Error: " . json_last_error_msg());
    }
    
    if (!isset($config_data_from_js['semester_id_to_load']) || 
        $config_data_from_js['semester_id_to_load'] === '' || 
        $config_data_from_js['semester_id_to_load'] === null ||
        !filter_var($config_data_from_js['semester_id_to_load'], FILTER_VALIDATE_INT) ||
        (int)$config_data_from_js['semester_id_to_load'] <= 0
    ) {
        throw new Exception("A valid Semester ID is required from the form.");
    }
    $selected_semester_id = (int)$config_data_from_js['semester_id_to_load'];
    // Kiểm tra kỹ python_executable_path từ JS, nếu không có thì dùng default
    $python_executable_path_from_js = $config_data_from_js['python_executable_path'] ?? '';
    $python_executable_path = !empty(trim($python_executable_path_from_js)) ? sanitize_input($python_executable_path_from_js) : $default_python_executable;

    
    $run_id = date('Ymd_His') . '_' . substr(md5(uniqid(rand(), true)), 0, 8);
    $log_file_relative_to_python_algo_dir = 'output_data/logs/scheduler_progress_' . $run_id . '.log';
    $output_file_relative_to_python_algo_dir = 'output_data/final_schedule_output_' . $run_id . '.json';
    $input_file_relative_to_python_algo_dir = 'input_data/scheduler_input_config_' . $run_id . '.json';

    $_SESSION['scheduler_log_file'] = $log_file_relative_to_python_algo_dir;
    $_SESSION['scheduler_output_file'] = $output_file_relative_to_python_algo_dir;
    $_SESSION['scheduler_status'] = 'initiating_background'; 
    $_SESSION['scheduler_progress_percent'] = 1;

    $python_input_config = [
        'semester_id_to_load' => $selected_semester_id,
        'cp_time_limit_seconds' => (float)($config_data_from_js['cp_time_limit_seconds'] ?? $default_cp_time_limit),
        'ga_population_size' => (int)($config_data_from_js['ga_population_size'] ?? $default_ga_pop_size),
        'ga_generations' => (int)($config_data_from_js['ga_generations'] ?? $default_ga_generations),
        'ga_crossover_rate' => (float)($config_data_from_js['ga_crossover_rate'] ?? $default_ga_crossover_rate),
        'ga_mutation_rate' => (float)($config_data_from_js['ga_mutation_rate'] ?? $default_ga_mutation_rate),
        'ga_tournament_size' => (int)($config_data_from_js['ga_tournament_size'] ?? $default_ga_tournament_size),
        'ga_allow_hard_constraint_violations' => filter_var($config_data_from_js['ga_allow_hard_constraint_violations'] ?? $default_ga_allow_hc_violations, FILTER_VALIDATE_BOOLEAN),
        'priority_student_clash' => sanitize_input($config_data_from_js['priority_student_clash'] ?? $default_priority_value),
        'priority_lecturer_load_break' => sanitize_input($config_data_from_js['priority_lecturer_load_break'] ?? $default_priority_value), 
        'priority_classroom_util' => sanitize_input($config_data_from_js['priority_classroom_util'] ?? $default_priority_value),
        'progress_log_file_path_from_php' => $log_file_relative_to_python_algo_dir,
        'output_filename_override' => $output_file_relative_to_python_algo_dir
    ];
    
    $input_json_content_for_python = json_encode($python_input_config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    
    $python_algorithm_script_dir = realpath(__DIR__ . '/../python_algorithm');
    if (!$python_algorithm_script_dir) throw new Exception("Python algorithm directory not found at " . __DIR__ . '/../python_algorithm');
    
    // Chuẩn hóa đường dẫn cho Windows (thay \ bằng / nếu cần cho các lệnh shell, nhưng realpath thường trả về \)
    $python_algorithm_script_dir_cmd = str_replace('\\', '/', $python_algorithm_script_dir);


    $python_script_name_only = 'main_solver.py'; // Chỉ tên file script
    $python_input_filename_only = basename($input_file_relative_to_python_algo_dir);

    $input_file_abs_path_for_python = $python_algorithm_script_dir . DIRECTORY_SEPARATOR . 'input_data' . DIRECTORY_SEPARATOR . $python_input_filename_only;
    if (file_put_contents($input_file_abs_path_for_python, $input_json_content_for_python) === false) {
        throw new Exception("Could not write to Python input file: " . $input_file_abs_path_for_python);
    }

    $log_file_absolute_for_python = $python_algorithm_script_dir . DIRECTORY_SEPARATOR . $log_file_relative_to_python_algo_dir;
    $log_dir_for_python = dirname($log_file_absolute_for_python);
    if (!is_dir($log_dir_for_python)) {
        if (!@mkdir($log_dir_for_python, 0775, true) && !is_dir($log_dir_for_python)) {
             throw new Exception("Could not create Python log directory: " . $log_dir_for_python);
        }
    }
    
    // --- SỬA LỆNH CHO WINDOWS ---
    // Sử dụng start /B để chạy trong background mà không tạo cửa sổ mới
    // Đường dẫn cần được đặt trong dấu ngoặc kép nếu có khoảng trắng
    // Chuyển hướng output vào file log
    // quan trọng: `escapeshellcmd` cho python_executable_path
    // `escapeshellarg` cho các argument của script python (đường dẫn, tên file)

    $escaped_python_exe = escapeshellcmd($python_executable_path); // ví dụ: "C:\Python39\python.exe" hoặc chỉ "python"
    $escaped_script_name = escapeshellarg($python_script_name_only); // "main_solver.py"
    $escaped_input_file_arg = escapeshellarg($python_input_filename_only); // "scheduler_input_config_XYZ.json"
    $escaped_log_file_path = escapeshellarg($log_file_absolute_for_python);

    // Lệnh sẽ được thực thi từ bên trong thư mục $python_algorithm_script_dir_cmd
    // Không cần `cd` trong chính lệnh `start /B`
    $command_to_run_python = $escaped_python_exe . ' ' . $escaped_script_name . ' ' . $escaped_input_file_arg;
    
    // Lệnh `start /B` đầy đủ, bao gồm chuyển hướng output
    // Chạy từ thư mục của script python
    // Lưu ý: Chuyển hướng output của start /B có thể hơi phức tạp, 
    // tốt hơn là để script Python tự ghi log vào file được chỉ định trong config.
    // Python đã có logic ghi vào `_PROGRESS_LOG_FILE_PATH` dựa trên config.
    // Vì vậy, chúng ta chỉ cần chạy script Python.
    // `start /B` sẽ không đợi.
    
    $current_working_dir = getcwd(); // Lưu lại thư mục làm việc hiện tại của PHP
    chdir($python_algorithm_script_dir); // Chuyển thư mục làm việc sang thư mục của script Python

    // Lệnh đơn giản hơn: để Python tự quản lý ghi log dựa trên config
    // Chỉ cần chạy Python với argument là tên file config
    $command = 'start /B "" ' . $escaped_python_exe . ' ' . $escaped_script_name . ' ' . $escaped_input_file_arg . 
               ' > NUL 2>NUL'; // Chuyển hướng output của chính lệnh start /B vào NUL để không có cửa sổ console hiện ra
                               // Python script sẽ tự ghi vào file log đã được config

    error_log("run_scheduler_ajax.php: Executing Windows background command: " . $command . " (from CWD: " . $python_algorithm_script_dir . ")");
    
    pclose(popen($command, 'r')); // Thực thi và không đợi

    chdir($current_working_dir); // Trả lại thư mục làm việc cũ cho PHP

    $_SESSION['scheduler_status'] = 'running_background'; 
    $_SESSION['scheduler_progress_percent'] = 2; 
    session_write_close(); 

    $response['status'] = 'background_initiated';
    $response['message'] = 'Scheduler process has been initiated in the background (Windows). Please monitor progress via log file.';
    $response['log_file'] = $log_file_relative_to_python_algo_dir; 
    $response['output_file'] = $output_file_relative_to_python_algo_dir;
    // Không có PID dễ dàng trên Windows từ popen/start /B

} catch (Exception $e) {
    if (session_status() == PHP_SESSION_NONE) @session_start();
    $_SESSION['scheduler_status'] = 'error_php_fatal';
    if (session_id() !== '') @session_write_close(); 

    $response['status'] = 'error_php_fatal';
    $response['message'] = "PHP Fatal Error: " . $e->getMessage();
    error_log("run_scheduler_ajax.php FATAL EXCEPTION: " . $e->getMessage() . "\nFile: " . $e->getFile() . "\nLine: " . $e->getLine());
}

if (!headers_sent()) {
    header('Content-Type: application/json');
}
echo json_encode($response);
exit;
?>