<?php
session_start();
require_once 'db.php'; // برای اتصال به دیتابیس

// تابع برای ریدایرکت به داشبورد بر اساس نقش کاربر
function redirectToDashboard($role) {
    if ($role === 'admin') {
        header("Location: dashboard_admin.php");
    } elseif ($role === 'seller') {
        header("Location: dashboard_seller.php");
    }
    exit;
}

// چک کردن وضعیت لاگین
if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    // اگه سشن فعال باشه، مستقیم به داشبورد بره
    redirectToDashboard($_SESSION['role']);
} elseif (isset($_COOKIE['login_token'])) {
    // اگه کوکی توکن وجود داره، اعتبارش رو چک کنیم
    $token = $_COOKIE['login_token'];

    $stmt = $pdo->prepare("SELECT user_id, full_name, role FROM Users WHERE login_token = ? AND token_expiry > NOW()");
    $stmt->execute([$token]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        // اگه توکن معتبر بود، سشن رو بازسازی کنیم و به داشبورد بریم
        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['role'] = $user['role'];
        redirectToDashboard($user['role']);
    } else {
        // اگه توکن نامعتبر بود، کوکی رو پاک کنیم
        setcookie('login_token', '', time() - 3600, '/');
    }
}
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ورود به سیستم</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@100;200;300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Vazirmatn', sans-serif;
            background-color: #f8f9fa;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-box {
            max-width: 350px;
            width: 100%;
            padding: 20px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
    </style>
</head>
<body style="background: linear-gradient(to bottom right, lightgreen, lightcyan);">
    <div class="login-box">
        <h6 class="text-center mb-4">سیستم مدیریت فروش محصولات پوست و مو</h6>
        <?php
        if (isset($_SESSION['error'])) {
            echo '<div class="alert alert-danger">' . $_SESSION['error'] . '</div>';
            unset($_SESSION['error']);
        }
        ?>
        <form action="login_process.php" method="POST">
            <div class="mb-3">
                <label for="username" class="form-label">نام کاربری</label>
                <input type="text" class="form-control" id="username" name="username" value="<?php echo isset($_COOKIE['username']) ? htmlspecialchars($_COOKIE['username']) : ''; ?>" required>
            </div>
            <div class="mb-3">
                <label for="password" class="form-label">رمز عبور</label>
                <input type="password" class="form-control" id="password" name="password" required>
            </div>
            <div class="mb-3 form-check">
                <input type="checkbox" class="form-check-input" id="remember" name="remember" <?php echo isset($_COOKIE['username']) ? 'checked' : ''; ?>>
                <label class="form-check-label" for="remember">ذخیره ورود</label>
            </div>
            <button type="submit" class="btn btn-primary w-100">
                <i class="fas fa-sign-in-alt"></i> ورود
            </button>
        </form>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>