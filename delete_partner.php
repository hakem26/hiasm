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
    $stmt = $pdo->prepare("DELETE FROM Partners WHERE partner_id = ?");
    $stmt->execute([$partner_id]);

    // حذف اطلاعات مرتبط در Work_Details
    $stmt = $pdo->prepare("UPDATE Work_Details SET partner1_id = NULL WHERE partner1_id = ?");
    $stmt->execute([$partner_id]);
    $stmt = $pdo->prepare("UPDATE Work_Details SET partner2_id = NULL WHERE partner2_id = ?");
    $stmt->execute([$partner_id]);
    $stmt = $pdo->prepare("UPDATE Work_Details SET agency_partner_id = NULL WHERE agency_partner_id = ?");
    $stmt->execute([$partner_id]);

    header("Location: partners.php");
    exit;
}
?>