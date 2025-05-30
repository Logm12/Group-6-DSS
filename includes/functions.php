<?php

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// --- BASE_URL DEFINITION ---
if (!defined('BASE_URL')) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'];

    $app_base_path = '/DSS/'; 
    
    if ($app_base_path !== '/') { 
        $app_base_path = '/' . trim($app_base_path, '/') . '/';
    }
    define('BASE_URL', $protocol . $host . $app_base_path);
}

// --- UTILITY FUNCTIONS ---

/**
 * Redirects to a given path relative to the application's BASE_URL.
 * @param string $relative_app_path Path relative to BASE_URL (e.g., 'admin/index.php', 'login.php').
 */
function redirect(string $relative_app_path): void {
    $base = rtrim(BASE_URL, '/');
    $path = ltrim($relative_app_path, '/'); 
    $target_url = $base . '/' . $path;

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
        // MySQL TIME can be just H:i:s or can be timedelta string if very large
        // Python model uses H:H:M:S string. Standard DateTime expects H:i or H:i:s
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
 * @param string $condition Optional SQL WHERE condition (without "WHERE" keyword). User input for this MUST be sanitized/parameterized beforehand.
 * @param string $order_by Optional SQL ORDER BY clause (without "ORDER BY" keyword). User input for this MUST be sanitized beforehand.
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
    string $default_option_text = "-- Select Options --"
): string {
    $options_html = '';
    if (!empty($default_option_text)) { 
        $options_html .= "<option value=''>" . htmlspecialchars($default_option_text) . "</option>"; 
    }

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
    // Use alias for DisplayText to avoid issues if text_columns is a single complex expression
    $display_text_sql = "CONCAT_WS(' - ', " . implode(", ", $safe_text_column_parts) . ") AS DisplayText";
    
    $sql = "SELECT {$safe_value_column}, {$display_text_sql} FROM {$safe_table_name}";
    
    // WARNING: $condition and $order_by are directly concatenated. 
    // They MUST be constructed from trusted sources or use prepared statements if they involve user input.
    if (!empty($condition)) { $sql .= " WHERE " . $condition; } // $condition MUST be safe
    if (!empty($order_by)) { $sql .= " ORDER BY " . $order_by; } // $order_by MUST be safe

    $stmt = $conn->prepare($sql); // Try to prepare at least the main part
    if (!$stmt) {
        error_log("Error preparing statement in generate_select_options for table '{$table_name}': " . $conn->error . " | SQL Attempted: " . $sql);
        return $options_html . "<option value=''>Error loading options</option>";
    }

    if ($stmt->execute()) {
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                // Use the actual value column name as fetched
                $option_val_raw = $row[preg_replace('/[^a-zA-Z0-9_]/', '', $value_column)];
                $option_val_escaped = htmlspecialchars((string)$option_val_raw);
                $option_text_escaped = htmlspecialchars((string)$row['DisplayText']);
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

// --- APPLICATION SPECIFIC FUNCTIONS ---
function get_english_day_of_week(string $day_of_week_vietnamese): string {
    $days_map = [
        'Thứ Hai' => 'Monday', 'Thứ Ba' => 'Tuesday', 'Thứ Tư' => 'Wednesday',
        'Thứ Năm' => 'Thursday', 'Thứ Sáu' => 'Friday', 'Thứ Bảy' => 'Saturday', 'Chủ Nhật' => 'Sunday',
        // Add English to English mapping in case input is already English
        'Monday' => 'Monday', 'Tuesday' => 'Tuesday', 'Wednesday' => 'Wednesday',
        'Thursday' => 'Thursday', 'Friday' => 'Friday', 'Saturday' => 'Saturday', 'Sunday' => 'Sunday',
    ];
    return $days_map[$day_of_week_vietnamese] ?? $day_of_week_vietnamese; 
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

/**
 * Checks if two class sections (for the same student) overlap in time.
 * Assumes $class1_details and $class2_details are arrays/objects
 * containing at least 'timeslot_id_db'.
 * @param array $class1_details Details of the first class section.
 * @param array $class2_details Details of the second class section.
 * @return bool True if they overlap in time, False otherwise.
 */
function check_class_overlap_detailed(array $class1_details, array $class2_details): bool {
    // For generating a single student's schedule, an overlap occurs if two
    // chosen class sections fall into the exact same timeslot.
    // The main Python solver handles lecturer/classroom clashes.
    if (isset($class1_details['timeslot_id_db']) && isset($class2_details['timeslot_id_db'])) {
        return $class1_details['timeslot_id_db'] === $class2_details['timeslot_id_db'];
    }
    // If timeslot information is missing, assume no overlap to be safe, or handle error
    trigger_error("check_class_overlap_detailed: Missing 'timeslot_id_db' in class details.", E_USER_WARNING);
    return false; 
} 

/**
 * Counts the number of distinct days present in a given schedule.
 * @param array $schedule An array of scheduled events, each event must have 'timeslot_id_db'.
 * @param mysqli $conn Database connection object.
 * @return int Number of distinct days.
 */
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
        error_log("count_distinct_days_in_schedule: Prepare failed: " . $conn->error);
        return 0; // Or throw an exception
    }

    $stmt->bind_param($types, ...$unique_timeslot_ids);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $distinct_days = [];
    while ($row = $result->fetch_assoc()) {
        $distinct_days[] = $row['DayOfWeek'];
    }
    
    $stmt->close();
    return count(array_unique($distinct_days));
} 

/**
 * Calculates the total gap time (in minutes) for a schedule, grouped by DayOfWeek.
 * Assumes the $schedule is for a single entity (e.g., one student or one lecturer).
 * @param array $schedule An array of scheduled events, each with 'timeslot_id_db'.
 * @param mysqli $conn Database connection object.
 * @return array Associative array with DayOfWeek as key and total gap minutes as value.
 */
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
        error_log("calculate_total_gap_time_by_dow: Prepare failed: " . $conn->error);
        return [];
    }
    $stmt->bind_param($types, ...$unique_timeslot_ids);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $slots_data = [];
    while ($row = $result->fetch_assoc()) {
        $slots_data[$row['TimeSlotID']] = [
            'DayOfWeek' => $row['DayOfWeek'],
            'StartTime' => new DateTime($row['StartTime']),
            'EndTime'   => new DateTime($row['EndTime'])
        ];
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

        // Sort events by StartTime
        usort($day_events, function ($a, $b) {
            return $a['StartTime'] <=> $b['StartTime'];
        });

        $total_daily_gap = 0;
        for ($i = 0; $i < count($day_events) - 1; $i++) {
            $event1_end = $day_events[$i]['EndTime'];
            $event2_start = $day_events[$i+1]['StartTime'];
            if ($event2_start > $event1_end) { // Ensure there is a gap
                $interval = $event1_end->diff($event2_start);
                $gap_minutes = ($interval->h * 60) + $interval->i;
                $total_daily_gap += $gap_minutes;
            }
        }
        $gap_times_by_day[$day] = $total_daily_gap;
    }
    return $gap_times_by_day;
} 

