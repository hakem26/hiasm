<?php
session_start();
require_once 'db.php';
require_once 'jdf.php';
require_once 'persian_year.php';

function gregorian_to_jalali_format($gregorian_date) {
    list($gy, $gm, $gd) = explode('-', $gregorian_date);
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
    return "$jy/$jm/$jd";
}

$jalali_year = $_POST['year'] ?? '';
$current_user_id = $_SESSION['user_id'] ?? null;

if (!$jalali_year || !$current_user_id) {
    echo '';
    exit;
}

// دریافت همه تاریخ‌های شروع از Work_Months
$stmt = $pdo->query("SELECT DISTINCT start_date FROM Work_Months");
$work_months_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// پیدا کردن سال‌های میلادی معادل سال شمسی
$gregorian_years = [];
foreach ($work_months_data as $month) {
    $year_jalali = get_persian_year($month['start_date']);
    if ($year_jalali == $jalali_year) {
        $gregorian_year = date('Y', strtotime($month['start_date']));
        $gregorian_years[] = $gregorian_year;
    }
}
$gregorian_years = array_unique($gregorian_years);

if (empty($gregorian_years)) {
    echo '';
    exit;
}

// بررسی نقش کاربر
$stmt = $pdo->prepare("SELECT role FROM Users WHERE user_id = ?");
$stmt->execute([$current_user_id]);
$user_role = $stmt->fetchColumn();

$query = "
    SELECT DISTINCT wm.work_month_id, wm.start_date, wm.end_date 
    FROM Work_Months wm
    JOIN Work_Details wd ON wm.work_month_id = wd.work_month_id
    JOIN Partners p ON wd.partner_id = p.partner_id
    WHERE YEAR(wm.start_date) IN (" . implode(',', array_fill(0, count($gregorian_years), '?')) . ")
";
$params = $gregorian_years;

if ($user_role !== 'admin') {
    $query .= " AND (p.user_id1 = ? OR p.user_id2 = ?)";
    $params[] = $current_user_id;
    $params[] = $current_user_id;
}

$query .= " ORDER BY wm.start_date DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$months = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($months as $month) {
    echo "<option value='{$month['work_month_id']}'>" . gregorian_to_jalali_format($month['start_date']) . " تا " . gregorian_to_jalali_format($month['end_date']) . "</option>";
}
?>