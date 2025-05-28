<?php
// htdocs/DSS/includes/functions.php

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!defined('BASE_URL')) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'];
    $script_name_parts = explode('/', trim($_SERVER['SCRIPT_NAME'], '/'));
    $base_path_parts = [];
    $project_root_dir_name = 'DSS'; 
    $found_root = false;
    foreach ($script_name_parts as $part) {
        $base_path_parts[] = $part;
        if ($part == $project_root_dir_name) { $found_root = true; break; }
    }
    if (!$found_root && isset($script_name_parts[0]) && $script_name_parts[0] == $project_root_dir_name) {
         $base_uri = $project_root_dir_name;
    } elseif (!$found_root) {
        if (isset($script_name_parts[0])) { $base_uri = $script_name_parts[0]; } 
        else { $base_uri = '';}
    } else { $base_uri = implode('/', $base_path_parts); }
    define('BASE_URL', $protocol . $host . '/' . rtrim($base_uri, '/') . '/');
}
// ... (Các hàm redirect, sanitize_input, is_logged_in, user info, require_role, flash messages, format datetime giữ nguyên) ...
function redirect($relative_url) {
    if (headers_sent()) { echo "<script>window.location.href='" . BASE_URL . ltrim($relative_url, '/') . "';</script>"; exit(); }
    header("Location: " . BASE_URL . ltrim($relative_url, '/')); exit();
}
function sanitize_input($data) {
    if (is_array($data)) { return array_map('sanitize_input', $data); }
    $data = trim($data); $data = stripslashes($data); $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8'); return $data;
}
function is_logged_in() { return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']); }
function get_current_user_id() { return $_SESSION['user_id'] ?? null; }
function get_current_user_role() { return $_SESSION['role'] ?? null; }
function get_current_user_fullname() { return $_SESSION['fullname'] ?? null; }
function get_current_user_linked_entity_id() { return $_SESSION['linked_entity_id'] ?? null; }

function require_role($allowed_roles, $login_page = 'login.php', $unauthorized_page_content = "<h1>403 Forbidden</h1><p>You do not have permission to access this page.</p>") {
    if (!is_logged_in()) {
        $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
        set_flash_message('login_required', 'Please log in to access this page.', 'warning');
        redirect($login_page);
    }
    $current_role = get_current_user_role();
    if (!in_array($current_role, (array)$allowed_roles)) {
        http_response_code(403);
        echo "<!DOCTYPE html><html lang='vi'><head><meta charset='UTF-8'><title>403 Forbidden</title>";
        echo "<link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css' rel='stylesheet'>";
        echo "<style>body{display:flex;justify-content:center;align-items:center;min-height:100vh;background-color:#f8f9fa;text-align:center;font-family:sans-serif;}</style>";
        echo "</head><body><div class='container'>";
        echo $unauthorized_page_content;
        $dashboard_map = ['admin' => 'admin/index.php', 'instructor' => 'instructor/index.php', 'student' => 'student/index.php'];
        if (isset($dashboard_map[$current_role])) {
             echo "<p><a href='" . BASE_URL . htmlspecialchars(ltrim($dashboard_map[$current_role], '/')) . "' class='btn btn-primary mt-3'>Go to your dashboard</a></p>";
        } else {
             echo "<p><a href='" . BASE_URL . htmlspecialchars(ltrim($login_page, '/')) . "' class='btn btn-secondary mt-3'>Login</a></p>";
        }
        echo "</div></body></html>";
        exit();
    }
}

function set_flash_message($name, $message, $type = 'info') {
    if (!isset($_SESSION['flash_messages'])) { $_SESSION['flash_messages'] = []; }
    $_SESSION['flash_messages'][$name] = ['message' => $message, 'type' => $type ];
}
function display_flash_message($name) {
    if (isset($_SESSION['flash_messages'][$name])) {
        $flash = $_SESSION['flash_messages'][$name]; unset($_SESSION['flash_messages'][$name]);
        $type = htmlspecialchars($flash['type']);
        $valid_types = ['success', 'danger', 'warning', 'info', 'primary', 'secondary', 'light', 'dark'];
        if (!in_array($type, $valid_types)) $type = 'info';
        return "<div class='alert alert-{$type} alert-dismissible fade show' role='alert' style='margin-top: 15px;'>" . htmlspecialchars($flash['message']) . "<button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button></div>";
    } return '';
}
function display_all_flash_messages() {
    $output = ''; if (isset($_SESSION['flash_messages']) && is_array($_SESSION['flash_messages'])) { foreach (array_keys($_SESSION['flash_messages']) as $name) { $output .= display_flash_message($name); } } return $output;
}
function format_datetime_for_display($datetime_string) { if (empty($datetime_string) || $datetime_string === '0000-00-00 00:00:00' || $datetime_string === null) return ''; try { $date = new DateTime($datetime_string); return $date->format('d/m/Y H:i:s'); } catch (Exception $e) { return 'Invalid datetime';} }
function format_date_for_display($date_string) { if (empty($date_string) || $date_string === '0000-00-00' || $date_string === null) return ''; try { $date = new DateTime($date_string); return $date->format('d/m/Y'); } catch (Exception $e) { return 'Invalid date';} }
function format_time_for_display($time_string) { if (empty($time_string) || $time_string === null) return ''; try { $time = new DateTime('1970-01-01 ' . $time_string); return $time->format('H:i'); } catch (Exception $e) { return 'Invalid time';} }
function generate_select_options($conn, $table_name, $value_column, $text_columns, $selected_value = null, $condition = "", $order_by = "", $default_option_text = "") {
    $options_html = ""; if (!empty($default_option_text)) { $options_html .= "<option value=''>" . htmlspecialchars($default_option_text) . "</option>\n"; }
    $fields_to_select = "`" . $value_column . "`"; if (is_array($text_columns)) { foreach ($text_columns as $col) { $fields_to_select .= ", `" . $col . "`"; } } else { $fields_to_select .= ", `" . $text_columns . "`"; }
    $sql = "SELECT {$fields_to_select} FROM `{$table_name}`"; if (!empty($condition)) { $sql .= " WHERE " . $condition; } if (!empty($order_by)) { $sql .= " ORDER BY " . $order_by; }
    $result = $conn->query($sql);
    if ($result) { if ($result->num_rows > 0) { while ($row = $result->fetch_assoc()) { $value = htmlspecialchars($row[$value_column]); $text_parts = []; if (is_array($text_columns)) { foreach ($text_columns as $col) { $text_parts[] = htmlspecialchars($row[$col]); } } else { $text_parts[] = htmlspecialchars($row[$text_columns]); } $text = implode(" - ", $text_parts); $selected_attr = ($selected_value !== null && (string)$row[$value_column] == (string)$selected_value) ? " selected" : ""; $options_html .= "<option value='{$value}'{$selected_attr}>{$text}</option>\n"; } } else { if (empty($default_option_text)) { $options_html .= "<option value='' disabled>Không có dữ liệu</option>"; } } } else { $options_html .= "<option value=''>Lỗi tải dữ liệu</option>"; } return $options_html;
}
function get_vietnamese_day_of_week($day_of_week) { $days = [ 'Monday' => 'Thứ Hai', 'Tuesday' => 'Thứ Ba', 'Wednesday' => 'Thứ Tư', 'Thursday' => 'Thứ Năm', 'Friday' => 'Thứ Sáu', 'Saturday' => 'Thứ Bảy', 'Sunday' => 'Chủ Nhật' ]; return $days[ucfirst(strtolower($day_of_week))] ?? ucfirst(strtolower($day_of_week)); }
function get_date_range_array($start_date, $end_date) { $dates = []; $current = strtotime($start_date); $end = strtotime($end_date); while ($current <= $end) { $dates[] = date('Y-m-d', $current); $current = strtotime('+1 day', $current); } return $dates; }


/**
 * Thực thi một script Python và trả về kết quả.
 *
 * @param string $python_executable Đường dẫn đến trình thông dịch Python.
 * @param string $python_script_absolute_path Đường dẫn TUYỆT ĐỐI đến file script Python chính.
 * @param string $input_json_content Nội dung JSON để ghi vào file input cho Python.
 * @param string $python_input_filename Tên file (không có đường dẫn) mà Python sẽ đọc từ thư mục input_data của nó.
 * @param string $python_output_filename Tên file (không có đường dẫn) mà Python sẽ ghi kết quả vào thư mục output_data của nó.
 * @param int $timeout_seconds Thời gian tối đa (giây) cho script Python chạy.
 * @return array Kết quả gồm ['status', 'message', 'data' (array từ JSON), 'debug_stdout', 'debug_stderr'].
 */
function call_python_scheduler(
    string $python_executable,
    string $python_script_absolute_path,
    string $input_json_content,
    string $python_input_filename, // Ví dụ: 'scheduler_input_config.json'
    string $python_output_filename, // Ví dụ: 'final_schedule_output.json'
    int $timeout_seconds = 300
): array {
    $result = ['status' => 'error', 'message' => 'Lỗi không xác định trong quá trình thực thi Python.', 'data' => null, 'debug_stdout' => '', 'debug_stderr' => ''];
    
    $python_script_dir = dirname($python_script_absolute_path); // Thư mục chứa script Python (ví dụ: DSS/python_algorithm)
    
    // Tạo đường dẫn tuyệt đối đến thư mục input và output của Python
    $python_input_dir_absolute = $python_script_dir . '/input_data';
    $python_output_dir_absolute = $python_script_dir . '/output_data';

    // Tạo các thư mục nếu chúng chưa tồn tại
    foreach ([$python_input_dir_absolute, $python_output_dir_absolute] as $dir) {
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0775, true)) {
                $result['message'] = "Lỗi PHP: Không thể tạo thư mục: " . $dir;
                return $result;
            }
        }
        if (!is_writable($dir)) {
            $result['message'] = "Lỗi PHP: Thư mục không có quyền ghi: " . $dir;
            return $result;
        }
    }

    $input_file_for_python_absolute = $python_input_dir_absolute . '/' . $python_input_filename;
    $output_file_from_python_absolute = $python_output_dir_absolute . '/' . $python_output_filename;

    if (file_put_contents($input_file_for_python_absolute, $input_json_content) === false) {
        $result['message'] = "Lỗi PHP: Không thể ghi vào file input của Python: " . $input_file_for_python_absolute;
        return $result;
    }

    if (file_exists($output_file_from_python_absolute)) {
        if (!unlink($output_file_from_python_absolute)) {
            // Đây chỉ là cảnh báo, không nên dừng hẳn
            $result['message'] = "Cảnh báo PHP: Không thể xóa file output cũ: " . $output_file_from_python_absolute . ". Tiếp tục...";
        }
    }

    // Lệnh thực thi: cd vào thư mục script rồi chạy script
    $command = 'cd ' . escapeshellarg($python_script_dir) . ' && ' .
               escapeshellcmd($python_executable) . ' ' . escapeshellarg(basename($python_script_absolute_path));
    
    $descriptorspec = [
        0 => ["pipe", "r"], // stdin
        1 => ["pipe", "w"], // stdout
        2 => ["pipe", "w"]  // stderr
    ];
    $pipes = [];
    // Chạy process từ thư mục của script Python để nó tìm được các module con
    $process = proc_open($command, $descriptorspec, $pipes, $python_script_dir);
    
    $stdout_content = '';
    $stderr_content = '';
    $start_exec_time = microtime(true);

    if (is_resource($process)) {
        fclose($pipes[0]); // Không gửi gì vào stdin của script Python

        stream_set_blocking($pipes[1], false); // Non-blocking stdout
        stream_set_blocking($pipes[2], false); // Non-blocking stderr

        while (true) {
            $proc_status = proc_get_status($process);
            if (!$proc_status['running']) {
                break; // Process đã kết thúc
            }

            // Kiểm tra timeout
            if ($timeout_seconds > 0 && (microtime(true) - $start_exec_time) > $timeout_seconds) {
                proc_terminate($process, 9); // Gửi SIGKILL
                $stderr_content .= "\nLỗi PHP: Script Python vượt quá thời gian cho phép ({$timeout_seconds} giây) và đã bị dừng.";
                break; 
            }

            // Đọc từ pipes
            $read_stdout_chunk = fread($pipes[1], 8192);
            if ($read_stdout_chunk !== false && $read_stdout_chunk !== '') {
                $stdout_content .= $read_stdout_chunk;
            }
            $read_stderr_chunk = fread($pipes[2], 8192);
            if ($read_stderr_chunk !== false && $read_stderr_chunk !== '') {
                $stderr_content .= $read_stderr_chunk;
            }
            
            // Nếu không có gì để đọc và process vẫn đang chạy, ngủ một chút
            if (($read_stdout_chunk === '' || $read_stdout_chunk === false) && 
                ($read_stderr_chunk === '' || $read_stderr_chunk === false) && 
                $proc_status['running']) {
                usleep(50000); // 50ms
            }
        }
        // Đọc nốt phần còn lại sau khi process kết thúc
        $stdout_content .= stream_get_contents($pipes[1]);
        $stderr_content .= stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit_code = proc_close($process);

        $result['debug_stdout'] = trim($stdout_content);
        $result['debug_stderr'] = trim($stderr_content);

        if ($exit_code === 0) { // Python chạy thành công (theo exit code)
            if (file_exists($output_file_from_python_absolute)) {
                $json_output_content = file_get_contents($output_file_from_python_absolute);
                if ($json_output_content === false) {
                    $result['message'] = "Lỗi PHP: Script Python hoàn thành, nhưng không thể đọc file output: " . $output_file_from_python_absolute;
                } else {
                    $output_data_array = json_decode($json_output_content, true);
                    if (json_last_error() === JSON_ERROR_NONE && isset($output_data_array['status'])) {
                        // Thành công nếu Python trả về status là một trong các dạng success
                        $python_status = $output_data_array['status'];
                        if (strpos($python_status, 'success') === 0) {
                             $result['status'] = 'success'; // Trạng thái tổng thể của hàm PHP
                        } else {
                             $result['status'] = 'error_python_logic'; // Python chạy nhưng logic bên trong báo lỗi
                        }
                        $result['message'] = $output_data_array['message'] ?? 'Không có thông điệp từ Python.';
                        $result['data'] = $output_data_array; // Trả về toàn bộ object JSON từ Python
                    } else {
                        $result['message'] = "Lỗi PHP: Không thể giải mã JSON output từ Python. Lỗi: " . json_last_error_msg();
                        $result['raw_output_from_python'] = substr($json_output_content, 0, 500); // Gửi một phần output thô để debug
                    }
                }
            } else {
                $result['message'] = "Lỗi PHP: Script Python hoàn thành (exit code 0), nhưng không tìm thấy file output: " . $output_file_from_python_absolute;
                if(!empty($result['debug_stderr'])) { $result['message'] .= " Python Stderr: " . $result['debug_stderr']; }
                elseif(!empty($result['debug_stdout'])) { $result['message'] .= " Python Stdout: " . $result['debug_stdout']; } // Chỉ hiển thị stdout nếu stderr trống
            }
        } else { // Python trả về lỗi
            $result['message'] = "Lỗi PHP: Script Python thoát với mã lỗi " . $exit_code . ".";
            if (!empty($result['debug_stderr'])) { $result['message'] .= " Python Stderr: " . $result['debug_stderr']; }
            elseif(!empty($result['debug_stdout'])) { $result['message'] .= " Python Stdout (có thể chứa traceback): " . $result['debug_stdout']; }
        }
    } else {
        $result['message'] = "Lỗi PHP: Không thể thực thi script Python (proc_open thất bại). Lệnh: " . $command;
    }

    // Dọn dẹp file input (tùy chọn)
    // if (file_exists($input_file_for_python_absolute)) {
    //     @unlink($input_file_for_python_absolute);
    // }
    return $result;
}
function check_class_overlap_detailed($class1, $class2) {
    // class1 và class2 là các mảng chứa DayOfWeek, StartTime, EndTime
    if (empty($class1['DayOfWeek']) || empty($class1['StartTime']) || empty($class1['EndTime']) ||
        empty($class2['DayOfWeek']) || empty($class2['StartTime']) || empty($class2['EndTime'])) {
        // Nếu thiếu thông tin thời gian, coi như không thể xác định, không trùng
        return false; 
    }

    if (strtolower($class1['DayOfWeek']) != strtolower($class2['DayOfWeek'])) {
        // Khác THỨ thì không thể trùng về mặt ca học trong tuần
        return false;
    }

    // Cùng THỨ, kiểm tra giờ
    // Chuyển đổi thời gian sang dạng timestamp để so sánh dễ dàng (chỉ cần so sánh giờ và phút)
    // Sử dụng một ngày giả cố định để chuyển đổi, vì chúng ta chỉ quan tâm đến phần thời gian
    $dummy_date_str = '2000-01-01 '; // Một ngày bất kỳ
    try {
        $start1 = strtotime($dummy_date_str . $class1['StartTime']);
        $end1 = strtotime($dummy_date_str . $class1['EndTime']);
        $start2 = strtotime($dummy_date_str . $class2['StartTime']);
        $end2 = strtotime($dummy_date_str . $class2['EndTime']);
    } catch (Exception $e) {
        error_log("Error parsing time in check_class_overlap_detailed: " . $e->getMessage());
        return false; // Nếu lỗi parse thời gian, coi như không trùng để tránh lỗi logic
    }
    

    if ($start1 === false || $end1 === false || $start2 === false || $end2 === false) {
        // Lỗi chuyển đổi thời gian
        return false;
    }
    
    // Logic overlap: max(start1, start2) < min(end1, end2)
    return max($start1, $start2) < min($end1, $end2);
}

