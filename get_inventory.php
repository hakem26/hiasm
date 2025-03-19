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
$user_id = $_POST['user_id'] ?? '';

if (!$product_id || !$user_id) {
    error_log("get_inventory.php - Missing parameters: product_id=$product_id, user_id=$user_id");
    respond(false, 'شناسه محصول یا کاربر مشخص نشده است.', ['product_id' => $product_id, 'user_id' => $user_id]);
}

// بررسی دسترسی
if ($_SESSION['role'] === 'seller') {
    $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM Partners WHERE user_id1 = ? AND user_id1 = ?");
    $stmt_check->execute([$_SESSION['user_id'], $user_id]);
    if ($stmt_check->fetchColumn() == 0) {
        respond(false, 'شما دسترسی به موجودی این کاربر ندارید.');
    }
}

try {
    $stmt = $pdo->prepare("SELECT quantity FROM Inventory WHERE user_id = ? AND product_id = ?");
    $stmt->execute([$user_id, $product_id]);
    $inventory = $stmt->fetch(PDO::FETCH_ASSOC);

    $quantity = $inventory ? (int)$inventory['quantity'] : 0;

    // لاگ برای دیباگ
    error_log("get_inventory.php - user_id: $user_id, product_id: $product_id, quantity: $quantity");

    respond(true, 'موجودی با موفقیت دریافت شد.', ['inventory' => $quantity]);
} catch (Exception $e) {
    error_log("get_inventory.php - Error: " . $e->getMessage());
    respond(false, 'خطا در دریافت موجودی: ' . $e->getMessage());
}
?>