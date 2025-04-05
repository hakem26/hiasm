<?php
session_start();
require_once 'db.php';

// پاک کردن توکن از دیتابیس
if (isset($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("UPDATE Users SET login_token = NULL, token_expiry = NULL WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
}

// پاک کردن سشن و کوکی‌ها
session_destroy();
setcookie('login_token', '', time() - 3600, '/');
setcookie('username', '', time() - 3600, '/');

// ریدایرکت به صفحه لاگین
header("Location: index.php");
exit;
?>