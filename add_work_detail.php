<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}

require_once 'db.php';
require_once 'jdf.php';

// تابع تبدیل تاریخ شمسی به میلادی
function jalali_to_gregorian_format($jalali_date)
{
    list($jy, $jm, $jd) = explode('/', $jalali_date);
    list($gy, $gm, $gd) = jalali_to_gregorian($jy, $jm, $jd);
    return "$gy-$gm-$gd";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $work_month_id = (int) $_POST['work_month_id'];
    $work_date = $_POST['work_date'];
    $user_id1 = (int) $_POST['user_id1'];
    $user_id2 = isset($_POST['user_id2']) && !empty($_POST['user_id2']) ? (int) $_POST['user_id2'] : null;

    // تبدیل تاریخ شمسی به میلادی
    $work_date_gregorian = jalali_to_gregorian_format($work_date);

    // محاسبه روز هفته با jdate (تقویم ایرانی)
    list($jy, $jm, $jd) = explode('/', $work_date);
    $adjusted_day_number = (int) jdate('w', jalali_to_gregorian($jy, $jm, $jd, true)); // 0 (شنبه) تا 6 (جمعه)
    $adjusted_day_number = $adjusted_day_number + 1; // تبدیل به 1 (شنبه) تا 7 (جمعه)

    // بررسی وجود جفت همکار توی جدول Partners
    $stmt = $pdo->prepare("
        SELECT partner_id FROM Partners 
        WHERE (user_id1 = ? AND user_id2 = ?) OR (user_id1 = ? AND user_id2 = ?)
    ");
    $stmt->execute([$user_id1, $user_id2, $user_id2, $user_id1]);
    $partner = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($partner) {
        $partner_id = $partner['partner_id'];
    } else {
        // ایجاد جفت همکار جدید
        $stmt = $pdo->prepare("
            INSERT INTO Partners (user_id1, user_id2, work_day) 
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$user_id1, $user_id2, $adjusted_day_number]);
        $partner_id = $pdo->lastInsertId();
    }

    // بررسی وجود روز کاری
    $stmt = $pdo->prepare("
        SELECT id FROM Work_Details 
        WHERE work_date = ? AND work_month_id = ? AND partner_id = ?
    ");
    $stmt->execute([$work_date_gregorian, $work_month_id, $partner_id]);
    $existing_detail = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$existing_detail) {
        $stmt = $pdo->prepare("
            INSERT INTO Work_Details (work_month_id, work_date, work_day, partner_id, agency_owner_id, status) 
            VALUES (?, ?, ?, ?, ?, 0)
        ");
        $stmt->execute([$work_month_id, $work_date_gregorian, $adjusted_day_number, $partner_id, null]);
    }

    // ریدایرکت به صفحه اطلاعات کاری
    header("Location: work_details.php?year=" . date('Y') . "&work_month_id=" . $work_month_id);
    exit;
} else {
    header("Location: work_details.php");
    exit;
}
?>