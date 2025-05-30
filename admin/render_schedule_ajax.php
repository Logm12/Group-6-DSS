<?php
// htdocs/DSS/admin/render_schedule_ajax.php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db_connect.php';

if (!is_logged_in() || get_current_user_role() !== 'admin') {
    http_response_code(403);
    echo "<p class='text-danger'>Unauthorized access.</p>";
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $schedule_events = $input['schedule_events'] ?? [];

    // Lấy thông tin cần thiết để hiển thị (tên giảng viên, tên phòng, thông tin timeslot)
    $lecturer_names = []; $classroom_codes = []; $timeslot_details = []; $course_names = [];

    // Chỉ thực hiện truy vấn nếu có dữ liệu lịch trình
    if (!empty($schedule_events)) {
        $all_lecturer_ids = array_filter(array_unique(array_column($schedule_events, 'lecturer_id_db')));
        $all_classroom_ids = array_filter(array_unique(array_column($schedule_events, 'classroom_id_db')));
        $all_timeslot_ids = array_filter(array_unique(array_column($schedule_events, 'timeslot_id_db')));
        $all_course_ids = array_filter(array_unique(array_column($schedule_events, 'course_id_str')));

        if (!empty($all_lecturer_ids)) {
            $lect_ids_str = implode(',', array_map('intval', $all_lecturer_ids));
            $res_lect = $conn->query("SELECT LecturerID, LecturerName FROM Lecturers WHERE LecturerID IN ($lect_ids_str)");
            if($res_lect) while($row = $res_lect->fetch_assoc()) $lecturer_names[$row['LecturerID']] = $row['LecturerName'];
        }
        if (!empty($all_classroom_ids)) {
            $room_ids_str = implode(',', array_map('intval', $all_classroom_ids));
            $res_room = $conn->query("SELECT ClassroomID, RoomCode FROM Classrooms WHERE ClassroomID IN ($room_ids_str)");
            if($res_room) while($row = $res_room->fetch_assoc()) $classroom_codes[$row['ClassroomID']] = $row['RoomCode'];
        }
        if (!empty($all_timeslot_ids)) {
            $ts_ids_str = implode(',', array_map('intval', $all_timeslot_ids));
            $res_ts = $conn->query("SELECT TimeSlotID, DayOfWeek, StartTime, EndTime FROM TimeSlots WHERE TimeSlotID IN ($ts_ids_str)");
            if($res_ts) while($row = $res_ts->fetch_assoc()) $timeslot_details[$row['TimeSlotID']] = $row;
        }
        if(!empty($all_course_ids)){
            $course_ids_placeholders = implode(',', array_fill(0, count($all_course_ids), '?'));
            $stmt_courses = $conn->prepare("SELECT CourseID, CourseName FROM Courses WHERE CourseID IN ($course_ids_placeholders)");
            if ($stmt_courses) {
                $types_courses = str_repeat('s', count($all_course_ids));
                $stmt_courses->bind_param($types_courses, ...$all_course_ids);
                $stmt_courses->execute();
                $res_courses = $stmt_courses->get_result();
                while($row = $res_courses->fetch_assoc()) $course_names[$row['CourseID']] = $row['CourseName'];
                $stmt_courses->close();
            }
        }

        // Sắp xếp lịch trình theo timeslot (ngày và giờ)
        usort($schedule_events, function($a, $b) use ($timeslot_details) {
            $ts_a_id = $a['timeslot_id_db'] ?? null;
            $ts_b_id = $b['timeslot_id_db'] ?? null;

            if ($ts_a_id === null || $ts_b_id === null || !isset($timeslot_details[$ts_a_id]) || !isset($timeslot_details[$ts_b_id])) return 0;
            
            $ts_a = $timeslot_details[$ts_a_id];
            $ts_b = $timeslot_details[$ts_b_id];

            $day_order = ['Monday' => 1, 'Tuesday' => 2, 'Wednesday' => 3, 'Thursday' => 4, 'Friday' => 5, 'Saturday' => 6, 'Sunday' => 7];
            $day_compare = ($day_order[$ts_a['DayOfWeek']] ?? 99) <=> ($day_order[$ts_b['DayOfWeek']] ?? 99);
            if ($day_compare !== 0) return $day_compare;
            return strcmp($ts_a['StartTime'], $ts_b['StartTime']);
        });
    }
    
    if (empty($schedule_events)) {
        echo "<p class='text-muted'>No schedule data to display or an empty schedule was generated.</p>";
        exit;
    }

    $html = '<div class="table-responsive"><table class="table table-bordered table-striped table-hover schedule-table">';
    $html .= '<thead class="table-dark"><tr><th>Course Code</th><th>Course Name</th><th>Instructor</th><th>Classroom</th><th>Day</th><th>Time</th><th>Students</th></tr></thead><tbody>';

    foreach ($schedule_events as $event) {
        $course_id = $event['course_id_str'] ?? 'N/A';
        $course_name_display = $course_names[$course_id] ?? $event['course_name'] ?? 'N/A'; // 'course_name' từ Python output
        $lecturer_name_display = $lecturer_names[$event['lecturer_id_db']] ?? 'N/A';
        $classroom_code_display = $classroom_codes[$event['classroom_id_db']] ?? 'N/A';
        $ts_info = $timeslot_details[$event['timeslot_id_db']] ?? null;
        $day_display = $ts_info ? htmlspecialchars($ts_info['DayOfWeek']) : 'N/A';
        // $time_display = $ts_info ? htmlspecialchars(format_time_for_display($ts_info['StartTime']) . ' - ' . format_time_for_display($ts_info['EndTime'])) : 'N/A';
        // timeslot_info_str đã có từ Python, dùng luôn cho tiện
        $time_display_from_python = $event['timeslot_info_str'] ?? null;
        if ($time_display_from_python) {
             list($day_py, $time_range_py) = explode(" (", rtrim($time_display_from_python, ")"), 2);
             $time_display = htmlspecialchars($time_range_py);
        } else if ($ts_info) {
            $time_display = htmlspecialchars(format_time_for_display($ts_info['StartTime']) . ' - ' . format_time_for_display($ts_info['EndTime']));
        } else {
            $time_display = 'N/A';
        }


        $num_students_display = htmlspecialchars($event['num_students'] ?? 'N/A');

        $html .= "<tr>";
        $html .= "<td>" . htmlspecialchars($course_id) . "</td>";
        $html .= "<td>" . htmlspecialchars($course_name_display) . "</td>";
        $html .= "<td>" . htmlspecialchars($lecturer_name_display) . "</td>";
        $html .= "<td>" . htmlspecialchars($classroom_code_display) . "</td>";
        $html .= "<td>" . $day_display . "</td>";
        $html .= "<td>" . $time_display . "</td>";
        $html .= "<td>" . $num_students_display . "</td>";
        $html .= "</tr>";
    }
    $html .= '</tbody></table></div>';
    echo $html;
} else {
    http_response_code(405); // Method Not Allowed
    echo "<p class='text-danger'>Invalid request method.</p>";
}
?>