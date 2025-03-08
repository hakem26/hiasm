<?php
require_once 'db.php';
require_once 'jdf.php';

function gregorian_to_jalali_format($gregorian_date) {
    list($gy, $gm, $gd) = explode('-', $gregorian_date);
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
    return "$jy/$jm/$jd";
}

$month_id = $_POST['month_id'];
$user_id = $_POST['user_id'];
$stmt = $pdo->prepare("
    SELECT work_details_id, work_date, user1, user2 
    FROM Work_Details 
    WHERE work_month_id = ? AND (user_id1 = ? OR user_id2 = ?)
    ORDER BY work_date ASC
");
$stmt->execute([$month_id, $user_id, $user_id]);
$work_days = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo '<option value="">همه روزها</option>';
foreach ($work_days as $day) {
    echo "<option value='{$day['work_details_id']}'>" . gregorian_to_jalali_format($day['work_date']) . " ({$day['user1']} - {$day['user2']})</option>";
}
?>