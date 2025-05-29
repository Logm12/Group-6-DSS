<?php
// htdocs/DSS/includes/functions.php

// --- KHỞI TẠO SESSION NGAY ĐẦU FILE ---
// Quan trọng: Đảm bảo không có output nào (HTML, khoảng trắng, echo) trước dòng này.
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// --- BASE_URL DEFINITION ---
if (!defined('BASE_URL')) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'];
    // TUỲ CHỈNH $app_base_path CHO ĐÚNG VỚI CẤU TRÚC CỦA BẠN
    // Ví dụ: /DSS/ nếu thư mục gốc là htdocs/DSS
    $app_base_path = '/DSS/'; // Giả sử DSS là thư mục con trực tiếp của document root
    
    if ($app_base_path !== '/') { // Đảm bảo nó luôn có dấu / ở đầu và cuối nếu không phải là root
        $app_base_path = '/' . trim($app_base_path, '/') . '/';
    }
    define('BASE_URL', $protocol . $host . $app_base_path);
}
// Để debug: // echo "DEBUG: BASE_URL is defined as: " . BASE_URL; // die();

// --- UTILITY FUNCTIONS ---

/**
 * Redirects to a given path relative to the application's BASE_URL.
 * @param string $relative_app_path Path relative to BASE_URL (e.g., 'admin/index.php', 'login.php').
 */
function redirect(string $relative_app_path): void {
    $base = rtrim(BASE_URL, '/'); // Ví dụ: http://localhost/DSS
    $path = ltrim($relative_app_path, '/'); // Ví dụ: admin/index.php
    $target_url = $base . '/' . $path;

    // Further normalization to prevent multiple slashes after protocol
    // e.g. http://localhost//DSS -> http://localhost/DSS
    if (strpos($target_url, '://') !== false) {
        list($protocol_part, $path_part_after_protocol) = explode('://', $target_url, 2);
        // Replace multiple slashes with a single slash in the path part
        $path_part_after_protocol = preg_replace('#/{2,}#', '/', $path_part_after_protocol);
        $target_url = $protocol_part . '://' . $path_part_after_protocol;
    } else {
        // Should not happen if BASE_URL is correctly defined with protocol and host
        $target_url = preg_replace('#/{2,}#', '/', $target_url);
    }
    
    header("Location: " . $target_url);
    exit(); // Crucial to stop script execution after redirect
}

/**
 * Sanitizes a single string input data to prevent XSS.
 * If an array is passed, it logs a warning and returns an empty string.
 * @param mixed $data The data to sanitize.
 * @return string The sanitized string.
 */
