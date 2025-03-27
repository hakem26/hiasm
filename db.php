<?php
// [BLOCK-DB-001]
$host = 'localhost';
// main
$dbname = 'yshcvdau_hiasm';
$username = 'yshcvdau_hiasmadmin';
// test
// $dbname = 'ukvojota_hiasm';
// $username = 'ukvojota_hiasmadmin'; 
$password = 'H72j51300!';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("اتصال به دیتابیس失败: " . $e->getMessage());
}
?>