<?php
$host = "localhost";
$dbname = "sales_system"; // نام دیتابیس شما
$username = "root";
$password = "";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if ($_SERVER["REQUEST_METHOD"] == "POST") {
        $miladi_date = $_POST["miladi_date"];

        if (!empty($miladi_date)) {
            $stmt = $pdo->prepare("INSERT INTO dates (date_column) VALUES (:date_column)");
            $stmt->bindParam(":date_column", $miladi_date);
            $stmt->execute();

            echo "تاریخ با موفقیت ذخیره شد: " . $miladi_date;
        } else {
            echo "تاریخ معتبر نیست.";
        }
    }
} catch (PDOException $e) {
    echo "خطا در اتصال به دیتابیس: " . $e->getMessage();
}
?>