function sanitize_input(mixed $data): string {
    if (is_array($data)) {
        error_log("Warning: sanitize_input() received an array, but was expected to process a string. Input: " . print_r($data, true) . ". Returning empty string for safety.");
        return ''; 
    }
    $data_string = (string) $data; // Cast to string to handle various input types
    $data_string = trim($data_string);
    // stripslashes should generally not be needed if magic_quotes_gpc is off (which it should be)
    // $data_string = stripslashes($data_string); 
    $data_string = htmlspecialchars($data_string, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return $data_string;
}

// --- USER SESSION FUNCTIONS ---

/**
 * Checks if the user is currently logged in by checking for 'user_id' in session.
 * @return bool True if logged in, False otherwise.
 */
function is_logged_in(): bool {
    return isset($_SESSION['user_id']);
}

/**
 * Gets the UserID of the currently logged-in user.
 * @return int|null UserID as integer if logged in and 'user_id' is numeric, null otherwise.
 */
function get_current_user_id(): ?int {
    return isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
}

/**
 * Gets the role (e.g., 'admin', 'instructor', 'student') of the currently logged-in user.
 * @return string|null Role string if logged in and 'role' is set, null otherwise.
 */
function get_current_user_role(): ?string {
    return isset($_SESSION['role']) && is_string($_SESSION['role']) ? $_SESSION['role'] : null;
}

/**
 * Gets the full name of the currently logged-in user.
 * @return string|null Full name string if logged in and 'fullname' is set, null otherwise.
 */
function get_current_user_fullname(): ?string {
    return isset($_SESSION['fullname']) && is_string($_SESSION['fullname']) ? $_SESSION['fullname'] : null;
}

/**
 * Gets the LinkedEntityID (e.g., StudentID or LecturerID as string) for the current user.
 * @return string|null LinkedEntityID string if set, null otherwise.
 */
function get_current_user_linked_entity_id(): ?string {
    return isset($_SESSION['linked_entity_id']) ? (string)$_SESSION['linked_entity_id'] : null;
}

/**
 * Enforces role-based access control for a page.
 * If the user is not logged in, redirects to the login page (saving the intended URL).
 * If the user is logged in but does not have an allowed role, displays a 403 Forbidden message.
 * @param array $allowed_roles Array of role strings that are allowed to access the page.
 * @param string $login_page_relative_to_base Path to the login page, relative to BASE_URL.
 * @param string $unauthorized_page_content HTML content for the 403 Forbidden page.
 */
function require_role(array $allowed_roles, string $login_page_relative_to_base = 'login.php', string $unauthorized_page_content = "<h1>403 Forbidden</h1><p>You do not have permission to access this page.</p>"): void {
    if (!is_logged_in()) {
        $request_uri = $_SERVER['REQUEST_URI']; 
        $base_url_path_component = parse_url(BASE_URL, PHP_URL_PATH); 
        
        $relative_redirect_path_to_app = $request_uri;
        if ($base_url_path_component && strpos($request_uri, $base_url_path_component) === 0) {
            $relative_redirect_path_to_app = substr($request_uri, strlen($base_url_path_component));
        }
        $_SESSION['redirect_url'] = ltrim($relative_redirect_path_to_app, '/');

        set_flash_message('auth_error', 'You need to login to access this page.', 'warning');
        redirect($login_page_relative_to_base); // This function calls exit()
    }
    
    $user_role = get_current_user_role();
    if ($user_role === null || !in_array($user_role, $allowed_roles, true)) { // Use strict comparison for in_array
        http_response_code(403); 
        // You might want to include a proper HTML structure for the error page
        // For example, by including a header and footer partial.
        echo "<!DOCTYPE html><html lang='en'><head><title>403 Forbidden</title></head><body>";
        echo $unauthorized_page_content;
        echo "</body></html>";
        exit();
    }
}

// --- FLASH MESSAGE FUNCTIONS ---
/**
 * Sets a flash message in the session.
 * @param string $name Key for the flash message.
 * @param string $message The message content.
 * @param string $type Bootstrap alert type (e.g., 'info', 'success', 'warning', 'danger').
 */
function set_flash_message(string $name, string $message, string $type = 'info'): void {
    if (!isset($_SESSION['flash_messages']) || !is_array($_SESSION['flash_messages'])) {
        $_SESSION['flash_messages'] = [];
    }
    $_SESSION['flash_messages'][$name] = ['message' => $message, 'type' => $type];
}

/**
 * Displays a specific flash message and removes it from the session.
 * @param string $name Key of the flash message to display.
 * @return string HTML for the flash message or an empty string if not found.
 */
function display_flash_message(string $name): string {
    if (isset($_SESSION['flash_messages'][$name])) {
        $flash = $_SESSION['flash_messages'][$name];
        unset($_SESSION['flash_messages'][$name]); // Remove after retrieval
        $alert_class = 'alert-' . htmlspecialchars(sanitize_input($flash['type'])); // Sanitize type
        return "<div class='alert {$alert_class} alert-dismissible fade show py-2 mb-3' role='alert'>" . 
               htmlspecialchars($flash['message']) . // Message already sanitized if set via form, but good practice
               "<button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button></div>";
    }
    return '';
}

/**
 * Displays all currently set flash messages and clears them from the session.
 * @return string HTML for all flash messages or an empty string.
 */
function display_all_flash_messages(): string {
    $output = '';
    if (!empty($_SESSION['flash_messages']) && is_array($_SESSION['flash_messages'])) {
        foreach ($_SESSION['flash_messages'] as $name => $flash) {
            if (is_array($flash) && isset($flash['message']) && isset($flash['type'])) {
                $alert_class = 'alert-' . htmlspecialchars(sanitize_input($flash['type']));
                $output .= "<div class='alert {$alert_class} alert-dismissible fade show py-2 mb-3' role='alert'>" . 
                           htmlspecialchars($flash['message']) . 
                           "<button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button></div>";
            }
        }
        unset($_SESSION['flash_messages']); // Clear all after displaying
    }
    return $output;
}


// --- FORMATTING FUNCTIONS ---
function format_datetime_for_display(?string $datetime_string, string $format = 'd/m/Y H:i:s'): string {
    if (empty($datetime_string) || $datetime_string === '0000-00-00 00:00:00' || $datetime_string === null) return 'N/A';
    try { $date = new DateTime($datetime_string); return $date->format($format); } 
    catch (Exception $e) { /* error_log("Error formatting datetime: {$datetime_string} - ".$e->getMessage()); */ return 'Invalid Date';}
}
function format_date_for_display(?string $date_string, string $format = 'd/m/Y'): string {
    if (empty($date_string) || $date_string === '0000-00-00' || $date_string === null) return 'N/A';
    try { $date = new DateTime($date_string); return $date->format($format); }
    catch (Exception $e) { /* error_log("Error formatting date: {$date_string} - ".$e->getMessage()); */ return 'Invalid Date'; }
}
function format_time_for_display(?string $time_string, string $format = 'H:i'): string {
    if (empty($time_string) || $time_string === null) return 'N/A';
    try {
        $date = DateTime::createFromFormat('H:i:s', $time_string) ?: DateTime::createFromFormat('H:i', $time_string);
        return $date ? $date->format($format) : 'Invalid Time';
    } catch (Exception $e) { /* error_log("Error formatting time: {$time_string} - ".$e->getMessage()); */ return 'Invalid Time'; }
}

// --- DATABASE RELATED UTILITIES ---
/**
 * Generates HTML <option> tags for a <select> element from a database table.
 * @param mysqli $conn Database connection object.
 * @param string $table_name Name of the table.
 * @param string $value_column Column to use for option values.
 * @param string|array $text_columns Column(s) to use for option display text. If array, concatenates with ' - '.
 * @param mixed $selected_value The value that should be pre-selected.
 * @param string $condition Optional SQL WHERE condition (without "WHERE" keyword).
 * @param string $order_by Optional SQL ORDER BY clause (without "ORDER BY" keyword).
 * @param string $default_option_text Text for the default unselected option (e.g., "-- Select --").
 * @return string HTML string of <option> tags.
 */
function generate_select_options(
    mysqli $conn, 
    string $table_name, 
    string $value_column, 
    mixed $text_columns, // Can be string or array
    mixed $selected_value = null, 
    string $condition = "", 
    string $order_by = "", 
    string $default_option_text = "-- Select Options --" // Changed default
): string {
    $options_html = '';
    if (!empty($default_option_text)) { 
        $options_html .= "<option value=''>" . htmlspecialchars($default_option_text) . "</option>"; 
    }

    // Basic sanitization for table and column names to prevent simple SQL injection via these parameters
    $safe_table_name = "`" . preg_replace('/[^a-zA-Z0-9_]/', '', $table_name) . "`";
    $safe_value_column = "`" . preg_replace('/[^a-zA-Z0-9_]/', '', $value_column) . "`";
    
    $safe_text_column_parts = [];
    if (is_array($text_columns)) {
        foreach ($text_columns as $tc) {
            $safe_text_column_parts[] = "`" . preg_replace('/[^a-zA-Z0-9_]/', '', (string)$tc) . "`";
        }
    } else {
        $safe_text_column_parts[] = "`" . preg_replace('/[^a-zA-Z0-9_]/', '', (string)$text_columns) . "`";
    }
    $display_text_sql = "CONCAT_WS(' - ', " . implode(", ", $safe_text_column_parts) . ")";
    
    $sql = "SELECT {$safe_value_column}, {$display_text_sql} AS DisplayText FROM {$safe_table_name}";
    
    // IMPORTANT: $condition and $order_by are NOT sanitized here. 
    // They MUST be constructed safely or use prepared statements if they ever involve user input.
    // For admin-defined conditions/order_by from code, this might be acceptable.
    if (!empty($condition)) { $sql .= " WHERE " . $condition; }
    if (!empty($order_by)) { $sql .= " ORDER BY " . $order_by; }

    $result = $conn->query($sql);
    if ($result) {
        if ($result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                // Use original column name for value, which might be different from safe_value_column if it had special chars
                $original_value_col_name = preg_replace('/[^a-zA-Z0-9_]/', '', $value_column); 
                $option_val = htmlspecialchars((string)$row[$original_value_col_name]);
                $option_text = htmlspecialchars((string)$row['DisplayText']);
                $selected_attr = ($selected_value !== null && (string)$selected_value === $option_val) ? 'selected' : '';
                $options_html .= "<option value='{$option_val}' {$selected_attr}>{$option_text}</option>";
            }
        }
        $result->free();
    } else { 
        error_log("Error in generate_select_options for table '{$table_name}': " . $conn->error . " | SQL Attempted: " . $sql);
    }
    return $options_html;
}

