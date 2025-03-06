<!DOCTYPE html>
<html lang="fa">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ورود تاریخ شمسی</title>

    <!-- لود jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

    <!-- استایل و اسکریپت pwt.datepicker -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/pwt.datepicker/dist/css/persian-datepicker.min.css">
    <script src="https://cdn.jsdelivr.net/npm/pwt.datepicker/dist/js/persian-datepicker.min.js"></script>

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
        <input type="hidden" id="gregorian_date" name="gregorian_date">
        <button type="submit">ذخیره</button>
    </form>

    <script>
        $(document).ready(function() {
            let datepicker = $("#jalali_date").persianDatepicker({
                format: 'YYYY/MM/DD',
                autoClose: true,
                initialValue: false,
                onSelect: function(unix) {
                    let selectedDate = new persianDate(unix).toGregorian().format('YYYY-MM-DD');
                    $("#gregorian_date").val(selectedDate);
                }
            });
        });
    </script>

    <?php
    if ($_SERVER["REQUEST_METHOD"] == "POST") {
        $host = 'localhost';
        $dbname = 'ukvojota_hiasm';
        $username = 'ukvojota_hiasmadmin';
        $password = 'H72j51300!';
        
        try {
            $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $jalali_date = $_POST['jalali_date'];
            $gregorian_date = $_POST['gregorian_date'];

            $stmt = $pdo->prepare("INSERT INTO dates_table (jalali_date, gregorian_date) VALUES (:jalali, :gregorian)");
            $stmt->execute(['jalali' => $jalali_date, 'gregorian' => $gregorian_date]);

            echo "<p>تاریخ ذخیره شد: $jalali_date ($gregorian_date)</p>";
        } catch (PDOException $e) {
            echo "خطا: " . $e->getMessage();
        }
    }
    ?>
</body>
</html>
