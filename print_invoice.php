<?php
session_start();
require_once 'db.php';
require_once 'jdf.php';

function gregorian_to_jalali_format($gregorian_date)
{
    list($gy, $gm, $gd) = explode('-', $gregorian_date);
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
    return "$jy/$jm/$jd";
}

// دریافت اطلاعات فاکتور
$order_id = isset($_GET['order_id']) ? (int) $_GET['order_id'] : 0;
if ($order_id <= 0) {
    echo "فاکتور نامعتبر است.";
    exit;
}

// دریافت اطلاعات سفارش
$stmt = $pdo->prepare("
    SELECT o.order_id, o.customer_name, o.total_amount, o.discount, o.final_amount, wd.work_date,
           u1.full_name AS partner1_name, u1.phone_number AS partner1_phone,
           u2.full_name AS partner2_name, u2.phone_number AS partner2_phone
    FROM Orders o
    LEFT JOIN Work_Details wd ON o.work_details_id = wd.id
    LEFT JOIN Partners p ON wd.partner_id = p.partner_id
    LEFT JOIN Users u1 ON p.user_id1 = u1.user_id
    LEFT JOIN Users u2 ON p.user_id2 = u2.user_id
    WHERE o.order_id = ?
");
$stmt->execute([$order_id]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    echo "فاکتور یافت نشد.";
    exit;
}

// دریافت محصولات فاکتور
$stmt_items = $pdo->prepare("
    SELECT product_name, unit_price, quantity
    FROM Order_Items
    WHERE order_id = ?
");
$stmt_items->execute([$order_id]);
$items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);

// تقسیم محصولات به گروه‌های 14 تایی
$items_per_page = 14;
$total_items = count($items);
$total_pages = ceil($total_items / $items_per_page);
$pages = array_chunk($items, $items_per_page);
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>فاکتور فروش</title>
    <style>
        @font-face {
            font-family: Vazirmatn RD FD NL;
            src: url('assets/fonts/Vazirmatn-RD-FD-NL-Thin.woff2') format('woff2');
            font-weight: 100;
            font-style: normal;
            font-display: swap;
        }
        @font-face {
            font-family: Vazirmatn RD FD NL;
            src: url('assets/fonts/Vazirmatn-RD-FD-NL-ExtraLight.woff2') format('woff2');
            font-weight: 200;
            font-style: normal;
            font-display: swap;
        }
        @font-face {
            font-family: Vazirmatn RD FD NL;
            src: url('assets/fonts/Vazirmatn-RD-FD-NL-Light.woff2') format('woff2');
            font-weight: 300;
            font-style: normal;
            font-display: swap;
        }
        @font-face {
            font-family: Vazirmatn RD FD NL;
            src: url('assets/fonts/Vazirmatn-RD-FD-NL-Regular.woff2') format('woff2');
            font-weight: 400;
            font-style: normal;
            font-display: swap;
        }
        @font-face {
            font-family: Vazirmatn RD FD NL;
            src: url('assets/fonts/Vazirmatn-RD-FD-NL-Medium.woff2') format('woff2');
            font-weight: 500;
            font-style: normal;
            font-display: swap;
        }
        @font-face {
            font-family: Vazirmatn RD FD NL;
            src: url('assets/fonts/Vazirmatn-RD-FD-NL-SemiBold.woff2') format('woff2');
            font-weight: 600;
            font-style: normal;
            font-display: swap;
        }
        @font-face {
            font-family: Vazirmatn RD FD NL;
            src: url('assets/fonts/Vazirmatn-RD-FD-NL-Bold.woff2') format('woff2');
            font-weight: 700;
            font-style: normal;
            font-display: swap;
        }
        @font-face {
            font-family: Vazirmatn RD FD NL;
            src: url('assets/fonts/Vazirmatn-RD-FD-NL-ExtraBold.woff2') format('woff2');
            font-weight: 800;
            font-style: normal;
            font-display: swap;
        }
        @font-face {
            font-family: Vazirmatn RD FD NL;
            src: url('assets/fonts/Vazirmatn-RD-FD-NL-Black.woff2') format('woff2');
            font-weight: 900;
            font-style: normal;
            font-display: swap;
        }

        * {
            font-feature-settings: "lnum" 0;
            font-variant-numeric: normal;
        }

        body {
            margin: 0;
            padding: 0;
            font-family: "Vazirmatn RD FD NL";
            unicode-range: U+06F0-06F9;
            direction: rtl;
            text-align: right;
        }

        .invoice-container {
            width: 148mm;
            height: 210mm;
            margin: 0 auto;
            padding: 0 5mm;
            box-sizing: border-box;
            border: 1px solid #ccc;
            position: relative;
            overflow: hidden;
            page-break-after: always;
        }

        .invoice-container:last-child {
            page-break-after: auto;
        }

        .invoice-header {
            text-align: center;
            margin-bottom: 5mm;
            position: relative;
        }

        .page-number {
            position: absolute;
            top: 2mm;
            right: 5mm;
            font-size: 10pt;
        }

        .invoice-details {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5mm;
            font-size: 10pt;
        }

        .invoice-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 3mm;
        }

        .invoice-table th,
        .invoice-table td {
            border: 1px solid #000;
            padding: 5px;
            text-align: center;
            font-size: 10pt;
        }

        .invoice-table th {
            background-color: #f0f0f0;
        }

        .invoice-summary {
            font-size: 10pt;
        }

        .invoice-footer {
            position: absolute;
            bottom: 2mm;
            left: 0;
            right: 0;
            font-size: 8pt;
            text-align: center;
        }

        @media print {
            .invoice-container {
                border: none;
            }

            @page {
                size: A5 portrait;
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
    <?php for ($page = 0; $page < $total_pages; $page++): ?>
        <div class="invoice-container">
            <!-- تیتر و شماره صفحه -->
            <div class="invoice-header">
                <h3>فاکتور فروش</h3>
                <div class="page-number">صفحه <?= ($page + 1) ?> از <?= $total_pages ?></div>
            </div>

            <!-- اطلاعات فاکتور -->
            <div class="invoice-details">
                <div>صورتحساب: <?= htmlspecialchars($order['customer_name']) ?></div>
                <div>تاریخ: <?= gregorian_to_jalali_format($order['work_date']) ?></div>
                <div>شماره فاکتور: <?= $order['order_id'] ?></div>
            </div>

            <!-- جدول محصولات -->
            <table class="invoice-table">
                <thead>
                    <tr>
                        <th>ردیف</th>
                        <th>نام محصول</th>
                        <th>قیمت واحد</th>
                        <th>تعداد</th>
                        <th>قیمت کل</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $index = $page * $items_per_page + 1; ?>
                    <?php foreach ($pages[$page] as $item): ?>
                        <tr>
                            <td><?= $index++ ?></td>
                            <td><?= htmlspecialchars($item['product_name']) ?></td>
                            <td><?= number_format($item['unit_price'], 0) ?> تومان</td>
                            <td><?= $item['quantity'] ?></td>
                            <td><?= number_format($item['unit_price'] * $item['quantity'], 0) ?> تومان</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- جمع‌بندی -->
            <div class="invoice-summary">
                <p>مبلغ کل فاکتور: <?= number_format($order['total_amount'], 0) ?> تومان</p>
                <p>تخفیف: <?= number_format($order['discount'], 0) ?> تومان</p>
                <p>مبلغ قابل پرداخت: <?= number_format($order['final_amount'], 0) ?> تومان</p>
            </div>

            <!-- پایین صفحه: فروشندگان -->
            <div class="invoice-footer">
                <hr>
                <p>فروشندگان: </p>
                <p>
                    <?= htmlspecialchars($order['partner1_name']) ?> - شماره تماس:
                    <?= htmlspecialchars($order['partner1_phone'] ?? 'نامشخص') ?> |
                    <?= htmlspecialchars($order['partner2_name']) ?> - شماره تماس:
                    <?= htmlspecialchars($order['partner2_phone'] ?? 'نامشخص') ?>
                </p>
            </div>
        </div>
    <?php endfor; ?>

    <script>
        window.onload = function () {
            window.print();
        };
    </script>
</body>

</html>