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
    <!-- Bootstrap RTL CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" integrity="sha384-dpuaG1suU0eT09tx5plTaGMLBsfDLzUCCUXOY2j/LSvXYuG6Bqs43ALlhIqAJVRb" crossorigin="anonymous">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <!-- Vazir Font -->
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@100;200;300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Vazirmatn', sans-serif;
            padding-top: 56px;
        }
        .navbar {
            z-index: 1000;
        }
        .sidebar {
            position: fixed;
            top: 0;
            right: 0;
            bottom: 0;
            width: 250px;
            z-index: 900; /* زیر منوی بالا */
            transition: width 0.3s;
        }
        .sidebar.collapsed {
            width: 70px;
        }
        .sidebar .nav-link {
            color: #212529;
        }
        .sidebar .nav-link i {
            margin-left: 10px;
        }
        .sidebar.collapsed .nav-link span {
            display: none;
        }
        @media (max-width: 768px) {
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
    <!-- منوی بالا -->
    <nav class="navbar navbar-expand navbar-light bg-light fixed-top">
        <div class="container-fluid">
            <div class="d-flex align-items-center">
                <a href="#" class="text-dark me-3" data-bs-toggle="dropdown">
                    <i class="fas fa-user-circle fa-2x"></i>
                </a>
                <span class="navbar-text"><?php echo $jalali_date; ?></span>
                <ul class="dropdown-menu mt-2 dropdown-menu-end">
                    <li class="dropdown-item"><?php echo htmlspecialchars($full_name); ?></li>
                    <li class="dropdown-item"><?php echo $role; ?></li>
                    <li><hr class="dropdown-divider"></li>
                    <li class="dropdown-item"><i class="fas fa-cog me-2"></i> تنظیمات</li>
                    <li class="dropdown-item"><a href="logout.php" class="text-decoration-none text-dark"><i class="fas fa-sign-out-alt me-2"></i> خروج</a></li>
                </ul>
            </div>
            <div class="navbar-text mx-auto">داشبورد</div>
            <button class="btn btn-outline-secondary ms-3" type="button" id="sidebarToggle">
                <i class="fas fa-bars"></i>
            </button>
        </div>
    </nav>

    <!-- منوی راست -->
    <div class="sidebar bg-light <?php echo isset($_COOKIE['side_nav_collapsed']) && $_COOKIE['side_nav_collapsed'] == '1' ? 'collapsed' : ''; ?>">
        <ul class="nav flex-column mt-5">
            <li class="nav-item">
                <a class="nav-link" href="dashboard.php">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>داشبورد</span>
                </a>
            </li>
        </ul>
    </div>

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