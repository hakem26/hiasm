<?php
session_start();
require_once 'db.php';

header('Content-Type: application/json');

$response = ['success' => false, 'message' => ''];

try {
    $product_name = $_POST['product_name'] ?? '';
    $quantity = (int) ($_POST['quantity'] ?? 0);
    $user_id = $_POST['user_id'] ?? '';
    $action = $_POST['action'] ?? ''; // 'add' برای اضافه کردن به موجودی، 'subtract' برای کم کردن

    if (empty($product_name) || $quantity <= 0 || empty($user_id) || !in_array($action, ['add', 'subtract'])) {
        throw new Exception('پارامترهای ورودی نامعتبر هستند.');
    }

    // دریافت product_id از نام محصول
    $stmt_product = $pdo->prepare("SELECT product_id FROM Products WHERE product_name = ? LIMIT 1");
    $stmt_product->execute([$product_name]);
    $product = $stmt_product->fetch(PDO::FETCH_ASSOC);
    $product_id = $product ? $product['product_id'] : null;

    if (!$product_id) {
        throw new Exception('محصول یافت نشد.');
    }

    // شروع تراکنش
    $pdo->beginTransaction();

    // دریافت موجودی فعلی
    $stmt_inventory = $pdo->prepare("SELECT quantity FROM Inventory WHERE user_id = ? AND product_id = ? FOR UPDATE");
    $stmt_inventory->execute([$user_id, $product_id]);
    $inventory = $stmt_inventory->fetch(PDO::FETCH_ASSOC);
    $current_quantity = $inventory ? $inventory['quantity'] : 0;

    // محاسبه موجودی جدید
    if ($action === 'add') {
        $new_quantity = $current_quantity + $quantity;
    } else {
        $new_quantity = $current_quantity - $quantity;
        if ($new_quantity < 0) {
            throw new Exception("موجودی کافی نیست. موجودی فعلی: $current_quantity");
        }
    }

    // به‌روزرسانی موجودی
    $stmt_update = $pdo->prepare("INSERT INTO Inventory (user_id, product_id, quantity) VALUES (?, ?, ?) 
                               ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)");
    $stmt_update->execute([$user_id, $product_id, $new_quantity]);

    $pdo->commit();

    $response['success'] = true;
    $response['message'] = 'موجودی با موفقیت به‌روزرسانی شد.';
} catch (Exception $e) {
    $pdo->rollBack();
    $response['message'] = $e->getMessage();
}

echo json_encode($response);
?>