// --- APPLICATION SPECIFIC FUNCTIONS ---
function get_english_day_of_week(string $day_of_week_vietnamese): string { // Changed to get English name
    $days_map = [
        'Thứ Hai' => 'Monday', 'Thứ Ba' => 'Tuesday', 'Thứ Tư' => 'Wednesday',
        'Thứ Năm' => 'Thursday', 'Thứ Sáu' => 'Friday', 'Thứ Bảy' => 'Saturday', 'Chủ Nhật' => 'Sunday'
    ];
    return $days_map[$day_of_week_vietnamese] ?? $day_of_week_vietnamese; // Return original if not found
}

function get_time_slot_display_string(string $startTimeStr, string $endTimeStr): string { // Changed name for clarity
    if (empty($startTimeStr) || empty($endTimeStr)) return 'N/A';
    try {
        $start = new DateTime($startTimeStr);
        $end = new DateTime($endTimeStr);
        return $start->format('H:i') . ' - ' . $end->format('H:i');
    } catch (Exception $e) {
        return 'Invalid Time Range';
    }
}

// Placeholder/Example for other functions you mentioned were "giữ nguyên"
// You will need to implement their actual logic.
function check_class_overlap_detailed($class1_details, $class2_details): bool { 
    // Placeholder: Implement actual overlap checking logic
    // This would involve comparing $class1_details['timeslot_id_db'], $class1_details['lecturer_id_db'], $class1_details['classroom_id_db']
    // with $class2_details respectively.
    // For now, assume no overlap for simplicity in this placeholder.
    return false; 
} 
function count_distinct_days_in_schedule(array $schedule, mysqli $conn): int {
    if (empty($schedule)) return 0;
    $day_of_weeks = [];
    foreach($schedule as $event) {
        if(isset($event['timeslot_id_db'])) {
            $stmt = $conn->prepare("SELECT DayOfWeek FROM TimeSlots WHERE TimeSlotID = ?");
            if($stmt){
                $stmt->bind_param("i", $event['timeslot_id_db']);
                $stmt->execute();
                $res = $stmt->get_result();
                if($row = $res->fetch_assoc()){
                    $day_of_weeks[] = $row['DayOfWeek'];
                }
                $stmt->close();
            }
        }
    }
    return count(array_unique($day_of_weeks));
} 
function calculate_total_gap_time_by_dow(array $schedule, mysqli $conn): array { 
    // Placeholder: Implement logic to calculate gap times
    // This is a complex function that would need to:
    // 1. Group scheduled classes by day for each student/lecturer.
    // 2. Sort classes within each day by start time.
    // 3. Calculate time difference between end of one class and start of the next.
    // 4. Sum up these gaps.
    return ['Monday' => 0, 'Tuesday' => 0]; // Example structure
} 
function select_best_schedule_for_student(array $schedules): ?array { 
    // Placeholder: Implement logic to select the "best" schedule from a list
    // This could be based on fewest clashes, preferred times, etc.
    return $schedules[0] ?? null; 
} 
function generate_possible_schedules_for_student(
    mysqli $conn, /* Add $conn */
    array $courses_with_their_class_options, 
    array $current_schedule = [], 
    int $course_idx = 0, 
    array &$all_valid_schedules = [], 
    ?array $course_ids_to_process = null
): void { 
    // Placeholder: This is a very complex recursive/backtracking function.
    // Its full implementation is beyond a simple fix here.
    // It would iterate through course_ids_to_process.
    // For each course, iterate through its available class_options (sections).
    // Add a section to current_schedule.
    // Check for validity (no clashes with existing items in current_schedule).
    // If valid, recurse for the next course.
    // If all courses processed, add current_schedule to all_valid_schedules.
    // Backtrack by removing the last added section and trying another option.
    if ($course_ids_to_process === null) { // Initialize on first call
        $course_ids_to_process = array_keys($courses_with_their_class_options);
    }

    if ($course_idx == count($course_ids_to_process)) {
        if (!empty($current_schedule)) { // Only add non-empty valid schedules
            $all_valid_schedules[] = $current_schedule;
        }
        return;
    }

    $current_course_id = $course_ids_to_process[$course_idx];
    if (!isset($courses_with_their_class_options[$current_course_id])) {
        // Skip if this course has no options or data error
        generate_possible_schedules_for_student($conn, $courses_with_their_class_options, $current_schedule, $course_idx + 1, $all_valid_schedules, $course_ids_to_process);
        return;
    }
    
    // Try scheduling this course
    foreach ($courses_with_their_class_options[$current_course_id] as $class_option) {
        $temp_schedule = array_merge($current_schedule, [$class_option]); // Add current option
        // Simplified validity check: no direct time clashes for this student with other classes in temp_schedule
        // A real check_class_overlap_detailed would be more robust.
        $is_currently_valid = true;
        if (count($temp_schedule) > 1) {
            for ($i = 0; $i < count($temp_schedule) - 1; $i++) {
                if (check_class_overlap_detailed($temp_schedule[$i], $class_option)){ // Check against the newly added class
                    $is_currently_valid = false;
                    break;
                }
            }
        }

        if ($is_currently_valid) {
            generate_possible_schedules_for_student($conn, $courses_with_their_class_options, $temp_schedule, $course_idx + 1, $all_valid_schedules, $course_ids_to_process);
        }
    }
    // Option: also consider not taking this course (if courses are optional)
    // generate_possible_schedules_for_student($conn, $courses_with_their_class_options, $current_schedule, $course_idx + 1, $all_valid_schedules, $course_ids_to_process);
} 

