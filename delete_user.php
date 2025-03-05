<?php
// [BLOCK-DELETE-USER-001]
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}
require_once 'db.php';

if (isset($_GET['user_id'])) {
    $user_id = $_GET['user_id'];
    $stmt = $pdo->prepare("DELETE FROM Users WHERE user_id = ?");
    $stmt->execute([$user_id]);

    header("Location: users.php");
    exit;
}
?>