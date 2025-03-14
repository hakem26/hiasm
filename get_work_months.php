<?php
session_start();
require_once 'db.php';
require_once 'jdf.php';

// تابع برای دریافت نام ماه شمسی
function get_jalali_month_name($month) {
    $month_names = [
        1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد',
        4 => 'تیر', 5 => 'مرداد', 6 => 'شهریور',
        7 => 'مهر', 8 => 'آبان', 9 => 'آذر',
        10 => 'دی', 11 => 'بهمن', 12 => 'اسفند'
    ];
    return $month_names[$month] ?? '';
}

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'لطفاً وارد شوید.']);
    exit;
}

$year = $_GET['year'] ?? '';
$gregorian_year = $year ? ($year - 621) : '';

$work_months = [];
if ($year) {
    $stmt = $pdo->prepare("SELECT work_month_id, start_date, end_date FROM Work_Months WHERE YEAR(start_date) = ? ORDER BY start_date DESC");
    $stmt->execute([$gregorian_year]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $start_date = explode('-', $row['start_date']);
        $jalali_year = $start_date[0] + 621;
        $jalali_month = (int)$start_date[1];
        $month_name = get_jalali_month_name($jalali_month) . ' ' . $jalali_year;
        $work_months[] = [
            'work_month_id' => $row['work_month_id'],
            'month_name' => $month_name
        ];
    }
}

echo json_encode(['success' => true, 'months' => $work_months]);