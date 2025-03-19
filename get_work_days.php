<?php
session_start();
require_once 'db.php';
require_once 'jdf.php';

function gregorian_to_jalali_format($gregorian_date) {
    list($gy, $gm, $gd) = explode('-', $gregorian_date);
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
    return "$jy/$jm/$jd";
}

$year = $_POST['year'] ?? '';
$work_month_id = $_POST['work_month_id'] ?? 'all';
$partner_id = $_POST['partner_id'] ?? 'all';
$current_user_id = $_SESSION['user_id'] ?? null;

if (!$year || !$current_user_id || $work_month_id === 'all' || $partner_id === 'all') {
    echo '';
    exit;
}

$query = "
    SELECT DISTINCT wd.work_date
    FROM Work_Details wd
    JOIN Partners p ON wd.partner_id = p.partner_id
    JOIN Work_Months wm ON wd.work_month_id = wm.work_month_id
    WHERE YEAR(wm.start_date) = ?
    AND wd.work_month_id = ?
    AND (p.user_id1 = ? OR p.user_id2 = ?)
    AND (p.user_id1 = ? OR p.user_id2 = ?)
    ORDER BY wd.work_date DESC
";
$params = [$year, $work_month_id, $current_user_id, $current_user_id, $partner_id, $partner_id];

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$work_dates = $stmt->fetchAll(PDO::FETCH_COLUMN);

foreach ($work_dates as $date) {
    echo "<option value='$date'>" . gregorian_to_jalali_format($date) . "</option>";
}
?>