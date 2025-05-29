<?php
// htdocs/DSS/includes/admin_sidebar_menu.php

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db_connect.php'; 
require_once __DIR__ . '/functions.php';  

// Get user information if logged in
$user_fullname = get_current_user_fullname();
$user_role = get_current_user_role(); 
$user_linked_id = get_current_user_linked_entity_id(); // Assuming this function exists and returns string|null
$user_avatar_placeholder = BASE_URL . 'assets/images/default_avatar.png'; // Ensure this image exists

// Determine current page and role directory for active menu highlighting
$current_page_filename = basename($_SERVER['PHP_SELF']); 
$current_script_path = $_SERVER['SCRIPT_NAME']; 
$path_parts = explode('/', trim($current_script_path, '/')); 
$current_role_dir = '';
// Example: /DSS/admin/index.php -> $path_parts = [DSS, admin, index.php]
// We want the directory before the filename, if it's a role directory
if (count($path_parts) > 1 && in_array($path_parts[count($path_parts)-2], ['admin', 'instructor', 'student'])) {
    $current_role_dir = $path_parts[count($path_parts)-2]; 
}

$page_title = "Dashboard"; // Default page title
$menu_items = []; // Initialize menu items array

// Customize menu and page title based on the user's actual role from session
if ($user_role === 'admin') {
    $page_title = "Admin Dashboard"; // Default for admin if no specific page matches
    $menu_items = [
        ['label' => 'Dashboard', 'icon' => 'fas fa-tachometer-alt', 'link' => 'admin/index.php'],
        [
            'label' => 'System Management', 'icon' => 'fas fa-cogs', 'link' => '#', 
            'id' => 'system-management-menu', 
            'submenu' => [ 
                ['label' => 'Semesters', 'icon' => 'fas fa-calendar-alt', 'link' => 'admin/semesters.php'],
                ['label' => 'System Users', 'icon' => 'fas fa-users-cog', 'link' => 'admin/users.php'],
            ]
        ],
        [
            'label' => 'Data Management', 'icon' => 'fas fa-database', 'link' => '#',
            'id' => 'data-management-menu',
            'submenu' => [
                ['label' => 'Instructors', 'icon' => 'fas fa-chalkboard-teacher', 'link' => 'admin/instructors.php'],
                ['label' => 'Students', 'icon' => 'fas fa-user-graduate', 'link' => 'admin/students.php'],
                ['label' => 'Courses', 'icon' => 'fas fa-book', 'link' => 'admin/courses.php'],
                ['label' => 'Classrooms', 'icon' => 'fas fa-school', 'link' => 'admin/classrooms.php'],
                ['label' => 'Time Slots', 'icon' => 'fas fa-clock', 'link' => 'admin/timeslots.php'],
            ]
        ],
        ['label' => 'Schedule Generator', 'icon' => 'fas fa-calendar-check', 'link' => 'admin/scheduler.php'],
    ];
} elseif ($user_role === 'instructor') {
    $page_title = "Instructor Dashboard";
    $menu_items = [
        ['label' => 'Dashboard', 'icon' => 'fas fa-tachometer-alt', 'link' => 'instructor/index.php'],
        ['label' => 'My Schedule', 'icon' => 'fas fa-calendar-alt', 'link' => 'instructor/my_schedule.php'],
        // ['label' => 'My Availability', 'icon' => 'fas fa-user-clock', 'link' => 'instructor/availability.php'],
    ];
} elseif ($user_role === 'student') {
    $page_title = "Student Dashboard";
    $menu_items = [
        ['label' => 'Dashboard', 'icon' => 'fas fa-tachometer-alt', 'link' => 'student/index.php'],
        ['label' => 'My Schedule', 'icon' => 'fas fa-calendar-alt', 'link' => 'student/my_schedule.php'],
    ];
}

// Determine specific page title if the current page matches a menu item
$relative_current_page_for_title = $current_role_dir . '/' . $current_page_filename;
$page_found_in_menu = false;

// Recursive function to find the page in menu (including submenus) and set active state
// This function modifies $page_title_ref and $page_found_ref by reference
function determine_page_title_from_menu($items, $current_path, &$page_title_ref, &$page_found_ref, $user_role_for_title) {
    foreach ($items as $item) {
        if (isset($item['submenu']) && is_array($item['submenu'])) {
            if (determine_page_title_from_menu($item['submenu'], $current_path, $page_title_ref, $page_found_ref, $user_role_for_title)) {
                // If a submenu item is active, the parent title might be preferred or the specific one.
                // For now, if a submenu item matches, its label becomes the page title.
                return true; 
            }
        } elseif (isset($item['link']) && $current_path == $item['link']) {
            $page_title_ref = $item['label'];
            // Optionally append role to title:
            // if ($user_role_for_title) {
            //      $page_title_ref .= " - " . ucfirst($user_role_for_title);
            // }
            $page_found_ref = true;
            return true; 
        }
    }
    return false; 
}

