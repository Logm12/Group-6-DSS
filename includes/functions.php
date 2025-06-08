<?php

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!defined('BASE_URL')) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'];
    $app_base_path = '/DSS/'; 
    
    if ($app_base_path !== '/') { 
        $app_base_path = '/' . trim($app_base_path, '/') . '/';
    }
    define('BASE_URL', $protocol . $host . $app_base_path);
}

function redirect(string $relative_app_path): void {
    $base_url = rtrim(BASE_URL, '/');
    $path_segment = ltrim($relative_app_path, '/'); 
    $target_url = $base_url . '/' . $path_segment;

    if (strpos($target_url, '://') !== false) {
        list($protocol_part, $path_part_after_protocol) = explode('://', $target_url, 2);
        $path_part_after_protocol = preg_replace('#/{2,}#', '/', $path_part_after_protocol);
        $target_url = $protocol_part . '://' . $path_part_after_protocol;
    } else {
        $target_url = preg_replace('#/{2,}#', '/', $target_url);
    }
    
    header("Location: " . $target_url);
    exit(); 
}

function sanitize_input(mixed $data): string {
    if (is_array($data)) {
        error_log("Warning: sanitize_input() received an array. Input: " . print_r($data, true) . ". Returning empty string.");
        return ''; 
    }
    $data_string = (string) $data;
    $data_string = trim($data_string);
    $data_string = htmlspecialchars($data_string, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return $data_string;
}

function is_logged_in(): bool {
    return isset($_SESSION['user_id']);
}

function get_current_user_id(): ?int {
    return isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
}

function get_current_user_role(): ?string {
    return isset($_SESSION['role']) && is_string($_SESSION['role']) ? $_SESSION['role'] : null;
}

function get_current_user_fullname(): ?string {
    return isset($_SESSION['fullname']) && is_string($_SESSION['fullname']) ? $_SESSION['fullname'] : null;
}

function get_current_user_linked_entity_id(): ?string {
    return isset($_SESSION['linked_entity_id']) ? (string)$_SESSION['linked_entity_id'] : null;
}

function require_role(array $allowed_roles, string $login_page_relative_to_base = 'login.php', string $unauthorized_page_content = "<!DOCTYPE html><html lang='en'><head><meta charset='UTF-8'><meta name='viewport' content='width=device-width, initial-scale=1.0'><title>403 Forbidden</title><style>body{font-family:sans-serif;display:flex;justify-content:center;align-items:center;min-height:80vh;text-align:center;background-color:#f8f9fa;color:#6c757d;}.container{padding:20px;border:1px solid #dee2e6;border-radius:5px;background-color:white;}h1{color:#dc3545;}</style></head><body><div class='container'><h1>403 Forbidden</h1><p>You do not have permission to access this page.</p><p><a href='" . BASE_URL . "'>Go to Homepage</a></p></div></body></html>"): void {
    if (!is_logged_in()) {
        $request_uri = $_SERVER['REQUEST_URI']; 
        $base_url_path_component = parse_url(BASE_URL, PHP_URL_PATH) ?: '/'; 
        
        $relative_redirect_path_to_app = $request_uri;
        $base_url_path_component_for_strip = rtrim($base_url_path_component, '/') . '/';
        if ($base_url_path_component_for_strip !== '/' && strpos($request_uri, $base_url_path_component_for_strip) === 0) {
            $relative_redirect_path_to_app = substr($request_uri, strlen($base_url_path_component_for_strip));
        }
        $_SESSION['redirect_url'] = ltrim($relative_redirect_path_to_app, '/');

        set_flash_message('auth_error', 'You need to login to access this page.', 'warning');
        redirect($login_page_relative_to_base);
    }
    
    $user_role = get_current_user_role();
    if ($user_role === null || !in_array($user_role, $allowed_roles, true)) {
        http_response_code(403); 
        echo $unauthorized_page_content;
        exit();
    }
}

function set_flash_message(string $name, string $message, string $type = 'info'): void {
    if (!isset($_SESSION['flash_messages']) || !is_array($_SESSION['flash_messages'])) {
        $_SESSION['flash_messages'] = [];
    }
    $_SESSION['flash_messages'][$name] = ['message' => $message, 'type' => $type];
}

function display_flash_message(string $name): string {
    if (isset($_SESSION['flash_messages'][$name])) {
        $flash = $_SESSION['flash_messages'][$name];
        unset($_SESSION['flash_messages'][$name]); 
        $alert_class = 'alert-' . htmlspecialchars(sanitize_input($flash['type'])); 
        return "<div class='alert {$alert_class} alert-dismissible fade show py-2 mb-3' role='alert'>" . 
               htmlspecialchars($flash['message']) . 
               "<button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button></div>";
    }
    return '';
}

function display_all_flash_messages(): string {
    $output = '';
    if (!empty($_SESSION['flash_messages']) && is_array($_SESSION['flash_messages'])) {
        foreach (array_keys($_SESSION['flash_messages']) as $name) {
            $output .= display_flash_message($name);
        }
    }
    return $output;
}


function format_datetime_for_display(?string $datetime_string, string $format = 'd/m/Y H:i:s'): string {
    if (empty($datetime_string) || $datetime_string === '0000-00-00 00:00:00' || $datetime_string === null) return 'N/A';
    try { $date = new DateTime($datetime_string); return $date->format($format); } 
    catch (Exception $e) { return 'Invalid Date';}
}

function format_date_for_display(?string $date_string, string $format = 'd/m/Y'): string {
    if (empty($date_string) || $date_string === '0000-00-00' || $date_string === null) return 'N/A';
    try { $date = new DateTime($date_string); return $date->format($format); }
    catch (Exception $e) { return 'Invalid Date'; }
}

function format_time_for_display(?string $time_string, string $format = 'H:i'): string {
    if (empty($time_string) || $time_string === null) return 'N/A';
    try {
        $date = DateTime::createFromFormat('H:i:s', $time_string) ?: DateTime::createFromFormat('H:i', $time_string);
        return $date ? $date->format($format) : 'Invalid Time';
    } catch (Exception $e) { return 'Invalid Time'; }
}

function generate_select_options(
    mysqli $conn, 
    string $table_name, 
    string $value_column, 
    mixed $text_columns, 
    mixed $selected_value = null, 
    string $condition = "", 
    string $order_by = "", 
    string $default_option_text = "-- Select Options --"
): string {
    $options_html = '';
    if (!empty($default_option_text)) { 
        $options_html .= "<option value=''>" . htmlspecialchars($default_option_text) . "</option>"; 
    }

    $safe_table_name = "`" . str_replace("`", "", $table_name) . "`";
    $safe_value_column = "`" . str_replace("`", "", $value_column) . "`";
    
    $safe_text_column_parts = [];
    if (is_array($text_columns)) {
        foreach ($text_columns as $tc) {
            $safe_text_column_parts[] = "`" . str_replace("`", "", (string)$tc) . "`";
        }
    } else {
        $safe_text_column_parts[] = "`" . str_replace("`", "", (string)$text_columns) . "`";
    }
    $display_text_sql = "CONCAT_WS(' - ', " . implode(", ", $safe_text_column_parts) . ") AS DisplayText";
    
    $sql = "SELECT {$safe_value_column}, {$display_text_sql} FROM {$safe_table_name}";
    
    if (!empty($condition)) { $sql .= " WHERE " . $condition; } 
    if (!empty($order_by)) { $sql .= " ORDER BY " . $order_by; }

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("Error preparing statement in generate_select_options for table '{$table_name}': " . $conn->error . " | SQL: " . $sql);
        return $options_html . "<option value=''>Error loading options</option>";
    }

    if ($stmt->execute()) {
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $option_val_raw = $row[str_replace("`", "", $value_column)];
                $option_val_escaped = htmlspecialchars((string)$option_val_raw, ENT_QUOTES, 'UTF-8');
                $option_text_escaped = htmlspecialchars((string)$row['DisplayText'], ENT_QUOTES, 'UTF-8');
                $selected_attr = ($selected_value !== null && (string)$selected_value === (string)$option_val_raw) ? 'selected' : '';
                $options_html .= "<option value='{$option_val_escaped}' {$selected_attr}>{$option_text_escaped}</option>";
            }
        }
        $result->free();
    } else {
         error_log("Error executing statement in generate_select_options for table '{$table_name}': " . $stmt->error . " | SQL: " . $sql);
    }
    $stmt->close();
    return $options_html;
}