/**
 * Đếm số lượng THỨ khác nhau mà sinh viên phải đi học trong lịch.
 */
function count_distinct_days_of_week($schedule) {
    $days_of_week = [];
    foreach ($schedule as $event) {
        if (isset($event['DayOfWeek'])) {
            $days_of_week[strtolower($event['DayOfWeek'])] = true;
        }
    }
    return count($days_of_week);
}

/**
 * Tính tổng thời gian trống giữa các ca học trong cùng một THỨ.
 * Bỏ qua ngày cụ thể.
 */
function calculate_total_gap_time_by_dow($schedule) {
    if (count($schedule) < 2) return 0;

    // Nhóm các lớp theo DayOfWeek
    $schedule_by_dow = [];
    foreach ($schedule as $event) {
        if (isset($event['DayOfWeek']) && isset($event['StartTime']) && isset($event['EndTime'])) {
            $schedule_by_dow[strtolower($event['DayOfWeek'])][] = $event;
        }
    }

    $total_gap_seconds_overall = 0;
    $dummy_date_str = '2000-01-01 ';

    foreach ($schedule_by_dow as $day => $events_on_day) {
        if (count($events_on_day) < 2) continue;

        // Sắp xếp các lớp trong ngày theo thời gian bắt đầu
        usort($events_on_day, function($a, $b) use ($dummy_date_str) {
            try {
                $time_a = strtotime($dummy_date_str . $a['StartTime']);
                $time_b = strtotime($dummy_date_str . $b['StartTime']);
                if ($time_a === false || $time_b === false) return 0;
                return $time_a - $time_b;
            } catch (Exception $e) { return 0; }
        });

        for ($i = 0; $i < count($events_on_day) - 1; $i++) {
            $event1 = $events_on_day[$i];
            $event2 = $events_on_day[$i+1];
            
            try {
                $end1 = strtotime($dummy_date_str . $event1['EndTime']);
                $start2 = strtotime($dummy_date_str . $event2['StartTime']);
            } catch (Exception $e) { continue; }


            if ($end1 === false || $start2 === false) continue;

            if ($start2 > $end1) { // Có khoảng trống
                $gap = $start2 - $end1;
                $total_gap_seconds_overall += $gap;
            }
        }
    }
    return $total_gap_seconds_overall / 60; // Trả về phút
}