/**
 * Selects the "best" schedule for a student from a list of possible schedules.
 * "Best" is defined as: fewest distinct days, then least total gap time.
 * @param array $schedules Array of schedule candidates. Each schedule is an array of events.
 * @param mysqli $conn Database connection object.
 * @return array|null The best schedule found, or null if no schedules provided.
 */
function select_best_schedule_for_student(array $schedules, mysqli $conn): ?array { 
    if (empty($schedules)) return null;

    $best_schedule = null;
    $min_distinct_days = PHP_INT_MAX;
    $min_total_gap_at_min_days = PHP_INT_MAX;

    foreach ($schedules as $current_schedule) {
        if (empty($current_schedule)) continue;

        $distinct_days = count_distinct_days_in_schedule($current_schedule, $conn);
        $gap_times_by_dow = calculate_total_gap_time_by_dow($current_schedule, $conn);
        $total_gap_time = array_sum($gap_times_by_dow);

        if ($distinct_days < $min_distinct_days) {
            $min_distinct_days = $distinct_days;
            $min_total_gap_at_min_days = $total_gap_time;
            $best_schedule = $current_schedule;
        } elseif ($distinct_days === $min_distinct_days) {
            if ($total_gap_time < $min_total_gap_at_min_days) {
                $min_total_gap_at_min_days = $total_gap_time;
                $best_schedule = $current_schedule;
            }
        }
    }
    return $best_schedule; 
} 

