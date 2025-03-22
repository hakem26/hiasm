<?php
session_start();
require_once 'db.php';

$month_id = isset($_POST['month_id']) ? (int) $_POST['month_id'] : null;
$user_id = isset($_POST['user_id']) ? (int) $_POST['user_id'] : null;
$current_user_id = $_SESSION['user_id'] ?? null;

if (!$month_id || !$current_user_id) {
    echo '<option value="">انتخاب همکار</option>';
    exit;
}

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
$partners = $stmt->fetchAll(PDO::FETCH_ASSOC);

// دیباگ
if (empty($partners)) {
    error_log("get_partners_admin.php: No partners found for month_id $month_id, user_id $current_user_id");
} else {
    foreach ($partners as $partner) {
        error_log("get_partners_admin.php: Found partner: user_id = {$partner['user_id']}, full_name = {$partner['full_name']}");
    }
}

$output = '<option value="">انتخاب همکار</option>';
foreach ($partners as $partner) {
    $output .= "<option value='{$partner['user_id']}'>" . htmlspecialchars($partner['full_name']) . "</option>";
}
echo $output;
?>