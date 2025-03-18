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

// بررسی وجود سفارش
$stmt = $pdo->prepare("SELECT COUNT(*) FROM Orders WHERE work_details_id = ?");
$stmt->execute([$work_detail_id]);
$order_count = $stmt->fetchColumn();

if ($order_count > 0) {
    echo json_encode(['success' => false, 'message' => 'این روز کاری به دلیل ثبت سفارش قابل حذف نیست']);
    exit;
}

// حذف روز کاری
$stmt = $pdo->prepare("DELETE FROM Work_Details WHERE id = ?");
$stmt->execute([$work_detail_id]);

echo json_encode(['success' => true]);
exit;