/**
 * Generates all possible valid (non-clashing for the student) schedules.
 * @param mysqli $conn DB connection.
 * @param array $courses_with_their_class_options e.g., ['CourseID1' => [class_optionA, class_optionB], ...]
 *                                                Each class_option is an array ['schedule_db_id' => ..., 'course_id_str' => ..., 'timeslot_id_db' => ...]
 * @param array $current_schedule Accumulator for the current schedule being built.
 * @param int $course_idx Index of the current course being processed from $course_ids_to_process.
 * @param array &$all_valid_schedules Reference to array to store all valid schedules found.
 * @param array|null $course_ids_to_process Array of CourseIDs to iterate through. Initialized if null.
 */
function generate_possible_schedules_for_student(
    mysqli $conn, 
    array $courses_with_their_class_options, 
    array $current_schedule = [], 
    int $course_idx = 0, 
    array &$all_valid_schedules = [], 
    ?array $course_ids_to_process = null
): void { 
    if ($course_ids_to_process === null) { // Initialize on first call
        $course_ids_to_process = array_keys($courses_with_their_class_options);
    }

    // Base case: all courses have been considered
    if ($course_idx == count($course_ids_to_process)) {
        if (!empty($current_schedule)) { // Only add non-empty valid schedules
            // Ensure the schedule is internally consistent (no self-clashes) before adding
            // This check is somewhat redundant if check_class_overlap_detailed is robustly used during building
            $is_final_schedule_valid = true;
            for ($i = 0; $i < count($current_schedule); $i++) {
                for ($j = $i + 1; $j < count($current_schedule); $j++) {
                    if (check_class_overlap_detailed($current_schedule[$i], $current_schedule[$j])) {
                        $is_final_schedule_valid = false;
                        break 2;
                    }
                }
            }
            if ($is_final_schedule_valid) {
                $all_valid_schedules[] = $current_schedule;
            }
        }
        return;
    }

    $current_course_id = $course_ids_to_process[$course_idx];
    
    // Case 1: Try scheduling the current course
    if (isset($courses_with_their_class_options[$current_course_id]) && !empty($courses_with_their_class_options[$current_course_id])) {
        foreach ($courses_with_their_class_options[$current_course_id] as $class_option) {
            // A class option must have 'timeslot_id_db' to be valid for overlap checking
            if (!isset($class_option['timeslot_id_db'])) {
                trigger_error("generate_possible_schedules_for_student: class_option for course {$current_course_id} is missing 'timeslot_id_db'.", E_USER_WARNING);
                continue; 
            }

            $can_add_option = true;
            // Check if this class_option clashes with anything already in current_schedule
            foreach ($current_schedule as $existing_class_in_schedule) {
                if (check_class_overlap_detailed($existing_class_in_schedule, $class_option)) {
                    $can_add_option = false;
                    break;
                }
            }

            if ($can_add_option) {
                $new_schedule_with_option = array_merge($current_schedule, [$class_option]);
                generate_possible_schedules_for_student($conn, $courses_with_their_class_options, $new_schedule_with_option, $course_idx + 1, $all_valid_schedules, $course_ids_to_process);
            }
        }
    } else {
        // If the course has no options, or is not in the list (should not happen if $course_ids_to_process is from keys)
        // we must proceed to the next course without adding anything for this one.
        generate_possible_schedules_for_student($conn, $courses_with_their_class_options, $current_schedule, $course_idx + 1, $all_valid_schedules, $course_ids_to_process);
    }

    // Case 2: (Optional, if courses are not mandatory) Skip the current course and move to the next.
    // For university scheduling, usually all registered courses are mandatory.
    // If a student *can* choose to not schedule a registered course, this branch would be uncommented.
    // generate_possible_schedules_for_student($conn, $courses_with_their_class_options, $current_schedule, $course_idx + 1, $all_valid_schedules, $course_ids_to_process);
} 

function selected_if_match($current_value, $option_value): void { 
    if ((string)$current_value === (string)$option_value) { echo 'selected'; } 
}

// --- PYTHON SCRIPT EXECUTION FUNCTION --- 
// (Giữ nguyên hàm call_python_scheduler như đã cung cấp)
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
    $command = escapeshellcmd($python_executable) . ' ' . escapeshellarg($python_script_absolute_path) . ' ' . escapeshellarg($python_input_filename); // Truyền tên file input
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