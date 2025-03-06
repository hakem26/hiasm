<?php
// [BLOCK-ADD-WORK-DETAIL-001]
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $month_id = $_POST['month_id'] ?? null;
    $work_date = $_POST['work_date'] ?? null;
    $partner1_id = $_POST['partner1_id'] ?? null;
    $partner2_id = $_POST['partner2_id'] ?? null;
    $agency_partner_id = $_POST['agency_partner_id'] ?? null;

    if (!$month_id || !$work_date || !$partner1_id || !$partner2_id || !$agency_partner_id) {
        die("خطا: اطلاعات ارسالی ناقص است.");
    }

    try {
        $work_day = jdate('l', strtotime($work_date), '', '', 'gregorian', 'persian');
        $check_stmt = $pdo->prepare("SELECT work_detail_id FROM Work_Details WHERE work_month_id = ? AND work_date = ?");
        $check_stmt->execute([$month_id, $work_date]);
        if (!$check_stmt->fetch(PDO::FETCH_ASSOC)) {
            $insert_stmt = $pdo->prepare("INSERT INTO Work_Details (work_month_id, work_date, partner1_id, partner2_id, agency_partner_id, work_day) VALUES (?, ?, ?, ?, ?, ?)");
            $insert_stmt->execute([$month_id, $work_date, $partner1_id, $partner2_id, $agency_partner_id, $work_day]);
        }
        header("Location: work_details.php?month_id=" . $month_id);
        exit;
    } catch (PDOException $e) {
        die("خطا در ثبت اطلاعات کار: " . $e->getMessage());
    }
} else {
    header("Location: work_details.php");
    exit;
}
?>