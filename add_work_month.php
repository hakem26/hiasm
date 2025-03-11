<?php
// [BLOCK-ADD-WORK-MONTH-001]
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}
require_once 'db.php';
require_once 'jdf.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];

    // اعتبارسنجی فرمت تاریخ (YYYY/MM/DD)
    if (!preg_match('/^\d{4}\/\d{2}\/\d{2}$/', $start_date) || !preg_match('/^\d{4}\/\d{2}\/\d{2}$/', $end_date)) {
        die("فرمت تاریخ نامعتبر است! از فرمت YYYY/MM/DD استفاده کنید.");
    }

    // تبدیل شمسی به میلادی
    list($jy, $jm, $jd) = explode('/', $start_date);
    list($gy, $gm, $gd) = jalali_to_gregorian($jy, $jm, $jd);
    $start_gregorian = "$gy-$gm-$gd"; // فرمت خروجی: YYYY-MM-DD

    list($jy, $jm, $jd) = explode('/', $end_date);
    list($gy, $gm, $gd) = jalali_to_gregorian($jy, $jm, $jd);
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