determine_page_title_from_menu($menu_items, $relative_current_page_for_title, $page_title, $page_found_in_menu, $user_role);

// If no specific menu item matched, but user has a role and is on an index page, set a generic dashboard title
if (!$page_found_in_menu) { 
    if ($current_page_filename == 'index.php' && $user_role) {
        $page_title = ucfirst($user_role) . " Dashboard";
    } elseif (empty($user_role) && $current_page_filename != 'login.php' && $current_page_filename != 'register.php' && $current_page_filename != 'verify_otp.php') {
        // If not logged in and not on a public auth page, title might be a generic site title
        $page_title = "University DSS"; 
    } else if (empty($user_role) && ($current_page_filename == 'login.php' || $current_page_filename == 'register.php' || $current_page_filename == 'verify_otp.php')) {
        // Keep specific titles for login/register if not overridden by menu logic
        // (This logic might be redundant if login/register pages set their own titles)
        if ($current_page_filename == 'login.php') $page_title = 'Login';
        if ($current_page_filename == 'register.php') $page_title = 'Register';
        if ($current_page_filename == 'verify_otp.php') $page_title = 'Verify OTP';
    }
}
?>
<!DOCTYPE html>
<html lang="en"> <!-- Changed to English -->
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?> - UniDSS</title> <!-- UniDSS for brevity -->

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <!-- Link to your custom CSS file if you have one, e.g., assets/css/style.css -->
    <!-- <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/admin_layout.css"> -->

    <style>
        :root {
            --primary-blue: #005c9e; 
            --sidebar-bg: #00406e; 
            --sidebar-text-color: #e0e0e0;
            --sidebar-hover-bg: #006ac1; /* Slightly lighter blue for hover */
            --sidebar-active-bg: #007bff; 
            --sidebar-active-text-color: #ffffff;
            --topbar-bg: #ffffff;
            --topbar-border-color: #dee2e6;
            --content-bg: #f4f6f9; 
            --sidebar-width: 260px;
        }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: var(--content-bg); margin: 0; padding: 0; display: flex; min-height: 100vh; overflow-x: hidden; }
        .sidebar { width: var(--sidebar-width); background-color: var(--sidebar-bg); color: var(--sidebar-text-color); padding-top: 0; /* Remove top padding if header is part of sidebar div */ position: fixed; top: 0; left: 0; bottom: 0; display: flex; flex-direction: column; transition: transform 0.3s ease; z-index: 1030; overflow-y: auto; }
        .sidebar.collapsed { transform: translateX(-100%); } 
        .sidebar::-webkit-scrollbar { width: 6px; }
        .sidebar::-webkit-scrollbar-track { background: rgba(0,0,0,0.1); }
        .sidebar::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.3); border-radius: 3px; }
        .sidebar::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,0.5); }
        
        .sidebar-header { padding: 1rem 1.25rem; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); margin-bottom: 1rem; background-color: var(--primary-blue); /* Make header distinct */ }
        .sidebar-header .logo { font-size: 1.6rem; font-weight: bold; color: #ffffff; text-decoration: none; display: flex; align-items: center; justify-content: center; }
        .sidebar-header .logo i { margin-right: 0.6rem; /* color: #87cefa; */ }
        
        .sidebar-menu { list-style: none; padding: 0; margin: 0; flex-grow: 1; }
        .sidebar-menu-item { margin: 0; }
        .sidebar-menu-item > .menu-link { 
            display: flex; align-items: center; padding: 0.85rem 1.25rem; /* Adjusted padding */
            color: var(--sidebar-text-color); text-decoration: none; 
            transition: background-color 0.2s ease, color 0.2s ease; 
            border-left: 4px solid transparent; /* Thicker border */
            cursor: pointer; 
        }
        .sidebar-menu-item > .menu-link:hover { background-color: var(--sidebar-hover-bg); color: #ffffff; border-left-color: var(--sidebar-active-text-color); }
        .sidebar-menu-item > .menu-link.active { background-color: var(--sidebar-active-bg); color: var(--sidebar-active-text-color); font-weight: 500; border-left-color: #87cefa; /* Light blue active border */ }
        .sidebar-menu-item > .menu-link i.menu-icon { font-size: 1rem; margin-right: 1rem; width: 20px; text-align: center; }
        .sidebar-menu-item > .menu-link .menu-arrow { margin-left: auto; transition: transform 0.2s ease-out; font-size: 0.75em; }
        .sidebar-menu-item > .menu-link:not(.collapsed) .menu-arrow { transform: rotate(0deg); } /* Default arrow */
        .sidebar-menu-item > .menu-link.collapsed .menu-arrow { transform: rotate(-90deg); } /* Arrow when submenu is hidden */
        
        .sidebar-submenu {
            list-style: none; padding-left: 0; 
            overflow: hidden;
            background-color: rgba(0,0,0,0.15); /* Slightly darker submenu bg */
        }
        /* Bootstrap 'collapse' and 'show' classes will handle visibility. Max-height for animation: */
        .sidebar-submenu:not(.show) { max-height: 0; transition: max-height 0.25s ease-out; }
        .sidebar-submenu.show { max-height: 500px; /* Adjust as needed, or set by JS */ transition: max-height 0.35s ease-in; }

        .sidebar-submenu .sidebar-menu-item a { /* Styling for submenu links */
            padding: 0.7rem 1.25rem 0.7rem 3rem; /* Indent submenu items */
            font-size: 0.9rem;
            display: block; /* Make them block for full-width click */
            color: var(--sidebar-text-color);
            text-decoration: none;
        }
        .sidebar-submenu .sidebar-menu-item a:hover { background-color: var(--sidebar-hover-bg); color: #ffffff; }
        .sidebar-submenu .sidebar-menu-item a.active { background-color: var(--sidebar-active-bg); color: var(--sidebar-active-text-color); font-weight: bold; }

        .sidebar-user-profile { padding: 1rem 1.25rem; border-top: 1px solid rgba(255,255,255,0.1); margin-top: auto; background-color: rgba(0,0,0,0.1); }
        .sidebar-user-profile .user-avatar img { width: 36px; height: 36px; border-radius: 50%; margin-right: 0.75rem; border: 1px solid rgba(255,255,255,0.2); }
        .sidebar-user-profile .user-info h6 { margin-bottom: 0.1rem; font-size: 0.9rem; color: #fff; font-weight: 500; }
        .sidebar-user-profile .user-info p { margin-bottom: 0; font-size: 0.75rem; color: rgba(255,255,255,0.7); }
        
        .main-content-wrapper { margin-left: var(--sidebar-width); width: calc(100% - var(--sidebar-width)); display: flex; flex-direction: column; transition: margin-left 0.3s ease, width 0.3s ease; flex-grow: 1; }
        .main-content-wrapper.sidebar-collapsed { margin-left: 0; width: 100%; } 
        
        .topbar { background-color: var(--topbar-bg); padding: 0 1.5rem; border-bottom: 1px solid var(--topbar-border-color); display: flex; align-items: center; justify-content: space-between; height: 60px; position: sticky; top: 0; z-index: 1020; }
        .topbar .topbar-left { display: flex; align-items: center; }
        .topbar .page-title-h { font-size: 1.25rem; margin-bottom: 0; color: #333; font-weight: 500; margin-left: 1rem; }
        .topbar .topbar-right { display: flex; align-items: center; }
        .topbar .topbar-right .nav-item .nav-link { color: #6c757d; font-size: 1.1rem; padding: 0.5rem 0.75rem; }
        .topbar .topbar-right .nav-item .nav-link:hover { color: var(--primary-blue); }
        .topbar .topbar-right .dropdown-menu { border-radius: 0.375rem; box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.15); }
        .topbar .topbar-right .user-avatar-top img { width: 32px; height: 32px; border-radius: 50%; }
        
        .content-area { padding: 20px; flex-grow: 1; background-color: var(--content-bg); }
        .alert { border-radius: .375rem; }
        
        @media (max-width: 767.98px) {
            .sidebar { transform: translateX(-100%); } 
            .sidebar.active { transform: translateX(0); box-shadow: 0 0 15px rgba(0,0,0,0.2); } 
            .main-content-wrapper { margin-left: 0; width: 100%; }
            .topbar .page-title-h { margin-left: 0.5rem; font-size: 1.1rem; }
        }
    </style>
</head>
<body>
    <div class="sidebar" id="mainSidebar">
        <div class="sidebar-header">
            <a href="<?php echo BASE_URL . ($user_role ? htmlspecialchars($user_role) . '/index.php' : 'login.php'); ?>" class="logo">
                <i class="fas fa-university"></i> UniDSS Portal
            </a>
        </div>
        <ul class="sidebar-menu">
            <?php if (!empty($menu_items)): ?>
                <li class="sidebar-menu-item menu-title" style="padding: 10px 20px; font-size: 0.8rem; color: rgba(255,255,255,0.5); text-transform: uppercase;"><span>Navigation</span></li>
                <?php
                // Hàm render menu đã được cập nhật ở phiên bản trước
                // Đảm bảo nó được định nghĩa ở đây hoặc include từ file khác nếu cần
                if (!function_exists('render_menu_items_recursive')) { // Tránh định nghĩa lại nếu include nhiều lần
                    function render_menu_items_recursive($items, $current_role_dir, $current_page_filename, $is_submenu_level = false) {
                        foreach ($items as $item) {
                            $has_submenu = isset($item['submenu']) && is_array($item['submenu']) && !empty($item['submenu']);
                            $link_href = $has_submenu ? '#' : BASE_URL . htmlspecialchars($item['link']);
                            
                            $is_item_active = false;
                            $is_parent_active = false;

                            if (!$has_submenu && ($current_role_dir . '/' . $current_page_filename == $item['link'])) {
                                $is_item_active = true;
                            } elseif ($has_submenu) {
                                foreach ($item['submenu'] as $sub_item) {
                                    if ($current_role_dir . '/' . $current_page_filename == $sub_item['link']) {
                                        $is_parent_active = true; // Parent of active submenu item
                                        break;
                                    }
                                }
                            }
                            $link_classes = "menu-link";
                            if ($is_item_active || ($has_submenu && $is_parent_active)) $link_classes .= " active";
                            if ($has_submenu && !$is_parent_active) $link_classes .= " collapsed";
                            ?>
                            <li class="sidebar-menu-item <?php echo $has_submenu ? 'has-submenu' : ''; ?>">
                                <a href="<?php echo $link_href; ?>" 
                                   class="<?php echo $link_classes; ?>"
                                   <?php if ($has_submenu): ?>
                                       data-bs-toggle="collapse" 
                                       data-bs-target="#<?php echo htmlspecialchars($item['id']); ?>" 
                                       aria-expanded="<?php echo $is_parent_active ? 'true' : 'false'; ?>" 
                                       aria-controls="<?php echo htmlspecialchars($item['id']); ?>"
                                   <?php endif; ?>>
                                    <i class="menu-icon <?php echo htmlspecialchars($item['icon']); ?>"></i>
                                    <span><?php echo htmlspecialchars($item['label']); ?></span>
                                    <?php if ($has_submenu): ?>
                                        <i class="fas fa-chevron-down menu-arrow"></i>
                                    <?php endif; ?>
                                </a>
                                <?php if ($has_submenu): ?>
                                    <ul class="sidebar-submenu collapse <?php echo $is_parent_active ? 'show' : ''; ?>" id="<?php echo htmlspecialchars($item['id']); ?>">
                                        <?php render_menu_items_recursive($item['submenu'], $current_role_dir, $current_page_filename, true); ?>
                                    </ul>
                                <?php endif; ?>
                            </li>
                            <?php
                        }
                    }
                } // end if function_exists
                render_menu_items_recursive($menu_items, $current_role_dir, $current_page_filename);
                ?>
            <?php else: ?>
                 <li class="sidebar-menu-item" style="padding: 15px 20px; color: rgba(255,255,255,0.7);">Please login to see the menu.</li>
            <?php endif; ?>
        </ul>
        <?php if ($user_fullname): ?>
        <div class="sidebar-user-profile">
            <div class="d-flex align-items-center">
                <div class="user-avatar">
                    <img src="<?php echo $user_avatar_placeholder; ?>" alt="User Avatar">
                </div>
                <div class="user-info">
                    <h6><?php echo htmlspecialchars($user_fullname); ?></h6>
                    <p><?php echo htmlspecialchars(ucfirst($user_role)); ?></p>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="main-content-wrapper" id="mainContentWrapper">
        <nav class="topbar">
            <div class="topbar-left">
                 <button class="btn btn-light d-md-none me-2" type="button" id="sidebarToggleMobile" aria-label="Toggle sidebar">
                    <i class="fas fa-bars"></i>
                </button>
                <h4 class="page-title-h mb-0 d-none d-md-block"><?php echo htmlspecialchars($page_title);?></h4>
            </div>
            <ul class="navbar-nav topbar-right flex-row align-items-center">
                <!-- <li class="nav-item"> <a class="nav-link" href="#" title="Notifications"> <i class="far fa-bell"></i> </a> </li> -->
                <?php if ($user_fullname): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <span class="user-avatar-top me-2"> <img src="<?php echo $user_avatar_placeholder; ?>" alt="User Avatar"> </span>
                        <span class="d-none d-sm-inline"><?php echo htmlspecialchars($user_fullname); ?></span>
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
        <main class="content-area" id="mainContentArea"> <!-- Thêm ID cho main content area -->
            <?php echo display_all_flash_messages(); ?>
            <!-- Content of specific pages (e.g., admin/index.php) will be inserted here by PHP's include mechanism -->