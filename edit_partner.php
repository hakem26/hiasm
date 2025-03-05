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
    $user_id1 = $_POST['user_id1'] ?? null;
    $user_id2 = $_POST['user_id2'] ?? null;

    // بررسی وجود داده‌ها
    if ($user_id1 && $user_id2) {
        $stmt = $pdo->prepare("UPDATE Partners SET user_id1 = ?, user_id2 = ? WHERE partner_id = ?");
        $stmt->execute([$user_id1, $user_id2, $partner_id]);

        header("Location: partners.php");
        exit;
    } else {
        die("خطا: اطلاعات همکار کامل نیست!");
    }
}
?>