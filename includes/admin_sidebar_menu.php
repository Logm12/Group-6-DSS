<?php
// htdocs/DSS/includes/admin_sidebar_menu.php

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Ensure functions.php is included. Typically done by the calling file.
if (!function_exists('get_current_user_fullname')) { 
    require_once __DIR__ . '/functions.php';
}

// Get user information if logged in
$user_fullname_template = get_current_user_fullname();
$user_role_template = get_current_user_role();
$user_avatar_placeholder_template = BASE_URL . 'assets/images/default_avatar.png'; // Ensure this path is correct

// Determine current page and role directory for active menu highlighting
$current_page_filename_template = basename($_SERVER['PHP_SELF']);
$current_script_path_template = $_SERVER['SCRIPT_NAME'];
$path_parts_template = explode('/', trim($current_script_path_template, '/'));
$current_role_dir_template = '';
if (count($path_parts_template) > 1 && in_array($path_parts_template[count($path_parts_template)-2], ['admin', 'instructor', 'student'])) {
    $current_role_dir_template = $path_parts_template[count($path_parts_template)-2];
}
$current_relative_path_template = $current_role_dir_template . '/' . $current_page_filename_template;


$menu_items_template = [];
$page_title_template = "Dashboard"; // Default title

if ($user_role_template === 'admin') {
    $page_title_template = "Admin Dashboard";
    $menu_items_template = [
        ['label' => 'Dashboard', 'icon' => 'fas fa-tachometer-alt', 'link' => 'admin/index.php'],
        ['label' => 'Manage Students', 'icon' => 'fas fa-user-graduate', 'link' => 'admin/students.php'],
        ['label' => 'Manage Courses', 'icon' => 'fas fa-book', 'link' => 'admin/courses.php'],
        ['label' => 'Manage Instructors', 'icon' => 'fas fa-chalkboard-teacher', 'link' => 'admin/instructors.php'],
        ['label' => 'Manage Classrooms', 'icon' => 'fas fa-school', 'link' => 'admin/classrooms.php'],
        ['label' => 'Manage Time Slots', 'icon' => 'fas fa-clock', 'link' => 'admin/timeslots.php'],
        [
            'label' => 'System Settings', 'icon' => 'fas fa-cogs', 'link' => '#',
            'id' => 'system-settings-menu',
            'submenu' => [
                ['label' => 'Manage Semesters', 'icon' => 'fas fa-calendar-alt', 'link' => 'admin/semesters.php'],
                ['label' => 'Manage Users', 'icon' => 'fas fa-users-cog', 'link' => 'admin/users.php'],
            ]
        ],
        ['label' => 'Generate Schedule', 'icon' => 'fas fa-calendar-check', 'link' => 'admin/scheduler.php'],
    ];
} elseif ($user_role_template === 'instructor') {
    $page_title_template = "Instructor Dashboard";
    $menu_items_template = [
        ['label' => 'Dashboard', 'icon' => 'fas fa-tachometer-alt', 'link' => 'instructor/index.php'],
        ['label' => 'My Schedule', 'icon' => 'fas fa-calendar-alt', 'link' => 'instructor/my_schedule.php'],
        ['label' => 'My Availability', 'icon' => 'fas fa-user-clock', 'link' => 'instructor/unavailable_slots.php'],
    ];
} elseif ($user_role_template === 'student') {
    $page_title_template = "Student Dashboard";
    $menu_items_template = [
        ['label' => 'Dashboard', 'icon' => 'fas fa-tachometer-alt', 'link' => 'student/index.php'],
        ['label' => 'My Schedule', 'icon' => 'fas fa-calendar-alt', 'link' => 'student/my_schedule.php'],
        ['label' => 'Course Registration', 'icon' => 'fas fa-edit', 'link' => 'student/registration.php'],
    ];
}

