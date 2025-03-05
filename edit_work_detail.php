<?php
// [BLOCK-EDIT-WORK-DETAIL-001]
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $detail_id = $_POST['detail_id'];
    $work_date = $_POST['work_date']; // تاریخ شمسی
    $partner1_id = $_POST['partner1_id'] ?: null;
    $partner2_id = $_POST['partner2_id'] ?: null;
    $agency_partner_id = $_POST['agency_partner_id'] ?: null;

    // تبدیل تاریخ شمسی به میلادی
    require_once 'jdf.php';
    $work_date_gregorian = jdate('Y-m-d', '', '', '', $work_date, 'gregorian');

    $stmt = $pdo->prepare("UPDATE Work_Details SET work_date = ?, partner1_id = ?, partner2_id = ?, agency_partner_id = ? WHERE work_detail_id = ?");
    $stmt->execute([$work_date_gregorian, $partner1_id, $partner2_id, $agency_partner_id, $detail_id]);

    header("Location: work_details.php?month_id=" . $_GET['month_id'] ?? '');
    exit;
}
?>