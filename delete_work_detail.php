<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'دسترسی غیرمجاز']);
    exit;
}

if (isset($_POST['work_detail_id'])) {
    $work_detail_id = (int) $_POST['work_detail_id'];

    // بررسی وجود سفارش برای این روز کاری
    $order_check_query = $pdo->prepare("SELECT COUNT(*) FROM Orders WHERE work_details_id = ?");
    $order_check_query->execute([$work_detail_id]);
    $order_count = $order_check_query->fetchColumn();

    if ($order_count > 0) {
        echo json_encode(['success' => false, 'message' => 'این روز کاری سفارش دارد و قابل حذف نیست']);
        exit;
    }

    // حذف روز کاری
    $delete_query = $pdo->prepare("DELETE FROM Work_Details WHERE id = ?");
    $delete_query->execute([$work_detail_id]);

    echo json_encode(['success' => true, 'message' => 'روز کاری با موفقیت حذف شد']);
} else {
    echo json_encode(['success' => false, 'message' => 'شناسه روز کاری ارسال نشده است']);
}
?>