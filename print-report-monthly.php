<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

require_once 'db.php';
require_once 'jdf.php';

// تابع تبدیل تاریخ میلادی به شمسی
function gregorian_to_jalali_format($gregorian_date) {
    list($gy, $gm, $gd) = explode('-', $gregorian_date);
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
    return sprintf("%02d/%02d/%04d", $jd, $jm, $jy);
}

// بررسی دسترسی کاربر
$current_user_id = $_SESSION['user_id'];
$work_month_id = $_GET['work_month_id'] ?? '';
$partner_id = $_GET['partner_id'] ?? '';

if (!$work_month_id || !$partner_id) {
    die('پارامترهای لازم مشخص نشده‌اند.');
}

// دریافت اطلاعات ماه کاری
$stmt = $pdo->prepare("
    SELECT wm.start_date, wm.end_date, p.user_id1, p.user_id2, u1.full_name AS user1_name, u2.full_name AS user2_name,
           SUM(o.total_amount) AS total_sales
    FROM Work_Months wm
    JOIN Work_Details wd ON wm.work_month_id = wd.work_month_id
    JOIN Partners p ON wd.partner_id = p.partner_id
    LEFT JOIN Users u1 ON p.user_id1 = u1.user_id
    LEFT JOIN Users u2 ON p.user_id2 = u2.user_id
    LEFT JOIN Orders o ON o.work_details_id = wd.id
    WHERE wm.work_month_id = ? AND p.partner_id = ?
    GROUP BY wm.work_month_id, p.partner_id
");
$stmt->execute([$work_month_id, $partner_id]);
$month_data = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$month_data) {
    die('ماه کاری یا همکاران یافت نشد.');
}

$start_date = gregorian_to_jalali_format($month_data['start_date']);
$end_date = gregorian_to_jalali_format($month_data['end_date']);
$user1_name = $month_data['user1_name'] ?: 'نامشخص';
$user2_name = $month_data['user2_name'] ?: 'نامشخص';
$total_sales = $month_data['total_sales'] ?? 0;

// دریافت روزهای کاری و سفارشات
$stmt = $pdo->prepare("
    SELECT wd.id, wd.work_date, o.order_id, o.customer_name, o.total_amount, o.discount, o.final_amount
    FROM Work_Details wd
    LEFT JOIN Orders o ON o.work_details_id = wd.id
    WHERE wd.work_month_id = ? AND wd.partner_id = ?
    ORDER BY wd.work_date ASC
");
$stmt->execute([$work_month_id, $partner_id]);
$work_days = [];
$current_day = null;

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $work_date = $row['work_date'];
    if ($current_day !== $work_date) {
        if ($current_day !== null) {
            $work_days[] = $day_data;
        }
        $current_day = $work_date;
        $day_data = [
            'work_date' => gregorian_to_jalali_format($work_date),
            'orders' => []
        ];
    }
    if ($row['order_id']) {
        // دریافت اقلام سفارش
        $items_stmt = $pdo->prepare("SELECT product_name, quantity, total_price FROM Order_Items WHERE order_id = ?");
        $items_stmt->execute([$row['order_id']]);
        $items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

        $items_str = [];
        foreach ($items as $item) {
            $quantity = $item['quantity'] == 1 ? '' : $item['quantity'] . 'عدد ';
            $items_str[] = "{$item['product_name']} {$quantity}({$item['total_price']})";
        }
        $items_display = implode(' - ', $items_str);

        $day_data['orders'][] = [
            'customer_name' => $row['customer_name'],
            'items' => $items_display,
            'total_amount' => $row['total_amount'],
            'discount' => $row['discount'],
            'final_amount' => $row['final_amount'],
            'payment_type' => 'نقدی', // فعلاً ثابت (برای گزارش‌های بعدی تغییر می‌کنه)
            'payment_date' => '', // فعلاً خالی
            'remaining' => 0 // فعلاً صفر
        ];
    }
}
if ($current_day !== null) {
    $work_days[] = $day_data;
}

