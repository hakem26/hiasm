<?php
session_start();
require_once 'db.php';

$year = $_POST['year'] ?? '';
$work_month_id = $_POST['work_month_id'] ?? 'all';
$current_user_id = $_SESSION['user_id'] ?? null;

if (!$year || !$current_user_id || $work_month_id === 'all') {
    echo '';
    exit;
}

$query = "
    SELECT DISTINCT u.user_id, u.full_name
    FROM Users u
    JOIN Partners p ON (u.user_id = p.user_id1 OR u.user_id = p.user_id2)
    JOIN Work_Details wd ON p.partner_id = wd.partner_id
    JOIN Work_Months wm ON wd.work_month_id = wm.work_month_id
    WHERE YEAR(wm.start_date) = ?
    AND wd.work_month_id = ?
    AND (p.user_id1 = ? OR p.user_id2 = ?)
    AND u.user_id != ?
    ORDER BY u.full_name
";
$params = [$year, $work_month_id, $current_user_id, $current_user_id, $current_user_id];

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$partners = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($partners as $partner) {
    echo "<option value='{$partner['user_id']}'>" . htmlspecialchars($partner['full_name']) . "</option>";
}
?>