<?php
// htdocs/DSS/student/my_schedule.php

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db_connect.php'; // $conn

require_role(['student'], '../login.php');

$page_specific_title = "My Schedule";

$current_student_id_page = get_current_user_linked_entity_id();

$selected_semester_id = null;
$semesters_list = [];
$schedule_events_json = '[]';
$current_semester_details_for_calendar = null;
$schedule_source_message = ""; // To inform user where the schedule is from

if (isset($conn) && $conn instanceof mysqli) {
    // Get list of semesters for the dropdown selector
    $result_semesters_list = $conn->query("SELECT SemesterID, SemesterName, StartDate, EndDate FROM Semesters ORDER BY StartDate DESC");
    if ($result_semesters_list) {
        while ($row_sem = $result_semesters_list->fetch_assoc()) {
            $semesters_list[] = $row_sem;
        }
        $result_semesters_list->free();
    } else {
        error_log("MySchedulePage: Failed to fetch semesters list: " . $conn->error);
    }

    // Determine the selected semester
    if (isset($_GET['semester_id']) && filter_var($_GET['semester_id'], FILTER_VALIDATE_INT)) {
        $selected_semester_id = (int)$_GET['semester_id'];
    } elseif (!empty($semesters_list)) {
        $today_date_for_default_sem = date('Y-m-d');
        foreach ($semesters_list as $semester_item) {
            if ($today_date_for_default_sem >= $semester_item['StartDate'] && $today_date_for_default_sem <= $semester_item['EndDate']) {
                $selected_semester_id = (int)$semester_item['SemesterID'];
                break;
            }
        }
        if (!$selected_semester_id && isset($semesters_list[0]['SemesterID'])) {
            $selected_semester_id = (int)$semesters_list[0]['SemesterID'];
        }
    }

    // Fetch details of the selected semester for date iteration
    if ($selected_semester_id) {
        foreach ($semesters_list as $s_detail_item) {
            if ($s_detail_item['SemesterID'] == $selected_semester_id) {
                $current_semester_details_for_calendar = $s_detail_item;
                break;
            }
        }
    }

    // Fetch schedule data if a student and semester are selected
    if ($current_student_id_page && $selected_semester_id && $current_semester_details_for_calendar) {
        $events_for_calendar_array = [];

        // --- START: MODIFIED LOGIC TO FETCH SCHEDULE ---
        // 1. Try to fetch the student's active personal schedule for the selected semester
        $stmt_personal_schedule = $conn->prepare(
            "SELECT ScheduleData, ScheduleName 
             FROM StudentPersonalSchedules 
             WHERE StudentID = ? AND SemesterID = ? AND IsActive = 1 
             LIMIT 1"
        );
        if ($stmt_personal_schedule) {
            $stmt_personal_schedule->bind_param("si", $current_student_id_page, $selected_semester_id);
            if ($stmt_personal_schedule->execute()) {
                $result_personal_schedule = $stmt_personal_schedule->get_result();
                if ($personal_schedule_row = $result_personal_schedule->fetch_assoc()) {
                    $schedule_data_json_from_db = $personal_schedule_row['ScheduleData'];
                    $personal_schedule_name = $personal_schedule_row['ScheduleName'];
                    $decoded_personal_schedule_events = json_decode($schedule_data_json_from_db, true);

                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded_personal_schedule_events)) {
                        // Successfully decoded personal schedule. Now transform it for FullCalendar.
                        // The structure in ScheduleData is assumed to be an array of event objects
                        // similar to what Python outputs for 'final_schedule'.
                        // Each event should have: course_id_str, lecturer_id_db, classroom_id_db, timeslot_id_db, course_name, lecturer_name, room_code, timeslot_info_str
                        
                        // We need to fetch DayOfWeek, StartTime, EndTime from TimeSlots table based on timeslot_id_db
                        // and other details if not directly in ScheduleData
                        $all_timeslot_ids_from_personal = array_unique(array_column($decoded_personal_schedule_events, 'timeslot_id_db'));
                        $timeslot_details_map_personal = [];
                        if (!empty($all_timeslot_ids_from_personal)) {
                            $ts_ids_in = implode(',', array_map('intval', $all_timeslot_ids_from_personal));
                            $res_ts_personal = $conn->query("SELECT TimeSlotID, DayOfWeek, StartTime, EndTime FROM TimeSlots WHERE TimeSlotID IN ($ts_ids_in)");
                            if ($res_ts_personal) {
                                while($r_ts = $res_ts_personal->fetch_assoc()) $timeslot_details_map_personal[$r_ts['TimeSlotID']] = $r_ts;
                                $res_ts_personal->free();
                            }
                        }

                        try {
                            $semester_start_date_obj = new DateTime($current_semester_details_for_calendar['StartDate']);
                            $semester_end_date_obj = new DateTime($current_semester_details_for_calendar['EndDate']);
                            $semester_end_date_obj->modify('+1 day');
                            $date_interval_one_day = new DateInterval('P1D');
                            $semester_date_period = new DatePeriod($semester_start_date_obj, $date_interval_one_day, $semester_end_date_obj);

                            foreach ($decoded_personal_schedule_events as $event_data) {
                                $ts_id = $event_data['timeslot_id_db'] ?? null;
                                if ($ts_id && isset($timeslot_details_map_personal[$ts_id])) {
                                    $ts_info = $timeslot_details_map_personal[$ts_id];
                                    $day_name_from_event = $ts_info['DayOfWeek'];

                                    foreach ($semester_date_period as $date_in_period) {
                                        if ($date_in_period->format('l') === $day_name_from_event) {
                                            $events_for_calendar_array[] = [
                                                'id' => ($event_data['schedule_db_id'] ?? uniqid('evt_')) . '_' . $date_in_period->format('Ymd'), // schedule_db_id might be temp ID like -1
                                                'title' => ($event_data['course_id_str'] ?? 'Unknown Course') . " - " . ($event_data['course_name'] ?? 'Unknown'),
                                                'start' => $date_in_period->format('Y-m-d') . ' ' . $ts_info['StartTime'],
                                                'end' => $date_in_period->format('Y-m-d') . ' ' . $ts_info['EndTime'],
                                                'extendedProps' => [
                                                    'lecturer' => $event_data['lecturer_name'] ?? 'N/A',
                                                    'room' => $event_data['room_code'] ?? 'N/A',
                                                    'course_code' => $event_data['course_id_str'] ?? 'N/A',
                                                    'full_course_name' => $event_data['course_name'] ?? 'N/A'
                                                ],
                                                // 'backgroundColor' => '#28a745', // Green for personal saved schedule
                                                // 'borderColor' => '#1e7e34'
                                            ];
                                        }
                                    }
                                }
                            }
                            $schedule_source_message = "Displaying your saved schedule: '" . htmlspecialchars($personal_schedule_name) . "'.";
                        } catch (Exception $e) {
                            error_log("MySchedulePage: Error processing personal schedule for FullCalendar: " . $e->getMessage());
                            $events_for_calendar_array = []; // Clear if error during processing
                        }
                    } else {
                        error_log("MySchedulePage: Failed to decode personal schedule JSON or not an array for StudentID: $current_student_id_page, SemesterID: $selected_semester_id. JSON Error: " . json_last_error_msg());
                    }
                }
                $result_personal_schedule->free();
            } else {
                error_log("MySchedulePage: Failed to execute personal schedule query: " . $stmt_personal_schedule->error);
            }
            $stmt_personal_schedule->close();
        } else {
            error_log("MySchedulePage: Failed to prepare personal schedule query: " . $conn->error);
        }
        // --- END: MODIFIED LOGIC TO FETCH SCHEDULE ---

        // 2. If no active personal schedule found, fallback to fetching from general ScheduledClasses based on enrollments
        if (empty($events_for_calendar_array)) {
            $schedule_source_message = "Displaying general schedule based on your enrollments. You can build and save a personal schedule via 'Register Courses'.";
            $sql_general_schedule_query = "SELECT sc.ScheduleID, c.CourseID, c.CourseName, l.LecturerName, cr.RoomCode,
                                          t.DayOfWeek, t.StartTime, t.EndTime
                                   FROM StudentEnrollments se
                                   JOIN Courses c ON se.CourseID = c.CourseID
                                   JOIN ScheduledClasses sc ON se.CourseID = sc.CourseID AND se.SemesterID = sc.SemesterID
                                   JOIN Lecturers l ON sc.LecturerID = l.LecturerID
                                   JOIN Classrooms cr ON sc.ClassroomID = cr.ClassroomID
                                   JOIN TimeSlots t ON sc.TimeSlotID = t.TimeSlotID
                                   WHERE se.StudentID = ? AND sc.SemesterID = ?
                                   ORDER BY FIELD(t.DayOfWeek, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'), t.StartTime";

            $stmt_general_schedule_query = $conn->prepare($sql_general_schedule_query);
            if ($stmt_general_schedule_query) {
                $stmt_general_schedule_query->bind_param("si", $current_student_id_page, $selected_semester_id);
                if ($stmt_general_schedule_query->execute()) {
                    $result_general_schedule_data = $stmt_general_schedule_query->get_result();
                    try {
                        $semester_start_date_obj = new DateTime($current_semester_details_for_calendar['StartDate']);
                        $semester_end_date_obj = new DateTime($current_semester_details_for_calendar['EndDate']);
                        $semester_end_date_obj->modify('+1 day');
                        $date_interval_one_day = new DateInterval('P1D');
                        $semester_date_period = new DatePeriod($semester_start_date_obj, $date_interval_one_day, $semester_end_date_obj);

                        while ($row_schedule_item = $result_general_schedule_data->fetch_assoc()) {
                            $day_name_from_db_event = $row_schedule_item['DayOfWeek'];
                            foreach ($semester_date_period as $date_in_period) {
                                if ($date_in_period->format('l') === $day_name_from_db_event) {
                                    $events_for_calendar_array[] = [
                                        'id' => $row_schedule_item['ScheduleID'] . '_' . $date_in_period->format('Ymd'),
                                        'title' => ($row_schedule_item['CourseID'] ?? 'N/A') . " - " . ($row_schedule_item['CourseName'] ?? 'N/A'),
                                        'start' => $date_in_period->format('Y-m-d') . ' ' . $row_schedule_item['StartTime'],
                                        'end' => $date_in_period->format('Y-m-d') . ' ' . $row_schedule_item['EndTime'],
                                        'extendedProps' => [
                                            'lecturer' => $row_schedule_item['LecturerName'] ?? 'N/A',
                                            'room' => $row_schedule_item['RoomCode'] ?? 'N/A',
                                            'course_code' => $row_schedule_item['CourseID'] ?? 'N/A',
                                            'full_course_name' => $row_schedule_item['CourseName'] ?? 'N/A'
                                        ]
                                    ];
                                }
                            }
                        }
                    } catch (Exception $e) {
                        error_log("MySchedulePage: Error processing general schedule for FullCalendar: " . $e->getMessage());
                        $events_for_calendar_array = []; // Clear if error
                    }
                    $result_general_schedule_data->free();
                } else { error_log("MySchedulePage: Failed to execute general schedule query: " . $stmt_general_schedule_query->error); }
                $stmt_general_schedule_query->close();
            } else { error_log("MySchedulePage: Failed to prepare general schedule query: " . $conn->error); }
        }

        // Final JSON encoding for FullCalendar
        $schedule_events_json = json_encode($events_for_calendar_array);
        if ($schedule_events_json === false) {
            error_log("MySchedulePage: Failed to JSON encode final schedule events. Error: " . json_last_error_msg());
            $schedule_events_json = '[]';
        }
    }
}
// --- END: PHP Logic for student/my_schedule.php (Data Fetching Part) ---
?>
<?php
// --- START: PHP Logic for Layout (can be moved to a shared student_layout.php if used by multiple student pages) ---
if (!function_exists('determine_page_title_from_menu_layout')) {
    function determine_page_title_from_menu_layout($items, $current_path, &$page_title_ref_layout) {
        foreach ($items as $item) {
            if (isset($item['submenu']) && is_array($item['submenu'])) {
                if (determine_page_title_from_menu_layout($item['submenu'], $current_path, $page_title_ref_layout)) return true;
            } elseif (isset($item['link']) && $current_path == $item['link']) {
                $page_title_ref_layout = $item['label']; return true;
            }
        }
        return false;
    }
}