// تنظیمات صفحه‌بندی (2 جدول در هر صفحه)
$tables_per_page = 2;
$total_tables = count($work_days);
$total_pages = ceil($total_tables / $tables_per_page);
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>چاپ گزارش ماهانه</title>
    <style>
        @page {
            size: A4;
            margin: 3mm;
        }

        body {
            font-family: 'Tahoma', sans-serif;
            font-size: 10pt;
            margin: 0;
            padding: 0;
            direction: rtl;
        }

        .page {
            width: 210mm;
            height: 297mm;
            padding: 3mm;
            box-sizing: border-box;
            page-break-after: always;
        }

        .header {
            text-align: center;
            margin-bottom: 10mm;
            font-size: 12pt;
            font-weight: bold;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10mm;
        }

        th, td {
            border: 1px solid #000;
            padding: 2mm;
            text-align: center;
        }

        th {
            background-color: #f0f0f0;
            font-weight: bold;
        }

        /* تنظیم عرض ستون‌ها */
        .col-date { width: auto; white-space: nowrap; }
        .col-customer { width: auto; white-space: nowrap; }
        .col-items { width: 100%; } /* این ستون باقی‌مانده عرض رو پر می‌کنه */
        .col-total { width: auto; white-space: nowrap; }
        .col-discount { width: auto; white-space: nowrap; }
        .col-final { width: auto; white-space: nowrap; }
        .col-payment-type { width: auto; white-space: nowrap; }
        .col-payment-date { width: auto; white-space: nowrap; }
        .col-remaining { width: auto; white-space: nowrap; }
    </style>
</head>

<body>
    <?php for ($page = 1; $page <= $total_pages; $page++): ?>
        <div class="page">
            <!-- هدر -->
            <div class="header">
                گزارش کاری <?= htmlspecialchars($user1_name) ?> و <?= htmlspecialchars($user2_name) ?>
                از تاریخ <?= $start_date ?> تا تاریخ <?= $end_date ?>
                مبلغ <?= number_format($total_sales, 0) ?> تومان
            </div>

            <!-- جدول‌ها -->
            <?php
            $start_table = ($page - 1) * $tables_per_page;
            $end_table = min($start_table + $tables_per_page, $total_tables);
            for ($i = $start_table; $i < $end_table; $i++):
                $day = $work_days[$i];
            ?>
                <table>
                    <thead>
                        <tr>
                            <th class="col-date">تاریخ</th>
                            <th class="col-customer">نام خریدار</th>
                            <th class="col-items">اقلام و قیمت</th>
                            <th class="col-total">جمع</th>
                            <th class="col-discount">تخفیف</th>
                            <th class="col-final">رقم پرداختی</th>
                            <th class="col-payment-type">نوع پ</th>
                            <th class="col-payment-date">تاریخ پ</th>
                            <th class="col-remaining">مانده</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($day['orders'])): ?>
                            <tr>
                                <td class="col-date"><?= $day['work_date'] ?></td>
                                <td class="col-customer">-</td>
                                <td class="col-items">-</td>
                                <td class="col-total">-</td>
                                <td class="col-discount">-</td>
                                <td class="col-final">-</td>
                                <td class="col-payment-type">-</td>
                                <td class="col-payment-date">-</td>
                                <td class="col-remaining">-</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($day['orders'] as $order): ?>
                                <tr>
                                    <td class="col-date"><?= $day['work_date'] ?></td>
                                    <td class="col-customer"><?= htmlspecialchars($order['customer_name']) ?></td>
                                    <td class="col-items"><?= htmlspecialchars($order['items']) ?></td>
                                    <td class="col-total"><?= number_format($order['total_amount'], 0) ?></td>
                                    <td class="col-discount"><?= number_format($order['discount'], 0) ?></td>
                                    <td class="col-final"><?= number_format($order['final_amount'], 0) ?></td>
                                    <td class="col-payment-type"><?= $order['payment_type'] ?></td>
                                    <td class="col-payment-date"><?= $order['payment_date'] ?: '-' ?></td>
                                    <td class="col-remaining"><?= number_format($order['remaining'], 0) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            <?php endfor; ?>
        </div>
    <?php endfor; ?>
</body>

</html>