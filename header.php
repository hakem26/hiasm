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

// نام صفحه فعلی
$current_page = basename($_SERVER['PHP_SELF']);
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
    <!-- Persian Datepicker -->
    <link rel="stylesheet" href="assets/css/persian-datepicker.min.css" />
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"
        integrity="sha256-/xUj+3OJU5yExlq6GSYGSHk7tPXikynS7ogEvDej/m4=" crossorigin="anonymous"></script>
    <script src="assets/js/persian-date.min.js"></script>
    <script src="assets/js/persian-datepicker.min.js"></script>
    <script src="assets/js/jquery.dataTables.min.js"></script>
    <script src="assets/js/dataTables.responsive.min.js"></script>
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- فقط استایل اصلی DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css">
    <!-- قرار دادن کدهای style و کاستوم -->
    <link rel="stylesheet" href="style.css">
    <!-- <style>
        #productsTable th,
        #productsTable td {
            text-align: center !important;
            vertical-align: middle !important;
        }

        /* وسط‌چین کردن هدر و بدنه جدول */
        table.dataTable thead th,
        table.dataTable tbody td {
            text-align: center !important;
            vertical-align: middle !important;
            white-space: nowrap !important;
            /* جلوگیری از شکستن متن */
            overflow: hidden;
            text-overflow: ellipsis;
            /* نمایش ... برای متن طولانی */
        }
    </style> -->
</head>

<body>
    <!-- منوی بالا -->
    <nav class="navbar navbar-expand navbar-light fixed-top" style="background-color: #e7eedb;">
        <div class="container-fluid">
            <div class="d-flex align-items-center">
                <button class="btn btn-outline-secondary me-3" type="button" id="sidebarToggle">
                    <i class="fas fa-bars"></i>
                </button>
                <!-- <span class="navbar-text">صفحه <?php echo $page_name; ?></span> -->
            </div>
            <h6 class="navbar-text mx-auto" style="color: #690974;"><?php echo $jalali_date; ?></h6>
        </div>
    </nav>

    <!-- منوی کناری -->
    <div class="sidebar collapsed" style="overflow-y: auto;"> <!-- پیش‌فرض باریک (collapsed) -->
        <ul class="nav flex-column">
            <li class="nav-item">
                <a class="nav-link <?php echo $current_page == 'dashboard_admin.php' || $current_page == 'dashboard_seller.php' ? 'active' : ''; ?>"
                    href="<?php echo $_SESSION['role'] === 'admin' ? 'dashboard_admin.php' : 'dashboard_seller.php'; ?>">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>داشبورد</span>
                </a>
            </li>
            <hr class="sidebar-divider">
            <li class="nav-item">
                <a class="nav-link" href="#" data-bs-toggle="collapse" data-bs-target="#productsSubmenu"
                    aria-expanded="true" aria-controls="productsSubmenu"> <!-- پیش‌فرض باز -->
                    <i class="fas fa-box"></i>
                    <span>محصولات</span>
                </a>
                <ul class="collapse list-unstyled show" id="productsSubmenu"> <!-- پیش‌فرض باز -->
                    <li class="nav-item">
                        <a class="nav-link <?php echo $current_page == 'products.php' ? 'active' : ''; ?>"
                            href="products.php">
                            <i class="fas fa-list"></i>
                            <span>لیست </span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $current_page == 'sold_products.php' ? 'active' : ''; ?>"
                            href="sold_products.php">
                            <i class="fas fa-chart-bar"></i>
                            <span>تجمیع</span>
                        </a>
                    </li>
                </ul>
            </li>
            <hr class="sidebar-divider">
            <li class="nav-item">
                <a class="nav-link <?php echo $current_page == 'orders.php' ? 'active' : ''; ?>" href="orders.php">
                    <i class="fas fa-shopping-cart"></i>
                    <span>سفارشات</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $current_page == 'work_details.php' ? 'active' : ''; ?>"
                    href="work_details.php">
                    <i class="fas fa-info-circle"></i>
                    <span>اطلاعات کار</span>
                </a>
            </li>
            <hr class="sidebar-divider">
            <li class="nav-item">
                <a class="nav-link" href="#" data-bs-toggle="collapse" data-bs-target="#reportsSubmenu"
                    aria-expanded="true" aria-controls="reportsSubmenu"> <!-- پیش‌فرض باز -->
                    <i class="fas fa-box"></i>
                    <span>گزارشات</span>
                </a>
                <ul class="collapse list-unstyled show" id="reportsSubmenu"> <!-- پیش‌فرض باز -->
                    <?php if ($_SESSION['role'] === 'seller'): ?>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $current_page == 'report-daily.php' ? 'active' : ''; ?>"
                                href="report-monthly.php">
                                <i class="fas fa-calendar-alt"></i>
                                <span>ماهانه</span>
                            </a>
                        </li>
                    <?php endif; ?>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $current_page == 'report-summary.php' ? 'active' : ''; ?>"
                            href="<?php echo $_SESSION['role'] === 'admin' ? 'report_summary_admin.php' : 'report-summary.php'; ?>">
                            <i class="fas fa-chart-pie"></i>
                            <span>خلاصه</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $current_page == 'report-sell.php' || $current_page == 'report-admin-sell.php' ? 'active' : ''; ?>"
                            href="<?php echo $_SESSION['role'] === 'admin' ? 'report-admin-sell.php' : 'report-sell.php'; ?>">
                            <i class="fas fa-dollar-sign"></i>
                            <span>فروش</span>
                        </a>
                    </li>
                    <?php if ($_SESSION['role'] === 'seller'): ?>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $current_page == 'report-bill.php' ? 'active' : ''; ?>"
                            href="report-bill.php">
                            <i class="fas fa-money-bill-wave"></i>
                            <span>مالی</span>
                        </a>
                    </li>
                    <?php endif; ?>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $current_page == 'inventory_report.php' ? 'active' : ''; ?>"
                            href="inventory_report.php">
                            <i class="fas fa-warehouse"></i>
                            <span>موجودی</span>
                        </a>
                    </li>
                </ul>
            </li>
            <hr class="sidebar-divider">
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
            <li class="nav-item">
                <a class="nav-link" href="logout.php">
                    <i class="fas fa-sign-out-alt me-2"></i>
                    <span>خروج</span>
                </a>
            </li>
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
                    if (window.innerWidth <= 600) {
                        // در موبایل: کامل باز یا بسته می‌شه
                        sidebar.classList.toggle('open');
                        if (!sidebar.classList.contains('open')) {
                            sidebar.classList.remove('collapsed'); // مطمئن می‌شیم در حالت بسته کامل بسته بشه
                        }
                    } else {
                        // در دسکتاپ و تا 600px: حالت باریک یا باز
                        sidebar.classList.toggle('collapsed');
                        document.cookie = `side_nav_collapsed=${sidebar.classList.contains('collapsed') ? '1' : '0'}; path=/`;
                    }
                });

                // تنظیم اولیه منو به حالت باریک (collapsed) در دسکتاپ
                if (window.innerWidth > 600) {
                    sidebar.classList.add('collapsed');
                }
            });
        </script>