if (!function_exists('render_menu_items_layout_recursive')) {
    function render_menu_items_layout_recursive($items_to_render, $current_rel_path_layout) {
        foreach ($items_to_render as $item_render_layout) {
            $has_submenu_render_layout = isset($item_render_layout['submenu']) && is_array($item_render_layout['submenu']) && !empty($item_render_layout['submenu']);
            $link_href_render_layout = $has_submenu_render_layout ? '#' : BASE_URL . htmlspecialchars($item_render_layout['link']);
            $is_item_active_render_layout = false; $is_parent_active_render_layout = false;

            if (!$has_submenu_render_layout && ($current_rel_path_layout == $item_render_layout['link'])) {
                $is_item_active_render_layout = true;
            } elseif ($has_submenu_render_layout) {
                foreach ($item_render_layout['submenu'] as $sub_item_render_layout) {
                    if ($current_rel_path_layout == $sub_item_render_layout['link']) { $is_parent_active_render_layout = true; break; }
                }
            }
            $link_classes_render_layout = "menu-link";
            if ($is_item_active_render_layout || ($has_submenu_render_layout && $is_parent_active_render_layout)) $link_classes_render_layout .= " active";
            if ($has_submenu_render_layout && !$is_parent_active_render_layout) $link_classes_render_layout .= " collapsed";
            ?>
            <li class="sidebar-menu-item <?php echo $has_submenu_render_layout ? 'has-submenu' : ''; ?>">
                <a href="<?php echo $link_href_render_layout; ?>" class="<?php echo $link_classes_render_layout; ?>"
                   <?php if ($has_submenu_render_layout): ?>
                       data-bs-toggle="collapse" data-bs-target="#<?php echo htmlspecialchars($item_render_layout['id'] ?? uniqid('submenu-layout-')); ?>"
                       aria-expanded="<?php echo $is_parent_active_render_layout ? 'true' : 'false'; ?>" aria-controls="<?php echo htmlspecialchars($item_render_layout['id'] ?? ''); ?>"
                   <?php endif; ?>>
                    <i class="menu-icon <?php echo htmlspecialchars($item_render_layout['icon']); ?>"></i>
                    <span><?php echo htmlspecialchars($item_render_layout['label']); ?></span>
                    <?php if ($has_submenu_render_layout): ?><i class="fas fa-chevron-down menu-arrow"></i><?php endif; ?>
                </a>
                <?php if ($has_submenu_render_layout): ?>
                    <ul class="sidebar-submenu collapse <?php echo $is_parent_active_render_layout ? 'show' : ''; ?>" id="<?php echo htmlspecialchars($item_render_layout['id'] ?? ''); ?>">
                        <?php render_menu_items_layout_recursive($item_render_layout['submenu'], $current_rel_path_layout); ?>
                    </ul>
                <?php endif; ?>
            </li>
            <?php
        }
    }
}

