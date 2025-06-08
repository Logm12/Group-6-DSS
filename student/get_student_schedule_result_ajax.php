<?php
// htdocs/DSS/student/get_student_schedule_result_ajax.php

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');
require_once __DIR__ . '/../includes/functions.php';

$response = [
    'status' => 'error_initial_student_result_script',
    'message' => 'Result retrieval script for student schedule did not fully execute.',
    'data' => null
];

try {
    if (!is_logged_in() || get_current_user_role() !== 'student') {
        throw new Exception("Unauthorized access. Student login required.");
    }

    $output_filename_from_get_param = $_GET['file'] ?? null;
    $final_output_filename_relative = null;

    if (!empty($output_filename_from_get_param)) {
        $sanitized_output_filename = basename($output_filename_from_get_param);
        // Validate that filename matches expected pattern for student outputs
        if (!preg_match('/^student_schedule_output_[a-zA-Z0-9_]+\.json$/', $sanitized_output_filename)) {
             throw new Exception("Invalid output filename format received: " . htmlspecialchars($sanitized_output_filename));
        }
        // Construct relative path from python_algorithm directory
        $final_output_filename_relative = 'output_data' . DIRECTORY_SEPARATOR . $sanitized_output_filename;
    } else {
        // Fallback to session if GET param is missing (less ideal for statelessness but provides a backup)
        $output_filename_from_session = $_SESSION['student_scheduler_output_file'] ?? null;
        if (empty($output_filename_from_session)) {
            throw new Exception("Output filename reference not found in GET parameter or session.");
        }
        $final_output_filename_relative = $output_filename_from_session; // Assumes session stores it correctly with 'output_data/'
    }

    $python_algorithm_base_dir_abs = realpath(__DIR__ . '/../python_algorithm');
    if (!$python_algorithm_base_dir_abs) {
        throw new Exception("Server configuration error: Python algorithm base directory could not be resolved.");
    }
    
    $output_file_absolute_path = $python_algorithm_base_dir_abs . DIRECTORY_SEPARATOR . $final_output_filename_relative;

    if (file_exists($output_file_absolute_path)) {
        $json_content_from_output_file = @file_get_contents($output_file_absolute_path);
        if ($json_content_from_output_file === false) {
            throw new Exception("Could not read student schedule result file from: " . htmlspecialchars($output_file_absolute_path));
        }

        $decoded_python_output_data = json_decode($json_content_from_output_file, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $response['status'] = 'success_student_result_retrieved';
            $response['message'] = 'Student schedule results retrieved successfully.';
            $response['data'] = $decoded_python_output_data; // Entire Python output
        } else {
            throw new Exception("Failed to decode JSON from result file. Error: " . json_last_error_msg());
        }
    } else {
        throw new Exception("Student schedule result file not found at: " . htmlspecialchars($output_file_absolute_path));
    }

} catch (Exception $e) {
    $response['status'] = 'error_student_result_script_php';
    $response['message'] = "PHP Error: " . $e->getMessage();
    error_log("get_student_schedule_result_ajax.php EXCEPTION: " . $e->getMessage());
}

if (session_id() !== '') {
    session_write_close();
}

if (!headers_sent()) {
    header('Content-Type: application/json');
}
echo json_encode($response);
exit;
?>