<?php
// htdocs/DSS/student/index.php

// --- STAGE 1: PHP LOGIC FOR THIS PAGE (student/index.php) ---
if (session_status() == PHP_SESSION_NONE) {
    session_start(); 
}
require_once __DIR__ . '/../includes/functions.php'; 
require_once __DIR__ . '/../includes/db_connect.php'; // $conn should be available

require_role(['student'], '../login.php'); // Authenticate: only students allowed

// Page-specific variables for student/index.php
$page_specific_title = "Student Dashboard"; 

$current_student_id_page = get_current_user_linked_entity_id(); 
$current_user_fullname_page = get_current_user_fullname();   

// Data for the student dashboard
$student_info_page = null;
$upcoming_class_today_page = null;
$classes_this_semester_count_page = 0;
$active_semester_id_page = null;
$active_semester_name_page = "N/A";

if ($current_student_id_page && isset($conn) && $conn instanceof mysqli) {
    // Get student basic info
    $stmt_student_page = $conn->prepare("SELECT s.StudentID, s.StudentName, s.Email, s.Program, u.Username 
                                    FROM Students s
                                    LEFT JOIN Users u ON s.StudentID = u.LinkedEntityID AND u.Role = 'student'
                                    WHERE s.StudentID = ?");
    if ($stmt_student_page) {
        $stmt_student_page->bind_param("s", $current_student_id_page);
        if ($stmt_student_page->execute()) {
            $result_student_page = $stmt_student_page->get_result();
            if ($result_student_page && $result_student_page->num_rows > 0) {
                $student_info_page = $result_student_page->fetch_assoc();
            }
        } else { error_log("Student Dashboard: Failed to execute student info query: " . $stmt_student_page->error); }
        $stmt_student_page->close();
    } else { error_log("Student Dashboard: Failed to prepare student info query: " . $conn->error); }

    // Determine active semester
    $today_date_for_sem_page = date('Y-m-d');
    // Try to find a currently active semester
    $stmt_sem_page = $conn->prepare("SELECT SemesterID, SemesterName FROM Semesters WHERE StartDate <= ? AND EndDate >= ? ORDER BY StartDate DESC LIMIT 1");
    if ($stmt_sem_page) {
        $stmt_sem_page->bind_param("ss", $today_date_for_sem_page, $today_date_for_sem_page);
        if ($stmt_sem_page->execute()) {
            $res_sem_page = $stmt_sem_page->get_result();
            if ($row_sem_page = $res_sem_page->fetch_assoc()) {
                $active_semester_id_page = (int)$row_sem_page['SemesterID'];
                $active_semester_name_page = htmlspecialchars($row_sem_page['SemesterName']);
            }
        } else { error_log("Student Dashboard: Failed to execute current semester query: " . $stmt_sem_page->error); }
        $stmt_sem_page->close();
    }  else { error_log("Student Dashboard: Failed to prepare current semester query: " . $conn->error); }

     // Fallback: if no current semester, find the closest one (upcoming or most recent past)
     if (!$active_semester_id_page) { 
        $stmt_sem_fallback_page = $conn->prepare("SELECT SemesterID, SemesterName FROM Semesters ORDER BY ABS(DATEDIFF(StartDate, ?)) ASC LIMIT 1");
        if ($stmt_sem_fallback_page) {
            $stmt_sem_fallback_page->bind_param("s", $today_date_for_sem_page);
            if ($stmt_sem_fallback_page->execute()) {
                $res_sem_fallback_page = $stmt_sem_fallback_page->get_result();
                if ($row_sem_fallback_page = $res_sem_fallback_page->fetch_assoc()) {
                     $active_semester_id_page = (int)$row_sem_fallback_page['SemesterID'];
                     $active_semester_name_page = htmlspecialchars($row_sem_fallback_page['SemesterName']) . " (Closest available)";
                }
            } else { error_log("Student Dashboard: Failed to execute fallback semester query: " . $stmt_sem_fallback_page->error); }
            $stmt_sem_fallback_page->close();
        }  else { error_log("Student Dashboard: Failed to prepare fallback semester query: " . $conn->error); }
    }

    if ($active_semester_id_page) {
        // Get upcoming class for today for the student
        $current_day_name_page = date('l'); // Full textual representation of the day of the week (e.g., Monday)
        $current_time_page = date('H:i:s');
        $sql_upcoming_page = "SELECT c.CourseName, t.StartTime, t.EndTime, cr.RoomCode
                         FROM StudentEnrollments se
                         JOIN ScheduledClasses sc ON se.CourseID = sc.CourseID AND se.SemesterID = sc.SemesterID
                         JOIN Courses c ON sc.CourseID = c.CourseID
                         JOIN TimeSlots t ON sc.TimeSlotID = t.TimeSlotID
                         JOIN Classrooms cr ON sc.ClassroomID = cr.ClassroomID
                         WHERE se.StudentID = ? AND sc.SemesterID = ? AND t.DayOfWeek = ? AND t.StartTime >= ?
                         ORDER BY t.StartTime ASC
                         LIMIT 1";
        $stmt_upcoming_page = $conn->prepare($sql_upcoming_page);
        if ($stmt_upcoming_page) {
            $stmt_upcoming_page->bind_param("siss", $current_student_id_page, $active_semester_id_page, $current_day_name_page, $current_time_page);
            if ($stmt_upcoming_page->execute()) {
                $res_upcoming_page = $stmt_upcoming_page->get_result();
                if ($res_upcoming_page && $res_upcoming_page->num_rows > 0) {
                    $upcoming_class_today_page = $res_upcoming_page->fetch_assoc();
                }
            } else { error_log("Student Dashboard: Failed to execute upcoming class query: " . $stmt_upcoming_page->error); }
            $stmt_upcoming_page->close();
        } else { error_log("Student Dashboard: Failed to prepare upcoming class query: " . $conn->error); }

        // Count distinct scheduled courses for enrolled courses in the active semester.
        $sql_semester_courses_count_page = "SELECT COUNT(DISTINCT sc.CourseID) as class_count
                                       FROM StudentEnrollments se
                                       JOIN ScheduledClasses sc ON se.CourseID = sc.CourseID AND se.SemesterID = sc.SemesterID
                                       WHERE se.StudentID = ? AND sc.SemesterID = ?";
        $stmt_semester_courses_count_page = $conn->prepare($sql_semester_courses_count_page);
        if ($stmt_semester_courses_count_page) {
            $stmt_semester_courses_count_page->bind_param("si", $current_student_id_page, $active_semester_id_page);
            if ($stmt_semester_courses_count_page->execute()) {
                $res_semester_courses_count_page = $stmt_semester_courses_count_page->get_result();
                if ($res_semester_courses_count_page) {
                    $classes_this_semester_count_page = $res_semester_courses_count_page->fetch_assoc()['class_count'] ?? 0;
                }
            } else { error_log("Student Dashboard: Failed to execute semester course count query: " . $stmt_semester_courses_count_page->error); }
            $stmt_semester_courses_count_page->close();
        } else { error_log("Student Dashboard: Failed to prepare semester course count query: " . $conn->error); }
    }
}
?>
<?php
// --- START: PHP Logic for Layout (adapted from admin_sidebar_menu.php) ---
// This part defines variables and functions needed for the layout.
// It assumes functions.php (for BASE_URL, get_current_user_*) is already included.

$user_fullname_layout = get_current_user_fullname(); 
$user_role_layout = get_current_user_role();     
$user_avatar_placeholder_layout = BASE_URL . 'assets/images/default_avatar.png';

$current_page_filename_layout = basename($_SERVER['PHP_SELF']); 
$current_script_path_layout = $_SERVER['SCRIPT_NAME']; 
$path_parts_layout = explode('/', trim($current_script_path_layout, '/'));
$current_role_dir_layout = '';
if (count($path_parts_layout) > 1 && in_array($path_parts_layout[count($path_parts_layout)-2], ['admin', 'instructor', 'student'])) {
    $current_role_dir_layout = $path_parts_layout[count($path_parts_layout)-2]; 
}
// e.g., "student/index.php"
$current_relative_path_layout = $current_role_dir_layout . '/' . $current_page_filename_layout; 

$menu_items_layout = [];
// Use the page-specific title ($page_specific_title from Part 1)
$page_title_layout = $page_specific_title ?? "Student Portal"; 

if ($user_role_layout === 'student') {
    $menu_items_layout = [
        ['label' => 'Dashboard', 'icon' => 'fas fa-tachometer-alt', 'link' => 'student/index.php'],
        ['label' => 'My Schedule', 'icon' => 'fas fa-calendar-alt', 'link' => 'student/my_schedule.php'],
        ['label' => 'Courses Registration', 'icon' => 'fas fa-edit', 'link' => 'student/course_registration.php'], 
    ];
}

if (!function_exists('determine_page_title_from_menu_layout')) {
    function determine_page_title_from_menu_layout($items, $current_path, &$page_title_ref_layout) {
        foreach ($items as $item) {
            if (isset($item['submenu']) && is_array($item['submenu'])) {
                if (determine_page_title_from_menu_layout($item['submenu'], $current_path, $page_title_ref_layout)) {
                    return true; 
                }
            } elseif (isset($item['link']) && $current_path == $item['link']) {
                // If the current page matches a menu item, use its label for the title
                $page_title_ref_layout = $item['label'];
                return true;
            }
        }
        return false;
    }
}
// If current page has a menu item, its label will override $page_specific_title for $page_title_layout
determine_page_title_from_menu_layout($menu_items_layout, $current_relative_path_layout, $page_title_layout);

// Function to render menu items recursively
if (!function_exists('render_menu_items_layout_recursive')) {
    function render_menu_items_layout_recursive($items_to_render, $current_rel_path_layout) {
        foreach ($items_to_render as $item_render_layout) {
            $has_submenu_render_layout = isset($item_render_layout['submenu']) && is_array($item_render_layout['submenu']) && !empty($item_render_layout['submenu']);
            $link_href_render_layout = $has_submenu_render_layout ? '#' : BASE_URL . htmlspecialchars($item_render_layout['link']);
            
            $is_item_active_render_layout = false;
            $is_parent_active_render_layout = false;

            if (!$has_submenu_render_layout && ($current_rel_path_layout == $item_render_layout['link'])) {
                $is_item_active_render_layout = true;
            } elseif ($has_submenu_render_layout) {
                foreach ($item_render_layout['submenu'] as $sub_item_render_layout) {
                    if ($current_rel_path_layout == $sub_item_render_layout['link']) {
                        $is_parent_active_render_layout = true; break;
                    }
                }
            }
            $link_classes_render_layout = "menu-link";
            if ($is_item_active_render_layout || ($has_submenu_render_layout && $is_parent_active_render_layout)) $link_classes_render_layout .= " active";
            if ($has_submenu_render_layout && !$is_parent_active_render_layout) $link_classes_render_layout .= " collapsed";
            ?>
            <li class="sidebar-menu-item <?php echo $has_submenu_render_layout ? 'has-submenu' : ''; ?>">
                <a href="<?php echo $link_href_render_layout; ?>" class="<?php echo $link_classes_render_layout; ?>"
                   <?php if ($has_submenu_render_layout): ?>
                       data-bs-toggle="collapse"
                       data-bs-target="#<?php echo htmlspecialchars($item_render_layout['id'] ?? uniqid('submenu-layout-')); ?>"
                       aria-expanded="<?php echo $is_parent_active_render_layout ? 'true' : 'false'; ?>"
                       aria-controls="<?php echo htmlspecialchars($item_render_layout['id'] ?? ''); ?>"
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
    <style>
        /* --- START: CSS from admin_sidebar_menu.php (Layout CSS) --- */
        :root {
            --primary-blue: #005c9e; /* Adjusted primary color */
            --sidebar-bg: #00406e; /* Darker blue for sidebar */
            --sidebar-text-color: #e0e0e0;
            --sidebar-hover-bg: #006ac1;
            --sidebar-active-bg: #007bff; 
            --sidebar-active-text-color: #ffffff;
            --topbar-bg: #ffffff;
            --topbar-border-color: #dee2e6;
            --content-bg: #f4f6f9; /* Light grey for content area */
            --sidebar-width: 260px;
        }
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background-color: var(--content-bg); 
            margin: 0; padding: 0; 
            display: flex; min-height: 100vh; 
            overflow-x: hidden; /* Prevent horizontal scroll on body */
        }
        .sidebar { 
            width: var(--sidebar-width); background-color: var(--sidebar-bg); 
            color: var(--sidebar-text-color); padding-top: 0; 
            position: fixed; top: 0; left: 0; bottom: 0; 
            display: flex; flex-direction: column; 
            transition: transform 0.3s ease; z-index: 1030; 
            overflow-y: auto; 
        }
        .sidebar.active { /* For mobile: shown */
            transform: translateX(0); 
            box-shadow: 0 0 15px rgba(0,0,0,0.2); 
        }
        .sidebar.collapsed-desktop { /* For desktop: collapsed state */
            transform: translateX(calc(-1 * var(--sidebar-width) + 50px));
            overflow: visible; /* Allow tooltips or flyouts if any */
        }
        .sidebar.collapsed-desktop:hover {
             transform: translateX(0); /* Expand on hover when collapsed */
        }
        .sidebar:not(.active-mobile):not(.collapsed-desktop) {
             /* Default visible state on desktop */
        }
        @media (max-width: 767.98px) {
            .sidebar { transform: translateX(-100%); /* Hidden by default on mobile */ }
            .sidebar.active-mobile { transform: translateX(0); }
        }

        .sidebar::-webkit-scrollbar { width: 6px; }
        .sidebar::-webkit-scrollbar-track { background: rgba(0,0,0,0.1); }
        .sidebar::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.3); border-radius: 3px; }
        
        .sidebar-header { 
            padding: 1rem 1.25rem; text-align: center; 
            border-bottom: 1px solid rgba(255,255,255,0.1); 
            margin-bottom: 1rem; background-color: var(--primary-blue); 
        }
        .sidebar-header .logo { 
            font-size: 1.6rem; font-weight: bold; color: #ffffff; 
            text-decoration: none; display: flex; align-items: center; justify-content: center; 
        }
        .sidebar-header .logo i { margin-right: 0.6rem; }
        .sidebar-header .logo span { display: inline; }
        .collapsed-desktop .sidebar-header .logo span { display: none; }
        .collapsed-desktop .sidebar-header .logo i { margin-right: 0; }


        .sidebar-menu { list-style: none; padding: 0; margin: 0; flex-grow: 1; }
        .sidebar-menu-item > .menu-link { 
            display: flex; align-items: center; padding: 0.85rem 1.25rem; 
            color: var(--sidebar-text-color); text-decoration: none; 
            transition: background-color 0.2s ease, color 0.2s ease; 
            border-left: 4px solid transparent; cursor: pointer; position: relative;
        }
        .sidebar-menu-item > .menu-link:hover { 
            background-color: var(--sidebar-hover-bg); color: #ffffff; 
            border-left-color: var(--sidebar-active-text-color); /* Consistent hover indicator */
        }
        .sidebar-menu-item > .menu-link.active { 
            background-color: var(--sidebar-active-bg); color: var(--sidebar-active-text-color); 
            font-weight: 500; border-left-color: #87cefa; /* Lighter blue active indicator */
        }
        .sidebar-menu-item > .menu-link i.menu-icon { 
            font-size: 1rem; margin-right: 1rem; width: 20px; text-align: center; 
        }
        .collapsed-desktop .sidebar-menu-item > .menu-link i.menu-icon { margin-right: 0; }
        .collapsed-desktop .sidebar-menu-item > .menu-link span,
        .collapsed-desktop .sidebar-menu-item > .menu-link .menu-arrow { display: none; }


        .sidebar-menu-item > .menu-link .menu-arrow { 
            margin-left: auto; transition: transform 0.2s ease-out; font-size: 0.75em; 
        }
        .sidebar-menu-item > .menu-link:not(.collapsed) .menu-arrow { transform: rotate(0deg); }
        .sidebar-menu-item > .menu-link.collapsed .menu-arrow { transform: rotate(-90deg); }
        
        .sidebar-submenu { 
            list-style: none; padding-left: 0; overflow: hidden; 
            background-color: rgba(0,0,0,0.15); 
        }
        .sidebar-submenu:not(.show) { max-height: 0; transition: max-height 0.25s ease-out; }
        .sidebar-submenu.show { max-height: 500px; transition: max-height 0.35s ease-in; }
        .sidebar-submenu .sidebar-menu-item a { 
            padding: 0.7rem 1.25rem 0.7rem 3rem; font-size: 0.9rem; 
            display: block; color: var(--sidebar-text-color); text-decoration: none; 
        }
        .sidebar-submenu .sidebar-menu-item a:hover { background-color: var(--sidebar-hover-bg); color: #ffffff; }
        .sidebar-submenu .sidebar-menu-item a.active { 
            background-color: var(--sidebar-active-bg); color: var(--sidebar-active-text-color); font-weight: bold; 
        }
        .collapsed-desktop .sidebar-submenu { display: none !important; /* Hide submenus when collapsed */ }

        .sidebar-user-profile { 
            padding: 1rem 1.25rem; border-top: 1px solid rgba(255,255,255,0.1); 
            margin-top: auto; background-color: rgba(0,0,0,0.1); 
        }
        .sidebar-user-profile .user-avatar img { 
            width: 36px; height: 36px; border-radius: 50%; 
            margin-right: 0.75rem; border: 1px solid rgba(255,255,255,0.2); 
        }
        .sidebar-user-profile .user-info h6 { 
            margin-bottom: 0.1rem; font-size: 0.9rem; color: #fff; font-weight: 500; 
        }
        .sidebar-user-profile .user-info p { 
            margin-bottom: 0; font-size: 0.75rem; color: rgba(255,255,255,0.7); 
        }
        .collapsed-desktop .sidebar-user-profile .user-info { display: none; }
        .collapsed-desktop .sidebar-user-profile .user-avatar { margin: 0 auto; }


        .main-content-wrapper { 
            margin-left: var(--sidebar-width); width: calc(100% - var(--sidebar-width)); 
            display: flex; flex-direction: column; 
            transition: margin-left 0.3s ease, width 0.3s ease; flex-grow: 1; 
        }
        .main-content-wrapper.sidebar-hidden-desktop { /* When desktop sidebar is collapsed */
            margin-left: 50px; 
            width: calc(100% - 50px); 
        }
        @media (max-width: 767.98px) { /* On mobile, content always takes full width when sidebar is not overlaying */
            .main-content-wrapper { margin-left: 0; width: 100%; }
        }
        
        .topbar { 
            background-color: var(--topbar-bg); padding: 0 1.5rem; 
            border-bottom: 1px solid var(--topbar-border-color); 
            display: flex; align-items: center; justify-content: space-between; 
            height: 60px; position: sticky; top: 0; z-index: 1020; 
        }
        .topbar .topbar-left { display: flex; align-items: center; }
        .topbar .page-title-h { 
            font-size: 1.25rem; margin-bottom: 0; color: #333; font-weight: 500; margin-left: 1rem; 
        }
        .topbar .topbar-right { display: flex; align-items: center; }
        .topbar .topbar-right .nav-item .nav-link { 
            color: #6c757d; font-size: 1.1rem; padding: 0.5rem 0.75rem; 
        }
        .topbar .topbar-right .nav-item .nav-link:hover { color: var(--primary-blue); }
        .topbar .topbar-right .dropdown-menu { 
            border-radius: 0.375rem; box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.15); 
        }
        .topbar .topbar-right .user-avatar-top img { width: 32px; height: 32px; border-radius: 50%; }
        
        .content-area { padding: 20px; flex-grow: 1; background-color: var(--content-bg); }
        .alert { border-radius: .375rem; } /* Ensure Bootstrap alerts have rounded corners */
        
        @media (max-width: 767.98px) {
            .topbar .page-title-h { margin-left: 0.5rem; font-size: 1.1rem; }
            .sidebar-header .logo { font-size: 1.3rem; }
            #sidebarToggleDesktop { display: none; } /* Hide desktop toggle on mobile */
        }
        @media (min-width: 768px) {
            #sidebarToggleMobile { display: none; } /* Hide mobile toggle on desktop */
        }
        /* --- END: CSS from admin_sidebar_menu.php --- */

        /* --- START: Page-specific CSS for student/index.php --- */
        .info-box { 
            background-color: #fff; padding: 1.25rem; 
            border-radius: 0.375rem; box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.075); 
            margin-bottom: 1.5rem; height: 100%; display: flex; flex-direction: column; 
        }
        .info-box .stat-content { display: flex; align-items: center; width: 100%; }
        .info-box .stat-text { flex-grow: 1; }
        .info-box .stat-number { 
            font-size: 2rem; font-weight: 600; color: var(--primary-blue, #007bff); display: block; 
        }
        .info-box .stat-label { font-size: 0.85rem; color: #6c757d; margin-bottom: 0.25rem; }
        .info-box .stat-subtext { font-size: 0.75rem; color: #868e96; }
        .info-box .icon-wrapper { font-size: 2.25rem; opacity: 0.25; margin-left: 1rem; }
        
        .upcoming-class-card { 
            background-color: var(--bs-info-bg-subtle, #cfe2ff); /* Lighter blue */
            border-left: 4px solid var(--bs-info, #0d6efd); 
            padding: 1rem; color: var(--bs-info-text-emphasis, #052c65);
        }
        .upcoming-class-card .stat-label { font-size: 0.9rem; }
        .upcoming-class-card .fw-bold { font-size: 1.1rem; }
        .upcoming-class-card small { color: #495057; } /* Slightly darker text for details */
        
        .action-card-grid .col { display: flex; } /* Ensure cards in grid take full height of column */
        .action-card { 
            text-align: center; padding: 1.25rem 0.75rem; border-radius: 0.375rem; 
            background-color: #fff; box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.075); 
            transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out; 
            width: 100%; display: flex; flex-direction: column; 
            justify-content: center; align-items: center; min-height: 120px;
            border: 1px solid #e3e6f0;
        }
        .action-card:hover { 
            transform: translateY(-4px); box-shadow: 0 0.4rem 0.8rem rgba(0,0,0,0.12); 
            border-color: var(--primary-blue);
        }
        .action-card i.action-icon { 
            font-size: 1.75rem; color: var(--primary-blue, #007bff); margin-bottom: 0.5rem; 
        }
        .action-card .action-label { font-size: 0.85rem; font-weight: 500; color: #333; }
        
        .profile-card .profile-avatar { width: 80px; height: 80px; margin-bottom: 0.5rem; }
        .profile-card h5 { font-size: 1.1rem; }
        .profile-card .text-muted { font-size: 0.85rem;}
        .profile-details dl { margin-bottom: 0; }
        .profile-details dt { 
            font-weight: 500; color: #555; font-size: 0.85rem; padding-right: 0.5em; 
        }
        .profile-details dd { color: #333; font-size: 0.85rem; margin-left: 0; }
        
        .placeholder-card { 
            display: flex; align-items: center; justify-content: center; 
            height: 180px; background-color: #f8f9fa; 
            border: 1px dashed #ced4da; color: #6c757d; 
            border-radius: 0.375rem; font-size: 0.9rem;
        }
        .card-header .badge { font-size: 0.8em; }
        /* --- END: Page-specific CSS --- */
    </style>
</head>
<body>
    <div class="sidebar" id="mainSidebar"> <!-- Sidebar HTML -->
        <div class="sidebar-header">
            <a href="<?php echo BASE_URL . ($user_role_layout ? htmlspecialchars($user_role_layout) . '/index.php' : 'login.php'); ?>" class="logo">
                <i class="fas fa-university"></i> <span>UniDSS</span>
            </a>
        </div>
        <ul class="sidebar-menu">
            <?php if (!empty($menu_items_layout)): ?>
                <li class="sidebar-menu-item menu-title" style="padding: 10px 20px; font-size: 0.8rem; color: rgba(255,255,255,0.5); text-transform: uppercase;"><span>Navigation</span></li>
                <?php render_menu_items_layout_recursive($menu_items_layout, $current_relative_path_layout); ?>
            <?php else: ?>
                 <li class="sidebar-menu-item" style="padding: 15px 20px; color: rgba(255,255,255,0.7);">
                    <?php echo ($user_role_layout) ? "No menu items defined for your role." : "Please login to see the menu."; ?>
                 </li>
            <?php endif; ?>
        </ul>
        <?php if ($user_fullname_layout): ?>
        <div class="sidebar-user-profile">
            <div class="d-flex align-items-center">
                <div class="user-avatar">
                    <img src="<?php echo htmlspecialchars($user_avatar_placeholder_layout); ?>" alt="User Avatar">
                </div>
                <div class="user-info">
                    <h6><?php echo htmlspecialchars($user_fullname_layout); ?></h6>
                    <p><?php echo htmlspecialchars(ucfirst($user_role_layout)); ?></p>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="main-content-wrapper" id="mainContentWrapper"> <!-- Main content wrapper -->
        <nav class="topbar"> <!-- Topbar HTML -->
            <div class="topbar-left">
                 <button class="btn btn-light d-md-none me-2" type="button" id="sidebarToggleMobile" aria-label="Toggle sidebar">
                    <i class="fas fa-bars"></i>
                </button>
                 <button class="btn btn-light me-2 d-none d-md-inline-block" type="button" id="sidebarToggleDesktop" aria-label="Toggle sidebar desktop">
                    <i class="fas fa-bars"></i>
                </button>
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
                <?php else: ?>
                <li class="nav-item"> <a class="nav-link" href="<?php echo BASE_URL; ?>login.php"> <i class="fas fa-sign-in-alt me-1"></i> Login </a> </li>
                <?php endif; ?>
            </ul>
        </nav>
        <main class="content-area" id="mainContentArea"> <!-- Content area where page-specific content goes -->
            <?php 
            // Ensure display_all_flash_messages function exists and is called
            if (function_exists('display_all_flash_messages')) {
                echo display_all_flash_messages(); 
            }
            ?>
            
            <!-- START: Page-specific content for student/index.php -->
            <div class="container-fluid">
                <h1 class="mt-0 mb-3">Welcome, <?php echo htmlspecialchars($current_user_fullname_page ?: "Student"); ?>!</h1>
                <ol class="breadcrumb mb-4 bg-light p-2 border rounded-1">
                    <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>student/index.php">Dashboard</a></li>
                    <li class="breadcrumb-item active">Semester: <?php echo $active_semester_name_page; ?></li>
                </ol>

                <div class="row">
                    <!-- Profile Card -->
                    <div class="col-lg-4 mb-4">
                        <div class="info-box profile-card">
                            <div class="text-center">
                                <img src="<?php echo htmlspecialchars($user_avatar_placeholder_layout); ?>" alt="User Avatar" class="img-fluid rounded-circle profile-avatar">
                                <h5><?php echo htmlspecialchars($student_info_page['StudentName'] ?? 'N/A'); ?></h5>
                                <p class="text-muted mb-1">ID: <?php echo htmlspecialchars($student_info_page['StudentID'] ?? 'N/A'); ?></p>
                                <p class="text-muted mb-2"><?php echo htmlspecialchars($student_info_page['Program'] ?? 'Program not set'); ?></p>
                            </div>
                             <hr class="my-2">
                             <dl class="row profile-details g-0">
                                <dt class="col-sm-5 text-sm-end">Username:</dt><dd class="col-sm-7"><?php echo htmlspecialchars($student_info_page['Username'] ?? 'N/A'); ?></dd>
                                <dt class="col-sm-5 text-sm-end">Email:</dt><dd class="col-sm-7"><?php echo htmlspecialchars($student_info_page['Email'] ?? 'N/A'); ?></dd>
                            </dl>
                        </div>
                    </div>
                    <!-- Upcoming Class & Stats -->
                    <div class="col-lg-8 mb-4">
                        <div class="row">
                            <div class="col-md-12 mb-4">
                                 <div class="info-box upcoming-class-card">
                                    <div class="stat-content">
                                        <div class="flex-shrink-0 me-3"> <i class="fas fa-bell fa-2x"></i> </div>
                                        <div class="stat-text">
                                            <?php if ($upcoming_class_today_page): ?>
                                                <h6 class="stat-label mb-0">Next Class Today:</h6>
                                                <p class="fw-bold mb-0 fs-5"> <?php echo htmlspecialchars($upcoming_class_today_page['CourseName']); ?> </p>
                                                <p class="mb-0 stat-subtext"><?php echo htmlspecialchars(format_time_for_display($upcoming_class_today_page['StartTime'])) . ' - ' . htmlspecialchars(format_time_for_display($upcoming_class_today_page['EndTime'])); ?> in Room <?php echo htmlspecialchars($upcoming_class_today_page['RoomCode']); ?></p>
                                            <?php else: ?>
                                                <h6 class="stat-label mb-0">No more classes scheduled for today.</h6>
                                                <p class="stat-subtext">Enjoy your day or prepare for upcoming sessions!</p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6 mb-4">
                                <div class="info-box">
                                    <div class="stat-content">
                                        <div class="stat-text text-center">
                                            <div class="stat-number"><?php echo $classes_this_semester_count_page; ?></div>
                                            <div class="stat-label">Scheduled Courses</div>
                                            <small class="stat-subtext d-block mb-2">(Active Semester)</small>
                                            <a href="<?php echo BASE_URL; ?>student/my_schedule.php" class="btn btn-sm btn-outline-primary mt-1">View Schedule</a>
                                        </div>
                                        <div class="icon-wrapper"><i class="fas fa-calendar-week text-primary"></i></div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6 mb-4">
                                 <div class="info-box bg-light"> 
                                    <div class="stat-content">
                                        <div class="stat-text text-center">
                                            <div class="stat-number">0</div>
                                            <div class="stat-label">Exams This Week</div>
                                             <small class="stat-subtext d-block mb-2">(Placeholder)</small>
                                            <span class="text-muted btn btn-sm btn-outline-secondary mt-1 disabled">View Details</span> 
                                        </div>
                                         <div class="icon-wrapper"><i class="fas fa-edit text-warning"></i></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div> <!-- End first main row -->

                <h5 class="mt-3 mb-3">Quick Actions</h5>
                <div class="row row-cols-2 row-cols-sm-2 row-cols-lg-4 g-3 mb-4 action-card-grid"> 
                    <div class="col">
                        <a href="<?php echo BASE_URL; ?>student/my_schedule.php" class="text-decoration-none d-block h-100">
                            <div class="action-card">
                                <i class="fas fa-calendar-alt action-icon"></i>
                                <span class="action-label">View My Schedule</span>
                            </div>
                        </a>
                    </div>
                     <div class="col">
                        <a href="<?php echo BASE_URL; ?>student/course_registration.php" class="text-decoration-none d-block h-100"> 
                            <div class="action-card">
                                <i class="fas fa-edit action-icon"></i> 
                                <span class="action-label">Course Registration</span>
                            </div>
                        </a>
                    </div>
                    <div class="col">
                        <a href="#" class="text-decoration-none d-block h-100 disabled-link"> <!-- Placeholder -->
                            <div class="action-card" style="opacity:0.7;">
                                <i class="fas fa-user-circle action-icon"></i>
                                <span class="action-label">My Profile</span>
                            </div>
                        </a>
                    </div>
                    <div class="col">
                        <a href="#" class="text-decoration-none d-block h-100 disabled-link"> <!-- Placeholder -->
                            <div class="action-card" style="opacity:0.7;">
                                <i class="fas fa-graduation-cap action-icon"></i>
                                <span class="action-label">Academic Program</span>
                            </div>
                        </a>
                    </div>
                </div>

                <div class="row">
                    <div class="col-lg-6 mb-4">
                        <div class="card h-100 shadow-sm">
                            <div class="card-header bg-light-subtle"><i class="fas fa-chart-bar me-1"></i>Learning Progress <span class="badge bg-secondary float-end">Placeholder</span></div>
                            <div class="card-body placeholder-card">Learning analytics will be shown here.</div>
                        </div>
                    </div>
                    <div class="col-lg-6 mb-4">
                        <div class="card h-100 shadow-sm">
                            <div class="card-header bg-light-subtle"><i class="fas fa-tasks me-1"></i>Enrolled Courses - <?php echo $active_semester_name_page; ?> <span class="badge bg-secondary float-end">Placeholder</span></div>
                            <div class="card-body placeholder-card">List of enrolled courses for the semester.</div>
                        </div>
                    </div>
                </div>
            </div> 
            <!-- END: Page-specific content for student/index.php -->
        </main> 
    </div> <!-- End main-content-wrapper -->
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const sidebar = document.getElementById('mainSidebar');
        const mainContentWrapper = document.getElementById('mainContentWrapper');
        const sidebarToggleMobile = document.getElementById('sidebarToggleMobile');
        const sidebarToggleDesktop = document.getElementById('sidebarToggleDesktop');

        const SIDEBAR_COLLAPSED_DESKTOP_CLASS = 'collapsed-desktop';
        const SIDEBAR_ACTIVE_MOBILE_CLASS = 'active-mobile';
        const MAIN_CONTENT_SIDEBAR_HIDDEN_CLASS = 'sidebar-hidden-desktop';

        function isMobileView() {
            return window.innerWidth < 768;
        }

        // Initial setup based on screen size and localStorage
        function applySidebarState() {
            if (isMobileView()) {
                sidebar.classList.remove(SIDEBAR_COLLAPSED_DESKTOP_CLASS);
                mainContentWrapper.classList.remove(MAIN_CONTENT_SIDEBAR_HIDDEN_CLASS);
                // Mobile sidebar starts hidden, only shown if .active-mobile is present
            } else {
                sidebar.classList.remove(SIDEBAR_ACTIVE_MOBILE_CLASS); // Ensure mobile specific class is off
                if (localStorage.getItem('sidebarDesktopCollapsed') === 'true') {
                    sidebar.classList.add(SIDEBAR_COLLAPSED_DESKTOP_CLASS);
                    mainContentWrapper.classList.add(MAIN_CONTENT_SIDEBAR_HIDDEN_CLASS);
                } else {
                    sidebar.classList.remove(SIDEBAR_COLLAPSED_DESKTOP_CLASS);
                    mainContentWrapper.classList.remove(MAIN_CONTENT_SIDEBAR_HIDDEN_CLASS);
                }
            }
        }
        applySidebarState(); // Apply on load

        if (sidebarToggleMobile) {
            sidebarToggleMobile.addEventListener('click', function () {
                sidebar.classList.toggle(SIDEBAR_ACTIVE_MOBILE_CLASS);
                // No margin change for main content on mobile, sidebar overlays or pushes via its own transform
            });
        }

        if (sidebarToggleDesktop) {
            sidebarToggleDesktop.addEventListener('click', function () {
                sidebar.classList.toggle(SIDEBAR_COLLAPSED_DESKTOP_CLASS);
                mainContentWrapper.classList.toggle(MAIN_CONTENT_SIDEBAR_HIDDEN_CLASS);
                if (!isMobileView()) {
                    localStorage.setItem('sidebarDesktopCollapsed', sidebar.classList.contains(SIDEBAR_COLLAPSED_DESKTOP_CLASS));
                }
            });
        }
        
        window.addEventListener('resize', applySidebarState);

        // Close mobile sidebar if user clicks outside of it
        document.addEventListener('click', function(event) {
            if (isMobileView() && sidebar.classList.contains(SIDEBAR_ACTIVE_MOBILE_CLASS)) {
                const isClickInsideSidebar = sidebar.contains(event.target);
                const isClickOnMobileToggler = sidebarToggleMobile ? sidebarToggleMobile.contains(event.target) : false;
                
                if (!isClickInsideSidebar && !isClickOnMobileToggler) {
                    sidebar.classList.remove(SIDEBAR_ACTIVE_MOBILE_CLASS);
                }
            }
        });
        console.log("Student dashboard layout JS initialized.");
    });
    </script>
</body>
</html>