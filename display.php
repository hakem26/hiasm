<?php
require 'libs/jdf.php'; // کتابخانه تبدیل تاریخ

$host = 'localhost';
$dbname = 'ukvojota_hiasm';
$username = 'ukvojota_hiasmadmin';
$password = 'H72j51300!';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->query("SELECT * FROM dates ORDER BY id DESC");
    $dates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "<h2>تاریخ‌های ذخیره شده</h2>";
    echo "<table border='1'>";
    echo "<tr><th>شناسه</th><th>تاریخ میلادی</th><th>تاریخ شمسی</th></tr>";

    foreach ($dates as $row) {
        $miladi_date = $row["date_column"];
        list($gy, $gm, $gd) = explode('-', $miladi_date);
        $jalali_date = gregorian_to_jalali($gy, $gm, $gd, '-'); // تبدیل به شمسی
        
        echo "<tr>
                <td>{$row['id']}</td>
                <td>{$miladi_date}</td>
                <td>{$jalali_date}</td>
              </tr>";
    }

    echo "</table>";

} catch (PDOException $e) {
    echo "خطا در دریافت اطلاعات: " . $e->getMessage();
}
?>
<br>
<a href="index.php">برگشت به صفحه ورود تاریخ</a>
