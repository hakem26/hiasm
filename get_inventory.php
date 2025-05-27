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
$user_id = $_POST['user_id'] ?? ''; // همون کاربر فعلی که درخواست داده
$is_sub_order = isset($_POST['is_sub_order']) && $_POST['is_sub_order'] == '1'; // چک برای پیش‌فاکتور

if (!$product_id || !$user_id) {
    error_log("get_inventory.php - Missing parameters: product_id=$product_id, user_id=$user_id");
    respond(false, 'شناسه محصول یا کاربر مشخص نشده است.', ['product_id' => $product_id, 'user_id' => $user_id]);
}

$inventory_user_id = $user_id; // پیش‌فرض: موجودی برای کاربر فعلی

if (!$is_sub_order) {
    // فقط برای فاکتورهای اصلی (مثل add_order.php) همکار ۱ رو پیدا کن
    $work_details_id = $_POST['work_details_id'] ?? '';
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
        respond(false, 'همکار ۱ یافت نشد.');
    }

    $inventory_user_id = $partner1_id; // برای فاکتورهای اصلی، موجودی همکار ۱
}

try {
    $stmt = $pdo->prepare("SELECT quantity FROM Inventory WHERE user_id = ? AND product_id = ?");
    $stmt->execute([$inventory_user_id, $product_id]);
    $inventory = $stmt->fetch(PDO::FETCH_ASSOC);

    $quantity = $inventory ? (int)$inventory['quantity'] : 0;

    error_log("get_inventory.php - user_id: $user_id, inventory_user_id: $inventory_user_id, product_id: $product_id, quantity: $quantity");

    respond(true, 'موفقیت', ['inventory' => $quantity]);
} catch (Exception $e) {
    error_log("get_inventory.php - Error: " . $e->getMessage());
    respond(false, 'خطا در دریافت موجودی: ' . $e->getMessage());
}
?>