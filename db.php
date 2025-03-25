<?php
// [BLOCK-DB-001]
$host = 'localhost';
$dbname = 'ukvojota_hiasm';
$username = 'ukvojota_hiasmadmin'; // نام کاربری دیتابیس خود را وارد کنید
$password = 'H72j51300!'; // رمز عبور دیتابیس خود را وارد کنید

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("اتصال به دیتابیس失败: " . $e->getMessage());
}
?>