<?php
// [BLOCK-EDIT-USER-001]
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $user_id = $_POST['user_id'];
    $username = $_POST['username'];
    $full_name = $_POST['full_name'];
    $role = $_POST['role'];
    $phone_number = $_POST['phone_number'] ?? null; // اگه خالی باشه، NULL میشه
    $password = $_POST['password'] ? password_hash($_POST['password'], PASSWORD_DEFAULT) : null;

    try {
        if ($password) {
            $stmt = $pdo->prepare("UPDATE Users SET username = ?, full_name = ?, role = ?, phone_number = ?, password = ? WHERE user_id = ?");
            $stmt->execute([$username, $full_name, $role, $phone_number, $password, $user_id]);
        } else {
            $stmt = $pdo->prepare("UPDATE Users SET username = ?, full_name = ?, role = ?, phone_number = ? WHERE user_id = ?");
            $stmt->execute([$username, $full_name, $role, $phone_number, $user_id]);
        }
        header("Location: users.php");
        exit;
    } catch (PDOException $e) {
        die("خطا در ویرایش کاربر: " . $e->getMessage());
    }
}
?>