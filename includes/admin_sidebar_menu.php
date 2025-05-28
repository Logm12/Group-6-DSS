<?php
// htdocs/DSS/includes/admin_sidebar_menu.php

// KHỞI TẠO SESSION VÀ NẠP CÁC FILE CẦN THIẾT NGAY LẬP TỨC
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// __DIR__ ở đây là C:\xampp\htdocs\DSS\includes
require_once __DIR__ . '/db_connect.php'; // Kết nối CSDL
require_once __DIR__ . '/functions.php';  // Chứa các hàm tiện ích

// --- SAU KHI ĐÃ NẠP functions.php, MỚI CÓ THỂ SỬ DỤNG CÁC HÀM TỪ NÓ ---

// Lấy thông tin người dùng nếu đã đăng nhập
$user_fullname = get_current_user_fullname();
$user_role = get_current_user_role(); // Hàm này sẽ trả về vai trò từ session
$user_linked_id = get_current_user_linked_entity_id();
$user_avatar_placeholder = BASE_URL . 'assets/images/default_avatar.png'; // Cần có ảnh này hoặc thay thế

// Xác định trang hiện tại và thư mục vai trò
$current_page_filename = basename($_SERVER['PHP_SELF']); // ví dụ: index.php, my_schedule.php
$current_script_path = $_SERVER['SCRIPT_NAME']; // ví dụ: /DSS/admin/index.php
$path_parts = explode('/', trim($current_script_path, '/')); // [DSS, admin, index.php]
$current_role_dir = '';
if (count($path_parts) > 1 && in_array($path_parts[count($path_parts)-2], ['admin', 'instructor', 'student'])) {
    $current_role_dir = $path_parts[count($path_parts)-2]; // 'admin', 'instructor', or 'student'
}

$page_title = "Dashboard"; // Tiêu đề mặc định
$menu_items = [];

// Tùy chỉnh menu và tiêu đề trang dựa trên vai trò thực tế của người dùng từ session
if ($user_role === 'admin') {
    $page_title = "Admin Dashboard";
    $menu_items = [
        ['label' => 'Dashboard', 'icon' => 'fas fa-tachometer-alt', 'link' => 'admin/index.php'],
        ['label' => 'Học kỳ', 'icon' => 'fas fa-calendar-alt', 'link' => 'admin/semesters.php'],
        ['label' => 'Giảng viên', 'icon' => 'fas fa-chalkboard-teacher', 'link' => 'admin/instructors.php'],
        ['label' => 'Sinh viên', 'icon' => 'fas fa-user-graduate', 'link' => 'admin/students.php'],
        ['label' => 'Môn học', 'icon' => 'fas fa-book', 'link' => 'admin/courses.php'],
        ['label' => 'Phòng học', 'icon' => 'fas fa-school', 'link' => 'admin/classrooms.php'],
        ['label' => 'Khung giờ', 'icon' => 'fas fa-clock', 'link' => 'admin/timeslots.php'],
        ['label' => 'Lập lịch', 'icon' => 'fas fa-calendar-check', 'link' => 'admin/scheduler.php'],
        ['label' => 'Người dùng hệ thống', 'icon' => 'fas fa-users-cog', 'link' => 'admin/users.php'],
    ];
} elseif ($user_role === 'instructor') {
    $page_title = "Giảng viên Dashboard";
    $menu_items = [
        ['label' => 'Dashboard', 'icon' => 'fas fa-tachometer-alt', 'link' => 'instructor/index.php'],
        ['label' => 'Lịch giảng dạy', 'icon' => 'fas fa-calendar-alt', 'link' => 'instructor/my_schedule.php'],
    ];
} elseif ($user_role === 'student') {
    $page_title = "Sinh viên Dashboard";
    $menu_items = [
        ['label' => 'Dashboard', 'icon' => 'fas fa-tachometer-alt', 'link' => 'student/index.php'],
        ['label' => 'Lịch học', 'icon' => 'fas fa-calendar-alt', 'link' => 'student/my_schedule.php'],
        // ['label' => 'Đăng ký môn học', 'icon' => 'fas fa-edit', 'link' => 'student/enroll_courses.php'],
    ];
}

// Xác định tiêu đề trang cụ thể nếu trang hiện tại khớp với một mục menu
$relative_current_page_for_title = $current_role_dir . '/' . $current_page_filename;
foreach ($menu_items as $item) {
    if ($relative_current_page_for_title == $item['link']) {
        $page_title = $item['label'];
        if ($user_role) {
             $page_title .= " - " . ucfirst($user_role);
        }
        break;
    }
}
// Nếu không khớp menu nào nhưng có vai trò, dùng tiêu đề dashboard chung
if ($page_title === "Dashboard" && $user_role) {
    $page_title = ucfirst($user_role) . " Dashboard";
} elseif (empty($user_role) && $page_title === "Dashboard"){ // Chưa đăng nhập
     $page_title = "Hệ thống DSS";
}


