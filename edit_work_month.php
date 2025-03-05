<?php
// [BLOCK-EDIT-WORK-MONTH-001]
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}
require_once 'db.php';

// تبدیل تاریخ شمسی به میلادی
require_once 'jdf.php';
$month_id = $_POST['month_id'];
$start_date = $_POST['start_date'];
$end_date = $_POST['end_date'];

// تبدیل تاریخ شمسی به میلادی با استفاده از jdate
$start_gregorian = jdate('Y-m-d', '', '', '', $start_date, 'gregorian');
$end_gregorian = jdate('Y-m-d', '', '', '', $end_date, 'gregorian');

try {
    $stmt = $pdo->prepare("UPDATE Work_Months SET start_date = ?, end_date = ? WHERE work_month_id = ?");
    $stmt->execute([$start_gregorian, $end_gregorian, $month_id]);

    header("Location: work_months.php");
    exit;
} catch (PDOException $e) {
    die("خطا در ویرایش ماه کاری: " . $e->getMessage());
}
?>