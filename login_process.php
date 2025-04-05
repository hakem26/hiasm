<?php
session_start();
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']) ? true : false;

    // دیباگ: لاگ کردن اطلاعات ورودی
    error_log("Login attempt: username=$username, password=$password");

    $stmt = $pdo->prepare("SELECT * FROM Users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        error_log("User found: " . json_encode($user));
    } else {
        error_log("No user found for username=$username");
    }

    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['role'] = $user['role'];

        error_log("Login successful: user_id={$_SESSION['user_id']}, role={$_SESSION['role']}");

        $redirect_url = ($_SESSION['role'] === 'admin') ? 'dashboard_admin.php' : 'dashboard_seller.php';

        if ($remember) {
            // ساخت توکن منحصربه‌فرد
            $token = bin2hex(random_bytes(16));
            $expiry = date('Y-m-d H:i:s', strtotime('+30 days'));

            // ذخیره توکن توی دیتابیس
            $stmt = $pdo->prepare("UPDATE Users SET login_token = ?, token_expiry = ? WHERE user_id = ?");
            $stmt->execute([$token, $expiry, $user['user_id']]);

            // ذخیره توکن و نام کاربری توی کوکی
            setcookie('login_token', $token, time() + (30 * 24 * 60 * 60), "/"); // 30 روز
            setcookie('username', $username, time() + (30 * 24 * 60 * 60), "/");
        } else {
            // پاک کردن توکن و کوکی‌ها اگه "ذخیره ورود" تیک نخورده
            $stmt = $pdo->prepare("UPDATE Users SET login_token = NULL, token_expiry = NULL WHERE user_id = ?");
            $stmt->execute([$user['user_id']]);
            setcookie('login_token', '', time() - 3600, "/");
            setcookie('username', '', time() - 3600, "/");
        }

        header("Location: $redirect_url");
        exit;
    } else {
        $_SESSION['error'] = "نام کاربری یا رمز عبور اشتباه است.";
        error_log("Login failed: Invalid credentials");
        header("Location: index.php");
        exit;
    }
} else {
    $_SESSION['error'] = "درخواست نامعتبر است.";
    header("Location: index.php");
    exit;
}
?>