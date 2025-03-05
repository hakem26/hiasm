<?php
// [BLOCK-ADD-USER-001]
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = $_POST['username'];
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $full_name = $_POST['full_name'];
    $role = $_POST['role'];

    $stmt = $pdo->prepare("INSERT INTO Users (username, password, role, full_name) VALUES (?, ?, ?, ?)");
    $stmt->execute([$username, $password, $role, $full_name]);

    header("Location: users.php");
    exit;
}
?>