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

// لاگ برای دیباگ
error_log("Selected work_month_ids in get_partners: " . print_r($selected_work_month_ids, true));

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
    JOIN Work_Months wm ON wd.work_month_id = wm.work_month_id
    WHERE wm.work_month_id IN (" . implode(',', array_fill(0, count($selected_work_month_ids), '?')) . ")
    AND wd.work_month_id = ?
    AND (p.user_id1 = ? OR p.user_id2 = ?)
";
$params = array_merge($selected_work_month_ids, [$work_month_id, $current_user_id, $current_user_id]);

$query .= " ORDER BY u.full_name";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$partners = $stmt->fetchAll(PDO::FETCH_ASSOC);

// لاگ برای دیباگ
error_log("Partners query result: " . print_r($partners, true));

// چک کردن اگه user_id1 و user_id2 یکسان باشن، فقط کاربر فعلی رو نگه دار
$filtered_partners = [];
$has_self_pair = false;
foreach ($partners as $partner) {
    $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM Partners p JOIN Work_Details wd ON p.partner_id = wd.partner_id WHERE (p.user_id1 = ? AND p.user_id2 = ?) AND wd.work_month_id = ?");
    $stmt_check->execute([$current_user_id, $current_user_id, $work_month_id]);
    $count_same = $stmt_check->fetchColumn();
    if ($count_same > 0 && $partner['user_id'] == $current_user_id) {
        $has_self_pair = true;
        break;
    }
}

if ($has_self_pair) {
    $stmt_user = $pdo->prepare("SELECT full_name FROM Users WHERE user_id = ?");
    $stmt_user->execute([$current_user_id]);
    $current_user_name = $stmt_user->fetchColumn();
    $filtered_partners[] = ['user_id' => $current_user_id, 'full_name' => $current_user_name];
} else {
    $filtered_partners = $partners;
}

foreach ($filtered_partners as $partner) {
    echo "<option value='{$partner['user_id']}'>" . htmlspecialchars($partner['full_name']) . "</option>";
}
?>