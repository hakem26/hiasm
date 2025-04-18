<?php
session_start();
require_once 'db.php';
require_once 'jdf.php';

function gregorian_to_jalali_format($gregorian_date) {
    list($gy, $gm, $gd) = explode('-', $gregorian_date);
    $gy = (int)$gy;
    $gm = (int)$gm;
    $gd = (int)$gd;
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd); // تابع از jdf.php
    return sprintf("%04d/%02d/%02d", $jy, $jm, $jd);
}

$year_jalali = isset($_POST['year']) ? (int) $_POST['year'] : null;
$user_id = isset($_POST['user_id']) ? $_POST['user_id'] : 'all';

if (!isset($_SESSION['user_id'])) {
    echo '<option value="">انتخاب ماه</option>';
    exit;
}

// اگر سال انتخاب نشده، همه ماه‌ها رو برگردون
if (!$year_jalali) {
    $stmt = $pdo->prepare("
        SELECT DISTINCT wm.work_month_id, wm.start_date, wm.end_date 
        FROM Work_Months wm
        JOIN Work_Details wd ON wm.work_month_id = wd.work_month_id
        ORDER BY wm.start_date DESC
    ");
    $stmt->execute();
} else {
    // محاسبه بازه میلادی برای سال شمسی
    $gregorian_start_year = $year_jalali - 579;
    $gregorian_end_year = $gregorian_start_year + 1;
    $start_date = "$gregorian_start_year-03-21";
    $end_date = "$gregorian_end_year-03-21";

    if ($year_jalali == 1404) {
        $start_date = "2025-03-21";
        $end_date = "2026-03-21";
    } elseif ($year_jalali == 1403) {
        $start_date = "2024-03-20";
        $end_date = "2025-03-21";
    }

    if ($user_id === 'all') {
        // حالت "همه" برای ادمین
        $stmt = $pdo->prepare("
            SELECT DISTINCT wm.work_month_id, wm.start_date, wm.end_date 
            FROM Work_Months wm
            JOIN Work_Details wd ON wm.work_month_id = wd.work_month_id
            WHERE wm.start_date >= ? AND wm.start_date < ?
            ORDER BY wm.start_date DESC
        ");
        $stmt->execute([$start_date, $end_date]);
    } else {
        // برای همکار خاص برای ادمین
        $stmt = $pdo->prepare("
            SELECT DISTINCT wm.work_month_id, wm.start_date, wm.end_date 
            FROM Work_Months wm
            JOIN Work_Details wd ON wm.work_month_id = wd.work_month_id
            JOIN Partners p ON wd.partner_id = p.partner_id
            WHERE wm.start_date >= ? AND wm.start_date < ? AND (p.user_id1 = ? OR p.user_id2 = ?)
            ORDER BY wm.start_date DESC
        ");
        $stmt->execute([$start_date, $end_date, $user_id, $user_id]);
    }
}

$months = $stmt->fetchAll(PDO::FETCH_ASSOC);

// دیباگ
if (empty($months)) {
    error_log("get_admin_months.php: No months found for year_jalali $year_jalali");
} else {
    foreach ($months as $month) {
        error_log("get_admin_months.php: Found month: work_month_id = {$month['work_month_id']}, start_date = {$month['start_date']}, end_date = {$month['end_date']}");
    }
}

$output = '<option value="">انتخاب ماه</option>';
foreach ($months as $month) {
    $output .= "<option value='{$month['work_month_id']}'>" . gregorian_to_jalali_format($month['start_date']) . " تا " . gregorian_to_jalali_format($month['end_date']) . "</option>";
}
echo $output;
?>