function get_english_day_of_week(string $day_of_week_input): string {
    $days_map = [
        'Thứ Hai' => 'Monday', 'Thứ Ba' => 'Tuesday', 'Thứ Tư' => 'Wednesday',
        'Thứ Năm' => 'Thursday', 'Thứ Sáu' => 'Friday', 'Thứ Bảy' => 'Saturday', 'Chủ Nhật' => 'Sunday',
        'Monday' => 'Monday', 'Tuesday' => 'Tuesday', 'Wednesday' => 'Wednesday',
        'Thursday' => 'Thursday', 'Friday' => 'Friday', 'Saturday' => 'Saturday', 'Sunday' => 'Sunday',
    ];
    return $days_map[$day_of_week_input] ?? $day_of_week_input;
}

function get_time_slot_display_string(string $startTimeStr, string $endTimeStr): string { 
    if (empty($startTimeStr) || empty($endTimeStr)) return 'N/A';
    try {
        $start = new DateTime($startTimeStr);
        $end = new DateTime($endTimeStr);
        return $start->format('H:i') . ' - ' . $end->format('H:i');
    } catch (Exception $e) {
        return 'Invalid Time Range';
    }
}

function check_class_overlap_detailed(array $class1_details, array $class2_details): bool {
    if (isset($class1_details['timeslot_id_db']) && isset($class2_details['timeslot_id_db'])) {
        return $class1_details['timeslot_id_db'] === $class2_details['timeslot_id_db'];
    }
    error_log("Warning: check_class_overlap_detailed called with missing 'timeslot_id_db'. Class1: " . print_r($class1_details, true) . " Class2: " . print_r($class2_details, true));
    return false; 
} 

