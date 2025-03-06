<?php
require 'libs/Jalalian.php'; // کتابخانه تبدیل تاریخ

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
        list($jy, $jm, $jd) = explode('-', $jalaliDate);
        $miladiDate = Jalalian::toGregorian($jy, $jm, $jd);
        $miladiDateStr = sprintf("%04d-%02d-%02d", $miladiDate[0], $miladiDate[1], $miladiDate[2]);

        // ذخیره در دیتابیس
        $stmt = $pdo->prepare("INSERT INTO dates (date_column) VALUES (:date_column)");
        $stmt->execute(['date_column' => $miladiDateStr]);

        echo "<p style='color:green;'>تاریخ با موفقیت ذخیره شد!</p>";
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
    <script src="https://cdn.jsdelivr.net/npm/persian-datepicker/dist/js/persian-datepicker.min.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/persian-datepicker/dist/css/persian-datepicker.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
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
                format: 'YYYY-MM-DD'
            });
        });
    </script>
    <br>
    <a href="display.php">مشاهده تاریخ‌های ذخیره‌شده</a>
</body>
</html>
