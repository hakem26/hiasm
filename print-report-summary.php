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

// محاسبه بدهکاران برای هر روز کاری
$debtors_by_day = [];
$stmt = $pdo->prepare("
    SELECT wd.work_date, o.customer_name, o.total_amount, o.final_amount, COALESCE(SUM(op.amount), 0) AS paid_amount
    FROM Orders o
    LEFT JOIN Order_Payments op ON o.order_id = op.order_id
    JOIN Work_Details wd ON o.work_details_id = wd.id
    WHERE wd.work_month_id = ? AND wd.partner_id = ?
    GROUP BY wd.work_date, o.order_id, o.customer_name, o.total_amount, o.final_amount
    HAVING paid_amount < o.final_amount
");
$stmt->execute([$work_month_id, $partner_id]);
$debts = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($debts as $debt) {
    $remaining = $debt['final_amount'] - $debt['paid_amount'];
    if ($remaining > 0) {
        $debtors_by_day[$debt['work_date']][] = [
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

function get_jalali_month_name($month) {
    $month_names = [
        1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد', 4 => 'تیر', 5 => 'مرداد', 6 => 'شهریور',
        7 => 'مهر', 8 => 'آبان', 9 => 'آذر', 10 => 'دی', 11 => 'بهمن', 12 => 'اسفند'
    ];
    return $month_names[$month] ?? '';
}

// تبدیل تاریخ‌ها به شمسی
$start_date_jalali = gregorian_to_jalali_format($start_date);
$end_date_jalali = gregorian_to_jalali_format($end_date);

// آماده‌سازی روزهای کاری با تاریخ شمسی
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

// تقسیم روزهای کاری به صفحات (هر صفحه 4 روز)
$days_per_page = 4;
$work_days_chunks = array_chunk($work_days_with_month, $days_per_page);
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;700&display=swap" rel="stylesheet">
    <title>چاپ گزارش خلاصه</title>
    <style>
        body {
            font-family: "Vazirmatn", sans-serif;
            margin: 0;
            padding: 0;
            direction: rtl;
        }
        .page {
            width: 210mm;
            height: 297mm;
            margin: 0 auto;
            page-break-after: always;
            box-sizing: border-box;
            padding: 10mm;
            border: 1px solid #ccc; /* برای نمایش در صفحه */
        }
        .header {
            text-align: center;
            font-size: 12pt;
            font-weight: bold;
            margin-bottom: 10mm;
        }
        .main-table {
            width: 189mm; /* 90% of 210mm */
            height: 267mm; /* 90% of 297mm */
            border-collapse: collapse;
            margin: 0 auto;
        }
        .main-table td {
            border: 1px solid black;
            width: 50%;
            vertical-align: top;
        }
        .day-table {
            width: 100%;
            height: 100%;
            border-collapse: collapse;
        }
        .day-table td {
            border: 1px solid black;
            vertical-align: top;
        }
        .details {
        }
        .debtors {
        }
        .debtors-table {
            width: 100%;
            height: 100%;
            border-collapse: collapse;
        }
        .debtors-table td {
            border: 1px solid black;
            width: 50%;
            text-align: center;
            vertical-align: top;
        }
        p {
            margin: 2mm 0;
            font-size: 11pt;
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
    <?php
    foreach ($work_days_chunks as $page_index => $chunk) {
        while (count($chunk) < $days_per_page) {
            $chunk[] = null;
        }
    ?>
        <div class="page">
            <div class="header">
                گزارش کاری <?= htmlspecialchars($partner1_name) ?> و <?= htmlspecialchars($partner2_name) ?>
                از تاریخ <?= $start_date_jalali ?> تا تاریخ <?= $end_date_jalali ?>
                مبلغ <?= number_format($total_sales, 0) ?> تومان
            </div>

            <table class="main-table">
                <tr>
                    <td>
                        <?php if (isset($chunk[0]) && $chunk[0]) {
                            $day = $chunk[0];
                            $debtors = $debtors_by_day[$day['work_date']] ?? [];
                        ?>
                            <table class="day-table">
                                <tr>
                                    <td class="details">
                                        <p>تاریخ: <?= htmlspecialchars($day['jalali_date']) ?></p>
                                        <p>مجموع فروش: <?= number_format($day['total_sales'], 0) ?></p>
                                        <p>مجموع پورسانت و تخفیف: <?= number_format($day['total_discount'], 0) ?></p>
                                        <p>هزینه آژانس: <?= number_format($agency_cost, 0) ?> (<?= htmlspecialchars($day['agency_name'] ?? 'نامشخص') ?>)</p>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="debtors">
                                        <table class="debtors-table">
                                            <tr>
                                                <td>
                                                    <p>نام بدهکاران</p>
                                                    <?php foreach ($debtors as $debtor) { ?>
                                                        <p><?= htmlspecialchars($debtor['name']) ?></p>
                                                    <?php } ?>
                                                    <?php for ($i = count($debtors); $i < 5; $i++) { ?>
                                                        <p>&nbsp;</p>
                                                    <?php } ?>
                                                </td>
                                                <td>
                                                    <p>مبلغ</p>
                                                    <?php foreach ($debtors as $debtor) { ?>
                                                        <p><?= number_format($debtor['amount'], 0) ?></p>
                                                    <?php } ?>
                                                    <?php for ($i = count($debtors); $i < 5; $i++) { ?>
                                                        <p>&nbsp;</p>
                                                    <?php } ?>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        <?php } else { ?>
                            <table class="day-table">
                                <tr>
                                    <td class="details"></td>
                                </tr>
                                <tr>
                                    <td class="debtors">
                                        <table class="debtors-table">
                                            <tr>
                                                <td><p>نام بدهکاران</p><?php for ($i = 0; $i < 5; $i++) { echo '<p>&nbsp;</p>'; } ?></td>
                                                <td><p>مبلغ</p><?php for ($i = 0; $i < 5; $i++) { echo '<p>&nbsp;</p>'; } ?></td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        <?php } ?>
                    </td>
                    <td>
                        <?php if (isset($chunk[1]) && $chunk[1]) {
                            $day = $chunk[1];
                            $debtors = $debtors_by_day[$day['work_date']] ?? [];
                        ?>
                            <table class="day-table">
                                <tr>
                                    <td class="details">
                                        <p>تاریخ: <?= htmlspecialchars($day['jalali_date']) ?></p>
                                        <p>مجموع فروش: <?= number_format($day['total_sales'], 0) ?></p>
                                        <p>مجموع پورسانت و تخفیف: <?= number_format($day['total_discount'], 0) ?></p>
                                        <p>هزینه آژانس: <?= number_format($agency_cost, 0) ?> (<?= htmlspecialchars($day['agency_name'] ?? 'نامشخص') ?>)</p>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="debtors">
                                        <table class="debtors-table">
                                            <tr>
                                                <td>
                                                    <p>نام بدهکاران</p>
                                                    <?php foreach ($debtors as $debtor) { ?>
                                                        <p><?= htmlspecialchars($debtor['name']) ?></p>
                                                    <?php } ?>
                                                    <?php for ($i = count($debtors); $i < 5; $i++) { ?>
                                                        <p>&nbsp;</p>
                                                    <?php } ?>
                                                </td>
                                                <td>
                                                    <p>مبلغ</p>
                                                    <?php foreach ($debtors as $debtor) { ?>
                                                        <p><?= number_format($debtor['amount'], 0) ?></p>
                                                    <?php } ?>
                                                    <?php for ($i = count($debtors); $i < 5; $i++) { ?>
                                                        <p>&nbsp;</p>
                                                    <?php } ?>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        <?php } else { ?>
                            <table class="day-table">
                                <tr>
                                    <td class="details"></td>
                                </tr>
                                <tr>
                                    <td class="debtors">
                                        <table class="debtors-table">
                                            <tr>
                                                <td><p>نام بدهکاران</p><?php for ($i = 0; $i < 5; $i++) { echo '<p>&nbsp;</p>'; } ?></td>
                                                <td><p>مبلغ</p><?php for ($i = 0; $i < 5; $i++) { echo '<p>&nbsp;</p>'; } ?></td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        <?php } ?>
                    </td>
                </tr>
                <tr>
                    <td>
                        <?php if (isset($chunk[2]) && $chunk[2]) {
                            $day = $chunk[2];
                            $debtors = $debtors_by_day[$day['work_date']] ?? [];
                        ?>
                            <table class="day-table">
                                <tr>
                                    <td class="details">
                                        <p>تاریخ: <?= htmlspecialchars($day['jalali_date']) ?></p>
                                        <p>مجموع فروش: <?= number_format($day['total_sales'], 0) ?></p>
                                        <p>مجموع پورسانت و تخفیف: <?= number_format($day['total_discount'], 0) ?></p>
                                        <p>هزینه آژانس: <?= number_format($agency_cost, 0) ?> (<?= htmlspecialchars($day['agency_name'] ?? 'نامشخص') ?>)</p>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="debtors">
                                        <table class="debtors-table">
                                            <tr>
                                                <td>
                                                    <p>نام بدهکاران</p>
                                                    <?php foreach ($debtors as $debtor) { ?>
                                                        <p><?= htmlspecialchars($debtor['name']) ?></p>
                                                    <?php } ?>
                                                    <?php for ($i = count($debtors); $i < 5; $i++) { ?>
                                                        <p>&nbsp;</p>
                                                    <?php } ?>
                                                </td>
                                                <td>
                                                    <p>مبلغ</p>
                                                    <?php foreach ($debtors as $debtor) { ?>
                                                        <p><?= number_format($debtor['amount'], 0) ?></p>
                                                    <?php } ?>
                                                    <?php for ($i = count($debtors); $i < 5; $i++) { ?>
                                                        <p>&nbsp;</p>
                                                    <?php } ?>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        <?php } else { ?>
                            <table class="day-table">
                                <tr>
                                    <td class="details"></td>
                                </tr>
                                <tr>
                                    <td class="debtors">
                                        <table class="debtors-table">
                                            <tr>
                                                <td><p>نام بدهکاران</p><?php for ($i = 0; $i < 5; $i++) { echo '<p>&nbsp;</p>'; } ?></td>
                                                <td><p>مبلغ</p><?php for ($i = 0; $i < 5; $i++) { echo '<p>&nbsp;</p>'; } ?></td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        <?php } ?>
                    </td>
                    <td>
                        <?php if (isset($chunk[3]) && $chunk[3]) {
                            $day = $chunk[3];
                            $debtors = $debtors_by_day[$day['work_date']] ?? [];
                        ?>
                            <table class="day-table">
                                <tr>
                                    <td class="details">
                                        <p>تاریخ: <?= htmlspecialchars($day['jalali_date']) ?></p>
                                        <p>مجموع فروش: <?= number_format($day['total_sales'], 0) ?></p>
                                        <p>مجموع پورسانت و تخفیف: <?= number_format($day['total_discount'], 0) ?></p>
                                        <p>هزینه آژانس: <?= number_format($agency_cost, 0) ?> (<?= htmlspecialchars($day['agency_name'] ?? 'نامشخص') ?>)</p>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="debtors">
                                        <table class="debtors-table">
                                            <tr>
                                                <td>
                                                    <p>نام بدهکاران</p>
                                                    <?php foreach ($debtors as $debtor) { ?>
                                                        <p><?= htmlspecialchars($debtor['name']) ?></p>
                                                    <?php } ?>
                                                    <?php for ($i = count($debtors); $i < 5; $i++) { ?>
                                                        <p>&nbsp;</p>
                                                    <?php } ?>
                                                </td>
                                                <td>
                                                    <p>مبلغ</p>
                                                    <?php foreach ($debtors as $debtor) { ?>
                                                        <p><?= number_format($debtor['amount'], 0) ?></p>
                                                    <?php } ?>
                                                    <?php for ($i = count($debtors); $i < 5; $i++) { ?>
                                                        <p>&nbsp;</p>
                                                    <?php } ?>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        <?php } else { ?>
                            <table class="day-table">
                                <tr>
                                    <td class="details"></td>
                                </tr>
                                <tr>
                                    <td class="debtors">
                                        <table class="debtors-table">
                                            <tr>
                                                <td><p>نام بدهکاران</p><?php for ($i = 0; $i < 5; $i++) { echo '<p>&nbsp;</p>'; } ?></td>
                                                <td><p>مبلغ</p><?php for ($i = 0; $i < 5; $i++) { echo '<p>&nbsp;</p>'; } ?></td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        <?php } ?>
                    </td>
                </tr>
            </table>
        </div>
        <?php if ($page_index < count($work_days_chunks) - 1) { ?>
            <div class="page-break"></div>
        <?php } ?>
    <?php } ?>
</body>
</html>