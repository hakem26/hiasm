<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

require_once 'db.php';
require_once 'jdf.php';

$agency_cost = 200000;
$work_month_id = $_GET['work_month_id'] ?? '';
$partner_id = $_GET['partner_id'] ?? '';

if (!$work_month_id || !$partner_id) {
    die('پارامترهای نادرست.');
}

$stmt = $pdo->prepare("SELECT start_date, end_date FROM Work_Months WHERE work_month_id = ?");
$stmt->execute([$work_month_id]);
$month = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$month) {
    die('ماه کاری یافت نشد.');
}

$stmt = $pdo->prepare("SELECT wd.work_date, u.full_name AS agency_name,
           COALESCE(SUM(o.total_amount), 0) AS total_sales,
           COALESCE(SUM(o.discount), 0) AS total_discount
    FROM Work_Details wd
    LEFT JOIN Users u ON wd.agency_owner_id = u.user_id
    LEFT JOIN Orders o ON o.work_details_id = wd.id
    WHERE wd.work_month_id = ? AND wd.partner_id = ?
    GROUP BY wd.work_date, u.full_name
    ORDER BY wd.work_date");
$stmt->execute([$work_month_id, $partner_id]);
$work_days = $stmt->fetchAll(PDO::FETCH_ASSOC);

$debtors = [];
$stmt = $pdo->prepare("SELECT o.customer_name, o.total_amount, COALESCE(SUM(op.amount), 0) AS paid_amount
    FROM Orders o
    LEFT JOIN Order_Payments op ON o.order_id = op.order_id
    JOIN Work_Details wd ON o.work_details_id = wd.id
    WHERE wd.work_month_id = ? AND wd.partner_id = ?
    GROUP BY o.order_id, o.customer_name, o.total_amount
    HAVING paid_amount < total_amount");
$stmt->execute([$work_month_id, $partner_id]);
$debts = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($debts as $debt) {
    $remaining = $debt['total_amount'] - $debt['paid_amount'];
    if ($remaining > 0) {
        $debtors[] = ['name' => $debt['customer_name'], 'amount' => $remaining];
    }
}

function gregorian_to_jalali_format($date) {
    list($gy, $gm, $gd) = explode('-', $date);
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
    return sprintf("%04d/%02d/%02d", $jy, $jm, $jd);
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>گزارش خلاصه</title>
    <style>
        @page { size: A4 portrait; margin: 3mm; }
        body { font-family: Arial, sans-serif; font-size: 12px; }
        .container { display: flex; flex-wrap: wrap; justify-content: space-between; }
        .day-box, .debtors-box { width: 48%; border: 1px solid #ccc; padding: 5px; box-sizing: border-box; }
        .debtors-left, .debtors-right { width: 50%; float: right; padding: 5px; }
        .clear { clear: both; }
        @media print { .no-print { display: none; } }
    </style>
</head>
<body>
<div class="container">
    <?php
    $day_count = 0;
    foreach ($work_days as $index => $day) {
        $day_count++;
        $jalali_date = gregorian_to_jalali_format($day['work_date']);
        echo '<div class="day-box">';
        echo "<p>تاریخ: $jalali_date</p>";
        echo "<p>مجموع فروش: " . number_format($day['total_sales']) . " تومان</p>";
        echo "<p>مجموع تخفیف: " . number_format($day['total_discount']) . " تومان</p>";
        echo "<p>هزینه آژانس: " . number_format($agency_cost) . " تومان</p>";
        echo '</div>';
        if ($day_count % 4 == 0) {
            echo '<div class="clear"></div><div style="page-break-before: always;"></div>';
        }
    }
    ?>
    <div class="debtors-box">
        <div class="debtors-right"><strong>نام بدهکاران</strong><br>
            <?php echo implode('<br>', array_column($debtors, 'name')); ?>
        </div>
        <div class="debtors-left"><strong>مبلغ</strong><br>
            <?php echo implode('<br>', array_map(fn($d) => number_format($d['amount']), $debtors)); ?>
        </div>
        <div class="clear"></div>
    </div>
</div>
<button class="no-print" onclick="window.print()">چاپ</button>
</body>
</html>
