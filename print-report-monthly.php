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
    return sprintf("%04d/%02d/%02d", $jy, $jm, $jd);
}

// متغیر برای مشخص کردن اینکه خروجی برای PDF هست یا نه
$is_pdf = isset($is_pdf) ? $is_pdf : false;

// دریافت پارامترها
$work_month_id = $_GET['work_month_id'] ?? null;
$partner_id = $_GET['partner_id'] ?? null;
$current_user_id = $_SESSION['user_id'];

if (!$work_month_id || !$partner_id) {
    die('پارامترهای مورد نیاز یافت نشد.');
}

// بررسی دسترسی کاربر
$stmt_access = $pdo->prepare("
    SELECT COUNT(*) 
    FROM Partners p 
    WHERE p.partner_id = ? AND (p.user_id1 = ? OR p.user_id2 = ?)
");
$stmt_access->execute([$partner_id, $current_user_id, $current_user_id]);
if ($stmt_access->fetchColumn() == 0) {
    die('شما به این گزارش دسترسی ندارید.');
}

// دریافت اطلاعات ماه کاری
$stmt_month = $pdo->prepare("SELECT start_date, end_date FROM Work_Months WHERE work_month_id = ?");
$stmt_month->execute([$work_month_id]);
$month = $stmt_month->fetch(PDO::FETCH_ASSOC);

if (!$month) {
    die('ماه کاری یافت نشد.');
}

$start_date = gregorian_to_jalali_format($month['start_date']);
$end_date = gregorian_to_jalali_format($month['end_date']);

// دریافت نام همکاران
$stmt_partner = $pdo->prepare("
    SELECT u1.full_name AS user1_name, u2.full_name AS user2_name
    FROM Partners p
    LEFT JOIN Users u1 ON p.user_id1 = u1.user_id
    LEFT JOIN Users u2 ON p.user_id2 = u2.user_id
    WHERE p.partner_id = ?
");
$stmt_partner->execute([$partner_id]);
$partner = $stmt_partner->fetch(PDO::FETCH_ASSOC);

$user1_name = $partner['user1_name'] ?? 'نامشخص';
$user2_name = $partner['user2_name'] ?? 'نامشخص';

// دریافت روزهای کاری و سفارشات
$stmt_days = $pdo->prepare("
    SELECT wd.id, wd.work_date,
           GROUP_CONCAT(
               CONCAT(o.order_id, '|||', o.customer_name, '|||', o.total_amount, '|||', o.discount, '|||', o.final_amount, '|||', 
                      (SELECT GROUP_CONCAT(CONCAT(oi.quantity, ' x ', oi.item_name, ' @ ', oi.unit_price) SEPARATOR ', ') 
                       FROM Order_Items oi WHERE oi.order_id = o.order_id) SEPARATOR '---') AS items,
               '---' AS delimiter
           ) AS order_items,
           (SELECT GROUP_CONCAT(CONCAT(op.amount, ' (', op.payment_type, ' - ', op.payment_date, ')') SEPARATOR ', ')
            FROM Order_Payments op WHERE op.order_id = o.order_id) AS payments
    FROM Work_Details wd
    LEFT JOIN Orders o ON o.work_details_id = wd.id
    WHERE wd.work_month_id = ? AND wd.partner_id = ?
    GROUP BY wd.id
    ORDER BY wd.work_date
");
$stmt_days->execute([$work_month_id, $partner_id]);
$work_days = [];

while ($row = $stmt_days->fetch(PDO::FETCH_ASSOC)) {
    $orders = [];
    if ($row['order_items']) {
        $order_items = explode('---', $row['order_items']);
        $payments = $row['payments'] ? explode(', ', $row['payments']) : [];
        
        foreach ($order_items as $index => $item) {
            if (empty($item)) continue;
            list($order_id, $customer_name, $total_amount, $discount, $final_amount, $items) = explode('|||', $item);
            $payment = isset($payments[$index]) ? $payments[$index] : '';
            $payment_type = $payment_date = '-';
            if ($payment) {
                preg_match('/(.+?) \((.+?) - (.+?)\)/', $payment, $matches);
                if ($matches) {
                    $payment_type = $matches[2];
                    $payment_date = gregorian_to_jalali_format($matches[3]);
                }
            }
            $remaining = $final_amount - array_sum(array_map(function($p) {
                preg_match('/(\d+)/', $p, $matches);
                return $matches[1] ?? 0;
            }, array_filter($payments)));

            $orders[] = [
                'customer_name' => $customer_name,
                'items' => $items,
                'total_amount' => $total_amount,
                'discount' => $discount,
                'final_amount' => $final_amount,
                'payment_type' => $payment_type,
                'payment_date' => $payment_date,
                'remaining' => $remaining
            ];
        }
    }

    $work_days[] = [
        'work_date' => gregorian_to_jalali_format($row['work_date']),
        'orders' => $orders
    ];
}

// محاسبه مجموع فروش
$total_sales = 0;
foreach ($work_days as $day) {
    foreach ($day['orders'] as $order) {
        $total_sales += $order['total_amount'];
    }
}

// تنظیمات صفحه‌بندی برای چاپ
$tables_per_page = 3; // تعداد جدول‌ها در هر صفحه
$total_tables = count($work_days);
$total_pages = ceil($total_tables / $tables_per_page);
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@100;200;300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <title>چاپ گزارش ماهانه</title>
    <style>
        @page {
            size: A4 landscape;
            margin: 3mm;
        }

        body {
            font-family: "Vazirmatn", sans-serif;
            font-size: 10pt;
            margin: 0;
            padding: 0;
            direction: rtl;
        }

        .page {
            width: 297mm;
            height: 210mm;
            padding: 3mm;
            font-weight: bold;
            margin: 0 auto;
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
    <?php if (!$is_pdf): ?>
    <script>
        window.onload = function() {
            window.print();
        };
    </script>
    <?php endif; ?>
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