?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?> - DSS</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <style>
        :root {
            --primary-blue: #005c9e; /* Màu xanh chủ đạo đậm hơn chút */
            --sidebar-bg: #00406e; /* Màu nền sidebar (xanh đậm) */
            --sidebar-text-color: #e0e0e0;
            --sidebar-hover-bg: #006ac1;
            --sidebar-active-bg: #007bff; /* Bootstrap primary blue cho active */
            --sidebar-active-text-color: #ffffff;
            --topbar-bg: #ffffff;
            --topbar-border-color: #dee2e6;
            --content-bg: #f4f6f9; /* Màu nền cho content area */
            --sidebar-width: 260px;
        }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: var(--content-bg); margin: 0; padding: 0; display: flex; min-height: 100vh; overflow-x: hidden; }
        .sidebar { width: var(--sidebar-width); background-color: var(--sidebar-bg); color: var(--sidebar-text-color); padding: 20px 0; position: fixed; top: 0; left: 0; bottom: 0; display: flex; flex-direction: column; transition: width 0.3s ease, transform 0.3s ease; z-index: 1030; overflow-y: auto; }
        .sidebar.collapsed { width: 0; transform: translateX(-100%); } /* For mobile toggle */
        .sidebar::-webkit-scrollbar { width: 6px; }
        .sidebar::-webkit-scrollbar-track { background: var(--sidebar-bg); }
        .sidebar::-webkit-scrollbar-thumb { background: var(--sidebar-hover-bg); border-radius: 3px; }
        .sidebar::-webkit-scrollbar-thumb:hover { background: var(--sidebar-active-bg); }
        .sidebar-header { padding: 0 20px 20px 20px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); margin-bottom: 15px; }
        .sidebar-header .logo { font-size: 1.8rem; font-weight: bold; color: #ffffff; text-decoration: none; }
        .sidebar-header .logo i { margin-right: 10px; color: #87cefa; }
        .sidebar-search { padding: 0 15px 15px 15px; }
        .sidebar-search .form-control { background-color: rgba(255,255,255,0.1); border: none; color: #fff; border-radius: 0.375rem; }
        .sidebar-search .form-control::placeholder { color: rgba(255,255,255,0.6); }
        .sidebar-search .form-control:focus { background-color: rgba(255,255,255,0.2); box-shadow: none; color: #fff; }
        .sidebar-menu { list-style: none; padding: 0; margin: 0; flex-grow: 1; }
        .sidebar-menu-item { margin: 0; }
        .sidebar-menu-item .menu-title { font-size: 0.8rem; color: rgba(255,255,255,0.5); padding: 10px 20px; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; }
        .sidebar-menu-item a { display: flex; align-items: center; padding: 12px 20px; color: var(--sidebar-text-color); text-decoration: none; transition: background-color 0.2s ease, color 0.2s ease; border-left: 3px solid transparent; }
        .sidebar-menu-item a:hover { background-color: var(--sidebar-hover-bg); color: #ffffff; border-left-color: var(--sidebar-active-bg); }
        .sidebar-menu-item a.active { background-color: var(--sidebar-active-bg); color: var(--sidebar-active-text-color); font-weight: 500; border-left-color: #87cefa; }
        .sidebar-menu-item a i.menu-icon { font-size: 1rem; margin-right: 15px; width: 20px; text-align: center; }
        .sidebar-menu-item a .badge { margin-left: auto; font-size: 0.75em; }
        .sidebar-user-profile { padding: 15px 20px; border-top: 1px solid rgba(255,255,255,0.1); margin-top: auto; }
        .sidebar-user-profile .user-avatar img { width: 40px; height: 40px; border-radius: 50%; margin-right: 10px; border: 2px solid rgba(255,255,255,0.3); }
        .sidebar-user-profile .user-info h6 { margin-bottom: 0; font-size: 0.9rem; color: #fff; font-weight: 500; }
        .sidebar-user-profile .user-info p { margin-bottom: 0; font-size: 0.75rem; color: rgba(255,255,255,0.7); }
        .main-content-wrapper { margin-left: var(--sidebar-width); width: calc(100% - var(--sidebar-width)); display: flex; flex-direction: column; transition: margin-left 0.3s ease, width 0.3s ease; flex-grow: 1; }
        .main-content-wrapper.sidebar-collapsed { margin-left: 0; width: 100%; } /* For mobile toggle */
        .topbar { background-color: var(--topbar-bg); padding: 0.75rem 1.5rem; border-bottom: 1px solid var(--topbar-border-color); display: flex; align-items: center; justify-content: space-between; height: 60px; position: sticky; top: 0; z-index: 1020; }
        .topbar .topbar-left { display: flex; align-items: center; }
        .topbar .topbar-search { margin-left: 15px; }
        .topbar .topbar-search .page-title-h { font-size: 1.25rem; margin-bottom: 0; color: #333; font-weight: 500; }
        .topbar .topbar-right { display: flex; align-items: center; }
        .topbar .topbar-right .nav-item .nav-link { color: #6c757d; font-size: 1.1rem; padding: 0.5rem 0.75rem; }
        .topbar .topbar-right .nav-item .nav-link:hover { color: var(--primary-blue); }
        .topbar .topbar-right .dropdown-menu { border-radius: 0.375rem; box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.15); }
        .topbar .topbar-right .user-avatar-top img { width: 32px; height: 32px; border-radius: 50%; }
        .content-area { padding: 20px; flex-grow: 1; background-color: var(--content-bg); }
        .alert { border-radius: .375rem; }
        .btn-close { box-sizing: content-box; width: 1em; height: 1em; padding: .25em .25em; color: #000; background: transparent url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16' fill='%23000'%3e%3cpath d='M.293.293a1 1 0 011.414 0L8 6.586 14.293.293a1 1 0 111.414 1.414L9.414 8l6.293 6.293a1 1 0 01-1.414 1.414L8 9.414l-6.293 6.293a1 1 0 01-1.414-1.414L6.586 8 .293 1.707a1 1 0 010-1.414z'/%3e%3c/svg%3e") center/1em auto no-repeat; border: 0; border-radius: .25rem; opacity: .5; }
        .btn-close:hover { color: #000; text-decoration: none; opacity: .75; }
        .alert-dismissible .btn-close { position: absolute; top: 0; right: 0; z-index: 2; padding: 1.25rem 1rem; }

        /* Mobile specific styles for sidebar toggle */
        @media (max-width: 767.98px) {
            .sidebar { transform: translateX(-100%); } /* Hide by default on mobile */
            .sidebar.active { transform: translateX(0); } /* Show when active */
            .main-content-wrapper { margin-left: 0; width: 100%; }
        }

    </style>
</head>
<body>

    <div class="sidebar" id="mainSidebar">
        <div class="sidebar-header">
            <a href="<?php echo BASE_URL . ($user_role ? htmlspecialchars($user_role) . '/index.php' : 'login.php'); ?>" class="logo">
                <i class="fas fa-university"></i> DSS Portal
            </a>
        </div>
        <div class="sidebar-search">
            <input type="text" class="form-control form-control-sm" placeholder="Search...">
        </div>
        <ul class="sidebar-menu">
            <?php if (!empty($menu_items)): ?>
                <li class="sidebar-menu-item menu-title"><span>Menu</span></li>
                <?php foreach ($menu_items as $item): ?>
                    <?php
                        $is_active_class = ($current_role_dir . '/' . $current_page_filename == $item['link']) ? 'active' : '';
                    ?>
                    <li class="sidebar-menu-item">
                        <a href="<?php echo BASE_URL . htmlspecialchars($item['link']); ?>" class="<?php echo $is_active_class; ?>">
                            <i class="menu-icon <?php echo htmlspecialchars($item['icon']); ?>"></i>
                            <span><?php echo htmlspecialchars($item['label']); ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            <?php else: ?>
                 <li class="sidebar-menu-item" style="padding: 15px 20px; color: rgba(255,255,255,0.7);">Vui lòng đăng nhập để xem menu.</li>
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
                 <button class="btn btn-light d-md-none me-2" type="button" id="sidebarToggleMobile">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="topbar-search d-none d-md-block">
                     <h4 class="page-title-h"><?php echo htmlspecialchars($page_title);?></h4>
                </div>
            </div>
            <ul class="navbar-nav topbar-right flex-row">
                <li class="nav-item"> <a class="nav-link" href="#" title="Notifications"> <i class="far fa-bell"></i> </a> </li>
                <?php if ($user_fullname): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <span class="user-avatar-top me-2"> <img src="<?php echo $user_avatar_placeholder; ?>" alt="User Avatar"> </span>
                        <span class="d-none d-sm-inline"><?php echo htmlspecialchars($user_fullname); ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                        <li><a class="dropdown-item" href="#"><i class="fas fa-user-circle me-2"></i> Profile</a></li>
                        <li><a class="dropdown-item" href="#"><i class="fas fa-cog me-2"></i> Settings</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>logout.php"><i class="fas fa-sign-out-alt me-2"></i> Logout</a></li>
                    </ul>
                </li>
                <?php else: ?>
                <li class="nav-item"> <a class="nav-link" href="<?php echo BASE_URL; ?>login.php"> <i class="fas fa-sign-in-alt me-1"></i> Login </a> </li>
                <?php endif; ?>
            </ul>
        </nav>
        <main class="content-area">
            <?php echo display_all_flash_messages(); ?>
            <!-- Nội dung của từng trang cụ thể sẽ được include hoặc viết ở đây -->
            <!-- Ví dụ, trong admin/index.php, nội dung dashboard sẽ bắt đầu sau dòng include_once này -->