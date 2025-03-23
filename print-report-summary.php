<?php
// بررسی مقداردهی متغیرها برای جلوگیری از خطا
$work_days = $work_days ?? []; // اگر مقداردهی نشده، به آرایه خالی تبدیل شود
$debtors = $debtors ?? []; // جلوگیری از خطای مقدار null

$day_count = 0;
?>
<!DOCTYPE html>
<html lang="fa">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>گزارش خلاصه</title>
    <style>
        body {
            font-family: 'Vazir', sans-serif;
            direction: rtl;
            text-align: right;
        }
        .container {
            width: 21cm;
            height: 29.7cm;
            margin: auto;
            padding: 2cm;
            border: 1px solid black;
        }
        .day-box {
            width: 48%;
            display: inline-block;
            vertical-align: top;
            border: 1px solid #000;
            margin: 5px;
            padding: 10px;
            box-sizing: border-box;
        }
        .clear {
            clear: both;
        }
        .debtors-box {
            margin-top: 20px;
            border: 1px solid black;
            padding: 10px;
        }
        .debtors-right, .debtors-left {
            display: inline-block;
            width: 48%;
            vertical-align: top;
        }
    </style>
</head>
<body>
    <div class="container">
        <?php
        foreach ($work_days as $index => $day) {
            $day_count++;
            $jalali_date = gregorian_to_jalali_format($day['work_date'] ?? 'نامشخص');
            $agency_name = $day['agency_name'] ?? 'نامشخص';

            echo '<div class="day-box">';
            echo '<p><strong>تاریخ:</strong> ' . $jalali_date . '</p>';
            echo '<p><strong>مجموع فروش:</strong> ' . number_format($day['total_sales'] ?? 0, 0) . ' تومان</p>';
            echo '<p><strong>مجموع پورسانت و تخفیف:</strong> ' . number_format($day['total_discount'] ?? 0, 0) . ' تومان</p>';
            echo '<p><strong>هزینه آژانس:</strong> ' . number_format($agency_cost ?? 0, 0) . ' تومان (' . $agency_name . ')</p>';
            echo '</div>';

            if ($day_count % 4 == 0) {
                echo '<div class="clear"></div>';
                echo '<div style="page-break-before: always;"></div>';
            }
        }

        while ($day_count % 4 != 0) {
            echo '<div class="day-box"></div>';
            $day_count++;
        }
        ?>

        <div class="debtors-box">
            <div class="debtors-right"><strong>نام بدهکاران</strong><br>
                <?php echo !empty($debtors) ? implode('<br>', array_column($debtors, 'name')) : 'هیچ بدهکاری ثبت نشده'; ?>
            </div>
            <div class="debtors-left"><strong>مبلغ</strong><br>
                <?php echo !empty($debtors) ? implode('<br>', array_map(fn($d) => number_format($d['amount'] ?? 0, 0), $debtors)) : '-'; ?>
            </div>
            <div class="clear"></div>
        </div>
    </div>
</body>
</html>
