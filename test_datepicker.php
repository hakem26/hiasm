<?php
include "jdf.php"; // اضافه کردن کتابخانه jdf

$conn = new PDO("mysql:host=localhost;dbname=ukvojota_hiasm;charset=utf8", "ukvojota_hiasmadmin", "H72j51300!");

// ذخیره تاریخ در دیتابیس
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $shamsi_date = $_POST["date_input"];
    
    // تبدیل شمسی به میلادی
    list($jy, $jm, $jd) = explode('/', $shamsi_date);
    list($gy, $gm, $gd) = jalali_to_gregorian($jy, $jm, $jd);
    $miladi_date = "$gy-$gm-$gd"; // فرمت خروجی: YYYY-MM-DD

    $stmt = $conn->prepare("INSERT INTO dates_table (date_value) VALUES (:date_value)");
    $stmt->execute(["date_value" => $miladi_date]);

    echo "تاریخ ذخیره شد: " . $miladi_date;
}

// دریافت تاریخ برای نمایش
$stmt = $conn->query("SELECT * FROM dates_table ORDER BY id DESC LIMIT 1");
$row = $stmt->fetch();
$saved_date = $row ? gregorian_to_jalali_format($row["date_value"]) : "تاریخی ثبت نشده";

// تابع تبدیل میلادی به شمسی
function gregorian_to_jalali_format($gregorian_date) {
    list($gy, $gm, $gd) = explode('-', $gregorian_date);
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
    return "$jy/$jm/$jd"; // خروجی: YYYY/MM/DD
}
?>

<!DOCTYPE html>
<html lang="fa">
<head>
    <meta charset="UTF-8">
    <title>Persian Datepicker</title>
    <link rel="stylesheet" href="assets/css/persian-datepicker.min.css">
    <script src="assets/js/jquery-3.6.0.min.js"></script>
    <script src="assets/js/persian-date.min.js"></script>
    <script src="assets/js/persian-datepicker.min.js"></script>
</head>
<body>

<form method="post">
    <input type="text" id="date_input" name="date_input" required>
    <button type="submit">ذخیره</button>
</form>

<p>تاریخ ذخیره شده: <?php echo $saved_date; ?></p>

<script>
$(document).ready(function() {
    $("#date_input").persianDatepicker({
        format: "YYYY/MM/DD",
        autoClose: true
    });
});
</script>

</body>
</html>
