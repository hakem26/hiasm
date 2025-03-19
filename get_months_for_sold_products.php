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
$current_user_id = $_SESSION['user_id'] ?? null;

if (!$year || !$current_user_id) {
    echo '';
    exit;
}

$query = "
    SELECT DISTINCT wm.work_month_id, wm.start_date, wm.end_date 
    FROM Work_Months wm
    JOIN Work_Details wd ON wm.work_month_id = wd.work_month_id
    JOIN Partners p ON wd.partner_id = p.partner_id
    WHERE YEAR(wm.start_date) = ?
    AND (p.user_id1 = ? OR p.user_id2 = ?)
    ORDER BY wm.start_date DESC
";
$params = [$year, $current_user_id, $current_user_id];

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$months = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($months as $month) {
    echo "<option value='{$month['work_month_id']}'>" . gregorian_to_jalali_format($month['start_date']) . " تا " . gregorian_to_jalali_format($month['end_date']) . "</option>";
}
?>