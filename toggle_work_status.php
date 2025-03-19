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
        echo json_encode(['success' => false, 'message' => 'این روز کاری سفارش دارد و قابل تغییر وضعیت نیست']);
        exit;
    }

    // دریافت وضعیت فعلی
    $status_query = $pdo->prepare("SELECT status FROM Work_Details WHERE id = ?");
    $status_query->execute([$work_detail_id]);
    $current_status = $status_query->fetchColumn();

    // تغییر وضعیت
    $new_status = $current_status ? 0 : 1;
    $update_query = $pdo->prepare("UPDATE Work_Details SET status = ? WHERE id = ?");
    $update_query->execute([$new_status, $work_detail_id]);

    echo json_encode(['success' => true, 'message' => 'وضعیت با موفقیت تغییر کرد', 'status' => $new_status]);
} else {
    echo json_encode(['success' => false, 'message' => 'شناسه روز کاری ارسال نشده است']);
}
?>