function count_distinct_days_in_schedule(array $schedule, mysqli $conn): int {
    if (empty($schedule)) return 0;
    
    $timeslot_ids = [];
    foreach ($schedule as $event) {
        if (isset($event['timeslot_id_db']) && is_numeric($event['timeslot_id_db'])) {
            $timeslot_ids[] = (int)$event['timeslot_id_db'];
        }
    }

    if (empty($timeslot_ids)) return 0;

    $unique_timeslot_ids = array_unique($timeslot_ids);
    $placeholders = implode(',', array_fill(0, count($unique_timeslot_ids), '?'));
    $types = str_repeat('i', count($unique_timeslot_ids));

    $sql = "SELECT DISTINCT DayOfWeek FROM TimeSlots WHERE TimeSlotID IN ($placeholders)";
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        error_log("count_distinct_days_in_schedule: Prepare failed: " . $conn->error . " | SQL: " . $sql);
        return 0;
    }

    $stmt->bind_param($types, ...$unique_timeslot_ids);
    if (!$stmt->execute()) {
        error_log("count_distinct_days_in_schedule: Execute failed: " . $stmt->error);
        $stmt->close();
        return 0;
    }
    $result = $stmt->get_result();
    
    $distinct_days = [];
    while ($row = $result->fetch_assoc()) {
        $distinct_days[] = $row['DayOfWeek'];
    }
    
    $stmt->close();
    return count(array_unique($distinct_days));
} 