/**
 * Chọn lịch "tốt nhất" từ danh sách các lịch khả thi.
 * Ưu tiên ít THỨ đi học hơn, sau đó là ít thời gian trống hơn.
 */
function select_best_schedule_for_student($schedules) {
    if (empty($schedules)) return null;

    $best_schedule = null;
    // Sử dụng các hàm mới đã sửa
    $min_days_of_week_attending = PHP_INT_MAX; 
    $min_gaps_for_min_days = PHP_INT_MAX;

    foreach ($schedules as $schedule_candidate) {
        if(empty($schedule_candidate)) continue;

        $days_attending = count_distinct_days_of_week($schedule_candidate);
        $gap_time = calculate_total_gap_time_by_dow($schedule_candidate);

        if ($best_schedule === null) {
            $best_schedule = $schedule_candidate;
            $min_days_of_week_attending = $days_attending;
            $min_gaps_for_min_days = $gap_time;
        } elseif ($days_attending < $min_days_of_week_attending) {
            $min_days_of_week_attending = $days_attending;
            $min_gaps_for_min_days = $gap_time;
            $best_schedule = $schedule_candidate;
        } elseif ($days_attending == $min_days_of_week_attending) {
            if ($gap_time < $min_gaps_for_min_days) {
                $min_gaps_for_min_days = $gap_time;
                $best_schedule = $schedule_candidate;
            }
        }
    }
    return $best_schedule;
}

