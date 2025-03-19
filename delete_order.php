<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

require_once 'db.php';

// بررسی نقش کاربر
$is_admin = ($_SESSION['role'] === 'admin');
if ($is_admin) {
    $_SESSION['message'] = ['type' => 'danger', 'text' => 'شما به عنوان مدیر اجازه حذف سفارش را ندارید.'];
    header("Location: orders.php");
    exit;
}

$current_user_id = $_SESSION['user_id'];
$order_id = $_GET['order_id'] ?? '';

if (!$order_id) {
    $_SESSION['message'] = ['type' => 'danger', 'text' => 'شناسه سفارش مشخص نشده است.'];
    header("Location: orders.php");
    exit;
}

// بررسی دسترسی کاربر به سفارش
$stmt = $pdo->prepare("
    SELECT o.order_id, wd.partner_id
    FROM Orders o
    JOIN Work_Details wd ON o.work_details_id = wd.id
    JOIN Partners p ON wd.partner_id = p.partner_id
    WHERE o.order_id = ? AND (p.user_id1 = ? OR p.user_id2 = ?)
");
$stmt->execute([$order_id, $current_user_id, $current_user_id]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    $_SESSION['message'] = ['type' => 'danger', 'text' => 'سفارش یافت نشد یا شما دسترسی حذف آن را ندارید.'];
    header("Location: orders.php");
    exit;
}

// دریافت user_id همکار ۱ برای مدیریت موجودی
$stmt_partner = $pdo->prepare("SELECT user_id1 FROM Partners WHERE partner_id = ?");
$stmt_partner->execute([$order['partner_id']]);
$partner_data = $stmt_partner->fetch(PDO::FETCH_ASSOC);
$partner1_id = $partner_data['user_id1'] ?? null;

if (!$partner1_id) {
    $_SESSION['message'] = ['type' => 'danger', 'text' => 'همکار ۱ یافت نشد.'];
    header("Location: orders.php");
    exit;
}

// دریافت اقلام سفارش
$items_stmt = $pdo->prepare("SELECT * FROM Order_Items WHERE order_id = ?");
$items_stmt->execute([$order_id]);
$items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

$pdo->beginTransaction();
try {
    // برگرداندن موجودی محصولات برای همکار ۱
    foreach ($items as $item) {
        $stmt_product = $pdo->prepare("SELECT product_id FROM Products WHERE product_name = ? LIMIT 1");
        $stmt_product->execute([$item['product_name']]);
        $product = $stmt_product->fetch(PDO::FETCH_ASSOC);
        $product_id = $product ? $product['product_id'] : null;

        if ($product_id) {
            $stmt_inventory = $pdo->prepare("SELECT quantity FROM Inventory WHERE user_id = ? AND product_id = ? FOR UPDATE");
            $stmt_inventory->execute([$partner1_id, $product_id]);
            $inventory = $stmt_inventory->fetch(PDO::FETCH_ASSOC);

            $current_quantity = $inventory ? $inventory['quantity'] : 0;
            $new_quantity = $current_quantity + $item['quantity'];

            $stmt_update = $pdo->prepare("INSERT INTO Inventory (user_id, product_id, quantity) VALUES (?, ?, ?) 
                                       ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)");
            $stmt_update->execute([$partner1_id, $product_id, $new_quantity]);
        }
    }

    // حذف پرداخت‌های مربوط به سفارش
    $stmt = $pdo->prepare("DELETE FROM Order_Payments WHERE order_id = ?");
    $stmt->execute([$order_id]);

    // حذف اقلام سفارش
    $stmt = $pdo->prepare("DELETE FROM Order_Items WHERE order_id = ?");
    $stmt->execute([$order_id]);

    // حذف سفارش
    $stmt = $pdo->prepare("DELETE FROM Orders WHERE order_id = ?");
    $stmt->execute([$order_id]);

    $pdo->commit();
    $_SESSION['message'] = ['type' => 'success', 'text' => 'سفارش با موفقیت حذف شد.'];
    header("Location: orders.php");
    exit;
} catch (Exception $e) {
    $pdo->rollBack();
    $_SESSION['message'] = ['type' => 'danger', 'text' => 'خطا در حذف سفارش: ' . $e->getMessage()];
    header("Location: orders.php");
    exit;
}
?>