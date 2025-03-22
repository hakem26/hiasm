<?php
session_start();
require_once 'db.php';
require_once 'jdf.php';

function gregorian_to_jalali_format($gregorian_date) {
    list($gy, $gm, $gd) = explode('-', $gregorian_date);
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
    return "$jy/$jm/$jd";
}

// تابع تبدیل سال شمسی به میلادی
function jalali_year_to_gregorian($jalali_year) {
    return $jalali_year + 579; // تقریب ساده
}

$year_jalali = isset($_POST['year']) ? (int) $_POST['year'] : null;
$user_id = isset($_POST['user_id']) ? $_POST['user_id'] : null;
$current_user_id = $_SESSION['user_id'] ?? null;

if (!$year_jalali || !$current_user_id) {
    echo '<option value="">انتخاب ماه</option>';
    exit;
}

// تبدیل سال شمسی به میلادی
$year_miladi = jalali_year_to_gregorian($year_jalali);

// بررسی نقش کاربر
$stmt = $pdo->prepare("SELECT role FROM Users WHERE user_id = ?");
$stmt->execute([$current_user_id]);
$user_role = $stmt->fetchColumn();

if ($user_role === 'admin') {
    // برای ادمین
    if ($user_id === 'all') {
        // حالت "همه" برای ادمین
        $stmt = $pdo->prepare("
            SELECT DISTINCT wm.work_month_id, wm.start_date, wm.end_date 
            FROM Work_Months wm
            JOIN Work_Details wd ON wm.work_month_id = wd.work_month_id
            WHERE YEAR(wm.start_date) = ?
            ORDER BY wm.start_date DESC
        ");
        $stmt->execute([$year_miladi]);
    } else {
        // برای همکار خاص برای ادمین
        $stmt = $pdo->prepare("
            SELECT DISTINCT wm.work_month_id, wm.start_date, wm.end_date 
            FROM Work_Months wm
            JOIN Work_Details wd ON wm.work_month_id = wd.work_month_id
            JOIN Partners p ON wd.partner_id = p.partner_id
            WHERE YEAR(wm.start_date) = ? AND p.user_id1 = ?
            ORDER BY wm.start_date DESC
        ");
        $stmt->execute([$year_miladi, $user_id]);
    }
} else {
    // برای فروشندگان
    $stmt = $pdo->prepare("
        SELECT DISTINCT wm.work_month_id, wm.start_date, wm.end_date 
        FROM Work_Months wm
        JOIN Work_Details wd ON wm.work_month_id = wd.work_month_id
        JOIN Partners p ON wd.partner_id = p.partner_id
        WHERE YEAR(wm.start_date) = ? 
        AND (p.user_id1 = ? OR p.user_id2 = ? OR wd.agency_owner_id = ?)
        ORDER BY wm.start_date DESC
    ");
    $stmt->execute([$year_miladi, $current_user_id, $current_user_id, $current_user_id]);
}

$months = $stmt->fetchAll(PDO::FETCH_ASSOC);

// دیباگ
if (empty($months)) {
    error_log("get_months.php: No months found for year_jalali $year_jalali, year_miladi $year_miladi");
} else {
    foreach ($months as $month) {
        error_log("get_months.php: Found month: work_month_id = {$month['work_month_id']}, start_date = {$month['start_date']}, end_date = {$month['end_date']}");
    }
}

$output = '<option value="">انتخاب ماه</option>';
foreach ($months as $month) {
    $output .= "<option value='{$month['work_month_id']}'>" . gregorian_to_jalali_format($month['start_date']) . " تا " . gregorian_to_jalali_format($month['end_date']) . "</option>";
}
echo $output;
?>