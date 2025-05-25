<?php
session_start();
require_once 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'لطفاً ابتدا وارد شوید.']);
    exit;
}

// لاگ داده‌های ارسالی
file_put_contents('debug.log', date('Y-m-d H:i:s') . ' - POST: ' . print_r($_POST, true) . "\n", FILE_APPEND);

$user_id = $_SESSION['user_id'];
$action = $_POST['action'] ?? '';

try {
    $pdo->beginTransaction();

    if ($action === 'update') {
        $transaction_id = (int)($_POST['transaction_id'] ?? 0);
        $product_id = (int)($_POST['product_id'] ?? 0);
        $new_quantity = isset($_POST['new_quantity']) ? (int)$_POST['new_quantity'] : -1;

        // لاگ مقادیر
        file_put_contents('debug.log', date('Y-m-d H:i:s') . " - Update: transaction_id=$transaction_id, product_id=$product_id, new_quantity=$new_quantity\n", FILE_APPEND);

        if ($transaction_id <= 0) {
            throw new Exception('شناسه تراکنش نامعتبر است.');
        }
        if ($product_id <= 0) {
            throw new Exception('شناسه محصول نامعتبر است.');
        }
        if ($new_quantity < 0) {
            throw new Exception('مقدار تعداد جدید نامعتبر است.');
        }

        // چک کردن مالکیت تراکنش
        $stmt = $pdo->prepare("SELECT user_id, product_id, quantity FROM Inventory_Transactions WHERE id = ?");
        $stmt->execute([$transaction_id]);
        $transaction = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$transaction) {
            throw new Exception('تراکنش یافت نشد.');
        }

        if ($transaction['user_id'] != $user_id && $_SESSION['role'] !== 'admin') {
            throw new Exception('شما اجازه ویرایش این تراکنش را ندارید.');
        }

        if ($transaction['product_id'] != $product_id) {
            throw new Exception('شناسه محصول مطابقت ندارد.');
        }

        // محاسبه تغییر در موجودی
        $old_quantity = $transaction['quantity'];
        $signed_new_quantity = $old_quantity > 0 ? $new_quantity : -$new_quantity;

        // دریافت موجودی فعلی
        $stmt_inventory = $pdo->prepare("SELECT quantity FROM Inventory WHERE user_id = ? AND product_id = ? FOR UPDATE");
        $stmt_inventory->execute([$user_id, $product_id]);
        $inventory = $stmt_inventory->fetch(PDO::FETCH_ASSOC);
        $current_quantity = $inventory ? $inventory['quantity'] : 0;

        // محاسبه موجودی جدید
        $quantity_diff = $signed_new_quantity - $old_quantity;
        $new_inventory_quantity = $current_quantity + $quantity_diff;

        if ($new_inventory_quantity < 0) {
            throw new Exception("موجودی کافی نیست. موجودی فعلی: $current_quantity");
        }

        // به‌روزرسانی موجودی در Inventory
        $stmt_update_inventory = $pdo->prepare("
            INSERT INTO Inventory (user_id, product_id, quantity) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)
        ");
        $stmt_update_inventory->execute([$user_id, $product_id, $new_inventory_quantity]);

        // به‌روزرسانی مقدار در Inventory_Transactions
        $stmt_update_transaction = $pdo->prepare("UPDATE Inventory_Transactions SET quantity = ? WHERE id = ?");
        $stmt_update_transaction->execute([$signed_new_quantity, $transaction_id]);

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'تعداد با موفقیت به‌روزرسانی شد.']);
        exit;
    }

    if ($action === 'delete') {
        $transaction_id = (int)($_POST['transaction_id'] ?? 0);
        $product_id = (int)($_POST['product_id'] ?? 0);

        // لاگ مقادیر
        file_put_contents('debug.log', date('Y-m-d H:i:s') . " - Delete: transaction_id=$transaction_id, product_id=$product_id\n", FILE_APPEND);

        if ($transaction_id <= 0) {
            throw new Exception('شناسه تراکنش نامعتبر است.');
        }
        if ($product_id <= 0) {
            throw new Exception('شناسه محصول نامعتبر است.');
        }

        // چک کردن مالکیت تراکنش
        $stmt = $pdo->prepare("SELECT user_id, product_id, quantity FROM Inventory_Transactions WHERE id = ?");
        $stmt->execute([$transaction_id]);
        $transaction = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$transaction) {
            throw new Exception('تراکنش یافت نشد.');
        }

        if ($transaction['user_id'] != $user_id && $_SESSION['role'] !== 'admin') {
            throw new Exception('شما اجازه حذف این تراکنش را ندارید.');
        }

        if ($transaction['product_id'] != $product_id) {
            throw new Exception('شناسه محصول مطابقت ندارد.');
        }

        // دریافت موجودی فعلی
        $stmt_inventory = $pdo->prepare("SELECT quantity FROM Inventory WHERE user_id = ? AND product_id = ? FOR UPDATE");
        $stmt_inventory->execute([$user_id, $product_id]);
        $inventory = $stmt_inventory->fetch(PDO::FETCH_ASSOC);
        $current_quantity = $inventory ? $inventory['quantity'] : 0;

        // اصلاح موجودی (برعکس کردن اثر تراکنش)
        $old_quantity = $transaction['quantity'];
        $new_inventory_quantity = $current_quantity - $old_quantity;

        if ($new_inventory_quantity < 0) {
            throw new Exception("موجودی کافی نیست. موجودی فعلی: $current_quantity");
        }

        // به‌روزرسانی موجودی در Inventory
        $stmt_update_inventory = $pdo->prepare("
            INSERT INTO Inventory (user_id, product_id, quantity) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)
        ");
        $stmt_update_inventory->execute([$user_id, $product_id, $new_inventory_quantity]);

        // حذف تراکنش
        $stmt_delete = $pdo->prepare("DELETE FROM Inventory_Transactions WHERE id = ?");
        $stmt_delete->execute([$transaction_id]);

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'تراکنش با موفقیت حذف شد.']);
        exit;
    }

    throw new Exception('عملیات نامعتبر.');
} catch (Exception $e) {
    $pdo->rollBack();
    file_put_contents('debug.log', date('Y-m-d H:i:s') . " - Error: " . $e->getMessage() . "\n", FILE_APPEND);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>