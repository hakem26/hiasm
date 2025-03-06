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
    $partner1_id = $_POST['partner1_id'];
    $partner2_id = $_POST['partner2_id'];
    // agency_partner_name فقط برای نمایش، نیازی به به‌روزرسانی نیست

    try {
        $stmt = $pdo->prepare("UPDATE Work_Details SET partner1_id = ?, partner2_id = ? WHERE work_detail_id = ?");
        $stmt->execute([$partner1_id, $partner2_id, $detail_id]);

        $month_id = $pdo->query("SELECT work_month_id FROM Work_Details WHERE work_detail_id = ?", [$detail_id])->fetchColumn();
        header("Location: work_details.php?month_id=" . $month_id);
        exit;
    } catch (PDOException $e) {
        die("خطا در ویرایش اطلاعات کار: " . $e->getMessage());
    }
}
?>