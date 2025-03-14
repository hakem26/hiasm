<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

require_once 'db.php';
require_once 'jdf.php';

// متغیر ثابت برای هزینه آژانس
$agency_cost = 200000;

// دریافت پارامترها
$work_month_id = $_GET['work_month_id'] ?? '';
$partner_id = $_GET['partner_id'] ?? '';

if (!$work_month_id || !$partner_id) {
    die('پارامترهای نادرست.');
}

// دریافت اطلاعات ماه کاری
$stmt = $pdo->prepare("SELECT start_date, end_date FROM Work_Months WHERE work_month_id = ?");
$stmt->execute([$work_month_id]);
$month = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$month) {
    die('ماه کاری یافت نشد.');
}

$start_date = $month['start_date'];
$end_date = $month['end_date'];

// دریافت روزهای کاری برای این ماه و همکار
$stmt = $pdo->prepare("
    SELECT DISTINCT wd.work_date, p.user_id1, p.user_id2, u1.full_name AS agency_name1, u2.full_name AS agency_name2,
           COALESCE(SUM(o.total_amount), 0) AS total_sales,
           COALESCE(SUM(o.discount), 0) AS total_discount
    FROM Work_Details wd
    JOIN Partners p ON wd.partner_id = p.partner_id
    LEFT JOIN Users u1 ON p.user_id1 = u1.user_id
    LEFT JOIN Users u2 ON p.user_id2 = u2.user_id
    LEFT JOIN Orders o ON o.work_details_id = wd.id
    WHERE wd.work_month_id = ? AND wd.partner_id = ?
    GROUP BY wd.work_date, p.user_id1, p.user_id2, u1.full_name, u2.full_name
    ORDER BY wd.work_date
");
$stmt->execute([$work_month_id, $partner_id]);
$work_days = $stmt->fetchAll(PDO::FETCH_ASSOC);

// محاسبه بدهکاران و مبالغ از Order_Payments
$debtors = [];
$stmt = $pdo->prepare("
    SELECT o.customer_name, o.total_amount, COALESCE(SUM(op.amount), 0) AS paid_amount
    FROM Orders o
    LEFT JOIN Order_Payments op ON o.order_id = op.order_id
    JOIN Work_Details wd ON o.work_details_id = wd.id
    WHERE wd.work_month_id = ? AND wd.partner_id = ?
    GROUP BY o.order_id, o.customer_name, o.total_amount
    HAVING paid_amount < total_amount
");
$stmt->execute([$work_month_id, $partner_id]);
$debts = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($debts as $debt) {
    $remaining = $debt['total_amount'] - $debt['paid_amount'];
    if ($remaining > 0) {
        $debtors[] = [
            'name' => $debt['customer_name'],
            'amount' => $remaining
        ];
    }
}

// تبدیل تاریخ به شمسی
function gregorian_to_jalali_format($gregorian_date) {
    list($gy, $gm, $gd) = explode('-', $gregorian_date);
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
    return sprintf("%04d/%02d/%02d", $jy, $jm, $jd);
}

?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>چاپ گزارش خلاصه</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        @page {
            size: A4 portrait;
            margin: 3mm;
        }
        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
        }
        .container {
            width: 100%;
            height: 100%;
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
        }
        .day-box {
            width: 48%;
            height: 45%;
            border: 1px solid #ccc;
            margin-bottom: 2%;
            padding: 5px;
            box-sizing: border-box;
            overflow: hidden;
        }
        .debtors-box {
            width: 48%;
            height: 45%;
            border: 1px solid #ccc;
            padding: 5px;
            box-sizing: border-box;
        }
        .debtors-left, .debtors-right {
            width: 50%;
            float: right;
            padding: 5px;
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
            $agency_name = $day['agency_name1'] ?? $day['agency_name2'] ?? 'نامشخص';

            echo '<div class="day-box">';
            echo '<p>تاریخ: ' . $jalali_date . '</p>';
            echo '<p>مجموع فروش: ' . number_format($day['total_sales'], 0) . ' تومان</p>';
            echo '<p>مجموع پورسانت و تخفیف: ' . number_format($day['total_discount'], 0) . ' تومان</p>';
            echo '<p>هزینه آژانس: ' . number_format($agency_cost, 0) . ' تومان (' . $agency_name . ')</p>';
            echo '</div>';

            if ($day_count % 4 == 0) {
                echo '<div class="clear"></div>';
                echo '<div style="page-break-before: always;"></div>';
            }
        }

        // پر کردن باکس‌های بدهکاران اگر روزها کمتر از 4 تا باشه
        if ($day_count < 4) {
            while ($day_count < 4) {
                echo '<div class="day-box"></div>';
                $day_count++;
                if ($day_count % 4 == 0) {
                    echo '<div class="clear"></div>';
                    echo '<div style="page-break-before: always;"></div>';
                }
            }
        }

        // باکس‌های بدهکاران
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

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>