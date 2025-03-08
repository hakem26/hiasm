<?php
require_once 'db.php';
require_once 'jdf.php';

function gregorian_to_jalali_format($gregorian_date) {
    list($gy, $gm, $gd) = explode('-', $gregorian_date);
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
    return "$jy/$jm/$jd";
}

$year = $_POST['year'];
$user_id = $_POST['user_id'];
$stmt = $pdo->prepare("
    SELECT DISTINCT wm.work_month_id, wm.start_date, wm.end_date 
    FROM Work_Months wm
    JOIN Work_Details wd ON wm.work_month_id = wd.work_month_id
    WHERE YEAR(wm.start_date) = ? AND wd.user_id = ?
    ORDER BY wm.start_date DESC
");
$stmt->execute([$year, $user_id]);
$months = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo '<option value="">انتخاب ماه</option>';
foreach ($months as $month) {
    echo "<option value='{$month['work_month_id']}'>" . gregorian_to_jalali_format($month['start_date']) . " تا " . gregorian_to_jalali_format($month['end_date']) . "</option>";
}
?>