<?php
session_start();
require_once 'db.php';

$month_id = isset($_POST['month_id']) ? (int) $_POST['month_id'] : null;
$user_id = isset($_POST['user_id']) ? (int) $_POST['user_id'] : null;
$is_admin = isset($_POST['is_admin']) ? filter_var($_POST['is_admin'], FILTER_VALIDATE_BOOLEAN) : false;
$current_user_id = $_SESSION['user_id'] ?? null;

if (!$month_id || !$current_user_id) {
    echo '<option value="">انتخاب همکار</option>';
    exit;
}

$partners = [];

if ($is_admin) {
    // برای ادمین: همه همکارانی که توی جدول Partners وجود دارن
    $stmt = $pdo->prepare("
        SELECT DISTINCT u.user_id, u.full_name
        FROM Users u
        WHERE u.user_id IN (
            SELECT user_id1 FROM Partners
            UNION
            SELECT user_id2 FROM Partners
        )
        ORDER BY u.full_name
    ");
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($users as $user) {
        $partners[] = [
            'user_id' => $user['user_id'],
            'full_name' => $user['full_name']
        ];
    }
} else {
    // برای کاربر معمولی: فقط همکارانی که با کاربر فعلی توی یه تیم هستن
    $stmt = $pdo->prepare("
        SELECT DISTINCT u.user_id, u.full_name
        FROM Users u
        JOIN Partners p ON (u.user_id = p.user_id1 OR u.user_id = p.user_id2)
        JOIN Work_Details wd ON wd.partner_id = p.partner_id
        WHERE wd.work_month_id = ? AND (p.user_id1 = ? OR p.user_id2 = ?)
        ORDER BY u.full_name
    ");
    $stmt->execute([$month_id, $current_user_id, $current_user_id]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($users as $user) {
        $partners[] = [
            'user_id' => $user['user_id'],
            'full_name' => $user['full_name']
        ];
    }
}

// دیباگ
if (empty($partners)) {
    error_log("get_partners.php: No partners found for month_id $month_id, user_id $current_user_id, is_admin " . ($is_admin ? 'true' : 'false'));
} else {
    foreach ($partners as $partner) {
        error_log("get_partners.php: Found partner: user_id = {$partner['user_id']}, full_name = {$partner['full_name']}");
    }
}

$output = '<option value="">انتخاب همکار</option>';
foreach ($partners as $partner) {
    $output .= "<option value='{$partner['user_id']}'>" . htmlspecialchars($partner['full_name']) . "</option>";
}
echo $output;
?>