<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $detail_id = $_POST['detail_id'];
    $agency_partner_id = $_POST['agency_partner_id'];

    $stmt = $pdo->prepare("UPDATE Work_Details SET agency_partner_id = ? WHERE work_detail_id = ?");
    $stmt->execute([$agency_partner_id, $detail_id]);

    $month_id = $pdo->query("SELECT work_month_id FROM Work_Details WHERE work_detail_id = $detail_id")->fetchColumn();
    header("Location: work_details.php?month_id=$month_id");
    exit;
}
header("Location: work_details.php");
?>