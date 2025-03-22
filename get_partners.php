<?php
require_once 'db.php';

$month_id = $_POST['month_id'];
$user_id = $_POST['user_id'];

$stmt = $pdo->prepare("
    SELECT DISTINCT u.user_id, u.full_name
    FROM Work_Details wd
    JOIN Partners p ON wd.partner_id = p.partner_id
    JOIN Users u ON u.user_id IN (p.user_id1, p.user_id2)
    WHERE wd.work_month_id = ? AND (p.user_id1 = ? OR p.user_id2 = ?)
    AND u.user_id != ?
");
$stmt->execute([$month_id, $user_id, $user_id, $user_id]);
$partners = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo '<option value="">انتخاب همکار</option>';
foreach ($partners as $partner) {
    echo "<option value='{$partner['user_id']}'>{$partner['full_name']}</option>";
}