<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$is_admin = ($_SESSION['role'] === 'admin');
$current_user_id = $_SESSION['user_id'];
$temp_order_id = isset($_GET['temp_order_id']) ? (int)$_GET['temp_order_id'] : 0;

if ($temp_order_id <= 0) {
    $_SESSION['message'] = ['type' => 'danger', 'text' => 'شماره سفارش نامعتبر است.'];
    header("Location: temp_orders.php");
    exit;
}

// چک کردن مالکیت سفارش یا دسترسی ادمین
$stmt_check = $pdo->prepare("SELECT user_id FROM Temp_Orders WHERE temp_order_id = ?");
$stmt_check->execute([$temp_order_id]);
$order = $stmt_check->fetch(PDO::FETCH_ASSOC);

if (!$order || (!$is_admin && $order['user_id'] != $current_user_id)) {
    $_SESSION['message'] = ['type' => 'danger', 'text' => 'شما اجازه حذف این سفارش را ندارید.'];
    header("Location: temp_orders.php");
    exit;
}

try {
    $pdo->beginTransaction();

    // حذف پرداخت‌ها
    $stmt_payments = $pdo->prepare("DELETE FROM Order_Payments WHERE order_id = ?");
    $stmt_payments->execute([$temp_order_id]);

    // حذف آیتم‌ها
    $stmt_items = $pdo->prepare("DELETE FROM Temp_Order_Items WHERE temp_order_id = ?");
    $stmt_items->execute([$temp_order_id]);

    // حذف سفارش
    $stmt_order = $pdo->prepare("DELETE FROM Temp_Orders WHERE temp_order_id = ?");
    $stmt_order->execute([$temp_order_id]);

    $pdo->commit();
    $_SESSION['message'] = ['type' => 'success', 'text' => 'سفارش با موفقیت حذف شد.'];
} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Error deleting temp order $temp_order_id: " . $e->getMessage());
    $_SESSION['message'] = ['type' => 'danger', 'text' => 'خطا در حذف سفارش. لطفاً دوباره تلاش کنید.'];
}

header("Location: temp_orders.php");
exit;
?>