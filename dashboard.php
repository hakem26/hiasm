<?php
// [BLOCK-DASHBOARD-001]
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}
require_once 'db.php';

// استفاده از کتابخانه php-jalali برای تبدیل تاریخ
// دانلود دستی از: https://github.com/jalalhosseini/php-jalali
require_once 'jdf.php'; // فرض بر این است که فایل jdf.php در پروژه اضافه شده

$full_name = $_SESSION['full_name'];
$gregorian_date = date('Y-m-d H:i:s'); // تاریخ میلادی فعلی
$jalali_date = jdate('Y/m/d H:i:s', strtotime($gregorian_date)); // تبدیل به شمسی
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>داشبورد</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <!-- Vazir Font -->
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@100;200;300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Vazirmatn', sans-serif;
            background-color: #f8f9fa;
        }
        .welcome-box {
            margin: 50px auto;
            max-width: 600px;
            padding: 20px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
            text-align: center;
        }
    </style>
</head>
<body>
    <!-- [BLOCK-DASHBOARD-002] -->
    <div class="container">
        <div class="welcome-box">
            <h3>تاریخ: <?php echo $jalali_date; ?></h3>
            <h1>سلام <?php echo htmlspecialchars($full_name); ?> عزیز، خوش آمدی!</h1>
            <a href="logout.php" class="btn btn-danger mt-3">
                <i class="fas fa-sign-out-alt"></i> خروج
            </a>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>