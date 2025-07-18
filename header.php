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
    <meta name="msapplication-TileColor" content="#ffffff">
    <meta name="msapplication-TileImage" content="/assets/icons/ms-icon-144x144.png">
    <meta name="theme-color" content="#ffffff">
    <link rel="manifest" href="/manifest.json">
    <link rel="icon" href="/assets/icons/favicon.ico" type="image/x-icon">
    <title>سیستم مدیریت فروش</title>
    <link rel="apple-touch-icon" sizes="57x57" href="/assets/icons/apple-icon-57x57.png">
    <link rel="apple-touch-icon" sizes="60x60" href="/assets/icons/apple-icon-60x60.png">
    <link rel="apple-touch-icon" sizes="72x72" href="/assets/icons/apple-icon-72x72.png">
    <link rel="apple-touch-icon" sizes="76x76" href="/assets/icons/apple-icon-76x76.png">
    <link rel="apple-touch-icon" sizes="114x114" href="/assets/icons/apple-icon-114x114.png">
    <link rel="apple-touch-icon" sizes="120x120" href="/assets/icons/apple-icon-120x120.png">
    <link rel="apple-touch-icon" sizes="144x144" href="/assets/icons/apple-icon-144x144.png">
    <link rel="apple-touch-icon" sizes="152x152" href="/assets/icons/apple-icon-152x152.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/assets/icons/apple-icon-180x180.png">
    <link rel="icon" type="image/png" sizes="192x192" href="/assets/icons/android-icon-192x192.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/icons/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="96x96" href="/assets/icons/favicon-96x96.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/assets/icons/favicon-16x16.png">
    <!-- Bootstrap RTL CSS -->
    <link rel="stylesheet" href="assets/css/bootstrap.rtl.min.css"
        integrity="sha384-dpuaG1suU0eT09tx5plTaGMLBsfDLzUCCUXOY2j/LSvXYuG6Bqs43ALlhIqAJVRb" crossorigin="anonymous">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <!-- Persian Datepicker -->
    <link rel="stylesheet" href="assets/css/persian-datepicker.min.css" />
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"
        integrity="sha256-/xUj+3OJU5yExlq6GSYGSHk7tPXikynS7ogEvDej/m4=" crossorigin="anonymous"></script>
    <script src="assets/js/persian-date.min.js"></script>
    <script src="assets/js/persian-datepicker.min.js"></script>
    <script src="assets/js/jquery.dataTables.min.js"></script>
    <script src="assets/js/dataTables.responsive.min.js"></script>
    <!-- Chart.js -->
    <script src="assets/js/chart.js"></script>
    <!-- فقط استایل اصلی DataTables -->
    <link rel="stylesheet" href="assets/css/jquery.dataTables.min.css">
    <!-- قرار دادن کدهای style و کاستوم -->
    <link rel="stylesheet" href="style.css">
    <script>
        function persianSort(a, b) {
            const persianAlphabet = 'آابپتثجچحخدذرزژسشصضطظعغفقکگلمنوهی';
            const getCharIndex = char => {
                const index = persianAlphabet.indexOf(char);
                return index === -1 ? char.charCodeAt(0) : index;
            };
            a = a.trim();
            b = b.trim();
            for (let i = 0; i < Math.min(a.length, b.length); i++) {
                const charA = getCharIndex(a[i]);
                const charB = getCharIndex(b[i]);
                if (charA !== charB) return charA - charB;
            }
            return a.length - b.length;
        }
    </script>
</head>

