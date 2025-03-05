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
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>سیستم مدیریت فروش</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <!-- Vazir Font -->
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@100;200;300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Vazirmatn', sans-serif;
            background-color: #1a202c;
            padding-left: 250px; /* فضای منوی سمت چپ */
        }
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            width: 250px;
            background-color: #2d3748;
            z-index: 1000;
            transition: width 0.3s;
        }
        .sidebar .nav-link {
            color: #a0aec0;
            padding: 15px 20px;
            display: flex;
            align-items: center;
        }
        .sidebar .nav-link:hover {
            color: white;
            background-color: #4a5568;
        }
        .sidebar .nav-link i {
            margin-right: 10px; /* برای راست‌چین بودن */
        }
        .navbar {
            background-color: #2d3748;
            border-bottom: 1px solid #4a5568;
            z-index: 1100;
        }
        .navbar-brand, .navbar-text {
            color: #a0aec0;
        }
        .dropdown-menu {
            background-color: #2d3748;
            border: 1px solid #4a5568;
        }
        .dropdown-item {
            color: #a0aec0;
        }
        .dropdown-item:hover, .dropdown-item:focus {
            background-color: #4a5568;
            color: white;
        }
        .dropdown-divider {
            border-top: 1px solid #4a5568;
        }
        @media (max-width: 768px) {
            body {
                padding-left: 0;
            }
            .sidebar {
                width: 0;
                overflow: hidden;
            }
            .sidebar.open {
                width: 250px;
            }
        }
    </style>
</head>
<body>
    <!-- [BLOCK-HEADER-002] -->
    <!-- منوی سمت چپ -->
    <div class="sidebar <?php echo isset($_COOKIE['side_nav_collapsed']) && $_COOKIE['side_nav_collapsed'] == '1' ? 'collapsed' : ''; ?>">
        <ul class="nav flex-column pt-5">
            <li class="nav-item">
                <a class="nav-link" href="dashboard.php">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>داشبورد</span>
                </a>
            </li>
        </ul>
    </div>

    <!-- منوی بالا -->
    <nav class="navbar navbar-expand navbar-dark fixed-top">
        <div class="container-fluid">
            <button class="btn btn-outline-light ms-3" type="button" id="sidebarToggle">
                <i class="fas fa-bars"></i>
            </button>
            <span class="navbar-text mx-auto">تاریخ: <?php echo $jalali_date; ?></span>
            <div class="dropdown ms-3">
                <a href="#" class="text-light" data-bs-toggle="dropdown">
                    <i class="fas fa-user-circle fa-2x"></i>
                </a>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li class="dropdown-item"><?php echo htmlspecialchars($full_name); ?></li>
                    <li class="dropdown-item"><?php echo $role; ?></li>
                    <li><hr class="dropdown-divider"></li>
                    <li class="dropdown-item"><i class="fas fa-cog me-2"></i> تنظیمات</li>
                    <li class="dropdown-item"><a href="logout.php" class="text-decoration-none text-light"><i class="fas fa-sign-out-alt me-2"></i> خروج</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- اسکریپت‌ها -->
    <script>
        // [BLOCK-HEADER-003]
        document.addEventListener('DOMContentLoaded', () => {
            const sidebarToggle = document.querySelector('#sidebarToggle');
            const sidebar = document.querySelector('.sidebar');

            sidebarToggle.addEventListener('click', () => {
                if (window.innerWidth <= 768px) {
                    sidebar.classList.toggle('open');
                } else {
                    sidebar.classList.toggle('collapsed');
                    document.cookie = `side_nav_collapsed=${sidebar.classList.contains('collapsed') ? '1' : '0'}; path=/`;
                }
            });
        });
    </script>