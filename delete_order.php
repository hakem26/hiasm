<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

require_once 'db.php';

// بررسی نقش کاربر (فقط فروشنده‌ها می‌تونن سفارش حذف کنن)
$is_admin = ($_SESSION['role'] === 'admin');
if ($is_admin) {
    header("Location: orders.php");
    exit;
}
$current_user_id = $_SESSION['user_id'];

// دریافت order_id از GET
$order_id = $_GET['order_id'] ?? '';
if (!$order_id) {
    echo "<div class='container-fluid mt-5'><div class='alert alert-danger text-center'>شناسه سفارش مشخص نشده است.</div></div>";
    exit;
}

// بررسی دسترسی کاربر به سفارش
$stmt = $pdo->prepare("
    SELECT o.order_id
    FROM Orders o
    JOIN Work_Details wd ON o.work_details_id = wd.id
    JOIN Partners p ON wd.partner_id = p.partner_id
    WHERE o.order_id = ? AND (p.user_id1 = ? OR p.user_id2 = ?)
");
$stmt->execute([$order_id, $current_user_id, $current_user_id]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    echo "<div class='container-fluid mt-5'><div class='alert alert-danger text-center'>سفارش یافت نشد یا شما دسترسی حذف آن را ندارید.</div></div>";
    exit;
}

// حذف سفارش و اقلام مرتبط
$pdo->beginTransaction();
try {
    // حذف اقلام مرتبط از Order_Items
    $stmt = $pdo->prepare("DELETE FROM Order_Items WHERE order_id = ?");
    $stmt->execute([$order_id]);

    // حذف سفارش از Orders
    $stmt = $pdo->prepare("DELETE FROM Orders WHERE order_id = ?");
    $stmt->execute([$order_id]);

    $pdo->commit();

    // ریدایرکت به صفحه سفارشات با پیام موفقیت
    header("Location: orders.php?message=سفارش با موفقیت حذف شد");
    exit;
} catch (Exception $e) {
    $pdo->rollBack();
    echo "<div class='container-fluid mt-5'><div class='alert alert-danger text-center'>خطا در حذف سفارش: " . $e->getMessage() . "</div></div>";
    exit;
}