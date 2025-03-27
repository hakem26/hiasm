<?php
// [BLOCK-DB-001]
$host = 'localhost';
$dbname = 'yshcvdau_hiasm';
$username = 'yshcvdau_hiasmadmin';
$password = 'H72j51300!';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "اتصال به دیتابیس با موفقیت برقرار شد!"; // خط تست
} catch (PDOException $e) {
    die("اتصال به دیتابیس失败: " . $e->getMessage());
}
?>