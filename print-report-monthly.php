<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link
        href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@100;200;300;400;500;600;700;800;900&display=swap"
        rel="stylesheet">
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
    <script>
        window.onload = function() {
            window.print();
        };
    </script>
</head>

<body>
    <?php for ($page = 1; $page <= $total_pages; $page++): ?>
        <div class="page">
            <!-- هدر -->
            <div class="header"></div>
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
