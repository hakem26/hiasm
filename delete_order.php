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
$work_month_id = $_GET['work_month_id'] ?? '';

if (!$order_id) {
    $_SESSION['message'] = ['type' => 'danger', 'text' => 'شناسه سفارش مشخص نشده است.'];
    header("Location: orders.php" . ($work_month_id ? "?work_month_id=$work_month_id" : ""));
    exit;
}

// بررسی دسترسی و نوع سفارش
$stmt = $pdo->prepare("
    SELECT o.order_id, o.is_main_order, wd.partner_id, wd.work_month_id
    FROM Orders o
    JOIN Work_Details wd ON o.work_details_id = wd.id
    JOIN Partners p ON wd.partner_id = p.id
    WHERE o.order_id = ? AND (p.user_id1 = ? OR p.user_id2 = ?)
");
$stmt->execute([$order_id, $current_user_id, $current_user_id]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    $_SESSION['message'] = ['type' => 'danger', 'text' => 'سفارش یافت نشد یا شما دسترسی حذف آن را ندارید.'];
    header("Location: orders.php" . ($work_month_id ? "?work_month_id=$work_month_id" : ""));
    exit;
}

// اگر work_month_id از URL نیومده، از دیتابیس بگیریم
$work_month_id = $work_month_id ?: $order['work_month_id'];
if (!$work_month_id) {
    $_SESSION['message'] = ['type' => 'danger', 'text' => 'ماه کاری شناسایی نشد.'];
    header("Location: orders.php");
    exit;
}

// فقط پیش‌فاکتورها قابل حذف هستند
if ($order['is_main_order']) {
    $_SESSION['message'] = ['type' => 'danger', 'text' => 'فاکتورهای اصلی قابل حذف نیستند.'];
    header("Location: orders.php?work_month_id=$work_month_id");
    exit;
}

// انتخاب کاربر برای مدیریت موجودی (برای پیش‌فاکتور، همیشه خود کاربر)
$user_id_for_inventory = $current_user_id;

// دریافت اقلام سفارش
$items_stmt = $pdo->prepare("SELECT product_id, quantity FROM Order_Items WHERE order_id = ? AND product_name != 'ارسال پستی'");
$items_stmt->execute([$order_id]);
$items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

$pdo->beginTransaction();
try {
    // برگرداندن موجودی محصولات
    foreach ($items as $item) {
        if ($item['product_id']) {
            $stmt_inventory = $pdo->prepare("
                SELECT quantity FROM Inventory WHERE user_id = ? AND product_id = ? FOR UPDATE
            ");
            $stmt_inventory->execute([$user_id_for_inventory, $item['product_id']]);
            $inventory = $stmt_inventory->fetch(PDO::FETCH_ASSOC);

            $current_quantity = $inventory ? (int)$inventory['quantity'] : 0;
            $new_quantity = $current_quantity + $item['quantity'];

            $stmt_update = $pdo->prepare("
                INSERT INTO Inventory (user_id, product_id, quantity)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE quantity = ?
            ");
            $stmt_update->execute([$user_id_for_inventory, $item['product_id'], $new_quantity, $new_quantity]);
        }
    }

    // حذف پرداخت‌ها
    $stmt = $pdo->prepare("DELETE FROM Order_Payments WHERE order_id = ?");
    $stmt->execute([$order_id]);

    // حذف از Invoice_Prices
    $stmt = $pdo->prepare("DELETE FROM Invoice_Prices WHERE order_id = ?");
    $stmt->execute([$order_id]);

    // حذف اقلام سفارش
    $stmt = $pdo->prepare("DELETE FROM Order_Items WHERE order_id = ?");
    $stmt->execute([$order_id]);

    // حذف سفارش
    $stmt = $pdo->prepare("DELETE FROM Orders WHERE order_id = ?");
    $stmt->execute([$order_id]);

    $pdo->commit();
    $_SESSION['message'] = ['type' => 'success', 'text' => 'سفارش با موفقیت حذف شد.'];
    header("Location: orders.php?work_month_id=$work_month_id");
    exit;
} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Error in delete_order.php: " . $e->getMessage());
    $_SESSION['message'] = ['type' => 'danger', 'text' => 'خطا در حذف سفارش: ' . $e->getMessage()];
    header("Location: orders.php?work_month_id=$work_month_id");
    exit;
}
?>