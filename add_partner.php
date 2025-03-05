<?php
// [BLOCK-ADD-PARTNER-001]
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $user_id = $_POST['user_id'];

    $stmt = $pdo->prepare("INSERT INTO Partners (user_id) VALUES (?)");
    $stmt->execute([$user_id]);

    header("Location: partners.php");
    exit;
}
?>