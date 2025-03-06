<?php
// [BLOCK-ADD-PARTNER-001]
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $user_id1 = $_POST['user_id1'];
    $user_id2 = $_POST['user_id2'];
    $work_day = $_POST['work_day'];

    try {
        // افزودن همکار جدید به جدول Partners
        $stmt = $pdo->prepare("INSERT INTO Partners (user_id1, user_id2, work_day) VALUES (?, ?, ?)");
        $stmt->execute([$user_id1, $user_id2, $work_day]);

        // هدایت به صفحه همکاران
        header("Location: partners.php");
        exit;
    } catch (PDOException $e) {
        die("خطا در افزودن همکار: " . $e->getMessage());
    }
} else {
    // اگر درخواست مستقیم باشه، به صفحه همکاران هدایت کن
    header("Location: partners.php");
    exit;
}
?>