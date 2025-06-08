<?php
// htdocs/DSS/student/get_student_schedule_progress_ajax.php

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');
require_once __DIR__ . '/../includes/functions.php';

$response = [
    'status' => 'error_unknown_student_progress_state',
    'message' => 'Could not determine student scheduler progress.',
    'progress_percent' => 0,
    'log_content' => '',
    'output_file' => null
];

try {
    if (!is_logged_in() || get_current_user_role() !== 'student') {
        throw new Exception("Unauthorized access for progress check.");
    }

    $current_scheduler_status_in_session = $_SESSION['student_scheduler_status'] ?? 'idle';
    $log_file_relative_to_py_dir = $_SESSION['student_scheduler_log_file'] ?? null;
    $output_file_relative_to_py_dir = $_SESSION['student_scheduler_output_file'] ?? null;
    $progress_percent_in_session = $_SESSION['student_scheduler_progress_percent'] ?? 0;

    $response['status'] = $current_scheduler_status_in_session;
    $response['progress_percent'] = (int)$progress_percent_in_session;
    $response['output_file'] = $output_file_relative_to_py_dir;

    $python_script_has_definitely_finished = false;

    if (in_array($current_scheduler_status_in_session, ['running_background', 'initiating_background', 'python_running'])) {
        if ($log_file_relative_to_py_dir) {
            $python_algorithm_base_dir_abs = realpath(__DIR__ . '/../python_algorithm');
            if (!$python_algorithm_base_dir_abs) {
                error_log("get_student_schedule_progress_ajax.php CRITICAL: Python algorithm base directory not found.");
                $response['status'] = 'error_file_ref';
                $response['message'] = 'Server configuration error: Python algorithm directory not found.';
            } else {
                $log_file_absolute_path = $python_algorithm_base_dir_abs . DIRECTORY_SEPARATOR . $log_file_relative_to_py_dir;

                if (file_exists($log_file_absolute_path)) {
                    $log_content_from_file_raw = @file_get_contents($log_file_absolute_path);
                    if ($log_content_from_file_raw !== false) {
                        $response['log_content'] = htmlspecialchars($log_content_from_file_raw);

                        $python_reported_progress = $progress_percent_in_session;
                        if (preg_match_all('/PYTHON_PROGRESS: Progress: (\d+)%/im', $log_content_from_file_raw, $matches_progress)) {
                            $last_progress_value_match = end($matches_progress[1]);
                            if (is_numeric($last_progress_value_match)) {
                                $python_reported_progress = max($python_reported_progress, (int)$last_progress_value_match);
                            }
                        }
                        $response['progress_percent'] = $python_reported_progress;

                        if (stripos($log_content_from_file_raw, "--- PYTHON SCHEDULER SCRIPT EXECUTION FINISHED ---") !== false) {
                            $python_script_has_definitely_finished = true;
                            $response['progress_percent'] = 100;
                        }

                        if ($python_script_has_definitely_finished) {
                            if ($output_file_relative_to_py_dir) {
                                $output_file_absolute_path = $python_algorithm_base_dir_abs . DIRECTORY_SEPARATOR . $output_file_relative_to_py_dir;
                                if (file_exists($output_file_absolute_path)) {
                                    $python_output_json_content = @file_get_contents($output_file_absolute_path);
                                    if ($python_output_json_content !== false) {
                                        $python_output_data_decoded = json_decode($python_output_json_content, true);
                                        if (json_last_error() === JSON_ERROR_NONE && isset($python_output_data_decoded['status'])) {
                                            if (stripos($python_output_data_decoded['status'], 'success') !== false) {
                                                $response['status'] = 'completed_student_schedule_success';
                                                $response['message'] = $python_output_data_decoded['message'] ?? 'Schedule options generated.';
                                            } else {
                                                $response['status'] = 'completed_student_schedule_error';
                                                $response['message'] = $python_output_data_decoded['message'] ?? 'Python error during schedule generation.';
                                            }
                                        } else {
                                            $response['status'] = 'completed_student_schedule_error';
                                            $response['message'] = 'Python output file invalid or missing status.';
                                        }
                                    } else {
                                        $response['status'] = 'completed_student_schedule_error';
                                        $response['message'] = 'Python finished, but output file unreadable.';
                                    }
                                } else {
                                    $response['message'] = 'Python log indicates completion, but result file not yet available. Re-checking...';
                                    $response['status'] = 'running_background'; // Keep polling
                                }
                            } else {
                                $response['status'] = 'completed_student_schedule_error';
                                $response['message'] = 'Python finished, but output file reference missing.';
                            }
                        } elseif (in_array($current_scheduler_status_in_session, ['running_background', 'initiating_background', 'python_running'])) {
                            $response['status'] = 'running_background';
                            $response['message'] = 'Processing student schedule...';
                        }
                        $_SESSION['student_scheduler_progress_percent'] = $response['progress_percent'];
                        $_SESSION['student_scheduler_status'] = $response['status'];
                    } else {
                        $response['log_content'] = "Error reading student progress log file: " . htmlspecialchars($log_file_absolute_path);
                    }
                } else {
                    if (in_array($current_scheduler_status_in_session, ['initiating_background', 'running_background'])) {
                        $response['log_content'] = "Student progress log not yet available at: " . htmlspecialchars($log_file_absolute_path ?? 'N/A');
                    }
                }
            }
        } elseif (in_array($current_scheduler_status_in_session, ['running_background', 'initiating_background'])) {
            $response['status'] = 'error_session_data_missing';
            $response['message'] = 'Log file reference missing in session, though process is marked as running.';
        }
    } elseif ($current_scheduler_status_in_session === 'completed_student_schedule_success') {
        $response['message'] = 'Student schedule process previously completed successfully.';
        $response['progress_percent'] = 100;
    } elseif (in_array($current_scheduler_status_in_session, ['completed_student_schedule_error', 'error_php_processing_request'])) {
        $response['message'] = 'Student schedule process previously finished with an error (status: ' . htmlspecialchars($current_scheduler_status_in_session) . ').';
        $response['progress_percent'] = 100;
    } elseif ($current_scheduler_status_in_session === 'idle') {
        $response['message'] = 'Student scheduler is idle.';
        $response['progress_percent'] = 0;
    }

} catch (Exception $e) {
    $response['status'] = 'error_student_progress_script_php';
    $response['message'] = "PHP Error: " . $e->getMessage();
    error_log("get_student_schedule_progress_ajax.php EXCEPTION: " . $e->getMessage());
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