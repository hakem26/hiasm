<?php
session_start();
require_once 'db.php';

header('Content-Type: application/json');

function sendResponse($success, $message = '', $data = []) {
    echo json_encode(['success' => $success, 'message' => $message, 'data' => $data]);
    exit;
}

$user_id = $_POST['user_id'] ?? null;
$product_id = $_POST['product_id'] ?? null;

if (!$user_id || !$product_id) {
    sendResponse(false, 'شناسه کاربر یا محصول ارائه نشده است.');
}

try {
    $stmt = $pdo->prepare("
        SELECT quantity 
        FROM Inventory 
        WHERE product_id = ? AND user_id = ?
    ");
    $stmt->execute([$product_id, $user_id]);
    $inventory = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($inventory) {
        sendResponse(true, 'موجودی با موفقیت دریافت شد.', ['inventory' => (int)$inventory['quantity']]);
    } else {
        sendResponse(true, 'موجودی برای این محصول یافت نشد.', ['inventory' => 0]);
    }
} catch (Exception $e) {
    sendResponse(false, 'خطا در دریافت موجودی: ' . $e->getMessage());
}
?>