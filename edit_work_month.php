<?php
// [BLOCK-EDIT-WORK-MONTH-001]
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}
require_once 'db.php';
require_once 'jdf.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $month_id = $_POST['month_id'];
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];

    // تبدیل شمسی به میلادی
    list($jy, $jm, $jd) = explode('/', $start_date);
    list($gy, $gm, $gd) = jalali_to_gregorian($jy, $jm, $jd);
    $start_gregorian = "$gy-$gm-$gd"; // فرمت خروجی: YYYY-MM-DD

    list($jy, $jm, $jd) = explode('/', $end_date);
    list($gy, $gm, $gd) = jalali_to_gregorian($jy, $jm, $jd);
    $end_gregorian = "$gy-$gm-$gd"; // فرمت خروجی: YYYY-MM-DD

    try {
        $stmt = $pdo->prepare("UPDATE Work_Months SET start_date = ?, end_date = ? WHERE work_month_id = ?");
        $stmt->execute([$start_gregorian, $end_gregorian, $month_id]);

        header("Location: work_months.php");
        exit;
    } catch (PDOException $e) {
        die("خطا در ویرایش ماه کاری: " . $e->getMessage());
    }
}
?>