// Hàm generate_possible_schedules_for_student vẫn giữ nguyên logic cốt lõi,
// vì nó dựa vào check_class_overlap_detailed để kiểm tra xung đột.
// Nếu check_class_overlap_detailed đã được sửa để chỉ dựa vào Thứ & Giờ,
// thì generate_possible_schedules_for_student sẽ tự động hoạt động theo logic đó.
function generate_possible_schedules_for_student(
    array $courses_with_their_class_options,
    array $current_schedule = [],
    int $course_idx = 0,
    array &$all_valid_schedules = [],
    ?array $course_ids_to_process = null
) {
    if ($course_ids_to_process === null) {
        $course_ids_to_process = [];
        foreach($courses_with_their_class_options as $cid => $options) {
            if (!empty($options)) {
                $course_ids_to_process[] = $cid;
            }
        }
        if (empty($course_ids_to_process)) {
             if (!empty($current_schedule) || count($courses_with_their_class_options) == 0) { // Nếu không có môn nào để chọn, hoặc đã chọn hết
                $all_valid_schedules[] = $current_schedule; 
             }
             return;
        }
    }

    if ($course_idx >= count($course_ids_to_process)) {
        if (!empty($current_schedule)) {
            $all_valid_schedules[] = $current_schedule;
        }
        return;
    }

    $current_course_id = $course_ids_to_process[$course_idx];
    $class_options_for_this_course = $courses_with_their_class_options[$current_course_id] ?? [];

    if (!empty($class_options_for_this_course)) {
        // Vì SV chọn 1 lớp/môn qua radio, $class_options_for_this_course chỉ có 1 phần tử
        $class_option = $class_options_for_this_course[0]; 

        $is_compatible = true;
        foreach ($current_schedule as $selected_class_in_schedule) {
            if (check_class_overlap_detailed($selected_class_in_schedule, $class_option)) { // Đã sửa
                $is_compatible = false;
                break;
            }
        }

        if ($is_compatible) {
            $new_schedule = array_merge($current_schedule, [$class_option]);
            generate_possible_schedules_for_student(
                $courses_with_their_class_options,
                $new_schedule,
                $course_idx + 1,
                $all_valid_schedules,
                $course_ids_to_process
            );
        } else {
            // Lựa chọn của SV cho môn này bị xung đột với các lựa chọn trước đó.
            // Không tạo lịch nào từ nhánh này.
            // student_schedule_builder.php sẽ báo lỗi.
        }
    } else {
        // Môn này không có lớp nào được SV chọn (ví dụ, chọn "Không chọn môn này")
        generate_possible_schedules_for_student(
            $courses_with_their_class_options,
            $current_schedule,
            $course_idx + 1,
            $all_valid_schedules,
            $course_ids_to_process
        );
    }
}
/**
 * Chuyển đổi StartTime và EndTime thành chuỗi mô tả Tiết học.
 * Cần tùy chỉnh logic này cho phù hợp với trường của bạn.
 * 
 * @param string $startTimeStr ví dụ: "07:00:00"
 * @param string $endTimeStr ví dụ: "09:30:00"
 * @return string ví dụ: "Tiết 1-3 (Sáng)" hoặc giờ nếu không khớp
 */
