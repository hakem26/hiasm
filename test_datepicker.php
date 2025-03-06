<?php
require 'libs/jdf.php'; // تبدیل تاریخ

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
        list($jy, $jm, $jd) = explode('/', $jalaliDate);
        $miladiDate = jalali_to_gregorian($jy, $jm, $jd, '-'); 

        // ذخیره در دیتابیس
        $stmt = $pdo->prepare("INSERT INTO dates (date_column) VALUES (:date_column)");
        $stmt->execute(['date_column' => $miladiDate]);

        echo "<p style='color:green;'>✅ تاریخ ذخیره شد!</p>";
    }
} catch (PDOException $e) {
    echo "خطا در اتصال: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="fa">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>انتخاب تاریخ شمسی</title>
    
    <!-- اضافه کردن jQuery قبل از Persian Datepicker -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

    <!-- Persian Datepicker -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/persian-datepicker@1.2.0/dist/css/persian-datepicker.min.css">
    <script src="https://cdn.jsdelivr.net/npm/persian-datepicker@1.2.0/dist/js/persian-datepicker.min.js"></script>

    <style>
        body { font-family: Arial, sans-serif; text-align: center; margin-top: 50px; }
        input { padding: 8px; font-size: 16px; width: 200px; text-align: center; }
        button { padding: 8px 15px; font-size: 16px; }
    </style>
</head>
<body>
    <h2>ورود تاریخ شمسی</h2>
    <form method="post">
        <input type="text" id="jalali_date" name="jalali_date" required>
        <button type="submit">ذخیره</button>
    </form>

    <script>
        $(document).ready(function() {
            $("#jalali_date").persianDatepicker({
                format: 'YYYY/MM/DD',
                autoClose: true,
                initialValue: false
            });
        });
    </script>

    <br>
    <a href="display.php">📅 مشاهده تاریخ‌های ذخیره‌شده</a>
</body>
</html>
