<?php
session_start();
require_once 'db.php';

header('Content-Type: application/json');

function respond($success, $message = '', $data = []) {
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ]);
    exit;
}

$product_id = $_POST['product_id'] ?? '';
$user_id = $_POST['user_id'] ?? ''; // همون همکار فعلی که درخواست داده

if (!$product_id || !$user_id) {
    error_log("get_inventory.php - Missing parameters: product_id=$product_id, user_id=$user_id");
    respond(false, 'شناسه محصول یا کاربر مشخص نشده است.', ['product_id' => $product_id, 'user_id' => $user_id]);
}

// پیدا کردن همکار 1 برای این روز کاری
$work_details_id = $_POST['work_details_id'] ?? ''; // از درخواست می‌گیریم یا از سشن
if ($work_details_id) {
    $stmt_work = $pdo->prepare("SELECT partner_id FROM Work_Details WHERE id = ?");
    $stmt_work->execute([$work_details_id]);
    $work_info = $stmt_work->fetch(PDO::FETCH_ASSOC);

    if ($work_info) {
        $stmt_partner = $pdo->prepare("SELECT user_id1 FROM Partners WHERE partner_id = ?");
        $stmt_partner->execute([$work_info['partner_id']]);
        $partner_data = $stmt_partner->fetch(PDO::FETCH_ASSOC);
        $partner1_id = $partner_data['user_id1'] ?? null;
    }
}

if (!isset($partner1_id)) {
    error_log("get_inventory.php - Could not determine partner1_id for work_details_id: $work_details_id");
    respond(false, 'همکار 1 یافت نشد.');
}

// اگه کاربر فعلی همکار 2 باشه، موجودی همکار 1 رو نشون بده
$inventory_user_id = $partner1_id; // همیشه موجودی همکار 1 رو می‌بینیم

try {
    $stmt = $pdo->prepare("SELECT quantity FROM Inventory WHERE user_id = ? AND product_id = ?");
    $stmt->execute([$inventory_user_id, $product_id]);
    $inventory = $stmt->fetch(PDO::FETCH_ASSOC);

    $quantity = $inventory ? (int)$inventory['quantity'] : 0;

    error_log("get_inventory.php - user_id: $user_id, inventory_user_id: $inventory_user_id, product_id: $product_id, quantity: $quantity");

    respond(true, 'موجودی با موفقیت دریافت شد.', ['inventory' => $quantity]);
} catch (Exception $e) {
    error_log("get_inventory.php - Error: " . $e->getMessage());
    respond(false, 'خطا در دریافت موجودی: ' . $e->getMessage());
}
?>