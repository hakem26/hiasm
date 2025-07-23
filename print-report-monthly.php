<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

require_once 'db.php';
require_once 'jdf.php';

// تابع تبدیل تاریخ میلادی به شمسی
function gregorian_to_jalali_format($gregorian_date)
{
    list($gy, $gm, $gd) = explode('-', $gregorian_date);
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
    return sprintf("%04d/%02d/%02d", $jy, $jm, $jd); // فرمت کامل 1404/01/09
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

// دریافت روزهای کاری، سفارشات و پرداخت‌ها
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
$orders_data = [];

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
            $quantity = $item['quantity'] == 1 ? '' : '(' . $item['quantity'] . 'عدد)';
            $items_str[] = "{$item['product_name']} {$quantity}(" . number_format($item['total_price'] / 1000, 0) . ")";
        }
        $items_display = implode(' - ', $items_str);

        // دریافت پرداخت‌ها
        $payments_stmt = $pdo->prepare("SELECT amount, payment_date, payment_type, payment_code FROM Order_Payments WHERE order_id = ?");
        $payments_stmt->execute([$row['order_id']]);
        $payments = $payments_stmt->fetchAll(PDO::FETCH_ASSOC);

        $total_paid = array_sum(array_column($payments, 'amount'));
        $remaining = $row['final_amount'] - $total_paid;

        if (empty($payments)) {
            $day_data['orders'][] = [
                'customer_name' => $row['customer_name'],
                'items' => $items_display,
                'total_amount' => $row['total_amount'],
                'discount' => $row['discount'],
                'final_amount' => $row['final_amount'],
                'payments' => [['amount' => 0, 'payment_date' => '-', 'payment_type' => '-', 'payment_code' => '-']],
                'remaining' => $row['final_amount']
            ];
        } else {
            $payment_rows = [];
            foreach ($payments as $payment) {
                $payment_rows[] = [
                    'amount' => $payment['amount'],
                    'payment_date' => gregorian_to_jalali_format($payment['payment_date']),
                    'payment_type' => $payment['payment_type'],
                    'payment_code' => $payment['payment_code']
                ];
            }
            $day_data['orders'][] = [
                'customer_name' => $row['customer_name'],
                'items' => $items_display,
                'total_amount' => $row['total_amount'],
                'discount' => $row['discount'],
                'final_amount' => $row['final_amount'],
                'payments' => $payment_rows,
                'remaining' => $remaining
            ];
        }
    }
}
if ($current_day !== null) {
    $work_days[] = $day_data;
}

// تنظیمات صفحه‌بندی
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
    <link rel="icon" href="/favicon.ico" type="image/x-icon">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
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

        body {
            font-family: 'Vazirmatn RD FD NL';
            font-size: 10pt;
            margin: 0;
            padding: 0;
            direction: rtl;
        }

        .page {
            width: 297mm;
            height: 210mm;
            padding: 3mm;
            margin: 0 auto;
            box-sizing: border-box;
            page-break-after: always;
            border: 1px solid #000;
        }

        .page:last-child {
            page-break-after: auto;
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
            table-layout: fixed;
        }

        th,
        td {
            border: 1px solid #000;
            padding: 5px;
            text-align: center;
            vertical-align: middle;
            word-wrap: break-word;
        }

        th {
            background-color: #f0f0f0;
            font-weight: bold;
        }

        .col-date {
            width: 10%;
            white-space: nowrap;
        }

        .col-customer {
            width: 15%;
            white-space: nowrap;
        }

        .col-items {
            width: 30%;
            word-break: break-all;
        }

        .col-total,
        .col-discount,
        .col-final,
        .col-remaining {
            width: 10%;
            white-space: nowrap;
        }

        .col-payment {
            width: 25%;
            word-break: break-all;
        }

        .save-png-btn {
            position: fixed;
            top: 10px;
            right: 10px;
            padding: 10px 20px;
            background-color: #28a745;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-family: "Vazirmatn RD FD NL";
            font-size: 12pt;
            z-index: 1000;
        }

        .save-png-btn:hover {
            background-color: #218838;
        }

        @media print {
            @page {
                size: A4 landscape;
                margin: 0;
            }

            body {
                margin: 0;
                padding: 0;
            }

            .save-png-btn {
                display: none;
            }
        }
    </style>