function calculate_total_gap_time_by_dow(array $schedule, mysqli $conn): array { 
    if (empty($schedule)) return [];

    $timeslot_ids = [];
    foreach ($schedule as $event) {
        if (isset($event['timeslot_id_db']) && is_numeric($event['timeslot_id_db'])) {
            $timeslot_ids[] = (int)$event['timeslot_id_db'];
        }
    }
    if (empty($timeslot_ids)) return [];

    $unique_timeslot_ids = array_unique($timeslot_ids);
    $placeholders = implode(',', array_fill(0, count($unique_timeslot_ids), '?'));
    $types = str_repeat('i', count($unique_timeslot_ids));

    $sql = "SELECT TimeSlotID, DayOfWeek, StartTime, EndTime FROM TimeSlots WHERE TimeSlotID IN ($placeholders)";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("calculate_total_gap_time_by_dow: Prepare failed: " . $conn->error . " | SQL: " . $sql);
        return [];
    }
    $stmt->bind_param($types, ...$unique_timeslot_ids);
    if (!$stmt->execute()) {
        error_log("calculate_total_gap_time_by_dow: Execute failed: " . $stmt->error);
        $stmt->close();
        return [];
    }
    $result = $stmt->get_result();
    
    $slots_data = [];
    while ($row = $result->fetch_assoc()) {
        try {
            $slots_data[$row['TimeSlotID']] = [
                'DayOfWeek' => $row['DayOfWeek'],
                'StartTime' => new DateTime($row['StartTime']),
                'EndTime'   => new DateTime($row['EndTime'])
            ];
        } catch (Exception $e) {
            error_log("Error parsing time in calculate_total_gap_time_by_dow for TimeSlotID " . $row['TimeSlotID'] . ": " . $e->getMessage());
        }
    }
    $stmt->close();

    $events_by_day = [];
    foreach ($schedule as $event) {
        if (isset($event['timeslot_id_db']) && isset($slots_data[$event['timeslot_id_db']])) {
            $slot_info = $slots_data[$event['timeslot_id_db']];
            $events_by_day[$slot_info['DayOfWeek']][] = $slot_info;
        }
    }

    $gap_times_by_day = [];
    foreach ($events_by_day as $day => $day_events) {
        if (count($day_events) < 2) {
            $gap_times_by_day[$day] = 0;
            continue;
        }

        usort($day_events, function ($a, $b) {
            return $a['StartTime'] <=> $b['StartTime'];
        });

        $total_daily_gap = 0;
        for ($i = 0; $i < count($day_events) - 1; $i++) {
            $event1_end = $day_events[$i]['EndTime'];
            $event2_start = $day_events[$i+1]['StartTime'];
            if ($event2_start > $event1_end) { 
                $interval = $event1_end->diff($event2_start);
                $gap_minutes = ($interval->h * 60) + $interval->i;
                $total_daily_gap += $gap_minutes;
            }
        }
        $gap_times_by_day[$day] = $total_daily_gap;
    }
    return $gap_times_by_day;
} 

function select_best_schedule_for_student(array $schedules, mysqli $conn): ?array { 
    if (empty($schedules)) return null;

    $best_schedule = null;
    $min_distinct_days = PHP_INT_MAX;
    $min_total_gap_at_min_days = PHP_INT_MAX;

    foreach ($schedules as $current_schedule_candidate) {
        if (empty($current_schedule_candidate) || !is_array($current_schedule_candidate)) continue;

        $distinct_days = count_distinct_days_in_schedule($current_schedule_candidate, $conn);
        $gap_times_by_dow = calculate_total_gap_time_by_dow($current_schedule_candidate, $conn);
        $total_gap_time = array_sum($gap_times_by_dow);

        if ($distinct_days < $min_distinct_days) {
            $min_distinct_days = $distinct_days;
            $min_total_gap_at_min_days = $total_gap_time;
            $best_schedule = $current_schedule_candidate;
        } elseif ($distinct_days === $min_distinct_days) {
            if ($total_gap_time < $min_total_gap_at_min_days) {
                $min_total_gap_at_min_days = $total_gap_time;
                $best_schedule = $current_schedule_candidate;
            }
        }
    }
    return $best_schedule; 
} 

