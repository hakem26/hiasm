<?php
session_start();
require_once 'db.php';
require_once 'jdf.php';
require_once 'persian_year.php';

$jalali_year = $_POST['year'] ?? '';
$work_month_id = $_POST['work_month_id'] ?? 'all';
$current_user_id = $_SESSION['user_id'] ?? null;

if (!$jalali_year || !$current_user_id || $work_month_id === 'all') {
    echo '';
    exit;
}

// دریافت همه تاریخ‌های شروع از Work_Months
$stmt = $pdo->query("SELECT DISTINCT start_date FROM Work_Months");
$work_months_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// پیدا کردن سال‌های میلادی معادل سال شمسی
$gregorian_years = [];
foreach ($work_months_data as $month) {
    $year_jalali = get_persian_year($month['start_date']);
    if ($year_jalali == $jalali_year) {
        $gregorian_year = date('Y', strtotime($month['start_date']));
        $gregorian_years[] = $gregorian_year;
    }
}
$gregorian_years = array_unique($gregorian_years);

if (empty($gregorian_years)) {
    echo '';
    exit;
}

// بررسی نقش کاربر
$stmt = $pdo->prepare("SELECT role FROM Users WHERE user_id = ?");
$stmt->execute([$current_user_id]);
$user_role = $stmt->fetchColumn();

$query = "
    SELECT DISTINCT u.user_id, u.full_name
    FROM Users u
    JOIN Partners p ON (u.user_id = p.user_id1 OR u.user_id = p.user_id2)
    JOIN Work_Details wd ON p.partner_id = wd.partner_id
    JOIN Work_Months wm ON wd.work_month_id = wm.work_month_id
    WHERE YEAR(wm.start_date) IN (" . implode(',', array_fill(0, count($gregorian_years), '?')) . ")
    AND wd.work_month_id = ?
";
$params = array_merge($gregorian_years, [$work_month_id]);

if ($user_role !== 'admin') {
    $query .= " AND (p.user_id1 = ? OR p.user_id2 = ?)";
    $params[] = $current_user_id;
    $params[] = $current_user_id;
    $query .= " AND u.user_id != ?";
    $params[] = $current_user_id;
}

$query .= " ORDER BY u.full_name";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$partners = $stmt->fetchAll(PDO::FETCH_ASSOC);

// لاگ برای دیباگ
error_log("Partners query result: " . print_r($partners, true));

foreach ($partners as $partner) {
    echo "<option value='{$partner['user_id']}'>" . htmlspecialchars($partner['full_name']) . "</option>";
}
?>