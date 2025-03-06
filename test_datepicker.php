<?php
require 'libs/jdf.php'; // فایل تبدیل تاریخ شمسی به میلادی

$host = 'localhost';
$dbname = 'ukvojota_hiasm';
$username = 'ukvojota_hiasmadmin';
$password = 'H72j51300!';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if ($_SERVER["REQUEST_METHOD"] == "POST") {
        $jalaliDate = $_POST["jalali_date"];

        // تبدیل تاریخ شمسی به میلادی
        list($jy, $jm, $jd) = explode('/', $jalaliDate); // فرمت خروجی datepicker به‌صورت 1402/12/15 است
        $miladiDate = jalali_to_gregorian($jy, $jm, $jd, '-'); // تبدیل به YYYY-MM-DD

        // ذخیره در دیتابیس
        $stmt = $pdo->prepare("INSERT INTO dates (date_column) VALUES (:date_column)");
        $stmt->execute(['date_column' => $miladiDate]);

        echo "<p style='color:green;'>✅ تاریخ با موفقیت ذخیره شد!</p>";
    }
} catch (PDOException $e) {
    echo "خطا در اتصال به دیتابیس: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="fa">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ثبت تاریخ شمسی</title>
    
    <!-- استایل و اسکریپت‌های Persian Datepicker -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/persian-datepicker/dist/css/persian-datepicker.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/persian-datepicker/dist/js/persian-datepicker.min.js"></script>

</head>
<body>
    <h2>ورود تاریخ شمسی</h2>
    <form method="post">
        <input type="text" id="jalali_date" name="jalali_date" required readonly>
        <button type="submit">ذخیره</button>
    </form>

    <script>
        $(document).ready(function() {
            $("#jalali_date").persianDatepicker({
                format: 'YYYY/MM/DD', // فرمت تاریخ شمسی
                autoClose: true,
                initialValue: false, // مقدار پیش‌فرض خالی باشد
            });
        });
    </script>

    <br>
    <a href="display.php">📅 مشاهده تاریخ‌های ذخیره‌شده</a>
</body>
</html>