function get_period_string_from_times($startTimeStr, $endTimeStr) {
    // Đây là logic ví dụ, bạn cần định nghĩa các mốc thời gian cho từng tiết
    $periods = [
        // Buổi Sáng
        "Tiết 1-2 (S)" => ["start" => "07:00", "end" => "08:40"],
        "Tiết 1-3 (S)" => ["start" => "07:00", "end" => "09:30"], // Nếu có ca 3 tiết
        "Tiết 3-4 (S)" => ["start" => "08:50", "end" => "10:30"],
        "Tiết 3-5 (S)" => ["start" => "08:50", "end" => "11:20"],
        "Tiết 5-6 (S)" => ["start" => "10:40", "end" => "12:20"],
        // Buổi Chiều
        "Tiết 7-8 (C)" => ["start" => "13:00", "end" => "14:40"],
        "Tiết 7-9 (C)" => ["start" => "13:00", "end" => "15:30"],
        "Tiết 9-10 (C)" => ["start" => "14:50", "end" => "16:30"],
        "Tiết 9-11 (C)" => ["start" => "14:50", "end" => "17:20"],
        // Buổi Tối
        "Tiết 12-13 (T)" => ["start" => "18:00", "end" => "19:40"],
        "Tiết 12-14 (T)" => ["start" => "18:00", "end" => "20:30"],
    ];

    // Chuẩn hóa start và end time đầu vào (chỉ lấy HH:MM)
    $input_start = substr($startTimeStr, 0, 5);
    $input_end = substr($endTimeStr, 0, 5);

    foreach ($periods as $label => $times) {
        if ($times["start"] == $input_start && $times["end"] == $input_end) {
            return $label;
        }
    }
    // Fallback nếu không khớp chính xác
    return format_time_for_display($startTimeStr) . " - " . format_time_for_display($endTimeStr);
}
?>