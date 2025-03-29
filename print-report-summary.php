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

// دریافت اطلاعات همکار
$stmt = $pdo->prepare("
    SELECT u1.full_name AS partner1_name, u2.full_name AS partner2_name
    FROM Partners p
    JOIN Users u1 ON p.user_id1 = u1.user_id
    JOIN Users u2 ON p.user_id2 = u2.user_id
    WHERE p.partner_id = ?
");
$stmt->execute([$partner_id]);
$partner = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$partner) {
    die('همکار یافت نشد.');
}
$partner1_name = $partner['partner1_name'];
$partner2_name = $partner['partner2_name'];

// دریافت روزهای کاری برای این ماه و همکار
$stmt = $pdo->prepare("
    SELECT DISTINCT wd.work_date, wd.agency_owner_id, u.full_name AS agency_name,
           COALESCE(SUM(o.total_amount), 0) AS total_sales,
           COALESCE(SUM(o.discount), 0) AS total_discount
    FROM Work_Details wd
    LEFT JOIN Users u ON wd.agency_owner_id = u.user_id
    LEFT JOIN Orders o ON o.work_details_id = wd.id
    WHERE wd.work_month_id = ? AND wd.partner_id = ?
    GROUP BY wd.work_date, wd.agency_owner_id, u.full_name
    ORDER BY wd.work_date
");
$stmt->execute([$work_month_id, $partner_id]);
$work_days = $stmt->fetchAll(PDO::FETCH_ASSOC);

// محاسبه مجموع فروش کل
$total_sales = 0;
foreach ($work_days as $day) {
    $total_sales += $day['total_sales'];
}

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
    return sprintf("%02d / %02d / %04d", $jd, $jm, $jy);
}

// تابع برای دریافت نام ماه شمسی
function get_jalali_month_name($month) {
    $month_names = [
        1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد',
        4 => 'تیر', 5 => 'مرداد', 6 => 'شهریور',
        7 => 'مهر', 8 => 'آبان', 9 => 'آذر',
        10 => 'دی', 11 => 'بهمن', 12 => 'اسفند'
    ];
    return $month_names[$month] ?? '';
}

// تبدیل تاریخ‌ها به شمسی
$start_date_jalali = gregorian_to_jalali_format($start_date);
$end_date_jalali = gregorian_to_jalali_format($end_date);

// دریافت نام ماه برای هر روز کاری
$work_days_with_month = [];
foreach ($work_days as $day) {
    list($gy, $gm, $gd) = explode('-', $day['work_date']);
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
    $month_name = get_jalali_month_name($jm);
    $work_days_with_month[] = array_merge($day, [
        'jalali_date' => sprintf("%d %s", $jd, $month_name),
        'jalali_full_date' => sprintf("%02d / %02d / %04d", $jd, $jm, $jy)
    ]);
}
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <!-- Vazirmatn Font -->
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@100;200;300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <title>چاپ گزارش خلاصه</title>
    <style>
        body {
            font-family: "Vazirmatn", sans-serif;
            font-size: 10pt;
            margin: 0;
            padding: 0;
            direction: rtl;
        }
        .page {
            width: 210mm;
            height: 297mm;
            margin: 0 auto;
            box-sizing: border-box;
            page-break-after: always;
            border: 1px solid #000;
            overflow: hidden;
        }
        .title {
            text-align: center;
            margin-top: 0;
            margin-bottom: 8pt;
            line-height: 108%;
            font-size: 14pt;
            font-family: 'Vazirmatn', sans-serif;
            font-weight: bold;
        }
        .table-container {
            text-align: center;
            margin-bottom: 16pt;
        }
        .report-table {
            width: 95%;
            margin: 0 auto;
            border-collapse: collapse;
        }
        .report-table td {
            border: 2.25pt solid black;
            padding: 4.28pt;
            vertical-align: top;
        }
        .report-table .day-cell {
            width: 268.75pt;
        }
        .report-table .debtor-name-cell {
            width: 128.95pt;
        }
        .report-table .debtor-amount-cell {
            width: 129pt;
        }
        .report-table p {
            margin: 0;
            line-height: 150%;
            font-size: 11pt;
            font-family: 'Vazirmatn', sans-serif;
            font-weight: bold;
        }
        .debtor-table p {
            text-align: center;
        }
        .spacer {
            height: 0pt;
        }
        .page-break {
            page-break-before: always;
        }
        @media print {
            @page {
                size: A4 portrait;
                margin: 0;
            }

            body {
                margin: 0;
                padding: 0;
            }
        }
    </style>
</head>