function selected_if_match($current_value, $option_value): void { 
    if ((string)$current_value === (string)$option_value) { echo 'selected'; } 
}

// --- PYTHON SCRIPT EXECUTION FUNCTION --- 
function call_python_scheduler(string $python_executable, string $python_script_absolute_path, string $input_json_content, string $python_input_filename, string $python_output_filename, int $timeout_seconds = 360 ): array {
    $result = ['status' => 'error_php_setup', 'message' => 'PHP Error: Initial setup for Python execution failed.', 'data' => null, 'debug_stdout' => '', 'debug_stderr' => ''];
    if (empty(trim($python_executable))) { $result['message'] = "PHP Error: Python executable path is not configured."; return $result; }
    if (!file_exists($python_script_absolute_path) || !is_readable($python_script_absolute_path)) { $result['message'] = "PHP Error: Python script not found or not readable at: " . htmlspecialchars($python_script_absolute_path); return $result; }
    $python_script_dir = dirname($python_script_absolute_path);
    $python_input_dir_absolute = $python_script_dir . DIRECTORY_SEPARATOR . 'input_data'; $python_output_dir_absolute = $python_script_dir . DIRECTORY_SEPARATOR . 'output_data';
    foreach ([$python_input_dir_absolute, $python_output_dir_absolute] as $dir) {
        if (!is_dir($dir)) { if (!@mkdir($dir, 0775, true) && !is_dir($dir)) { $result['message'] = "PHP Error: Could not create Python I/O directory: " . htmlspecialchars($dir); return $result; } }
        if (!is_writable($dir)) { $result['message'] = "PHP Error: Python I/O directory is not writable: " . htmlspecialchars($dir); return $result; }
    }
    $input_file_for_python_abs = $python_input_dir_absolute . DIRECTORY_SEPARATOR . $python_input_filename; $output_file_from_python_abs = $python_output_dir_absolute . DIRECTORY_SEPARATOR . $python_output_filename;
    if (file_put_contents($input_file_for_python_abs, $input_json_content) === false) { $result['message'] = "PHP Error: Could not write to Python input file: " . htmlspecialchars($input_file_for_python_abs); return $result; }
    if (file_exists($output_file_from_python_abs)) { @unlink($output_file_from_python_abs); }
    $command = escapeshellcmd($python_executable) . ' ' . escapeshellarg($python_script_absolute_path);
    $descriptorspec = [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]]; $pipes = [];
    $process = @proc_open($command, $descriptorspec, $pipes, $python_script_dir, null);
    $stdout_content = ''; $stderr_content = ''; $start_exec_time = microtime(true);
    if (is_resource($process)) {
        fclose($pipes[0]); stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
        while (true) {
            $proc_status = proc_get_status($process);
            if (!$proc_status || !$proc_status['running']) { $stdout_content .= stream_get_contents($pipes[1]); $stderr_content .= stream_get_contents($pipes[2]); break; }
            if ($timeout_seconds > 0 && (microtime(true) - $start_exec_time) > $timeout_seconds) { proc_terminate($process, 9); $stderr_content .= "\nPHP Error: Python script execution timed out after {$timeout_seconds} seconds and was terminated."; $stdout_content .= stream_get_contents($pipes[1]); $stderr_content .= stream_get_contents($pipes[2]); break; }
            $read_stdout = fread($pipes[1], 8192); if ($read_stdout !== false && $read_stdout !== '') $stdout_content .= $read_stdout;
            $read_stderr = fread($pipes[2], 8192); if ($read_stderr !== false && $read_stderr !== '') $stderr_content .= $read_stderr;
            if (($read_stdout === '' || $read_stdout === false) && ($read_stderr === '' || $read_stderr === false) && $proc_status['running']) { usleep(100000); }
        }
        fclose($pipes[1]); fclose($pipes[2]); $exit_code = $proc_status['exitcode'] ?? proc_close($process);
        $result['debug_stdout'] = trim($stdout_content); $result['debug_stderr'] = trim($stderr_content);
        if ($exit_code === 0) {
            if (file_exists($output_file_from_python_abs)) {
                $json_output_content = @file_get_contents($output_file_from_python_abs);
                if ($json_output_content === false) { $result['message'] = "PHP Error: Python completed (exit 0), but could not read output file: " . htmlspecialchars($output_file_from_python_abs); $result['status'] = 'error_php_read_output'; }
                else {
                    $output_data_array = json_decode($json_output_content, true);
                    if (json_last_error() === JSON_ERROR_NONE && isset($output_data_array['status'])) {
                        $python_status = $output_data_array['status']; $result['status'] = (strpos($python_status, 'success') === 0) ? 'success' : 'error_python_logic';
                        $result['message'] = $output_data_array['message'] ?? 'No message from Python.'; $result['data'] = $output_data_array;
                    } else { $result['message'] = "PHP Error: Could not decode JSON from Python. JSON Error: " . json_last_error_msg(); $result['raw_output_from_python'] = substr(htmlspecialchars($json_output_content), 0, 1000); $result['status'] = 'error_php_json_decode'; }
                }
            } else { $result['message'] = "PHP Error: Python completed (exit 0), but output file not found: " . htmlspecialchars($output_file_from_python_abs); $result['status'] = 'error_python_no_output_file'; if(!empty($result['debug_stderr'])) { $result['message'] .= " PyStderr: " . $result['debug_stderr']; } elseif(!empty($result['debug_stdout'])) { $result['message'] .= " PyStdout: " . $result['debug_stdout']; } }
        } else { $result['message'] = "PHP Error: Python script exited with error code " . $exit_code . "."; $result['status'] = 'error_python_exit_code'; if (!empty($result['debug_stderr'])) { $result['message'] .= " PyStderr: " . $result['debug_stderr']; } elseif(!empty($result['debug_stdout'])) { $result['message'] .= " PyStdout: " . $result['debug_stdout']; } }
    } else { $result['message'] = "PHP Error: Could not execute Python script (proc_open failed). Check PHP config/permissions. Cmd: " . htmlspecialchars($command); $result['status'] = 'error_php_proc_open'; }
    return $result;
}
?>