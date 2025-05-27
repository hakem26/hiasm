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

if (!$order_id || !$work_month_id) {
    $_SESSION['message'] = ['type' => 'danger', 'text' => 'شناسه سفارش یا ماه کاری مشخص نشده است.'];
    header("Location: orders.php?work_month_id=$work_month_id");
    header("Location: orders.php");
    exit;
}

// بررسی دسترسی و نوع سفارش
$stmt = $pdo->prepare("
    SELECT o.order_id, o.is_main_order, wd.partner_id
    FROM Orders o
    JOIN Work_Details wd ON o.work_details_id = wd.id
    JOIN Partners p ON wd.partner_id = p.id
    WHERE o.order_id = ? AND (p.user_id1 = ? OR p.user_id2 = ?)
");
$stmt->execute([$order_id, $current_user_id, $current_user_id]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    $_SESSION['message'] = ['type' => 'danger', 'text' => 'سفارش یافت نشد یا شما دسترسی حذف آن را ندارید.'];
    header("Location: orders.php?work_month_id=$work_month_id");
    exit;
}

// انتخاب user_id برای مدیریت موجودی
$is_main_order = $order['is_main_order'];
$user_id_for_inventory = $current_user_id; // پیش‌فرض برای پیش‌فاکتور

if ($is_main_order) {
    // برای فاکتور اصلی، موجودی برای partner1_id
    $stmt_partner = $pdo->prepare("SELECT user_id1 FROM Partners WHERE partner_id = ?");
    $stmt_partner->execute([$order['partner_id']]);
    $partner_data = $stmt_partner->fetch(PDO::FETCH_ASSOC);
    $user_id_for_inventory = $partner_data['user_id1'] ?? null;

    if (!$user_id_for_inventory) {
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'همکار ۱ یافت نشد.'];
        header("Location: orders.php?work_month_id=$work_month_id");
        exit;
    }
}

// دریافت اقلام سفارش
$items_stmt = $pdo->prepare("SELECT * FROM Order_Items WHERE order_id = ? AND product_name != 'ارسال پستی'");
$items_stmt->execute([$order_id]);
$items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

$pdo->beginTransaction();
try {
    // برگرداندن موجودی محصولات
    foreach ($items as $item) {
        $stmt_product = $pdo->prepare("SELECT product_id FROM Products WHERE product_name = ? LIMIT 1");
        $stmt_product->execute([$item['product_name']]);
        $product = $stmt_product->fetch(PDO::FETCH_ASSOC);
        $product_id = $product ? $product['product_id'] : null;

        if ($product_id) {
            $stmt_inventory = $pdo->prepare("SELECT quantity FROM Inventory WHERE user_id = ? AND product_id = ? FOR UPDATE");
            $stmt_inventory->execute([$user_id_for_inventory, $product_id]);
            $inventory = $stmt_inventory->fetch(PDO::FETCH_ASSOC);

            $current_quantity = $inventory ? $inventory['quantity'] : 0;
            $new_quantity = $current_quantity + $item['quantity'];

            $stmt_update = $pdo->prepare("
                INSERT INTO Inventory (user_id, product_id, quantity)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE quantity = ?
            ");
            $stmt_update->execute([$user_id_for_inventory, $product_id, $new_quantity, $new_quantity]);
        }
    }

    // حذف پرداخت‌های مربوط به سفارش
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
    $_SESSION['message'] = ['type' => 'danger', 'text' => 'خطا در حذف سفارش: ' . $e->getMessage()];
    header("Location: orders.php?work_month_id=$work_month_id");
    exit;
}
?>