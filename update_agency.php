<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    echo "دسترسی غیرمجاز";
    exit;
}

require_once 'db.php'; // فایل اتصال به دیتابیس

if (isset($_POST['work_date']) && isset($_POST['partner_id']) && isset($_POST['agency_owner_id'])) {
    $work_date = $_POST['work_date'];
    $partner_id = (int)$_POST['partner_id'];
    $agency_owner_id = (int)$_POST['agency_owner_id'];

    // چک کردن وضعیت روز (تعطیل یا غیر تعطیل)
    $status_query = $pdo->prepare("
        SELECT status FROM Work_Details 
        WHERE work_date = ? AND partner_id = ?
    ");
    $status_query->execute([$work_date, $partner_id]);
    $status = $status_query->fetchColumn();

    if ($status === false) {
        echo "روز کاری یافت نشد!";
        exit;
    }

    if ($status == 1) {
        echo "این روز تعطیل است و امکان ثبت آژانس وجود ندارد.";
        exit;
    }

    // به‌روزرسانی آژانس
    $update_query = $pdo->prepare("
        UPDATE Work_Details 
        SET agency_owner_id = ? 
        WHERE work_date = ? AND partner_id = ?
    ");
    
    if ($update_query->execute([$agency_owner_id, $work_date, $partner_id])) {
        echo "تغییرات با موفقیت ذخیره شد!";
    } else {
        echo "خطا در ذخیره تغییرات: " . print_r($pdo->errorInfo(), true);
    }
} else {
    echo "داده‌های ورودی ناقص است!";
}
?>