global $page_title; 
if (!isset($page_title) || empty($page_title)) {
    $_page_title_from_menu = $page_title_template; 
    $_page_found_in_menu = false;

    if (!function_exists('determine_page_title_from_menu_template')) {
        function determine_page_title_from_menu_template($items, $current_path, &$page_title_ref, &$page_found_ref) {
            foreach ($items as $item) {
                if (isset($item['submenu']) && is_array($item['submenu'])) {
                    if (determine_page_title_from_menu_template($item['submenu'], $current_path, $page_title_ref, $page_found_ref)) {
                        return true;
                    }
                } elseif (isset($item['link']) && $current_path == $item['link']) {
                    $page_title_ref = $item['label'];
                    $page_found_ref = true;
                    return true;
                }
            }
            return false;
        }
    }
    determine_page_title_from_menu_template($menu_items_template, $current_relative_path_template, $_page_title_from_menu, $_page_found_in_menu);
    $page_title = $_page_title_from_menu; 
}


// --- Menu Rendering Function ---
if (!function_exists('render_menu_items_recursive_tpl')) {
    function render_menu_items_recursive_tpl($items_to_render, $current_rel_path, $is_submenu_level = false) {
        foreach ($items_to_render as $item_render) {
            $has_submenu_render = isset($item_render['submenu']) && is_array($item_render['submenu']) && !empty($item_render['submenu']);
            $link_href_render = $has_submenu_render ? '#' : BASE_URL . htmlspecialchars($item_render['link']);
            
            $is_item_active_render = false;
            $is_parent_active_render = false;

            if (!$has_submenu_render && ($current_rel_path == $item_render['link'])) {
                $is_item_active_render = true;
            } elseif ($has_submenu_render) {
                foreach ($item_render['submenu'] as $sub_item_render) {
                    if ($current_rel_path == $sub_item_render['link']) {
                        $is_parent_active_render = true;
                        break;
                    }
                }
            }
            $link_classes_render = "menu-link";
            if ($is_item_active_render || ($has_submenu_render && $is_parent_active_render)) $link_classes_render .= " active";
            if ($has_submenu_render && !$is_parent_active_render) $link_classes_render .= " collapsed"; // For arrow direction
            ?>
            <li class="sidebar-menu-item <?php echo $has_submenu_render ? 'has-submenu' : ''; ?>">
                <a href="<?php echo $link_href_render; ?>"
                   class="<?php echo $link_classes_render; ?>"
                   <?php if ($has_submenu_render): ?>
                       data-bs-toggle="collapse"
                       data-bs-target="#<?php echo htmlspecialchars($item_render['id'] ?? uniqid('submenu-tpl-')); ?>"
                       aria-expanded="<?php echo $is_parent_active_render ? 'true' : 'false'; ?>"
                       aria-controls="<?php echo htmlspecialchars($item_render['id'] ?? ''); ?>"
                   <?php endif; ?>>
                    <i class="menu-icon <?php echo htmlspecialchars($item_render['icon']); ?>"></i>
                    <span><?php echo htmlspecialchars($item_render['label']); ?></span>
                    <?php if ($has_submenu_render): ?>
                        <i class="fas fa-chevron-down menu-arrow"></i>
                    <?php endif; ?>
                </a>
                <?php if ($has_submenu_render): ?>
                    <ul class="sidebar-submenu collapse <?php echo $is_parent_active_render ? 'show' : ''; ?>" id="<?php echo htmlspecialchars($item_render['id'] ?? ''); ?>">
                        <?php render_menu_items_recursive_tpl($item_render['submenu'], $current_rel_path, true); ?>
                    </ul>
                <?php endif; ?>
            </li>
            <?php
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? htmlspecialchars($page_title) : 'UniDSS'; ?> - UniDSS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <!-- CSS styles are embedded here directly -->
    <style>
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
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background-color: var(--content-bg); 
            margin: 0; 
            padding: 0; 
            display: flex; 
            min-height: 100vh; 
            overflow-x: hidden; /* Prevent horizontal scrollbar from body */
        }
        .sidebar { 
            width: var(--sidebar-width); 
            background-color: var(--sidebar-bg); 
            color: var(--sidebar-text-color); 
            padding-top: 0; 
            position: fixed; 
            top: 0; 
            left: 0; 
            bottom: 0; 
            display: flex; 
            flex-direction: column; 
            transition: transform 0.3s ease; 
            z-index: 1030; 
            overflow-y: auto; 
        }
        .sidebar.active { 
            transform: translateX(0); 
            box-shadow: 0 0 15px rgba(0,0,0,0.2); 
        }
        /* Style for when sidebar is initially hidden on mobile or manually collapsed */
        .sidebar:not(.active-forced) { 
             /* Default state, can be overridden by JS adding .active */
        }
        
        /* Scrollbar styling */
        .sidebar::-webkit-scrollbar { width: 6px; }
        .sidebar::-webkit-scrollbar-track { background: rgba(0,0,0,0.1); }
        .sidebar::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.3); border-radius: 3px; }
        
        .sidebar-header { 
            padding: 1rem 1.25rem; 
            text-align: center; 
            border-bottom: 1px solid rgba(255,255,255,0.1); 
            margin-bottom: 1rem; 
            background-color: var(--primary-blue); 
        }
        .sidebar-header .logo { 
            font-size: 1.6rem; 
            font-weight: bold; 
            color: #ffffff; 
            text-decoration: none; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
        }
        .sidebar-header .logo i { margin-right: 0.6rem; }
        
        .sidebar-menu { list-style: none; padding: 0; margin: 0; flex-grow: 1; }
        .sidebar-menu-item { margin: 0; }
        .sidebar-menu-item > .menu-link { 
            display: flex; align-items: center; padding: 0.85rem 1.25rem;
            color: var(--sidebar-text-color); text-decoration: none; 
            transition: background-color 0.2s ease, color 0.2s ease; 
            border-left: 4px solid transparent; 
            cursor: pointer; 
            position: relative; /* For arrow positioning */
        }
        .sidebar-menu-item > .menu-link:hover { background-color: var(--sidebar-hover-bg); color: #ffffff; border-left-color: var(--sidebar-active-text-color); }
        .sidebar-menu-item > .menu-link.active { background-color: var(--sidebar-active-bg); color: var(--sidebar-active-text-color); font-weight: 500; border-left-color: #87cefa; /* Lighter blue for active indicator */ }
        .sidebar-menu-item > .menu-link i.menu-icon { font-size: 1rem; margin-right: 1rem; width: 20px; text-align: center; }
        .sidebar-menu-item > .menu-link .menu-arrow { 
            margin-left: auto; 
            transition: transform 0.2s ease-out; 
            font-size: 0.75em; 
        }
        .sidebar-menu-item > .menu-link:not(.collapsed) .menu-arrow { transform: rotate(0deg); /* Arrow pointing down when expanded */ }
        .sidebar-menu-item > .menu-link.collapsed .menu-arrow { transform: rotate(-90deg); /* Arrow pointing right when collapsed */ }
        
        .sidebar-submenu {
            list-style: none; padding-left: 0; 
            overflow: hidden;
            background-color: rgba(0,0,0,0.15); /* Slightly darker background for submenu */
        }
        /* Smooth collapse/expand animation for submenu */
        .sidebar-submenu:not(.show) { max-height: 0; transition: max-height 0.25s ease-out; }
        .sidebar-submenu.show { max-height: 500px; /* Adjust if submenus can be very long */ transition: max-height 0.35s ease-in; }

        .sidebar-submenu .sidebar-menu-item a {
            padding: 0.7rem 1.25rem 0.7rem 3rem; /* Indent submenu items */
            font-size: 0.9rem;
            display: block;
            color: var(--sidebar-text-color);
            text-decoration: none;
            transition: background-color 0.2s ease, color 0.2s ease;
        }
        .sidebar-submenu .sidebar-menu-item a:hover { background-color: var(--sidebar-hover-bg); color: #ffffff; }
        .sidebar-submenu .sidebar-menu-item a.active { background-color: var(--sidebar-active-bg); color: var(--sidebar-active-text-color); font-weight: bold; }

        .sidebar-user-profile { 
            padding: 1rem 1.25rem; 
            border-top: 1px solid rgba(255,255,255,0.1); 
            margin-top: auto; /* Pushes to the bottom */
            background-color: rgba(0,0,0,0.1); 
        }
        .sidebar-user-profile .user-avatar img { width: 36px; height: 36px; border-radius: 50%; margin-right: 0.75rem; border: 1px solid rgba(255,255,255,0.2); }
        .sidebar-user-profile .user-info h6 { margin-bottom: 0.1rem; font-size: 0.9rem; color: #fff; font-weight: 500; }
        .sidebar-user-profile .user-info p { margin-bottom: 0; font-size: 0.75rem; color: rgba(255,255,255,0.7); }
        
        .main-content-wrapper { 
            margin-left: var(--sidebar-width); 
            width: calc(100% - var(--sidebar-width)); 
            display: flex; 
            flex-direction: column; 
            transition: margin-left 0.3s ease, width 0.3s ease; 
            flex-grow: 1; 
        }
        .main-content-wrapper.sidebar-hidden { /* Class added by JS when sidebar is hidden */
            margin-left: 0; 
            width: 100%; 
        } 
        
        .topbar { 
            background-color: var(--topbar-bg); 
            padding: 0 1.5rem; 
            border-bottom: 1px solid var(--topbar-border-color); 
            display: flex; 
            align-items: center; 
            justify-content: space-between; 
            height: 60px; 
            position: sticky; 
            top: 0; 
            z-index: 1020; /* Below sidebar but above content */
        }
        .topbar .topbar-left { display: flex; align-items: center; }
        .topbar .page-title-h { font-size: 1.25rem; margin-bottom: 0; color: #333; font-weight: 500; margin-left: 1rem; }
        .topbar .topbar-right { display: flex; align-items: center; }
        .topbar .topbar-right .nav-item .nav-link { color: #6c757d; font-size: 1.1rem; padding: 0.5rem 0.75rem; }
        .topbar .topbar-right .nav-item .nav-link:hover { color: var(--primary-blue); }
        .topbar .topbar-right .dropdown-menu { border-radius: 0.375rem; box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.15); }
        .topbar .topbar-right .user-avatar-top img { width: 32px; height: 32px; border-radius: 50%; }
        
        .content-area { 
            padding: 20px; 
            flex-grow: 1; 
            background-color: var(--content-bg); 
        }
        .alert { border-radius: .375rem; } /* Consistent alert styling */
        
        /* Responsive adjustments */
        @media (max-width: 767.98px) {
            .sidebar { transform: translateX(-100%); /* Sidebar hidden by default on mobile */ } 
            .sidebar.active-mobile { transform: translateX(0); /* JS will add this class */ } 
            .main-content-wrapper { margin-left: 0; width: 100%; }
            .topbar .page-title-h { margin-left: 0.5rem; font-size: 1.1rem; }
            .sidebar-header .logo { font-size: 1.3rem; }
            #sidebarToggleDesktop { display: none; } /* Hide desktop toggle on mobile */
        }
        @media (min-width: 768px) {
            #sidebarToggleMobile { display: none; } /* Hide mobile toggle on desktop */
             /* Default state for sidebar on desktop (can be collapsed by JS) */
            .sidebar.collapsed-desktop { 
                transform: translateX(calc(-1 * var(--sidebar-width) + 50px)); /* Show only icons */
                width: var(--sidebar-width); /* Keep width for re-expansion */
            }
            .sidebar.collapsed-desktop .sidebar-menu-item > .menu-link span,
            .sidebar.collapsed-desktop .sidebar-menu-item > .menu-link .menu-arrow,
            .sidebar.collapsed-desktop .sidebar-header .logo span, /* Hide text part of logo */
            .sidebar.collapsed-desktop .sidebar-user-profile .user-info {
                display: none;
                opacity: 0;
            }
             .sidebar.collapsed-desktop .sidebar-header .logo i { margin-right: 0; } /* Center icon */
            .sidebar.collapsed-desktop .sidebar-menu-item > .menu-link { justify-content: center; }
            .sidebar.collapsed-desktop .sidebar-menu-item > .menu-link i.menu-icon { margin-right: 0; }
             .sidebar.collapsed-desktop .sidebar-user-profile .user-avatar { margin: 0 auto; }


            .main-content-wrapper.sidebar-collapsed-desktop {
                margin-left: 50px; /* Width of the collapsed sidebar showing icons */
                width: calc(100% - 50px);
            }
        }
    </style>
    <!-- Other CSS links can be added here -->
</head>
<body>
    <div class="sidebar" id="mainSidebar"> <!-- Ensure sidebar starts without 'active' on mobile -->
        <div class="sidebar-header">
            <a href="<?php echo BASE_URL . ($user_role_template ? htmlspecialchars($user_role_template) . '/index.php' : 'login.php'); ?>" class="logo">
                <i class="fas fa-university"></i> <span>UniDSS</span>
            </a>
        </div>
        <ul class="sidebar-menu">
            <?php if (!empty($menu_items_template)): ?>
                <li class="sidebar-menu-item menu-title" style="padding: 10px 20px; font-size: 0.8rem; color: rgba(255,255,255,0.5); text-transform: uppercase;"><span>Navigation</span></li>
                <?php render_menu_items_recursive_tpl($menu_items_template, $current_relative_path_template); ?>
            <?php else: ?>
                 <li class="sidebar-menu-item" style="padding: 15px 20px; color: rgba(255,255,255,0.7);">
                    <?php echo ($user_role_template) ? "No menu items defined for your role." : "Please login to see the menu."; ?>
                 </li>
            <?php endif; ?>
        </ul>
        <?php if ($user_fullname_template): ?>
        <div class="sidebar-user-profile">
            <div class="d-flex align-items-center">
                <div class="user-avatar">
                    <img src="<?php echo htmlspecialchars($user_avatar_placeholder_template); ?>" alt="User Avatar">
                </div>
                <div class="user-info">
                    <h6><?php echo htmlspecialchars($user_fullname_template); ?></h6>
                    <p><?php echo htmlspecialchars(ucfirst($user_role_template)); ?></p>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="main-content-wrapper" id="mainContentWrapper">
        <nav class="topbar">
            <div class="topbar-left">
                 <button class="btn btn-light me-2" type="button" id="sidebarToggleMobile" aria-label="Toggle sidebar mobile">
                    <i class="fas fa-bars"></i>
                </button>
                <button class="btn btn-light me-2 d-none d-md-inline-block" type="button" id="sidebarToggleDesktop" aria-label="Toggle sidebar desktop">
                    <i class="fas fa-bars"></i>
                </button>
                <h4 class="page-title-h mb-0 d-none d-md-block"><?php echo isset($page_title) ? htmlspecialchars($page_title) : 'Dashboard';?></h4>
            </div>
            <ul class="navbar-nav topbar-right flex-row align-items-center">
                <?php if ($user_fullname_template): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <span class="user-avatar-top me-2"> <img src="<?php echo htmlspecialchars($user_avatar_placeholder_template); ?>" alt="User Avatar"> </span>
                        <span class="d-none d-sm-inline"><?php echo htmlspecialchars($user_fullname_template); ?></span>
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
        <main class="content-area" id="mainContentArea">
            <?php 
            if (function_exists('display_all_flash_messages')) {
                echo display_all_flash_messages(); 
            }
            ?>
<!-- JavaScript for sidebar toggle functionality (should be placed before </body>) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const sidebar = document.getElementById('mainSidebar');
    const mainContentWrapper = document.getElementById('mainContentWrapper');
    const sidebarToggleMobile = document.getElementById('sidebarToggleMobile');
    const sidebarToggleDesktop = document.getElementById('sidebarToggleDesktop');

    const SIDEBAR_COLLAPSED_DESKTOP_CLASS = 'collapsed-desktop';
    const SIDEBAR_ACTIVE_MOBILE_CLASS = 'active-mobile';
    const MAIN_CONTENT_SIDEBAR_HIDDEN_CLASS = 'sidebar-hidden'; // General class for when sidebar is not full width

    // Function to check if the view is mobile
    function isMobileView() {
        return window.innerWidth < 768;
    }

    // Load sidebar state for desktop from localStorage
    if (!isMobileView() && localStorage.getItem('sidebarDesktopCollapsed') === 'true') {
        sidebar.classList.add(SIDEBAR_COLLAPSED_DESKTOP_CLASS);
        mainContentWrapper.classList.add(MAIN_CONTENT_SIDEBAR_HIDDEN_CLASS); // Use a general class
    }


    if (sidebarToggleMobile) {
        sidebarToggleMobile.addEventListener('click', function () {
            sidebar.classList.toggle(SIDEBAR_ACTIVE_MOBILE_CLASS);
            // On mobile, main content wrapper doesn't change margin, sidebar overlays or pushes
        });
    }

    if (sidebarToggleDesktop) {
        sidebarToggleDesktop.addEventListener('click', function () {
            sidebar.classList.toggle(SIDEBAR_COLLAPSED_DESKTOP_CLASS);
            mainContentWrapper.classList.toggle(MAIN_CONTENT_SIDEBAR_HIDDEN_CLASS); // Use a general class
            if (!isMobileView()) { // Save state only for desktop view
                localStorage.setItem('sidebarDesktopCollapsed', sidebar.classList.contains(SIDEBAR_COLLAPSED_DESKTOP_CLASS));
            }
        });
    }
    
    // Ensure sidebar state is correctly applied on window resize
    window.addEventListener('resize', function() {
        if (isMobileView()) {
            // If becomes mobile, remove desktop collapsed state and ensure mobile state is clean or as intended
            sidebar.classList.remove(SIDEBAR_COLLAPSED_DESKTOP_CLASS);
            mainContentWrapper.classList.remove(MAIN_CONTENT_SIDEBAR_HIDDEN_CLASS); // Or re-evaluate based on mobile state
            // If sidebar was active on mobile and screen resizes, it might remain open or you might want to close it
            // sidebar.classList.remove(SIDEBAR_ACTIVE_MOBILE_CLASS); // Optional: close mobile sidebar on resize to desktop
        } else {
            // If becomes desktop, remove mobile active state
            sidebar.classList.remove(SIDEBAR_ACTIVE_MOBILE_CLASS);
            // Re-apply desktop collapsed state if it was set
            if (localStorage.getItem('sidebarDesktopCollapsed') === 'true') {
                sidebar.classList.add(SIDEBAR_COLLAPSED_DESKTOP_CLASS);
                mainContentWrapper.classList.add(MAIN_CONTENT_SIDEBAR_HIDDEN_CLASS);
            } else {
                sidebar.classList.remove(SIDEBAR_COLLAPSED_DESKTOP_CLASS);
                mainContentWrapper.classList.remove(MAIN_CONTENT_SIDEBAR_HIDDEN_CLASS);
            }
        }
    });

    // Close mobile sidebar if user clicks outside of it on mobile view
    document.addEventListener('click', function(event) {
        if (isMobileView() && sidebar.classList.contains(SIDEBAR_ACTIVE_MOBILE_CLASS)) {
            const isClickInsideSidebar = sidebar.contains(event.target);
            const isClickOnMobileToggler = sidebarToggleMobile ? sidebarToggleMobile.contains(event.target) : false;
            
            if (!isClickInsideSidebar && !isClickOnMobileToggler) {
                sidebar.classList.remove(SIDEBAR_ACTIVE_MOBILE_CLASS);
            }
        }
    });

});
</script>
</body>
</html>