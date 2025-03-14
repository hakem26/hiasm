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
    respond(false, 'شناسه محصول یا کاربر مشخص نشده است.');
}

try {
    $stmt = $pdo->prepare("SELECT quantity FROM Inventory WHERE user_id = ? AND product_id = ?");
    $stmt->execute([$user_id, $product_id]);
    $inventory = $stmt->fetch(PDO::FETCH_ASSOC);

    $quantity = $inventory ? $inventory['quantity'] : 0;

    respond(true, 'موجودی با موفقیت دریافت شد.', ['inventory' => $quantity]);
} catch (Exception $e) {
    respond(false, 'خطا در دریافت موجودی: ' . $e->getMessage());
}