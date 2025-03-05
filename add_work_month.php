<?php
// [BLOCK-ADD-WORK-MONTH-001]
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}
require_once 'db.php';

// تبدیل تاریخ شمسی به میلادی
require_once 'jdf.php';
$start_date = $_POST['start_date'];
$end_date = $_POST['end_date'];

$start_gregorian = jdate('Y-m-d', '', '', '', $start_date, 'gregorian');
$end_gregorian = jdate('Y-m-d', '', '', '', $end_date, 'gregorian');

$stmt = $pdo->prepare("INSERT INTO Work_Months (start_date, end_date) VALUES (?, ?)");
$stmt->execute([$start_gregorian, $end_gregorian]);

header("Location: work_months.php");
exit;
?>