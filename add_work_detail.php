<?php
session_start();
require_once 'db.php';
require_once 'jdf.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}

// تابع تبدیل تاریخ شمسی به میلادی
function jalali_to_gregorian_format($jalali_date)
{
    list($jy, $jm, $jd) = explode('/', $jalali_date);
    list($gy, $gm, $gd) = jalali_to_gregorian($jy, $jm, $jd);
    return "$gy-$gm-$gd";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $work_month_id = (int) $_POST['work_month_id'];
    $work_date = jalali_to_gregorian_format($_POST['work_date']);
    $user_id1 = (int) $_POST['user_id1'];
    $user_id2 = isset($_POST['user_id2']) && !empty($_POST['user_id2']) ? (int) $_POST['user_id2'] : null;

    // دیباگ: بررسی مقادیر ارسالی
    error_log("Debug: user_id1 = $user_id1, user_id2 = " . ($user_id2 ?? 'NULL') . "\n", 3, "debug.log");

    // پیدا کردن partner_id یا ساختن جفت جدید
    $partner_query = $pdo->prepare("
        SELECT partner_id 
        FROM Partners 
        WHERE user_id1 = ? AND (user_id2 = ? OR user_id2 IS NULL OR ? IS NULL)
    ");
    $partner_query->execute([$user_id1, $user_id2, $user_id2]);
    $partner = $partner_query->fetch(PDO::FETCH_ASSOC);
    $partner_id = $partner ? $partner['partner_id'] : null;

    if (!$partner_id) {
        // جفت جدید بساز
        $insert_partner_query = $pdo->prepare("
            INSERT INTO Partners (user_id1, user_id2) 
            VALUES (?, ?)
        ");
        $insert_partner_query->execute([$user_id1, $user_id2]);
        $partner_id = $pdo->lastInsertId();

        error_log("Debug: Created new partner_id = $partner_id\n", 3, "debug.log");
    }

    // دیباگ: بررسی partner_id
    error_log("Debug: partner_id = " . ($partner_id ?? 'NULL') . "\n", 3, "debug.log");

    // بررسی اینکه آیا این تاریخ قبلاً ثبت شده یا نه
    $check_query = $pdo->prepare("
        SELECT id 
        FROM Work_Details 
        WHERE work_month_id = ? AND work_date = ?
    ");
    $check_query->execute([$work_month_id, $work_date]);
    if ($check_query->fetch()) {
        echo "این تاریخ قبلاً ثبت شده است.";
        exit;
    }

    // ثبت روز کاری جدید
    $insert_query = $pdo->prepare("
        INSERT INTO Work_Details (work_month_id, work_date, partner_id, status) 
        VALUES (?, ?, ?, 0)
    ");
    $insert_query->execute([$work_month_id, $work_date, $partner_id]);

    header("Location: work_details.php?work_month_id=$work_month_id");
    exit;
} else {
    echo "درخواست نامعتبر است.";
}
?>