$user_fullname_layout = get_current_user_fullname();
$user_role_layout = get_current_user_role();
$user_avatar_placeholder_layout = BASE_URL . 'assets/images/default_avatar.png';

$current_page_filename_layout = basename($_SERVER['PHP_SELF']);
$current_script_path_layout = $_SERVER['SCRIPT_NAME'];
$path_parts_layout = explode('/', trim($current_script_path_layout, '/'));
$current_role_dir_layout = $path_parts_layout[count($path_parts_layout)-2] ?? '';
$current_relative_path_layout = $current_role_dir_layout . '/' . $current_page_filename_layout;

$menu_items_layout = [];
$page_title_layout = $page_specific_title ?? "Student Portal";

if ($user_role_layout === 'student') {
    $menu_items_layout = [
        ['label' => 'Dashboard', 'icon' => 'fas fa-tachometer-alt', 'link' => 'student/index.php', 'id' => 'student_dashboard_menu'],
        ['label' => 'My Schedule', 'icon' => 'fas fa-calendar-alt', 'link' => 'student/my_schedule.php', 'id' => 'student_schedule_menu'],
        ['label' => 'Register Courses', 'icon' => 'fas fa-edit', 'link' => 'student/course_registration.php', 'id' => 'student_registration_menu'],
    ];
    if (!isset($page_specific_title)) { // Only determine from menu if page hasn't set it
        determine_page_title_from_menu_layout($menu_items_layout, $current_relative_path_layout, $page_title_layout);
    }
}
// --- END: PHP Logic for Layout ---
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title_layout); ?> - Student Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <!-- Shared layout CSS and page-specific CSS will be in the <style> tag below -->
</head>
<body>
    <div class="sidebar" id="mainSidebar">
        <div class="sidebar-header">
            <a href="<?php echo BASE_URL . ($user_role_layout ? htmlspecialchars($user_role_layout) . '/index.php' : 'login.php'); ?>" class="logo">
                <i class="fas fa-university"></i> <span>UniDSS</span>
            </a>
        </div>
        <ul class="sidebar-menu">
            <?php if (!empty($menu_items_layout)): ?>
                <li class="sidebar-menu-item menu-title" style="padding: 10px 20px; font-size: 0.8rem; color: rgba(255,255,255,0.5); text-transform: uppercase;"><span>Navigation</span></li>
                <?php render_menu_items_layout_recursive($menu_items_layout, $current_relative_path_layout); ?>
            <?php endif; ?>
        </ul>
        <?php if ($user_fullname_layout): ?>
        <div class="sidebar-user-profile">
            <div class="d-flex align-items-center">
                <div class="user-avatar"> <img src="<?php echo htmlspecialchars($user_avatar_placeholder_layout); ?>" alt="User Avatar"> </div>
                <div class="user-info"><h6><?php echo htmlspecialchars($user_fullname_layout); ?></h6><p><?php echo htmlspecialchars(ucfirst($user_role_layout)); ?></p></div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="main-content-wrapper" id="mainContentWrapper">
        <nav class="topbar">
            <div class="topbar-left">
                 <button class="btn btn-light d-md-none me-2" type="button" id="sidebarToggleMobile" aria-label="Toggle sidebar"> <i class="fas fa-bars"></i> </button>
                 <button class="btn btn-light me-2 d-none d-md-inline-block" type="button" id="sidebarToggleDesktop" aria-label="Toggle sidebar desktop"> <i class="fas fa-bars"></i></button>
                <h4 class="page-title-h mb-0 d-none d-md-block"><?php echo htmlspecialchars($page_title_layout);?></h4>
            </div>
            <ul class="navbar-nav topbar-right flex-row align-items-center">
                 <?php if ($user_fullname_layout): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <span class="user-avatar-top me-2"> <img src="<?php echo htmlspecialchars($user_avatar_placeholder_layout); ?>" alt="User Avatar"> </span>
                        <span class="d-none d-sm-inline"><?php echo htmlspecialchars($user_fullname_layout); ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                        <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>logout.php"><i class="fas fa-sign-out-alt me-2"></i> Logout</a></li>
                    </ul>
                </li>
                <?php endif; ?>
            </ul>
        </nav>
        <main class="content-area" id="mainContentArea">
            <?php
            if (function_exists('display_all_flash_messages')) {
                echo display_all_flash_messages();
            }
            ?>

            <!-- START: Page-specific content for student/my_schedule.php -->
            <div class="container-fluid pt-3">
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
                    <div> <!-- Placeholder for title if moved from layout --> </div>
                    <form method="GET" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" class="d-flex align-items-center ms-md-auto">
                        <label for="semester_select" class="form-label me-2 mb-0 text-nowrap visually-hidden">Semester:</label>
                        <select name="semester_id" id="semester_select" class="form-select form-select-sm" style="min-width: 220px;" onchange="this.form.submit()" aria-label="Select semester">
                            <?php if (empty($semesters_list)): ?>
                                <option value="">No semesters available</option>
                            <?php else: ?>
                                <?php foreach ($semesters_list as $semester_item_select): ?>
                                    <option value="<?php echo $semester_item_select['SemesterID']; ?>" <?php if ($semester_item_select['SemesterID'] == $selected_semester_id) echo 'selected'; ?>>
                                        <?php echo htmlspecialchars($semester_item_select['SemesterName']); ?>
                                        (<?php echo htmlspecialchars(format_date_for_display($semester_item_select['StartDate'], 'd M Y')); ?> - <?php echo htmlspecialchars(format_date_for_display($semester_item_select['EndDate'], 'd M Y')); ?>)
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </form>
                </div>

                <?php if (!empty($schedule_source_message)): ?>
                    <div class="alert alert-primary small" role="alert">
                        <i class="fas fa-info-circle me-1"></i> <?php echo htmlspecialchars($schedule_source_message); ?>
                    </div>
                <?php endif; ?>

                <div class="card shadow mb-4">
                    <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                        <h6 class="m-0 font-weight-bold text-primary">My Weekly Schedule View</h6>
                    </div>
                    <div class="card-body">
                        <div id="calendarContainer">
                            <div id='calendar'>
                                <?php if (empty($schedule_events_json) || $schedule_events_json === '[]'): ?>
                                    <div class="alert alert-info text-center" role="alert" id="calendarPlaceholder">
                                        <i class="fas fa-calendar-times me-2"></i>
                                        No schedule data available to display for the selected semester.
                                        <?php if (empty($schedule_source_message) && $current_student_id_page && $selected_semester_id): ?>
                                            You can try building a personal schedule via the 'Register Courses' page.
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- END: Page-specific content -->
        </main>
    </div> <!-- End main-content-wrapper -->

    <div class="modal fade" id="eventDetailsModal" tabindex="-1" aria-labelledby="eventDetailsModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="eventDetailsModalLabel">Class Details</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <p><span class="detail-label">Course:</span> <span id="modalCourseName"></span> (<span id="modalCourseCode"></span>)</p>
            <p><span class="detail-label">Time:</span> <span id="modalTime"></span></p>
            <p><span class="detail-label">Day:</span> <span id="modalDay"></span></p>
            <p><span class="detail-label">Room:</span> <span id="modalRoom"></span></p>
            <p><span class="detail-label">Lecturer:</span> <span id="modalLecturer"></span></p>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
          </div>
        </div>
      </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src='<?php echo BASE_URL; ?>assets/fullcalendar/index.global.min.js'></script>
    <style>
        /* CSS from Part 3 of your previous submission */
        :root {
            --primary-blue: #005c9e;
            --sidebar-bg: #00406e;
            --sidebar-text-color: #e0e0e0;
            --sidebar-hover-bg: #006ac1;
            --sidebar-active-bg: #007bff;
            --sidebar-active-text-color: #ffffff;
            --topbar-bg: #ffffff;
            --topbar-border-color: #dee2e6;
            --content-bg: #f4f6f9;
            --sidebar-width: 260px;
        }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: var(--content-bg); margin: 0; padding: 0; display: flex; min-height: 100vh; overflow-x: hidden; }
        .sidebar { width: var(--sidebar-width); background-color: var(--sidebar-bg); color: var(--sidebar-text-color); padding-top: 0; position: fixed; top: 0; left: 0; bottom: 0; display: flex; flex-direction: column; transition: transform 0.3s ease; z-index: 1030; overflow-y: auto; }
        .sidebar.active { transform: translateX(0); box-shadow: 0 0 15px rgba(0,0,0,0.2); }
        .sidebar.collapsed-desktop { transform: translateX(calc(-1 * var(--sidebar-width) + 50px)); overflow: visible; }
        .sidebar.collapsed-desktop .sidebar-header .logo span,
        .sidebar.collapsed-desktop .sidebar-menu-item > .menu-link span,
        .sidebar.collapsed-desktop .sidebar-menu-item > .menu-link .menu-arrow,
        .sidebar.collapsed-desktop .sidebar-user-profile .user-info { display: none; }
        .sidebar.collapsed-desktop .sidebar-header .logo i,
        .sidebar.collapsed-desktop .sidebar-menu-item > .menu-link i.menu-icon { margin-right: 0; }
        .sidebar.collapsed-desktop .sidebar-user-profile .user-avatar { margin: 0 auto; }
        .sidebar.collapsed-desktop:hover { transform: translateX(0); }
        .sidebar.collapsed-desktop:hover .sidebar-header .logo span,
        .sidebar.collapsed-desktop:hover .sidebar-menu-item > .menu-link span,
        .sidebar.collapsed-desktop:hover .sidebar-menu-item > .menu-link .menu-arrow,
        .sidebar.collapsed-desktop:hover .sidebar-user-profile .user-info { display: inline; }
        .sidebar.collapsed-desktop:hover .sidebar-header .logo i { margin-right: 0.6rem; }
        .sidebar.collapsed-desktop:hover .sidebar-menu-item > .menu-link i.menu-icon { margin-right: 1rem; }
        @media (max-width: 767.98px) { .sidebar { transform: translateX(-100%); } .sidebar.active-mobile { transform: translateX(0); } }
        .sidebar::-webkit-scrollbar { width: 6px; } .sidebar::-webkit-scrollbar-track { background: rgba(0,0,0,0.1); } .sidebar::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.3); border-radius: 3px; }
        .sidebar-header { padding: 1rem 1.25rem; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); margin-bottom: 1rem; background-color: var(--primary-blue); }
        .sidebar-header .logo { font-size: 1.6rem; font-weight: bold; color: #ffffff; text-decoration: none; display: flex; align-items: center; justify-content: center; }
        .sidebar-header .logo i { margin-right: 0.6rem; }
        .sidebar-menu { list-style: none; padding: 0; margin: 0; flex-grow: 1; }
        .sidebar-menu-item > .menu-link { display: flex; align-items: center; padding: 0.85rem 1.25rem; color: var(--sidebar-text-color); text-decoration: none; transition: background-color 0.2s ease, color 0.2s ease; border-left: 4px solid transparent; cursor: pointer; position: relative; }
        .sidebar-menu-item > .menu-link:hover { background-color: var(--sidebar-hover-bg); color: #ffffff; border-left-color: var(--sidebar-active-text-color); }
        .sidebar-menu-item > .menu-link.active { background-color: var(--sidebar-active-bg); color: var(--sidebar-active-text-color); font-weight: 500; border-left-color: #87cefa; }
        .sidebar-menu-item > .menu-link i.menu-icon { font-size: 1rem; margin-right: 1rem; width: 20px; text-align: center; }
        .sidebar-menu-item > .menu-link .menu-arrow { margin-left: auto; transition: transform 0.2s ease-out; font-size: 0.75em; }
        .sidebar-menu-item > .menu-link:not(.collapsed) .menu-arrow { transform: rotate(0deg); } .sidebar-menu-item > .menu-link.collapsed .menu-arrow { transform: rotate(-90deg); }
        .sidebar-submenu { list-style: none; padding-left: 0; overflow: hidden; background-color: rgba(0,0,0,0.15); }
        .sidebar-submenu:not(.show) { max-height: 0; transition: max-height 0.25s ease-out; } .sidebar-submenu.show { max-height: 500px; transition: max-height 0.35s ease-in; }
        .sidebar-submenu .sidebar-menu-item a { padding: 0.7rem 1.25rem 0.7rem 3rem; font-size: 0.9rem; display: block; color: var(--sidebar-text-color); text-decoration: none; }
        .sidebar-submenu .sidebar-menu-item a:hover { background-color: var(--sidebar-hover-bg); color: #ffffff; }
        .sidebar-submenu .sidebar-menu-item a.active { background-color: var(--sidebar-active-bg); color: var(--sidebar-active-text-color); font-weight: bold; }
        .sidebar-user-profile { padding: 1rem 1.25rem; border-top: 1px solid rgba(255,255,255,0.1); margin-top: auto; background-color: rgba(0,0,0,0.1); }
        .sidebar-user-profile .user-avatar img { width: 36px; height: 36px; border-radius: 50%; margin-right: 0.75rem; border: 1px solid rgba(255,255,255,0.2); }
        .sidebar-user-profile .user-info h6 { margin-bottom: 0.1rem; font-size: 0.9rem; color: #fff; font-weight: 500; }
        .sidebar-user-profile .user-info p { margin-bottom: 0; font-size: 0.75rem; color: rgba(255,255,255,0.7); }
        .main-content-wrapper { margin-left: var(--sidebar-width); width: calc(100% - var(--sidebar-width)); display: flex; flex-direction: column; transition: margin-left 0.3s ease, width 0.3s ease; flex-grow: 1; }
        .main-content-wrapper.sidebar-hidden-desktop { margin-left: 50px; width: calc(100% - 50px); }
        @media (max-width: 767.98px) { .main-content-wrapper { margin-left: 0; width: 100%; } }
        .topbar { background-color: var(--topbar-bg); padding: 0 1.5rem; border-bottom: 1px solid var(--topbar-border-color); display: flex; align-items: center; justify-content: space-between; height: 60px; position: sticky; top: 0; z-index: 1020; }
        .topbar .topbar-left { display: flex; align-items: center; } .topbar .page-title-h { font-size: 1.25rem; margin-bottom: 0; color: #333; font-weight: 500; margin-left: 1rem; }
        .topbar .topbar-right { display: flex; align-items: center; } .topbar .topbar-right .nav-item .nav-link { color: #6c757d; font-size: 1.1rem; padding: 0.5rem 0.75rem; }
        .topbar .topbar-right .nav-item .nav-link:hover { color: var(--primary-blue); }
        .topbar .topbar-right .dropdown-menu { border-radius: 0.375rem; box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.15); }
        .topbar .topbar-right .user-avatar-top img { width: 32px; height: 32px; border-radius: 50%; }
        .content-area { padding: 20px; flex-grow: 1; background-color: var(--content-bg); } .alert { border-radius: .375rem; }
        @media (max-width: 767.98px) { .topbar .page-title-h { margin-left: 0.5rem; font-size: 1.1rem; } .sidebar-header .logo { font-size: 1.3rem; } #sidebarToggleDesktop { display: none; } }
        @media (min-width: 768px) { #sidebarToggleMobile { display: none; } }

        #calendarContainer { max-width: 1100px; margin: 10px auto; background-color: #fff; padding: 15px; border-radius: 0.375rem; box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.075); }
        .fc-event { cursor: pointer; font-size: 0.78em !important; border-width: 1px !important; }
        .fc-event-main-frame { overflow: hidden; }
        .fc-event-title-container { padding: 2px 4px; }
        .fc-daygrid-event-dot { border-color: var(--primary-blue) !important; }
        .fc .fc-daygrid-day.fc-day-today { background-color: var(--bs-info-bg-subtle, #eaf3ff) !important; }
        #eventDetailsModal .modal-header { background-color: var(--primary-blue); color: white; }
        #eventDetailsModal .modal-title { font-size: 1.15rem; }
        #eventDetailsModal .modal-body p { margin-bottom: 0.75rem; font-size:0.9rem; }
        #eventDetailsModal .detail-label { font-weight: 600; color: #495057; }
        .fc .fc-toolbar-title { font-size: 1.4em !important; }
        .fc .fc-button { background-color: #f8f9fa; border-color: #dee2e6; color: #495057; text-transform: capitalize; padding: 0.3rem 0.6rem; font-size: 0.85rem; }
        .fc .fc-button-primary:not(:disabled).fc-button-active,
        .fc .fc-button-primary:not(:disabled):active { background-color: var(--primary-blue); border-color: var(--primary-blue); }
        .fc .fc-button-primary:hover { background-color: #004b82; border-color: #004b82; }
        #calendarPlaceholder { padding: 2rem; font-size: 1.1rem; }
    </style>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Sidebar toggle JS (from previous version, seems okay)
        const sidebar = document.getElementById('mainSidebar');
        const mainContentWrapper = document.getElementById('mainContentWrapper');
        const sidebarToggleMobile = document.getElementById('sidebarToggleMobile');
        const sidebarToggleDesktop = document.getElementById('sidebarToggleDesktop');

        const SIDEBAR_COLLAPSED_DESKTOP_CLASS = 'collapsed-desktop';
        const SIDEBAR_ACTIVE_MOBILE_CLASS = 'active-mobile';
        const MAIN_CONTENT_SIDEBAR_HIDDEN_CLASS = 'sidebar-hidden-desktop';

        function isMobileView() { return window.innerWidth < 768; }

        function applySidebarState() {
            if (!sidebar || !mainContentWrapper) return;
            if (isMobileView()) {
                sidebar.classList.remove(SIDEBAR_COLLAPSED_DESKTOP_CLASS);
                mainContentWrapper.classList.remove(MAIN_CONTENT_SIDEBAR_HIDDEN_CLASS);
            } else {
                sidebar.classList.remove(SIDEBAR_ACTIVE_MOBILE_CLASS);
                if (localStorage.getItem('sidebarDesktopCollapsed') === 'true') {
                    sidebar.classList.add(SIDEBAR_COLLAPSED_DESKTOP_CLASS);
                    mainContentWrapper.classList.add(MAIN_CONTENT_SIDEBAR_HIDDEN_CLASS);
                } else {
                    sidebar.classList.remove(SIDEBAR_COLLAPSED_DESKTOP_CLASS);
                    mainContentWrapper.classList.remove(MAIN_CONTENT_SIDEBAR_HIDDEN_CLASS);
                }
            }
        }
        applySidebarState();

        if (sidebarToggleMobile) {
            sidebarToggleMobile.addEventListener('click', function () {
                if(sidebar) sidebar.classList.toggle(SIDEBAR_ACTIVE_MOBILE_CLASS);
            });
        }
        if (sidebarToggleDesktop) {
            sidebarToggleDesktop.addEventListener('click', function () {
                if(sidebar) sidebar.classList.toggle(SIDEBAR_COLLAPSED_DESKTOP_CLASS);
                if(mainContentWrapper) mainContentWrapper.classList.toggle(MAIN_CONTENT_SIDEBAR_HIDDEN_CLASS);
                if (!isMobileView() && sidebar) {
                    localStorage.setItem('sidebarDesktopCollapsed', sidebar.classList.contains(SIDEBAR_COLLAPSED_DESKTOP_CLASS));
                }
            });
        }
        window.addEventListener('resize', applySidebarState);

        document.addEventListener('click', function(event) {
            if (isMobileView() && sidebar && sidebar.classList.contains(SIDEBAR_ACTIVE_MOBILE_CLASS)) {
                const isClickInsideSidebar = sidebar.contains(event.target);
                const isClickOnMobileToggler = sidebarToggleMobile ? sidebarToggleMobile.contains(event.target) : false;
                if (!isClickInsideSidebar && !isClickOnMobileToggler) {
                    sidebar.classList.remove(SIDEBAR_ACTIVE_MOBILE_CLASS);
                }
            }
        });

        // FullCalendar Initialization
    var calendarEl = document.getElementById('calendar');
    var calendarPlaceholderEl = document.getElementById('calendarPlaceholder');

    if (calendarEl) {
        var calendarEventsData = [];
        var phpOutput = <?php echo $schedule_events_json ?: '[]'; ?>; // Echo the PHP variable directly

        if (Array.isArray(phpOutput)) {
            // If PHP json_encode somehow resulted in a direct array in JS context (less common but possible with some setups/frameworks)
            calendarEventsData = phpOutput;
            // console.log("PHP output was directly an array/object.");
        } else if (typeof phpOutput === 'string') {
            try {
                calendarEventsData = JSON.parse(phpOutput);
            } catch (e) {
                console.error("Error parsing schedule_events_json string:", e, "Raw string was:", phpOutput);
                calendarEventsData = [];
                if(calendarPlaceholderEl) {
                    calendarPlaceholderEl.innerHTML = '<i class="fas fa-exclamation-triangle me-2"></i> Error loading schedule data format. Please try again or contact support.';
                    calendarPlaceholderEl.classList.remove('alert-info');
                    calendarPlaceholderEl.classList.add('alert-danger');
                    calendarPlaceholderEl.style.display = 'block';
                }
            }
        } else {
            console.error("schedule_events_json from PHP is not a string or an array. Value:", phpOutput);
            if(calendarPlaceholderEl) {
                calendarPlaceholderEl.innerHTML = '<i class="fas fa-exclamation-triangle me-2"></i> Invalid schedule data received from server.';
                calendarPlaceholderEl.classList.remove('alert-info');
                calendarPlaceholderEl.classList.add('alert-danger');
                calendarPlaceholderEl.style.display = 'block';
            }
        }

        if (calendarEventsData.length === 0) {
            if(calendarPlaceholderEl && !calendarPlaceholderEl.classList.contains('alert-danger')) {
                 calendarPlaceholderEl.style.display = 'block';
            }
        } else {
            if(calendarPlaceholderEl) calendarPlaceholderEl.style.display = 'none';

            // CHECK IF FullCalendar IS LOADED BEFORE USING IT
            if (typeof FullCalendar === 'undefined' || typeof FullCalendar.Calendar === 'undefined') {
                console.error("FullCalendar library is not loaded!");
                if(calendarPlaceholderEl) { // Show error in placeholder if FullCalendar is missing
                    calendarPlaceholderEl.innerHTML = '<i class="fas fa-exclamation-triangle me-2"></i> Calendar library could not be loaded. Please check your internet connection or contact support.';
                    calendarPlaceholderEl.classList.remove('alert-info');
                    calendarPlaceholderEl.classList.add('alert-danger');
                    calendarPlaceholderEl.style.display = 'block';
                }
                return; // Stop further execution if FullCalendar is not available
            }

            var defaultViewFc = window.innerWidth < 768 ? 'listWeek' : 'timeGridWeek';

            var calendar = new FullCalendar.Calendar(calendarEl, {
                // ... (phần cấu hình FullCalendar còn lại giữ nguyên như trước)
                initialView: defaultViewFc,
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek'
                },
                events: calendarEventsData,
                editable: false,
                selectable: false,
                eventDisplay: 'block',
                eventTimeFormat: {
                    hour: '2-digit', minute: '2-digit', meridiem: false, hour12: false
                },
                slotLabelFormat: {
                    hour: '2-digit', minute: '2-digit', meridiem: false, hour12: false
                },
                eventClick: function(info) {
                    const modalCourseNameEl = document.getElementById('modalCourseName');
                    const modalCourseCodeEl = document.getElementById('modalCourseCode');
                    const modalTimeEl = document.getElementById('modalTime');
                    const modalDayEl = document.getElementById('modalDay');
                    const modalRoomEl = document.getElementById('modalRoom');
                    const modalLecturerEl = document.getElementById('modalLecturer');

                    if(modalCourseNameEl) modalCourseNameEl.textContent = info.event.extendedProps.full_course_name || 'N/A';
                    if(modalCourseCodeEl) modalCourseCodeEl.textContent = info.event.extendedProps.course_code || 'N/A';

                    let startTimeStr = 'N/A', endTimeStr = 'N/A';
                    if (info.event.start) {
                         startTimeStr = info.event.start.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: false });
                    }
                    if (info.event.end) {
                        endTimeStr = info.event.end.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: false });
                    }
                    if(modalTimeEl) modalTimeEl.textContent = startTimeStr + ' - ' + endTimeStr;

                    if(modalDayEl) modalDayEl.textContent = info.event.start ? info.event.start.toLocaleDateString(undefined, { weekday: 'long' }) : 'N/A';
                    if(modalRoomEl) modalRoomEl.textContent = info.event.extendedProps.room || 'N/A';
                    if(modalLecturerEl) modalLecturerEl.textContent = info.event.extendedProps.lecturer || 'N/A';

                    var eventModal = new bootstrap.Modal(document.getElementById('eventDetailsModal'));
                    eventModal.show();
                },
                dayMaxEvents: true,
                weekends: true,
                allDaySlot: false,
                height: 'auto',
                aspectRatio: 1.8,
                expandRows: true,
                slotMinTime: "07:00:00",
                slotMaxTime: "19:00:00",
                businessHours: {
                    daysOfWeek: [ 1, 2, 3, 4, 5 ],
                    startTime: '07:00',
                    endTime: '18:30',
                }
            });
            calendar.render();
        }
    } 
});
</script>
</body>
</html>