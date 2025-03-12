<ع?php
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

// نام صفحه فعلی
$page_name = basename($_SERVER['PHP_SELF'], ".php");
$page_name = $page_name === 'dashboard_admin' || $page_name === 'dashboard_seller' ? 'داشبورد' : $page_name;
$page_name = $page_name === 'products' ? 'محصولات' : $page_name;
$page_name = $page_name === 'orders' ? 'سفارشات' : $page_name;
$page_name = $page_name === 'users' ? 'کاربران' : $page_name;
$page_name = $page_name === 'partners' ? 'همکاران' : $page_name;
$page_name = $page_name === 'work_months' ? 'ماه کاری' : $page_name;
$page_name = $page_name === 'work_details' ? 'اطلاعات کار' : $page_name;
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="favicon.png" type="image/x-icon">
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
    <!-- قرار دادن کدهای style و کاستوم -->
    <link rel="stylesheet" href="style.css">
    <!-- Persian Datepicker -->
    <link rel="stylesheet" href="assets/css/persian-datepicker.min.css" />
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"
        integrity="sha256-/xUj+3OJU5yExlq6GSYGSHk7tPXikynS7ogEvDej/m4=" crossorigin="anonymous"></script>
    <script src="assets/js/persian-date.min.js"></script>
    <script src="assets/js/persian-datepicker.min.js"></script>
</head>

<body>
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
                <a href="#" class="text-dark dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fas fa-user-circle fa-2x"></i>
                </a>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li class="dropdown-item"><?php echo htmlspecialchars($full_name); ?></li>
                    <li class="dropdown-item"><?php echo $role; ?></li>
                    <li>
                        <hr class="dropdown-divider">
                    </li>
                    <li class="dropdown-item"><i class="fas fa-cog me-2"></i> تنظیمات</li>
                    <li class="dropdown-item">
                        <a href="logout.php" class="text-decoration-none text-dark"><i
                                class="fas fa-sign-out-alt me-2"></i> خروج</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- منوی کناری -->
    <
        class="sidebar <?php echo isset($_COOKIE['side_nav_collapsed']) && $_COOKIE['side_nav_collapsed'] == '1' ? 'collapsed' : ''; ?>">
        <ul class="nav flex-column">
            <!-- همه -->
            <li class="nav-item">
                <a class="nav-link"
                    href="<?php echo $_SESSION['role'] === 'admin' ? 'dashboard_admin.php' : 'dashboard_seller.php'; ?>">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>داشبورد</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="#" data-bs-toggle="collapse" data-bs-target="#productsSubmenu" aria-expanded="false">
                    <i class="fas fa-box"></i>
                    <span>محصولات</span>
                </a>
                <ul class="collapse list-unstyled" id="productsSubmenu">
                    <li class="nav-item">
                        <a class="nav-link" href="products.php">
                            <i class="fas fa-list"></i>
                            <span>لیست</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="product_summary.php">
                            <i class="fas fa-chart-bar"></i>
                            <span>تجمیع</span>
                        </a>
                    </li>
                </ul>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="orders.php">
                    <i class="fas fa-shopping-cart"></i>
                    <span>سفارشات</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="work_details.php">
                    <i class="fas fa-info-circle"></i>
                    <span>اطلاعات کار</span>
                </a>
            </li>
            <li class="nav-item"></li>
                <a class="nav-link" href="#" data-bs-toggle="collapse" data-bs-target="#reportsSubmenu" aria-expanded="false">
                    <i class="fas fa-chart-line"></i>
                    <span>گزارشات</span>
                </a>
                <ul class="collapse list-unstyled" id="reportsSubmenu">
                    <li class="nav-item">
                        <a class="nav-link" href="monthly_reports.php">
                            <i class="fas fa-calendar"></i>
                            <span>ماهانه</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="summary_reports.php">
                            <i class="fas fa-file-alt"></i>
                            <span>خلاصه</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="sales_reports.php"></a>
                            <i class="fas fa-chart-pie"></i>
                            <span>فروش</span>
                        </a>
                    </li>
                </ul>
            </li>
            <!-- ادمین -->
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
            <?php endif; ?>
        </ul>
    </div>

    <!-- محتوای اصلی -->
    <div class="main-content">
        <!-- محتوا در فایل‌های دیگر قرار می‌گیره -->

        <script>
            // مدیریت منوی کناری
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