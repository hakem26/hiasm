<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'دسترسی غیرمجاز']);
    exit;
}

require_once 'db.php';

if (!isset($_POST['work_detail_id'])) {
    echo json_encode(['success' => false, 'message' => 'شناسه روز کاری مشخص نشده است']);
    exit;
}

$work_detail_id = (int) $_POST['work_detail_id'];

// دریافت وضعیت فعلی
$stmt = $pdo->prepare("SELECT status FROM Work_Details WHERE id = ?");
$stmt->execute([$work_detail_id]);
$current_status = $stmt->fetchColumn();

if ($current_status === false) {
    echo json_encode(['success' => false, 'message' => 'روز کاری یافت نشد']);
    exit;
}

// تغییر وضعیت (0 به 1 یا 1 به 0)
$new_status = $current_status ? 0 : 1;
$stmt = $pdo->prepare("UPDATE Work_Details SET status = ? WHERE id = ?");
$stmt->execute([$new_status, $work_detail_id]);

echo json_encode(['success' => true, 'status' => $new_status]);
exit;