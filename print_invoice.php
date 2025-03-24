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
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>فاکتور فروش</title>
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;700&display=swap" rel="stylesheet">
    <style>
        body {
            margin: 0;
            padding: 0;
            font-family: 'Vazirmatn', sans-serif;
            direction: rtl;
            text-align: right;
        }

        .invoice-container {
            width: 148mm;
            /* عرض A5 */
            height: 210mm;
            /* ارتفاع A5 */
            margin: 0 auto;
            padding: 0 3mm;
            box-sizing: border-box;
            border: 1px solid #ccc;
            /* برای نمایش در صفحه */
            position: relative;
        }

        .invoice-header {
            text-align: center;
            margin-bottom: 5mm;
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
            bottom: 3mm;
            left: 0;
            right: 0;
            font-size: 9pt;
            text-align: center;
        }

        @media print {
            .invoice-container {
                border: none;
                /* حذف border در پرینت */
            }

            @page {
                size: A5 portrait;
                margin: 0;
            }
        }
    </style>
</head>

<body>
    <div class="invoice-container">
        <!-- تیتر -->
        <div class="invoice-header">
            <h3>فاکتور فروش</h3>
        </div>

        <!-- خط دوم: اطلاعات فاکتور -->
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
                <?php $index = 1; ?>
                <?php foreach ($items as $item): ?>
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

    <!-- <script>
        window.onload = function() {
            window.print();
        };
    </script> -->
</body>

</html>