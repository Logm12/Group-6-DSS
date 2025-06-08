<?php
// htdocs/DSS/student/render_student_schedule_options_ajax.php

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db_connect.php';

if (!is_logged_in() || get_current_user_role() !== 'student') {
    http_response_code(403);
    echo "<p class='text-danger p-3'>Error: Unauthorized access.</p>";
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input_data = json_decode(file_get_contents('php://input'), true);
    // Expects 'schedule_options' as an array of schedules
    // Each schedule is an array of event objects
    $schedule_options_from_js = $input_data['schedule_options'] ?? [];
    $requested_view_type = $input_data['view_type'] ?? 'table_html_options'; // Default view
    // option_metrics should be an array parallel to schedule_options, containing metrics for each option
    $option_metrics_from_js = $input_data['option_metrics'] ?? [];


    if (empty($schedule_options_from_js) || !is_array($schedule_options_from_js)) {
        echo "<p class='text-muted p-3 text-center'>No schedule options provided to display.</p>";
        exit;
    }

    $all_lecturer_ids_in_options = [];
    $all_classroom_ids_in_options = [];
    $all_timeslot_ids_in_options = [];
    $all_course_ids_in_options = [];

    foreach ($schedule_options_from_js as $single_schedule_option) {
        if (is_array($single_schedule_option)) {
            $all_lecturer_ids_in_options = array_merge($all_lecturer_ids_in_options, array_column($single_schedule_option, 'lecturer_id_db'));
            $all_classroom_ids_in_options = array_merge($all_classroom_ids_in_options, array_column($single_schedule_option, 'classroom_id_db'));
            $all_timeslot_ids_in_options = array_merge($all_timeslot_ids_in_options, array_column($single_schedule_option, 'timeslot_id_db'));
            $all_course_ids_in_options = array_merge($all_course_ids_in_options, array_column($single_schedule_option, 'course_id_str'));
        }
    }

    $lecturer_names_map = []; $classroom_codes_map = []; $timeslot_details_map = []; $course_names_map = [];

    if ($conn) {
        $unique_lect_ids = array_filter(array_unique($all_lecturer_ids_in_options));
        if (!empty($unique_lect_ids)) {
            $lect_ids_in_sql = implode(',', array_map('intval', $unique_lect_ids));
            $res_l = $conn->query("SELECT LecturerID, LecturerName FROM Lecturers WHERE LecturerID IN ($lect_ids_in_sql)");
            if($res_l) while($r = $res_l->fetch_assoc()) $lecturer_names_map[$r['LecturerID']] = $r['LecturerName'];
        }

        $unique_room_ids = array_filter(array_unique($all_classroom_ids_in_options));
        if(!empty($unique_room_ids)){
            $room_ids_in_sql = implode(',', array_map('intval', $unique_room_ids));
            $res_r = $conn->query("SELECT ClassroomID, RoomCode FROM Classrooms WHERE ClassroomID IN ($room_ids_in_sql)");
            if($res_r) while($r = $res_r->fetch_assoc()) $classroom_codes_map[$r['ClassroomID']] = $r['RoomCode'];
        }

        $unique_ts_ids = array_filter(array_unique($all_timeslot_ids_in_options));
         if(!empty($unique_ts_ids)){
            $ts_ids_in_sql = implode(',', array_map('intval', $unique_ts_ids));
            $res_t = $conn->query("SELECT TimeSlotID, DayOfWeek, StartTime, EndTime FROM TimeSlots WHERE TimeSlotID IN ($ts_ids_in_sql)");
            if($res_t) while($r = $res_t->fetch_assoc()) $timeslot_details_map[$r['TimeSlotID']] = $r;
        }

        $unique_course_ids = array_filter(array_unique($all_course_ids_in_options));
        if(!empty($unique_course_ids)){
            $course_ids_placeholders_sql = implode(',', array_fill(0, count($unique_course_ids), '?'));
            $stmt_c = $conn->prepare("SELECT CourseID, CourseName FROM Courses WHERE CourseID IN ($course_ids_placeholders_sql)");
            if ($stmt_c) {
                $types_c = str_repeat('s', count($unique_course_ids));
                $stmt_c->bind_param($types_c, ...$unique_course_ids);
                $stmt_c->execute();
                $res_c = $stmt_c->get_result();
                while($r = $res_c->fetch_assoc()) $course_names_map[$r['CourseID']] = $r['CourseName'];
                $stmt_c->close();
            } else {
                 error_log("render_student_schedule_options_ajax: Failed to prepare statement for course names: " . $conn->error);
            }
        }
    } else {
        echo "<p class='text-danger p-3'>Error: Database connection not available for rendering.</p>";
        exit;
    }

    $html_output_all_options = "";

    foreach ($schedule_options_from_js as $option_idx => $single_schedule_option_events) {
        if (!is_array($single_schedule_option_events)) continue;

        usort($single_schedule_option_events, function($a, $b) use ($timeslot_details_map) {
            $ts_a_id = $a['timeslot_id_db'] ?? null; $ts_b_id = $b['timeslot_id_db'] ?? null;
            if ($ts_a_id === null || $ts_b_id === null || !isset($timeslot_details_map[$ts_a_id]) || !isset($timeslot_details_map[$ts_b_id])) return 0;
            $ts_a_data = $timeslot_details_map[$ts_a_id]; $ts_b_data = $timeslot_details_map[$ts_b_id];
            $day_order = ['Monday'=>1, 'Tuesday'=>2, 'Wednesday'=>3, 'Thursday'=>4, 'Friday'=>5, 'Saturday'=>6, 'Sunday'=>7];
            $day_comp = ($day_order[$ts_a_data['DayOfWeek']] ?? 99) <=> ($day_order[$ts_b_data['DayOfWeek']] ?? 99);
            if ($day_comp !== 0) return $day_comp;
            return strcmp($ts_a_data['StartTime'], $ts_b_data['StartTime']);
        });

        $current_option_metrics = $option_metrics_from_js[$option_idx] ?? [];
        $option_penalty_score = $current_option_metrics['final_penalty_score'] ?? 'N/A';
        $option_is_recommended = $current_option_metrics['is_recommended'] ?? false;

        $html_output_all_options .= "<div class='schedule-option-wrapper border rounded p-3 mb-4 shadow-sm " . ($option_is_recommended ? "border-success recommended-option" : "") . "'>";
        $html_output_all_options .= "<h4 class='mb-3 d-flex justify-content-between align-items-center'>";
        $html_output_all_options .= "Schedule Option " . ($option_idx + 1);
        if ($option_is_recommended) {
            $html_output_all_options .= " <span class='badge bg-success-subtle text-success-emphasis rounded-pill'><i class='fas fa-star me-1'></i> Recommended</span>";
        }
        // Button to select this specific schedule option
        $html_output_all_options .= "<button class='btn btn-sm " . ($option_is_recommended ? "btn-success" : "btn-outline-primary") . " select-schedule-option-btn' data-option-index='" . $option_idx . "'><i class='fas fa-check-circle me-1'></i> Select This Schedule</button>";
        $html_output_all_options .= "</h4>";
        if ($option_penalty_score !== 'N/A') {
            $html_output_all_options .= "<p class='text-muted small'>Quality Score (Lower is better): " . htmlspecialchars(number_format((float)$option_penalty_score, 2)) . "</p>";
        }


        if ($requested_view_type === 'table_html_options') {
            $html_output_all_options .= '<div class="table-responsive mb-3"><table class="table table-sm table-bordered table-striped schedule-table-student-option">';
            $html_output_all_options .= '<thead class="table-light"><tr><th>Course</th><th>Instructor</th><th>Room</th><th>Day</th><th>Time</th></tr></thead><tbody>';
            foreach ($single_schedule_option_events as $event) {
                $course_id_item = $event['course_id_str'] ?? 'N/A';
                $course_name_item = htmlspecialchars($event['course_name'] ?? ($course_names_map[$course_id_item] ?? $course_id_item));
                $lecturer_item = htmlspecialchars($lecturer_names_map[$event['lecturer_id_db']] ?? ($event['lecturer_name'] ?? 'N/A'));
                $room_item = htmlspecialchars($classroom_codes_map[$event['classroom_id_db']] ?? ($event['room_code'] ?? 'N/A'));
                $ts_info_item = $timeslot_details_map[$event['timeslot_id_db']] ?? null;
                $day_item = $ts_info_item ? htmlspecialchars($ts_info_item['DayOfWeek']) : 'N/A';
                $time_item = 'N/A';
                if (isset($event['timeslot_info_str']) && !empty($event['timeslot_info_str'])) {
                     // Assumes format like "Day (StartTime-EndTime)"
                    if (preg_match('/\(([^)]+)\)/', $event['timeslot_info_str'], $matches)) {
                        $time_item = htmlspecialchars($matches[1]);
                    } else {
                        $time_item = htmlspecialchars($event['timeslot_info_str']); // Fallback
                    }
                } elseif ($ts_info_item) {
                    $time_item = htmlspecialchars(format_time_for_display($ts_info_item['StartTime']) . ' - ' . format_time_for_display($ts_info_item['EndTime']));
                }

                $html_output_all_options .= "<tr>";
                $html_output_all_options .= "<td><strong>" . $course_name_item . "</strong><br><small class='text-muted'>" . htmlspecialchars($course_id_item) . "</small></td>";
                $html_output_all_options .= "<td>" . $lecturer_item . "</td>";
                $html_output_all_options .= "<td>" . $room_item . "</td>";
                $html_output_all_options .= "<td>" . $day_item . "</td>";
                $html_output_all_options .= "<td>" . $time_item . "</td>";
                $html_output_all_options .= "</tr>";
            }
            $html_output_all_options .= '</tbody></table></div>';
        } elseif ($requested_view_type === 'weekly_visual_options') {
            $days_ordered_weekly = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            $unique_time_periods_weekly = [];
            $events_by_day_time_weekly = [];

            foreach ($single_schedule_option_events as $event_weekly) {
                $ts_id_w = $event_weekly['timeslot_id_db'] ?? null;
                if ($ts_id_w && isset($timeslot_details_map[$ts_id_w])) {
                    $ts_data_w = $timeslot_details_map[$ts_id_w];
                    $day_w = $ts_data_w['DayOfWeek'];
                    $time_key_w = format_time_for_display($ts_data_w['StartTime']) . ' - ' . format_time_for_display($ts_data_w['EndTime']);
                    if (!in_array($time_key_w, $unique_time_periods_weekly)) $unique_time_periods_weekly[] = $time_key_w;
                    $events_by_day_time_weekly[$day_w][$time_key_w][] = $event_weekly;
                }
            }
            usort($unique_time_periods_weekly, function($a_tp, $b_tp){ return strcmp(substr($a_tp,0,5), substr($b_tp,0,5)); });

            $html_output_all_options .= '<div class="table-responsive schedule-visual-weekly mb-3"><table class="table table-bordered text-center">';
            $html_output_all_options .= '<thead><tr><th style="width:12%;">Time</th>';
            foreach ($days_ordered_weekly as $day_h_w) $html_output_all_options .= '<th>' . htmlspecialchars($day_h_w) . '</th>';
            $html_output_all_options .= '</tr></thead><tbody>';

            if (empty($unique_time_periods_weekly)) {
                $html_output_all_options .= '<tr><td colspan="' . (count($days_ordered_weekly) + 1) . '" class="text-muted p-3">No schedule data for this option.</td></tr>';
            } else {
                foreach ($unique_time_periods_weekly as $time_p_w) {
                    $html_output_all_options .= '<tr><td class="schedule-time-cell"><strong>' . htmlspecialchars($time_p_w) . '</strong></td>';
                    foreach ($days_ordered_weekly as $day_curr_w) {
                        $html_output_all_options .= '<td class="schedule-slot">';
                        if (isset($events_by_day_time_weekly[$day_curr_w][$time_p_w])) {
                            foreach ($events_by_day_time_weekly[$day_curr_w][$time_p_w] as $evt_w) {
                                $course_id_ew = $evt_w['course_id_str'] ?? 'N/A';
                                $course_name_ew = htmlspecialchars($evt_w['course_name'] ?? ($course_names_map[$course_id_ew] ?? $course_id_ew));
                                $lecturer_ew = htmlspecialchars($lecturer_names_map[$evt_w['lecturer_id_db']] ?? ($evt_w['lecturer_name'] ?? ''));
                                $room_ew = htmlspecialchars($classroom_codes_map[$evt_w['classroom_id_db']] ?? ($evt_w['room_code'] ?? ''));

                                $html_output_all_options .= "<div class='schedule-event schedule-event-option p-1 mb-1 rounded border' title='" . htmlspecialchars("{$course_name_ew}\nLecturer: {$lecturer_ew}\nRoom: {$room_ew}") . "'>";
                                $html_output_all_options .= "<strong class='event-course d-block'>" . $course_name_ew . "</strong>";
                                if($lecturer_ew) $html_output_all_options .= "<small class='event-lecturer d-block'><i class='fas fa-user-tie fa-fw me-1'></i>" . $lecturer_ew . "</small>";
                                if($room_ew) $html_output_all_options .= "<small class='event-room d-block'><i class='fas fa-map-marker-alt fa-fw me-1'></i>" . $room_ew . "</small>";
                                $html_output_all_options .= "</div>";
                            }
                        }
                        $html_output_all_options .= '</td>';
                    }
                    $html_output_all_options .= '</tr>';
                }
            }
            $html_output_all_options .= '</tbody></table></div>';
        }
        $html_output_all_options .= "</div>"; // End .schedule-option-wrapper
    }
    echo $html_output_all_options;

} else {
    http_response_code(405);
    echo "<p class='text-danger p-3'>Error: Invalid request method.</p>";
}
?>