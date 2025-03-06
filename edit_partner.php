<?php
// [BLOCK-EDIT-PARTNER-001]
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $partner_id = $_POST['partner_id'];
    $user_id1 = $_POST['user_id1'];
    $user_id2 = $_POST['user_id2'];
    $work_day = $_POST['work_day'];

    try {
        // به‌روزرسانی اطلاعات همکار در جدول Partners
        $stmt = $pdo->prepare("UPDATE Partners SET user_id1 = ?, user_id2 = ?, work_day = ? WHERE partner_id = ?");
        $stmt->execute([$user_id1, $user_id2, $work_day, $partner_id]);

        // هدایت به صفحه همکاران
        header("Location: partners.php");
        exit;
    } catch (PDOException $e) {
        die("خطا در ویرایش همکار: " . $e->getMessage());
    }
} else {
    // اگر درخواست مستقیم باشه، به صفحه همکاران هدایت کن
    header("Location: partners.php");
    exit;
}
?>