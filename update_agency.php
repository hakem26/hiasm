<?php
require_once 'db.php'; // فایل اتصال به دیتابیس (فرض می‌کنم $pdo اینجاست)

if (isset($_POST['work_date']) && isset($_POST['partner_id']) && isset($_POST['agency_owner_id'])) {
    $work_date = $_POST['work_date'];
    $partner_id = (int)$_POST['partner_id'];
    $agency_owner_id = (int)$_POST['agency_owner_id'];

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