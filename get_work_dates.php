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
$user_id = $_POST['user_id'] ?? '';
$current_user_id = $_SESSION['user_id'] ?? null;

if (!$year || !$current_user_id) {
    echo '';
    exit;
}

// بررسی نقش کاربر
$stmt = $pdo->prepare("SELECT role FROM Users WHERE user_id = ?");
$stmt->execute([$current_user_id]);
$user_role = $stmt->fetchColumn();

$query = "
    SELECT DISTINCT wd.work_date
    FROM Work_Details wd
    JOIN Work_Months wm ON wd.work_month_id = wm.work_month_id
    WHERE YEAR(wm.start_date) = ?
";
$params = [$year];

if ($work_month_id !== 'all') {
    $query .= " AND wd.work_month_id = ?";
    $params[] = $work_month_id;
}

if ($user_id !== 'all') {
    $query .= " AND wd.partner_id IN (SELECT partner_id FROM Partners WHERE user_id1 = ?)";
    $params[] = $user_id;
} elseif ($user_role !== 'admin') {
    $query .= " AND (wd.partner_id IN (SELECT partner_id FROM Partners WHERE user_id1 = ?) OR wd.agency_owner_id = ?)";
    $params[] = $current_user_id;
    $params[] = $current_user_id;
}

$query .= " ORDER BY wd.work_date DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$work_dates = $stmt->fetchAll(PDO::FETCH_COLUMN);

foreach ($work_dates as $date) {
    echo "<option value='$date'>" . gregorian_to_jalali_format($date) . "</option>";
}
?>