<body>
    <div class="page">
        <?php
        // تقسیم روزهای کاری به گروه‌های دوتایی
        $work_days_chunks = array_chunk($work_days_with_month, 2);
        $debtor_chunks = array_chunk($debtors, 10); // حداکثر 10 بدهکار در هر جدول (5 در هر ستون)

        foreach ($work_days_chunks as $index => $chunk) {
            // عنوان صفحه گزارش
            echo `<h3 style="text-align: center;">
            گزارش کاری <?= htmlspecialchars($partner1_name) ?> و <?= htmlspecialchars($partner2_name) ?> 
            از تاریخ <?= $start_date_jalali ?> تا تاریخ <?= $end_date_jalali ?> 
            مبلغ <?= number_format($total_sales, 0) ?> تومان
            </h3>`; 

            echo '<div class="table-container">';
            echo '<table class="report-table">';
            echo '<tbody>';

            // ردیف روزهای کاری
            echo '<tr style="height: 150pt;">';
            echo '<td style="border-bottom-style: solid; border-bottom-width: 2.25pt; width: 0.35pt;"></td>';

            // روز اول
            if (isset($chunk[0])) {
                $day = $chunk[0];
                echo '<td colspan="3" class="day-cell">';
                echo '<p>تاریخ: ' . htmlspecialchars($day['jalali_date']) . '</p>';
                echo '<p>مجموع فروش: ' . number_format($day['total_sales'], 0) . '</p>';
                echo '<p>مجموع پورسانت و تخفیف: ' . number_format($day['total_discount'], 0) . '</p>';
                echo '<p>هزینه آژانس: ' . number_format($agency_cost, 0) . ' (' . htmlspecialchars($day['agency_name'] ?? 'نامشخص') . ')</p>';
                echo '</td>';
            } else {
                echo '<td colspan="3" class="day-cell"></td>';
            }

            // روز دوم
            if (isset($chunk[1])) {
                $day = $chunk[1];
                echo '<td colspan="3" class="day-cell">';
                echo '<p>تاریخ: ' . htmlspecialchars($day['jalali_date']) . '</p>';
                echo '<p>مجموع کل فروش: ' . number_format($day['total_sales'], 0) . '</p>';
                echo '<p>مجموع پورسانت و تخفیف: ' . number_format($day['total_discount'], 0) . '</p>';
                echo '<p>هزینه آژانس: ' . number_format($agency_cost, 0) . ' (' . htmlspecialchars($day['agency_name'] ?? 'نامشخص') . ')</p>';
                echo '</td>';
            } else {
                echo '<td colspan="3" class="day-cell"></td>';
            }

            echo '<td style="border-bottom-style: solid; border-bottom-width: 2.25pt; width: 0.65pt;"></td>';
            echo '</tr>';

            // ردیف بدهکاران
            $current_debtors = $debtor_chunks[$index] ?? [];
            // جلوگیری از خطای array_chunk با length=0
            if (count($current_debtors) > 0) {
                $debtor_half = array_chunk($current_debtors, ceil(count($current_debtors) / 2));
            } else {
                $debtor_half = [[], []]; // آرایه خالی برای بدهکاران
            }
            $left_debtors = $debtor_half[0] ?? [];
            $right_debtors = $debtor_half[1] ?? [];
            $row_height = count($current_debtors) > 8 ? '188.35pt' : '161.1pt';

            echo '<tr style="height: ' . $row_height . ';" class="debtor-table">';
            echo '<td style="border-top-style: solid; border-top-width: 2.25pt; width: 0.35pt;"></td>';

            // بدهکاران سمت راست
            echo '<td colspan="2" class="debtor-name-cell">';
            echo '<p>نام بدهکاران</p>';
            if (count($left_debtors) > 0) {
                foreach ($left_debtors as $debtor) {
                    echo '<p>' . htmlspecialchars($debtor['name']) . '</p>';
                }
                for ($i = count($left_debtors); $i < 5; $i++) {
                    echo '<p> </p>';
                }
            } else {
                for ($i = 0; $i < 5; $i++) {
                    echo '<p> </p>';
                }
            }
            echo '</td>';

            echo '<td class="debtor-amount-cell">';
            echo '<p>مبلغ</p>';
            if (count($left_debtors) > 0) {
                foreach ($left_debtors as $debtor) {
                    echo '<p>' . number_format($debtor['amount'], 0) . '</p>';
                }
                for ($i = count($left_debtors); $i < 5; $i++) {
                    echo '<p> </p>';
                }
            } else {
                for ($i = 0; $i < 5; $i++) {
                    echo '<p> </p>';
                }
            }
            echo '</td>';

            // بدهکاران سمت چپ
            echo '<td colspan="2" class="debtor-name-cell">';
            echo '<p>نام بدهکاران</p>';
            if (count($right_debtors) > 0) {
                foreach ($right_debtors as $debtor) {
                    echo '<p>' . htmlspecialchars($debtor['name']) . '</p>';
                }
                for ($i = count($right_debtors); $i < 5; $i++) {
                    echo '<p> </p>';
                }
            } else {
                for ($i = 0; $i < 5; $i++) {
                    echo '<p> </p>';
                }
            }
            echo '</td>';

            echo '<td class="debtor-amount-cell">';
            echo '<p>مبلغ</p>';
            if (count($right_debtors) > 0) {
                foreach ($right_debtors as $debtor) {
                    echo '<p>' . number_format($debtor['amount'], 0) . '</p>';
                }
                for ($i = count($right_debtors); $i < 5; $i++) {
                    echo '<p> </p>';
                }
            } else {
                for ($i = 0; $i < 5; $i++) {
                    echo '<p> </p>';
                }
            }
            echo '</td>';

            echo '<td style="border-top-style: solid; border-top-width: 2.25pt; width: 0.65pt;"></td>';
            echo '</tr>';

            // ردیف فاصله‌گذار
            echo '<tr class="spacer">';
            echo '<td style="width: 0.35pt;"></td>';
            echo '<td style="width: 139.4pt;"></td>';
            echo '<td style="width: 139.8pt;"></td>';
            echo '<td style="width: 0.35pt;"></td>';
            echo '<td style="width: 139.4pt;"></td>';
            echo '<td style="width: 139.75pt;"></td>';
            echo '<td style="width: 0.65pt;"></td>';
            echo '</tr>';

            echo '</tbody>';
            echo '</table>';
            echo '</div>';

            // اضافه کردن page break برای صفحات بعدی
            if ($index < count($work_days_chunks) - 1) {
                echo '<div class="page-break"></div>';
            }
        }

        // اگه هیچ روز کاری‌ای وجود نداشت، یه جدول خالی نمایش بده
        if (empty($work_days_chunks)) {
            echo '<div class="table-container">';
            echo '<table class="report-table">';
            echo '<tbody>';

            // ردیف روزهای کاری (خالی)
            echo '<tr style="height: 148.35pt;">';
            echo '<td style="border-bottom-style: solid; border-bottom-width: 2.25pt; width: 0.35pt;"></td>';
            echo '<td colspan="3" class="day-cell"></td>';
            echo '<td colspan="3" class="day-cell"></td>';
            echo '<td style="border-bottom-style: solid; border-bottom-width: 2.25pt; width: 0.65pt;"></td>';
            echo '</tr>';

            // ردیف بدهکاران (خالی)
            echo '<tr style="height: 220pt;" class="debtor-table">';
            echo '<td style="border-top-style: solid; border-top-width: 2.25pt; width: 0.35pt;"></td>';
            echo '<td colspan="2" class="debtor-name-cell">';
            echo '<p>نام بدهکاران</p>';
            for ($i = 0; $i < 5; $i++) {
                echo '<p> </p>';
            }
            echo '</td>';
            echo '<td class="debtor-amount-cell">';
            echo '<p>مبلغ</p>';
            for ($i = 0; $i < 5; $i++) {
                echo '<p> </p>';
            }
            echo '</td>';
            echo '<td colspan="2" class="debtor-name-cell">';
            echo '<p>نام بدهکاران</p>';
            for ($i = 0; $i < 5; $i++) {
                echo '<p> </p>';
            }
            echo '</td>';
            echo '<td class="debtor-amount-cell">';
            echo '<p>مبلغ</p>';
            for ($i = 0; $i < 5; $i++) {
                echo '<p> </p>';
            }
            echo '</td>';
            echo '<td style="border-top-style: solid; border-top-width: 2.25pt; width: 0.65pt;"></td>';
            echo '</tr>';

            // ردیف فاصله‌گذار
            echo '<tr class="spacer">';
            echo '<td style="width: 0.35pt;"></td>';
            echo '<td style="width: 139.4pt;"></td>';
            echo '<td style="width: 139.8pt;"></td>';
            echo '<td style="width: 0.35pt;"></td>';
            echo '<td style="width: 139.4pt;"></td>';
            echo '<td style="width: 139.75pt;"></td>';
            echo '<td style="width: 0.65pt;"></td>';
            echo '</tr>';

            echo '</tbody>';
            echo '</table>';
            echo '</div>';
        }
        ?>
    </div>
</body>

</html>