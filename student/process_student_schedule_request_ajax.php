<?php
// htdocs/DSS/student/process_student_schedule_request_ajax.php

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db_connect.php';

// Default Python execution parameters for student requests
$default_python_executable_student = 'python';
$default_cp_time_limit_student = 20.0;
$default_ga_pop_size_student = 30;
$default_ga_generations_student = 50;
// Other GA params can use system defaults or be adjusted

$response = [
    'status' => 'error_php_initial_setup',
    'message' => 'PHP script setup error before processing student request.',
    'log_file' => null,
    'output_file' => null
];

try {
    if (!is_logged_in() || get_current_user_role() !== 'student') {
        throw new Exception("Unauthorized access. You must be logged in as a student.");
    }
    $current_student_id = get_current_user_linked_entity_id();
    if (!$current_student_id) {
        throw new Exception("Student ID not found in session. Please re-login.");
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Invalid request method. This endpoint only accepts POST requests.");
    }

    unset(
        $_SESSION['student_scheduler_run_id'],
        $_SESSION['student_scheduler_log_file'],
        $_SESSION['student_scheduler_output_file'],
        $_SESSION['student_scheduler_status'],
        $_SESSION['student_scheduler_progress_percent']
    );

    $raw_input_data = file_get_contents('php://input');
    if ($raw_input_data === false) {
        throw new Exception("Could not read the request body from client.");
    }

    $client_request_data = json_decode($raw_input_data, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid JSON data received from client. Error: " . json_last_error_msg());
    }

    $selected_semester_id_from_client = $client_request_data['semester_id_to_load'] ?? null;
    if (empty($selected_semester_id_from_client) || !filter_var($selected_semester_id_from_client, FILTER_VALIDATE_INT) || (int)$selected_semester_id_from_client <= 0) {
        throw new Exception("A valid Semester ID is required to process the schedule request.");
    }
    $selected_semester_id = (int)$selected_semester_id_from_client;

    $selected_course_ids_from_client = $client_request_data['selected_course_ids'] ?? [];
    if (empty($selected_course_ids_from_client) || !is_array($selected_course_ids_from_client)) {
        throw new Exception("At least one course must be selected.");
    }
    $sanitized_selected_course_ids = array_map('sanitize_input', $selected_course_ids_from_client);
    $sanitized_selected_course_ids = array_filter($sanitized_selected_course_ids);
    if (empty($sanitized_selected_course_ids)) {
        throw new Exception("No valid courses selected after sanitization.");
    }

    $student_preferences_from_client = $client_request_data['preferences'] ?? [];

    $student_run_id = 'student_' . $current_student_id . '_' . date('YmdHis') . '_' . substr(uniqid(), -5);
    $log_file_relative_to_py_dir = 'output_data/logs/student_schedule_progress_' . $student_run_id . '.log';
    $output_file_relative_to_py_dir = 'output_data/student_schedule_output_' . $student_run_id . '.json';
    $input_file_relative_to_py_dir = 'input_data/student_schedule_input_' . $student_run_id . '.json';

    $_SESSION['student_scheduler_run_id'] = $student_run_id;
    $_SESSION['student_scheduler_log_file'] = $log_file_relative_to_py_dir;
    $_SESSION['student_scheduler_output_file'] = $output_file_relative_to_py_dir;
    $_SESSION['student_scheduler_status'] = 'initiating_background';
    $_SESSION['student_scheduler_progress_percent'] = 1;

    $python_input_config_for_student = [
        'run_type' => 'student_schedule_request',
        'student_id' => $current_student_id,
        'semester_id_to_load' => $selected_semester_id,
        'requested_course_ids' => $sanitized_selected_course_ids,
        'student_preferences' => $student_preferences_from_client,
        'cp_time_limit_seconds' => $default_cp_time_limit_student,
        'ga_population_size' => $default_ga_pop_size_student,
        'ga_generations' => $default_ga_generations_student,
        'ga_crossover_rate' => (float)($client_request_data['ga_crossover_rate'] ?? 0.8),
        'ga_mutation_rate' => (float)($client_request_data['ga_mutation_rate'] ?? 0.2),
        'ga_tournament_size' => (int)($client_request_data['ga_tournament_size'] ?? 5),
        'ga_allow_hard_constraint_violations' => false,
        'priority_student_clash' => 'very_high',
        'priority_student_preferences' => sanitize_input($client_request_data['priority_student_preferences'] ?? 'high'),
        'priority_lecturer_load_break' => 'medium',
        'priority_classroom_util' => 'low',
        'progress_log_file_path_from_php' => $log_file_relative_to_py_dir,
        'output_filename_override' => $output_file_relative_to_py_dir
    ];

    $input_json_for_python = json_encode($python_input_config_for_student, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($input_json_for_python === false) {
        throw new Exception("Failed to encode Python input configuration to JSON. Error: " . json_last_error_msg());
    }

    $python_algorithm_base_dir_abs = realpath(__DIR__ . '/../python_algorithm');
    if (!$python_algorithm_base_dir_abs) {
        throw new Exception("Python algorithm base directory not found at expected location.");
    }

    $python_input_filename_only = basename($input_file_relative_to_py_dir);
    $input_file_abs_path = $python_algorithm_base_dir_abs . DIRECTORY_SEPARATOR . 'input_data' . DIRECTORY_SEPARATOR . $python_input_filename_only;

    if (file_put_contents($input_file_abs_path, $input_json_for_python) === false) {
        throw new Exception("Could not write to Python input config file: " . htmlspecialchars($input_file_abs_path));
    }

    $log_file_abs_path = $python_algorithm_base_dir_abs . DIRECTORY_SEPARATOR . $log_file_relative_to_py_dir;
    $log_dir_for_py = dirname($log_file_abs_path);
    if (!is_dir($log_dir_for_py)) {
        if (!@mkdir($log_dir_for_py, 0775, true) && !is_dir($log_dir_for_py)) {
             throw new Exception("Could not create Python log directory: " . htmlspecialchars($log_dir_for_py));
        }
    }

    $python_exe_to_use = $client_request_data['python_executable_path'] ?? $default_python_executable_student;
    $python_exe_to_use = !empty(trim($python_exe_to_use)) ? sanitize_input($python_exe_to_use) : $default_python_executable_student;

    $escaped_python_exe_cmd = escapeshellcmd($python_exe_to_use);
    $main_solver_script_name = 'main_solver.py';
    $escaped_main_solver_cmd = escapeshellarg($main_solver_script_name);
    $escaped_python_input_file_arg = escapeshellarg($python_input_filename_only);

    $python_command_string = $escaped_python_exe_cmd . ' ' . $escaped_main_solver_cmd . ' ' . $escaped_python_input_file_arg;
    $full_execution_command = 'start /B "" ' . $python_command_string . ' > NUL 2>NUL';

    $php_current_working_dir = getcwd();
    if ($php_current_working_dir === false) throw new Exception("PHP could not get its current working directory.");
    if (!chdir($python_algorithm_base_dir_abs)) {
        throw new Exception("PHP could not change working directory to Python script's directory.");
    }

    error_log("ProcessStudentScheduleRequest: Executing background command: " . $full_execution_command);

    $process_handle_py = popen($full_execution_command, 'r');
    if ($process_handle_py === false) {
        chdir($php_current_working_dir);
        throw new Exception("Failed to execute Python script via popen/start. Command: " . htmlspecialchars($full_execution_command));
    }
    pclose($process_handle_py);

    chdir($php_current_working_dir);

    $_SESSION['student_scheduler_status'] = 'running_background';
    $_SESSION['student_scheduler_progress_percent'] = 5;

    if (session_id() !== '') {
        session_write_close();
    }

    $response['status'] = 'background_initiated_student';
    $response['message'] = 'Your schedule request is being processed. Please wait for progress updates.';
    $response['log_file'] = $log_file_relative_to_py_dir;
    $response['output_file'] = $output_file_relative_to_py_dir;

} catch (Exception $e) {
    if (session_status() == PHP_SESSION_NONE) { @session_start(); }
    $_SESSION['student_scheduler_status'] = 'error_php_processing_request';
    if (session_id() !== '') { @session_write_close(); }

    $response['status'] = 'error_php_processing_request';
    $response['message'] = "PHP Error: " . $e->getMessage();
    error_log("process_student_schedule_request_ajax.php EXCEPTION: " . $e->getMessage() .
              "\nFile: " . $e->getFile() . "\nLine: " . $e->getLine());
}

if (!headers_sent()) {
    header('Content-Type: application/json');
}
echo json_encode($response);
exit;
?>