</head>

<body>
    <button class="save-png-btn" onclick="saveReportAsPNG()">ذخیره به‌صورت PNG</button>

    <?php for ($page = 1; $page <= $total_pages; $page++): ?>
        <div class="page" id="page-<?= $page ?>">
            <div class="header">
                گزارش کاری <?= htmlspecialchars($user1_name) ?> و <?= htmlspecialchars($user2_name) ?>
                از تاریخ <?= $start_date ?> تا تاریخ <?= $end_date ?>
                مبلغ <?= number_format($total_sales, 0) ?> تومان
            </div>

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
                            <th class="col-final">قابل پرداخت</th>
                            <th class="col-payment">پرداخت‌ها (مبلغ / نوع / تاریخ / کد)</th>
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
                                <td class="col-payment">-</td>
                                <td class="col-remaining">-</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($day['orders'] as $order): ?>
                                <?php
                                $rowspan = count($order['payments']) > 0 ? count($order['payments']) : 1;
                                $first_payment = true;
                                foreach ($order['payments'] as $payment):
                                    ?>
                                    <tr>
                                        <?php if ($first_payment): ?>
                                            <td class="col-date" rowspan="<?= $rowspan ?>"><?= $day['work_date'] ?></td>
                                            <td class="col-customer" rowspan="<?= $rowspan ?>"><?= htmlspecialchars($order['customer_name']) ?>
                                            </td>
                                            <td class="col-items" rowspan="<?= $rowspan ?>"><?= htmlspecialchars($order['items']) ?></td>
                                            <td class="col-total" rowspan="<?= $rowspan ?>"><?= number_format($order['total_amount'], 0) ?></td>
                                            <td class="col-discount" rowspan="<?= $rowspan ?>"><?= number_format($order['discount'], 0) ?></td>
                                            <td class="col-final" rowspan="<?= $rowspan ?>"><?= number_format($order['final_amount'], 0) ?></td>
                                        <?php endif; ?>
                                        <td class="col-payment">
                                            <?= number_format($payment['amount'], 0) ?> /
                                            <?= htmlspecialchars($payment['payment_type']) ?> /
                                            <?= $payment['payment_date'] ?> /
                                            <?= htmlspecialchars($payment['payment_code']) ?>
                                        </td>
                                        <?php if ($first_payment): ?>
                                            <td class="col-remaining" rowspan="<?= $rowspan ?>"><?= number_format($order['remaining'], 0) ?>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                    <?php $first_payment = false; ?>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            <?php endfor; ?>
        </div>
    <?php endfor; ?>

    <script>
        console.log('Script loaded');

        function saveReportAsPNG() {
            if (typeof html2canvas === 'undefined') {
                console.error('html2canvas is not loaded');
                alert('خطا: کتابخانه html2canvas لود نشده است. لطفاً اتصال اینترنت را بررسی کنید.');
                return;
            }

            const totalPages = <?= $total_pages ?>;
            const user1Name = <?= json_encode($user1_name) ?>;
            const user2Name = <?= json_encode($user2_name) ?>;

            for (let page = 1; page <= totalPages; page++) {
                const pageContainer = document.getElementById(`page-${page}`);
                if (!pageContainer) {
                    console.error(`Page container for page ${page} not found`);
                    continue;
                }

                html2canvas(pageContainer, {
                    scale: 4,
                    useCORS: true,
                    backgroundColor: '#ffffff',
                    logging: true
                }).then(canvas => {
                    const link = document.createElement('a');
                    link.href = canvas.toDataURL('image/png', 1.0);
                    link.download = `گزارش ماهانه ${user1Name} و ${user2Name} صفحه_${page}.png`;
                    link.click();
                }).catch(error => {
                    console.error('Error saving PNG:', error);
                    alert('خطا در ذخیره تصویر گزارش. لطفاً دوباره تلاش کنید.');
                });
            }
        }

        document.fonts.ready.then(function () {
            console.log('Fonts loaded');
        }).catch(error => {
            console.error('Error loading fonts:', error);
        });
    </script>
</body>

</html>