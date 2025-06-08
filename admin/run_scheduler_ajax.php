<?php
// htdocs/DSS/admin/run_scheduler_ajax.php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json'); // Always respond with JSON
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db_connect.php';

// Default configuration values for the Python script
$default_python_executable = 'python'; 
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
        throw new Exception("Unauthorized access. Admin role required.");
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Invalid request method. Only POST is accepted.");
    }
    
    // Clear any previous scheduler session variables
    unset($_SESSION['scheduler_log_file'], $_SESSION['scheduler_output_file'], $_SESSION['scheduler_status'], $_SESSION['scheduler_progress_percent']);

    $raw_input = file_get_contents('php://input');
    if ($raw_input === false) {
        throw new Exception("Could not read request body.");
    }
    
    $config_data_from_js = json_decode($raw_input, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid JSON data received. Error: " . json_last_error_msg());
    }
    
    // Validate essential semester_id
    if (!isset($config_data_from_js['semester_id_to_load']) || 
        empty(trim((string)$config_data_from_js['semester_id_to_load'])) ||
        !filter_var($config_data_from_js['semester_id_to_load'], FILTER_VALIDATE_INT) ||
        (int)$config_data_from_js['semester_id_to_load'] <= 0
    ) {
        throw new Exception("A valid Semester ID is required.");
    }
    $selected_semester_id = (int)$config_data_from_js['semester_id_to_load'];
    
    $python_executable_path_from_js = $config_data_from_js['python_executable_path'] ?? '';
    $python_executable_path = !empty(trim($python_executable_path_from_js)) ? sanitize_input($python_executable_path_from_js) : $default_python_executable;
    
    // Generate unique filenames for this run
    $run_id = date('Ymd_His') . '_' . substr(md5(uniqid((string)rand(), true)), 0, 8);
    $log_file_relative_to_python_algo_dir = 'output_data/logs/scheduler_progress_' . $run_id . '.log';
    $output_file_relative_to_python_algo_dir = 'output_data/final_schedule_output_' . $run_id . '.json';
    $input_file_relative_to_python_algo_dir = 'input_data/scheduler_input_config_' . $run_id . '.json';

    // Store file paths in session for the progress checker to use
    $_SESSION['scheduler_log_file'] = $log_file_relative_to_python_algo_dir;
    $_SESSION['scheduler_output_file'] = $output_file_relative_to_python_algo_dir;
    $_SESSION['scheduler_status'] = 'initiating_background'; 
    $_SESSION['scheduler_progress_percent'] = 1;

    // Prepare configuration for the Python script
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
        'progress_log_file_path_from_php' => $log_file_relative_to_python_algo_dir, // Path relative to python_algorithm dir
        'output_filename_override' => $output_file_relative_to_python_algo_dir    // Path relative to python_algorithm dir
    ];
    
    $input_json_content_for_python = json_encode($python_input_config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($input_json_content_for_python === false) {
        throw new Exception("Failed to encode Python input configuration to JSON.");
    }
    
    $python_algorithm_script_dir = realpath(__DIR__ . '/../python_algorithm');
    if (!$python_algorithm_script_dir) {
        throw new Exception("Python algorithm directory not found at " . __DIR__ . '/../python_algorithm');
    }
    
    $python_script_name_only = 'main_solver.py';
    $python_input_filename_only = basename($input_file_relative_to_python_algo_dir);

    $input_file_abs_path_for_python_script = $python_algorithm_script_dir . DIRECTORY_SEPARATOR . 'input_data' . DIRECTORY_SEPARATOR . $python_input_filename_only;
    if (file_put_contents($input_file_abs_path_for_python_script, $input_json_content_for_python) === false) {
        throw new Exception("Could not write Python input config file: " . htmlspecialchars($input_file_abs_path_for_python_script));
    }

    // Ensure log directory exists
    $log_file_absolute_path_for_python_script = $python_algorithm_script_dir . DIRECTORY_SEPARATOR . $log_file_relative_to_python_algo_dir;
    $log_dir_for_python_script = dirname($log_file_absolute_path_for_python_script);
    if (!is_dir($log_dir_for_python_script)) {
        if (!@mkdir($log_dir_for_python_script, 0775, true) && !is_dir($log_dir_for_python_script)) {
             throw new Exception("Could not create Python log directory: " . htmlspecialchars($log_dir_for_python_script));
        }
    }
    
    // Command execution for Windows (background, non-blocking)
    // Python script is expected to handle its own logging based on the config.
    $escaped_python_exe = escapeshellcmd($python_executable_path);
    $escaped_script_name = escapeshellarg($python_script_name_only); 
    $escaped_input_file_arg = escapeshellarg($python_input_filename_only); // Python script expects this as an argument

    // The command to run Python, Python will read the config file whose name is passed as argument.
    $command_to_run_python = $escaped_python_exe . ' ' . $escaped_script_name . ' ' . $escaped_input_file_arg;
    
    // Execute in the background using 'start /B'.
    // Note: Output redirection (>) for 'start /B' can be tricky. 
    // It's more reliable to have the Python script manage its own log file writing.
    // The ' > NUL 2>NUL ' part is to suppress any console window for the 'start' command itself.
    $full_command = 'start /B "" ' . $command_to_run_python . ' > NUL 2>NUL'; 

    $current_working_dir_php = getcwd(); 
    if ($current_working_dir_php === false) {
        throw new Exception("Could not get current working directory for PHP.");
    }
    if (!chdir($python_algorithm_script_dir)) { // Change CWD for popen to the Python script's directory
        throw new Exception("Could not change PHP working directory to Python script directory: " . htmlspecialchars($python_algorithm_script_dir));
    }

    error_log("run_scheduler_ajax.php: Executing Windows background command: " . $full_command . " (from CWD: " . $python_algorithm_script_dir . ")");
    
    $process_handle = popen($full_command, 'r'); // 'r' mode for popen with 'start /B'
    if ($process_handle === false) {
        chdir($current_working_dir_php); // Change back CWD even on failure
        throw new Exception("Failed to execute Python script using popen/start. Command: " . htmlspecialchars($full_command));
    }
    pclose($process_handle); // Close immediately for background execution

    chdir($current_working_dir_php); // Restore PHP's original CWD

    $_SESSION['scheduler_status'] = 'running_background'; 
    $_SESSION['scheduler_progress_percent'] = 2; // Small initial progress
    
    // Crucial: Close session to allow other scripts (like progress checker) to read updated session data.
    if (session_id() !== '') {
        session_write_close(); 
    }

    $response['status'] = 'background_initiated';
    $response['message'] = 'Scheduler process has been initiated in the background. Please monitor progress.';
    $response['log_file'] = $log_file_relative_to_python_algo_dir; // Relative path for client/progress checker
    $response['output_file'] = $output_file_relative_to_python_algo_dir;

} catch (Exception $e) {
    if (session_status() == PHP_SESSION_NONE) { // Ensure session is active to write status
        @session_start();
    }
    $_SESSION['scheduler_status'] = 'error_php_fatal'; // Set status on error
    if (session_id() !== '') {
        @session_write_close(); 
    }

    $response['status'] = 'error_php_fatal';
    $response['message'] = "PHP Error: " . $e->getMessage();
    error_log("run_scheduler_ajax.php FATAL EXCEPTION: " . $e->getMessage() . "\nFile: " . $e->getFile() . "\nLine: " . $e->getLine() . "\nTrace: " . $e->getTraceAsString());
}

// Final JSON output
if (!headers_sent()) {
    header('Content-Type: application/json');
}
echo json_encode($response);
exit;
?>