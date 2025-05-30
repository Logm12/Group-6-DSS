<?php
// htdocs/DSS/admin/get_scheduler_progress.php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// QUAN TRỌNG: Require functions.php để có is_logged_in() và các hàm session khác
require_once __DIR__ . '/../includes/functions.php'; 

// Đặt header Content-Type lên đầu, nhưng sau khi include và session_start
// để đảm bảo không có output nào trước đó.
// Tuy nhiên, nếu có lỗi nghiêm trọng trong functions.php, header này có thể không được gửi.
// Cách tốt nhất là đảm bảo các file include không tự ý echo.
if (!headers_sent()) {
    header('Content-Type: application/json');
}


// Khởi tạo response mặc định phòng trường hợp lỗi sớm
$response = [
    'status' => 'error_unknown_progress_state',
    'message' => 'Could not determine scheduler progress.',
    'progress_percent' => 0,
    'log_content' => '',
    'output_file' => null
];

try {
    if (!is_logged_in() || get_current_user_role() !== 'admin') {
        // Ném exception thay vì echo trực tiếp để try-catch bên ngoài xử lý
        throw new Exception("Unauthorized access for progress check.");
    }

    $current_status_from_session = $_SESSION['scheduler_status'] ?? 'idle';
    $log_file_relative = $_SESSION['scheduler_log_file'] ?? null;
    $output_file_relative = $_SESSION['scheduler_output_file'] ?? null;
    $progress_percent_from_session = $_SESSION['scheduler_progress_percent'] ?? 0;

    // Cập nhật response dựa trên thông tin session
    $response['status'] = $current_status_from_session;
    $response['progress_percent'] = $progress_percent_from_session;
    $response['output_file'] = $output_file_relative;

    if ($current_status_from_session === 'running_background' || $current_status_from_session === 'initiating_background') {
        if ($log_file_relative) {
            $python_algo_dir = realpath(__DIR__ . '/../python_algorithm');
            if (!$python_algo_dir) { // Kiểm tra xem thư mục python có tồn tại không
                 throw new Exception("Python algorithm directory not found when checking progress.");
            }
            $log_file_absolute = $python_algo_dir . DIRECTORY_SEPARATOR . $log_file_relative;

            if (file_exists($log_file_absolute)) {
                $log_content_raw = @file_get_contents($log_file_absolute); 
                if ($log_content_raw !== false) {
                    $response['log_content'] = htmlspecialchars($log_content_raw); // Gửi toàn bộ log
                    
                    $parsed_percent = $progress_percent_from_session; 
                    if (preg_match_all('/PYTHON_PROGRESS: Progress: (\d+)% complete/i', $log_content_raw, $matches)) {
                        $last_match = end($matches[1]);
                        if (is_numeric($last_match)) {
                            $parsed_percent = (int)$last_match;
                        }
                    }

                    $is_python_script_finished = (stripos($log_content_raw, "END OF PYTHON SCHEDULER SCRIPT EXECUTION") !== false);

                    if ($parsed_percent >= 100 || $is_python_script_finished) {
                        $response['progress_percent'] = 100; // Luôn là 100 khi log báo xong

                        if ($output_file_relative) {
                             $output_file_absolute = $python_algo_dir . DIRECTORY_SEPARATOR . $output_file_relative;
                             if (file_exists($output_file_absolute)) {
                                $output_json_content = @file_get_contents($output_file_absolute);
                                if ($output_json_content) {
                                    $output_data_py = json_decode($output_json_content, true);
                                    if (isset($output_data_py['status'])) {
                                        if (strpos($output_data_py['status'], 'success') === 0) {
                                            $response['status'] = 'completed_success';
                                            $response['message'] = $output_data_py['message'] ?? 'Python process completed successfully.';
                                        } else {
                                            $response['status'] = 'completed_error';
                                            $response['message'] = $output_data_py['message'] ?? 'Python process completed with an internal error reported in output file.';
                                        }
                                    } else {
                                        $response['status'] = 'completed_error';
                                        $response['message'] = 'Python output file is missing status information.';
                                    }
                                } else {
                                     $response['status'] = 'completed_error';
                                     $response['message'] = 'Python process log indicates completion, but result file is unreadable.';
                                }
                             } else {
                                 $response['message'] = 'Python log indicates completion, but output file is not yet found by progress checker. Waiting...';
                                 // Giữ status là running_background để client tiếp tục poll, Python có thể đang ghi file output
                                 // Hoặc, nếu muốn coi đây là lỗi ngay:
                                 // $response['status'] = 'completed_error';
                                 // $response['message'] = 'Python log indicates completion, but output file not found.';
                             }
                        } else {
                            $response['status'] = 'completed_error'; 
                            $response['message'] = 'Python process seems complete but output file reference is missing in session.';
                        }
                    } else { // Chưa xong, vẫn đang chạy
                        $response['progress_percent'] = max($progress_percent_from_session, $parsed_percent); // Lấy % lớn hơn
                        $response['status'] = 'running_background'; // Đảm bảo status là đang chạy
                    }
                    // Cập nhật session với thông tin mới nhất
                    $_SESSION['scheduler_progress_percent'] = $response['progress_percent'];
                    $_SESSION['scheduler_status'] = $response['status'];
                } else {
                     $response['log_content'] = "Could not read progress log file (empty or read error): " . htmlspecialchars($log_file_absolute);
                     $response['message'] = "Reading progress log..."; // Vẫn có thể đang ghi
                }
            } else {
                $response['log_content'] = "Progress log file not yet available. Path: " . htmlspecialchars($log_file_absolute);
                $response['message'] = "Waiting for process to generate log...";
            }
        } else if ($current_status_from_session === 'running_background' || $current_status_from_session === 'initiating_background') {
             // Log file không được set trong session nhưng trạng thái là đang chạy
             $response['log_content'] = "Log file information not found in session but process is marked as running.";
             $response['message'] = "Process initiated, awaiting log file reference.";
        }
    } else if ($current_status_from_session === 'completed_success') {
        $response['message'] = 'Process completed successfully. Results are ready.';
        $response['progress_percent'] = 100;
    } else if ($current_status_from_session === 'completed_error' || $current_status_from_session === 'error_php_fatal' || $current_status_from_session === 'error_php_setup') {
        $response['message'] = 'Process finished with an error (status: ' . htmlspecialchars($current_status_from_session) . ').';
        $response['progress_percent'] = 100;
    } else if ($current_status_from_session === 'idle') {
        $response['message'] = 'Scheduler is idle.';
    }
    // Không cần else, vì $response đã được khởi tạo

} catch (Exception $e) {
    // Lỗi xảy ra trong chính get_scheduler_progress.php
    $response['status'] = 'error_progress_script';
    $response['message'] = "Error in progress script: " . $e->getMessage();
    $response['progress_percent'] = $progress_percent_from_session; // Giữ % cũ nếu có
    error_log("get_scheduler_progress.php EXCEPTION: " . $e->getMessage() . "\nFile: " . $e->getFile() . "\nLine: " . $e->getLine());
}

// Đóng session lại sau khi đã đọc và có thể đã cập nhật
if (session_id() !== '') { 
    session_write_close();
}

// Đảm bảo header Content-Type được set một lần nữa nếu chưa (mặc dù đã gọi ở trên)
if (!headers_sent()) {
    header('Content-Type: application/json');
}
echo json_encode($response);
exit;
?>