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
    header("Location: orders.php");
    exit;
}
$current_user_id = $_SESSION['user_id'];

// دریافت payment_id از GET
$payment_id = $_GET['payment_id'] ?? '';
if (!$payment_id) {
    echo "<div class='container-fluid mt-5'><div class='alert alert-danger text-center'>شناسه پرداخت مشخص نشده است.</div></div>";
    exit;
}

// بررسی دسترسی کاربر به پرداخت (از طریق order_id مرتبط)
$stmt = $pdo->prepare("
    SELECT p.payment_id
    FROM Payments p
    JOIN Orders o ON p.order_id = o.order_id
    JOIN Work_Details wd ON o.work_details_id = wd.id
    JOIN Partners pr ON wd.partner_id = pr.partner_id
    WHERE p.payment_id = ? AND (pr.user_id1 = ? OR pr.user_id2 = ?)
");
$stmt->execute([$payment_id, $current_user_id, $current_user_id]);
$payment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$payment) {
    echo "<div class='container-fluid mt-5'><div class='alert alert-danger text-center'>پرداخت یافت نشد یا شما دسترسی حذف آن را ندارید.</div></div>";
    exit;
}

// حذف پرداخت
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare("DELETE FROM Payments WHERE payment_id = ?");
    $stmt->execute([$payment_id]);

    $pdo->commit();

    // ریدایرکت به صفحه سفارشات با پیام موفقیت
    header("Location: orders.php?message=پرداخت با موفقیت حذف شد");
    exit;
} catch (Exception $e) {
    $pdo->rollBack();
    echo "<div class='container-fluid mt-5'><div class='alert alert-danger text-center'>خطا در حذف پرداخت: " . $e->getMessage() . "</div></div>";
    exit;
}
?>