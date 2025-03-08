<?php
require_once 'db.php';
require_once 'jdf.php';

function gregorian_to_jalali_format($gregorian_date) {
    list($gy, $gm, $gd) = explode('-', $gregorian_date);
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
    return "$jy/$jm/$jd";
}

$month_id = $_POST['month_id'];
$partner_id = $_POST['partner_id'];
$user_id = $_POST['user_id'];

$stmt = $pdo->prepare("
    SELECT wd.id AS work_details_id, wd.work_date, 
           u1.username AS partner_name, 
           u2.username AS agency_owner_name
    FROM Work_Details wd
    JOIN Users u1 ON u1.user_id = wd.partner_id
    JOIN Users u2 ON u2.user_id = wd.agency_owner_id
    WHERE wd.work_month_id = ? AND (wd.partner_id = ? OR wd.agency_owner_id = ?)
    AND (wd.partner_id = ? OR wd.agency_owner_id = ?)
    ORDER BY wd.work_date ASC
");
$stmt->execute([$month_id, $user_id, $user_id, $partner_id, $partner_id]);
$work_days = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo '<option value="">انتخاب روز</option>';
foreach ($work_days as $day) {
    echo "<option value='{$day['work_details_id']}'>" . gregorian_to_jalali_format($day['work_date']) . " ({$day['partner_name']} - {$day['agency_owner_name']})</option>";
}