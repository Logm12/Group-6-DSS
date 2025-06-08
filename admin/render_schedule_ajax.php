<?php
// htdocs/DSS/admin/render_schedule_ajax.php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db_connect.php'; // $conn is expected to be available

// Check user authorization
if (!is_logged_in() || get_current_user_role() !== 'admin') {
    http_response_code(403); // Forbidden
    // Output a simple error message; client-side JS might handle this more gracefully
    echo "<p class='text-danger p-3'>Error: Unauthorized access. Admin privileges required.</p>"; 
    exit;
}

// Process only POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $schedule_events = $input['schedule_events'] ?? [];
    $view_type = $input['view_type'] ?? 'table_html'; // Default to table view if not specified

    // Initialize arrays for data enrichment
    $lecturer_names = []; 
    $classroom_codes = []; 
    $timeslot_details = []; 
    $course_names = [];

    if (empty($schedule_events)) {
        if ($view_type === 'table_html') {
            echo "<p class='text-muted p-3'>No schedule data to display or an empty schedule was generated.</p>";
        } else {
            echo "<div class='p-3 text-center text-muted'>No schedule events to visualize.</div>";
        }
        exit;
    }

    // Collect all unique IDs for efficient database querying
    $all_lecturer_ids = array_filter(array_unique(array_column($schedule_events, 'lecturer_id_db')));
    $all_classroom_ids = array_filter(array_unique(array_column($schedule_events, 'classroom_id_db')));
    $all_timeslot_ids = array_filter(array_unique(array_column($schedule_events, 'timeslot_id_db')));
    $all_course_ids_str = array_filter(array_unique(array_column($schedule_events, 'course_id_str')));

    // Fetch details from database
    if ($conn) {
        if (!empty($all_lecturer_ids)) {
            $lect_ids_in = implode(',', array_map('intval', $all_lecturer_ids));
            $result_lecturers = $conn->query("SELECT LecturerID, LecturerName FROM Lecturers WHERE LecturerID IN ($lect_ids_in)");
            if($result_lecturers) while($row = $result_lecturers->fetch_assoc()) $lecturer_names[$row['LecturerID']] = $row['LecturerName'];
        }
        if (!empty($all_classroom_ids)) {
            $room_ids_in = implode(',', array_map('intval', $all_classroom_ids));
            $result_classrooms = $conn->query("SELECT ClassroomID, RoomCode FROM Classrooms WHERE ClassroomID IN ($room_ids_in)");
            if($result_classrooms) while($row = $result_classrooms->fetch_assoc()) $classroom_codes[$row['ClassroomID']] = $row['RoomCode'];
        }
        if (!empty($all_timeslot_ids)) {
            $ts_ids_in = implode(',', array_map('intval', $all_timeslot_ids));
            $result_timeslots = $conn->query("SELECT TimeSlotID, DayOfWeek, StartTime, EndTime FROM TimeSlots WHERE TimeSlotID IN ($ts_ids_in)");
            if($result_timeslots) while($row = $result_timeslots->fetch_assoc()) $timeslot_details[$row['TimeSlotID']] = $row;
        }
        if(!empty($all_course_ids_str)){
            // Using prepared statement for course IDs as they are strings
            $course_ids_placeholders = implode(',', array_fill(0, count($all_course_ids_str), '?'));
            $stmt_courses_info = $conn->prepare("SELECT CourseID, CourseName FROM Courses WHERE CourseID IN ($course_ids_placeholders)");
            if ($stmt_courses_info) {
                $types_for_courses = str_repeat('s', count($all_course_ids_str));
                $stmt_courses_info->bind_param($types_for_courses, ...$all_course_ids_str);
                $stmt_courses_info->execute();
                $result_courses_info = $stmt_courses_info->get_result();
                while($row = $result_courses_info->fetch_assoc()) $course_names[$row['CourseID']] = $row['CourseName'];
                $stmt_courses_info->close();
            } else {
                error_log("render_schedule_ajax: Failed to prepare statement for course names: " . $conn->error);
            }
        }
    } else {
        // Handle case where $conn is not available (should not happen if db_connect.php is correct)
        echo "<p class='text-danger p-3'>Error: Database connection not available for rendering schedule.</p>";
        exit;
    }
    

    // Sort events by timeslot (day and start time) for consistent display
    usort($schedule_events, function($a, $b) use ($timeslot_details) {
        $ts_a_id = $a['timeslot_id_db'] ?? null;
        $ts_b_id = $b['timeslot_id_db'] ?? null;

        if ($ts_a_id === null || $ts_b_id === null || !isset($timeslot_details[$ts_a_id]) || !isset($timeslot_details[$ts_b_id])) return 0;
        
        $ts_a_data = $timeslot_details[$ts_a_id];
        $ts_b_data = $timeslot_details[$ts_b_id];

        // Define order for days of the week
        $day_order_map = ['Monday' => 1, 'Tuesday' => 2, 'Wednesday' => 3, 'Thursday' => 4, 'Friday' => 5, 'Saturday' => 6, 'Sunday' => 7];
        $day_comparison_result = ($day_order_map[$ts_a_data['DayOfWeek']] ?? 99) <=> ($day_order_map[$ts_b_data['DayOfWeek']] ?? 99);
        
        if ($day_comparison_result !== 0) return $day_comparison_result;
        return strcmp($ts_a_data['StartTime'], $ts_b_data['StartTime']); // Then by start time
    });
    
    // --- Render based on view_type ---
    if ($view_type === 'table_html') {
        $html_output = '<div class="table-responsive"><table class="table table-bordered table-striped table-hover schedule-table">';
        $html_output .= '<thead class="table-dark"><tr><th>Course Code</th><th>Course Name</th><th>Instructor</th><th>Classroom</th><th>Day</th><th>Time</th><th>Students</th></tr></thead><tbody>';

        foreach ($schedule_events as $event) {
            $course_id_val = $event['course_id_str'] ?? 'N/A';
            // Use course_name from Python output first, fallback to DB lookup if available
            $course_name_display_val = htmlspecialchars($event['course_name'] ?? ($course_names[$course_id_val] ?? 'N/A'));
            $lecturer_name_display_val = htmlspecialchars($lecturer_names[$event['lecturer_id_db']] ?? ($event['lecturer_name'] ?? 'N/A'));
            $classroom_code_display_val = htmlspecialchars($classroom_codes[$event['classroom_id_db']] ?? ($event['room_code'] ?? 'N/A'));
            
            $ts_info_current = $timeslot_details[$event['timeslot_id_db']] ?? null;
            $day_display_val = $ts_info_current ? htmlspecialchars($ts_info_current['DayOfWeek']) : 'N/A';
            
            // Prefer timeslot_info_str from Python output as it's pre-formatted
            $time_display_val = 'N/A';
            $time_info_from_python = $event['timeslot_info_str'] ?? null;
            if ($time_info_from_python) {
                 if (strpos($time_info_from_python, "(") !== false && strpos($time_info_from_python, ")") !== false) {
                    list($_, $time_range_py_val) = explode(" (", rtrim($time_info_from_python, ")"), 2);
                    $time_display_val = htmlspecialchars($time_range_py_val);
                 } else { // Fallback if format is unexpected
                    $time_display_val = htmlspecialchars($time_info_from_python);
                 }
            } elseif ($ts_info_current) {
                $start_formatted = format_time_for_display($ts_info_current['StartTime']);
                $end_formatted = format_time_for_display($ts_info_current['EndTime']);
                if ($start_formatted !== 'Invalid Time' && $end_formatted !== 'Invalid Time') {
                    $time_display_val = htmlspecialchars($start_formatted . ' - ' . $end_formatted);
                }
            }
            $num_students_display_val = htmlspecialchars($event['num_students'] ?? 'N/A');

            $html_output .= "<tr>";
            $html_output .= "<td>" . htmlspecialchars($course_id_val) . "</td>";
            $html_output .= "<td>" . $course_name_display_val . "</td>";
            $html_output .= "<td>" . $lecturer_name_display_val . "</td>";
            $html_output .= "<td>" . $classroom_code_display_val . "</td>";
            $html_output .= "<td>" . $day_display_val . "</td>";
            $html_output .= "<td>" . $time_display_val . "</td>";
            $html_output .= "<td>" . $num_students_display_val . "</td>";
            $html_output .= "</tr>";
        }
        $html_output .= '</tbody></table></div>';
        echo $html_output;

    } elseif ($view_type === 'weekly_visual_html') {
        // --- Logic for Weekly Visual View ---
        $days_of_week_ordered = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday']; // Sunday can be added if needed
        $unique_time_periods_ordered = []; // Store unique StartTime-EndTime pairs, ordered

        $slots_by_day_and_time = [];
        foreach ($schedule_events as $event) {
            $ts_id = $event['timeslot_id_db'] ?? null;
            if ($ts_id && isset($timeslot_details[$ts_id])) {
                $ts_data = $timeslot_details[$ts_id];
                $day = $ts_data['DayOfWeek'];
                $time_key = format_time_for_display($ts_data['StartTime']) . ' - ' . format_time_for_display($ts_data['EndTime']);
                
                if (!in_array($time_key, $unique_time_periods_ordered)) {
                    $unique_time_periods_ordered[] = $time_key;
                }
                $slots_by_day_and_time[$day][$time_key][] = $event;
            }
        }
        // Sort unique time periods
        usort($unique_time_periods_ordered, function($a, $b) {
            $start_a = substr($a, 0, strpos($a, ' - '));
            $start_b = substr($b, 0, strpos($b, ' - '));
            return strcmp($start_a, $start_b);
        });

        $html_weekly = '<div class="table-responsive schedule-visual-weekly"><table class="table table-bordered text-center">';
        $html_weekly .= '<thead><tr><th>Time</th>';
        foreach ($days_of_week_ordered as $day_header) {
            $html_weekly .= '<th>' . htmlspecialchars($day_header) . '</th>';
        }
        $html_weekly .= '</tr></thead><tbody>';

        if (empty($unique_time_periods_ordered)) {
            $html_weekly .= '<tr><td colspan="' . (count($days_of_week_ordered) + 1) . '" class="text-muted p-3">No time slots to display in weekly view.</td></tr>';
        } else {
            foreach ($unique_time_periods_ordered as $time_period_display) {
                $html_weekly .= '<tr><td class="schedule-time-cell"><strong>' . htmlspecialchars($time_period_display) . '</strong></td>';
                foreach ($days_of_week_ordered as $current_day_loop) {
                    $html_weekly .= '<td class="schedule-slot">';
                    if (isset($slots_by_day_and_time[$current_day_loop][$time_period_display])) {
                        foreach ($slots_by_day_and_time[$current_day_loop][$time_period_display] as $evt) {
                            $course_id_evt = $evt['course_id_str'] ?? 'N/A';
                            $course_name_evt = htmlspecialchars($evt['course_name'] ?? ($course_names[$course_id_evt] ?? $course_id_evt));
                            $lecturer_name_evt = htmlspecialchars($lecturer_names[$evt['lecturer_id_db']] ?? ($evt['lecturer_name'] ?? 'Unknown'));
                            $room_code_evt = htmlspecialchars($classroom_codes[$evt['classroom_id_db']] ?? ($evt['room_code'] ?? 'N/A'));
                            
                            $html_weekly .= "<div class='schedule-event mb-1 p-1 rounded bg-info-light border-info-light text-info-dark'>"; // Bootstrap color classes
                            $html_weekly .= "<strong class='event-course d-block'>" . $course_name_evt . "</strong>";
                            $html_weekly .= "<small class='event-lecturer d-block'><i class='fas fa-chalkboard-teacher me-1'></i>" . $lecturer_name_evt . "</small>";
                            $html_weekly .= "<small class='event-room d-block'><i class='fas fa-map-marker-alt me-1'></i>" . $room_code_evt . "</small>";
                            $html_weekly .= "</div>";
                        }
                    }
                    $html_weekly .= '</td>';
                }
                $html_weekly .= '</tr>';
            }
        }
        $html_weekly .= '</tbody></table></div>';
        echo $html_weekly;

    } elseif ($view_type === 'daily_visual_html') {
        // --- Logic for Daily List View ---
        $events_grouped_by_day = [];
        foreach ($schedule_events as $event) {
            $ts_id = $event['timeslot_id_db'] ?? null;
            if ($ts_id && isset($timeslot_details[$ts_id])) {
                $events_grouped_by_day[$timeslot_details[$ts_id]['DayOfWeek']][] = $event;
            }
        }
        // Order days for consistent display
        $ordered_day_keys = array_intersect_key(['Monday'=>1, 'Tuesday'=>1, 'Wednesday'=>1, 'Thursday'=>1, 'Friday'=>1, 'Saturday'=>1, 'Sunday'=>1], $events_grouped_by_day);


        if (empty($events_grouped_by_day)) {
             echo "<div class='text-center text-muted p-3'>No events to display in daily list view.</div>";
        } else {
            $html_daily = '<div class="row">';
            foreach (array_keys($ordered_day_keys) as $day_name_key) {
                if (!isset($events_grouped_by_day[$day_name_key])) continue;

                $html_daily .= '<div class="col-md-6 col-lg-4 mb-4 daily-schedule-card">';
                $html_daily .= '<div class="card h-100 shadow-sm">';
                $html_daily .= '<div class="card-header bg-primary text-white"><h5>' . htmlspecialchars($day_name_key) . '</h5></div>';
                $html_daily .= '<ul class="list-group list-group-flush">';

                foreach ($events_grouped_by_day[$day_name_key] as $event_daily) {
                    $course_id_daily = $event_daily['course_id_str'] ?? 'N/A';
                    $course_name_daily = htmlspecialchars($event_daily['course_name'] ?? ($course_names[$course_id_daily] ?? $course_id_daily));
                    $lecturer_name_daily = htmlspecialchars($lecturer_names[$event_daily['lecturer_id_db']] ?? ($event_daily['lecturer_name'] ?? 'Unknown'));
                    $room_code_daily = htmlspecialchars($classroom_codes[$event_daily['classroom_id_db']] ?? ($event_daily['room_code'] ?? 'N/A'));
                    
                    $time_display_daily = 'N/A';
                    $time_info_from_python_daily = $event_daily['timeslot_info_str'] ?? null;
                     if ($time_info_from_python_daily) {
                        if (strpos($time_info_from_python_daily, "(") !== false && strpos($time_info_from_python_daily, ")") !== false) {
                            list($_, $time_range_py_daily) = explode(" (", rtrim($time_info_from_python_daily, ")"), 2);
                            $time_display_daily = htmlspecialchars($time_range_py_daily);
                        } else { $time_display_daily = htmlspecialchars($time_info_from_python_daily); }
                    } elseif (isset($timeslot_details[$event_daily['timeslot_id_db']])) {
                        $ts_info_daily = $timeslot_details[$event_daily['timeslot_id_db']];
                        $start_fmt_daily = format_time_for_display($ts_info_daily['StartTime']);
                        $end_fmt_daily = format_time_for_display($ts_info_daily['EndTime']);
                        if ($start_fmt_daily !== 'Invalid Time' && $end_fmt_daily !== 'Invalid Time') {
                            $time_display_daily = htmlspecialchars($start_fmt_daily . ' - ' . $end_fmt_daily);
                        }
                    }

                    $html_daily .= '<li class="list-group-item">';
                    $html_daily .= '<h6 class="mb-1">' . $course_name_daily . ' <small class="text-muted">(' . htmlspecialchars($course_id_daily) . ')</small></h6>';
                    $html_daily .= '<p class="mb-1"><small><i class="fas fa-clock me-1 text-info"></i> ' . $time_display_daily . '</small></p>';
                    $html_daily .= '<p class="mb-1"><small><i class="fas fa-chalkboard-teacher me-1 text-success"></i> ' . $lecturer_name_daily . '</small></p>';
                    $html_daily .= '<p class="mb-0"><small><i class="fas fa-map-marker-alt me-1 text-danger"></i> ' . $room_code_daily . '</small></p>';
                    $html_daily .= '</li>';
                }
                $html_daily .= '</ul></div></div>'; 
            }
            $html_daily .= '</div>'; 
            echo $html_daily;
        }
    } else {
        http_response_code(400); // Bad Request
        echo "<p class='text-danger p-3'>Error: Invalid view type requested.</p>";
    }

} else {
    http_response_code(405); // Method Not Allowed
    echo "<p class='text-danger p-3'>Error: Invalid request method. Only POST is accepted.</p>";
}
?>