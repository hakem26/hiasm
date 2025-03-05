<?php
// [BLOCK-TEST-DATEPICKER-001]
session_start();
// اتصال به دیتابیس تست
$host = 'localhost';
$dbname = 'ukvojota_hiasm';
$username = 'ukvojota_hiasmadmin'; // نام کاربری دیتابیس خود را وارد کنید
$password = 'H72j51300!'; // رمز عبور دیتابیس خود را وارد کنید

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("خطا در اتصال به دیتابیس: " . $e->getMessage());
}

// تابع کمکی برای تبدیل کاراکترهای فارسی به انگلیسی
function convertPersianToEnglishNumbers($string) {
    $persian_numbers = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    $english_numbers = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    return str_replace($persian_numbers, $english_numbers, $string);
}

// تابع سفارشی برای تبدیل تاریخ شمسی به میلادی
function jalaliToGregorian($jalali_date) {
    $date_parts = explode('/', convertPersianToEnglishNumbers($jalali_date));
    if (count($date_parts) !== 3) {
        return '0000-00-00';
    }

    $year = (int)$date_parts[0];  // سال شمسی (مثلاً 1403)
    $month = (int)$date_parts[1]; // ماه شمسی (مثلاً 12)
    $day = (int)$date_parts[2];   // روز شمسی (مثلاً 15)

    if ($year < 1000 || $year > 1499 || $month < 1 || $month > 12 || $day < 1 || $day > 31) {
        return '0000-00-00';
    }

    // تنظیمات پایه برای تبدیل (بر اساس الگوریتم استاندارد)
    $gy = 621; // پایه سال میلادی
    $jalali_epoch = 226894; // روزهای پایه از 1 Farvardin 1 (شمسی) تا 1 January 1 (میلادی)

    // محاسبه روزهای کل از سال 1 شمسی
    $days = 0;
    for ($i = 1; $i < $year; $i++) {
        $days += (($i % 4 == 3) ? 366 : 365); // سال کبیسه شمسی (هر 4 سال یک‌بار)
    }

    // اضافه کردن روزهای ماه‌های سال جاری
    $jalali_month_days = [0, 31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29];
    for ($i = 1; $i < $month; $i++) {
        $days += $jalali_month_days[$i];
    }
    $days += $day;

    // اضافه کردن روزهای پایه و تبدیل به میلادی
    $days += $jalali_epoch;
    $gy += floor($days / 365.2425); // سال میلادی تقریبی
    $days = floor($days % 365.2425);

    // محاسبه ماه و روز میلادی
    $leap = (($gy % 4 == 0 && $gy % 100 != 0) || ($gy % 400 == 0)) ? 29 : 28;
    $gregorian_month_days = [0, 31, $leap, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];

    $gm = 1;
    while ($days > $gregorian_month_days[$gm]) {
        $days -= $gregorian_month_days[$gm];
        $gm++;
    }
    $gd = $days;

    return sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
}
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تست Datepicker شمسی</title>
    <!-- Bootstrap RTL CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" integrity="sha384-dpuaG1suU0eT09tx5plTaGMLBsfDLzUCCUXOY2j/LSvXYuG6Bqs43ALlhIqAJVRb" crossorigin="anonymous">
    <!-- Persian Datepicker CSS -->
    <link rel="stylesheet" href="assets/css/persian-datepicker.min.css" />
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js" integrity="sha256-/xUj+3OJU5yExlq6GSYGSHk7tPXikynS7ogEvDej/m4=" crossorigin="anonymous"></script>
    <!-- Persian Date JS (فقط برای جاوااسکریپت) -->
    <script src="assets/js/persian-date.min.js"></script>
    <!-- Persian Datepicker JS -->
    <script src="assets/js/persian-datepicker.min.js"></script>
    <style>
        body {
            font-family: 'Vazirmatn', sans-serif;
            background-color: #f8f9fa;
            margin: 20px;
        }
        .container {
            max-width: 600px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2 class="text-center mb-4">تست Datepicker شمسی</h2>
        <form method="POST" action="">
            <div class="mb-3">
                <label for="start_date" class="form-label">تاریخ شروع (شمسی)</label>
                <input type="text" class="form-control" id="start_date" name="start_date" required>
            </div>
            <div class="mb-3">
                <label for="end_date" class="form-label">تاریخ پایان (شمسی)</label>
                <input type="text" class="form-control" id="end_date" name="end_date" required>
            </div>
            <button type="submit" class="btn btn-primary">ثبت تاریخ‌ها</button>
        </form>

        <?php
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $start_date = $_POST['start_date'];
            $end_date = $_POST['end_date'];

            // تبدیل تاریخ شمسی به میلادی با تابع سفارشی
            $start_gregorian = jalaliToGregorian($start_date);
            $end_gregorian = jalaliToGregorian($end_date);

            try {
                $stmt = $pdo->prepare("INSERT INTO test_dates (date_value) VALUES (?)");
                $stmt->execute([$start_gregorian]);
                $stmt->execute([$end_gregorian]);

                echo "<div class='alert alert-success mt-3'>تاریخ‌ها با موفقیت ثبت شدند: شروع = " . $start_gregorian . "، پایان = " . $end_gregorian . "</div>";
            } catch (PDOException $e) {
                echo "<div class='alert alert-danger mt-3'>خطا در ثبت تاریخ‌ها: " . $e->getMessage() . "</div>";
            }
        }
        ?>

        <h3 class="mt-4">تاریخ‌های ثبت‌شده در دیتابیس:</h3>
        <?php
        $stmt = $pdo->query("SELECT * FROM test_dates ORDER BY id DESC");
        $dates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($dates)): ?>
        <table class="table table-light table-hover">
            <thead>
                <tr>
                    <th>شناسه</th>
                    <th>تاریخ</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($dates as $date): ?>
                <tr>
                    <td><?php echo $date['id']; ?></td>
                    <td><?php echo $date['date_value']; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div class="alert alert-warning">هیچ تاریخی ثبت نشده است.</div>
        <?php endif; ?>
    </div>

    <script>
        // [BLOCK-TEST-DATEPICKER-002]
        document.addEventListener('DOMContentLoaded', () => {
            $('#start_date, #end_date').persianDatepicker({
                format: 'YYYY/MM/DD',
                autoClose: true,
                calendar: {
                    persian: {
                        locale: 'fa'
                    }
                }
            });
        });
    </script>
</body>
</html>