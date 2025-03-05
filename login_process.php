<?php
// [BLOCK-LOGIN-PROCESS-001]
session_start();
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = $_POST['username'];
    $password = $_POST['password'];
    $remember = isset($_POST['remember']) ? true : false;

    $stmt = $pdo->prepare("SELECT * FROM Users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['role'] = $user['role'];

        // اگر تیک "ذخیره ورود" فعال باشد، کوکی تنظیم می‌شود
        if ($remember) {
            setcookie('username', $username, time() + (30 * 24 * 60 * 60), "/"); // 30 روز اعتبار
        } else {
            setcookie('username', '', time() - 3600, "/"); // حذف کوکی اگر تیک غیرفعال باشد
        }

        header("Location: dashboard.php");
        exit;
    } else {
        $_SESSION['error'] = "نام کاربری یا رمز عبور اشتباه است.";
        header("Location: index.php");
        exit;
    }
}
?>