function generate_possible_schedules_for_student(
    mysqli $conn, 
    array $courses_with_class_options, 
    array $current_schedule_build = [], 
    int $course_idx = 0, 
    array &$all_valid_schedules_ref = [], 
    ?array $course_ids_to_process_list = null
): void { 
    if ($course_ids_to_process_list === null) { 
        $course_ids_to_process_list = array_keys($courses_with_class_options);
    }

    if ($course_idx == count($course_ids_to_process_list)) {
        if (!empty($current_schedule_build)) {
            $is_schedule_internally_valid = true;
            for ($i = 0; $i < count($current_schedule_build); $i++) {
                for ($j = $i + 1; $j < count($current_schedule_build); $j++) {
                    if (check_class_overlap_detailed($current_schedule_build[$i], $current_schedule_build[$j])) {
                        $is_schedule_internally_valid = false;
                        break 2;
                    }
                }
            }
            if ($is_schedule_internally_valid) {
                $all_valid_schedules_ref[] = $current_schedule_build;
            }
        }
        return;
    }

    $current_course_id_to_process = $course_ids_to_process_list[$course_idx];
    
    if (isset($courses_with_class_options[$current_course_id_to_process]) && !empty($courses_with_class_options[$current_course_id_to_process])) {
        foreach ($courses_with_class_options[$current_course_id_to_process] as $single_class_option) {
            if (!isset($single_class_option['timeslot_id_db'])) {
                error_log("generate_possible_schedules_for_student: class_option for course {$current_course_id_to_process} is missing 'timeslot_id_db'.");
                continue; 
            }

            $can_add_this_option = true;
            foreach ($current_schedule_build as $existing_class_in_schedule) {
                if (check_class_overlap_detailed($existing_class_in_schedule, $single_class_option)) {
                    $can_add_this_option = false;
                    break;
                }
            }

            if ($can_add_this_option) {
                $new_schedule_with_this_option = array_merge($current_schedule_build, [$single_class_option]);
                generate_possible_schedules_for_student($conn, $courses_with_class_options, $new_schedule_with_this_option, $course_idx + 1, $all_valid_schedules_ref, $course_ids_to_process_list);
            }
        }
    } else {
        generate_possible_schedules_for_student($conn, $courses_with_class_options, $current_schedule_build, $course_idx + 1, $all_valid_schedules_ref, $course_ids_to_process_list);
    }
} 

function selected_if_match($current_value, $option_value): void { 
    if ((string)$current_value === (string)$option_value) { echo ' selected'; } 
}

