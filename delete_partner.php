<?php
// [BLOCK-DELETE-PARTNER-001]
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}
require_once 'db.php';

if (isset($_GET['partner_id'])) {
    $partner_id = $_GET['partner_id'];
    try {
        $stmt = $pdo->prepare("DELETE FROM Partners WHERE partner_id = ?");
        $stmt->execute([$partner_id]);
        echo "success";
    } catch (PDOException $e) {
        echo "error: " . $e->getMessage();
    }
}
?>