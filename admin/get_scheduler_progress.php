<?php
// htdocs/DSS/admin/get_scheduler_progress.php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/functions.php';

if (!headers_sent()) {
    header('Content-Type: application/json');
}

$response = [
    'status' => 'error_unknown_progress_state',
    'message' => 'Could not determine scheduler progress state.',
    'progress_percent' => 0,
    'log_content' => '', // Will send the full log content
    'output_file' => null
];

try {
    if (!is_logged_in() || get_current_user_role() !== 'admin') {
        // Client-side should ideally prevent unauthorized polling, but good to have server check
        $response['status'] = 'error_auth';
        $response['message'] = "Unauthorized access for progress check.";
        // session_write_close(); // Close session before exiting
        // echo json_encode($response);
        // exit;
        throw new Exception("Unauthorized access for progress check."); // Throw to be caught by main catch
    }

    $current_status_from_session = $_SESSION['scheduler_status'] ?? 'idle';
    $log_file_relative_path = $_SESSION['scheduler_log_file'] ?? null;
    $output_file_relative_path = $_SESSION['scheduler_output_file'] ?? null;
    // Use the progress from session as a base, Python log might update it further
    $progress_percent_from_session = $_SESSION['scheduler_progress_percent'] ?? 0;

    $response['status'] = $current_status_from_session;
    $response['progress_percent'] = (int)$progress_percent_from_session;
    $response['output_file'] = $output_file_relative_path; // Pass this back for JS

    $python_script_has_definitely_finished = false; // Flag

    // Only read log if process is expected to be active or recently finished
    if (in_array($current_status_from_session, ['running_background', 'initiating_background', 'python_running']) ||
        ($current_status_from_session === 'completed_success' || $current_status_from_session === 'completed_error')) { // Also check log if recently completed to ensure latest state
        
        if ($log_file_relative_path) {
            $python_algo_base_dir = realpath(__DIR__ . '/../python_algorithm');
            if (!$python_algo_base_dir) {
                // This is a server config issue, should be logged
                error_log("get_scheduler_progress.php CRITICAL: Python algorithm base directory not found.");
                $response['status'] = 'error_file_ref'; // More specific error
                $response['message'] = 'Server configuration error: Python algorithm directory not found.';
                // No further log reading possible
            } else {
                $log_file_absolute_path = $python_algo_base_dir . DIRECTORY_SEPARATOR . $log_file_relative_path;

                if (file_exists($log_file_absolute_path)) {
                    $log_content_raw = @file_get_contents($log_file_absolute_path);
                    if ($log_content_raw !== false) {
                        $response['log_content'] = $log_content_raw; // Send the raw log (JS will handle htmlspecialchars if needed for display)

                        // --- Improved Progress Parsing & Completion Detection ---
                        $python_reported_progress = $progress_percent_from_session; // Start with session progress

                        // Look for the last "PYTHON_PROGRESS: Progress: X%" line
                        if (preg_match_all('/PYTHON_PROGRESS: Progress: (\d+)%/im', $log_content_raw, $matches)) {
                            $last_progress_match = end($matches[1]);
                            if (is_numeric($last_progress_match)) {
                                $python_reported_progress = max($python_reported_progress, (int)$last_progress_match);
                            }
                        }
                        $response['progress_percent'] = $python_reported_progress;

                        // Check for Python's explicit end signal
                        // Adjusted to look for the specific string from main_solver.py
                        if (stripos($log_content_raw, "--- PYTHON SCHEDULER SCRIPT EXECUTION FINISHED ---") !== false) {
                            $python_script_has_definitely_finished = true;
                            $response['progress_percent'] = 100; // If Python says it finished, progress is 100%
                        }
                        
                        // --- Determine Overall Status based on Python's completion and output file ---
                        if ($python_script_has_definitely_finished) {
                            if ($output_file_relative_path) {
                                $output_file_absolute_path = $python_algo_base_dir . DIRECTORY_SEPARATOR . $output_file_relative_path;
                                if (file_exists($output_file_absolute_path)) {
                                    $output_json_content = @file_get_contents($output_file_absolute_path);
                                    if ($output_json_content !== false) {
                                        $decoded_output = json_decode($output_json_content, true);
                                        if (json_last_error() === JSON_ERROR_NONE && isset($decoded_output['status'])) {
                                            if (stripos($decoded_output['status'], 'success') !== false) {
                                                $response['status'] = 'completed_success';
                                                $response['message'] = $decoded_output['message'] ?? 'Python process completed successfully.';
                                            } else { // Python reported an error in its own output file
                                                $response['status'] = 'completed_error';
                                                $response['message'] = $decoded_output['message'] ?? 'Python process completed with an internal error.';
                                            }
                                        } else {
                                            $response['status'] = 'completed_error';
                                            $response['message'] = 'Python output file is invalid or missing status key.';
                                        }
                                    } else {
                                        $response['status'] = 'completed_error';
                                        $response['message'] = 'Python process finished, but its output file is unreadable.';
                                    }
                                } else {
                                    // Python log says finished, but output file NOT found. This is a problem.
                                    $response['status'] = 'completed_error';
                                    $response['message'] = 'Python process finished (per log), but output file is missing.';
                                }
                            } else { // Python finished but no output file reference
                                $response['status'] = 'completed_error';
                                $response['message'] = 'Python process finished, but output file reference is missing in session.';
                            }
                        } elseif (in_array($current_status_from_session, ['running_background', 'initiating_background', 'python_running'])) {
                             // If not definitively finished by log marker, and session says it's running, keep it running.
                            $response['status'] = 'running_background'; // Or 'python_running' if more granular status is useful
                            $response['message'] = 'Processing... Current progress from log.';
                        }
                        // If current_status_from_session was already 'completed_success' or 'completed_error',
                        // and python_script_has_definitely_finished is false (e.g. log got truncated/corrupted),
                        // we should probably trust the session's completed status.
                        // The logic above handles re-evaluating if log shows completion.

                    } else { // Log file exists but couldn't be read
                        $response['log_content'] = "Error: Could not read content from progress log file at " . htmlspecialchars($log_file_absolute_path);
                        $response['message'] = "Reading progress log (file exists, but unreadable)...";
                        // Don't change status, let JS retry. If persists, JS might timeout.
                    }
                } else { // Log file path known, but file does not exist yet
                     if ($current_status_from_session === 'initiating_background' || $current_status_from_session === 'running_background') {
                        $response['log_content'] = "Progress log file not yet available. Expected at: " . htmlspecialchars($log_file_absolute_path ?? 'N/A');
                        $response['message'] = "Waiting for process to generate log file...";
                     } else {
                        // If status is e.g. completed_success but log file is missing, that's odd.
                        $response['log_content'] = "Progress log file missing despite process state: " . $current_status_from_session;
                     }
                }
            }
        } else { // No log file path in session - this is an issue if process was meant to start
            if ($current_status_from_session === 'initiating_background' || $current_status_from_session === 'running_background') {
                $response['status'] = 'error_file_ref';
                $response['message'] = 'Error: Log file reference is missing from session. Cannot track progress.';
            }
            // If status is idle, completed, etc., missing log file path is not an active error.
        }
    } else if ($current_status_from_session === 'idle') {
        $response['message'] = 'Scheduler is currently idle. No active process.';
        $response['progress_percent'] = 0; // Reset progress for idle state
    }
    // For already completed states from session, the initial response assignment handles it.

    // Update session with the latest determined status and progress
    // This ensures that even if JS misses an update, the next poll gets the latest state
    // (Only if status actually changed or progress increased)
    if (($_SESSION['scheduler_status'] ?? '') !== $response['status'] || ($_SESSION['scheduler_progress_percent'] ?? 0) < $response['progress_percent']) {
        $_SESSION['scheduler_status'] = $response['status'];
        $_SESSION['scheduler_progress_percent'] = $response['progress_percent'];
    }

} catch (Exception $e) {
    $response['status'] = 'error_progress_script';
    $response['message'] = "PHP Error in progress script: " . $e->getMessage();
    error_log("get_scheduler_progress.php EXCEPTION: " . $e->getMessage() . "\nFile: " . $e->getFile() . "\nLine: " . $e->getLine());
}

if (session_id() !== '') {
    session_write_close();
}

echo json_encode($response);
exit;
?>