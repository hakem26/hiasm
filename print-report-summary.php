<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>چاپ گزارش خلاصه</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@latest/Vazirmatn-font-face.css" rel="stylesheet">

    <style>
        @page {
            size: A4 portrait;
            margin: 15mm;
        }
        body {
            font-family: 'Vazirmatn', Arial, sans-serif;
            font-size: 14px;
            direction: rtl;
            text-align: right;
            margin: 0;
            padding: 0;
            background: white;
        }
        .container {
            width: 100%;
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            page-break-after: always;
        }
        .day-box {
            width: 48%;
            min-height: 180px;
            border: 1px solid black;
            padding: 10px;
            box-sizing: border-box;
            margin-bottom: 10px;
            font-size: 14px;
        }
        .debtors-box {
            width: 100%;
            border: 1px solid black;
            padding: 10px;
            box-sizing: border-box;
            margin-top: 20px;
        }
        .debtors-left, .debtors-right {
            width: 50%;
            display: inline-block;
            vertical-align: top;
            padding: 5px;
        }
        .debtors-left {
            text-align: left;
        }
        .clear {
            clear: both;
        }
        @media print {
            .no-print {
                display: none;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <?php
        $day_count = 0;
        foreach ($work_days as $index => $day) {
            $day_count++;
            $jalali_date = gregorian_to_jalali_format($day['work_date']);
            $agency_name = $day['agency_name'] ?? 'نامشخص';

            echo '<div class="day-box">';
            echo '<p><strong>تاریخ:</strong> ' . $jalali_date . '</p>';
            echo '<p><strong>مجموع فروش:</strong> ' . number_format($day['total_sales'], 0) . ' تومان</p>';
            echo '<p><strong>مجموع پورسانت و تخفیف:</strong> ' . number_format($day['total_discount'], 0) . ' تومان</p>';
            echo '<p><strong>هزینه آژانس:</strong> ' . number_format($agency_cost, 0) . ' تومان (' . $agency_name . ')</p>';
            echo '</div>';

            if ($day_count % 4 == 0) {
                echo '<div class="clear"></div>';
                echo '<div style="page-break-before: always;"></div>';
            }
        }

        // پر کردن باکس‌های خالی تا ۴ روز تکمیل شود
        while ($day_count % 4 != 0) {
            echo '<div class="day-box"></div>';
            $day_count++;
        }

        // باکس بدهکاران
        echo '<div class="debtors-box">';
        echo '<div class="debtors-right"><strong>نام بدهکاران</strong><br>';
        echo implode('<br>', array_column($debtors, 'name'));
        echo '</div>';
        echo '<div class="debtors-left"><strong>مبلغ</strong><br>';
        echo implode('<br>', array_map(function($d) { return number_format($d['amount'], 0); }, $debtors));
        echo '</div>';
        echo '<div class="clear"></div>';
        echo '</div>';
        ?>
    </div>

    <button class="no-print btn btn-secondary mt-3" onclick="window.print()">چاپ</button>

</body>
</html>
