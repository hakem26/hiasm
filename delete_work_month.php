<?php
// [BLOCK-DELETE-WORK-MONTH-001]
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}
require_once 'db.php';

if (isset($_GET['month_id'])) {
    $month_id = $_GET['month_id'];
    try {
        $pdo->beginTransaction();

        // حذف اطلاعات مرتبط در Work_Details
        $stmt = $pdo->prepare("DELETE FROM Work_Details WHERE work_month_id = ?");
        $stmt->execute([$month_id]);

        // حذف ماه کاری
        $stmt = $pdo->prepare("DELETE FROM Work_Months WHERE work_month_id = ?");
        $stmt->execute([$month_id]);

        $pdo->commit();

        header("Location: work_months.php?success=1");
        exit;
    } catch (PDOException $e) {
        $pdo->rollBack();
        die("خطا در حذف ماه کاری: " . $e->getMessage());
    }
}
?>