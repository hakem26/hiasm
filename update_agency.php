<?php
require 'db.php'; // فایل اتصال به دیتابیس

if (isset($_POST['work_date']) && isset($_POST['agency_owner_id'])) {
    $work_date = $_POST['work_date'];
    $agency_owner_id = $_POST['agency_owner_id'];

    $update_query = $conn->prepare("
        UPDATE Work_Details SET agency_owner_id = ? WHERE work_date = ?
    ");
    
    if ($update_query->execute([$agency_owner_id, $work_date])) {
        echo "تغییرات با موفقیت ذخیره شد!";
    } else {
        echo "خطا در ذخیره تغییرات!";
    }
}
?>
