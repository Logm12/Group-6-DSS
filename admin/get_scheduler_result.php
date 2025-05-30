<?php
// htdocs/DSS/admin/get_scheduler_result.php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// QUAN TRỌNG: Include functions.php để có is_logged_in() và các hàm session khác
require_once __DIR__ . '/../includes/functions.php'; 

// Đặt header Content-Type lên đầu, sau khi session và include đã xong
if (!headers_sent()) {
    header('Content-Type: application/json');
}

// Khởi tạo response mặc định
$response = ['status' => 'error_initial_result', 'message' => 'Result script did not fully execute.'];

try {
    if (!is_logged_in() || get_current_user_role() !== 'admin') {
        throw new Exception("Unauthorized access for fetching results.");
    }

    $output_filename_relative = $_SESSION['scheduler_output_file'] ?? null;

    if (isset($_GET['file'])) {
        $output_filename_from_get = basename($_GET['file']); 
        if ($output_filename_relative && str_ends_with($output_filename_relative, $output_filename_from_get)) {
            // Hợp lệ, dùng từ session là chính xác nhất
        } else if (preg_match('/^final_schedule_output_[a-zA-Z0-9_]+\.json$/', $output_filename_from_get)) {
            // Nếu không có session, nhưng tên file từ GET hợp lệ
            $output_filename_relative = 'output_data/' . $output_filename_from_get; // Xây dựng lại đường dẫn tương đối
             $_SESSION['scheduler_output_file'] = $output_filename_relative; // Cập nhật lại session nếu có thể
        } else {
            $output_filename_relative = null; 
        }
    }

    if (!$output_filename_relative) {
        throw new Exception("Output file reference not found or invalid.");
    }

    $python_algo_dir = realpath(__DIR__ . '/../python_algorithm');
    if (!$python_algo_dir) {
        throw new Exception("Python algorithm directory not found when trying to get result.");
    }
    $file_path_absolute = $python_algo_dir . DIRECTORY_SEPARATOR . $output_filename_relative;

    if (file_exists($file_path_absolute)) {
        $json_content = @file_get_contents($file_path_absolute);
        if ($json_content === false) {
            throw new Exception("Could not read result file: " . htmlspecialchars($file_path_absolute));
        }
        $data = json_decode($json_content, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            // Data từ file Python đã có các key 'status', 'message', 'final_schedule', 'metrics'
            // Nên trả về toàn bộ data này cho client
            $response = ['status' => 'success', 'data' => $data]; 
        } else {
            throw new Exception("Failed to decode JSON from result file. Error: " . json_last_error_msg() . ". Raw content (first 500 chars): " . substr(htmlspecialchars($json_content), 0, 500));
        }
    } else {
        throw new Exception("Result file not found: " . htmlspecialchars($file_path_absolute));
    }

} catch (Exception $e) {
    $response['status'] = 'error_result_script';
    $response['message'] = "Error in get_scheduler_result.php: " . $e->getMessage();
    error_log("get_scheduler_result.php EXCEPTION: " . $e->getMessage());
}


if (session_id() !== '') { 
    session_write_close();
}

// Đảm bảo header được set (nếu chưa có lỗi header nào trước đó)
if (!headers_sent()) {
    header('Content-Type: application/json');
}
echo json_encode($response);
exit;
?>