function call_python_scheduler(
    string $python_executable, 
    string $python_script_absolute_path, 
    string $input_json_content, 
    string $python_input_filename, 
    string $python_output_filename, 
    int $timeout_seconds = 360 
): array {
    $result = ['status' => 'error_php_setup', 'message' => 'PHP Error: Initial setup for Python execution failed.', 'data' => null, 'debug_stdout' => '', 'debug_stderr' => ''];

    if (empty(trim($python_executable))) { 
        $result['message'] = "PHP Error: Python executable path is not configured."; return $result; 
    }
    if (!file_exists($python_script_absolute_path) || !is_readable($python_script_absolute_path)) { 
        $result['message'] = "PHP Error: Python script not found or not readable at: " . htmlspecialchars($python_script_absolute_path); return $result; 
    }

    $python_script_dir = dirname($python_script_absolute_path);
    $python_input_dir_absolute = $python_script_dir . DIRECTORY_SEPARATOR . 'input_data'; 
    $python_output_dir_absolute = $python_script_dir . DIRECTORY_SEPARATOR . 'output_data';

    foreach ([$python_input_dir_absolute, $python_output_dir_absolute] as $dir_path) {
        if (!is_dir($dir_path)) { 
            if (!@mkdir($dir_path, 0775, true) && !is_dir($dir_path)) { 
                $result['message'] = "PHP Error: Could not create Python I/O directory: " . htmlspecialchars($dir_path); return $result; 
            } 
        }
        if (!is_writable($dir_path)) { 
            $result['message'] = "PHP Error: Python I/O directory is not writable: " . htmlspecialchars($dir_path); return $result; 
        }
    }

    $input_file_for_python_abs = $python_input_dir_absolute . DIRECTORY_SEPARATOR . $python_input_filename; 
    $output_file_from_python_abs = $python_output_dir_absolute . DIRECTORY_SEPARATOR . $python_output_filename;

    if (file_put_contents($input_file_for_python_abs, $input_json_content) === false) { 
        $result['message'] = "PHP Error: Could not write to Python input file: " . htmlspecialchars($input_file_for_python_abs); return $result; 
    }
    if (file_exists($output_file_from_python_abs)) { 
        @unlink($output_file_from_python_abs); 
    }

    $command = escapeshellcmd($python_executable) . ' ' . escapeshellarg($python_script_absolute_path) . ' ' . escapeshellarg($python_input_filename); 
    
    $descriptorspec = [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]]; $pipes = [];
    $process = @proc_open($command, $descriptorspec, $pipes, $python_script_dir, null); 
    
    $stdout_content = ''; $stderr_content = ''; $start_exec_time = microtime(true);

    if (is_resource($process)) {
        fclose($pipes[0]); 
        stream_set_blocking($pipes[1], false); 
        stream_set_blocking($pipes[2], false); 

        while (true) {
            $proc_status = proc_get_status($process);
            if (!$proc_status || !$proc_status['running']) { 
                $stdout_content .= stream_get_contents($pipes[1]); 
                $stderr_content .= stream_get_contents($pipes[2]);
                break; 
            }
            if ($timeout_seconds > 0 && (microtime(true) - $start_exec_time) > $timeout_seconds) {
                proc_terminate($process, 9); 
                $stderr_content .= "\nPHP Error: Python script execution timed out after {$timeout_seconds} seconds and was terminated.";
                $stdout_content .= stream_get_contents($pipes[1]); 
                $stderr_content .= stream_get_contents($pipes[2]);
                break;
            }
            
            $read_stdout_chunk = fread($pipes[1], 8192); if ($read_stdout_chunk !== false && $read_stdout_chunk !== '') $stdout_content .= $read_stdout_chunk;
            $read_stderr_chunk = fread($pipes[2], 8192); if ($read_stderr_chunk !== false && $read_stderr_chunk !== '') $stderr_content .= $read_stderr_chunk;

            if (($read_stdout_chunk === '' || $read_stdout_chunk === false) && ($read_stderr_chunk === '' || $read_stderr_chunk === false) && $proc_status['running']) {
                usleep(100000);
            }
        }
        fclose($pipes[1]); fclose($pipes[2]);
        $exit_code = $proc_status['exitcode'] ?? proc_close($process);

        $result['debug_stdout'] = trim($stdout_content); 
        $result['debug_stderr'] = trim($stderr_content);

        if ($exit_code === 0) { 
            if (file_exists($output_file_from_python_abs)) {
                $json_output_content_py = @file_get_contents($output_file_from_python_abs);
                if ($json_output_content_py === false) { 
                    $result['message'] = "PHP Error: Python completed (exit 0), but could not read output file: " . htmlspecialchars($output_file_from_python_abs); 
                    $result['status'] = 'error_php_read_output'; 
                } else {
                    $output_data_array_py = json_decode($json_output_content_py, true);
                    if (json_last_error() === JSON_ERROR_NONE && isset($output_data_array_py['status'])) {
                        $python_reported_status = $output_data_array_py['status'];
                        $result['status'] = (stripos($python_reported_status, 'success') !== false) ? 'success' : 'error_python_logic';
                        $result['message'] = $output_data_array_py['message'] ?? 'No specific message from Python.'; 
                        $result['data'] = $output_data_array_py;
                    } else { 
                        $result['message'] = "PHP Error: Could not decode JSON from Python. JSON Error: " . json_last_error_msg(); 
                        $result['raw_output_from_python'] = substr(htmlspecialchars($json_output_content_py, ENT_QUOTES, 'UTF-8'), 0, 1000); 
                        $result['status'] = 'error_php_json_decode'; 
                    }
                }
            } else { 
                $result['message'] = "PHP Error: Python completed (exit 0), but output file not found: " . htmlspecialchars($output_file_from_python_abs); 
                $result['status'] = 'error_python_no_output_file'; 
                if(!empty($result['debug_stderr'])) { $result['message'] .= " Python Stderr: " . $result['debug_stderr']; } 
                elseif(!empty($result['debug_stdout'])) { $result['message'] .= " Python Stdout: " . $result['debug_stdout']; }
            }
        } else { 
            $result['message'] = "PHP Error: Python script exited with error code " . $exit_code . "."; 
            $result['status'] = 'error_python_exit_code'; 
            if (!empty($result['debug_stderr'])) { $result['message'] .= " Python Stderr: " . $result['debug_stderr']; } 
            elseif(!empty($result['debug_stdout'])) { $result['message'] .= " Python Stdout: " . $result['debug_stdout']; }
        }
    } else { 
        $result['message'] = "PHP Error: Could not execute Python script (proc_open failed). Check PHP configuration or permissions. Command attempted: " . htmlspecialchars($command); 
        $result['status'] = 'error_php_proc_open'; 
    }
    return $result;
}

?>