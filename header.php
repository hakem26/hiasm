<?php
// [BLOCK-HEADER-001]
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}
require_once 'db.php';
require_once 'jdf.php';

$full_name = $_SESSION['full_name'];
$role = $_SESSION['role'] === 'admin' ? 'ادمین' : 'فروشنده';
$gregorian_date = date('Y-m-d');
$jalali_date = jdate('Y/m/d', strtotime($gregorian_date));

// نام صفحه فعلی (برای این مثال، "داشبورد" فرض می‌کنیم)
$page_name = "داشبورد";
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>سیستم مدیریت فروش</title>
    <!-- Bootstrap RTL CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css"
        integrity="sha384-dpuaG1suU0eT09tx5plTaGMLBsfDLzUCCUXOY2j/LSvXYuG6Bqs43ALlhIqAJVRb" crossorigin="anonymous">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <!-- Vazir Font -->
    <link
        href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@100;200;300;400;500;600;700;800;900&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>

<body>
    <!-- [BLOCK-HEADER-002] -->
    <!-- منوی بالا -->
    <nav class="navbar navbar-expand navbar-light fixed-top">
        <div class="container-fluid">
            <div class="d-flex align-items-center">
                <button class="btn btn-outline-secondary me-3" type="button" id="sidebarToggle">
                    <i class="fas fa-bars"></i>
                </button>
                <span class="navbar-text">صفحه <?php echo $page_name; ?></span>
            </div>
            <span class="navbar-text mx-auto">تاریخ: <?php echo $jalali_date; ?></span>
            <div class="dropdown ms-3">
                <a href="#" class="text-dark" data-bs-toggle="dropdown">
                    <i class="fas fa-user-circle fa-2x"></i>
                </a>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li class="dropdown-item"><?php echo htmlspecialchars($full_name); ?></li>
                    <li class="dropdown-item"><?php echo $role; ?></li>
                    <li>
                        <hr class="dropdown-divider">
                    </li>
                    <li class="dropdown-item"><i class="fas fa-cog me-2"></i> تنظیمات</li>
                    <li class="dropdown-item"><a href="logout.php" class="text-decoration-none text-dark"><i
                                class="fas fa-sign-out-alt me-2"></i> خروج</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- منوی راست -->
    <div
        class="sidebar <?php echo isset($_COOKIE['side_nav_collapsed']) && $_COOKIE['side_nav_collapsed'] == '1' ? 'collapsed' : ''; ?>">
        <ul class="nav flex-column pt-5">
            <li class="nav-item">
                <a class="nav-link" href="dashboard.php">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>داشبورد</span>
                </a>
            </li>
            <?php if ($_SESSION['role'] === 'admin'): ?>
                <li class="nav-item">
                    <a class="nav-link" href="users.php">
                        <i class="fas fa-users"></i>
                        <span>کاربران</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="partners.php">
                        <i class="fas fa-handshake"></i>
                        <span>همکاران</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="work_months.php">
                        <i class="fas fa-calendar-alt"></i>
                        <span>ماه کاری</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="work_details.php">
                        <i class="fas fa-list"></i>
                        <span>اطلاعات کار</span>
                    </a>
                </li>
            <?php endif; ?>
        </ul>
    </div>

    <!-- اسکریپت‌ها -->
    <script>
        // [BLOCK-HEADER-003]
        document.addEventListener('DOMContentLoaded', () => {
            const sidebarToggle = document.querySelector('#sidebarToggle');
            const sidebar = document.querySelector('.sidebar');

            sidebarToggle.addEventListener('click', () => {
                if (window.innerWidth <= 768) {
                    sidebar.classList.toggle('open');
                } else {
                    sidebar.classList.toggle('collapsed');
                    document.cookie = `side_nav_collapsed=${sidebar.classList.contains('collapsed') ? '1' : '0'}; path=/`;
                }
            });
        });
    </script>