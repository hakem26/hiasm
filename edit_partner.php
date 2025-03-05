<?php
// [BLOCK-EDIT-PARTNER-001]
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $partner_id = $_POST['partner_id'];
    $user_id = $_POST['user_id'];

    $stmt = $pdo->prepare("UPDATE Partners SET user_id = ? WHERE partner_id = ?");
    $stmt->execute([$user_id, $partner_id]);

    header("Location: partners.php");
    exit;
}
?>