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
    $password = $_POST['password'] ? password_hash($_POST['password'], PASSWORD_DEFAULT) : null;

    if ($password) {
        $stmt = $pdo->prepare("UPDATE Users SET username = ?, full_name = ?, role = ?, password = ? WHERE user_id = ?");
        $stmt->execute([$username, $full_name, $role, $password, $user_id]);
    } else {
        $stmt = $pdo->prepare("UPDATE Users SET username = ?, full_name = ?, role = ? WHERE user_id = ?");
        $stmt->execute([$username, $full_name, $role, $user_id]);
    }

    header("Location: users.php");
    exit;
}
?>