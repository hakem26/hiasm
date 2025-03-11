<?php
// [BLOCK-ADD-WORK-MONTH-001]
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}
require_once 'db.php';
require_once 'jdf.php';

// تابع تبدیل اعداد عربی به انگلیسی
function arabic_to_english_numbers($string) {
    $arabic_numbers = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    $english_numbers = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    return str_replace($arabic_numbers, $english_numbers, $string);
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $start_date = trim($_POST['start_date']);
    $end_date = trim($_POST['end_date']);

    // تبدیل اعداد عربی به انگلیسی
    $start_date = arabic_to_english_numbers($start_date);
    $end_date = arabic_to_english_numbers($end_date);

    // دیباگ: چاپ مقادیر دریافت‌شده
    // echo "Start Date Raw: $start_date<br>End Date Raw: $end_date<br>"; exit;

    // نرمالایز کردن فرمت تاریخ به YYYY/MM/DD با پشتیبانی از جداکننده‌های مختلف
    $start_date_parts = preg_split('/[-\/]/', $start_date); // جداکننده - یا /
    $end_date_parts = preg_split('/[-\/]/', $end_date);

    if (count($start_date_parts) !== 3 || count($end_date_parts) !== 3) {
        die("فرمت تاریخ نامعتبر است! از فرمت YYYY/MM/DD یا YYYY-MM-DD استفاده کنید.");
    }

    $jy_start = str_pad($start_date_parts[0], 4, '0', STR_PAD_LEFT);
    $jm_start = str_pad($start_date_parts[1], 2, '0', STR_PAD_LEFT);
    $jd_start = str_pad($start_date_parts[2], 2, '0', STR_PAD_LEFT);
    $jy_end = str_pad($end_date_parts[0], 4, '0', STR_PAD_LEFT);
    $jm_end = str_pad($end_date_parts[1], 2, '0', STR_PAD_LEFT);
    $jd_end = str_pad($end_date_parts[2], 2, '0', STR_PAD_LEFT);

    $normalized_start_date = "$jy_start/$jm_start/$jd_start";
    $normalized_end_date = "$jy_end/$jm_end/$jd_end";

    // اعتبارسنجی فرمت
    if (!preg_match('/^\d{4}\/\d{2}\/\d{2}$/', $normalized_start_date) || !preg_match('/^\d{4}\/\d{2}\/\d{2}$/', $normalized_end_date)) {
        die("فرمت تاریخ نامعتبر است! از فرمت YYYY/MM/DD استفاده کنید.");
    }

    // تبدیل شمسی به میلادی
    list($gy, $gm, $gd) = jalali_to_gregorian($jy_start, $jm_start, $jd_start);
    $start_gregorian = "$gy-$gm-$gd"; // فرمت خروجی: YYYY-MM-DD

    list($gy, $gm, $gd) = jalali_to_gregorian($jy_end, $jm_end, $jd_end);
    $end_gregorian = "$gy-$gm-$gd"; // فرمت خروجی: YYYY-MM-DD

    try {
        $stmt = $pdo->prepare("INSERT INTO Work_Months (start_date, end_date) VALUES (?, ?)");
        $stmt->execute([$start_gregorian, $end_gregorian]);

        header("Location: work_months.php?success=1");
        exit;
    } catch (PDOException $e) {
        die("خطا در ثبت ماه کاری: " . $e->getMessage());
    }
}
?>