<body>
    <!-- منوی بالا -->
    <nav class="navbar navbar-light fixed-top" style="background-color: #e7eedb;">
        <div class="container-fluid">
            <!-- دکمه همبرگر -->
            <button class="navbar-toggler" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebarOffcanvas"
                aria-controls="sidebarOffcanvas">
                <span class="navbar-toggler-icon"></span>
            </button>
            <!-- تاریخ در وسط -->
            <span class="navbar-text mx-auto" style="color: #690974;"><?php echo $jalali_date; ?></span>
        </div>
    </nav>

    <!-- منوی کناری (Offcanvas) -->
    <div class="offcanvas offcanvas-start" data-bs-backdrop="static" tabindex="-1" id="sidebarOffcanvas"
        aria-labelledby="sidebarOffcanvasLabel">
        <div class="offcanvas-header" style="background-color: #e7eedb;">
            <h5 class="offcanvas-title" id="sidebarOffcanvasLabel">منوی سیستم</h5>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
        </div>
        <div class="offcanvas-body" style="overflow-y: auto;">
            <ul class="nav flex-column">
                <li class="nav-item">
                    <a class="nav-link <?php echo $current_page == 'dashboard_admin.php' || $current_page == 'dashboard_seller.php' ? 'active' : ''; ?>"
                        href="<?php echo $_SESSION['role'] === 'admin' ? 'dashboard_admin.php' : 'dashboard_seller.php'; ?>">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>پیشخوان</span>
                    </a>
                </li>
                <hr class="sidebar-divider">
                <li class="nav-item">
                    <a class="nav-link" href="#" data-bs-toggle="collapse" data-bs-target="#productsSubmenu"
                        aria-expanded="true" aria-controls="productsSubmenu">
                        <i class="fas fa-box"></i>
                        <span>محصولات</span>
                    </a>
                    <ul class="collapse list-unstyled show" id="productsSubmenu">
                        <li class="nav-item">
                            <a class="nav-link <?php echo $current_page == 'products.php' ? 'active' : ''; ?>"
                                href="products.php">
                                <i class="fas fa-list"></i>
                                <span>لیست</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $current_page == 'sold_products.php' ? 'active' : ''; ?>"
                                href="sold_products.php">
                                <i class="fas fa-chart-bar"></i>
                                <span>تجمیع</span>
                            </a>
                        </li>
                        <?php if ($_SESSION['role'] === 'admin'): ?>
                            <li class="nav-item">
                                <a class="nav-link <?php echo $current_page == 'inventery_products.php' ? 'active' : ''; ?>"
                                    href="inventery_products.php">
                                    <i class="fas fa-boxes"></i>
                                    <span>موجودی</span>
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </li>
                <hr class="sidebar-divider">
                <li class="nav-item">
                    <a class="nav-link <?php echo $current_page == 'orders.php' ? 'active' : ''; ?>" href="orders.php">
                        <i class="fas fa-shopping-cart"></i>
                        <span>سفارشات</span>
                    </a>
                </li>
                <?php if ($_SESSION['role'] === 'seller'): ?>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $current_page == 'temp_orders.php' ? 'active' : ''; ?>"
                            href="temp_orders.php">
                            <i class="fas fa-hourglass-half"></i>
                            <span>سفارشات موقت</span>
                        </a>
                    </li>
                <?php endif; ?>
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
                        aria-expanded="true" aria-controls="reportsSubmenu">
                        <i class="fas fa-box"></i>
                        <span>گزارشات</span>
                    </a>
                    <ul class="collapse list-unstyled show" id="reportsSubmenu">
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
                            <li class="nav-item">
                                <a class="nav-link <?php echo $current_page == 'seller-inventory-report.php' ? 'active' : ''; ?>"
                                    href="seller-inventory-report.php">
                                    <i class="fas fa-warehouse"></i>
                                    <span>تخصیص</span>
                                </a>
                            </li>
                        <?php endif; ?>
                        <!-- <li class="nav-item">
                            <a class="nav-link <?php echo $current_page == 'inventory_report.php' ? 'active' : ''; ?>"
                                href="inventory_report.php">
                                <i class="fas fa-warehouse"></i>
                                <span>تخصیص</span>
                            </a>
                        </li> -->
                    </ul>
                </li>
                <hr class="sidebar-divider">
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
    </div>

    <!-- محتوای اصلی -->
    <div class="main-content">
        <!-- محتوا در فایل‌های دیگر قرار می‌گیره -->