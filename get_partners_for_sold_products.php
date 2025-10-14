<?php
session_start();
require_once 'db.php';
require_once 'jdf.php';
require_once 'persian_year.php';

$jalali_year = $_POST['year'] ?? '';
$work_month_id = $_POST['work_month_id'] ?? 'all';
$partner_type = $_POST['partner_type'] ?? 'all';
$current_user_id = $_SESSION['user_id'] ?? null;

if (!$jalali_year || !$current_user_id || $work_month_id === 'all') {
    echo '';
    exit;
}

// پیدا کردن work_month_idهایی که توی سال شمسی انتخاب‌شده هستن
$selected_work_month_ids = [];
$stmt = $pdo->query("SELECT work_month_id, start_date FROM Work_Months");
$all_work_months = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($all_work_months as $month) {
    $jalali_year_from_date = get_persian_year($month['start_date']);
    if ($jalali_year_from_date == $jalali_year) {
        $selected_work_month_ids[] = $month['work_month_id'];
    }
}

if (empty($selected_work_month_ids)) {
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
    WHERE wd.work_month_id IN (" . implode(',', array_fill(0, count($selected_work_month_ids), '?')) . ")
    AND wd.work_month_id = ?
";
$params = array_merge($selected_work_month_ids, [$work_month_id]);

if ($user_role !== 'admin') {
    $query .= " AND (p.user_id1 = ? OR p.user_id2 = ?)";
    $params[] = $current_user_id;
    $params[] = $current_user_id;
    $query .= " AND u.user_id != ?";
    $params[] = $current_user_id;
}

if ($partner_type !== 'all') {
    $query .= " AND (";
    if ($partner_type === 'leader') {
        $query .= "p.user_id1 = ?";
        $params[] = $current_user_id;
    } elseif ($partner_type === 'sub') {
        $query .= "p.user_id2 = ?";
        $params[] = $current_user_id;
    }
    $query .= ")";
}

$query .= " ORDER BY u.full_name";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$partners = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($partners as $partner) {
    echo "<option value='{$partner['user_id']}'>" . htmlspecialchars($partner['full_name']) . "</option>";
}
?>